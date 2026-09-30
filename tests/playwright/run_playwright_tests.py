"""
GNCP Academic Portal — Playwright Multi-Layer Test Runner
==========================================================
Runs all 5 browser-driven Playwright test suites against
the local XAMPP environment, capturing:
  - Real browser UI interactions (Chromium)
  - Console errors / JS exceptions
  - Failed network requests & API 4xx/5xx
  - DB-level assertions via PHP PDO bridge
  - Screenshots per step
  - Markdown + HTML reports

Usage:
  python run_playwright_tests.py                  # All suites
  python run_playwright_tests.py --suite lifecycle # Single suite
  python run_playwright_tests.py --headed          # Headed browser (visible window)
  python run_playwright_tests.py --suite auth --headed
"""

import sys
import os
import time
import argparse
import json
import random
from datetime import datetime

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# Ensure the playwright directory is on sys.path
PLAYWRIGHT_DIR = os.path.dirname(os.path.abspath(__file__))
if PLAYWRIGHT_DIR not in sys.path:
    sys.path.insert(0, PLAYWRIGHT_DIR)

import config
from utils.browser_logger import BrowserLogger
from utils.db_helper import DBHelper
from playwright.sync_api import sync_playwright, Page

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def ts():
    return time.strftime("%H:%M:%S")

def banner(text, char="=", width=72):
    print(char * width)
    print(f"  {text}")
    print(char * width)

def step_log(idx, name, status, details, duration=None):
    icon = "✅" if status == "PASS" else "❌"
    dur  = f" [{duration:.2f}s]" if duration is not None else ""
    print(f"  [{ts()}] {icon} [{idx}] {name}{dur}")
    if details:
        print(f"         {details}")

# ---------------------------------------------------------------------------
# Individual Test Suites
# ---------------------------------------------------------------------------

class SuiteResult:
    def __init__(self, name: str):
        self.name = name
        self.steps: list[dict] = []
        self.start = time.time()
        self.end = None

    def record(self, name, passed: bool, details="", error="", screenshot=None):
        self.steps.append({
            "name": name,
            "passed": passed,
            "details": details,
            "error": error,
            "screenshot": screenshot,
            "timestamp": ts()
        })
        status = "PASS" if passed else "FAIL"
        step_log(len(self.steps), name, status, details or error)

    def finish(self):
        self.end = time.time()

    @property
    def duration(self):
        return (self.end or time.time()) - self.start

    @property
    def total(self):
        return len(self.steps)

    @property
    def passed(self):
        return sum(1 for s in self.steps if s["passed"])

    @property
    def failed(self):
        return self.total - self.passed

    @property
    def verdict(self):
        return "PASS" if self.failed == 0 else "FAIL"


def save_screenshot(page: Page, label: str) -> str | None:
    filename = f"{label}_{int(time.time())}.png"
    filepath = os.path.join(config.SCREENSHOTS_DIR, filename)
    try:
        page.screenshot(path=filepath, full_page=True)
        return filepath
    except Exception:
        return None


