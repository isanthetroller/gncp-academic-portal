import os
import time
import json
import requests
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def test_cashier_receipt():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()

        # 1. Login to Employee Portal Gateway via main index.html
        print("Logging in to Employee Portal Gateway...")
        page.goto(f"{BASE_URL}/index.html")
        page.wait_for_load_state("networkidle")
        time.sleep(1)

        page.fill("#username", "cashier")
        page.fill("#password", "password123")
        page.click("button[type='submit']")
        page.wait_for_load_state("networkidle")
        time.sleep(2)

        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        page.wait_for_load_state("networkidle")
        time.sleep(2)

        print(f"Current URL after login: {page.url}")

        # 2. Pick student from Cashier queue
        debug_info = page.evaluate("""() => {
            if (!window.app) return { error: 'No window.app' };
            const q = window.app.students || [];
            if (q.length === 0) return { error: 'Empty students array' };
            const target = q.find(s => s.status === 'PAID' || s.status === 'MEDICAL_CLEARED' || s.status === 'ADVISED') || q[0];
            window.app.openProcess(target);
            return {
                studentsCount: q.length,
                selectedRef: target.referenceNumber,
                selectedStatus: target.status,
                balance: target.payment ? target.payment.balance : null
            };
        }""")
        print("Selected student debug info:", debug_info)
        time.sleep(1.5)

        # 3. Trigger Print Official Receipt / Mark Enrolled & Print
        print("Triggering Official Receipt Generation (In-Tab)...")
        print_result = page.evaluate("""async () => {
            if (!window.app || !window.app.selectedStudent) return { error: 'No selected student' };
            const s = window.app.selectedStudent;
            if (s.status !== 'PAID') {
                // Settle payment first
                window.app.payAmountInput = s.payment.balance || 5000;
                window.app.cashTendered = window.app.payAmountInput;
                await window.app.recordPayment();
            } else {
                await window.app.markEnrolledAndPrint(s);
            }
            return {
                receiptDataSet: !!window.app.receiptData,
                receiptDataVal: window.app.receiptData
            };
        }""")
        print("Print/Record Result:", print_result)
        time.sleep(1.5)

        # Dismiss SweetAlert if visible
        swal_confirm = page.locator(".swal2-confirm")
        if swal_confirm.count() > 0 and swal_confirm.is_visible():
            swal_confirm.click()
            time.sleep(1)

        # 4. Verify in-tab receipt overlay is displayed and NO new browser tabs were opened
        num_tabs = len(context.pages)
        print(f"Number of open browser tabs: {num_tabs}")
        assert num_tabs == 1, f"Expected 1 tab, but found {num_tabs} tabs opened!"

        receipt_visible = page.evaluate("""() => {
            const card = document.querySelector('#printable-receipt-card');
            return card && card.offsetParent !== null;
        }""")
        print(f"In-tab receipt card visible: {receipt_visible}")
        assert receipt_visible, "In-tab receipt card was not visible!"

        # 5. Capture screenshot of the in-tab receipt overlay
        receipt_screenshot = os.path.join(ARTIFACT_DIR, "cashier_in_tab_official_receipt.png")
        page.screenshot(path=receipt_screenshot, full_page=False)
        print(f"Saved in-tab official receipt screenshot: {receipt_screenshot}")

        browser.close()
        print("Cashier In-Tab Official Receipt test passed with 100% success!")

if __name__ == "__main__":
    test_cashier_receipt()
