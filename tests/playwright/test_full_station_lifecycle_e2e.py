import os
import sys
import time
import random
from playwright.sync_api import sync_playwright, expect

# Add playwright directory to sys.path
sys.path.append(os.path.abspath(os.path.dirname(__file__)))
from config import BASE_URL, CREDENTIALS, PAGES
from utils.db_helper import DBHelper

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
    print("STARTING PLAYWRIGHT FULL STATION LIFECYCLE E2E TESTS")
    print("========================================================\n")

    rand_id = random.randint(10000, 99999)
    test_ref_prog = f"REF-PW-E2E-{rand_id}"
    test_email_prog = f"test.pw.prog.{rand_id}@gncp.edu.ph"
    test_name_prog = f"Playwright Student {rand_id}"

    test_ref_ret = f"REF-PW-RET-{rand_id}"
    test_email_ret = f"test.pw.ret.{rand_id}@gncp.edu.ph"
    test_name_ret = f"Return Student {rand_id}"

    # Clean up any stale records
    DBHelper.execute_statement("DELETE FROM pre_enrollments WHERE email LIKE 'test.pw.%@gncp.edu.ph'")
    DBHelper.execute_statement("DELETE FROM students WHERE email LIKE 'test.pw.%@gncp.edu.ph'")

    # Seed initial test student 1 for full progression (BSIT, 1st Year)
    # Seed with verified documents so registrar approval succeeds smoothly
    req_json = '{"status":"VERIFIED","docs":{"psa":{"status":"ORIGINAL"},"form138":{"status":"ORIGINAL"},"good_moral":{"status":"ORIGINAL"},"id_photos":{"status":"ORIGINAL"}}}'
    DBHelper.execute_statement("""
        INSERT INTO `pre_enrollments` 
        (`temp_student_id`, `temp_pin`, `first_name`, `last_name`, `course_code`, `year_level_applied`, `email`, `status`, `requirements_data`, `created_at`) 
        VALUES 
        (:ref, '1234', 'Playwright', :lname, 'BSIT', '1st Year', :email, 'PRE_REGISTERED', :reqs, NOW())
    """, {
        "ref": test_ref_prog,
        "lname": f"Student {rand_id}",
        "email": test_email_prog,
        "reqs": req_json
    })

    # Seed initial test student 2 for return-for-correction (BSN, 2nd Year)
    DBHelper.execute_statement("""
        INSERT INTO `pre_enrollments` 
        (`temp_student_id`, `temp_pin`, `first_name`, `last_name`, `course_code`, `year_level_applied`, `email`, `status`, `created_at`) 
        VALUES 
        (:ref, '1234', 'Return', :lname, 'BSN', '2nd Year', :email, 'PRE_REGISTERED', NOW())
    """, {
        "ref": test_ref_ret,
        "lname": f"Student {rand_id}",
        "email": test_email_ret
    })
    print(f"  -> Seeded test student for progression: {test_ref_prog} (BSIT 1st Year)")
    print(f"  -> Seeded test student for return flow: {test_ref_ret} (BSN 2nd Year)")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()

        # -------------------------------------------------------------
        # TEST 1: REGISTRAR WORKSTATION (Queue, Review, Approve, History, Refresh)
        # -------------------------------------------------------------
        print("\n[TEST 1] Registrar Workstation Lifecycle (Progression Student)...")
        login_as(page, "REGISTRAR")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)
        print("  -> Logged in as Registrar successfully.")

        # 1.2 Navigate to Pending Applications View
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1000)

        # 1.3 Assert student is in Active Queue
        active_text = page.inner_text(".main")
        assert test_ref_prog in active_text, f"Student {test_ref_prog} not found in Registrar Active Queue DOM!"
        print(f"  -> Student {test_ref_prog} verified in Registrar Active Queue.")

        # 1.4 Open Review Modal and Approve Student
        page.evaluate(f"""() => {{
            const apps = window.app.pendingApplications || [];
            const target = apps.find(a => a.referenceNumber === '{test_ref_prog}');
            if (target) {{
                window.app.openApplicationModal(target);
                window.app.selectedApplication.sectionCode = 'BSIT-1A';
                const reqs = window.app.selectedApplication.requirements || [];
                reqs.forEach(r => window.app.setDocStatus(r, 'ORIGINAL'));
            }}
        }}""")
        page.wait_for_selector("#applicationModal", state="visible", timeout=5000)
        page.wait_for_timeout(500)

        # Click Approve & Verify in modal
        approve_btn = page.locator("button:has-text('Approve & Verify'), button:has-text('Approve')").first
        if approve_btn.is_visible():
            approve_btn.click()
            page.wait_for_timeout(500)
            
            # Click SweetAlert confirmation
            swal_confirm = page.locator(".swal2-confirm")
            if swal_confirm.is_visible():
                swal_confirm.click()
                page.wait_for_timeout(1500)
                # Dismiss success modal if open
                if page.locator(".swal2-confirm").is_visible():
                    page.locator(".swal2-confirm").click()
                    page.wait_for_timeout(500)

        print("  -> Submitted approval for student in Registrar.")

        # 1.5 Verify student immediately removed from Active Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1000)
        active_text_after = page.inner_text(".main")
        assert test_ref_prog not in active_text_after, f"Approved student {test_ref_prog} still in active queue!"
        print("  -> Student immediately removed from Registrar Active Queue.")

        # 1.6 Switch to Review History View & Verify Student Appears
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('review-history'); }")
        page.wait_for_timeout(1500)
        history_text = page.inner_text(".main")
        assert test_ref_prog in history_text, f"Student {test_ref_prog} missing from Registrar Review History view!"
        print("  -> Student successfully verified in Registrar Review History view.")

        # 1.7 Refresh page and confirm persistence
        page.reload()
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1000)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('review-history'); }")
        page.wait_for_timeout(1500)
        reloaded_hist_text = page.inner_text(".main")
        assert test_ref_prog in reloaded_hist_text, f"Student {test_ref_prog} not in Review History after page reload!"
        print("  -> Review History persistence verified across page refresh.")

        # 1.8 Direct DB verification for Registrar action
        db_reg = DBHelper.execute_query("SELECT status, requirements_data FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": test_ref_prog})
        assert len(db_reg) == 1 and db_reg[0]["status"] == "VERIFIED", f"Unexpected DB status for verified student: {db_reg}"
        db_audit = DBHelper.execute_query("SELECT * FROM audit_logs WHERE reference_number = :ref AND station_role = 'REGISTRAR' ORDER BY id DESC LIMIT 1", {"ref": test_ref_prog})
        assert len(db_audit) == 1 and db_audit[0]["action_performed"] == "REQUIREMENTS_VERIFIED", f"Audit log missing for registrar: {db_audit}"
        print("  -> DB status (VERIFIED) and audit_logs (REQUIREMENTS_VERIFIED) verified in MariaDB.")

        # -------------------------------------------------------------
        # TEST 2: RETURNED FOR CORRECTION WORKFLOW IN REGISTRAR UI
        # -------------------------------------------------------------
        print("\n[TEST 2] Registrar Return-for-Correction Workflow...")
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1000)
        
        # Verify return test student is in queue
        assert test_ref_ret in page.inner_text(".main"), f"Student {test_ref_ret} not in queue!"
        
        # Open modal and return for correction via UI
        return_reason_msg = "PSA Birth Certificate image is corrupted. Please re-upload clear copy."
        page.evaluate(f"""() => {{
            const apps = window.app.pendingApplications || [];
            const target = apps.find(a => a.referenceNumber === '{test_ref_ret}');
            if (target) {{
                window.app.openApplicationModal(target);
            }}
        }}""")
        page.wait_for_selector("#applicationModal", state="visible", timeout=5000)
        page.wait_for_timeout(500)

        # Click Request Correction
        page.locator("button:has-text('Request Correction')").click()
        page.wait_for_selector(".swal2-textarea", state="visible", timeout=5000)
        page.fill(".swal2-textarea", return_reason_msg)
        page.click("button:has-text('Return Application'), .swal2-confirm")
        page.wait_for_timeout(1500)

        # Dismiss success modal if open
        if page.locator(".swal2-confirm").is_visible():
            page.locator(".swal2-confirm").click()
            page.wait_for_timeout(500)

        print("  -> Returned student for correction with explicit reason.")

        # Verify DB state
        db_ret_chk = DBHelper.execute_query("SELECT status, registrar_notes FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": test_ref_ret})
        assert len(db_ret_chk) == 1 and db_ret_chk[0]["status"] == "RETURNED", f"DB status not RETURNED: {db_ret_chk}"
        assert "corrupted" in db_ret_chk[0]["registrar_notes"], f"Return reason missing in DB: {db_ret_chk}"

        # Verify audit log recorded
        db_ret_audit = DBHelper.execute_query("SELECT * FROM audit_logs WHERE reference_number = :ref AND action_performed = 'RETURNED_FOR_CORRECTION' ORDER BY id DESC LIMIT 1", {"ref": test_ref_ret})
        assert len(db_ret_audit) == 1, f"Audit log missing return record: {db_ret_audit}"
        print("  -> Return for correction verified in MariaDB and audit_logs.")

        # Verify returned student still displayed in active queue with badge
        page.reload()
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1000)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1000)
        ret_queue_text = page.inner_text(".main")
        assert test_ref_ret in ret_queue_text, f"Returned student {test_ref_ret} not present in active queue!"
        print("  -> Returned student correctly remains in Active Queue for student compliance.")

        # -------------------------------------------------------------
        # TEST 3: TLC HELPDESK WORKSTATION (Advising, Sectioning, NSTP, History)
        # -------------------------------------------------------------
        print("\n[TEST 3] TLC Helpdesk Workstation Lifecycle...")
        login_as(page, "HELPDESK")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)

        # Switch to Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        hd_queue_text = page.inner_text(".main-panel")
        assert test_ref_prog in hd_queue_text, f"Student {test_ref_prog} missing from Helpdesk Active Queue!"
        print(f"  -> Student {test_ref_prog} verified in TLC Helpdesk Active Queue.")

        # Open student review modal in Helpdesk & mark completed
        page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref_prog}' || x.temp_student_id === '{test_ref_prog}'));
            if (s) {{
                window.app.openReview(s);
                s.section = 'BSIT-1A';
                s.nstp = 'ROTC';
                window.app.markCompleted();
            }}
        }}""")
        page.wait_for_timeout(2000)
        print("  -> Completed academic advising & NSTP lock-in in Helpdesk.")

        # Verify student is removed from Helpdesk active queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert test_ref_prog not in page.inner_text(".main-panel"), f"Student {test_ref_prog} still in Helpdesk active queue!"
        print("  -> Student immediately removed from Helpdesk Active Queue.")

        # Switch to Review History in Helpdesk
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('history'); }")
        page.wait_for_timeout(1500)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Helpdesk Review History!"
        print("  -> Student verified in TLC Helpdesk Review History table.")

        # Refresh page & verify persistence
        page.reload()
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1000)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('history'); }")
        page.wait_for_timeout(1500)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Helpdesk History after reload!"
        print("  -> TLC Helpdesk Review History persistence verified across page refresh.")

        # -------------------------------------------------------------
        # TEST 4: MEDICAL CLINIC WORKSTATION (Physical Exam, Clearance, History)
        # -------------------------------------------------------------
        print("\n[TEST 4] Medical Clinic Workstation Lifecycle...")
        login_as(page, "MEDICAL")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)

        # Switch to Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Medical Clinic Active Queue!"
        print(f"  -> Student {test_ref_prog} verified in Medical Clinic Active Queue.")

        # Process Medical Clearance with all required fields filled
        page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref_prog}' || x.temp_student_id === '{test_ref_prog}'));
            if (s) {{
                window.app.openReview(s);
                s.physicalExam = 'passed';
                s.medicalInterview = 'passed';
                s.peFitness = 'fit';
                s.nstpFitness = 'fit';
                s.status = 'fit';
                s.notes = 'Fit for college enrollment';
                window.app.saveCheckup();
            }}
        }}""")
        page.wait_for_timeout(2000)
        print("  -> Recorded FIT clearance for student in Medical Clinic.")

        # Verify removal from Medical active queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert test_ref_prog not in page.inner_text(".main-panel"), f"Student {test_ref_prog} still in Medical active queue!"
        print("  -> Student immediately removed from Medical Clinic Active Queue.")

        # Switch to Completed Clearances view
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('completed'); }")
        page.wait_for_timeout(1500)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Completed Medical Clearances!"
        print("  -> Student verified in Medical Clinic Completed Clearances table.")

        # -------------------------------------------------------------
        # TEST 5: CASHIER TREASURY WORKSTATION (Tuition, Receipt, Payment History)
        # -------------------------------------------------------------
        print("\n[TEST 5] Cashier Treasury Workstation Lifecycle...")
        login_as(page, "CASHIER")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)

        # Switch to Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Cashier Active Queue!"
        print(f"  -> Student {test_ref_prog} verified in Cashier Treasury Active Queue.")

        # Process Cashier Payment & Issue OR
        or_number = f"OR-PW-{rand_id}"
        page.evaluate(f"""async () => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref_prog}' || x.temp_student_id === '{test_ref_prog}'));
            if (s) {{
                window.app.openProcess(s);
                const bal = (s.payment && s.payment.balance > 0) ? s.payment.balance : 3000;
                window.app.payAmountInput = bal;
                window.app.cashTendered = bal;
                setTimeout(() => {{
                    const btn = document.querySelector('.swal2-confirm');
                    if (btn) btn.click();
                }}, 800);
                await window.app.recordPayment();
            }}
        }}""")
        page.wait_for_timeout(2500)

        # Dismiss SweetAlert payment confirmation
        if page.locator(".swal2-confirm").is_visible():
            page.locator(".swal2-confirm").click()
            page.wait_for_timeout(1000)

        print(f"  -> Processed tuition payment in Cashier.")

        # Verify removal from Cashier active queue table
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1500)
        table_html = page.inner_html(".data-table tbody")
        assert test_ref_prog not in table_html, f"Student {test_ref_prog} still in Cashier active queue table!"
        print("  -> Student immediately removed from Cashier Active Queue.")

        # Switch to Payment History
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('history'); }")
        page.wait_for_timeout(1500)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Cashier Payment History!"
        print("  -> Student verified in Cashier Payment History table.")

        # Refresh page & verify persistence
        page.reload()
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1000)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('history'); }")
        page.wait_for_timeout(1500)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from Cashier History after reload!"
        print("  -> Cashier Payment History persistence verified across page refresh.")

        # -------------------------------------------------------------
        # TEST 6: IT CENTER WORKSTATION (Promotion & Permanent Student Directory)
        # -------------------------------------------------------------
        print("\n[TEST 6] IT Center Workstation Lifecycle & Promotion...")
        login_as(page, "IT_CENTER")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)

        # Switch to Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert test_ref_prog in page.inner_text(".main-panel"), f"Student {test_ref_prog} missing from IT Center Activation Queue!"
        print(f"  -> Student {test_ref_prog} verified in IT Center Activation Queue.")

        # Activate Student Account
        perm_student_id = f"GNCP-2026-{rand_id}"
        page.evaluate(f"""async () => {{
            const studs = window.app.studentsList || window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref_prog}' || x.temp_student_id === '{test_ref_prog}'));
            if (s) {{
                window.app.openReview(s);
                window.app.generatedStudentId = '{perm_student_id}';
                window.app.generatedEmail = '{test_email_prog}';
                await window.app.finalizeEnrollment();
            }}
        }}""")
        page.wait_for_timeout(2500)
        print(f"  -> Activated institutional account & generated Permanent ID: {perm_student_id}.")

        # Verify removal from IT Center active queue table
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert test_ref_prog not in page.inner_html(".data-table tbody"), f"Student {test_ref_prog} still in IT Center active queue!"
        print("  -> Student immediately removed from IT Center Activation Queue.")

        # Switch to Student Directory / Accounts View
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('accounts'); }")
        page.wait_for_timeout(1500)
        assert perm_student_id in page.inner_text(".main-panel"), f"Permanent ID {perm_student_id} missing from IT Center Directory!"
        print(f"  -> Permanent ID {perm_student_id} verified in IT Center Student Directory.")

        # Direct MariaDB Promotion Assertion
        db_official = DBHelper.execute_query("SELECT id, name, program, status, temp_reference_no FROM students WHERE id = :pid", {"pid": perm_student_id})
        assert len(db_official) == 1, f"Official student record missing in students table: {db_official}"
        assert db_official[0]["status"].upper() in ["ACTIVE", "ENROLLED"], f"Unexpected status: {db_official}"
        assert db_official[0]["temp_reference_no"] == test_ref_prog, f"Mismatched reference no: {db_official}"
        print(f"  -> MariaDB Promotion Verified: Student {perm_student_id} successfully created in students table.")

        browser.close()

    print("\n========================================================")
    print("ALL PLAYWRIGHT FULL STATION LIFECYCLE TESTS PASSED 100%!")
    print("========================================================\n")

if __name__ == "__main__":
    run_e2e_tests()
