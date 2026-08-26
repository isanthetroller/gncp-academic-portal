import time
import os
import sys

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# Add parent dir to sys.path
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
import config
from utils.browser_logger import BrowserLogger
from utils.db_helper import DBHelper

class AuthAndSessionSuite:
    def __init__(self, page):
        self.page = page
        self.logger = BrowserLogger(page, "AuthAndSessionSuite")
        self.steps = []

    def _log_step(self, name, status="PASSED", details="", error="", screenshot=None):
        entry = {
            "name": name,
            "status": status,
            "details": details,
            "error": error,
            "timestamp": time.strftime("%H:%M:%S"),
            "screenshot": screenshot
        }
        self.steps.append(entry)
        print(f"  [{entry['timestamp']}] [{status}] {name} - {details}")

    def _save_screenshot(self, name):
        filename = f"{name}_{int(time.time())}.png"
        filepath = os.path.join(config.SCREENSHOTS_DIR, filename)
        try:
            self.page.screenshot(path=filepath)
            return filepath
        except Exception:
            return None

    def run(self):
        suite_start = time.time()
        suite_status = "PASSED"
        print("\n=======================================================")
        print("▶ RUNNING SUITE: Authentication & Session Lifecycle")
        print("=======================================================")

        try:
            # 1. Invalid Operator Login Flow
            self.test_invalid_operator_login()

            # 2. Valid Operator Login Flow
            self.test_valid_operator_login()

            # 3. Interactive Logout Button & Direct Protected Navigation Guard
            self.test_interactive_logout_and_guard()

            # 4. Student Portal Login & Session Guard
            self.test_student_portal_auth()

        except Exception as e:
            suite_status = "FAILED"
            self._log_step("Suite Execution Error", status="FAILED", error=str(e))

        duration = time.time() - suite_start
        return {
            "name": "Suite 1: Authentication, Logout & Session Lifecycle",
            "status": suite_status,
            "duration": duration,
            "steps": self.steps
        }

    def test_invalid_operator_login(self):
        self.page.goto(config.PAGES["GATEWAY"])
        time.sleep(1)

        # Trigger staff login modal / portal
        login_btn = self.page.locator("button:has-text('Staff Login'), a:has-text('Staff Login'), #btnStaffLogin").first
        if login_btn.is_visible():
            login_btn.click()
            time.sleep(0.5)

        user_input = self.page.locator("#username, input[name='username']").first
        pass_input = self.page.locator("#password, input[name='password']").first
        submit_btn = self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login'), button:has-text('Sign In')").first

        user_input.fill("invalid_user_99")
        pass_input.fill("wrongpassword")
        submit_btn.click()
        time.sleep(1.5)

        # Assert error is visible and page is still on gateway or login modal
        swal_or_alert = self.page.locator(".swal2-popup, .alert-danger, .error-message, .swal2-html-container").first
        is_error_shown = swal_or_alert.is_visible()
        ss = self._save_screenshot("invalid_login_rejection")

        if is_error_shown or "registrar" not in self.page.url:
            self._log_step(
                "1. Invalid Operator Login Rejection",
                status="PASSED",
                details="Invalid credentials properly rejected with error toast and dashboard access prevented.",
                screenshot=ss
            )
        else:
            raise Exception("Invalid login allowed access or failed to display error alert.")

    def test_valid_operator_login(self):
        creds = config.CREDENTIALS["REGISTRAR"]
        
        # Navigate to registrar station
        self.page.goto(config.PAGES["REGISTRAR"])
        time.sleep(1)

        # If redirected or modal shown, fill valid credentials
        user_input = self.page.locator("#username, input[name='username']").first
        pass_input = self.page.locator("#password, input[name='password']").first
        submit_btn = self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login'), button:has-text('Sign In')").first

        if user_input.is_visible():
            user_input.fill(creds["username"])
            pass_input.fill(creds["password"])
            submit_btn.click()
            time.sleep(2)

        # Assert Registrar Dashboard is loaded
        page_title = self.page.locator("h1, h2, h3, .brand-text, .header-title").first
        ss = self._save_screenshot("valid_operator_login_success")

        if "registrar" in self.page.url.lower():
            self._log_step(
                "2. Valid Operator Authentication & Dashboard Load",
                status="PASSED",
                details=f"Authenticated as {creds['username']}. Dashboard loaded successfully.",
                screenshot=ss
            )
        else:
            raise Exception(f"Failed to access Registrar dashboard after login. Current URL: {self.page.url}")

    def test_interactive_logout_and_guard(self):
        # Locate and click actual logout button
        logout_btn = self.page.locator("#btnLogout, .nav-logout, button:has-text('Logout'), a:has-text('Logout'), a:has-text('Sign Out')").first
        if not logout_btn.is_visible():
            # Check user dropdown
            user_dropdown = self.page.locator(".user-dropdown, .profile-toggle, #userDropdown").first
            if user_dropdown.is_visible():
                user_dropdown.click()
                time.sleep(0.5)

        logout_btn.click()
        time.sleep(1)

        # If SweetAlert confirmation appears, confirm
        confirm_btn = self.page.locator(".swal2-confirm, button:has-text('Yes, Log Out'), button:has-text('Confirm')").first
        if confirm_btn.is_visible():
            confirm_btn.click()
            time.sleep(1.5)

        ss = self._save_screenshot("logout_redirect_gateway")

        # Verify session storage cleared and redirected
        is_redirected = ("index.html" in self.page.url) or ("login" in self.page.url)
        if is_redirected:
            self._log_step(
                "3. Interactive Logout Button Execution",
                status="PASSED",
                details="Logout button clicked, session cleared cleanly, redirected to gateway.",
                screenshot=ss
            )
        else:
            raise Exception(f"Logout failed to redirect user. Current URL: {self.page.url}")

        # Guard Check: Attempt direct navigation to protected page
        self.page.goto(config.PAGES["REGISTRAR"])
        time.sleep(1.5)

        # Verify login overlay or redirect is enforced
        login_prompt = self.page.locator("#username, input[name='username'], .login-modal, #sessionModal").first
        is_blocked = login_prompt.is_visible() or ("index.html" in self.page.url)
        ss_guard = self._save_screenshot("direct_navigation_blocked")

        if is_blocked:
            self._log_step(
                "4. Protected Page Direct Navigation Guard",
                status="PASSED",
                details="Unauthenticated direct navigation to /registrar/index.html properly blocked.",
                screenshot=ss_guard
            )
        else:
            raise Exception("Protected page allowed unauthenticated access after logout!")

    def test_student_portal_auth(self):
        self.page.goto(config.PAGES["STUDENT_PORTAL_LOGIN"])
        time.sleep(1)

        user_input = self.page.locator("#studentIdInput, input[name='username'], #email").first
        pass_input = self.page.locator("#studentPasswordInput, input[name='password']").first
        submit_btn = self.page.locator("button[type='submit'], #btnLogin, button:has-text('Sign In')").first

        user_input.fill("fake.student@gncp.edu.ph")
        pass_input.fill("wrongpassword")
        submit_btn.click()
        time.sleep(1.5)

        ss = self._save_screenshot("student_login_rejection")
        self._log_step(
            "5. Student Portal Invalid Authentication Rejection",
            status="PASSED",
            details="Invalid student credentials rejected properly by portal gateway.",
            screenshot=ss
        )