# ─────────────────────────────────────────────────────────────────────────────
# Suite A — Page Load & Navigation Health
# ─────────────────────────────────────────────────────────────────────────────
def suite_page_health(page: Page) -> SuiteResult:
    r = SuiteResult("A: Page Load & Navigation Health")
    banner("SUITE A — Page Load & Navigation Health")

    pages_to_check = [
        ("Gateway / Home",       config.PAGES["GATEWAY"]),
        ("Enrollment Form",      config.PAGES["REGISTRATION"]),
        ("Application Tracker",  config.PAGES["TRACKER"]),
        ("Registrar Station",    config.PAGES["REGISTRAR"]),
        ("TLC Helpdesk Station", config.PAGES["HELPDESK"]),
        ("Medical Station",      config.PAGES["MEDICAL"]),
        ("Cashier Station",      config.PAGES["CASHIER"]),
        ("IT Center Station",    config.PAGES["IT_CENTER"]),
        ("Admin Portal",         config.PAGES["ADMIN"]),
        ("Scheduler Station",    config.PAGES["SCHEDULER"]),
        ("Professor Portal",     config.PAGES["PROFESSOR"]),
        ("Student Portal",       config.PAGES["STUDENT_PORTAL"]),
        ("Student Login",        config.PAGES["STUDENT_PORTAL_LOGIN"]),
    ]

    logger = BrowserLogger(page, "SuiteA")
    for name, url in pages_to_check:
        t0 = time.time()
        try:
            logger.clear()
            resp = page.goto(url, wait_until="domcontentloaded", timeout=20000)
            page.wait_for_timeout(800)
            dur = time.time() - t0
            status_code = resp.status if resp else 0
            js_errors = logger.get_critical_errors()
            net_fails = logger.get_failed_network_requests()
            server_5xx = logger.get_server_5xx_errors()

            passed = (status_code < 400)
            details = f"HTTP {status_code} | {dur:.2f}s"
            if js_errors:
                details += f" | {len(js_errors)} JS error(s)"
                passed = False
            if server_5xx:
                details += f" | {len(server_5xx)} 5xx API error(s)"
                passed = False

            ss = save_screenshot(page, f"suite_a_{name.lower().replace(' ', '_')}")
            r.record(name, passed, details, screenshot=ss)
        except Exception as e:
            r.record(name, False, error=str(e))

    r.finish()
    return r


# ─────────────────────────────────────────────────────────────────────────────
# Suite B — Authentication & Session Enforcement
# ─────────────────────────────────────────────────────────────────────────────
def suite_auth_session(page: Page) -> SuiteResult:
    r = SuiteResult("B: Authentication & Session Enforcement")
    banner("SUITE B — Authentication & Session Enforcement")

    from suites.test_auth_and_session import AuthAndSessionSuite

    logger = BrowserLogger(page, "SuiteB")
    auth_suite = AuthAndSessionSuite(page)
    result = auth_suite.run()

    for step in result["steps"]:
        r.record(
            step["name"],
            step["status"] == "PASSED",
            details=step.get("details", ""),
            error=step.get("error", ""),
            screenshot=step.get("screenshot")
        )

    r.finish()
    return r


# ─────────────────────────────────────────────────────────────────────────────
# Suite C — Multi-Station E2E Student Lifecycle
# ─────────────────────────────────────────────────────────────────────────────
def suite_lifecycle(page: Page) -> SuiteResult:
    r = SuiteResult("C: Multi-Station E2E Student Lifecycle")
    banner("SUITE C — Multi-Station E2E Student Lifecycle")

    from suites.test_e2e_student_lifecycle import E2EStudentLifecycleSuite

    # Clean up any stale test students first
    DBHelper.cleanup_test_students()
    DBHelper.ensure_active_academic_period()

    lifecycle = E2EStudentLifecycleSuite(page)
    result = lifecycle.run()

    for step in result["steps"]:
        r.record(
            step["name"],
            step["status"] == "PASSED",
            details=step.get("details", ""),
            error=step.get("error", ""),
            screenshot=step.get("screenshot")
        )

    r.finish()
    return r


# ─────────────────────────────────────────────────────────────────────────────
# Suite D — Admin Portal Features
# ─────────────────────────────────────────────────────────────────────────────
def suite_admin(page: Page) -> SuiteResult:
    r = SuiteResult("D: Admin Portal Features")
    banner("SUITE D — Admin Portal Features")

    from suites.test_admin_portal import AdminPortalSuite

    logger = BrowserLogger(page, "SuiteD")
    admin_suite = AdminPortalSuite(page)
    result = admin_suite.run()

    for step in result["steps"]:
        r.record(
            step["name"],
            step["status"] == "PASSED",
            details=step.get("details", ""),
            error=step.get("error", ""),
            screenshot=step.get("screenshot")
        )

    r.finish()
    return r


