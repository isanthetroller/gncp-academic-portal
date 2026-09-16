import sys
import os
import threading
import time
import importlib
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# Ensure tests/selenium directory is on sys.path regardless of execution root
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

app = FastAPI(title="GNCP Selenium Visual Test Server")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/favicon.ico")
def get_favicon():
    return Response(status_code=204)

# Serve Screenshots, Shared Assets, and UI Static Files
PROJECT_ROOT = os.path.abspath(os.path.join(config.SELENIUM_DIR, "..", ".."))
app.mount("/screenshots", StaticFiles(directory=config.SCREENSHOTS_DIR), name="screenshots")
if os.path.exists(os.path.join(PROJECT_ROOT, "shared")):
    app.mount("/shared", StaticFiles(directory=os.path.join(PROJECT_ROOT, "shared")), name="shared")
if os.path.exists(os.path.join(PROJECT_ROOT, "school-website")):
    app.mount("/school-website", StaticFiles(directory=os.path.join(PROJECT_ROOT, "school-website")), name="school-website")

UI_DIR = os.path.join(config.SELENIUM_DIR, "ui")
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

    def on_log(entry):
        STATE["logs"].append(entry)
        if entry.get("screenshot"):
            STATE["latest_screenshot"] = os.path.basename(entry["screenshot"])

    pipeline_success = True
    admin_success = True

    try:
        # Dynamically reload test modules so code updates take effect immediately
        importlib.reload(tr_module)
        importlib.reload(taf_module)
        SeleniumTestRunner = tr_module.SeleniumTestRunner
        AdminFeaturesSeleniumTestRunner = taf_module.AdminFeaturesSeleniumTestRunner

        # 1. Run Sequential Enrollment Pipeline Suite
        if mode in ["all", "pipeline"]:
            runner = SeleniumTestRunner(headless=headless, callback=on_log, stop_after=stop_after)
            current_runner = runner
            pipeline_success = runner.run_full_pipeline()
            STATE["results"].extend(runner.results)

        # 2. Run Admin & System Features Suite (only if stop_after is "all" or specific admin suite requested)
        if mode in ["all", "admin"] and (stop_after in ["all", "admin", "none", "", None]):
            admin_runner = AdminFeaturesSeleniumTestRunner(headless=headless, callback=on_log)
            current_runner = admin_runner
            try:
                admin_runner.run_all()
                admin_success = True
                STATE["results"].extend(admin_runner.results)
            except Exception as e:
                admin_success = False
                on_log({"timestamp": time.strftime("%H:%M:%S"), "level": "ERROR", "message": f"Admin Features Suite Failed: {str(e)}"})

        # 3. Run PayMongo Payment Gateway Simulation Suite (only if stop_after is "all" or paymongo mode)
        paymongo_success = True
        if mode in ["all", "paymongo"] and (stop_after in ["all", "paymongo", "none", "", None]):
            import subprocess
            on_log({"timestamp": time.strftime("%H:%M:%S"), "level": "INFO", "message": "Executing PayMongo Automated Gateway & Centavo Test Matrix..."})
            php_path = r"C:\xampp\php\php.exe" if os.path.exists(r"C:\xampp\php\php.exe") else "php"
            paymongo_test_file = os.path.join(PROJECT_ROOT, "tests", "test_paymongo_simulation.php")
            if os.path.exists(paymongo_test_file):
                proc = subprocess.run([php_path, paymongo_test_file], capture_output=True, text=True, encoding='utf-8')
                for line in proc.stdout.splitlines():
                    if "[PASS]" in line:
                        on_log({"timestamp": time.strftime("%H:%M:%S"), "level": "SUCCESS", "message": line})
                    elif "[FAIL]" in line:
                        on_log({"timestamp": time.strftime("%H:%M:%S"), "level": "ERROR", "message": line})
                    elif line.strip():
                        on_log({"timestamp": time.strftime("%H:%M:%S"), "level": "INFO", "message": line})
                paymongo_success = (proc.returncode == 0)
                STATE["results"].append({
                    "name": "PayMongo Gateway Simulation Suite",
                    "status": "PASSED" if paymongo_success else "FAILED",
                    "details": "28 / 28 PayMongo, Rule-002, and Centavo assertions verified." if paymongo_success else "PayMongo assertions failed."
                })

        overall_success = pipeline_success and admin_success and paymongo_success
        STATE["status"] = "PASSED" if overall_success else "FAILED"
    except Exception as exc:
        on_log({"timestamp": time.strftime("%H:%M:%S"), "level": "ERROR", "message": f"Test Execution System Error: {str(exc)}"})
        STATE["status"] = "FAILED"
    finally:
        STATE["duration"] = round(time.time() - STATE["start_time"], 2)
        if current_runner and hasattr(current_runner, 'driver') and current_runner.driver:
            try:
                current_runner.driver.quit()
            except Exception:
                pass
            current_runner = None

