import sys
import os
import threading
import time
import importlib
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# Ensure tests/playwright directory is on sys.path regardless of execution root
CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
if CURRENT_DIR not in sys.path:
    sys.path.insert(0, CURRENT_DIR)

from fastapi import FastAPI, BackgroundTasks, Response
from fastapi.staticfiles import StaticFiles
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse
from fastapi.middleware.cors import CORSMiddleware
import uvicorn

import test_runner as tr_module
import test_admin_features as taf_module
import config

app = FastAPI(title="GNCP Playwright Visual Test Server")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
    expose_headers=["*"]
)

@app.get("/favicon.ico")
def get_favicon():
    return Response(status_code=204)

# Serve Screenshots, Shared Assets, and UI Static Files
PROJECT_ROOT = os.path.abspath(os.path.join(config.PLAYWRIGHT_DIR, "..", ".."))
app.mount("/screenshots", StaticFiles(directory=config.SCREENSHOTS_DIR), name="screenshots")
if os.path.exists(os.path.join(PROJECT_ROOT, "shared")):
    app.mount("/shared", StaticFiles(directory=os.path.join(PROJECT_ROOT, "shared")), name="shared")
if os.path.exists(os.path.join(PROJECT_ROOT, "school-website")):
    app.mount("/school-website", StaticFiles(directory=os.path.join(PROJECT_ROOT, "school-website")), name="school-website")

UI_DIR = os.path.join(config.PLAYWRIGHT_DIR, "ui")
os.makedirs(UI_DIR, exist_ok=True)
app.mount("/ui", StaticFiles(directory=UI_DIR, html=True), name="ui")

@app.get("/")
@app.get("/ui")
def index_redirect():
    return RedirectResponse(url="/ui/index.html")

STATE = {
    "status": "IDLE", # IDLE, RUNNING, PASSED, FAILED
    "current_step": "",
    "logs": [],
    "results": [],
    "latest_screenshot": None,
    "start_time": None,
    "duration": 0
}

current_runner = None
runner_thread = None

def run_test_thread(headless=True, mode="all", stop_after="all"):
    global STATE, current_runner
    STATE["status"] = "RUNNING"
    STATE["logs"] = []
    STATE["results"] = []
    STATE["start_time"] = time.time()
    STATE["duration"] = 0
    STATE["latest_screenshot"] = None

    def log_callback(entry):
        STATE["logs"].append(entry)
        if entry.get("screenshot"):
            STATE["latest_screenshot"] = entry["screenshot"]
        if "Executing Step" in entry["message"]:
            STATE["current_step"] = entry["message"].replace("Executing Step", "Step").strip()

    try:
        importlib.reload(tr_module)
        importlib.reload(taf_module)
        
        if mode == "admin_features":
            current_runner = taf_module.AdminFeaturesPlaywrightTestRunner(headless=headless, callback=log_callback)
        else:
            current_runner = tr_module.PlaywrightTestRunner(headless=headless, callback=log_callback, stop_after=stop_after)
            
        success = current_runner.run_full_pipeline()
        STATE["results"] = current_runner.results
        STATE["status"] = "PASSED" if success else "FAILED"
    except Exception as e:
        STATE["status"] = "FAILED"
        log_callback({"timestamp": time.strftime("%H:%M:%S"), "level": "ERROR", "message": f"Server Thread Exception: {str(e)}", "screenshot": None})
    finally:
        STATE["duration"] = round(time.time() - (STATE["start_time"] or time.time()), 1)
        current_runner = None

@app.post("/api/run")
def start_test(headless: bool = True, mode: str = "all", stop_after: str = "all"):
    global runner_thread
    if STATE["status"] == "RUNNING":
        return JSONResponse(status_code=400, content={"error": "A test run is currently in progress."})
    
    runner_thread = threading.Thread(target=run_test_thread, kwargs={"headless": headless, "mode": mode, "stop_after": stop_after}, daemon=True)
    runner_thread.start()
    return {"status": "started", "headless": headless, "mode": mode, "stop_after": stop_after}

@app.post("/api/stop")
def stop_test():
    global current_runner, STATE
    if current_runner:
        current_runner.quit()
        STATE["status"] = "IDLE"
        STATE["logs"].append({"timestamp": time.strftime("%H:%M:%S"), "level": "WARN", "message": "Test execution halted manually by user.", "screenshot": None})
        return {"status": "stopped"}
    return {"status": "no_active_test"}

@app.get("/api/status")
def get_status():
    if STATE["status"] == "RUNNING" and STATE["start_time"]:
        STATE["duration"] = round(time.time() - STATE["start_time"], 1)
    return STATE

LAST_HEARTBEAT = time.time()
CLIENT_CONNECTED = False

@app.get("/api/ping")
def ping():
    global LAST_HEARTBEAT, CLIENT_CONNECTED
    LAST_HEARTBEAT = time.time()
    CLIENT_CONNECTED = True
    return {"status": "alive", "timestamp": LAST_HEARTBEAT}

@app.post("/api/shutdown")
def shutdown_server():
    def kill():
        time.sleep(0.5)
        print("\n[Server] Received shutdown signal from browser tab. Terminating server cleanly...")
        os._exit(0)
    threading.Thread(target=kill, daemon=True).start()
    return {"status": "shutting_down"}

def watchdog_thread():
    time.sleep(120)
    while True:
        time.sleep(10)
        if CLIENT_CONNECTED and STATE["status"] != "RUNNING" and (time.time() - LAST_HEARTBEAT > 3600):
            print("\n[Watchdog] Inactive for >1 hour. Auto-terminating Playwright test server...")
            os._exit(0)

if __name__ == "__main__":
    w_thread = threading.Thread(target=watchdog_thread, daemon=True)
    w_thread.start()

    print("================================================================")
    print("  GNCP Playwright Visual Test Server Running on:")
    print("  👉 http://localhost:8090/ui/index.html")
    print("  (Server will auto-terminate after 1 hour of total inactivity)")
    print("================================================================\n")
    uvicorn.run(app, host="0.0.0.0", port=8090, log_level="warning")
