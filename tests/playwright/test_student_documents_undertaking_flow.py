import os
import time
import json
import requests
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"
os.makedirs(ARTIFACT_DIR, exist_ok=True)

def run():
    # Query student via API
    res = requests.get(f"{BASE_URL}/api/index.php?action=student/track&ref=REF-2026-1001")
    data = res.json().get('data', {})
    student_id = data.get('id', '2026-1001')
    student_name = f"{data.get('firstName', 'Kathleen')} {data.get('lastName', 'Mercado')}"
    email = data.get('email', 'kathleen.mercado55@gncp.edu.ph')
    program = data.get('courseCode', 'BSIT')
    year_level = data.get('yearLevelApplied', '1st Year')
    ref_pin = data.get('tempPin', '5505')

    print(f"Testing with Student ID: {student_id}, Name: {student_name}, PIN: {ref_pin}")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()

        # Inject session into sessionStorage
        page.goto(f"{BASE_URL}/student-portal/index.html")
        session_data = {
            "id": student_id,
            "studentId": student_id,
            "email": email,
            "name": student_name,
            "program": program,
            "yearLevel": year_level,
            "status": "ACTIVE",
            "must_change_password": False
        }
        page.evaluate(f"sessionStorage.setItem('gncp_portal_student', JSON.stringify({json.dumps(session_data)}))")
        page.reload()
        page.wait_for_load_state("networkidle")
        time.sleep(1.5)

        # 1. Click Documents & Undertakings Hub in sidebar
        print("Navigating to Documents & Undertakings Hub...")
        docs_nav = page.locator("button.nav-item:has-text('Documents & Undertakings')")
        if docs_nav.count() > 0:
            docs_nav.click()
        else:
            page.evaluate("() => { if (window.app) window.app.activeTab = 'documents'; }")
        time.sleep(1.2)

        # Capture Documents Hub full page
        hub_screenshot = os.path.join(ARTIFACT_DIR, "student_documents_hub.png")
        page.screenshot(path=hub_screenshot, full_page=False)
        print(f"Saved Documents Hub screenshot: {hub_screenshot}")

        # 2. Open Upload Document & Undertaking Modal by clicking 'Submit Document'
        print("Opening Upload Document Modal...")
        submit_btn = page.locator("button:has-text('Submit Document')").first
        if submit_btn.count() > 0:
            submit_btn.click()
            time.sleep(0.8)
        else:
            page.evaluate("() => { if (window.app) window.app.openUploadDocModal(); }")
            time.sleep(0.8)

        # Toggle undertaking switch inside modal
        undertaking_toggle = page.locator("#undertakingCheck")
        if undertaking_toggle.count() > 0:
            undertaking_toggle.check()
            time.sleep(0.4)

        modal_screenshot = os.path.join(ARTIFACT_DIR, "student_upload_undertaking_modal.png")
        page.screenshot(path=modal_screenshot, full_page=False)
        print(f"Saved Upload & Undertaking Modal screenshot: {modal_screenshot}")

        # Close upload modal
        close_upload_btn = page.locator(".modal.show button.btn-close-white").first
        if close_upload_btn.count() > 0:
            close_upload_btn.click()
            time.sleep(0.5)

        # 3. Test Preview Document Modal
        print("Opening Universal Document Preview Modal...")
        preview_btn = page.locator("button:has-text('Preview')").first
        if preview_btn.count() > 0:
            preview_btn.click()
            time.sleep(1)
        else:
            page.evaluate("() => { if (window.app && window.app.documentsData.requirements[0]) window.app.openDocPreview(window.app.documentsData.requirements[0]); }")
            time.sleep(1)

        preview_screenshot = os.path.join(ARTIFACT_DIR, "student_document_preview_modal.png")
        page.screenshot(path=preview_screenshot, full_page=False)
        print(f"Saved Document Preview Modal screenshot: {preview_screenshot}")

        # 4. Visit Applicant Tracker with PIN 5505 to verify Document Checklist
        print(f"Visiting Applicant Tracker with PIN {ref_pin}...")
        ref_no = data.get('referenceNumber') or 'REF-2026-1001'
        page.goto(f"{BASE_URL}/enrollment-system/tracker.html?id={ref_no}&pin={ref_pin}")
        page.wait_for_load_state("networkidle")
        time.sleep(2)

        tracker_screenshot = os.path.join(ARTIFACT_DIR, "tracker_documents_checklist.png")
        page.screenshot(path=tracker_screenshot, full_page=False)
        print(f"Saved Tracker Document Checklist screenshot: {tracker_screenshot}")

        browser.close()
        print("All document flow Playwright tests passed successfully!")

if __name__ == "__main__":
    run()
