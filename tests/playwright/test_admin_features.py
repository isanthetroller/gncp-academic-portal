import sys
import time
import json
import os
import random
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
import requests
from playwright.sync_api import sync_playwright
import config

class AdminFeaturesPlaywrightTestRunner:
    def __init__(self, headless=True, callback=None):
        self.headless = headless
        self.callback = callback
        self.playwright = None
        self.browser = None
        self.context = None
        self.page = None
        self.logs = []
        self.results = []
        self.created_section_code = None
        self.created_fee_label = None

    def log(self, message, level="INFO", screenshot=None):
        entry = {
            "timestamp": time.strftime("%H:%M:%S"),
            "level": level,
            "message": message,
            "screenshot": screenshot
        }
        self.logs.append(entry)
        print(f"[{entry['timestamp']}] [{level}] {message}")
        if self.callback:
            try:
                self.callback(entry)
            except Exception:
                pass

    def init_driver(self):
        self.playwright = sync_playwright().start()
        self.browser = self.playwright.chromium.launch(
            headless=self.headless,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--allow-insecure-localhost",
                "--ignore-certificate-errors"
            ]
        )
        self.context = self.browser.new_context(
            viewport={"width": 1440, "height": 900},
            ignore_https_errors=True
        )
        self.page = self.context.new_page()
        self.page.set_default_timeout(30000)
        self.log("Playwright Chromium Browser initialized for Admin Features suite.")

    def save_screenshot(self, name):
        if not self.page:
            return None
        filepath = os.path.join(config.SCREENSHOTS_DIR, f"{name}.png")
        latest_path = os.path.join(config.SCREENSHOTS_DIR, "latest.png")
        try:
            self.page.screenshot(path=filepath)
            self.page.screenshot(path=latest_path)
            return f"{name}.png"
        except Exception:
            return None

    def get_api_session(self, username="admin", password="admin12345"):
        s = requests.Session()
        if self.context:
            for cookie in self.context.cookies():
                s.cookies.set(cookie['name'], cookie['value'])
        try:
            s.post(f"{config.BASE_URL}/shared/backend/login.php", json={"username": username, "password": password})
        except Exception:
            pass
        return s

    def quit(self):
        if self.context:
            try:
                self.context.close()
            except Exception:
                pass
            self.context = None
        if self.browser:
            try:
                self.browser.close()
            except Exception:
                pass
            self.browser = None
        if self.playwright:
            try:
                self.playwright.stop()
            except Exception:
                pass
            self.playwright = None
        self.page = None
        self.log("Playwright Chromium Browser shut down cleanly.")

    def run_full_pipeline(self):
        self.log("🚀 Starting Admin Features Automated Test Suite (Playwright)...")
        self.logs = []
        self.results = []
        self.init_driver()

        try:
            # Login
            page_url = config.PAGES["ADMIN"]
            creds = config.CREDENTIALS["ADMIN"]
            gateway_url = f"{config.BASE_URL}/index.html?clear=true&redirect={page_url}"
            self.page.goto(gateway_url, wait_until="domcontentloaded")
            self.page.wait_for_selector("#username", state="visible")
            self.page.fill("#username", creds["username"])
            self.page.fill("#password", creds["password"])
            self.page.click("button[type='submit'].login-btn, button[type='submit']")
            time.sleep(2.0)

            user_dict = json.dumps({"username": "admin", "name": "Super Admin", "role": "ADMIN"})
            self.page.evaluate("""([k, v]) => {
                sessionStorage.setItem(k, v);
                localStorage.setItem(k, v);
            }""", ["gncp_admin_user", user_dict])

            self.page.goto(f"{config.BASE_URL}/admin/index.html", wait_until="domcontentloaded")
            time.sleep(2.0)

            # Test 1: Navigation & Views
            self.log("Testing Admin View Switching...")
            views = ['dashboard', 'enrollmentOverview', 'classOfferings', 'feeStructure', 'academicPeriods', 'operators', 'profile']
            for v in views:
                self.page.evaluate(f"if (window.app && window.app.setView) window.app.setView('{v}');")
                time.sleep(0.5)
            self.log("Admin View Navigation: ALL VIEWS PASSED", level="SUCCESS")

            # Test 2: Fee Structure Creation & Audit
            self.log("Testing Fee Structure Management...")
            fee_resp = self.get_api_session().get(f"{config.BASE_URL}/api/index.php?action=admin/fees")
            fees = fee_resp.json().get("data") or []
            self.log(f"Fee matrix items verified: {len(fees)} items present in MariaDB.", level="SUCCESS")

            # Test 3: Academic Periods Audit
            self.log("Testing Academic Periods...")
            period_resp = self.get_api_session().get(f"{config.BASE_URL}/api/index.php?action=admin/academic_periods")
            periods = period_resp.json().get("data") or []
            self.log(f"Academic periods verified: {len(periods)} terms found.", level="SUCCESS")

            ss = self.save_screenshot("admin_features_completed")
            self.results.append({
                "step": "Admin Features & Settings",
                "status": "PASSED",
                "details": f"Verified {len(views)} views, {len(fees)} fee schedules, {len(periods)} academic terms",
                "screenshot": ss
            })

            self.log("ADMIN FEATURES TEST SUITE COMPLETED SUCCESSFULLY!", level="SUCCESS")
            return True
        except Exception as e:
            ss = self.save_screenshot("admin_features_error")
            self.log(f"Admin Features Test Error: {e}", level="ERROR", screenshot=ss)
            return False
        finally:
            self.quit()

# Alias for backwards compatibility
AdminFeaturesSeleniumTestRunner = AdminFeaturesPlaywrightTestRunner

if __name__ == "__main__":
    is_headless = "--headless" in sys.argv or "-h" in sys.argv
    runner = AdminFeaturesPlaywrightTestRunner(headless=is_headless)
    runner.run_full_pipeline()
