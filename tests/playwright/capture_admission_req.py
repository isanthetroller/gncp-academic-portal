import sys
import time
import os
from playwright.sync_api import sync_playwright

def capture():
    playwright = sync_playwright().start()
    browser = playwright.chromium.launch(
        headless=True,
        args=["--no-sandbox", "--disable-dev-shm-usage"]
    )
    context = browser.new_context(viewport={"width": 1440, "height": 900})
    page = context.new_page()

    # 1. Login to Registrar
    page.goto("http://127.0.0.1/systemtest/index.html?clear=true")
    page.fill("#username", "kriz")
    page.fill("#password", "kriz123")
    page.click("button[type='submit']")
    page.wait_for_url("**/registrar/index.html", timeout=15000)
    time.sleep(3.0)

    # 2. Open GNCP-TEST-DOC01 review modal
    page.evaluate("""
        () => {
            const list = (window.app && window.app.pendingApplications) ? window.app.pendingApplications : [];
            const docStudent = list.find(s => s.referenceNumber === 'GNCP-TEST-DOC01') || list[0];
            if (docStudent) {
                window.app.openApplicationModal(docStudent);
            }
        }
    """)
    time.sleep(2.0)
    
    # Capture requirements element specifically
    req_box = page.locator(".req-container")
    if req_box.count() > 0:
        req_box.screenshot(path="tests/playwright/screenshots/updated_admission_requirements_card.png")
        print("Captured updated_admission_requirements_card.png")
        
    modal = page.locator("#applicationModal .modal-content")
    if modal.count() > 0:
        modal.screenshot(path="tests/playwright/screenshots/updated_registrar_modal.png")
        print("Captured updated_registrar_modal.png")
    else:
        print("Modal element not found.")

    browser.close()
    playwright.stop()

if __name__ == "__main__":
    capture()
