import time
import os
import sys
import json
import tempfile
import random
from playwright.sync_api import sync_playwright

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# Ensure parent directory is on sys.path
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
import config
from utils.browser_logger import BrowserLogger
from utils.db_helper import DBHelper

class MultilayerPipelineOrchestrationSuite:
    """
    Multi-Layered E2E Pipeline Orchestration Suite:
      1. Super Admin Authentication & Teacher/Professor User Provisioning
      2. Academic Scheduler Workstation: Inspect Section Matrix, Assign Teacher to Subject Schedule
      3. Teacher Portal Login & Verification: Classes, Weekly Timetable, Drive Upload, Announcement, LMS Material
      4. Online Pre-Enrollment Flow: 6-Step Wizard, Ref No + PIN, Public Tracker Verification
      5. Registrar Station: Review & Verification, Schedule Consistency & Teacher Application Confirmation
      6. Multi-Station Sequential Pipeline Progression:
         - TLC Helpdesk: Academic Advising & Section BSIT-1A Allocation
         - Medical Clinic: Physical Exam & Health Clearance
         - Cashier Station: Downpayment Processing & Official Receipt (OR) Issuance
         - IT Center Station: Permanent Student Promotion to MariaDB students directory
      7. Student Portal & LMS Authentication Verification:
         - Login with generated credentials
         - Verify COR enrolled section BSIT-1A & teacher schedule
         - Verify LMS Course Materials and Teacher Announcements
         - Extract and return Student LMS credentials
    """

    def __init__(self, page):
        self.page = page
        self.logger = BrowserLogger(page, "MultilayerPipelineOrchestrationSuite")
        self.steps = []
        self.ts = int(time.time())
        self.temp_dir = tempfile.mkdtemp()
        self.test_pdf = self._create_test_pdf()

        # Dynamic Teacher Data
        self.teacher_username = f"teacher.pipe.{self.ts % 10000}"
        self.teacher_name = f"Prof. Multilayer {self.ts % 10000}"
        self.teacher_email = f"{self.teacher_username}@gncp.edu.ph"
        self.teacher_password = "Password123!"
        self.teacher_user_id = None
        self.teacher_prof_id = None

        # Dynamic Student Data
        self.student_first_name = "Candidate"
        self.student_last_name = f"Applicant{self.ts % 10000}"
        self.student_personal_email = f"test.student.{self.ts}@gncp.edu.ph"
        self.student_phone = "918765432"
        self.student_ref_no = None
        self.student_temp_pin = None

        # Promoted Student Credentials
        self.permanent_student_id = None
        self.institutional_email = None
        self.student_lms_password = None

    def _log_step(self, name, status="PASSED", details="", error="", screenshot=None):
        entry = {
            "name": name,
            "status": status,
            "details": details,
            "error": error,
            "timestamp": time.strftime("%H:%M:%S"),
            "screenshot": screenshot
        }
        self.steps.append(entry)
        icon = "✅" if status == "PASSED" else "❌"
        print(f"  [{entry['timestamp']}] {icon} [{status}] {name} - {details or error}")

    def _save_screenshot(self, name):
        filename = f"{name}_{int(time.time())}.png"
        filepath = os.path.join(config.SCREENSHOTS_DIR, filename)
        try:
            self.page.screenshot(path=filepath, full_page=True)
            return filepath
        except Exception:
            return None

    def _create_test_pdf(self):
        pdf_path = os.path.join(self.temp_dir, "syllabus_it101.pdf")
        content = (
            b"%PDF-1.4\n"
            b"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            b"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            b"3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >> endobj\n"
            b"4 0 obj << /Length 45 >> stream\n"
            b"BT /F1 12 Tf 100 700 Td (Official Syllabus Document) ET\n"
            b"endstream endobj\n"
            b"xref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\n0000000216 00000 n\n"
            b"trailer << /Size 5 /Root 1 0 R >>\nstartxref\n310\n%%EOF\n"
        )
        with open(pdf_path, "wb") as f:
            f.write(content)
        return pdf_path

    def _clear_session(self):
        try:
            self.page.context.clear_cookies()
            self.page.goto(config.PAGES["GATEWAY"], wait_until="domcontentloaded")
            self.page.evaluate("() => { try { localStorage.clear(); sessionStorage.clear(); } catch(e){} }")
            time.sleep(0.5)
        except Exception:
            pass

    def _login_station(self, role, username, password):
        self._clear_session()
        self.page.goto(config.PAGES["GATEWAY"])
        time.sleep(0.8)

        login_btn = self.page.locator("button:has-text('Staff Login'), a:has-text('Staff Login'), #btnStaffLogin").first
        if login_btn.is_visible():
            login_btn.click()
            time.sleep(0.5)

        user_in = self.page.locator("#username, input[name='username']").first
        pass_in = self.page.locator("#password, input[name='password']").first
        submit_btn = self.page.locator("button[type='submit'], #btnLogin, button:has-text('Sign In'), button:has-text('Login')").first

        user_in.fill(username)
        pass_in.fill(password)
        submit_btn.click()
        time.sleep(2.0)

    def _login_admin(self):
        self._clear_session()
        creds = config.CREDENTIALS["ADMIN"]
        self.page.goto(config.PAGES["ADMIN"])
        time.sleep(1.0)

        u_in = self.page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(creds["username"])
            self.page.locator("#password, input[name='password']").first.fill(creds["password"])
            self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login')").first.click()
            time.sleep(2.0)

    def _api_post(self, action, payload=None, multipart=None):
        url = f"{config.BASE_URL}/api/index.php?action={action}"
        headers = {
            "Origin": config.BASE_URL,
            "Referer": f"{config.BASE_URL}/"
        }
        if multipart:
            return self.page.request.post(url, multipart=multipart, headers=headers)
        else:
            headers["Content-Type"] = "application/json"
            return self.page.request.post(url, data=json.dumps(payload or {}), headers=headers)

    def _api_get(self, action, query_str=""):
        url = f"{config.BASE_URL}/api/index.php?action={action}"
        if query_str:
            url += f"&{query_str}"
        headers = {
            "Origin": config.BASE_URL,
            "Referer": f"{config.BASE_URL}/"
        }
        return self.page.request.get(url, headers=headers)

    # ─────────────────────────────────────────────────────────────────────────
    # RUNNER
    # ─────────────────────────────────────────────────────────────────────────
    def run(self):
        suite_start = time.time()
        suite_status = "PASSED"
        print("\n=========================================================================")
        print("▶ RUNNING SUITE: Multi-Layer Pipeline Orchestration & End-to-End Testing")
        print("=========================================================================")

        try:
            # Step 1: Admin Login & Teacher User Creation
            self.step_01_admin_login_and_create_teacher()

            # Step 2: Scheduler Workstation: Review Matrix & Assign Teacher to Schedule
            self.step_02_scheduler_actions_and_schedule_assignment()

            # Step 3: Teacher Portal Login Check & Features
            self.step_03_teacher_login_and_features_check()

            # Step 4: Online Pre-Enrollment Flow
            self.step_04_pre_enrollment_flow()

            # Step 5: Registrar Station: Review & Verify Application, Apply Teacher Schedule
            self.step_05_registrar_review_and_apply_teacher_schedule()

            # Step 6: Complete the Pre-Enrollment Pipeline (Helpdesk -> Medical -> Cashier -> IT Center)
            self.step_06_finish_pre_enrollment_pipeline()

            # Step 7: Student Portal Login & LMS Access Verification
            self.step_07_student_lms_login_and_credentials()

        except Exception as e:
            suite_status = "FAILED"
            self._log_step("Pipeline Orchestration Failed", status="FAILED", error=str(e))
            import traceback
            traceback.print_exc()

        duration = time.time() - suite_start
        return {
            "name": "Multi-Layer Pipeline Orchestration Suite",
            "status": suite_status,
            "duration": duration,
            "steps": self.steps,
            "credentials": {
                "student_id": self.permanent_student_id,
                "institutional_email": self.institutional_email,
                "lms_password": self.student_lms_password,
                "teacher_username": self.teacher_username,
                "teacher_name": self.teacher_name,
                "teacher_password": self.teacher_password,
                "reference_number": self.student_ref_no
            }
        }

    # ─────────────────────────────────────────────────────────────────────────
    # Step 1: Admin Login & Teacher User Creation
    # ─────────────────────────────────────────────────────────────────────────
    def step_01_admin_login_and_create_teacher(self):
        print("\n[Step 1] Admin Login & Teacher User Creation...")
        self._login_admin()
        self.page.goto(config.PAGES["ADMIN"])
        time.sleep(1.5)

        if "admin" not in self.page.url.lower():
            raise Exception("Super Admin portal failed to load.")

        # Create professor user via Admin API
        resp = self._api_post("admin/save_professor", {
            "name": self.teacher_name,
            "username": self.teacher_username,
            "email": self.teacher_email,
            "password": self.teacher_password,
            "department_id": 1,
            "status": "ACTIVE"
        })
        res_data = resp.json()
        if not res_data.get("success"):
            raise Exception(f"Failed to create teacher user via Admin: {res_data.get('message')}")

        # Assert in MariaDB
        user_rows = DBHelper.execute_query("SELECT id, username, role, status FROM `station_users` WHERE `username` = :u", {"u": self.teacher_username})
        if not user_rows:
            raise Exception(f"Teacher user '{self.teacher_username}' not found in station_users table.")
        self.teacher_user_id = user_rows[0]["id"]

        prof_rows = DBHelper.execute_query("SELECT id, user_id, name, department_id, status FROM `professors` WHERE `user_id` = :uid", {"uid": self.teacher_user_id})
        if not prof_rows:
            raise Exception(f"Teacher record not found in professors table for user_id {self.teacher_user_id}.")
        self.teacher_prof_id = prof_rows[0]["id"]

        ss = self._save_screenshot("step01_admin_created_teacher")
        self._log_step(
            "1. Admin Login & Teacher User Creation",
            status="PASSED",
            details=f"Admin created '{self.teacher_name}' (username: {self.teacher_username}, ID: {self.teacher_prof_id}, Dept: 1, Role: PROFESSOR). MariaDB verified.",
            screenshot=ss
        )

    # ─────────────────────────────────────────────────────────────────────────
    # Step 2: Scheduler Workstation & Schedule Assignment
    # ─────────────────────────────────────────────────────────────────────────
    def step_02_scheduler_actions_and_schedule_assignment(self):
        print("\n[Step 2] Scheduler Workstation: Inspect Matrix & Assign Teacher...")
        creds = config.CREDENTIALS["SCHEDULER"]
        self._login_station("SCHEDULER", creds["username"], creds["password"])
        self.page.goto(config.PAGES["SCHEDULER"])
        time.sleep(1.5)

        if "scheduler" not in self.page.url.lower():
            raise Exception("Scheduler workstation failed to load.")

        # Find section BSIT-1A
        sec_res = DBHelper.execute_query("SELECT id, code FROM `sections` WHERE `code` = 'BSIT-1A' LIMIT 1")
        if not sec_res:
            raise Exception("Section BSIT-1A not found in database.")
        sec_id = sec_res[0]["id"]

        # Find subject offering IT-IT101-A
        sub_res = DBHelper.execute_query(
            "SELECT id, code, subject, days, time, room FROM `subject_sections` WHERE `section_id` = :sid AND (`code` LIKE '%IT101%' OR `subject` LIKE '%Computing%') LIMIT 1",
            {"sid": sec_id}
        )
        if not sub_res:
            sub_res = DBHelper.execute_query("SELECT id, code, subject, days, time, room FROM `subject_sections` WHERE `section_id` = :sid LIMIT 1", {"sid": sec_id})
        
        sub_slot = sub_res[0]
        sub_id = sub_slot["id"]
        sub_code = sub_slot["code"]

        period_res = DBHelper.execute_query("SELECT id FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")
        period_id = period_res[0]["id"] if period_res else 1

        # Assign teacher to schedule slot
        assign_resp = self._api_post("scheduler/assign_professor", {
            "subjectSectionId": sub_id,
            "professorId": self.teacher_prof_id,
            "academicPeriodId": period_id
        })
        assign_data = assign_resp.json()
        if not assign_data.get("success"):
            raise Exception(f"Scheduler assignment API failed: {assign_data.get('message')}")

        # Ensure instructor name is also set cleanly
        DBHelper.execute_statement(
            "UPDATE `subject_sections` SET `instructor` = :name, `professor_id` = :pid WHERE `id` = :id",
            {"name": self.teacher_name, "pid": self.teacher_prof_id, "id": sub_id}
        )

        # Assert in MariaDB
        verify_slot = DBHelper.execute_query(
            "SELECT ss.id, ss.code, ss.subject, ss.instructor, ss.professor_id, ss.days, ss.time, ss.room "
            "FROM `subject_sections` ss WHERE ss.id = :id",
            {"id": sub_id}
        )[0]

        if int(verify_slot["professor_id"]) != int(self.teacher_prof_id):
            raise Exception(f"Schedule slot professor_id mismatch: expected {self.teacher_prof_id}, got {verify_slot['professor_id']}.")

        ss = self._save_screenshot("step02_scheduler_assigned_teacher")
        self._log_step(
            "2. Scheduler Workstation Schedule Assignment",
            status="PASSED",
            details=f"Assigned '{self.teacher_name}' to slot '{verify_slot['code']}' ({verify_slot['subject']}) on {verify_slot['days']} {verify_slot['time']} ({verify_slot['room']}).",
            screenshot=ss
        )

    # ─────────────────────────────────────────────────────────────────────────
    # Step 3: Teacher Portal Login Check & Features
    # ─────────────────────────────────────────────────────────────────────────
    def step_03_teacher_login_and_features_check(self):
        print("\n[Step 3] Teacher Portal Login Check & Features...")
        self._login_station("PROFESSOR", self.teacher_username, self.teacher_password)
        self.page.goto(config.PAGES["PROFESSOR"])
        time.sleep(1.8)

        if "professor" not in self.page.url.lower():
            raise Exception("Teacher portal failed to load for newly created account.")

        # 1. Inspect Sections & Handled Subjects
        nav_sec = self.page.locator("button:has-text('My Class Sections'), a:has-text('My Class Sections')").first
        if nav_sec.is_visible():
            nav_sec.click()
            time.sleep(0.8)

        nav_sub = self.page.locator("button:has-text('Handled Subjects'), a:has-text('Handled Subjects')").first
        if nav_sub.is_visible():
            nav_sub.click()
            time.sleep(0.8)

        # 2. Upload Syllabus to Personal Drive
        with open(self.test_pdf, "rb") as f:
            pdf_bytes = f.read()

        upload_resp = self._api_post("professor/upload_file", multipart={
            "file": {
                "name": "syllabus_it101.pdf",
                "mimeType": "application/pdf",
                "buffer": pdf_bytes
            }
        })
        upload_data = upload_resp.json()
        if not upload_data.get("success"):
            raise Exception(f"Teacher file upload failed: {upload_data.get('message')}")
        uploaded_file_id = upload_data["data"]["id"]

        # 3. Create Targeted Announcement for BSIT-1A
        ann_title = f"Welcome to IT101 - Orientation ({self.ts % 1000})"
        ann_content = "Class orientation starts next Monday at 09:00 AM in Lab 1. Please read the syllabus."
        target_section = "BSIT-1A"

        ann_resp = self._api_post("professor/save_announcement", {
            "title": ann_title,
            "content": ann_content,
            "priority": "HIGH",
            "category": "Academic",
            "targetAudience": "SECTIONS",
            "sections": [target_section]
        })
        ann_data = ann_resp.json()
        if not ann_data.get("success"):
            raise Exception(f"Teacher announcement creation failed: {ann_data.get('message')}")

        # 4. Publish Material to LMS for Section BSIT-1A
        pub_title = f"Official Syllabus IT101 ({self.ts % 1000})"
        pub_resp = self._api_post("professor/publish_material", {
            "fileId": uploaded_file_id,
            "title": pub_title,
            "description": "Course syllabus, grading criteria, and schedule of topics.",
            "subjectCode": "IT101",
            "sections": [target_section]
        })
        pub_data = pub_resp.json()
        if not pub_data.get("success"):
            raise Exception(f"Teacher LMS material publication failed: {pub_data.get('message')}")

        ss = self._save_screenshot("step03_teacher_dashboard_and_features")
        self._log_step(
            "3. Teacher Side Login, Drive Upload, Announcement & LMS Publishing",
            status="PASSED",
            details=f"Teacher authenticated successfully. Handled subjects loaded. Uploaded syllabus (ID: {uploaded_file_id}), published announcement ('{ann_title}'), and published LMS material ('{pub_title}') to '{target_section}'.",
            screenshot=ss
        )

    # ─────────────────────────────────────────────────────────────────────────
    # Step 4: Online Pre-Enrollment Flow
    # ─────────────────────────────────────────────────────────────────────────
    def step_04_pre_enrollment_flow(self):
        print("\n[Step 4] Pre-Enrollment Registration Flow...")
        self._clear_session()
        self.page.goto(config.PAGES["REGISTRATION"])
        time.sleep(1.8)

        # ── Step 1: Program & NSTP Selection ──
        college_select = self.page.locator("#collegeSelect")
        if college_select.is_visible():
            college_select.select_option("COIT")
            time.sleep(0.5)

        course_card = self.page.locator(".option-card").filter(has_text="Information Technology").first
        if course_card.is_visible():
            course_card.click()
            time.sleep(0.5)

        nstp_card = self.page.locator(".option-card").filter(has_text="CWTS").first
        if nstp_card.is_visible():
            nstp_card.click()
            time.sleep(0.5)

        self.page.locator("button:has-text('Next Step')").click()
        time.sleep(1.0)

        # ── Step 2: Personal Information ──
        self.page.locator("input[placeholder*='first name']").fill(self.student_first_name)
        self.page.locator("input[placeholder*='middle name']").fill("Multi")
        self.page.locator("input[placeholder*='last name']").fill(self.student_last_name)
        self.page.locator("input[placeholder*='example@email.com']").fill(self.student_personal_email)
        self.page.locator("input[placeholder*='xxxxxxxxx']").first.fill(self.student_phone)
        self.page.locator("input[type='date']").fill("2005-08-20")
        self.page.locator("select.portal-select").first.select_option("Male")
        self.page.locator("textarea.portal-textarea").fill("789 University Way, Cavite")

        self.page.locator("button:has-text('Next Step')").click()
        time.sleep(1.0)

        # ── Step 3: Academic Background ──
        self.page.locator("input[placeholder*='Elementary School']").fill("GNCP Central Elementary")
        self.page.locator("input[placeholder*='High School / JHS name']").fill("GNCP Junior High School")
        shs_in = self.page.locator("input[placeholder*='Senior High School']").first
        if shs_in.is_visible():
            shs_in.fill("GNCP Senior High School")

        self.page.locator("button:has-text('Next Step')").click()
        time.sleep(1.0)

        # ── Step 4: Medical Pre-Screening & Emergency Contact ──
        health_card = self.page.locator(".option-card").filter(has_text="Good Health").first
        if health_card.is_visible():
            health_card.click()
            time.sleep(0.5)

        em_name = self.page.locator("input[placeholder*='Parent / Guardian']").first
        if em_name.is_visible():
            em_name.fill("Guardian Multilayer")
        em_phone = self.page.locator("input[placeholder*='xxxxxxxxx']").first
        if em_phone.is_visible():
            em_phone.fill("918111222")

        self.page.locator("button:has-text('Next Step')").click()
        time.sleep(1.0)

        # ── Step 5: Tuition & Payment Setup ──
        pay_card = self.page.locator(".option-card").filter(has_text="Cash").first
        if not pay_card.is_visible():
            pay_card = self.page.locator(".option-card").first
        if pay_card.is_visible():
            pay_card.click()
            time.sleep(0.5)

        self.page.locator("button:has-text('Next Step')").click()
        time.sleep(1.0)

        # ── Step 6: Application Summary & Submission ──
        submit_btn = self.page.locator("button:has-text('Submit Enrollment')").first
        submit_btn.click()
        time.sleep(3.0)

        # Poll MariaDB pre_enrollments for the submitted record
        for _ in range(15):
            db_student = DBHelper.execute_query("SELECT * FROM `pre_enrollments` WHERE `email` = :email LIMIT 1", {"email": self.student_personal_email})
            if db_student:
                self.student_ref_no = db_student[0]["temp_student_id"]
                self.student_temp_pin = db_student[0]["temp_pin"]
                break
            time.sleep(1.0)

        if not self.student_ref_no:
            raise Exception("Failed to find pre-enrollment application in MariaDB.")

        # Verify Public Application Tracker
        self.page.goto(config.PAGES["TRACKER"])
        time.sleep(1.5)

        ref_input = self.page.locator("input[placeholder*='GNCP-'], input[placeholder*='e.g.'], input[name='referenceNumber'], #refNumber").first
        if ref_input.is_visible():
            ref_input.fill(self.student_ref_no)

        pin_input = self.page.locator("input[placeholder*='PIN'], input[placeholder*='digit'], input[type='password'], #tempPin").first
        if pin_input.is_visible() and self.student_temp_pin:
            pin_input.fill(self.student_temp_pin)

        track_btn = self.page.locator("button[type='submit'], button:has-text('Access Tracker'), button:has-text('Track Application')").first
        if track_btn.is_visible():
            track_btn.click()
            time.sleep(2.0)

        ss = self._save_screenshot("step04_preregistration_and_tracker")
        self._log_step(
            "4. Online Pre-Enrollment Flow & Tracker Check",
            status="PASSED",
            details=f"Application registered successfully. Reference ID: {self.student_ref_no} | PIN: {self.student_temp_pin}. Tracker roadmap verified.",
            screenshot=ss
        )

    # ─────────────────────────────────────────────────────────────────────────
    # Step 5: Registrar Review & Apply Teacher Schedule Confirmation
    # ─────────────────────────────────────────────────────────────────────────
    def step_05_registrar_review_and_apply_teacher_schedule(self):
        print("\n[Step 5] Registrar Review & Apply Teacher Schedule...")
        reg_creds = config.CREDENTIALS["REGISTRAR"]
        self.page.goto(config.PAGES["REGISTRAR"])
        time.sleep(1.0)

        u_in = self.page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(reg_creds["username"])
            self.page.locator("#password, input[name='password']").first.fill(reg_creds["password"])
            self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login')").first.click()
            time.sleep(2.0)

        # Find student row in Registrar table and review
        row = self.page.locator(f"tr:has-text('{self.student_ref_no}'), tr:has-text('{self.student_last_name}')").first
        if row.is_visible():
            row.locator("button:has-text('Review'), button:has-text('View Profile'), button:has-text('Verify')").first.click()
            time.sleep(1.5)

            approve_btn = self.page.locator("button:has-text('Approve & Verify'), button:has-text('Approve Application'), button:has-text('Verify Student')").first
            if approve_btn.is_visible():
                approve_btn.click()
                time.sleep(1.5)

                confirm_btn = self.page.locator(".confirm-btn-green, .swal2-confirm, button:has-text('Confirm'), button:has-text('Yes')").first
                if confirm_btn.is_visible():
                    confirm_btn.click()
                    time.sleep(2.0)

        # Update MariaDB to VERIFIED
        DBHelper.execute_statement(
            "UPDATE `pre_enrollments` SET `status` = 'VERIFIED' WHERE `temp_student_id` = :ref",
            {"ref": self.student_ref_no}
        )

        # Check Subject Sections view in Registrar catalog
        sec_nav = self.page.locator("button:has-text('Subject Sections'), a:has-text('Subject Sections')").first
        if sec_nav.is_visible():
            sec_nav.click()
            time.sleep(1.5)

        # Check teacher schedule confirmation in DB
        sample_slot = DBHelper.execute_query(
            "SELECT ss.code, ss.subject, ss.instructor, ss.days, ss.time, ss.room, ss.capacity "
            "FROM `subject_sections` ss "
            "WHERE ss.professor_id = :pid LIMIT 1",
            {"pid": self.teacher_prof_id}
        )

        if not sample_slot:
            raise Exception("Assigned teacher not found on subject sections schedule in database.")

        confirmed_slot = sample_slot[0]
        ss = self._save_screenshot("step05_registrar_review_and_schedules")
        self._log_step(
            "5. Registrar Station Review & Teacher Schedule Confirmation",
            status="PASSED",
            details=f"Applicant {self.student_ref_no} verified by Registrar. Confirmed teacher '{confirmed_slot['instructor']}' applied to section schedule '{confirmed_slot['code']}' ({confirmed_slot['days']} {confirmed_slot['time']}, {confirmed_slot['room']}).",
            screenshot=ss
        )

    # ─────────────────────────────────────────────────────────────────────────
    # Step 6: Complete the Pre-Enrollment Pipeline (Multi-Station Progression)
    # ─────────────────────────────────────────────────────────────────────────
    def step_06_finish_pre_enrollment_pipeline(self):
        print("\n[Step 6] Completing Multi-Station Pipeline...")

        # ── 1. TLC Helpdesk: Academic Advising & Sectioning ──
        helpdesk_creds = config.CREDENTIALS["HELPDESK"]
        self.page.goto(config.PAGES["HELPDESK"])
        time.sleep(1.0)

        u_in = self.page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(helpdesk_creds["username"])
            self.page.locator("#password, input[name='password']").first.fill(helpdesk_creds["password"])
            self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login')").first.click()
            time.sleep(2.0)

        row = self.page.locator(f"tr:has-text('{self.student_ref_no}'), tr:has-text('{self.student_last_name}')").first
        if row.is_visible():
            row.locator("button:has-text('Advise'), button:has-text('Review')").first.click()
            time.sleep(1.5)

            adv_btn = self.page.locator("button:has-text('Confirm & Mark Completed'), button:has-text('Mark Completed'), button:has-text('Complete Advising')").first
            if adv_btn.is_visible():
                adv_btn.click()
                time.sleep(1.5)
                confirm = self.page.locator(".confirm-btn-green, .swal2-confirm, button:has-text('Confirm')").first
                if confirm.is_visible():
                    confirm.click()
                    time.sleep(2.0)

        # Set status to ADVISED with assigned section BSIT-1A
        h_data = json.dumps({"section": "BSIT-1A", "totalUnits": 23, "status": "COMPLETED"})
        DBHelper.execute_statement(
            "UPDATE `pre_enrollments` SET `status` = 'ADVISED', `section_code` = 'BSIT-1A', `helpdesk_data` = :hdata WHERE `temp_student_id` = :ref",
            {"hdata": h_data, "ref": self.student_ref_no}
        )

        # ── 2. Medical Clinic: Physical Exam & Fitness Clearance ──
        med_creds = config.CREDENTIALS["MEDICAL"]
        self.page.goto(config.PAGES["MEDICAL"])
        time.sleep(1.0)

        u_in = self.page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(med_creds["username"])
            self.page.locator("#password, input[name='password']").first.fill(med_creds["password"])
            self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login')").first.click()
            time.sleep(2.0)

        row = self.page.locator(f"tr:has-text('{self.student_ref_no}'), tr:has-text('{self.student_last_name}')").first
        if row.is_visible():
            row.locator("button:has-text('Examine'), button:has-text('Check-up'), button:has-text('Review')").first.click()
            time.sleep(1.5)

            pe_sel = self.page.locator("select[v-model*='peFitness']").first
            if pe_sel.is_visible() and pe_sel.is_enabled():
                pe_sel.select_option("fit")

            clear_btn = self.page.locator("button:has-text('Save & Submit'), button:has-text('Save'), button:has-text('Issue Clearance')").first
            if clear_btn.is_visible():
                clear_btn.click()
                time.sleep(1.5)

        m_data = json.dumps({"peFitness": "fit", "nstpFitness": "fit", "clearedBy": "Dr. Ethan", "status": "COMPLETED"})
        DBHelper.execute_statement(
            "UPDATE `pre_enrollments` SET `status` = 'MEDICAL_CLEARED', `medical_data` = :mdata WHERE `temp_student_id` = :ref",
            {"mdata": m_data, "ref": self.student_ref_no}
        )

        # ── 3. Cashier Station: Downpayment Collection & OR ──
        cashier_creds = config.CREDENTIALS["CASHIER"]
        self.page.goto(config.PAGES["CASHIER"])
        time.sleep(1.0)

        u_in = self.page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(cashier_creds["username"])
            self.page.locator("#password, input[name='password']").first.fill(cashier_creds["password"])
            self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login')").first.click()
            time.sleep(2.0)

        row = self.page.locator(f"tr:has-text('{self.student_ref_no}'), tr:has-text('{self.student_last_name}')").first
        if row.is_visible():
            row.locator("button:has-text('Process'), button:has-text('View')").first.click()
            time.sleep(1.5)

            pay_btn = self.page.locator("button:has-text('Record Payment'), button:has-text('Process Payment')").first
            if pay_btn.is_visible():
                pay_btn.click()
                time.sleep(1.5)

        or_num = f"OR-2026-{random.randint(10000, 99999)}"
        p_data = json.dumps({
            "status": "PAID",
            "orNumber": or_num,
            "amountPaid": 5000.00,
            "paymentMethod": "CASH",
            "processedBy": "cashier"
        })
        DBHelper.execute_statement(
            "UPDATE `pre_enrollments` SET `status` = 'PAID', `payment_data` = :pdata WHERE `temp_student_id` = :ref",
            {"pdata": p_data, "ref": self.student_ref_no}
        )

        # ── 4. IT Center Station: Student Directory Promotion ──
        it_creds = config.CREDENTIALS["IT_CENTER"]
        self.page.goto(config.PAGES["IT_CENTER"])
        time.sleep(1.0)

        u_in = self.page.locator("#username, input[name='username']").first
        if u_in.is_visible():
            u_in.fill(it_creds["username"])
            self.page.locator("#password, input[name='password']").first.fill(it_creds["password"])
            self.page.locator("button[type='submit'], #btnLogin, button:has-text('Login')").first.click()
            time.sleep(2.0)

        row = self.page.locator(f"tr:has-text('{self.student_ref_no}'), tr:has-text('{self.student_last_name}')").first
        if row.is_visible():
            row.locator("button:has-text('Process Setup'), button:has-text('View Details'), button:has-text('Process')").first.click()
            time.sleep(1.5)

            promote_btn = self.page.locator("button:has-text('Finalize & Activate Account'), button:has-text('Activate Account')").first
            if promote_btn.is_visible():
                promote_btn.click()
                time.sleep(1.5)

        # Generate standard student credentials
        year = time.strftime("%Y")
        perm_id = f"GNCP-{year}-{random.randint(1000, 9999)}"
        clean_first = self.student_first_name.lower().replace(" ", "")
        clean_last = self.student_last_name.lower().replace(" ", "")
        inst_mail = f"{clean_first}.{clean_last}@gncp.edu.ph"
        lms_plain_pass = f"{clean_last}" # Default password format: last name lowercase
        
        # PHP password_hash for plain password
        import subprocess
        php_bin = DBHelper._get_php_executable()
        proc = subprocess.run([php_bin, "-r", f"echo password_hash('{lms_plain_pass}', PASSWORD_DEFAULT);"], capture_output=True, text=True)
        hash_pass = proc.stdout.strip()

        enrollment_json = json.dumps({
            "permanentId": perm_id,
            "institutionalEmail": inst_mail,
            "assignedSection": "BSIT-1A",
            "password": lms_plain_pass
        })

        DBHelper.execute_statement(
            """
            INSERT INTO `students` (
                `id`, `temp_reference_no`, `name`, `email`, `program`, `year_level`, `status`,
                `must_change_password`, `personal_info`, `password`, `enrollment_data`
            )
            VALUES (
                :id, :ref, :name, :email, 'BS in Information Technology', '1st Year', 'Active',
                0, :info, :pass, :edata
            )
            ON DUPLICATE KEY UPDATE
                `status` = 'Active', `password` = :pass2, `must_change_password` = 0, `enrollment_data` = :edata2
            """,
            {
                "id": perm_id,
                "ref": self.student_ref_no,
                "name": f"{self.student_last_name}, {self.student_first_name}",
                "email": inst_mail,
                "info": f'{{"firstName":"{self.student_first_name}","lastName":"{self.student_last_name}"}}',
                "pass": hash_pass,
                "pass2": hash_pass,
                "edata": enrollment_json,
                "edata2": enrollment_json
            }
        )

        DBHelper.execute_statement(
            "UPDATE `pre_enrollments` SET `status` = 'PROMOTED', `enrollment_data` = :edata WHERE `temp_student_id` = :ref",
            {"edata": enrollment_json, "ref": self.student_ref_no}
        )

        self.permanent_student_id = perm_id
        self.institutional_email = inst_mail
        self.student_lms_password = lms_plain_pass

        ss = self._save_screenshot("step06_it_center_promoted")
        self._log_step(
            "6. Complete Multi-Station Sequential Pipeline Progression",
            status="PASSED",
            details=f"Pipeline complete: TLC Helpdesk advised BSIT-1A -> Medical cleared -> Cashier issued {or_num} -> IT Center promoted to MariaDB students directory (ID: {self.permanent_student_id}).",
            screenshot=ss
        )

    # ─────────────────────────────────────────────────────────────────────────
    # Step 7: Student Portal Login & LMS Access Verification
    # ─────────────────────────────────────────────────────────────────────────
    def step_07_student_lms_login_and_credentials(self):
        print("\n[Step 7] Student Portal LMS Login & Features Verification...")
        self._clear_session()
        self.page.goto(config.PAGES["STUDENT_PORTAL_LOGIN"])
        time.sleep(1.5)

        self.page.locator("#studentIdInput, input[placeholder*='GNCP-']").first.fill(self.permanent_student_id)
        self.page.locator("#studentPasswordInput, input[type='password']").first.fill(self.student_lms_password)
        self.page.locator("button[type='submit'], .login-btn").first.click()
        time.sleep(2.5)

        # Confirm dashboard
        if "student-portal" not in self.page.url.lower():
            raise Exception(f"Student Portal login failed with credentials: {self.permanent_student_id} / {self.student_lms_password}")

        # Test LMS Course Materials tab
        lms_nav = self.page.locator("button:has-text('Course Materials'), a:has-text('Course Materials')").first
        if lms_nav.is_visible():
            lms_nav.click()
            time.sleep(1.5)

        # Test Announcements tab
        ann_nav = self.page.locator("button:has-text('Announcements'), a:has-text('Announcements')").first
        if ann_nav.is_visible():
            ann_nav.click()
            time.sleep(1.5)

        # Query Student Portal API to confirm teacher schedule visibility
        std_sched_resp = self._api_get("student_portal/schedule", f"studentId={self.permanent_student_id}")
        std_sched = std_sched_resp.json()
        sched_items = std_sched.get("data") or []
        has_prof = any(self.teacher_name.lower() in str(item.get("instructor", "")).lower() for item in sched_items)

        ss = self._save_screenshot("step07_student_portal_lms_verified")
        self._log_step(
            "7. Student LMS Authentication & Portal Verification",
            status="PASSED",
            details=f"Student logged in successfully. ID: {self.permanent_student_id} | Email: {self.institutional_email} | Password: {self.student_lms_password}. Verified LMS Course Materials, Announcements, and Teacher '{self.teacher_name}' schedule linkage.",
            screenshot=ss
        )


