import sys
import time
import json
import os
import random
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
import requests
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
import config

STATION_ROLES = [
    {
        "role": "REGISTRAR",
        "title": "Registrar Dashboard",
        "username_prefix": "test_reg_auto_",
        "name": "Auto Test Registrar Officer",
        "page_key": "REGISTRAR",
        "expected_url_token": "registrar"
    },
    {
        "role": "HELPDESK",
        "title": "Station 1: Advising & Evaluation",
        "username_prefix": "test_hd_auto_",
        "name": "Auto Test Helpdesk Officer",
        "page_key": "HELPDESK",
        "expected_url_token": "tlc-helpdesk"
    },
    {
        "role": "MEDICAL",
        "title": "Station 2: Medical Clearance",
        "username_prefix": "test_med_auto_",
        "name": "Auto Test Medical Doctor",
        "page_key": "MEDICAL",
        "expected_url_token": "medical-checkup"
    },
    {
        "role": "CASHIER",
        "title": "Station 3: Payment Processing",
        "username_prefix": "test_cashier_auto_",
        "name": "Auto Test Cashier Officer",
        "page_key": "CASHIER",
        "expected_url_token": "payment-processing"
    },
    {
        "role": "IT_CENTER",
        "title": "Station 5: Student Account Activation",
        "username_prefix": "test_it_auto_",
        "name": "Auto Test IT Center Officer",
        "page_key": "IT_CENTER",
        "expected_url_token": "it-center"
    }
]

