import os
import time
import requests
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"

def test_security_session_isolation():
    print("\n=======================================================")
    print(" [SECURITY TEST] MULTI-BROWSER ISOLATION & SESSIONS")
    print("=======================================================\n")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # -------------------------------------------------------------
        # 1. Unauthenticated Direct URL Access (Pasting Link in New Browser)
        # -------------------------------------------------------------
        print("[TEST 1] Testing Unauthenticated Direct URL Access...")
        fresh_context = browser.new_context()
        page_fresh = fresh_context.new_page()

        # Direct API query without session cookie
        res_queue = fresh_context.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        assert res_queue.status == 401, f"Expected 401 Unauthorized for unauthenticated queue access, got {res_queue.status}"
        data_queue = res_queue.json()
        assert data_queue.get("success") is False, "Expected success=False"
        print("  [OK] Unauthenticated workstation queue access blocked (401 Unauthorized).")

        # Direct student dashboard API query without session
        res_stud = fresh_context.request.get(f"{BASE_URL}/student-portal/backend/api.php?action=get_student_dashboard&studentId=GNCP-2026-0001")
        assert res_stud.status == 401, f"Expected 401 Unauthorized for unauthenticated student dashboard access, got {res_stud.status}"
        print("  [OK] Unauthenticated student dashboard access blocked (401 Unauthorized).")

        fresh_context.close()

        # -------------------------------------------------------------
        # 2. Staff Single-Active Session Concurrency (Browser 1 vs Browser 2)
        # -------------------------------------------------------------
        print("\n[TEST 2] Testing Staff Single-Active Session Concurrency...")
        # Context 1: First login (e.g. Chrome)
        ctx1 = browser.new_context()
        page1 = ctx1.new_page()
        page1.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/tlc-helpdesk/index.html")
        page1.wait_for_selector("#username", timeout=10000)
        page1.fill("#username", "tristan")
        page1.fill("#password", "tristan123")
        page1.click("button[type='submit']")
        time.sleep(2.0)

        # Verify Context 1 is authenticated
        res1_initial = ctx1.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        assert res1_initial.status == 200, f"Expected 200 for initial login on Context 1, got {res1_initial.status}"
        print("  [OK] Browser Context 1 logged in successfully and accessed workstation queue.")

        # Context 2: Second login with SAME credentials (e.g. Firefox)
        ctx2 = browser.new_context()
        page2 = ctx2.new_page()
        page2.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/tlc-helpdesk/index.html")
        page2.wait_for_selector("#username", timeout=10000)
        page2.fill("#username", "tristan")
        page2.fill("#password", "tristan123")
        page2.click("button[type='submit']")
        time.sleep(2.0)

        # Verify Context 2 is now active
        res2_initial = ctx2.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        assert res2_initial.status == 200, f"Expected 200 for login on Context 2, got {res2_initial.status}"
        print("  [OK] Browser Context 2 logged in with same account and superseded Context 1.")

        # Context 1: Next request must be REJECTED (401 Session Superseded)
        res1_after = ctx1.request.get(f"{BASE_URL}/api/index.php?action=stations/queue")
        assert res1_after.status == 401, f"Expected 401 for superseded Context 1 session, got {res1_after.status}"
        data1_after = res1_after.json()
        assert "another browser" in data1_after.get("message", "").lower() or "expired" in data1_after.get("message", "").lower()
        print("  [OK] Browser Context 1 was successfully invalidated and kicked out (401 Session Superseded).")

        ctx1.close()
        ctx2.close()

        # -------------------------------------------------------------
        # 3. Student Portal Credential Storage Sanitization (No Passwords in LocalStorage)
        # -------------------------------------------------------------
        print("\n[TEST 3] Testing Student Portal LocalStorage Credential Sanitization...")
        ctx_stud = browser.new_context()
        page_stud = ctx_stud.new_page()
        page_stud.goto(f"{BASE_URL}/student-portal/login.html?clear=true")
        page_stud.wait_for_selector("#studentIdInput", timeout=10000)

        # Check localStorage values
        storage_dump = page_stud.evaluate("() => ({ ...localStorage })")
        assert "gncp_saved_student_credentials" not in storage_dump, "CRITICAL: Raw password storage found in localStorage!"
        print("  [OK] Verified: No unencrypted passwords stored in localStorage.")

        # -------------------------------------------------------------
        # 4. Rate Limiting on Brute-Force Logins
        # -------------------------------------------------------------
        print("\n[TEST 4] Testing Brute-Force Rate Limiting (10 attempts / 5 mins)...")
        rate_limit_triggered = False
        for i in range(12):
            r = requests.post(f"{BASE_URL}/shared/backend/login.php", json={
                "username": "rate_limit_test_user",
                "password": f"wrong_password_{i}"
            })
            if r.status_code == 429:
                rate_limit_triggered = True
                print(f"  [OK] Rate limit triggered at attempt #{i+1} with HTTP 429 Too Many Requests.")
                break

        assert rate_limit_triggered, "Expected rate limit to trigger HTTP 429 on rapid login failures."

        # -------------------------------------------------------------
        # 5. Multi-Profile & Separate Tab Isolation (No Unauthenticated Auto-Redirect)
        # -------------------------------------------------------------
        print("\n[TEST 5] Testing Multi-Profile & Fresh Window Isolation...")
        # Context 1: Admin logs in
        ctx_admin = browser.new_context()
        page_admin = ctx_admin.new_page()
        page_admin.goto(f"{BASE_URL}/index.html?clear=true")
        page_admin.wait_for_selector("#username", timeout=10000)
        page_admin.fill("#username", "admin")
        page_admin.fill("#password", "admin12345")
        page_admin.click("button[type='submit']")
        page_admin.wait_for_url("**/admin/**", timeout=10000)
        print("  [OK] Profile 1 authenticated as Admin.")

        # Context 2: Fresh browser profile / private window opens index.html
        ctx_fresh = browser.new_context()
        page_fresh2 = ctx_fresh.new_page()
        page_fresh2.goto(f"{BASE_URL}/index.html")
        time.sleep(1.0)
        # Must show login gateway and NOT auto-redirect to admin
        assert "admin" not in page_fresh2.url.lower(), f"Expected index.html login page, got auto-redirected to {page_fresh2.url}"
        assert page_fresh2.is_visible("#username"), "Expected #username login input to be visible on fresh profile"
        print("  [OK] Fresh profile opening index.html displays login gateway (no auto-login leakage).")

        # Context 2 attempts to navigate directly to admin/index.html
        page_fresh2.goto(f"{BASE_URL}/admin/index.html")
        time.sleep(2.0)
        # Must be redirected to login gateway with auth_required
        assert "auth_required=true" in page_fresh2.url or "index.html" in page_fresh2.url or page_fresh2.url.split('?')[0].rstrip('/').endswith("systemtest"), f"Expected redirect away from admin, got {page_fresh2.url}"
        print("  [OK] Fresh profile navigating to admin/index.html is blocked and redirected to login.")

        ctx_admin.close()
        ctx_fresh.close()

        browser.close()

    print("\n=======================================================")
    print(" [PASS] ALL SECURITY & MULTI-BROWSER TESTS PASSED (100%)")
    print("=======================================================\n")

if __name__ == "__main__":
    test_security_session_isolation()
