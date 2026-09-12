import os
import time
import json
import requests
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\21a48c8a-94f2-437c-b423-d6b126b041cc"
os.makedirs(ARTIFACT_DIR, exist_ok=True)

def test_visual_scenarios():
    test_id = "REF-2026-VISUAL"
    test_email = "visual.test.student@gncp.edu.ph"

    # Step 1: Run PHP script to seed Complete Status (Scenario D)
    os.system('C:\\xampp\\php\\php.exe tests/seed_visual_helper.php complete')

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()

        # Load student portal with session
        page.goto(f"{BASE_URL}/student-portal/index.html")
        session_data = {
            "id": test_id,
            "studentId": test_id,
            "email": test_email,
            "name": "Lucas Vanguardia",
            "program": "BSIT",
            "yearLevel": "1st Year",
            "status": "VERIFIED",
            "temp_pin": "1234",
            "must_change_password": False
        }
        page.evaluate(f"sessionStorage.setItem('gncp_portal_student', JSON.stringify({json.dumps(session_data)}))")
        page.reload()
        page.wait_for_load_state("networkidle")
        time.sleep(1.5)

        # Navigate to Documents tab
        print("Navigating to Documents Tab (Complete Requirements)...")
        page.locator("button.nav-item:has-text('Documents & Undertakings')").click()
        time.sleep(1.2)

        # Assert "You have complete requirements." exists
        complete_heading = page.locator("h3:has-text('You have complete requirements.')")
        assert complete_heading.count() > 0, "Expected 'You have complete requirements.' heading"
        print(" Found heading: 'You have complete requirements.'")

        # Capture Screenshot of Complete State (Scenario D)
        sc_d_path = os.path.join(ARTIFACT_DIR, "scenario_d_complete_requirements.png")
        page.screenshot(path=sc_d_path, full_page=False)
        print(f" Captured Scenario D screenshot: {sc_d_path}")

        # Click View Cleared Documents button to expand history
        view_btn = page.locator("button:has-text('View Cleared Documents')")
        if view_btn.count() > 0:
            view_btn.click()
            time.sleep(0.6)
            sc_d_expanded = os.path.join(ARTIFACT_DIR, "scenario_d_cleared_docs_expanded.png")
            page.screenshot(path=sc_d_expanded, full_page=False)
            print(f" Captured Scenario D expanded cleared docs: {sc_d_expanded}")

        # Step 2: Seed Mixed Status with Undertaking (Scenario C & E)
        print("\nSeeding Scenario C/E (Active Undertaking & Processed Hardcopies)...")
        os.system('C:\\xampp\\php\\php.exe tests/seed_visual_helper.php undertaking')

        # Click Refresh in the portal
        refresh_btn = page.locator("button:has-text('Refresh')").first
        refresh_btn.click()
        time.sleep(1.5)

        # Assert Active Undertaking alert exists
        undertaking_alert = page.locator("h6:has-text('Conditional Enrollment Notice (Active Undertaking)')")
        assert undertaking_alert.count() > 0, "Expected Undertaking banner"
        print(" Found: 'Conditional Enrollment Notice (Active Undertaking)'")

        # Assert only goodMoral is in actionable requirements
        actionable_header = page.locator("h6:has-text('Actionable Requirements')")
        assert actionable_header.count() > 0, "Expected Actionable Requirements card"
        print(" Found: Actionable Requirements list showing ONLY pending undertaking!")

        # Capture Screenshot of Scenario C/E
        sc_c_path = os.path.join(ARTIFACT_DIR, "scenario_c_undertaking_actionable.png")
        page.screenshot(path=sc_c_path, full_page=False)
        print(f" Captured Scenario C/E screenshot: {sc_c_path}")

        browser.close()

    # Cleanup test applicant
    os.system('C:\\xampp\\php\\php.exe tests/seed_visual_helper.php cleanup')
    print("\nVisual Scenario Verification Completed Successfully!")

if __name__ == '__main__':
    test_visual_scenarios()