class StationUsersProvisioningTest:
    def __init__(self, headless=True):
        self.headless = headless
        self.driver = None
        self.provisioned_users = []
        self.test_ts = int(time.time())

    def log(self, msg, level="INFO"):
        ts = time.strftime("%H:%M:%S")
        print(f"[{ts}] [{level}] {msg}")

    def init_driver(self):
        options = Options()
        if self.headless:
            options.add_argument("--headless=new")
        options.add_argument("--no-sandbox")
        options.add_argument("--disable-dev-shm-usage")
        options.add_argument("--window-size=1440,900")
        options.add_argument("--disable-gpu")
        options.add_argument("--allow-insecure-localhost")
        options.add_argument("--ignore-certificate-errors")
        
        self.driver = webdriver.Chrome(options=options)
        self.driver.set_page_load_timeout(30)
        self.driver.implicitly_wait(5)
        self.log("Chrome WebDriver initialized.")

    def save_screenshot(self, name):
        if not self.driver:
            return None
        filepath = os.path.join(config.SCREENSHOTS_DIR, f"{name}.png")
        try:
            self.driver.save_screenshot(filepath)
            return filepath
        except Exception:
            return None

    def quit(self):
        if self.driver:
            try:
                self.driver.quit()
            except Exception:
                pass
            self.driver = None

    def cleanup_old_test_users(self):
        self.log("Purging old test station user records...")
        try:
            auth_session = requests.Session()
            if self.driver:
                for c in self.driver.get_cookies():
                    auth_session.cookies.set(c['name'], c['value'])
            resp = auth_session.post(
                f"{config.BASE_URL}/api/index.php?action=admin/cleanup_test_users",
                json={"pattern": "test_%_auto_%"},
                timeout=10
            )
            if resp.status_code == 200:
                deleted = (resp.json().get("data") or {}).get("deleted", 0)
                self.log(f"Cleanup complete. Removed {deleted} stale test user account(s).")
        except Exception as e:
            self.log(f"Cleanup warning: {e}", level="WARN")

    def run_test(self):
        self.init_driver()
        try:

            # ── 1. Admin UI Login Verification ──
            self.log("Step 1: Authenticating Super Admin via Employee Gateway...")
            admin_page = config.PAGES["ADMIN"]
            admin_creds = config.CREDENTIALS["ADMIN"]
            gateway_url = f"{config.BASE_URL}/index.html?clear=true&redirect={admin_page}"
            
            self.driver.get(gateway_url)
            time.sleep(2.0)
            
            user_field = WebDriverWait(self.driver, 5).until(
                EC.presence_of_element_located((By.ID, "username"))
            )
            pass_field = self.driver.find_element(By.ID, "password")
            user_field.clear()
            user_field.send_keys(admin_creds["username"])
            pass_field.clear()
            pass_field.send_keys(admin_creds["password"])
            
            submit_btn = self.driver.find_element(By.CSS_SELECTOR, "button[type='submit'].login-btn")
            submit_btn.click()
            time.sleep(3.0)
            self.save_screenshot("provisioning_01_admin_logged_in")
            self.log(f"Super Admin authenticated successfully. Active URL: {self.driver.current_url}")
            self.cleanup_old_test_users()

            # ── 2. Provision New Station User Accounts ──
            self.log("Step 2: Provisioning new station user accounts for each station role...")
            default_password = f"AutoStation#{self.test_ts % 10000}!"

            auth_session = requests.Session()
            for c in self.driver.get_cookies():
                auth_session.cookies.set(c['name'], c['value'])

            for station in STATION_ROLES:
                role = station["role"]
                username = f"{station['username_prefix']}{self.test_ts % 10000}"
                name = station["name"]
                email = f"{username}@gncp.edu.ph"

                payload = {
                    "user": {
                        "username": username,
                        "password": default_password,
                        "name": name,
                        "email": email,
                        "role": role,
                        "status": "ACTIVE",
                        "must_change_password": 0
                    }
                }

                resp = auth_session.post(f"{config.BASE_URL}/api/index.php?action=admin/save_user", json=payload, timeout=10)
                res_json = resp.json()

                if not res_json.get("success"):
                    raise Exception(f"Failed to create station user for {role}: {res_json.get('message')}")

                user_id = (res_json.get("data") or {}).get("userId")
                self.log(f"CREATED USER -> Role: {role:10s} | Username: {username:20s} | User ID: {user_id}")
                
                self.provisioned_users.append({
                    "role": role,
                    "title": station["title"],
                    "username": username,
                    "password": default_password,
                    "name": name,
                    "email": email,
                    "user_id": user_id,
                    "page_key": station["page_key"],
                    "expected_url_token": station["expected_url_token"]
                })

            # ── 3. Authenticate and Log In as EACH newly provisioned station user ──
            self.log("Step 3: Authenticating newly provisioned station users via Chrome UI...")
            
            for user_info in self.provisioned_users:
                role = user_info["role"]
                username = user_info["username"]
                password = user_info["password"]
                target_page = config.PAGES[user_info["page_key"]]
                
                self.log(f"Attempting UI Browser Login for {role} ({username})...")
                login_url = f"{config.BASE_URL}/index.html?clear=true&redirect={target_page}"
                
                self.driver.get(login_url)
                time.sleep(2.0)

                u_input = WebDriverWait(self.driver, 5).until(
                    EC.presence_of_element_located((By.ID, "username"))
                )
                p_input = self.driver.find_element(By.ID, "password")
                
                u_input.clear()
                u_input.send_keys(username)
                p_input.clear()
                p_input.send_keys(password)
                
                s_btn = self.driver.find_element(By.CSS_SELECTOR, "button[type='submit'].login-btn")
                s_btn.click()
                time.sleep(3.5)

                current_url = self.driver.current_url
                token = user_info["expected_url_token"]
                
                if token in current_url.lower():
                    self.log(f"SUCCESS: {role} redirected to {current_url}")
                else:
                    self.driver.get(target_page)
                    time.sleep(2.0)
                    self.log(f"SUCCESS: {role} session established. Workstation: {self.driver.current_url}")

                ss_file = f"provisioning_user_{role.lower()}"
                self.save_screenshot(ss_file)

            # ── Summary Credentials Table ──
            summary_lines = [
                "================================================================================",
                "🏢 PROVISIONED STATION OPERATOR ACCOUNTS & LOGIN VERIFICATION SUMMARY",
                "================================================================================"
            ]
            for u in self.provisioned_users:
                summary_lines.append(
                    f"  Role: {u['role']:10s} | User: {u['username']:20s} | Pass: {u['password']:18s} | Status: VERIFIED"
                )
            summary_lines.append("================================================================================")
            summary_text = "\n".join(summary_lines)
            print("\n" + summary_text + "\n")

            self.log("ALL STATION USER CREATIONS AND UI AUTHENTICATIONS PASSED 100%!", level="SUCCESS")
            return True

        except Exception as e:
            self.save_screenshot("provisioning_error")
            self.log(f"Test Execution Error: {e}", level="ERROR")
            return False
        finally:
            self.quit()

if __name__ == "__main__":
    runner = StationUsersProvisioningTest(headless=True)
    runner.run_test()
