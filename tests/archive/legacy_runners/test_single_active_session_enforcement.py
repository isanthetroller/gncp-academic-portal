"""
GNCP Academic System — Single Active Session Enforcement Playwright Test Suite
Tests multi-browser isolation, server-side session invalidation, token security,
logout revocation, password change rotation, and per-account scoping.
"""

import os
import sys
import time
import json
import pymysql
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
SCREENSHOTS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "screenshots")
os.makedirs(SCREENSHOTS_DIR, exist_ok=True)

DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "gncp_portal"
}

def get_db_connection():
    return pymysql.connect(**DB_CONFIG)

def log_test_result(name, passed, details=""):
    status = "PASS" if passed else "FAIL"
    print(f"[{status}] {name}")
    if details:
        print(f"       {details}")
    return {"name": name, "passed": passed, "details": details}

def run_tests():
    test_results = []
    print("==========================================================================")
    print("  GNCP SINGLE ACTIVE SESSION HARDENING — MULTI-BROWSER PLAYWRIGHT SUITE   ")
    print("==========================================================================\n")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # ----------------------------------------------------------------------
        # Test 1: Normal Login (Browser A)
        # ----------------------------------------------------------------------
        print("\n--- Running Test 1: Normal Login ---")
        context_a = browser.new_context()
        page_a = context_a.new_page()

        page_a.goto(f"{BASE_URL}/index.html")
        page_a.fill("#username", "admin")
        page_a.fill("#password", "admin12345")
        page_a.click("button[type='submit']")
        page_a.wait_for_url("**/admin/**", timeout=10000)

        # Verify authenticated session in DB
        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'admin'")
            token_db_1 = cur.fetchone()[0]
        conn.close()

        t1_passed = bool(token_db_1) and "admin" in page_a.url
        page_a.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test1_normal_login_browser_a.png"))
        test_results.append(log_test_result(
            "Test 1 — Normal Login",
            t1_passed,
            f"Browser A authenticated as 'admin'. DB Token established: {token_db_1[:16]}..."
        ))

        # ----------------------------------------------------------------------
        # Test 2: Same account from another browser (Browser B login -> Browser A invalidated)
        # ----------------------------------------------------------------------
        print("\n--- Running Test 2: Same account from another browser ---")
        context_b = browser.new_context()
        page_b = context_b.new_page()

        # Browser B logs in with the SAME account ('admin')
        page_b.goto(f"{BASE_URL}/index.html")
        page_b.fill("#username", "admin")
        page_b.fill("#password", "admin12345")
        page_b.click("button[type='submit']")
        page_b.wait_for_url("**/admin/**", timeout=10000)

        # Retrieve new token from DB
        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'admin'")
            token_db_2 = cur.fetchone()[0]
        conn.close()

        # Verify Browser B established a different active_session_token
        tokens_differ = (token_db_1 != token_db_2)

        # ----------------------------------------------------------------------
        # Test 2: Same account from another browser UI invalidation
        # ----------------------------------------------------------------------
        print("\n--- Running Test 2: Same account from another browser ---")
        # Now Browser A attempts to refresh / navigate to protected page /admin/index.php
        page_a.goto(f"{BASE_URL}/admin/index.php")
        time.sleep(1.5)

        # Browser A must be rejected by server requirePageAuth and redirected to gateway
        page_a_url = page_a.url
        url_path = page_a_url.split("?")[0].rstrip("/")
        page_a_rejected = not url_path.endswith("/admin") and not url_path.endswith("/admin/index.php")
        
        # Check that the invalidated banner is displayed on page A
        banner_text = ""
        banner_el = page_a.locator(".session-expired-banner, .alert-danger, .alert-custom-err").first
        if banner_el.is_visible():
            banner_text = banner_el.inner_text()

        expected_msg_match = "invalidated because this account was signed in from another device or browser" in banner_text

        # Verify Browser B remains authenticated
        page_b.goto(f"{BASE_URL}/admin/index.php")
        time.sleep(1)
        b_path = page_b.url.split("?")[0].rstrip("/")
        page_b_active = b_path.endswith("/admin") or b_path.endswith("/admin/index.php")

        page_a.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test2_browser_a_invalidated.png"))
        page_b.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test2_browser_b_active.png"))

        t2_passed = tokens_differ and page_a_rejected and page_b_active and expected_msg_match
        test_results.append(log_test_result(
            "Test 2 — Same account from another browser",
            t2_passed,
            f"Tokens differed: {tokens_differ}. Browser A rejected & on gateway: {page_a_rejected}. Banner text match: {expected_msg_match}. Browser B remained active: {page_b_active}."
        ))

        # ----------------------------------------------------------------------
        # Test 3: Old API credential rejection
        # ----------------------------------------------------------------------
        print("\n--- Running Test 3: Old API credential rejection ---")
        # Setup independent Browser A and Browser B contexts to test API rejection
        ctx_api_a = browser.new_context()
        page_api_a = ctx_api_a.new_page()
        page_api_a.goto(f"{BASE_URL}/index.html")
        page_api_a.fill("#username", "admin")
        page_api_a.fill("#password", "admin12345")
        page_api_a.click("button[type='submit']")
        page_api_a.wait_for_url("**/admin/**", timeout=10000)

        # Browser B logs in with the SAME account, superseding Browser A
        ctx_api_b = browser.new_context()
        page_api_b = ctx_api_b.new_page()
        page_api_b.goto(f"{BASE_URL}/index.html")
        page_api_b.fill("#username", "admin")
        page_api_b.fill("#password", "admin12345")
        page_api_b.click("button[type='submit']")
        page_api_b.wait_for_url("**/admin/**", timeout=10000)

        # Attempt to make an API request using Browser A's context (which holds the superseded session cookie)
        api_res_a = ctx_api_a.request.get(f"{BASE_URL}/api/index.php?action=auth/check")
        api_a_status = api_res_a.status
        try:
            api_a_json = api_res_a.json()
        except Exception:
            api_a_json = {}

        is_invalidated_flag = api_a_json.get("session_invalidated") or (api_a_json.get("data") and api_a_json["data"].get("session_invalidated"))
        msg_matches = "invalidated because this account was signed in from another device or browser" in str(api_a_json.get("message", ""))
        t3_passed = (api_a_status == 401) and (bool(is_invalidated_flag) or msg_matches)

        test_results.append(log_test_result(
            "Test 3 — Old API Credential Rejected",
            t3_passed,
            f"Status: {api_a_status} (Expected 401). session_invalidated in response: {bool(is_invalidated_flag)}. Message: '{api_a_json.get('message')}'."
        ))

        # ----------------------------------------------------------------------
        # Test 4: Different accounts (Per-account scoping, not global)
        # ----------------------------------------------------------------------
        print("\n--- Running Test 4: Different accounts isolation ---")
        context_u1 = browser.new_context()
        page_u1 = context_u1.new_page()
        page_u1.goto(f"{BASE_URL}/index.html")
        page_u1.fill("#username", "kriz")
        page_u1.fill("#password", "kriz123")
        page_u1.click("button[type='submit']")
        page_u1.wait_for_url("**/registrar/**", timeout=10000)

        context_u2 = browser.new_context()
        page_u2 = context_u2.new_page()
        page_u2.goto(f"{BASE_URL}/index.html")
        page_u2.fill("#username", "tristan")
        page_u2.fill("#password", "tristan123")
        page_u2.click("button[type='submit']")
        page_u2.wait_for_url("**/stations/tlc-helpdesk/**", timeout=10000)

        # Both refresh their protected pages
        page_u1.goto(f"{BASE_URL}/registrar/index.php")
        page_u2.goto(f"{BASE_URL}/stations/tlc-helpdesk/index.php")
        time.sleep(1)

        u1_active = "registrar" in page_u1.url and "index.html" not in page_u1.url
        u2_active = "tlc-helpdesk" in page_u2.url and "index.html" not in page_u2.url
        t4_passed = u1_active and u2_active

        page_u1.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test4_user_kriz_active.png"))
        page_u2.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test4_user_tristan_active.png"))

        test_results.append(log_test_result(
            "Test 4 — Different Accounts Isolation",
            t4_passed,
            f"User 1 ('kriz') active: {u1_active}. User 2 ('tristan') active: {u2_active}. Single-session is strictly per account."
        ))

        # ----------------------------------------------------------------------
        # Test 5: Multiple Tabs in same active browser session
        # ----------------------------------------------------------------------
        print("\n--- Running Test 5: Multiple Tabs in same session ---")
        tab_1 = page_u2  # already open on tlc-helpdesk
        tab_2 = context_u2.new_page()
        tab_2.goto(f"{BASE_URL}/stations/tlc-helpdesk/index.php")
        time.sleep(1)

        tab1_ok = "tlc-helpdesk" in tab_1.url and "index.html" not in tab_1.url
        tab2_ok = "tlc-helpdesk" in tab_2.url and "index.html" not in tab_2.url
        t5_passed = tab1_ok and tab2_ok

        test_results.append(log_test_result(
            "Test 5 — Multiple Tabs in Same Session",
            t5_passed,
            f"Tab 1 active: {tab1_ok}. Tab 2 active: {tab2_ok}. Both tabs share valid active session."
        ))

        # ----------------------------------------------------------------------
        # Test 6: Logout Revocation
        # ----------------------------------------------------------------------
        print("\n--- Running Test 6: Logout Revocation ---")
        context_logout = browser.new_context()
        page_logout = context_logout.new_page()
        page_logout.goto(f"{BASE_URL}/index.html")
        page_logout.fill("#username", "cashier")
        page_logout.fill("#password", "cashier123")
        page_logout.click("button[type='submit']")
        page_logout.wait_for_url("**/stations/payment-processing/**", timeout=10000)

        # Call logout API
        logout_res = context_logout.request.post(f"{BASE_URL}/api/index.php?action=auth/logout")
        logout_json = logout_res.json()

        # Verify active_session_token in DB was cleared to NULL
        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'cashier'")
            token_after_logout = cur.fetchone()[0]
        conn.close()

        # Attempt to access protected API after logout
        post_logout_api = context_logout.request.get(f"{BASE_URL}/api/index.php?action=auth/check")
        post_logout_status = post_logout_api.status

        # Attempt direct navigation after logout
        page_logout.goto(f"{BASE_URL}/stations/payment-processing/index.php")
        time.sleep(1)
        page_blocked_after_logout = ("payment-processing" not in page_logout.url) or ("clear=true" in page_logout.url)

        t6_passed = (token_after_logout is None) and (post_logout_status == 401) and page_blocked_after_logout
        test_results.append(log_test_result(
            "Test 6 — Logout Revocation",
            t6_passed,
            f"DB Token after logout: {token_after_logout}. API status after logout: {post_logout_status}. Page blocked: {page_blocked_after_logout}."
        ))

        # ----------------------------------------------------------------------
        # Test 7: Password Change Session Rotation
        # ----------------------------------------------------------------------
        print("\n--- Running Test 7: Password Change Session Rotation ---")
        # We test password change using the API with current session
        context_pw = browser.new_context()
        login_resp = context_pw.request.post(
            f"{BASE_URL}/api/index.php?action=auth/login",
            data=json.dumps({"username": "tristan", "password": "tristan123"}),
            headers={"Content-Type": "application/json"}
        )
        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'tristan'")
            token_before_pw = cur.fetchone()[0]
        conn.close()

        # Change password to new password, then revert
        pw_res = context_pw.request.post(
            f"{BASE_URL}/api/index.php?action=auth/change_password",
            data=json.dumps({
                "username": "tristan",
                "current_password": "tristan123",
                "new_password": "tristan_new_123"
            }),
            headers={"Content-Type": "application/json"}
        )
        pw_json = pw_res.json()

        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'tristan'")
            token_after_pw = cur.fetchone()[0]
        conn.close()

        # Revert password back immediately so system state is preserved
        context_pw.request.post(
            f"{BASE_URL}/api/index.php?action=auth/change_password",
            data=json.dumps({
                "username": "tristan",
                "current_password": "tristan_new_123",
                "new_password": "tristan123"
            }),
            headers={"Content-Type": "application/json"}
        )

        token_rotated = bool(token_before_pw and token_after_pw and token_before_pw != token_after_pw)
        t7_passed = pw_json.get("success", False) and token_rotated
        test_results.append(log_test_result(
            "Test 7 — Password Change Session Rotation",
            t7_passed,
            f"Password update success: {pw_json.get('success')}. Session token rotated: {token_rotated} (Old: {token_before_pw[:12]}..., New: {token_after_pw[:12]}...)."
        ))

        # ----------------------------------------------------------------------
        # Test 8: Expired / Tampered Session Token Rejection
        # ----------------------------------------------------------------------
        print("\n--- Running Test 8: Expired / Tampered Session Rejection ---")
        context_tampered = browser.new_context()
        # Set fake session cookie with invalid/tampered token
        context_tampered.add_cookies([{
            "name": "PHPSESSID",
            "value": "fake_tampered_session_id_9999",
            "domain": "127.0.0.1",
            "path": "/"
        }])
        tampered_api = context_tampered.request.get(f"{BASE_URL}/api/index.php?action=auth/check")
        t8_passed = (tampered_api.status == 401)
        test_results.append(log_test_result(
            "Test 8 — Expired / Tampered Session Rejection",
            t8_passed,
            f"API Status with tampered session: {tampered_api.status} (Expected 401)."
        ))

        # ----------------------------------------------------------------------
        # Test 9: Direct URL Access Guard
        # ----------------------------------------------------------------------
        print("\n--- Running Test 9: Direct URL Access Guard ---")
        context_unauth = browser.new_context()
        page_unauth = context_unauth.new_page()

        protected_urls = [
            f"{BASE_URL}/admin/index.php",
            f"{BASE_URL}/registrar/index.php",
            f"{BASE_URL}/stations/tlc-helpdesk/index.php",
            f"{BASE_URL}/stations/medical-checkup/index.php",
            f"{BASE_URL}/stations/payment-processing/index.php",
            f"{BASE_URL}/stations/it-center/index.php",
            f"{BASE_URL}/student-portal/index.php"
        ]

        all_blocked = True
        blocked_details = []
        for purl in protected_urls:
            page_unauth.goto(purl)
            time.sleep(0.5)
            # URL should be redirected to gateway with clear=true or auth_required=true
            is_blocked = ("systemtest/index.html" in page_unauth.url or "clear=true" in page_unauth.url or "student-portal/login" in page_unauth.url or "auth_required=true" in page_unauth.url)
            if not is_blocked:
                all_blocked = False
                blocked_details.append(f"LEAK: {purl} -> {page_unauth.url}")
            else:
                blocked_details.append(f"BLOCKED: {purl.split('/')[-2]}")

        t9_passed = all_blocked
        test_results.append(log_test_result(
            "Test 9 — Direct URL Access Guard",
            t9_passed,
            f"All 7 protected portal gateways blocked unauthenticated access: {all_blocked}. Details: {', '.join(blocked_details)}."
        ))

        # ----------------------------------------------------------------------
        # Test 10: Direct API Access Guard
        # ----------------------------------------------------------------------
        print("\n--- Running Test 10: Direct API Access Guard ---")
        protected_apis = [
            f"{BASE_URL}/api/index.php?action=stations/queue",
            f"{BASE_URL}/api/index.php?action=admin/users",
            f"{BASE_URL}/api/index.php?action=admin/analytics",
            f"{BASE_URL}/api/index.php?action=student_portal/dashboard&studentId=2026-1006",
            f"{BASE_URL}/api/index.php?action=student_portal/documents&studentId=2026-1006"
        ]

        all_apis_401 = True
        api_results = []
        for papi in protected_apis:
            r = context_unauth.request.get(papi)
            if r.status != 401:
                all_apis_401 = False
                api_results.append(f"UNGUARDED: {papi} ({r.status})")
            else:
                api_results.append(f"401: {papi.split('=')[-1]}")

        t10_passed = all_apis_401
        test_results.append(log_test_result(
            "Test 10 — Direct API Access Guard",
            t10_passed,
            f"All protected APIs returned 401 Unauthorized: {all_apis_401}. Results: {', '.join(api_results)}."
        ))

        # ----------------------------------------------------------------------
        # Test 11: Security Edge Cases (Privilege Escalation & Cross-Account Access)
        # ----------------------------------------------------------------------
        print("\n--- Running Test 11: Security Edge Cases ---")
        # User 'tristan' is HELPDESK. Attempt to access ADMIN-only endpoint (admin/users)
        priv_res = context_u2.request.get(f"{BASE_URL}/api/index.php?action=admin/users")
        priv_blocked = (priv_res.status in [401, 403])

        # Attempt cross-account student profile access
        cross_res = context_u2.request.get(f"{BASE_URL}/api/index.php?action=auth/profile&username=admin")
        cross_blocked = (cross_res.status in [401, 403])

        t11_passed = priv_blocked and cross_blocked
        test_results.append(log_test_result(
            "Test 11 — Privilege Escalation & Role Separation",
            t11_passed,
            f"Helpdesk access to admin/users rejected ({priv_res.status}). Cross-account profile access rejected ({cross_res.status})."
        ))

        # ----------------------------------------------------------------------
        # Test 12: Student Portal Single-Active Session Enforcement
        # ----------------------------------------------------------------------
        print("\n--- Running Test 12: Student Portal Single-Active Session ---")
        student_id = "2026-1006"
        student_pw = "delacruz"

        # Browser A logs in as student
        context_std_a = browser.new_context()
        page_std_a = context_std_a.new_page()
        page_std_a.goto(f"{BASE_URL}/student-portal/login.html")
        page_std_a.fill("#studentIdInput", student_id)
        page_std_a.fill("#studentPasswordInput", student_pw)
        page_std_a.click("button[type='submit']")
        page_std_a.wait_for_function("() => !window.location.href.includes('login')", timeout=10000)

        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM students WHERE id = %s", (student_id,))
            std_token_1 = cur.fetchone()[0]
        conn.close()

        # Browser B logs in with the SAME student account
        context_std_b = browser.new_context()
        page_std_b = context_std_b.new_page()
        page_std_b.goto(f"{BASE_URL}/student-portal/login.html")
        page_std_b.fill("#studentIdInput", student_id)
        page_std_b.fill("#studentPasswordInput", student_pw)
        page_std_b.click("button[type='submit']")
        page_std_b.wait_for_function("() => !window.location.href.includes('login')", timeout=10000)

        conn = get_db_connection()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM students WHERE id = %s", (student_id,))
            std_token_2 = cur.fetchone()[0]
        conn.close()

        std_tokens_differ = (std_token_1 != std_token_2)

        # Browser A refreshes / calls dashboard
        page_std_a.goto(f"{BASE_URL}/student-portal/index.php")
        time.sleep(1.5)

        std_a_rejected = ("login" in page_std_a.url or "clear=true" in page_std_a.url or "session_invalidated=1" in page_std_a.url)
        
        # Check that invalidated alert exists on student login page
        std_banner_el = page_std_a.locator(".alert-custom-err").first
        std_banner_text = std_banner_el.inner_text() if std_banner_el.is_visible() else ""
        std_msg_match = "invalidated because this account was signed in from another device or browser" in std_banner_text

        # Browser B navigates to student portal and remains authenticated
        page_std_b.goto(f"{BASE_URL}/student-portal/index.php")
        time.sleep(1)
        std_b_active = ("student-portal" in page_std_b.url and "login" not in page_std_b.url)

        page_std_a.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test12_student_browser_a_invalidated.png"))
        page_std_b.screenshot(path=os.path.join(SCREENSHOTS_DIR, "test12_student_browser_b_active.png"))

        t12_passed = std_tokens_differ and std_a_rejected and std_b_active and std_msg_match
        test_results.append(log_test_result(
            "Test 12 — Student Portal Single-Active Session",
            t12_passed,
            f"Tokens differed: {std_tokens_differ}. Student Browser A rejected: {std_a_rejected}. Invalidation Banner shown: {std_msg_match}. Student Browser B active: {std_b_active}."
        ))

        # ----------------------------------------------------------------------
        # Test 13: Workstation Multi-Station Lifecycle & Verification
        # ----------------------------------------------------------------------
        print("\n--- Running Test 13: Workstation Multi-Station Verification ---")
        stations_to_test = [
            ("REGISTRAR", "kriz", "kriz123", f"{BASE_URL}/registrar/index.php", "registrar"),
            ("HELPDESK", "tristan", "tristan123", f"{BASE_URL}/stations/tlc-helpdesk/index.php", "tlc-helpdesk"),
            ("MEDICAL", "ethan", "ethan123", f"{BASE_URL}/stations/medical-checkup/index.php", "medical-checkup"),
            ("CASHIER", "cashier", "cashier123", f"{BASE_URL}/stations/payment-processing/index.php", "payment-processing")
        ]

        station_passes = True
        for st_name, u, pw, entry_url, path_slug in stations_to_test:
            ctx = browser.new_context()
            pg = ctx.new_page()
            pg.goto(f"{BASE_URL}/index.html")
            pg.fill("#username", u)
            pg.fill("#password", pw)
            pg.click("button[type='submit']")
            time.sleep(1.5)
            pg.goto(entry_url)
            time.sleep(1)
            ok = (path_slug in pg.url and "index.html" not in pg.url)
            if not ok:
                station_passes = False
                print(f"      Station {st_name} failed validation. URL: {pg.url}")
            else:
                print(f"      Station {st_name}: OK ({pg.url})")
            ctx.close()

        t13_passed = station_passes
        test_results.append(log_test_result(
            "Test 13 — All Station Portals Operational",
            t13_passed,
            f"Registrar, Helpdesk, Medical, and Cashier stations successfully authenticate and load with single active session protection."
        ))

        browser.close()

    print("\n==========================================================================")
    print("                           TEST SUMMARY RESULTS                           ")
    print("==========================================================================")
    total = len(test_results)
    passed_count = sum(1 for r in test_results if r["passed"])
    failed_count = total - passed_count
    print(f"Total Tests : {total}")
    print(f"Passed      : {passed_count}")
    print(f"Failed      : {failed_count}")
    print(f"Overall     : {'PASSED ALL TESTS' if failed_count == 0 else 'FAILED'}")
    print("==========================================================================\n")

    return failed_count == 0

if __name__ == "__main__":
    success = run_tests()
    sys.exit(0 if success else 1)