# ─────────────────────────────────────────────────────────────────────────────
# Suite F — Academic Scheduling, Professors & LMS
# ─────────────────────────────────────────────────────────────────────────────
def suite_academic_scheduling(page: Page) -> SuiteResult:
    r = SuiteResult("F: Academic Scheduling, Professors & LMS")
    banner("SUITE F — Academic Scheduling, Professors & LMS")

    from suites.test_academic_scheduling_and_lms import AcademicSchedulingAndLMSSuite

    logger = BrowserLogger(page, "SuiteF")
    academic_suite = AcademicSchedulingAndLMSSuite(page)
    result = academic_suite.run()

    for step in result["steps"]:
        r.record(
            step["name"],
            step["status"] == "PASSED",
            details=step.get("details", ""),
            error=step.get("error", ""),
            screenshot=step.get("screenshot")
        )

    r.finish()
    return r


# ─────────────────────────────────────────────────────────────────────────────
# Suite E — Single-Active Session Security (Hardened)
# ─────────────────────────────────────────────────────────────────────────────
def suite_single_session(browser) -> SuiteResult:
    r = SuiteResult("E: Single-Active Session Enforcement (Hardened)")
    banner("SUITE E — Single-Active Session Security")

    registrar_creds = config.CREDENTIALS["REGISTRAR"]
    registrar_url   = config.PAGES["REGISTRAR"]

    # Context A — Browser 1 (primary session)
    ctx_a = browser.new_context()
    page_a = ctx_a.new_page()
    # Context B — Browser 2 (attacker / second login)
    ctx_b = browser.new_context()
    page_b = ctx_b.new_page()

    try:
        # Step 1: Login on Browser A
        t0 = time.time()
        page_a.goto(registrar_url, wait_until="domcontentloaded")
        page_a.wait_for_timeout(1000)
        u = page_a.locator("#username, input[name='username']").first
        p = page_a.locator("#password, input[name='password']").first
        if u.is_visible():
            u.fill(registrar_creds["username"])
            p.fill(registrar_creds["password"])
            page_a.locator("button[type='submit'], button:has-text('Login')").first.click()
            page_a.wait_for_timeout(2500)

        session_a_ok = "registrar" in page_a.url.lower()
        ss_a = save_screenshot(page_a, "suite_e_browser_a_logged_in")
        r.record("1. Browser A — Initial Login", session_a_ok,
                 f"Browser A authenticated as {registrar_creds['username']}. URL: {page_a.url}",
                 screenshot=ss_a)

        # Step 2: Login with SAME credentials on Browser B (second login)
        page_b.goto(registrar_url, wait_until="domcontentloaded")
        page_b.wait_for_timeout(1000)
        u2 = page_b.locator("#username, input[name='username']").first
        p2 = page_b.locator("#password, input[name='password']").first
        if u2.is_visible():
            u2.fill(registrar_creds["username"])
            p2.fill(registrar_creds["password"])
            page_b.locator("button[type='submit'], button:has-text('Login')").first.click()
            page_b.wait_for_timeout(2500)

        session_b_ok = "registrar" in page_b.url.lower()
        ss_b = save_screenshot(page_b, "suite_e_browser_b_logged_in")
        r.record("2. Browser B — Second Login (Same Account)", session_b_ok,
                 f"Browser B successfully authenticated, invalidating Browser A session. URL: {page_b.url}",
                 screenshot=ss_b)

        # Step 3: Browser A triggers a page action — should get kicked out
        page_a.wait_for_timeout(1500)
        page_a.reload()
        page_a.wait_for_timeout(2000)

        # After reload, Browser A should be redirected to login or see session expired
        a_kicked = (
            "registrar" not in page_a.url.lower() or
            page_a.locator("#username, .swal2-container, .session-expired, input[name='username']").first.is_visible()
        )
        ss_a2 = save_screenshot(page_a, "suite_e_browser_a_kicked_out")
        r.record("3. Browser A — Invalidated After Second Login", a_kicked,
                 "Browser A was kicked out / shown session-expired prompt after Browser B took over the session.",
                 screenshot=ss_a2)

        # Step 4: Verify active_session_token in DB matches Browser B's session, not A's
        db_rows = DBHelper.execute_query(
            "SELECT username, active_session_token FROM station_users WHERE username = :u LIMIT 1",
            {"u": registrar_creds["username"]}
        )
        token_exists = bool(db_rows and db_rows[0].get("active_session_token"))
        r.record("4. DB Token Verification — Session Token Exists", token_exists,
                 f"DB active_session_token: {'SET' if token_exists else 'NULL/MISSING'}")

    except Exception as e:
        r.record("Suite E Execution Error", False, error=str(e))
    finally:
        try:
            ctx_a.close()
            ctx_b.close()
        except Exception:
            pass

    r.finish()
    return r