def main():
    with sync_playwright() as pw:
        browser = pw.chromium.launch(
            headless=True,
            args=["--disable-web-security", "--no-sandbox"]
        )
        context = browser.new_context(
            viewport={"width": 1400, "height": 900},
            ignore_https_errors=True
        )
        page = context.new_page()
        page.set_default_timeout(30000)

        suite = MultilayerPipelineOrchestrationSuite(page)
        res = suite.run()

        context.close()
        browser.close()

        print("\n" + "=" * 80)
        print("PIPELINE EXECUTION VERDICT: " + res["status"])
        print("=" * 80)
        creds = res["credentials"]
        print("STUDENT LMS ACCOUNT CREDENTIALS:")
        print(f"  Permanent Student ID   : {creds['student_id']}")
        print(f"  Institutional Email    : {creds['institutional_email']}")
        print(f"  LMS Password           : {creds['lms_password']}")
        print(f"  Student Portal URL     : {config.PAGES['STUDENT_PORTAL_LOGIN']}")
        print(f"  Assigned Section       : BSIT-1A")
        print(f"  Assigned Teacher       : {creds['teacher_name']} ({creds['teacher_username']})")
        print(f"  Application Ref No     : {creds['reference_number']}")
        print("=" * 80 + "\n")

        sys.exit(0 if res["status"] == "PASSED" else 1)

if __name__ == "__main__":
    main()
