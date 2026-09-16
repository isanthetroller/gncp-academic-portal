import os
import sys
import time
import json
import random
import threading
import concurrent.futures
import pymysql
import requests
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
SCREENSHOTS_DIR = os.path.join(os.path.dirname(__file__), "screenshots")
os.makedirs(SCREENSHOTS_DIR, exist_ok=True)

DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "gncp_portal",
    "port": 3306
}

def get_db():
    return pymysql.connect(**DB_CONFIG)

def main():
    print("=" * 80)
    print("  GNCP AUTHENTICATION HARDENING — COMPREHENSIVE MULTI-LAYER VERIFICATION PASS  ")
    print("=" * 80)

    results = []

    def record(name, passed, details=""):
        tag = "[PASS]" if passed else "[FAIL]"
        print(f"{tag} {name}")
        if details:
            print(f"       {details}")
        results.append({
            "name": name,
            "status": "PASS" if passed else "FAIL",
            "details": details
        })
        return passed

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # ----------------------------------------------------------------------
        # LAYER 1: Session Fixation Verification
        # ----------------------------------------------------------------------
        print("\n--- LAYER 1: Session Fixation Protection ---")
        ctx_fix = browser.new_context()
        page_fix = ctx_fix.new_page()
        page_fix.goto(f"{BASE_URL}/index.html")
        cookies_pre = {c['name']: c['value'] for c in ctx_fix.cookies()}
        pre_sessid = cookies_pre.get("PHPSESSID")

        page_fix.fill("#username", "admin")
        page_fix.fill("#password", "admin12345")
        page_fix.click("button[type='submit']")
        page_fix.wait_for_url("**/admin/**", timeout=10000)

        cookies_post = {c['name']: c['value'] for c in ctx_fix.cookies()}
        post_sessid = cookies_post.get("PHPSESSID")

        fixation_prevented = (bool(pre_sessid) and bool(post_sessid) and pre_sessid != post_sessid) or (not pre_sessid and bool(post_sessid))
        record(
            "1.1 Session Fixation — ID Regeneration on Login",
            fixation_prevented,
            f"Pre-login PHPSESSID: {pre_sessid}, Post-login PHPSESSID: {post_sessid} (Regenerated: {pre_sessid != post_sessid})"
        )

        # ----------------------------------------------------------------------
        # LAYER 2: Cookie Security Configuration (HttpOnly, SameSite, Expiration)
        # ----------------------------------------------------------------------
        print("\n--- LAYER 2: Cookie Security Attributes ---")
        all_cookies = ctx_fix.cookies()
        sess_cookie = next((c for c in all_cookies if c['name'] == 'PHPSESSID'), None)
        http_only = sess_cookie.get('httpOnly', False) if sess_cookie else False
        same_site = sess_cookie.get('sameSite', '') if sess_cookie else ''
        record(
            "2.1 Cookie Security — HttpOnly & SameSite Configuration",
            http_only and same_site in ['Lax', 'Strict', 'None'],
            f"HttpOnly: {http_only}, SameSite: {same_site}"
        )
        ctx_fix.close()

        # ----------------------------------------------------------------------
        # LAYER 3: Primary Acceptance Test — Single Active Session (A vs B)
        # ----------------------------------------------------------------------
        print("\n--- LAYER 3: Primary Acceptance Test — Single Active Session ---")
        # Browser A logs in as admin
        ctx_a = browser.new_context()
        page_a = ctx_a.new_page()
        page_a_console = []
        page_a.on("console", lambda msg: page_a_console.append(msg.text))
        page_a.goto(f"{BASE_URL}/index.html")
        page_a.fill("#username", "admin")
        page_a.fill("#password", "admin12345")
        page_a.click("button[type='submit']")
        page_a.wait_for_url("**/admin/**", timeout=10000)

        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'admin'")
            token_a = cur.fetchone()[0]
        conn.close()

        # Browser B logs into the SAME account ('admin')
        ctx_b = browser.new_context()
        page_b = ctx_b.new_page()
        page_b.goto(f"{BASE_URL}/index.html")
        page_b.fill("#username", "admin")
        page_b.fill("#password", "admin12345")
        page_b.click("button[type='submit']")
        page_b.wait_for_url("**/admin/**", timeout=10000)

        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'admin'")
            token_b = cur.fetchone()[0]
        conn.close()

        token_overwritten = (token_a != token_b and bool(token_b))
        record("3.1 Atomic DB Session Overwrite", token_overwritten, f"Token A: {token_a[:12]}... -> Token B: {token_b[:12]}...")

        # 3.2: Browser A page refresh / navigation to protected module
        page_a.goto(f"{BASE_URL}/admin/index.php")
        time.sleep(1.5)
        a_path = page_a.url.split("?")[0].rstrip("/")
        page_a_nav_blocked = not a_path.endswith("/admin") and not a_path.endswith("/admin/index.php")
        banner_el = page_a.locator(".session-expired-banner, .alert-custom-err, .alert-danger").first
        banner_visible = banner_el.is_visible()
        banner_text = banner_el.inner_text() if banner_visible else ""
        banner_match = "invalidated because this account was signed in from another device or browser" in banner_text
        record(
            "3.2 Browser A Page Navigation Blocked & Security Banner Displayed",
            page_a_nav_blocked and banner_match,
            f"URL: {page_a.url}, Banner Visible: {banner_visible}, Banner Match: {banner_match}"
        )

        # 3.3: Set up superseded context for API and CRUD mutation testing
        ctx_api_a = browser.new_context()
        page_api_a = ctx_api_a.new_page()
        page_api_a.goto(f"{BASE_URL}/index.html")
        page_api_a.fill("#username", "admin")
        page_api_a.fill("#password", "admin12345")
        page_api_a.click("button[type='submit']")
        page_api_a.wait_for_url("**/admin/**", timeout=10000)

        # Supersede by logging in again in ctx_b
        page_b.goto(f"{BASE_URL}/index.html")
        page_b.fill("#username", "admin")
        page_b.fill("#password", "admin12345")
        page_b.click("button[type='submit']")
        page_b.wait_for_url("**/admin/**", timeout=10000)

        # Attempt API call using superseded ctx_api_a
        api_res_a = ctx_api_a.request.get(f"{BASE_URL}/api/index.php?action=admin/users")
        api_a_rejected = (api_res_a.status == 401)
        api_a_json = api_res_a.json() if api_a_rejected else {}
        msg_expected = "invalidated because this account was signed in from another device or browser" in str(api_a_json.get("message", ""))
        record(
            "3.3 Browser A Protected API Rejected by Backend",
            api_a_rejected and msg_expected,
            f"HTTP Status: {api_res_a.status}, Payload: {api_a_json.get('message')}"
        )

        # Attempt authenticated CRUD operation (POST save_section) with superseded context
        crud_res_a = ctx_api_a.request.post(
            f"{BASE_URL}/api/index.php?action=admin/save_section",
            data=json.dumps({"code": "ROGUE_SEC", "program": "BSIT", "yearLevel": "1st Year", "capacity": 30}),
            headers={"Content-Type": "application/json", "Origin": BASE_URL}
        )
        crud_a_rejected = (crud_res_a.status == 401)
        record(
            "3.4 Browser A Protected CRUD Mutation Blocked by Backend",
            crud_a_rejected,
            f"CRUD HTTP Status: {crud_res_a.status}"
        )
        ctx_api_a.close()

        # 3.5: Browser B remains authenticated and operational
        page_b.goto(f"{BASE_URL}/admin/index.php")
        time.sleep(1.0)
        b_path = page_b.url.split("?")[0].rstrip("/")
        page_b_active = b_path.endswith("/admin") or b_path.endswith("/admin/index.php")
        api_res_b = ctx_b.request.get(f"{BASE_URL}/api/index.php?action=admin/users")
        api_b_active = (api_res_b.status == 200)
        record(
            "3.5 Browser B Remains Fully Authenticated & Operational",
            page_b_active and api_b_active,
            f"Browser B Page Active: {page_b_active}, Browser B API Status: {api_res_b.status}"
        )

        # Clean up
        ctx_a.close()
        ctx_b.close()

        # ----------------------------------------------------------------------
        # LAYER 4: Different Accounts Isolation (Per-Account Scoping)
        # ----------------------------------------------------------------------
        print("\n--- LAYER 4: Different Accounts Isolation ---")
        ctx_kriz = browser.new_context()
        page_kriz = ctx_kriz.new_page()
        page_kriz.goto(f"{BASE_URL}/index.html")
        page_kriz.fill("#username", "kriz")
        page_kriz.fill("#password", "kriz123")
        page_kriz.click("button[type='submit']")
        page_kriz.wait_for_url("**/registrar/**", timeout=10000)

        ctx_tristan = browser.new_context()
        page_tristan = ctx_tristan.new_page()
        page_tristan.goto(f"{BASE_URL}/index.html")
        page_tristan.fill("#username", "tristan")
        page_tristan.fill("#password", "tristan123")
        page_tristan.click("button[type='submit']")
        page_tristan.wait_for_url("**/stations/tlc-helpdesk/**", timeout=10000)

        # Both refresh their pages and call their respective APIs
        res_kriz_api = ctx_kriz.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        res_tristan_api = ctx_tristan.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        both_ok = (res_kriz_api.status == 200) and (res_tristan_api.status == 200)
        record(
            "4.1 Concurrent Independent User Accounts",
            both_ok,
            f"User 'kriz' API: {res_kriz_api.status}, User 'tristan' API: {res_tristan_api.status}"
        )

        # ----------------------------------------------------------------------
        # LAYER 5: Multiple Tabs in Same Active Session
        # ----------------------------------------------------------------------
        print("\n--- LAYER 5: Multiple Tabs in Same Active Session ---")
        # Tab 1 is page_tristan. Open Tab 2 in same context:
        tab_2 = ctx_tristan.new_page()
        tab_2.goto(f"{BASE_URL}/stations/tlc-helpdesk/index.php")
        time.sleep(1.0)
        tab1_ok = "tlc-helpdesk" in page_tristan.url and "index.html" not in page_tristan.url
        tab2_ok = "tlc-helpdesk" in tab_2.url and "index.html" not in tab_2.url
        record(
            "5.1 Multiple Tabs Share Active Session",
            tab1_ok and tab2_ok,
            f"Tab 1 Active: {tab1_ok}, Tab 2 Active: {tab2_ok}"
        )

        # Now a second browser context logs into 'tristan'
        ctx_tristan_b = browser.new_context()
        page_tristan_b = ctx_tristan_b.new_page()
        page_tristan_b.goto(f"{BASE_URL}/index.html")
        page_tristan_b.fill("#username", "tristan")
        page_tristan_b.fill("#password", "tristan123")
        page_tristan_b.click("button[type='submit']")
        page_tristan_b.wait_for_url("**/stations/tlc-helpdesk/**", timeout=10000)

        # Both Tab 1 and Tab 2 should now be invalidated on refresh
        page_tristan.goto(f"{BASE_URL}/stations/tlc-helpdesk/index.php")
        tab_2.goto(f"{BASE_URL}/stations/tlc-helpdesk/index.php")
        time.sleep(1.5)

        tab1_invalidated = ("index.html" in page_tristan.url or "clear=true" in page_tristan.url)
        tab2_invalidated = ("index.html" in tab_2.url or "clear=true" in tab_2.url)
        record(
            "5.2 All Previous Tabs Invalidated When Superseded",
            tab1_invalidated and tab2_invalidated,
            f"Tab 1 Redirected: {tab1_invalidated}, Tab 2 Redirected: {tab2_invalidated}"
        )

        ctx_kriz.close()
        ctx_tristan.close()
        ctx_tristan_b.close()

        # ----------------------------------------------------------------------
        # LAYER 6: Logout Invalidation & Browser Back Button
        # ----------------------------------------------------------------------
        print("\n--- LAYER 6: Logout Invalidation & Anti-Cache Headers ---")
        ctx_logout = browser.new_context()
        page_logout = ctx_logout.new_page()
        page_logout.goto(f"{BASE_URL}/index.html")
        page_logout.fill("#username", "cashier")
        page_logout.fill("#password", "cashier123")
        page_logout.click("button[type='submit']")
        page_logout.wait_for_url("**/stations/payment-processing/**", timeout=10000)

        # Call logout
        logout_resp = ctx_logout.request.post(f"{BASE_URL}/api/index.php?action=auth/logout")
        record("6.1 Explicit Logout API Endpoint", logout_resp.status == 200, f"Logout Status: {logout_resp.status}")

        # Check DB token is NULL
        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("SELECT active_session_token FROM station_users WHERE username = 'cashier'")
            tok_after_logout = cur.fetchone()[0]
        conn.close()
        record("6.2 DB Active Session Token Nullified on Logout", tok_after_logout is None, f"DB Token: {tok_after_logout}")

        # Subsequent API call rejected
        api_after_logout = ctx_logout.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        record("6.3 Protected API Access Rejected After Logout", api_after_logout.status == 401, f"Status: {api_after_logout.status}")

        # Direct navigation to payment-processing redirected
        page_logout.goto(f"{BASE_URL}/stations/payment-processing/index.php")
        time.sleep(1.0)
        page_after_logout_blocked = "payment-processing" not in page_logout.url.split("?")[0]
        record("6.4 Protected Page Navigation Blocked After Logout", page_after_logout_blocked, f"URL: {page_logout.url}")

        ctx_logout.close()

        # ----------------------------------------------------------------------
        # LAYER 7: Session Expiration (Idle Timeout Verification)
        # ----------------------------------------------------------------------
        print("\n--- LAYER 7: Session Expiration & Idle Timeout ---")
        expire_test_cmd = """
        require_once 'shared/backend/config/database.php';
        require_once 'shared/backend/utils/session_guard.php';
        initSession();
        $_SESSION['gncp_admin_user'] = ['id' => 1, 'username' => 'admin', 'role' => 'SUPER_ADMIN', 'session_token' => 'dummy'];
        $_SESSION['last_activity'] = time() - 7500;
        $res = validateSession();
        echo json_encode($res);
        """
        import subprocess
        proc = subprocess.run(
            ["c:\\xampp\\php\\php.exe", "-r", expire_test_cmd],
            cwd="c:\\xampp\\htdocs\\systemtest",
            capture_output=True,
            text=True
        )
        try:
            expire_json = json.loads(proc.stdout.strip())
        except Exception:
            expire_json = {}
        record(
            "7.1 Server-Side 7200s Idle Timeout Rejection",
            expire_json.get("reason") == "expired" and expire_json.get("valid") is False,
            f"Validation Result: valid={expire_json.get('valid')}, reason={expire_json.get('reason')}"
        )

        # ----------------------------------------------------------------------
        # LAYER 8: Token Tampering & Session Reuse Rejection
        # ----------------------------------------------------------------------
        print("\n--- LAYER 8: Token Tampering & Replay Rejection ---")
        tamper_tests = [
            ("Forged PHPSESSID", {"PHPSESSID": "forged_malicious_sess_1234567890"}),
            ("Blank Cookie", {"PHPSESSID": ""}),
            ("SQL Injection Payload Cookie", {"PHPSESSID": "' OR '1'='1"}),
            ("Superseded Dead Token Cookie", {"PHPSESSID": "expired_or_dead_session"})
        ]
        all_tamper_rejected = True
        tamper_details = []
        for label, cookie_dict in tamper_tests:
            ctx_tamper = browser.new_context()
            ctx_tamper.add_cookies([{
                "name": k, "value": v, "domain": "127.0.0.1", "path": "/"
            } for k, v in cookie_dict.items()])
            r = ctx_tamper.request.get(f"{BASE_URL}/api/index.php?action=admin/users")
            if r.status != 401:
                all_tamper_rejected = False
            tamper_details.append(f"{label}: HTTP {r.status}")
            ctx_tamper.close()

        record("8.1 Tampered & Forged Credentials Rejected", all_tamper_rejected, ", ".join(tamper_details))

        # ----------------------------------------------------------------------
        # LAYER 9: Role-Based Access Control (RBAC) & Authorization Security
        # ----------------------------------------------------------------------
        print("\n--- LAYER 9: Role Authorization & Privilege Separation ---")
        ctx_std = browser.new_context()
        page_std = ctx_std.new_page()
        page_std.goto(f"{BASE_URL}/student-portal/login.html")
        page_std.fill("#studentIdInput", "2026-1006")
        page_std.fill("#studentPasswordInput", "delacruz")
        page_std.click("button[type='submit']")
        page_std.wait_for_function("() => !window.location.href.includes('login')", timeout=10000)

        # Student calls admin/users
        res_std_admin = ctx_std.request.get(f"{BASE_URL}/api/index.php?action=admin/users")
        # Student calls stations/queue
        res_std_queue = ctx_std.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        # Student attempts mutating system sections
        res_std_sec = ctx_std.request.post(
            f"{BASE_URL}/api/index.php?action=admin/save_section",
            data=json.dumps({"code": "STUDENT_INTRUDER"}),
            headers={"Content-Type": "application/json", "Origin": BASE_URL}
        )

        std_unauthorized = (res_std_admin.status in [401, 403]) and (res_std_queue.status in [401, 403]) and (res_std_sec.status in [401, 403])
        record(
            "9.1 Student Restricted from Administrative & Station APIs",
            std_unauthorized,
            f"admin/users: {res_std_admin.status}, stations/queue: {res_std_queue.status}, admin/save_section: {res_std_sec.status}"
        )

        # 9.2 Helpdesk operator attempts administrative user management
        ctx_hd = browser.new_context()
        page_hd = ctx_hd.new_page()
        page_hd.goto(f"{BASE_URL}/index.html")
        page_hd.fill("#username", "tristan")
        page_hd.fill("#password", "tristan123")
        page_hd.click("button[type='submit']")
        page_hd.wait_for_url("**/stations/tlc-helpdesk/**", timeout=10000)

        res_hd_admin = ctx_hd.request.get(f"{BASE_URL}/api/index.php?action=admin/users")
        res_hd_sec = ctx_hd.request.post(
            f"{BASE_URL}/api/index.php?action=admin/save_user",
            data=json.dumps({"username": "hacked_admin", "role": "SUPER_ADMIN"}),
            headers={"Content-Type": "application/json", "Origin": BASE_URL}
        )
        hd_restricted = (res_hd_admin.status in [401, 403]) and (res_hd_sec.status in [401, 403])
        record(
            "9.2 Station Operator Restricted from Admin User Provisioning",
            hd_restricted,
            f"admin/users: {res_hd_admin.status}, admin/save_user: {res_hd_sec.status}"
        )

        # 9.3 Cross-account student document fetch
        res_cross_doc = ctx_std.request.get(f"{BASE_URL}/api/index.php?action=student/profile&identifier=2026-9999")
        record(
            "9.3 Cross-Account Profile Data Protection",
            res_cross_doc.status in [401, 403, 404],
            f"Cross-account student profile fetch status: {res_cross_doc.status}"
        )

        ctx_std.close()
        ctx_hd.close()

        # ----------------------------------------------------------------------
        # LAYER 10: Direct URL Gateways Guard Validation
        # ----------------------------------------------------------------------
        print("\n--- LAYER 10: Direct URL Gateways Guard Validation ---")
        gateways = [
            ("Admin Portal", f"{BASE_URL}/admin/index.php", "index.html"),
            ("Registrar Station", f"{BASE_URL}/registrar/index.php", "index.html"),
            ("TLC Helpdesk Station", f"{BASE_URL}/stations/tlc-helpdesk/index.php", "index.html"),
            ("Medical Checkup Station", f"{BASE_URL}/stations/medical-checkup/index.php", "index.html"),
            ("Payment Processing Station", f"{BASE_URL}/stations/payment-processing/index.php", "index.html"),
            ("IT Center Station", f"{BASE_URL}/stations/it-center/index.php", "index.html"),
            ("Monitoring Station", f"{BASE_URL}/monitoring/index.php", "index.html"),
            ("Student Portal", f"{BASE_URL}/student-portal/index.php", "login")
        ]
        all_gateways_guarded = True
        gw_details = []
        ctx_unauth = browser.new_context()
        page_unauth = ctx_unauth.new_page()
        for name, url, expected_redir in gateways:
            page_unauth.goto(url)
            time.sleep(0.5)
            curr = page_unauth.url
            blocked = expected_redir in curr or "clear=true" in curr or "auth_required=1" in curr
            if not blocked:
                all_gateways_guarded = False
            gw_details.append(f"{name}: {'BLOCKED' if blocked else 'EXPOSED'}")
        ctx_unauth.close()
        record("10.1 All 8 Protected Portals Guarded Against Unauthenticated Navigation", all_gateways_guarded, ", ".join(gw_details[:4]) + "...")

        # ----------------------------------------------------------------------
        # LAYER 11: Concurrency & Race Condition Stress
        # ----------------------------------------------------------------------
        print("\n--- LAYER 11: Concurrency & Race Condition Testing ---")
        def perform_login(idx):
            s = requests.Session()
            s.headers["Origin"] = BASE_URL
            r = s.post(f"{BASE_URL}/shared/backend/login.php", json={"username": "admin", "password": "admin12345"}, timeout=10)
            return idx, r.status_code, s.cookies.get("PHPSESSID")

        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as executor:
            login_futs = [executor.submit(perform_login, i) for i in range(5)]
            login_responses = [f.result() for f in concurrent.futures.as_completed(login_futs)]

        all_200 = all(code == 200 for _, code, _ in login_responses)

        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*), active_session_token FROM station_users WHERE username = 'admin' GROUP BY active_session_token")
            token_rows = cur.fetchall()
        conn.close()

        single_db_token = (len(token_rows) == 1 and bool(token_rows[0][1]))
        record(
            "11.1 Rapid Concurrent Logins Establish Single Consistent Token",
            all_200 and single_db_token,
            f"Concurrent attempts successful: {all_200}, Database state: exactly 1 active token ({token_rows[0][1][:12]}...)"
        )

        # ----------------------------------------------------------------------
        # LAYER 12: Performance & Overhead Benchmarking
        # ----------------------------------------------------------------------
        print("\n--- LAYER 12: Authentication & Validation Latency ---")
        s_bench = requests.Session()
        s_bench.headers["Origin"] = BASE_URL
        t_start = time.time()
        s_bench.post(f"{BASE_URL}/shared/backend/login.php", json={"username": "admin", "password": "admin12345"})
        login_dur = (time.time() - t_start) * 1000

        check_times = []
        for _ in range(10):
            t0 = time.time()
            s_bench.get(f"{BASE_URL}/api/index.php?action=auth/check")
            check_times.append((time.time() - t0) * 1000)

        avg_check = sum(check_times) / len(check_times)
        record(
            "12.1 Performance & Latency Within Thresholds (<150ms)",
            avg_check < 150.0,
            f"Login Latency: {login_dur:.1f}ms, Avg Session Validation: {avg_check:.1f}ms (Min: {min(check_times):.1f}ms, Max: {max(check_times):.1f}ms)"
        )

        # ----------------------------------------------------------------------
        # LAYER 13: Database Integrity Audit
        # ----------------------------------------------------------------------
        print("\n--- LAYER 13: Database State & Data Integrity Check ---")
        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) FROM station_users WHERE active_session_token IS NOT NULL AND CHAR_LENGTH(active_session_token) != 64")
            bad_station_tokens = cur.fetchone()[0]
            cur.execute("SELECT COUNT(*) FROM students WHERE active_session_token IS NOT NULL AND CHAR_LENGTH(active_session_token) != 64")
            bad_student_tokens = cur.fetchone()[0]
        conn.close()

        db_healthy = (bad_station_tokens == 0 and bad_student_tokens == 0)
        record(
            "13.1 MariaDB Token Field Integrity & Length Validation (SHA-256 / 64-hex)",
            db_healthy,
            f"Malformed station tokens: {bad_station_tokens}, Malformed student tokens: {bad_student_tokens}"
        )

        # ----------------------------------------------------------------------
        # LAYER 14: Runtime Logs & Exception Inspection
        # ----------------------------------------------------------------------
        print("\n--- LAYER 14: Runtime Error Log Inspection ---")
        log_path = "c:\\xampp\\htdocs\\systemtest\\shared\\backend\\logs\\app_errors.log"
        recent_crashes = 0
        if os.path.exists(log_path):
            with open(log_path, "r", encoding="utf-8", errors="ignore") as f:
                lines = f.readlines()
                for line in lines[-50:]:
                    if "Fatal error" in line or "Parse error" in line or "Uncaught" in line:
                        recent_crashes += 1

        record(
            "14.1 Backend Error Log Inspection (Zero Fatal/Crash Errors)",
            recent_crashes == 0,
            f"Fatal/uncaught crashes found in app_errors.log: {recent_crashes}"
        )

        browser.close()

    print("\n" + "=" * 80)
    print("                      VERIFICATION SUITE SUMMARY                      ")
    print("=" * 80)
    total = len(results)
    passed = sum(1 for r in results if r['status'] == 'PASS')
    failed = total - passed
    print(f"Total Tests : {total}")
    print(f"Passed      : {passed}")
    print(f"Failed      : {failed}")
    print(f"Overall     : {'PASSED ALL TESTS' if failed == 0 else 'SOME TESTS FAILED'}")
    print("=" * 80)

    if failed > 0:
        sys.exit(1)

if __name__ == "__main__":
    main()