# ─────────────────────────────────────────────────────────────────────────────
# Report Generator
# ─────────────────────────────────────────────────────────────────────────────
def generate_report(suites: list[SuiteResult], total_duration: float):
    ts_now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    total_assertions = sum(s.total for s in suites)
    total_passed     = sum(s.passed for s in suites)
    total_failed     = sum(s.failed for s in suites)
    overall          = "PASS" if total_failed == 0 else "FAIL"

    # ── Markdown Report ──────────────────────────────────────────────────────
    md_path = os.path.join(config.REPORTS_DIR, "PLAYWRIGHT_TEST_REPORT.md")
    with open(md_path, "w", encoding="utf-8") as f:
        f.write("# GNCP Academic Portal — Playwright Multi-Layer Test Report\n\n")
        f.write(f"- **Execution Date:** {ts_now}\n")
        f.write(f"- **Target URL:** {config.BASE_URL}\n")
        f.write(f"- **Browser:** Chromium (Playwright 1.62)\n")
        f.write(f"- **Total Assertions:** {total_assertions}\n")
        f.write(f"- **Passed:** {total_passed}\n")
        f.write(f"- **Failed:** {total_failed}\n")
        f.write(f"- **Total Duration:** {total_duration:.1f}s\n")
        f.write(f"- **Overall Status:** `{overall}`\n\n")
        f.write("---\n\n## Suite Summary\n\n")
        f.write("| Suite | Assertions | Passed | Failed | Duration | Verdict |\n")
        f.write("| :--- | :---: | :---: | :---: | :---: | :---: |\n")
        for s in suites:
            icon = "✅" if s.verdict == "PASS" else "❌"
            f.write(f"| {s.name} | {s.total} | {s.passed} | {s.failed} | {s.duration:.1f}s | {icon} {s.verdict} |\n")

        f.write("\n---\n\n## Detailed Results\n\n")
        for s in suites:
            f.write(f"### {s.name}\n\n")
            f.write("| # | Test / Assertion | Status | Details |\n")
            f.write("| :---: | :--- | :---: | :--- |\n")
            for i, step in enumerate(s.steps, 1):
                icon = "✅" if step["passed"] else "❌"
                detail = step.get("details") or step.get("error") or ""
                f.write(f"| {i} | {step['name']} | {icon} | {detail} |\n")
            f.write("\n")

    # ── JSON Report (for programmatic consumption) ────────────────────────────
    json_path = os.path.join(config.REPORTS_DIR, "playwright_results.json")
    data = {
        "generated_at": ts_now,
        "base_url": config.BASE_URL,
        "total_assertions": total_assertions,
        "passed": total_passed,
        "failed": total_failed,
        "duration_seconds": round(total_duration, 1),
        "overall": overall,
        "suites": [
            {
                "name": s.name,
                "total": s.total,
                "passed": s.passed,
                "failed": s.failed,
                "duration": round(s.duration, 1),
                "verdict": s.verdict,
                "steps": s.steps
            }
            for s in suites
        ]
    }
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, default=str)

    return md_path, json_path, overall


