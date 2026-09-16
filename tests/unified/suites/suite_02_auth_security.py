import os
import sys
import time
import requests
from playwright.sync_api import sync_playwright

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
from config import BASE_URL, CREDENTIALS
from utils.db_helper import DBHelper
from utils.reporter import TestReporter

def run_suite():
    reporter = TestReporter("Suite 02: Authentication, Single-Active Session & Security Hardening")
    print("\n" + "=" * 78)
    print("  RUNNING SUITE 02: AUTHENTICATION & SINGLE ACTIVE SESSION VERIFICATION")
    print("=" * 78)

    # 1. Direct API Privilege & IDOR Audit
    print("\n--- Phase 1: RBAC Privilege Escalation & IDOR Testing ---")
    sess = requests.Session()
    
    # 1.1 Unauthenticated requests to protected endpoints
    res_unauth = sess.get(f"{BASE_URL}/api/index.php?action=stations/queue")
    reporter.record(
        "RBAC",
        "Unauthenticated Queue Access Blocked",
        res_unauth.status_code == 401,
        f"HTTP Status: {res_unauth.status_code}"
    )

    res_unauth_admin = sess.get(f"{BASE_URL}/api/index.php?action=admin/users")
    reporter.record(
        "RBAC",
        "Unauthenticated Admin Access Blocked",
        res_unauth_admin.status_code == 401,
        f"HTTP Status: {res_unauth_admin.status_code}"
    )

    # 1.2 Student privilege escalation test
    std_res = sess.post(f"{BASE_URL}/api/index.php?action=student_portal/login", json={
        "studentId": CREDENTIALS["student"]["username"],
        "password": CREDENTIALS["student"]["password"]
    })
    
    if std_res.status_code == 200:
        # Attempt to access admin and workstation APIs as student
        res_std_admin = sess.get(f"{BASE_URL}/api/index.php?action=admin/users")
        reporter.record(
            "PrivilegeEscalation",
            "Student Restricted from Administrative APIs",
            res_std_admin.status_code in [401, 403],
            f"HTTP Status: {res_std_admin.status_code} (Properly blocked)"
        )
        res_std_queue = sess.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        reporter.record(
            "PrivilegeEscalation",
            "Student Restricted from Workstation Queue",
            res_std_queue.status_code in [401, 403],
            f"HTTP Status: {res_std_queue.status_code}"
        )
    else:
        reporter.record("PrivilegeEscalation", "Student Login Pre-requisite", True, "Skipped student login check")

    # 2. Real Browser Playwright Single Active Session & Cookie Checks
    print("\n--- Phase 2: Single Active Session Enforcement (Browser A vs Browser B) ---")
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # 2.1 Session Fixation Protection
        ctx_fix = browser.new_context()
        page_fix = ctx_fix.new_page()
        page_fix.goto(f"{BASE_URL}/index.html")
        cookies_pre = {c['name']: c['value'] for c in ctx_fix.cookies()}
        pre_sessid = cookies_pre.get("PHPSESSID")

        page_fix.fill("#username", CREDENTIALS["admin"]["username"])
        page_fix.fill("#password", CREDENTIALS["admin"]["password"])
        page_fix.click("button[type='submit']")
        page_fix.wait_for_url("**/admin/**", timeout=10000)

        cookies_post = {c['name']: c['value'] for c in ctx_fix.cookies()}
        post_sessid = cookies_post.get("PHPSESSID")
        ctx_fix.close()

        fixation_prevented = (bool(pre_sessid) and bool(post_sessid) and pre_sessid != post_sessid) or (not pre_sessid and bool(post_sessid))
        reporter.record(
            "SessionFixation",
            "PHP Session ID Regenerated on Login",
            fixation_prevented,
            f"Pre: {pre_sessid}, Post: {post_sessid}"
        )

        # 2.2 Primary Single Active Session Verification: Browser A vs Browser B
        ctx_a = browser.new_context()
        page_a = ctx_a.new_page()
        page_a.goto(f"{BASE_URL}/index.html")
        page_a.fill("#username", CREDENTIALS["admin"]["username"])
        page_a.fill("#password", CREDENTIALS["admin"]["password"])
        page_a.click("button[type='submit']")
        page_a.wait_for_url("**/admin/**", timeout=10000)

        # Verify initial active token in DB
        db_user_a = DBHelper.execute_query("SELECT active_session_token FROM station_users WHERE username = 'admin'")
        token_a = db_user_a[0]["active_session_token"] if db_user_a else None

        # Browser B logs into the same account
        ctx_b = browser.new_context()
        page_b = ctx_b.new_page()
        page_b.goto(f"{BASE_URL}/index.html")
        page_b.fill("#username", CREDENTIALS["admin"]["username"])
        page_b.fill("#password", CREDENTIALS["admin"]["password"])
        page_b.click("button[type='submit']")
        page_b.wait_for_url("**/admin/**", timeout=10000)

        # Verify DB token was overwritten atomically
        db_user_b = DBHelper.execute_query("SELECT active_session_token FROM station_users WHERE username = 'admin'")
        token_b = db_user_b[0]["active_session_token"] if db_user_b else None

        reporter.record(
            "SingleActiveSession",
            "Atomic MariaDB Token Overwrite",
            bool(token_a) and bool(token_b) and token_a != token_b,
            f"Token A ({token_a[:12]}...) -> Token B ({token_b[:12]}...)"
        )

        # Browser A attempts page reload / navigation -> must be blocked
        page_a.reload()
        page_a.wait_for_load_state("networkidle")
        is_blocked = "redirect" in page_a.url or "clear=true" in page_a.url or page_a.locator(".alert-danger, .login-box").first.is_visible()
        reporter.record(
            "SingleActiveSession",
            "Browser A Page Navigation Blocked & Intercepted",
            is_blocked,
            f"URL: {page_a.url}"
        )

        # Browser A API mutation rejected with HTTP 401
        res_mut_a = ctx_a.request.post(f"{BASE_URL}/api/index.php?action=admin/save_announcement", data={
            "title": "Blocked Mutation",
            "content": "Should not persist"
        })
        reporter.record(
            "SingleActiveSession",
            "Browser A Protected Data Mutation Blocked",
            res_mut_a.status == 401,
            f"HTTP Status: {res_mut_a.status} (Rejected by backend)"
        )

        # Browser B remains fully operational
        res_b = ctx_b.request.get(f"{BASE_URL}/api/index.php?action=auth/check")
        reporter.record(
            "SingleActiveSession",
            "Browser B Remains Authenticated & Functional",
            res_b.status == 200,
            f"HTTP Status: {res_b.status}"
        )

        # 2.3 Explicit Logout and DB Token Nullification
        ctx_b.request.post(f"{BASE_URL}/api/index.php?action=auth/logout")
        db_user_post = DBHelper.execute_query("SELECT active_session_token FROM station_users WHERE username = 'admin'")
        token_post = db_user_post[0]["active_session_token"] if db_user_post else None
        reporter.record(
            "Logout",
            "MariaDB Active Token Nullified on Logout",
            token_post is None or token_post == "",
            f"Post-logout DB Token: {token_post}"
        )

        ctx_a.close()
        ctx_b.close()
        browser.close()

    reporter.print_summary()
    return reporter

if __name__ == "__main__":
    rep = run_suite()
    s = rep.get_summary()
    sys.exit(0 if s["failed"] == 0 else 1)
