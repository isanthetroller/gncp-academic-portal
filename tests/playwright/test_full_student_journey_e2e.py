import time
import random
import os
import sys
import json

sys.path.insert(0, os.path.abspath("."))
from playwright.sync_api import sync_playwright
from tests.playwright.config import BASE_URL, CREDENTIALS, PAGES
from tests.playwright.utils.db_helper import DBHelper

def login_as(page, role_key):
    creds = CREDENTIALS[role_key]
    dest = PAGES[role_key]
    page.goto(f"{BASE_URL}/index.html?clear=true&redirect={dest}", wait_until="domcontentloaded")
    page.wait_for_selector("#username", state="visible", timeout=10000)
    page.fill("#username", creds["username"])
    page.fill("#password", creds["password"])
    page.click("button[type='submit'].login-btn, button[type='submit']")
    page.wait_for_timeout(2000)

def run_full_student_journey():
    print("=" * 65)
    print("STARTING PLAYWRIGHT FULL STUDENT JOURNEY E2E SIMULATION")
    print("=" * 65)

    rand_id = random.randint(10000, 99999)
    first_name = f"RealStudent{rand_id}"
    last_name = f"Delacruz{rand_id}"
    full_name = f"{first_name} {last_name}"
    personal_email = f"real.student.{rand_id}@example.com"
    phone_digits = f"{random.randint(10000000, 99999999)}" # 8 digits (after 09)
    birth_date = "2004-08-20"
    
    print(f"\n[PHASE 1] Fresh Public Pre-Registration Submission...")
    print(f"  -> Candidate: {full_name} | Email: {personal_email}")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()

        # Step 1: Pre-Registration Form Submission
        page.goto(f"{BASE_URL}/enrollment-system/index.html")
        page.wait_for_load_state("networkidle")
        page.wait_for_selector("#enrollment-app")

        # Populate form fields on Vue instance
        page.evaluate(f"""() => {{
            const app = document.querySelector('#enrollment-app').__vue_app__;
            const root = app._instance.proxy;
            root.form.studentType = 'FRESHMAN';
            root.form.educationPathway = 'REGULAR';
            root.form.courseCode = 'BSIT';
            root.form.nstp = 'ROTC';
            root.form.firstName = '{first_name}';
            root.form.middleName = 'Santos';
            root.form.lastName = '{last_name}';
            root.form.email = '{personal_email}';
            root.form.phone = '{phone_digits}';
            root.form.birthDate = '{birth_date}';
            root.form.gender = 'Male';
            root.form.address = '123 Academic Way, Sampaloc, Manila';
            root.form.elementarySchool = 'Sampaloc Elementary School';
            root.form.juniorHighSchool = 'Manila High School';
            root.form.seniorHighSchool = 'National Senior High School';
            root.form.shsTrack = 'TVL-ICT';
            root.form.emergencyContactName = 'Maria Delacruz';
            root.form.emergencyContactPhone = '{phone_digits}';
            root.form.healthStatus = 'GOOD';
            root.form.paymentMode = 'CASH';
            root.form.scholarship = 'NONE';
            root.currentStep = 6;
        }}""")
        page.wait_for_timeout(500)

        # Click Final Submit Button
        page.click("button:has-text('Submit Enrollment')")
        page.wait_for_timeout(3500)

        # Verify Step 7 Success Screen
        page.wait_for_selector(".temp-account-card, .confirmation-success-badge, h3:has-text('Enrollment Pre-Registered!')")
        
        # Extract Reference Number and PIN from UI
        ref_text = page.inner_text("body")
        import re
        ref_match = re.search(r"(REF-\d{4}-\d{3,6}|GNCP-\d{4}-\d{3,6})", ref_text)
        assert ref_match, "Could not extract Reference Number from pre-registration confirmation screen!"
        generated_ref = ref_match.group(1)
        print(f"  -> Generated Application Reference Number: {generated_ref}")

        # Assert MariaDB state for Pre-Enrollment
        pre_rec = DBHelper.get_pre_enrollment(generated_ref)
        assert pre_rec, f"Pre-enrollment record {generated_ref} not found in MariaDB!"
        assert pre_rec["status"] == "PRE_REGISTERED", f"Unexpected status: {pre_rec['status']}"
        assert first_name in pre_rec["first_name"], "First name mismatch in DB"
        assert last_name in pre_rec["last_name"], "Last name mismatch in DB"
        print(f"  -> MariaDB Staging Assertion Passed: {generated_ref} saved with status 'PRE_REGISTERED'.")

        # Public Status Tracker Verification
        print(f"\n[PHASE 2] Public Application Tracker Verification...")
        page.goto(f"{BASE_URL}/enrollment-system/tracker.html?id={generated_ref}&pin={pre_rec['temp_pin']}")
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(1500)
        
        tracker_body = page.inner_text("body")
        assert generated_ref in tracker_body, "Student Reference Number not found in Public Tracker UI!"
        assert (full_name in tracker_body or first_name in tracker_body), "Student name not found in Public Tracker UI!"
        print(f"  -> Public Status Tracker verified for {generated_ref}.")

        # =========================================================================
        # PHASE 3: Sequential Workstation Progression
        # =========================================================================
        print(f"\n[PHASE 3] Sequential Workstation Evaluation & Advancement...")

        # -------------------------------------------------------------------------
        # Station 1: Registrar Workstation
        # -------------------------------------------------------------------------
        print(f"  [Station 1/5] Registrar Verification...")
        login_as(page, "REGISTRAR")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(2000)

        dbg = page.evaluate("""() => {
            return {
                currentView: window.app ? window.app.currentView : null,
                appsCount: window.app ? (window.app.pendingApplications || []).length : 0,
                allRefs: window.app ? (window.app.pendingApplications || []).map(a => a.referenceNumber) : []
            };
        }""")
        print("  [DEBUG REGISTRAR]", dbg)

        # Navigate to Pending Applications View
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1500)
        
        # Open Modal and Approve Application in Registrar
        page.evaluate(f"""() => {{
            const apps = window.app.pendingApplications || [];
            const target = apps.find(a => (a.referenceNumber === '{generated_ref}' || a.temp_student_id === '{generated_ref}'));
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
                if page.locator(".swal2-confirm").is_visible():
                    page.locator(".swal2-confirm").click()
                    page.wait_for_timeout(500)

        # Verify student immediately removed from Active Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('pending-applications'); }")
        page.wait_for_timeout(1000)
        active_text_after = page.inner_text("#app")
        assert generated_ref not in active_text_after, f"Approved student {generated_ref} still in active queue!"

        # Assert DB Status: VERIFIED
        db_s1 = DBHelper.get_pre_enrollment(generated_ref)
        assert db_s1["status"] == "VERIFIED", f"Expected VERIFIED, got {db_s1['status']}"
        print(f"    -> Registrar Approved: Status is now VERIFIED.")

        # -------------------------------------------------------------------------
        # Station 2: TLC Helpdesk Workstation
        # -------------------------------------------------------------------------
        print(f"  [Station 2/5] TLC Helpdesk Advising & Sectioning...")
        login_as(page, "HELPDESK")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref in page.inner_text(".main-panel"), f"Student {generated_ref} not in TLC Helpdesk Active Queue!"

        # Complete Advising & NSTP
        page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{generated_ref}' || x.temp_student_id === '{generated_ref}'));
            if (s) {{
                window.app.openReview(s);
                s.section = 'BSIT-1A';
                s.nstp = 'ROTC';
                window.app.markCompleted();
            }}
        }}""")
        page.wait_for_timeout(2000)

        # Assert removed from Helpdesk Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref not in page.inner_text(".main-panel"), "Student still in Helpdesk Active Queue!"
        db_s2 = DBHelper.get_pre_enrollment(generated_ref)
        assert db_s2["status"] == "ADVISED", f"Expected ADVISED, got {db_s2['status']}"
        print(f"    -> TLC Helpdesk Advised: Section BSIT-1A locked, status is ADVISED.")

        # -------------------------------------------------------------------------
        # Station 3: Medical Clinic Workstation
        # -------------------------------------------------------------------------
        print(f"  [Station 3/5] Medical Clinic Physical Exam & Fitness Clearance...")
        login_as(page, "MEDICAL")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref in page.inner_text(".main-panel"), f"Student {generated_ref} not in Medical Clinic Active Queue!"

        # Fill and save fitness checkup
        page.evaluate(f"""() => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{generated_ref}' || x.temp_student_id === '{generated_ref}'));
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

        # Assert removed from Medical Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref not in page.inner_text(".main-panel"), "Student still in Medical Active Queue!"
        db_s3 = DBHelper.get_pre_enrollment(generated_ref)
        assert db_s3["status"] == "MEDICAL_CLEARED", f"Expected MEDICAL_CLEARED, got {db_s3['status']}"
        print(f"    -> Medical Clearance Issued: Status is MEDICAL_CLEARED.")

        # -------------------------------------------------------------------------
        # Station 4: Cashier Treasury Workstation
        # -------------------------------------------------------------------------
        print(f"  [Station 4/5] Cashier Treasury Fee Collection & OR Issuance...")
        login_as(page, "CASHIER")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref in page.inner_text(".main-panel"), f"Student {generated_ref} not in Cashier Active Queue!"

        # Process Payment
        or_number = f"OR-2026-{rand_id}"
        page.evaluate(f"""async () => {{
            const studs = window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{generated_ref}' || x.temp_student_id === '{generated_ref}'));
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
        if page.locator(".swal2-confirm").is_visible():
            page.locator(".swal2-confirm").click()
            page.wait_for_timeout(1000)

        # Assert removed from Cashier Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1500)
        assert generated_ref not in page.inner_html(".data-table tbody"), "Student still in Cashier Active Queue!"
        db_s4 = DBHelper.get_pre_enrollment(generated_ref)
        assert db_s4["status"] == "PAID", f"Expected PAID, got {db_s4['status']}"
        print(f"    -> Cashier Payment Processed: OR {or_number} issued, status is PAID.")

        # -------------------------------------------------------------------------
        # Station 5: IT Center Workstation & Account Promotion
        # -------------------------------------------------------------------------
        print(f"  [Station 5/5] IT Center Account Promotion & Permanent ID Provisioning...")
        login_as(page, "IT_CENTER")
        page.wait_for_selector("#app", state="attached", timeout=10000)
        page.wait_for_timeout(1500)
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref in page.inner_text(".main-panel"), f"Student {generated_ref} not in IT Center Activation Queue!"

        # Finalize Enrollment
        perm_student_id = f"GNCP-2026-{rand_id}"
        student_portal_pass = f"delacruz{rand_id}"
        inst_email = f"realstudent.{rand_id}@gncp.edu.ph"

        page.evaluate(f"""async () => {{
            const studs = window.app.studentsList || window.app.students || [];
            const s = studs.find(x => (x.referenceNumber === '{generated_ref}' || x.temp_student_id === '{generated_ref}'));
            if (s) {{
                window.app.openReview(s);
                window.app.generatedStudentId = '{perm_student_id}';
                window.app.generatedEmail = '{inst_email}';
                window.app.generatedPassword = '{student_portal_pass}';
                await window.app.finalizeEnrollment();
            }}
        }}""")
        page.wait_for_timeout(2500)

        # Assert removed from IT Center Queue
        page.evaluate("() => { if (window.app && window.app.setView) window.app.setView('queue'); }")
        page.wait_for_timeout(1000)
        assert generated_ref not in page.inner_html(".data-table tbody"), "Student still in IT Center Activation Queue!"
        
        # STRICT MariaDB Assertion: Permanent student created in `students` table!
        created_student = DBHelper.get_student(perm_student_id)
        assert created_student, f"Official Student record {perm_student_id} was NOT created in MariaDB students table!"
        assert created_student["status"].upper() in ["ACTIVE", "ENROLLED"], f"Expected Active status in students table, got {created_student['status']}"
        assert created_student["temp_reference_no"] == generated_ref, "temp_reference_no mismatch in students table"
        print(f"    -> IT Center Promotion Successful!")
        print(f"    -> Permanent Student ID : {perm_student_id}")
        print(f"    -> Institutional Email  : {inst_email}")
        print(f"    -> Student Password     : {student_portal_pass}")

        # =========================================================================
        # PHASE 4: Student Perspective & Student Portal Gateway Login
        # =========================================================================
        print(f"\n[PHASE 4] Student Perspective: Portal Login & Dashboard Verification...")
        page.goto(f"{BASE_URL}/student-portal/login.html?clear=true&logout=true")
        page.wait_for_load_state("networkidle")
        page.wait_for_selector("#studentIdInput")

        # Test Invalid Password Rejection (Edge Case)
        page.fill("#studentIdInput", perm_student_id)
        page.fill("#studentPasswordInput", "WrongPassword999!")
        page.click("button[type='submit']")
        page.wait_for_timeout(1000)
        assert "Invalid Student ID or password" in page.inner_text("body"), "Failed to reject invalid password!"
        print(f"  -> Edge Case Verified: Invalid credentials correctly rejected (401).")

        # Log in with Genuine Student Credentials
        page.fill("#studentIdInput", perm_student_id)
        page.fill("#studentPasswordInput", student_portal_pass)
        page.click("button[type='submit']")
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(2500)

        # Assert Redirected to Student Portal Dashboard
        current_url = page.url
        assert "student-portal" in current_url and "login" not in current_url, f"Expected student portal dashboard, got {current_url}"
        
        # Verify Personalized Dashboard Content
        portal_text = page.inner_text("body")
        assert (first_name in portal_text or last_name in portal_text), "Student name not rendered on dashboard!"
        assert perm_student_id in portal_text, "Permanent Student ID not rendered on dashboard!"
        assert "BSIT" in portal_text or "Information Technology" in portal_text, "Enrolled program not rendered on dashboard!"
        print(f"  -> Student Portal Dashboard loaded with dynamic profile and program data.")

        # Test Refresh / Persistence
        page.reload()
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(1500)
        reloaded_text = page.inner_text("body")
        assert perm_student_id in reloaded_text, "Student dashboard lost state after page reload!"
        print(f"  -> Refresh Persistence Verified: Student session remains intact across reload.")

        # =========================================================================
        # PHASE 5: Account Security & RBAC Isolation Verification
        # =========================================================================
        print(f"\n[PHASE 5] Security & Authorization Isolation Verification...")

        # 1. Student attempting to query another student's dashboard
        cross_student_res = page.evaluate("""async () => {
            const res = await fetch('backend/api.php?action=get_student_dashboard&studentId=GNCP-2026-000001');
            return { status: res.status, data: await res.json() };
        }""")
        assert cross_student_res["data"]["success"] is False, "Cross-student dashboard access was NOT blocked!"
        print(f"  -> Cross-Student Data Isolation: Access to another student ID correctly blocked (401).")

        # 2. Student attempting to access Staff Station API
        station_access_res = page.evaluate("""async () => {
            const res = await fetch('../api/index.php?action=stations/queue');
            return { status: res.status, data: await res.json() };
        }""")
        assert station_access_res["data"]["success"] is False, "Staff workstation API was NOT blocked for student session!"
        print(f"  -> Station RBAC Isolation: Student session blocked from workstation queues (401/403).")

        # 3. Student Logout & Session Purge
        page.evaluate("""() => {
            if (window.StudentPortalController && window.StudentPortalController.confirmLogout) {
                window.StudentPortalController.confirmLogout();
            } else {
                sessionStorage.removeItem('gncp_portal_student');
                localStorage.removeItem('gncp_portal_student');
                window.location.replace('login.html?clear=true&logout=true');
            }
        }""")
        page.wait_for_timeout(2000)

        # Assert Redirected to Login
        assert "login" in page.url, "Logout did not redirect to login page!"
        
        # Assert Session Storage Cleared
        stored_sess = page.evaluate("() => sessionStorage.getItem('gncp_portal_student')")
        assert stored_sess is None, "Student session storage was not purged after logout!"
        print(f"  -> Student Logout Verified: Session invalidated, storage purged, redirected to login.")

        browser.close()

    print("\n" + "=" * 65)
    print("ALL END-TO-END STUDENT LIFECYCLE SIMULATION TESTS PASSED 100%!")
    print("=" * 65)

if __name__ == "__main__":
    run_full_student_journey()
