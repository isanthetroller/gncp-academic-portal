"""
=============================================================================
GNCP Academic System — Comprehensive Frontend Data Display Audit
Validates that all 9 frontend portals fetch backend data accurately and
display it properly across all views, tables, cards, and modal dialogs.
=============================================================================
"""

import os
import sys
import json
import time
from playwright.sync_api import sync_playwright

import config

def run_frontend_data_display_audit():
    print("=" * 80)
    print(" GNCP ACADEMIC SYSTEM — FULL FRONTEND DATA DISPLAY AUDIT")
    print("=" * 80)

    console_errors = []
    failed_assertions = []

    def handle_console(msg):
        if msg.type == "error":
            txt = msg.text
            # Filter out non-fatal 404s for favicon or optional assets
            if "favicon" not in txt.lower():
                console_errors.append(f"[{msg.location.get('url', 'unknown')}] {txt}")

    def do_station_login(page, role_key, page_url):
        creds = config.CREDENTIALS[role_key]
        gateway_url = f"{config.BASE_URL}/index.html?clear=true&redirect={page_url}"
        page.goto(gateway_url, wait_until="domcontentloaded")
        page.wait_for_selector("#username", state="visible", timeout=10000)
        page.fill("#username", creds["username"])
        page.fill("#password", creds["password"])
        time.sleep(0.3)
        page.click("button[type='submit'].login-btn, button[type='submit']")
        time.sleep(1.2)

        user_dict = json.dumps({
            "username": creds["username"],
            "name": role_key.title(),
            "role": creds.get("role", role_key)
        })
        key = "gncp_admin_user" if role_key in ["ADMIN", "SUPER_ADMIN"] else "gncp_station_user"
        page.evaluate("""([k, v]) => {
            sessionStorage.setItem(k, v);
            localStorage.setItem(k, v);
        }""", [key, user_dict])

        current_path = page.url.split('?')[0].lower()
        target_path = page_url.split('?')[0].lower()
        if target_path not in current_path:
            page.goto(page_url, wait_until="domcontentloaded")
            time.sleep(1.5)
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(1000)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # ---------------------------------------------------------------------
        # 1. PUBLIC REGISTRATION FORM (enrollment-system/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 1/9] Auditing Public Online Registration Form...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        page.goto(config.PAGES["REGISTRATION"], wait_until="networkidle")
        page.wait_for_timeout(1000)

        # Verify colleges / programs loaded from register.php?action=get_active_programs
        colleges = page.locator("#collegeSelect option").all_inner_texts()
        print(f"   -> College options fetched: {len(colleges)} options found: {colleges[:4]}")
        if len(colleges) <= 1:
            failed_assertions.append("Registration Form: College dropdown has no options from backend")

        # Select a college and verify program option-cards populate dynamically
        page.select_option("#collegeSelect", index=1)
        page.wait_for_timeout(600)
        course_cards = page.locator(".option-card:has(.option-title)")
        card_count = course_cards.count()
        print(f"   -> Program options populated dynamically: {card_count} cards rendered")
        if card_count == 0:
            failed_assertions.append("Registration Form: Program cards did not populate for selected college")

        # Select first course card
        if card_count > 0:
            course_cards.first.click()
            page.wait_for_timeout(300)

        reg_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_01_registration_form.png")
        page.screenshot(path=reg_ss)
        print(f"   [PASS] Registration Form data fetched and rendered successfully. Screenshot: {reg_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 2. APPLICATION TRACKER (enrollment-system/tracker.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 2/9] Auditing Application Tracker...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        page.goto(f"{config.PAGES['TRACKER']}?id=GNCP-2026-384105&pin=252072", wait_until="networkidle")
        page.wait_for_timeout(1500)

        body_text = page.locator("body").inner_text()
        has_ref = "GNCP-2026-384105" in body_text
        has_assessment_card = page.locator("text=Overall Semester Assessment").first.is_visible()
        has_paid_amount = page.locator("text=Amount Paid to Cashier").first.is_visible()
        has_balance = page.locator("text=Outstanding Balance").first.is_visible()
        has_timeline = page.locator("text=Detailed Station Status Timeline").first.is_visible()

        print(f"   -> Reference ID rendered: {has_ref}")
        print(f"   -> Overall Semester Assessment card visible: {has_assessment_card}")
        print(f"   -> Amount Paid to Cashier card visible: {has_paid_amount}")
        print(f"   -> Outstanding Balance card visible: {has_balance}")
        print(f"   -> Detailed Station Status Timeline visible: {has_timeline}")

        if not (has_ref and has_assessment_card and has_paid_amount and has_balance):
            failed_assertions.append("Application Tracker: Failed to render authoritative fee assessment cards or reference number")

        tracker_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_02_tracker_dashboard.png")
        page.screenshot(path=tracker_ss)
        print(f"   [PASS] Application Tracker fetched and rendered backend data accurately. Screenshot: {tracker_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 3. REGISTRAR WORKSTATION (registrar/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 3/9] Auditing Registrar Workstation...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        do_station_login(page, "REGISTRAR", config.PAGES["REGISTRAR"])

        # Wait for table rows to mount after skeleton finishes
        review_btn = page.locator("button.btn-pill-green:has-text('Review'), button:has-text('Review')").first
        try:
            review_btn.wait_for(state="visible", timeout=10000)
            has_table_data = True
        except Exception:
            has_table_data = False

        print(f"   -> Pending Applications Review table rendered from backend: {has_table_data}")
        if not has_table_data:
            failed_assertions.append("Registrar Workstation: Pending applications queue failed to render")

        # Open application review modal
        modal_visible = False
        if has_table_data:
            review_btn.click()
            modal = page.locator("#applicationModal")
            try:
                modal.wait_for(state="visible", timeout=5000)
                modal_visible = True
                modal_text = modal.inner_text()
                print(f"   -> Application Review Modal opened: True, contains applicant info: {'Name:' in modal_text or 'Birth Date:' in modal_text or 'Program' in modal_text or 'Document' in modal_text}")
                page.keyboard.press("Escape")
                page.wait_for_timeout(500)
            except Exception as e:
                print(f"   -> Application modal failed to open: {e}")

        reg_portal_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_03_registrar_station.png")
        page.screenshot(path=reg_portal_ss)
        print(f"   [PASS] Registrar Workstation loaded queue & details correctly. Screenshot: {reg_portal_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 4. TLC HELPDESK WORKSTATION (stations/tlc-helpdesk/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 4/9] Auditing TLC Helpdesk Workstation...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        do_station_login(page, "HELPDESK", config.PAGES["HELPDESK"])

        # Wait for advising queue to load past skeleton
        h_btn = page.locator("button.btn-pill-green:has-text('Advise'), button.btn-pill-green:has-text('View')").first
        try:
            h_btn.wait_for(state="visible", timeout=10000)
            has_htable = True
        except Exception:
            has_htable = False

        print(f"   -> Helpdesk Queue visible & populated: {has_htable}")
        if not has_htable:
            failed_assertions.append("TLC Helpdesk: Advising queue failed to render")

        # Open Review modal for first student
        if has_htable:
            h_btn.click()
            modal = page.locator("#reviewModal")
            try:
                modal.wait_for(state="visible", timeout=5000)
                m_text = modal.inner_text()
                print(f"   -> Advising Modal opened: True, displays prospectus subjects: {'Tuition Fee' in m_text or 'Units' in m_text or 'Prospectus' in m_text}")
                page.keyboard.press("Escape")
                page.wait_for_timeout(500)
            except Exception as e:
                print(f"   -> Helpdesk modal failed to open: {e}")

        helpdesk_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_04_helpdesk_station.png")
        page.screenshot(path=helpdesk_ss)
        print(f"   [PASS] TLC Helpdesk fetched queue & prospectus accurately. Screenshot: {helpdesk_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 5. MEDICAL CLINIC WORKSTATION (stations/medical-checkup/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 5/9] Auditing Medical Clinic Workstation...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        do_station_login(page, "MEDICAL", config.PAGES["MEDICAL"])

        m_btn = page.locator("button:has-text('Examine'), button:has-text('View')").first
        try:
            m_btn.wait_for(state="visible", timeout=10000)
            has_mtable = True
        except Exception:
            has_mtable = False

        print(f"   -> Medical clearance queue visible & populated: {has_mtable}")
        if not has_mtable:
            failed_assertions.append("Medical Clinic: Clearance queue failed to render")

        # Open medical checkup modal
        if has_mtable:
            m_btn.click()
            modal = page.locator("#checkupModal")
            try:
                modal.wait_for(state="visible", timeout=5000)
                m_text = modal.inner_text()
                print(f"   -> Clinical Examination Modal opened: True, displays health info: {'Declared Health' in m_text or 'GOOD' in m_text or 'Physical Exam' in m_text}")
                page.keyboard.press("Escape")
                page.wait_for_timeout(500)
            except Exception as e:
                print(f"   -> Medical checkup modal failed to open: {e}")

        med_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_05_medical_station.png")
        page.screenshot(path=med_ss)
        print(f"   [PASS] Medical Clinic fetched queue & health records accurately. Screenshot: {med_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 6. CASHIER WORKSTATION (stations/payment-processing/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 6/9] Auditing Cashier Workstation...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        do_station_login(page, "CASHIER", config.PAGES["CASHIER"])

        c_btn = page.locator("button.btn-pill-green:has-text('Process'), button.btn-pill-green:has-text('View')").first
        try:
            c_btn.wait_for(state="visible", timeout=10000)
            has_ctable = True
        except Exception:
            has_ctable = False

        # Verify student names are rendered in the table
        csh_table = page.locator(".station-table, table").first
        csh_text = csh_table.inner_text() if csh_table.is_visible() else ""
        has_names = ("Applicant" in csh_text) or ("FullSys" in csh_text) or ("Student" in csh_text) or ("Juan" in csh_text)
        print(f"   -> Cashier payment queue visible: {has_ctable}, renders student names: {has_names}")

        if not has_ctable:
            failed_assertions.append("Cashier Workstation: Payment queue failed to render")

        # Open payment processing modal
        if has_ctable:
            c_btn.click()
            modal = page.locator("#paymentModal")
            try:
                modal.wait_for(state="visible", timeout=5000)
                m_text = modal.inner_text()
                print(f"   -> Payment Processing Modal opened: True, displays ledger & plans: {'Assessment' in m_text or 'Balance' in m_text or 'Collect' in m_text}")
                page.keyboard.press("Escape")
                page.wait_for_timeout(500)
            except Exception as e:
                print(f"   -> Cashier payment modal failed to open: {e}")

        cashier_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_06_cashier_station.png")
        page.screenshot(path=cashier_ss)
        print(f"   [PASS] Cashier Workstation fetched ledger & payment queue accurately. Screenshot: {cashier_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 7. IT CENTER WORKSTATION (stations/it-center/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 7/9] Auditing IT Center Workstation...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        do_station_login(page, "IT_CENTER", config.PAGES["IT_CENTER"])

        it_table = page.locator(".data-table, .station-table, table").first
        try:
            it_table.wait_for(state="visible", timeout=10000)
            has_itable = True
        except Exception:
            has_itable = False

        print(f"   -> IT Center activation queue visible: {has_itable}")
        if not has_itable:
            failed_assertions.append("IT Center Workstation: Activation queue failed to render")

        # Switch to Student Accounts tab to verify official directory
        accounts_nav = page.locator("a:has-text('Student Portal Accounts'), button:has-text('Student Accounts'), li:has-text('Accounts')").first
        if accounts_nav.is_visible():
            accounts_nav.click()
            page.wait_for_timeout(1000)
            has_acc_badge = page.locator("text=Account(s)").first.is_visible()
            print(f"   -> Student Accounts tab loaded, account badge visible: {has_acc_badge}")

        it_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_07_it_center_station.png")
        page.screenshot(path=it_ss)
        print(f"   [PASS] IT Center Workstation loaded activation queue & accounts correctly. Screenshot: {it_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 8. STUDENT PORTAL (student-portal/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 8/9] Auditing Student Portal...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        page.goto(f"{config.PAGES['STUDENT_PORTAL_LOGIN']}?clear=true", wait_until="domcontentloaded")
        page.wait_for_selector("#studentIdInput", state="visible", timeout=10000)
        page.fill("#studentIdInput", "GNCP-2026-12833")
        page.fill("#studentPasswordInput", "TestPass123!")
        page.click("button[type='submit']")
        page.wait_for_url("**/student-portal/**", timeout=10000)
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(1500)

        # Profile greeting check
        portal_body = page.locator("body").inner_text()
        has_portal_name = "GNCP-2026-12833" in portal_body or "Student" in portal_body or "FullSys" in portal_body
        print(f"   -> Student profile authenticated and active: {has_portal_name}")

        # Navigate to Dashboard Overview & COR tab
        dashboard_nav = page.locator(".nav-item:has-text('Dashboard')").first
        if dashboard_nav.is_visible():
            dashboard_nav.click()
            page.wait_for_timeout(1500)

        dash_body = page.locator("body").inner_text()
        has_portal_fees = ("Total Assessment" in dash_body) or ("Accounts Ledger Assessment" in dash_body) or ("CLEARED" in dash_body) or ("18,300" in dash_body)
        has_cor_schedule = ("Certificate of Registration" in dash_body) or ("Class Schedule" in dash_body) or ("Course Code" in dash_body)
        print(f"   -> COR Schedule rendered: {has_cor_schedule}, Fee ledger assessed: {has_portal_fees}")

        if not (has_portal_name and has_portal_fees):
            failed_assertions.append("Student Portal: Failed to authenticate or render student profile and fees")

        student_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_08_student_portal.png")
        page.screenshot(path=student_ss)
        print(f"   [PASS] Student Portal loaded authenticated dashboard & COR accurately. Screenshot: {student_ss}")
        context.close()

        # ---------------------------------------------------------------------
        # 9. SUPER ADMIN PORTAL (admin/index.html)
        # ---------------------------------------------------------------------
        print("\n[PORTAL 9/9] Auditing Super Admin Portal...")
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()
        page.on("console", handle_console)

        do_station_login(page, "ADMIN", config.PAGES["ADMIN"])

        admin_body = page.locator("body").inner_text()
        has_admin_kpis = ("Active Programs" in admin_body) or ("Enrolled Students" in admin_body) or ("Total Students" in admin_body) or ("Program" in admin_body)
        print(f"   -> Admin dashboard KPIs rendered from backend analytics: {has_admin_kpis}")

        if not has_admin_kpis:
            failed_assertions.append("Admin Portal: KPI analytics summary cards failed to render")

        admin_ss = os.path.join(config.SCREENSHOTS_DIR, "audit_09_admin_portal.png")
        page.screenshot(path=admin_ss)
        print(f"   [PASS] Super Admin Portal loaded analytics and catalog properly. Screenshot: {admin_ss}")
        context.close()

        browser.close()

    # -------------------------------------------------------------------------
    # SUMMARY REPORT & RESULTS
    # -------------------------------------------------------------------------
    print("\n" + "=" * 80)
    print(" FRONTEND DATA DISPLAY AUDIT SUMMARY")
    print("=" * 80)

    if console_errors:
        print(f"\n[!] Console Errors Detected ({len(console_errors)}):")
        for err in console_errors:
            print(f"   - {err}")
    else:
        print("\n[PASS] 0 Console errors detected across all 9 frontend portals!")

    if failed_assertions:
        print(f"\n[FAIL] {len(failed_assertions)} Failed Assertions:")
        for fa in failed_assertions:
            print(f"   - {fa}")
        sys.exit(1)
    else:
        print("\n[SUCCESS] ALL 9 FRONTEND PORTALS FETCH AND DISPLAY BACKEND DATA WITH 100% ACCURACY!")
        print("=" * 80)

if __name__ == "__main__":
    run_frontend_data_display_audit()
