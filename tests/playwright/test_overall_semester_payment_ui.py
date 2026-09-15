import os
import sys
import json
import pymysql
from playwright.sync_api import sync_playwright

if sys.stdout.encoding != 'utf-8':
    sys.stdout.reconfigure(encoding='utf-8')

from config import BASE_URL, PAGES, DB_CONFIG, SCREENSHOTS_DIR

def run_test():
    print("=====================================================================")
    print(" [*] VERIFYING STUDENT FORM OVERALL SEMESTER PAYMENT UI")
    print("=====================================================================")
    
    os.makedirs(SCREENSHOTS_DIR, exist_ok=True)
    
    # 1. Fetch a test student from DB for tracker testing
    conn = pymysql.connect(
        host=DB_CONFIG["host"],
        user=DB_CONFIG["user"],
        password=DB_CONFIG["password"],
        database=DB_CONFIG["database"],
        port=DB_CONFIG["port"],
        cursorclass=pymysql.cursors.DictCursor
    )
    with conn.cursor() as cur:
        cur.execute("SELECT temp_student_id, temp_pin, course_code, status FROM pre_enrollments ORDER BY id DESC LIMIT 1")
        test_student = cur.fetchone()
        
        # Also prepare an active student for the student portal test
        hashed = "$2y$10$jY/6zKVMjn/5Yth.bt0IcuNsvHh8iRbh0JX2RHTcGRldHw2lAa8wi"
        cur.execute("""
            UPDATE students 
            SET password = %s, must_change_password = 0 
            WHERE id = 'GNCP-2026-12833'
        """, (hashed,))
        conn.commit()
    conn.close()
    
    console_errors = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1280, "height": 900})
        page = context.new_page()

        def on_console(msg):
            if msg.type == "error":
                console_errors.append(msg.text)
                print(f"   [!] Console Error: {msg.text}")

        page.on("console", on_console)

        # -------------------------------------------------------------
        # PART 1: Pre-Enrollment Registration Form (Step 5 Verification)
        # -------------------------------------------------------------
        print("\n--- 1. Testing Registration Form Step 5 (Fee Assessment) ---")
        page.goto(PAGES["REGISTRATION"])
        page.wait_for_load_state("networkidle")

        page.wait_for_function("() => window.enrollmentVm !== undefined")
        page.evaluate("""() => {
            const vm = window.enrollmentVm;
            vm.selectedCollege = 'COIT';
            vm.form.studentType = 'FRESHMAN';
            vm.form.courseCode  = 'BSIT';
            vm.currentStep = 5;
        }""")
        page.wait_for_timeout(1000)

        # Check if Step 5 is visible
        body_text = page.locator("body").inner_text()
        assert "OVERALL SEMESTER TUITION & FEES" in body_text, "Expected 'OVERALL SEMESTER TUITION & FEES' in Step 5"
        assert "18,300" in body_text, "Expected '18,300' in Step 5"
        
        # Verify 18,300 appears in the rendered DOM
        invoice_total = page.locator(".invoice-container").locator("text=18,300").first
        assert invoice_total.is_visible(), "Expected 18,300.00 to be rendered in registration fee breakdown"
        print("   [PASS] Found 18,300.00 overall semester tuition and fees in Step 5!")

        # Screenshot Step 5
        reg_ss = os.path.join(SCREENSHOTS_DIR, "registration_step5_overall_semester_fee.png")
        page.screenshot(path=reg_ss, full_page=True)
        print(f"   [OK] Saved screenshot to: {reg_ss}")

        # -------------------------------------------------------------
        # PART 2: Application Tracker Form (Fee Assessment Card)
        # -------------------------------------------------------------
        if test_student:
            print("\n--- 2. Testing Application Tracker Fee Assessment Card ---")
            page.goto(PAGES["TRACKER"])
            page.wait_for_load_state("networkidle")

            page.wait_for_function("() => window.trackerVm !== undefined")
            page.evaluate("""([id, pin]) => {
                window.trackerVm.loginForm.tempStudentId = id;
                window.trackerVm.loginForm.tempPin = pin;
                window.trackerVm.handleLogin();
            }""", [test_student["temp_student_id"], test_student["temp_pin"]])
            page.wait_for_timeout(2500)

            # Check that Semester Tuition card and Overall Semester Assessment are visible
            tuition_card = page.locator("text=Semester Tuition").first
            assert tuition_card.is_visible(), "Expected 'Semester Tuition & Fee Assessment' card in tracker"
            
            assessment_label = page.locator("text=Overall Semester Assessment").first
            assert assessment_label.is_visible(), "Expected 'Overall Semester Assessment' label in tracker"
            
            total_fee = page.locator("text=18,300").first
            assert total_fee.is_visible(), "Expected 18,300 to be displayed in tracker fee card"
            print("   [PASS] Found 'Semester Tuition & Fee Assessment' card with 'Overall Semester Assessment: 18,300' in tracker!")

            tracker_ss = os.path.join(SCREENSHOTS_DIR, "tracker_overall_semester_assessment.png")
            page.screenshot(path=tracker_ss, full_page=True)
            print(f"   [OK] Saved screenshot to: {tracker_ss}")

        # -------------------------------------------------------------
        # PART 3: Student Portal (Overall Semester Fee Hub Card & COR)
        # -------------------------------------------------------------
        print("\n--- 3. Testing Student Portal Dashboard Fees ---")
        page.goto(PAGES["STUDENT_PORTAL_LOGIN"])
        page.wait_for_load_state("networkidle")

        page.wait_for_selector("#studentIdInput, input[type='text']", timeout=10000)
        page.fill("#studentIdInput, input[type='text']", "GNCP-2026-12833")
        page.fill("#studentPasswordInput, input[type='password']", "TestPass123!")
        page.click("button[type='submit']")
        page.wait_for_timeout(2500)

        # Confirm on dashboard
        portal_text = page.locator("body").inner_text()
        has_fee_card = page.locator("text=Overall Semester Fee").first.is_visible()
        has_cor_fee = page.locator("text=Overall Semester Assessment").first.is_visible()
        
        assert has_fee_card or has_cor_fee, "Expected Overall Semester Fee or Overall Semester Assessment in student portal"
        print("   [PASS] Found 'Overall Semester Fee' / 'Overall Semester Assessment' in Student Portal dashboard!")
        
        portal_ss = os.path.join(SCREENSHOTS_DIR, "student_portal_overall_semester_fee.png")
        page.screenshot(path=portal_ss, full_page=True)
        print(f"   [OK] Saved screenshot to: {portal_ss}")

        browser.close()

    print("\n--- 4. Console Error Verification ---")
    critical_errors = [e for e in console_errors if "favicon" not in e.lower()]
    if critical_errors:
        print(f"   [!] Encountered {len(critical_errors)} console errors:")
        for err in critical_errors:
            print(f"      - {err}")
    else:
        print("   [PASS] 0 Console errors detected across all tested interfaces!")

    print("\n=====================================================================")
    print(" [SUCCESS] ALL OVERALL SEMESTER PAYMENT UI ASSERTIONS COMPLETED")
    print("=====================================================================")

if __name__ == "__main__":
    run_test()
