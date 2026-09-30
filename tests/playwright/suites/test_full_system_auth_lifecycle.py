import os
import sys
import time
import json
from playwright.sync_api import sync_playwright

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

BASE_URL = "http://127.0.0.1/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\11697041-483f-49a6-8921-acf78b167c4d"

# Matrix of all roles, credentials, target portal paths, and expected markers
PORTAL_ROLES = [
    {
        "role": "SUPER_ADMIN",
        "name": "Super Admin Console",
        "username": "admin",
        "password": "admin12345",
        "target_path": "/admin/",
        "dom_marker": "#admin-app, .admin-shell, .admin-header, .stat-card",
        "is_student": False
    },
    {
        "role": "REGISTRAR",
        "name": "Registrar Admissions Workstation",
        "username": "kriz",
        "password": "kriz123",
        "target_path": "/registrar/",
        "dom_marker": "#app, .station-shell, .table, h1, h2",
        "is_student": False
    },
    {
        "role": "HELPDESK",
        "name": "Station 1: Advising & Evaluation (TLC Helpdesk)",
        "username": "tristan",
        "password": "tristan123",
        "target_path": "/stations/tlc-helpdesk/",
        "dom_marker": "#app, .station-shell, .advising-layout, header",
        "is_student": False
    },
    {
        "role": "MEDICAL",
        "name": "Station 2: Medical Clearance (Clinic)",
        "username": "ethan",
        "password": "ethan123",
        "target_path": "/stations/medical-checkup/",
        "dom_marker": "#app, .station-shell, .clinic-layout, header",
        "is_student": False
    },
    {
        "role": "CASHIER",
        "name": "Station 3: Payment Processing (Treasury)",
        "username": "cashier",
        "password": "cashier123",
        "target_path": "/stations/payment-processing/",
        "dom_marker": "#app, .station-shell, .cashier-layout, header",
        "is_student": False
    },
    {
        "role": "IT_CENTER",
        "name": "Station 4: IT Center & Account Activation",
        "username": "it_officer",
        "password": "itpassword",
        "target_path": "/stations/it-center/",
        "dom_marker": "#app, .station-shell, .it-center-layout, header",
        "is_student": False
    },
    {
        "role": "SCHEDULER",
        "name": "Academic Scheduling & Faculty Allocation",
        "username": "scheduler",
        "password": "Gncp#2026!",
        "target_path": "/stations/scheduler/",
        "dom_marker": "#app, .scheduler-shell, .top-bar, .stat-card",
        "is_student": False
    },
    {
        "role": "PROFESSOR",
        "name": "Faculty Professor Workstation & LMS",
        "username": "teacher.pipe.8663",
        "password": "Password123!",
        "target_path": "/stations/professor/",
        "dom_marker": "#app, .professor-shell, .top-bar, .stat-card",
        "is_student": False
    },
    {
        "role": "STUDENT",
        "name": "Student Self-Service Portal & LMS",
        "username": "GNCP-2026-7775",
        "password": "applicant8663",
        "target_path": "/student-portal/",
        "dom_marker": "#app, .student-shell, .topbar, .campus-feed-layout, .quick-hub-val",
        "is_student": True
    }
]

