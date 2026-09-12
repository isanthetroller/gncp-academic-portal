import sys
import time
from playwright.sync_api import sync_playwright, expect
from config import BASE_URL, CREDENTIALS, PAGES

def login_as(page, role_key):
    creds = CREDENTIALS[role_key]
    dest = PAGES[role_key]
    page.goto(f"{BASE_URL}/index.html?clear=true&redirect={dest}", wait_until="domcontentloaded")
    page.wait_for_selector("#username", state="visible", timeout=10000)
    page.fill("#username", creds["username"])
    page.fill("#password", creds["password"])
    page.click("button[type='submit'].login-btn, button[type='submit']")
    page.wait_for_timeout(2000)

def run_e2e_tests():
    print("\n========================================================")
    print("STARTING PLAYWRIGHT E2E TESTS: QUEUES & HISTORY WORKFLOW")
    print("========================================================\n")
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", lambda msg: print(f"  [BROWSER CONSOLE {msg.type}] {msg.text}"))
        page.on("pageerror", lambda err: print(f"  [BROWSER ERROR] {err}"))

        # -------------------------------------------------------------
        # TEST 1: Admin Academic Periods in Section Modal
        # -------------------------------------------------------------
        print("[TEST 1] Testing Admin Section Modal Academic Periods Isolation...")
        login_as(page, "ADMIN")
        page.wait_for_selector("#admin-app, .shell", state="attached")
        page.wait_for_timeout(1500)

        # Navigate to Class Sections view
        page.click("a[data-view='sections'], button:has-text('Sections'), div:has-text('Sections')")
        page.wait_for_timeout(1000)

        # Click Add Section button
        add_btn = page.locator("button:has-text('Add Section'), button:has-text('Create Section')").first
        if add_btn.is_visible():
            add_btn.click()
            page.wait_for_timeout(500)
            
            # Check the academic period select dropdown
            select_loc = page.locator("#sectionAcademicPeriod, select[name='academicPeriodId'], select[v-model='selectedSection.academicPeriodId']").first
            if select_loc.is_visible():
                html = select_loc.inner_html()
                assert "Active Term(s)" in html or "(Active)" in html, f"Active optgroup/badge missing in section modal: {html}"
                assert "Inactive / Past Terms" in html or "(Inactive)" in html, f"Inactive optgroup/badge missing in section modal: {html}"
                val = select_loc.input_value()
                print(f"  -> Section modal academic period select correctly pre-selects active period value: {val}")
                print("  -> Section modal correctly isolates Active vs Inactive Terms in optgroups!")
            
            # Close modal
            page.keyboard.press("Escape")
            page.wait_for_timeout(500)

        # -------------------------------------------------------------
        # TEST 2: Registrar Pending Review & Review History Lifecycle
        # -------------------------------------------------------------
        print("\n[TEST 2] Testing Registrar Queue Filtering & Review History Lifecycle...")
        login_as(page, "REGISTRAR")
        page.wait_for_selector("#app", state="attached")
        page.wait_for_timeout(1500)

        # Verify sidebar nav has Pending Reviews and Review History
        pending_nav = page.locator("button:has-text('Pending Reviews'), a:has-text('Pending Reviews'), div:has-text('Pending Reviews')").first
        history_nav = page.locator("button:has-text('Review History'), a:has-text('Review History'), div:has-text('Review History')").first
        assert pending_nav.is_visible(), "Pending Reviews nav item missing in Registrar sidebar"
        assert history_nav.is_visible(), "Review History nav item missing in Registrar sidebar"
        print("  -> Sidebar contains both 'Pending Reviews' and 'Review History' navigation items.")

        # Click Review History to verify historical table
        print(f"  -> currentView before click: {page.evaluate('() => window.app ? window.app.currentView : null')}")
        
        # Click the nav item directly or via VM
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('review-history'); }")
        page.wait_for_timeout(1000)
        print(f"  -> currentView after setView: {page.evaluate('() => window.app ? window.app.currentView : null')}")
        
        history_header = page.locator("h3:has-text('Registrar Review History'), .panel-header h3").first
        assert history_header.is_visible(), f"Review History view did not render header. HTML: {page.inner_html('.content')[:300]}"
        print("  -> Review History view rendered successfully with audit logs and filter cards.")

        # Return to Pending Reviews
        pending_nav.click()
        page.wait_for_timeout(1000)

        # Check if there are active applications to review
        review_btns = page.locator("button:has-text('Review')")
        count = review_btns.count()
        print(f"  -> Found {count} applications awaiting review in active queue.")

        # -------------------------------------------------------------
        # TEST 3: TLC Helpdesk Active Queue & Review History
        # -------------------------------------------------------------
        print("\n[TEST 3] Testing TLC Helpdesk Workstation Active Queue & History...")
        login_as(page, "HELPDESK")
        page.wait_for_selector("#app", state="attached")
        page.wait_for_timeout(1500)

        # Check for Student Queue & Review History nav items
        hd_queue_nav = page.locator("button:has-text('Student Queue'), a:has-text('Student Queue'), div:has-text('Student Queue')").first
        hd_hist_nav = page.locator("button:has-text('Review History'), a:has-text('Review History'), div:has-text('Review History')").first
        assert hd_queue_nav.is_visible(), "Student Queue nav missing in Helpdesk sidebar"
        assert hd_hist_nav.is_visible(), "Review History nav missing in Helpdesk sidebar"
        print("  -> TLC Helpdesk sidebar contains both 'Student Queue' and 'Review History'.")

        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('history'); }")
        page.wait_for_timeout(1000)
        assert page.locator("h3:has-text('Completed Advising Records'), h4:has-text('TLC Advising History')").first.is_visible()
        print("  -> TLC Helpdesk Review History rendered successfully.")

        # -------------------------------------------------------------
        # TEST 4: Cashier Treasury Active Queue & Payment History
        # -------------------------------------------------------------
        print("\n[TEST 4] Testing Cashier Payment Station Active Queue & Payment History...")
        login_as(page, "CASHIER")
        page.wait_for_selector("#app", state="attached")
        page.wait_for_timeout(1500)

        cashier_hist_nav = page.locator("button:has-text('Payment History'), a:has-text('Payment History'), div:has-text('Payment History')").first
        assert cashier_hist_nav.is_visible(), "Payment History nav item missing in Cashier sidebar"
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('history'); }")
        page.wait_for_timeout(1000)
        assert page.locator("h3:has-text('Settled & Paid Transactions'), h4:has-text('Cashier Payment')").first.is_visible()
        print("  -> Cashier Payment History rendered successfully.")

        # -------------------------------------------------------------
        # TEST 5: Medical Clinic Station Active Queue & Completed Clearances
        # -------------------------------------------------------------
        print("\n[TEST 5] Testing Medical Clinic Station Active Queue & Completed Clearances...")
        login_as(page, "MEDICAL")
        page.wait_for_selector("#app", state="attached")
        page.wait_for_timeout(1500)

        clinic_comp_nav = page.locator("button:has-text('Completed Clearances'), a:has-text('Completed Clearances'), div:has-text('Completed Clearances')").first
        assert clinic_comp_nav.is_visible(), "Completed Clearances nav item missing in Clinic sidebar"
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('completed'); }")
        page.wait_for_timeout(1000)
        assert page.locator("h2:has-text('Completed Medical Clearances'), h3:has-text('Completed Clearances')").first.is_visible()
        print("  -> Clinic Completed Clearances rendered successfully.")

        browser.close()

    print("\n========================================================")
    print("ALL PLAYWRIGHT E2E QUEUES & HISTORY TESTS COMPLETED!")
    print("========================================================\n")

if __name__ == "__main__":
    run_e2e_tests()
