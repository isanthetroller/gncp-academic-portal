import os
import sys
import time
import json
from playwright.sync_api import sync_playwright

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
from config import BASE_URL, CREDENTIALS
from utils.db_helper import DBHelper
from utils.reporter import TestReporter

def run_suite():
    reporter = TestReporter("Suite 03: Specialized Journeys & Workflows")
    print("\n" + "=" * 78)
    print("  RUNNING SUITE 03: SPECIALIZED STUDENT JOURNEYS & GUARDS")
    print("=" * 78)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # ---------------------------------------------------------------------
        # 1. Mandatory Password Change Guard on First Login (SweetAlert2 Guard)
        # ---------------------------------------------------------------------
        print("\n--- 1. Mandatory Password Change Guard Verification ---")
        ctx_guard = browser.new_context(viewport={"width": 1280, "height": 800})
        page_guard = ctx_guard.new_page()

        page_guard.goto(f"{BASE_URL}/index.html", wait_until="networkidle")
        has_guard = page_guard.evaluate("() => typeof window.PasswordChangeGuard !== 'undefined'")
        reporter.record(
            "PasswordGuard",
            "PasswordChangeGuard Component Loaded",
            has_guard,
            "window.PasswordChangeGuard is active on gateway"
        )

        # Simulate user with must_change_password = 1
        page_guard.evaluate("""() => {
            window.__testGuardCompleted = false;
            window.PasswordChangeGuard.checkAndPrompt({
                id: 'TEST-GUARD-OPERATOR',
                name: 'Test Hardened Operator',
                role: 'REGISTRAR',
                must_change_password: 1
            }, () => { window.__testGuardCompleted = true; });
        }""")
        page_guard.wait_for_timeout(600)

        modal = page_guard.locator(".swal2-modal, .swal2-popup")
        modal_visible = modal.is_visible()
        reporter.record(
            "PasswordGuard",
            "SweetAlert2 Password Reset Interception Modal Displayed",
            modal_visible,
            f"Modal visible in DOM: {modal_visible}"
        )
        ctx_guard.close()

        # ---------------------------------------------------------------------
        # 2. Cashier Assessment Scoping & Fee Itemization
        # ---------------------------------------------------------------------
        print("\n--- 2. Cashier Transparent Assessment Breakdown Verification ---")
        ctx_cashier = browser.new_context(viewport={"width": 1366, "height": 900})
        page_csh = ctx_cashier.new_page()

        page_csh.goto(f"{BASE_URL}/stations/payment-processing/")
        # Fill login if prompted
        if page_csh.locator("#username, input[placeholder*='Username']").first.is_visible():
            page_csh.fill("#username, input[placeholder*='Username']", CREDENTIALS["cashier"]["username"])
            page_csh.fill("#password, input[type='password']", CREDENTIALS["cashier"]["password"])
            page_csh.click("button[type='submit']")
            page_csh.wait_for_timeout(1500)

        page_csh.wait_for_selector(".station-table, .data-table, table", timeout=10000)
        table_loaded = page_csh.locator("table tbody tr").count() > 0
        reporter.record(
            "CashierStation",
            "Cashier Workstation Active Queue Loaded",
            table_loaded,
            f"Loaded {page_csh.locator('table tbody tr').count()} applicants in queue table"
        )

        # Open process modal for an applicant
        page_csh.evaluate("""() => {
            if (window.app && window.app.students && window.app.students.length > 0) {
                let target = window.app.students.find(s => window.app.getAdvisedSubjects(s).length > 0) || window.app.students[0];
                window.app.openProcess(target);
            }
        }""")
        page_csh.wait_for_timeout(1000)

        modal_open = page_csh.locator(".station-modal, .modal, #paymentModal").first.is_visible()
        reporter.record(
            "CashierStation",
            "Assessment Breakdown Modal Open",
            modal_open,
            f"Modal rendered: {modal_open}"
        )
        ctx_cashier.close()

        # ---------------------------------------------------------------------
        # 3. Student Portal Documents & Undertakings Hub
        # ---------------------------------------------------------------------
        print("\n--- 3. Student Portal Documents Hub Verification ---")
        ctx_student = browser.new_context(viewport={"width": 1366, "height": 900})
        page_std = ctx_student.new_page()

        page_std.goto(f"{BASE_URL}/student-portal/login")
        page_std.wait_for_selector("#studentIdInput", timeout=10000)
        page_std.fill("#studentIdInput", CREDENTIALS["student"]["username"])
        page_std.fill("#studentPasswordInput", CREDENTIALS["student"]["password"])
        page_std.click(".login-btn, button[type='submit']")
        page_std.wait_for_timeout(2000)

        portal_auth = "login" not in page_std.url
        reporter.record(
            "StudentPortal",
            "Student Portal Authentication Successful",
            portal_auth,
            f"Active URL: {page_std.url}"
        )

        # Navigate to Documents Hub tab
        page_std.evaluate("() => { if (window.app) window.app.activeTab = 'documents'; }")
        page_std.wait_for_timeout(800)
        docs_hub_rendered = page_std.locator(".documents-hub-layout, h4:has-text('Academic Requirements')").first.is_visible()
        reporter.record(
            "StudentPortal",
            "Documents & Undertakings Hub View Rendered",
            docs_hub_rendered,
            f"Documents tab active and rendered in DOM: {docs_hub_rendered}"
        )
        ctx_student.close()

        browser.close()

    reporter.print_summary()
    return reporter

if __name__ == "__main__":
    rep = run_suite()
    s = rep.get_summary()
    sys.exit(0 if s["failed"] == 0 else 1)