def test_role_lifecycle(browser, role_config):
    role = role_config["role"]
    name = role_config["name"]
    username = role_config["username"]
    password = role_config["password"]
    target_path = role_config["target_path"]
    is_student = role_config["is_student"]

    print("\n" + "="*60)
    print(f"TESTING AUTH LIFECYCLE FOR: [{role}] {name}")
    print("="*60)

    # Fresh context to avoid stale cookies or storage leaking
    context = browser.new_context(viewport={"width": 1440, "height": 900})
    page = context.new_page()

    console_errors = []
    page_errors = []
    failed_requests = []

    page.on("console", lambda m: console_errors.append(f"[{m.type}] {m.text}") if m.type == "error" else None)
    page.on("pageerror", lambda e: page_errors.append(str(e)))
    page.on("response", lambda r: failed_requests.append(f"{r.status}: {r.url}") if r.status >= 400 and "favicon" not in r.url else None)

    step_results = {}

    try:
        # ── SUB-STEP 1: LOGIN ──
        if is_student:
            login_url = f"{BASE_URL}/student-portal/login.html"
            page.goto(login_url)
            page.wait_for_selector("#studentIdInput", timeout=10000)
            page.fill("#studentIdInput", username)
            page.fill("#studentPasswordInput", password)
            page.click("button[type='submit']")
            page.wait_for_url(lambda u: "student-portal" in u and "login" not in u, timeout=15000)
        else:
            login_url = f"{BASE_URL}/index.html"
            page.goto(login_url)
            page.wait_for_selector("#username", timeout=10000)
            page.fill("#username", username)
            page.fill("#password", password)
            page.click("button[type='submit']")
            page.wait_for_url(lambda u: target_path in u, timeout=15000)

        page.wait_for_load_state("networkidle")
        time.sleep(2.0)

        post_login_url = page.url
        print(f"  [1. Login] Redirected to: {post_login_url}")
        assert target_path in post_login_url or (is_student and "student-portal" in post_login_url), f"Unexpected URL after login: {post_login_url}"
        step_results["login"] = "PASSED"

        # ── SUB-STEP 2: DOM & RUNTIME STABILITY ──
        marker = role_config["dom_marker"]
        page.wait_for_selector(marker, timeout=10000)
        dom_matched = page.locator(marker).count()
        print(f"  [2. DOM Mount] Found {dom_matched} matching DOM element(s) for marker: {marker}")
        assert dom_matched > 0, f"Marker '{marker}' not found in DOM."

        # Filter out harmless CDN or network hiccups if any, focus on JavaScript/syntax runtime errors
        critical_errors = [e for e in console_errors if "favicon" not in e and "livereload" not in e]
        print(f"  [2. Runtime] Page Errors: {len(page_errors)}, Console Errors: {len(critical_errors)}")
        if page_errors:
            print(f"    Page Error Trace: {page_errors}")
        if critical_errors:
            print(f"    Console Error Trace: {critical_errors}")
        assert len(page_errors) == 0, f"Runtime JavaScript errors detected: {page_errors}"
        step_results["runtime"] = "PASSED"

        # Save screenshot of authenticated portal
        clean_role = role.lower()
        ss_path = os.path.join(ARTIFACT_DIR, f"auth_test_{clean_role}_authenticated.png")
        page.screenshot(path=ss_path, full_page=True)

        # ── SUB-STEP 3: INTERACTIVE LOGOUT ──
        print("  [3. Logout] Initiating interactive logout flow...")
        logout_btn = page.locator(".topbar-logout-btn, .nav-logout, button:has-text('Sign Out'), button:has-text('Logout'), a:has-text('Logout'), a:has-text('Sign Out')").first
        
        # In EmployeeSidebar or custom sidebar, if it's hidden in dropdown, check
        if not logout_btn.is_visible():
            logout_btn = page.locator(".sidebar-footer, .user-profile, #userDropdown, button[title*='Sign Out']").first

        if logout_btn.is_visible():
            logout_btn.scroll_into_view_if_needed()
            logout_btn.click()

            # Wait briefly for modal to appear (SweetAlert or custom confirm overlay)
            try:
                page.wait_for_selector(".swal2-container, .confirm-overlay", state="visible", timeout=3000)
                swal_btn = page.locator(".swal2-container button.swal2-confirm, button.swal2-confirm").first
                overlay_btn = page.locator(".confirm-overlay button.confirm-btn-danger, .confirm-overlay button:has-text('Log Out'), .confirm-overlay button:has-text('Sign Out')").first
                if swal_btn.is_visible():
                    print("    [3. Logout] Clicking SweetAlert confirm button...")
                    swal_btn.click()
                elif overlay_btn.is_visible():
                    print("    [3. Logout] Clicking custom modal confirm button...")
                    overlay_btn.click()
            except Exception:
                pass
            time.sleep(2.0)

        # Wait for redirect to login page
        if is_student:
            page.wait_for_url(lambda u: "login" in u, timeout=15000)
        else:
            page.wait_for_url(lambda u: "index.html" in u or "clear=true" in u or u.endswith("/systemtest/") or "login" in u, timeout=15000)

        page.wait_for_load_state("networkidle")
        time.sleep(1.5)

        post_logout_url = page.url
        print(f"  [3. Logout] Redirected post-logout to: {post_logout_url}")
        step_results["logout"] = "PASSED"

        # Check that session storage was cleared
        storage_check = page.evaluate("() => ({ admin: sessionStorage.getItem('gncp_admin_user'), station: sessionStorage.getItem('gncp_station_user'), student: sessionStorage.getItem('gncp_portal_student') })")
        print(f"  [3. Storage] Active session storage: {storage_check}")
        assert not storage_check["admin"] and not storage_check["station"] and not storage_check["student"], "Session storage not cleared after logout!"
        step_results["storage_purge"] = "PASSED"

        # ── SUB-STEP 4: DIRECT RE-ENTRY PROTECTION ──
        print(f"  [4. Guard] Attempting unauthorized direct navigation to protected portal: {target_path}...")
        page.goto(f"{BASE_URL}{target_path}")
        time.sleep(2.5)

        guard_url = page.url
        print(f"  [4. Guard] Redirected/Landed on: {guard_url}")
        
        # Verify access is blocked (redirected to gateway, login, or clear query)
        is_blocked = ("index.html" in guard_url) or ("login" in guard_url) or ("clear=true" in guard_url) or (guard_url.endswith("/systemtest/"))
        if is_student:
            is_blocked = is_blocked or ("student-portal/login" in guard_url)

        assert is_blocked, f"CRITICAL SECURITY FAIL: Protected portal {target_path} allowed unauthenticated access after logout! Current URL: {guard_url}"
        step_results["unauthorized_guard"] = "PASSED"
        print(f"  ✓ [{role}] ALL 4 LIFECYCLE CHECKS PASSED PERFECTLY.")

        context.close()
        return {
            "role": role,
            "name": name,
            "status": "PASSED",
            "steps": step_results,
            "screenshot": ss_path
        }

    except Exception as e:
        print(f"  ❌ [{role}] FAILED with exception: {e}")
        ss_err = os.path.join(ARTIFACT_DIR, f"auth_test_{role.lower()}_ERROR.png")
        try:
            page.screenshot(path=ss_err)
        except Exception:
            pass
        context.close()
        return {
            "role": role,
            "name": name,
            "status": "FAILED",
            "error": str(e),
            "steps": step_results
        }

def main():
    print("\n" + "="*70)
    print("GNCP FULL-SYSTEM END-TO-END AUTHENTICATION & LOGOUT AUDIT SUITE")
    print("="*70)

    results = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=["--disable-web-security", "--no-sandbox"])
        
        for cfg in PORTAL_ROLES:
            res = test_role_lifecycle(browser, cfg)
            results.append(res)
            time.sleep(1.0)

        browser.close()

    output_file = os.path.join(ARTIFACT_DIR, "full_auth_lifecycle_results.json")
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)

    print("\n" + "="*70)
    print("AUDIT SUMMARY MATRIX:")
    print("="*70)
    all_passed = True
    for r in results:
        status_tag = "✓ PASSED" if r["status"] == "PASSED" else "❌ FAILED"
        print(f"  {status_tag:10} | {r['role']:15} | {r['name']}")
        if r["status"] != "PASSED":
            all_passed = False
            print(f"             Error: {r.get('error')}")

    print("\nOverall Suite Status:", "100% VERIFIED & BULLETPROOF" if all_passed else "FAILURES DETECTED")
    print(f"Full results saved to: {output_file}")

if __name__ == "__main__":
    main()
