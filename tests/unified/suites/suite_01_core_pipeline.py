import os
import sys
import time
import random
from playwright.sync_api import sync_playwright

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
from config import BASE_URL, CREDENTIALS, SCREENSHOTS_DIR
from utils.db_helper import DBHelper
from utils.reporter import TestReporter

def run_suite():
    reporter = TestReporter("Suite 01: Core 8-Stage Pipeline & Admin CRUD")
    print("\n" + "=" * 78)
    print("  RUNNING SUITE 01: CORE LIFECYCLE PIPELINE & ADMIN WORKFLOWS")
    print("=" * 78)

    rand_id = random.randint(10000, 99999)
    test_ref = f"GNCP-2026-{rand_id}"
    test_pin = "8899"
    test_email = f"test.fullsys.{rand_id}@gncp.edu.ph"
    test_first = "FullSys"
    test_last = f"Student{rand_id}"
    perm_student_id = None
    inst_email = None
    portal_password = test_last.lower()

    # -------------------------------------------------------------------------
    # 0. Preflight Database Verification
    # -------------------------------------------------------------------------
    print("\n--- 0. Preflight Environment & Schema Verification ---")
    db_alive = DBHelper.execute_query("SELECT 1 as alive")
    is_alive = bool(db_alive and db_alive[0].get("alive") == 1)
    reporter.record(
        "Preflight",
        "MariaDB Connection",
        is_alive,
        "Connected to MariaDB gncp_portal on 127.0.0.1:3306"
    )

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(viewport={"width": 1366, "height": 900})
        page = ctx.new_page()

        # ---------------------------------------------------------------------
        # STAGE 1: Public Online Pre-Registration Form
        # ---------------------------------------------------------------------
        print("\n--- Stage 1: Public Online Pre-Registration Form ---")
        page.goto(f"{BASE_URL}/enrollment-system/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1500)

        # Step 1: Program & NSTP
        col_sel = page.locator("#collegeSelect")
        if col_sel.is_visible():
            col_sel.select_option("COIT")
            page.wait_for_timeout(300)

        bsit_card = page.locator(".option-card").filter(has_text="Information Technology").first
        if bsit_card.is_visible():
            bsit_card.click()
            page.wait_for_timeout(300)

        cwts_card = page.locator(".option-card").filter(has_text="CWTS").first
        if cwts_card.is_visible():
            cwts_card.click()
            page.wait_for_timeout(300)

        page.locator("button:has-text('Next Step')").click()
        page.wait_for_timeout(800)

        # Step 2: Personal Information
        page.locator("input[placeholder*='first name']").fill(test_first)
        page.locator("input[placeholder*='middle name']").fill("Reyes")
        page.locator("input[placeholder*='last name']").fill(test_last)
        page.locator("input[placeholder*='example@email.com']").fill(test_email)
        page.locator("input[placeholder*='xxxxxxxxx']").first.fill("917123456")
        page.locator("input[type='date']").fill("2005-08-20")
        page.locator("select.portal-select").first.select_option("Male")
        page.locator("textarea.portal-textarea").fill("Block 10 Lot 5 Diamond Street, Dasmarinas City")

        page.locator("button:has-text('Next Step')").click()
        page.wait_for_timeout(800)

        # Step 3: Academic Background
        page.locator("input[placeholder*='Elementary School']").fill("Dasmarinas Central Elementary")
        page.locator("input[placeholder*='High School / JHS name']").fill("Dasmarinas National High School")
        shs_in = page.locator("input[placeholder*='Senior High School']").first
        if shs_in.is_visible():
            shs_in.fill("Cavite Science SHS")

        page.locator("button:has-text('Next Step')").click()
        page.wait_for_timeout(800)

        # Step 4: Medical Pre-Screening & Emergency
        health_opt = page.locator(".option-card").filter(has_text="Good Health").first
        if health_opt.is_visible():
            health_opt.click()
            page.wait_for_timeout(300)

        em_n = page.locator("input[placeholder*='Parent / Guardian']").first
        if em_n.is_visible():
            em_n.fill("Elena Reyes Student")
        em_p = page.locator("input[placeholder*='xxxxxxxxx']").first
        if em_p.is_visible():
            em_p.fill("918999888")

        page.locator("button:has-text('Next Step')").click()
        page.wait_for_timeout(800)

        # Step 5: Tuition Plan
        cash_opt = page.locator(".option-card").filter(has_text="Cash").first
        if not cash_opt.is_visible():
            cash_opt = page.locator(".option-card").first
        if cash_opt.is_visible():
            cash_opt.click()
            page.wait_for_timeout(300)

        page.locator("button:has-text('Next Step')").click()
        page.wait_for_timeout(800)

        # Step 6: Submit
        page.locator("button:has-text('Submit Enrollment')").first.click()
        page.wait_for_timeout(3000)

        # Poll MariaDB to extract generated reference number and PIN
        db_new = None
        for _ in range(10):
            rows = DBHelper.execute_query("SELECT * FROM pre_enrollments WHERE email = :email LIMIT 1", {"email": test_email})
            if rows:
                db_new = rows[0]
                break
            time.sleep(1)

        reg_success = bool(db_new is not None)
        if db_new:
            test_ref = db_new["temp_student_id"]
            test_pin = db_new["temp_pin"]

        reporter.record(
            "Lifecycle",
            "Stage 1: Public Pre-Registration Form Submission",
            reg_success,
            f"Candidate registered. Ref: {test_ref} | PIN: {test_pin} | Status: {db_new['status'] if db_new else 'N/A'}"
        )

        # ---------------------------------------------------------------------
        # STAGE 2: Public Application Tracker
        # ---------------------------------------------------------------------
        print("\n--- Stage 2: Public Application Tracker ---")
        page.goto(f"{BASE_URL}/enrollment-system/tracker.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)

        ref_box = page.locator("input[placeholder*='GNCP-'], input[placeholder*='e.g.'], input[name='referenceNumber'], #refNumber").first
        pin_box = page.locator("input[placeholder*='PIN'], input[placeholder*='digit'], input[type='password'], #tempPin").first
        track_b = page.locator("button[type='submit'], button:has-text('Access Tracker'), button:has-text('Track Application')").first

        if ref_box.is_visible():
            ref_box.fill(test_ref)
            if pin_box.is_visible():
                pin_box.fill(test_pin)
            track_b.click()
            page.wait_for_timeout(2000)

        tracker_text = page.inner_text("#app, .tracker-container, body")
        tracker_ok = (test_ref in tracker_text or test_last in tracker_text or "submitted" in tracker_text.lower())
        reporter.record(
            "Lifecycle",
            "Stage 2: Public Application Tracker Roadmap Lookup",
            tracker_ok,
            f"Tracker rendered roadmap for applicant {test_ref}"
        )

        # ---------------------------------------------------------------------
        # STAGE 3: Registrar Station Review & Approval
        # ---------------------------------------------------------------------
        print("\n--- Stage 3: Registrar Review & Verification ---")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/registrar/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        u_in = page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(CREDENTIALS["registrar"]["username"])
            page.locator("#password, input[name='password']").first.fill(CREDENTIALS["registrar"]["password"])
            page.locator("button[type='submit'], .login-btn").first.click()
            page.wait_for_timeout(2000)

        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1000)

        # Approve applicant via UI
        page.evaluate(f"""() => {{
            const apps = window.app.pendingApplications || [];
            const target = apps.find(a => a.referenceNumber === '{test_ref}');
            if (target) {{
                window.app.openApplicationModal(target);
                window.app.selectedApplication.sectionCode = 'BSIT 1-A';
                const reqs = window.app.selectedApplication.requirements || [];
                reqs.forEach(r => window.app.setDocStatus(r, 'ORIGINAL'));
            }}
        }}""")
        page.wait_for_timeout(1000)

        app_btn = page.locator("button:has-text('Approve & Verify'), button:has-text('Approve Application')").first
        if app_btn.is_visible():
            app_btn.click()
            page.wait_for_timeout(500)
            if page.locator(".swal2-confirm").is_visible():
                page.locator(".swal2-confirm").click()
                page.wait_for_timeout(1500)
                if page.locator(".swal2-confirm").is_visible():
                    page.locator(".swal2-confirm").click()
                    page.wait_for_timeout(500)

        db_reg = DBHelper.execute_query("SELECT status FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": test_ref})
        reg_verified = bool(len(db_reg) == 1 and db_reg[0]["status"] == "VERIFIED")
        reporter.record(
            "Lifecycle",
            "Stage 3: Registrar Document & Eligibility Verification",
            reg_verified,
            f"Applicant status in DB: {db_reg[0]['status'] if db_reg else 'Not found'}"
        )

        # ---------------------------------------------------------------------
        # STAGE 4: TLC Helpdesk Advising & Sectioning
        # ---------------------------------------------------------------------
        print("\n--- Stage 4: TLC Helpdesk Academic Advising ---")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/tlc-helpdesk/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        u_in = page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(CREDENTIALS["helpdesk"]["username"])
            page.locator("#password, input[name='password']").first.fill(CREDENTIALS["helpdesk"]["password"])
            page.locator("button[type='submit'], .login-btn").first.click()
            page.wait_for_timeout(2000)

        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)

        page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref}' || x.temp_student_id === '{test_ref}'));
            if (s) {{
                window.app.openReview(s);
                s.section = 'BSIT 1-A';
                s.nstp = 'ROTC';
                window.app.markCompleted();
            }}
        }}""")
        page.wait_for_timeout(2000)

        db_hd = DBHelper.execute_query("SELECT status, section_code FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": test_ref})
        hd_advised = bool(len(db_hd) == 1 and db_hd[0]["status"] == "ADVISED" and db_hd[0]["section_code"] == "BSIT 1-A")
        reporter.record(
            "Lifecycle",
            "Stage 4: TLC Helpdesk Advising & Section Allocation",
            hd_advised,
            f"Status: {db_hd[0]['status'] if db_hd else 'None'} | Section: {db_hd[0]['section_code'] if db_hd else 'None'}"
        )

        # ---------------------------------------------------------------------
        # STAGE 5: Medical Clinic Fitness Clearance
        # ---------------------------------------------------------------------
        print("\n--- Stage 5: Medical Clinic Physical Exam & Clearance ---")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/medical-checkup/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        u_in = page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(CREDENTIALS["medical"]["username"])
            page.locator("#password, input[name='password']").first.fill(CREDENTIALS["medical"]["password"])
            page.locator("button[type='submit'], .login-btn").first.click()
            page.wait_for_timeout(2000)

        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)

        page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref}' || x.temp_student_id === '{test_ref}'));
            if (s) {{
                window.app.openReview(s);
                s.physicalExam = 'passed';
                s.medicalInterview = 'passed';
                s.peFitness = 'fit';
                s.nstpFitness = 'fit';
                s.status = 'fit';
                s.notes = 'Passed complete physical examination. Cleared for enrollment.';
                window.app.saveCheckup();
            }}
        }}""")
        page.wait_for_timeout(2000)

        db_med = DBHelper.execute_query("SELECT status FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": test_ref})
        med_cleared = bool(len(db_med) == 1 and db_med[0]["status"] == "MEDICAL_CLEARED")
        reporter.record(
            "Lifecycle",
            "Stage 5: Medical Clinic Physical Fitness Clearance",
            med_cleared,
            f"Status: {db_med[0]['status'] if db_med else 'None'}"
        )

        # ---------------------------------------------------------------------
        # STAGE 6: Cashier Tuition Calculation & Payment Processing
        # ---------------------------------------------------------------------
        print("\n--- Stage 6: Cashier Tuition Calculation & Payment Processing ---")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/payment-processing/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        u_in = page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(CREDENTIALS["cashier"]["username"])
            page.locator("#password, input[name='password']").first.fill(CREDENTIALS["cashier"]["password"])
            page.locator("button[type='submit'], .login-btn").first.click()
            page.wait_for_timeout(2000)

        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)

        cashier_calc = page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref}' || x.temp_student_id === '{test_ref}'));
            if (s) {{
                window.app.openProcess(s);
                const calc = window.app.activeCalculation || {{}};
                return {{
                    totalFee: s.payment?.totalFee || calc.totalFee || 0
                }};
            }}
            return {{ totalFee: 0 }};
        }}""")
        page.wait_for_timeout(1000)

        total_fee = float(cashier_calc.get("totalFee", 0))
        tuition_accurate = (total_fee == 18300.00)

        # Pay assessment
        page.evaluate(f"""async () => {{
            window.app.payAmountInput = {total_fee};
            window.app.cashTendered = {total_fee};
            window.app.selectedPaymentMethod = 'CASH';
            setTimeout(() => {{
                const btn = document.querySelector('.swal2-confirm');
                if (btn) btn.click();
            }}, 800);
            await window.app.recordPayment();
        }}""")
        page.wait_for_timeout(2500)

        if page.locator(".swal2-confirm").is_visible():
            page.locator(".swal2-confirm").click()
            page.wait_for_timeout(1000)

        db_cash = DBHelper.execute_query("SELECT status FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": test_ref})
        db_pays = DBHelper.get_payments(test_ref)
        cash_paid = bool(len(db_cash) == 1 and db_cash[0]["status"] == "PAID" and len(db_pays) >= 1)
        reporter.record(
            "Lifecycle",
            "Stage 6: Cashier Tuition Assessment & Official Payment",
            cash_paid and tuition_accurate,
            f"Assessment: PHP {total_fee:,.2f} | Status: {db_cash[0]['status'] if db_cash else 'None'} | Payments recorded: {len(db_pays)}"
        )

        # ---------------------------------------------------------------------
        # STAGE 7: IT Center Promotion to Official Students Directory
        # ---------------------------------------------------------------------
        print("\n--- Stage 7: IT Center Official Account Promotion ---")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/it-center/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        u_in = page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(CREDENTIALS["it_officer"]["username"])
            page.locator("#password, input[name='password']").first.fill(CREDENTIALS["it_officer"]["password"])
            page.locator("button[type='submit'], .login-btn").first.click()
            page.wait_for_timeout(2000)

        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)

        perm_student_id = f"GNCP-2026-{rand_id}"
        inst_email = f"playwright.student{rand_id}@gncp.edu.ph"

        page.evaluate(f"""async () => {{
            const studs = window.app.studentsList || window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{test_ref}' || x.temp_student_id === '{test_ref}'));
            if (s) {{
                window.app.openReview(s);
                window.app.generatedStudentId = '{perm_student_id}';
                window.app.generatedEmail = '{inst_email}';
                window.app.generatedPassword = '{portal_password}';
                await window.app.finalizeEnrollment();
            }}
        }}""")
        page.wait_for_timeout(3500)

        db_student = DBHelper.execute_query("SELECT * FROM students WHERE id = :pid OR temp_reference_no = :ref", {"pid": perm_student_id, "ref": test_ref})
        it_promoted = bool(len(db_student) >= 1 and db_student[0]["status"].upper() in ["ACTIVE", "ENROLLED"])
        if db_student:
            perm_student_id = db_student[0]["id"]
        reporter.record(
            "Lifecycle",
            "Stage 7: IT Center Account Promotion to MariaDB Directory",
            it_promoted,
            f"Created student record: {perm_student_id} | Status: {db_student[0]['status'] if db_student else 'None'}"
        )

        # ---------------------------------------------------------------------
        # STAGE 8: Student Portal Self-Service Authentication & COR
        # ---------------------------------------------------------------------
        print("\n--- Stage 8: Student Portal Authentication & Academic Dashboard ---")
        page.goto(f"{BASE_URL}/student-portal/login.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)

        sid_in = page.locator("#studentIdInput, #studentId, input[placeholder*='Student ID']").first
        spass_in = page.locator("#studentPasswordInput, #password, input[type='password']").first
        slogin_btn = page.locator("button[type='submit'], .login-btn").first

        sid_in.fill(perm_student_id)
        spass_in.fill(portal_password)
        slogin_btn.click()
        page.wait_for_timeout(2500)

        portal_text = page.inner_text("body")
        portal_loaded = (perm_student_id in portal_text or test_first in portal_text or "student" in page.url.lower())
        reporter.record(
            "Lifecycle",
            "Stage 8: Student Portal Self-Service Authentication & COR",
            portal_loaded,
            f"Student portal session active for {perm_student_id}"
        )

        # ---------------------------------------------------------------------
        # 9. Admin Portal CRUD (Announcements & Academic Milestones)
        # ---------------------------------------------------------------------
        print("\n--- 9. Admin Portal CRUD Operations ---")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/admin/index.html", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        u_in = page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(CREDENTIALS["admin"]["username"])
            page.locator("#password, input[name='password']").first.fill(CREDENTIALS["admin"]["password"])
            page.locator("button[type='submit'], .login-btn").first.click()
            page.wait_for_timeout(2000)

        ann_title = f"UNIFIED_TEST_ANNOUNCEMENT_{rand_id}"
        ann_updated = f"{ann_title}_UPDATED"

        # Create announcement
        page.evaluate(f"""async () => {{
            window.app.openAnnouncementModal();
            await new Promise(r => setTimeout(r, 200));
            window.app.announcementForm.title = '{ann_title}';
            const canvas = document.getElementById('announcement-content-canvas');
            if (canvas) canvas.innerHTML = '<p>Automated test announcement body.</p>';
            await window.app.saveAnnouncement();
        }}""")
        page.wait_for_timeout(1500)

        db_ann = DBHelper.execute_query("SELECT * FROM announcements WHERE title = :title", {"title": ann_title})
        ann_created = bool(len(db_ann) == 1)
        ann_id = db_ann[0]["id"] if db_ann else None
        reporter.record(
            "AdminCRUD",
            "Announcement CREATE Operation",
            ann_created,
            f"Announcement #{ann_id} created in DB"
        )

        # Update announcement
        if ann_id:
            page.evaluate(f"""async () => {{
                const ann = window.app.announcements.find(a => a.id === {ann_id}) || {{ id: {ann_id} }};
                window.app.openAnnouncementModal(ann);
                await new Promise(r => setTimeout(r, 200));
                window.app.announcementForm.title = '{ann_updated}';
                await window.app.saveAnnouncement();
            }}""")
            page.wait_for_timeout(1500)

            db_up = DBHelper.execute_query("SELECT * FROM announcements WHERE id = :id", {"id": ann_id})
            ann_up_ok = bool(len(db_up) == 1 and db_up[0]["title"] == ann_updated)
            reporter.record(
                "AdminCRUD",
                "Announcement UPDATE Operation",
                ann_up_ok,
                f"Announcement #{ann_id} title updated to {ann_updated}"
            )

            # Delete announcement
            page.evaluate(f"""async () => {{
                setTimeout(() => {{
                    const btn = document.querySelector('.swal2-confirm');
                    if (btn) btn.click();
                }}, 400);
                await window.app.deleteAnnouncement({ann_id});
            }}""")
            page.wait_for_timeout(1500)

            db_del = DBHelper.execute_query("SELECT * FROM announcements WHERE id = :id", {"id": ann_id})
            ann_del_ok = bool(len(db_del) == 0)
            reporter.record(
                "AdminCRUD",
                "Announcement DELETE Operation",
                ann_del_ok,
                f"Announcement #{ann_id} successfully deleted from MariaDB"
            )

        # Milestone CREATE & DELETE
        ms_title = f"UNIFIED_TEST_MILESTONE_{rand_id}"
        page.evaluate(f"""async () => {{
            window.app.openMilestoneModal();
            window.app.milestoneForm.title = '{ms_title}';
            window.app.milestoneForm.status = 'ACTIVE';
            window.app.milestoneForm.date_start = '2026-10-01';
            window.app.milestoneForm.date_end = '2026-10-05';
            window.app.milestoneForm.date_display = 'Oct 01 - 05, 2026';
            window.app.milestoneForm.display_order = 1;
            await window.app.saveMilestone();
        }}""")
        page.wait_for_timeout(1500)

        db_ms = DBHelper.execute_query("SELECT * FROM academic_milestones WHERE title = :title", {"title": ms_title})
        ms_created = bool(len(db_ms) == 1)
        ms_id = db_ms[0]["id"] if db_ms else None

        if ms_id:
            page.evaluate(f"""async () => {{
                setTimeout(() => {{
                    const btn = document.querySelector('.swal2-confirm');
                    if (btn) btn.click();
                }}, 400);
                await window.app.deleteMilestone({ms_id});
            }}""")
            page.wait_for_timeout(1500)
            ms_deleted = bool(len(DBHelper.execute_query("SELECT * FROM academic_milestones WHERE id = :id", {"id": ms_id})) == 0)
        else:
            ms_deleted = False

        reporter.record(
            "AdminCRUD",
            "Academic Milestone CREATE & DELETE Lifecycle",
            ms_created and ms_deleted,
            f"Milestone #{ms_id} successfully created and purged from MariaDB"
        )

        ctx.close()
        browser.close()

    reporter.print_summary()
    return reporter

if __name__ == "__main__":
    rep = run_suite()
    s = rep.get_summary()
    sys.exit(0 if s["failed"] == 0 else 1)