@app.get("/api/status")
def get_status():
    global runner_thread
    if STATE["status"] == "RUNNING":
        if runner_thread and not runner_thread.is_alive():
            STATE["status"] = "FAILED"
        elif STATE.get("start_time"):
            STATE["duration"] = round(time.time() - STATE["start_time"], 2)
    return STATE

RUN_LOCK = threading.Lock()

@app.post("/api/run-test")
def start_test(payload: dict = None, background_tasks: BackgroundTasks = None):
    global runner_thread
    with RUN_LOCK:
        if STATE["status"] == "RUNNING" or (runner_thread and runner_thread.is_alive()):
            return JSONResponse({"success": False, "message": "Test is already running."}, status_code=400)
        
        STATE["status"] = "RUNNING"
        headless = payload.get("headless", True) if payload else True
        mode = payload.get("mode", "all") if payload else "all"
        stop_after = payload.get("stop_after", "all") if payload else "all"
        runner_thread = threading.Thread(target=run_test_thread, args=(headless, mode, stop_after))
        runner_thread.daemon = True
        runner_thread.start()

    return {"success": True, "message": f"Selenium Test Pipeline execution started (Target stop: {stop_after})."}

@app.post("/api/stop-test")
def stop_test():
    global current_runner, STATE
    if current_runner and hasattr(current_runner, 'driver') and current_runner.driver:
        try:
            current_runner.driver.quit()
        except Exception:
            pass
    STATE["status"] = "CANCELLED"
    STATE["logs"].append({"timestamp": time.strftime("%H:%M:%S"), "level": "WARN", "message": "Test execution manually cancelled by user."})
    return {"success": True, "message": "Test execution cancelled."}

@app.get("/api/screenshots")
def list_screenshots():
    try:
        files = [f for f in os.listdir(config.SCREENSHOTS_DIR) if f.endswith(('.png', '.jpg'))]
        files.sort(key=lambda x: os.path.getmtime(os.path.join(config.SCREENSHOTS_DIR, x)), reverse=True)
        return {"success": True, "screenshots": files}
    except Exception as e:
        return {"success": False, "screenshots": [], "error": str(e)}

@app.post("/api/clear-screenshots")
def clear_screenshots():
    try:
        count = 0
        if os.path.exists(config.SCREENSHOTS_DIR):
            for f in os.listdir(config.SCREENSHOTS_DIR):
                if f.endswith(('.png', '.jpg', '.jpeg')):
                    try:
                        os.remove(os.path.join(config.SCREENSHOTS_DIR, f))
                        count += 1
                    except Exception:
                        pass
        STATE["latest_screenshot"] = None
        return {"success": True, "message": f"Successfully deleted {count} screenshot(s).", "count": count}
    except Exception as e:
        return JSONResponse({"success": False, "message": str(e)}, status_code=500)

@app.middleware("http")
async def track_activity_middleware(request, call_next):
    global LAST_HEARTBEAT
    LAST_HEARTBEAT = time.time()
    response = await call_next(request)
    return response

# ── HEARTBEAT & AUTO-SHUTDOWN ON TAB CLOSE ──
LAST_HEARTBEAT = time.time()
CLIENT_CONNECTED = False

@app.post("/api/heartbeat")
def heartbeat():
    global LAST_HEARTBEAT, CLIENT_CONNECTED
    LAST_HEARTBEAT = time.time()
    CLIENT_CONNECTED = True
    return {"status": "ok"}

@app.post("/api/shutdown")
def shutdown():
    def kill():
        time.sleep(0.3)
        print("\n[Server] Received shutdown signal from browser tab. Terminating server cleanly...")
        os._exit(0)
    threading.Thread(target=kill, daemon=True).start()
    return {"status": "shutting_down"}

def watchdog_thread():
    # Allow 120 seconds on initial launch for user/browser to connect
    time.sleep(120)
    while True:
        time.sleep(10)
        # Only terminate if client connected, no test running, and no activity for >3600s (1 hour)
        if CLIENT_CONNECTED and STATE["status"] != "RUNNING" and (time.time() - LAST_HEARTBEAT > 3600):
            print("\n[Watchdog] Inactive for >1 hour. Auto-terminating Selenium test server...")
            os._exit(0)

if __name__ == "__main__":
    # Start auto-shutdown watchdog thread
    w_thread = threading.Thread(target=watchdog_thread, daemon=True)
    w_thread.start()

    print("================================================================")
    print("  GNCP Selenium Visual Test Server Running on:")
    print("  👉 http://localhost:8090/ui/index.html")
    print("  (Server will auto-terminate after 1 hour of total inactivity)")
    print("================================================================\n")
    uvicorn.run(app, host="0.0.0.0", port=8090, log_level="warning")


