import time
import os
import sys
import json
import urllib.request
import urllib.parse
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

from config import BASE_URL, PAGES, CREDENTIALS, SCREENSHOTS_DIR

def run_test():
    print("[TEST] Starting Academic Milestones & Student Feed verification...")
    chrome_options = Options()
    chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument("--window-size=1400,1000")

    driver = webdriver.Chrome(options=chrome_options)
    wait = WebDriverWait(driver, 10)

    try:
        # Step 1: Query API directly to verify milestones endpoint
        print("\n--- 1. API Verification ---")
        api_url = f"{BASE_URL}/api/index.php?action=milestones/list"
        req = urllib.request.Request(api_url)
        with urllib.request.urlopen(req) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            assert data.get('success') is True, "API milestones/list failed"
            milestones = data.get('data', [])
            print(f"[SUCCESS] API returned {len(milestones)} milestones from database.")
            for m in milestones:
                print(f"  - #{m['display_order']} {m['title']} ({m['status']}): {m['date_display']}")

        # Step 2: Test Student Portal login and view Campus Feed
        print("\n--- 2. Student Portal Feed Inspection ---")
        driver.get(PAGES["STUDENT_PORTAL_LOGIN"])
        time.sleep(1)

        # Log in with existing active student or test student
        # Let's inspect active student in db or login via standard student test account
        email_input = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='text'], input[type='email']")))
        pass_input = driver.find_element(By.CSS_SELECTOR, "input[type='password']")
        submit_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit']")

        # We can also set sessionStorage directly if student credentials needed
        email_input.clear()
        email_input.send_keys("test.student.2026.0001@gncp.edu.ph")
        pass_input.clear()
        pass_input.send_keys("StudentPass2026!")
        submit_btn.click()
        time.sleep(2)

        # Direct session bootstrap for student portal visual test
        print("[INFO] Direct session bootstrap for student portal visual test...")
        driver.get(PAGES["STUDENT_PORTAL"])
        driver.execute_script("""
            const student = {
                id: 1,
                student_id: 'GNCP-2026-0001',
                name: 'Kriz Tristan Ethan',
                email: 'kriz.tristan@gncp.edu.ph',
                program: 'BSIT',
                year_level: '1st Year',
                status: 'ACTIVE'
            };
            sessionStorage.setItem('gncp_portal_student', JSON.stringify(student));
            localStorage.setItem('gncp_portal_student', JSON.stringify(student));
        """)
        driver.get(PAGES["STUDENT_PORTAL"])
        time.sleep(2)

        # Switch to announcements tab
        driver.execute_script("""
            if (window.app) {
                window.app.activeTab = 'announcements';
            }
        """)
        time.sleep(1.5)

        # Assert removed elements are NOT present in DOM
        page_source = driver.page_source

        print("\n--- 3. Assert Removed Elements ---")
        assert "Live Campus Desk" not in page_source, "FAILED: 'Live Campus Desk' is still present!"
        print("[PASSED] 'Live Campus Desk' badge removed.")

        assert "Campus Emergency Hotlines" not in page_source, "FAILED: 'Campus Emergency Hotlines' is still present!"
        print("[PASSED] 'Campus Emergency Hotlines' widget removed.")

        assert "Campus Office Directory" not in page_source, "FAILED: 'Campus Office Directory' is still present!"
        print("[PASSED] 'Campus Office Directory' widget removed.")

        assert "Student Resources &amp; Downloads" not in page_source and "Student Resources & Downloads" not in page_source, "FAILED: 'Student Resources & Downloads' is still present!"
        print("[PASSED] 'Student Resources & Downloads' widget removed.")

        # Assert Academic Milestones IS present
        assert "Academic Milestones" in page_source, "FAILED: 'Academic Milestones' section missing!"
        print("[PASSED] 'Academic Milestones' card is present and rendered.")

        # Take screenshot of Student Portal Campus Feed
        screenshot_path = os.path.join(r"C:\Users\ethan\.gemini\antigravity-ide\brain\4464001e-217e-417b-9221-861b7c89d6e8", "student_campus_feed_verified.png")
        driver.save_screenshot(screenshot_path)
        print(f"[SUCCESS] Saved screenshot to {screenshot_path}")

        # Log into Admin Portal
        print("\n--- 4. Admin Portal Milestone Management ---")
        driver.get(f"{BASE_URL}/shared/login.html")
        time.sleep(1)
        # Login as Admin
        driver.execute_async_script("""
            const done = arguments[0];
            fetch('../api/index.php?action=auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: 'admin', password: 'admin12345' })
            }).then(r => r.json()).then(res => {
                sessionStorage.setItem('gncp_admin_user', JSON.stringify(res.data));
                done(res);
            }).catch(err => done({ success: false, error: err.toString() }));
        """)
        
        driver.get(PAGES["ADMIN"])
        time.sleep(2)

        # Switch to periods view
        driver.execute_script("if (window.app) window.app.setView('periods');")
        time.sleep(1)

        admin_source = driver.page_source
        assert "Academic Milestones &amp; Calendar Deadlines" in admin_source or "Academic Milestones & Calendar Deadlines" in admin_source, "FAILED: Admin milestones table not found!"
        print("[PASSED] Admin portal Academic Milestones table is visible.")

        # Test adding a milestone via Admin Browser session
        print("\n--- 5. Test Admin Save Milestone Mutation ---")
        save_res = driver.execute_async_script("""
            const done = arguments[0];
            fetch('backend/api.php?action=save_milestone', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    milestone: {
                        id: null,
                        academic_period_id: 1,
                        title: 'Comprehensive Exit Examination',
                        date_start: '2026-11-20',
                        date_end: '2026-11-22',
                        date_display: 'Nov 20 – 22, 2026',
                        status: 'UPCOMING',
                        display_order: 5
                    }
                })
            }).then(r => r.json()).then(res => done(res)).catch(err => done({ success: false, error: err.toString() }));
        """)

        assert save_res.get('success') is True, f"Failed to save milestone: {save_res}"
        print("[PASSED] New milestone 'Comprehensive Exit Examination' created successfully.")

        # Check API directly to ensure DB persistence
        req = urllib.request.Request(api_url)
        with urllib.request.urlopen(req) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            titles = [m['title'] for m in data.get('data', [])]
            assert "Comprehensive Exit Examination" in titles, "Newly created milestone not found in list!"
            print(f"[PASSED] Milestone list verified in MariaDB: {titles}")

        # Step 6: Verify Student Portal dynamically renders the new milestone
        print("\n--- 6. Student Portal Live Sync Verification ---")
        driver.get(PAGES["STUDENT_PORTAL"])
        driver.execute_script("""
            const student = {
                id: 1,
                student_id: 'GNCP-2026-0001',
                name: 'Kriz Tristan Ethan',
                email: 'kriz.tristan@gncp.edu.ph',
                program: 'BSIT',
                year_level: '1st Year',
                status: 'ACTIVE'
            };
            sessionStorage.setItem('gncp_portal_student', JSON.stringify(student));
            localStorage.setItem('gncp_portal_student', JSON.stringify(student));
            if (window.app) window.app.activeTab = 'announcements';
        """)
        time.sleep(2)
        driver.execute_script("if (window.app) window.app.activeTab = 'announcements';")
        time.sleep(1)

        student_source = driver.page_source
        assert "Comprehensive Exit Examination" in student_source, "FAILED: Newly created milestone not rendered in Student Portal!"
        print("[PASSED] Student Portal dynamically rendered the new milestone in real-time.")

        # Take final verified screenshot
        screenshot_path = os.path.join(r"C:\Users\ethan\.gemini\antigravity-ide\brain\4464001e-217e-417b-9221-861b7c89d6e8", "student_campus_feed_verified.png")
        driver.save_screenshot(screenshot_path)
        print(f"[SUCCESS] Saved final screenshot to {screenshot_path}")

        print("\n[COMPLETE] All Academic Milestones and Student Feed tests passed with 100% success!")

    finally:
        driver.quit()

if __name__ == '__main__':
    run_test()