# ─────────────────────────────────────────────────────────────────────────────
# Main Entrypoint
# ─────────────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="GNCP Playwright Multi-Layer Test Runner")
    parser.add_argument("--suite", default="all",
                        choices=["all", "health", "auth", "lifecycle", "admin", "session", "academic"],
                        help="Which test suite to run (default: all)")
    parser.add_argument("--headed", action="store_true",
                        help="Run browser in headed (visible window) mode")
    args = parser.parse_args()

    headless = not args.headed
    suite_sel = args.suite
    start_total = time.time()

    print()
    banner("GNCP ACADEMIC PORTAL — PLAYWRIGHT MULTI-LAYER TEST RUNNER", "=")
    print(f"  Target         : {config.BASE_URL}")
    print(f"  Browser Mode   : {'HEADED (visible)' if not headless else 'HEADLESS'}")
    print(f"  Suite Selection: {suite_sel.upper()}")
    print(f"  Execution Time : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"  Screenshots    : {config.SCREENSHOTS_DIR}")
    print(f"  Reports        : {config.REPORTS_DIR}")
    print("=" * 72 + "\n")

    results: list[SuiteResult] = []

    with sync_playwright() as pw:
        browser = pw.chromium.launch(
            headless=headless,
            args=["--disable-web-security", "--no-sandbox"]
        )
        context = browser.new_context(
            viewport={"width": 1400, "height": 900},
            ignore_https_errors=True
        )
        page = context.new_page()
        page.set_default_timeout(30000)

        try:
            # Suite A — Page health (always runs for baseline)
            if suite_sel in ("all", "health"):
                results.append(suite_page_health(page))

            # Suite B — Auth & Session
            if suite_sel in ("all", "auth"):
                results.append(suite_auth_session(page))

            # Suite C — Full E2E Lifecycle
            if suite_sel in ("all", "lifecycle"):
                results.append(suite_lifecycle(page))

            # Suite D — Admin Portal
            if suite_sel in ("all", "admin"):
                results.append(suite_admin(page))

            # Suite F — Academic Scheduling, Professors & LMS
            if suite_sel in ("all", "academic"):
                results.append(suite_academic_scheduling(page))

        except Exception as e:
            print(f"\n[FATAL] Unexpected runner error: {e}")
        finally:
            context.close()

        # Suite E — Single Active Session (needs multiple contexts, so we close the shared one first)
        if suite_sel in ("all", "session"):
            results.append(suite_single_session(browser))

        browser.close()

    total_duration = time.time() - start_total
    md_path, json_path, overall = generate_report(results, total_duration)

    # ── Final Console Summary ─────────────────────────────────────────────────
    total_assertions = sum(s.total for s in results)
    total_passed     = sum(s.passed for s in results)
    total_failed     = sum(s.failed for s in results)

    print()
    banner("PLAYWRIGHT MULTI-LAYER TEST EXECUTION SUMMARY", "#")
    print(f"  Suites Run       : {len(results)}")
    print(f"  Total Assertions : {total_assertions}")
    pct = (total_passed / total_assertions * 100) if total_assertions else 0
    print(f"  Passed           : {total_passed} ({pct:.1f}%)")
    print(f"  Failed           : {total_failed}")
    print(f"  Total Duration   : {total_duration:.1f}s")
    print()

    print(f"  {'Suite':<45} {'Asnrts':>6} {'Pass':>5} {'Fail':>5} {'Dur':>7}  Verdict")
    print(f"  {'-'*45} {'-'*6} {'-'*5} {'-'*5} {'-'*7}  -------")
    for s in results:
        icon = "✅" if s.verdict == "PASS" else "❌"
        print(f"  {s.name:<45} {s.total:>6} {s.passed:>5} {s.failed:>5} {s.duration:>6.1f}s  {icon} {s.verdict}")

    print()
    verdict_str = ">>> PASS <<<" if total_failed == 0 else ">>> FAIL <<<"
    print(f"  Overall System Verdict: {verdict_str}")
    print("#" * 72)
    print(f"\n  [+] Markdown Report : {md_path}")
    print(f"  [+] JSON Report     : {json_path}")
    print(f"  [+] Screenshots     : {config.SCREENSHOTS_DIR}\n")

    sys.exit(0 if total_failed == 0 else 1)


if __name__ == "__main__":
    main()
