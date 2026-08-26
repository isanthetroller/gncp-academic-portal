import time
import os
import sys
import argparse

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

from playwright.sync_api import sync_playwright

import config
from utils.db_helper import DBHelper
from utils.report_generator import ReportGenerator

# Import Test Suites
from suites.test_auth_and_session import AuthAndSessionSuite
from suites.test_file_uploads import FileUploadsSuite
from suites.test_e2e_student_lifecycle import E2EStudentLifecycleSuite
from suites.test_admin_portal import AdminPortalSuite

def main():
    parser = argparse.ArgumentParser(description="Master Playwright End-to-End Test Runner")
    parser.add_argument("--headless", action="store_true", default=True, help="Run browser in headless mode (default: True)")
    parser.add_argument("--headed", dest="headless", action="store_false", help="Run browser in headed/visible mode")
    parser.add_argument("--slowmo", type=int, default=0, help="Slow down Playwright operations by N milliseconds")
    parser.add_argument("--suite", type=str, default="all", help="Specific suite to execute (auth, uploads, lifecycle, admin, all)")
    args = parser.parse_args()

    print("================================================================================")
    print("GNCP ACADEMIC & ENROLLMENT SYSTEM - PLAYWRIGHT E2E TEST RUNNER")
    print(f"   Base URL:  {config.BASE_URL}")
    print(f"   Mode:      {'Headless' if args.headless else 'Headed (Visible Browser)'}")
    print(f"   Engine:    Chromium (Real Browser)")
    print("================================================================================")

    total_start = time.time()
    suite_results = []

    # Clean up stale test students before run
    try:
        DBHelper.cleanup_test_students()
    except Exception as e:
        print(f"Notice: Pre-test cleanup: {e}")

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=args.headless,
            slow_mo=args.slowmo,
            args=[
                "--start-maximized",
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--ignore-certificate-errors"
            ]
        )
        context = browser.new_context(
            viewport={"width": 1440, "height": 900} if args.headless else None,
            no_viewport=not args.headless,
            ignore_https_errors=True,
            record_video_dir=config.PLAYWRIGHT_DIR + "/videos" if not args.headless else None
        )
        page = context.new_page()
        page.set_default_timeout(config.DEFAULT_TIMEOUT_MS)

        # ── Suite 1: Authentication & Session Lifecycle ───────────
        if args.suite in ["all", "auth", "1"]:
            suite1 = AuthAndSessionSuite(page)
            res1 = suite1.run()
            suite_results.append(res1)

        # ── Suite 2: File Uploads & Security Enforcement ──────────
        if args.suite in ["all", "uploads", "files", "2"]:
            suite2 = FileUploadsSuite(page)
            res2 = suite2.run()
            suite_results.append(res2)

        # ── Suite 3: End-to-End Multi-Station Student Lifecycle ───
        if args.suite in ["all", "lifecycle", "student", "3"]:
            suite3 = E2EStudentLifecycleSuite(page)
            res3 = suite3.run()
            suite_results.append(res3)

        # ── Suite 4: Super Admin Management & Catalog Portal ──────
        if args.suite in ["all", "admin", "4"]:
            suite4 = AdminPortalSuite(page)
            res4 = suite4.run()
            suite_results.append(res4)

        context.close()
        browser.close()

    total_duration = time.time() - total_start

    # Generate HTML Test Report
    report_path = os.path.join(config.REPORTS_DIR, "playwright_report.html")
    ReportGenerator.generate_html_report(suite_results, total_duration, report_path)

    # CLI Summary Output
    passed = sum(1 for s in suite_results if s["status"] == "PASSED")
    failed = sum(1 for s in suite_results if s["status"] == "FAILED")
    total_steps = sum(len(s.get("steps", [])) for s in suite_results)
    passed_steps = sum(sum(1 for st in s.get("steps", []) if st.get("status") == "PASSED") for s in suite_results)

    print("\n================================================================================")
    print("📊 PLAYWRIGHT TEST SUITE SUMMARY EXECUTION RESULTS")
    print("================================================================================")
    for s in suite_results:
        st_icon = "✅ PASS" if s["status"] == "PASSED" else "❌ FAIL"
        print(f"  {st_icon} | {s['name']:55s} | Duration: {s.get('duration', 0):.2f}s")
        for step in s.get("steps", []):
            step_icon = "  ✓" if step["status"] == "PASSED" else "  ✗"
            print(f"      {step_icon} {step['name']:50s} [{step['status']}]")
            if step.get("error"):
                print(f"         ⚠ Error: {step['error']}")

    print("--------------------------------------------------------------------------------")
    print(f"  Total Suites Run : {len(suite_results)}")
    print(f"  Suites Passed    : {passed}")
    print(f"  Suites Failed    : {failed}")
    print(f"  Total Steps Run  : {total_steps}")
    print(f"  Steps Passed     : {passed_steps}")
    print(f"  Total Duration   : {total_duration:.2f} seconds")
    print(f"  HTML Test Report : {report_path}")
    print("================================================================================")

    if failed > 0:
        sys.exit(1)
    else:
        sys.exit(0)

if __name__ == "__main__":
    main()
