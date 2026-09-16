from playwright.sync_api import sync_playwright
import time
import os
import requests

def capture_cashier():
    artifact_dir = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"
    os.makedirs(artifact_dir, exist_ok=True)

    # Reset student REF-2026-1001 to be at cashier payment step with unsettled balance
    roadmap = [
        {"id": 1, "stepId": "online_registration", "name": "Online Registration", "status": "COMPLETED"},
        {"id": 2, "stepId": "registrar_verification", "name": "Registrar Verification", "status": "COMPLETED"},
        {"id": 3, "stepId": "advising_assessment", "name": "Program Advising", "status": "COMPLETED"},
        {"id": 4, "stepId": "clinic_checkup", "name": "Medical Clearance", "status": "COMPLETED"},
        {"id": 5, "stepId": "cashier_payment", "name": "Cashier Payment", "status": "IN_PROGRESS"},
        {"id": 6, "stepId": "id_email_final", "name": "IT Account", "status": "PENDING"}
    ]
    payment = {
        "status": "PARTIAL",
        "totalFee": 18300,
        "amountPaid": 3000,
        "balance": 15300,
        "paymentType": "Cash",
        "history": []
    }

    try:
        requests.post("http://127.0.0.1/systemtest/api/index.php?action=stations/update", json={
            "referenceNumber": "REF-2026-1001",
            "updateData": {
                "status": "PARTIAL",
                "roadmap": roadmap,
                "payment": payment
            }
        })
    except Exception as e:
        print("API error:", e)

    p = sync_playwright().start()
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'cashier')
    page.fill('#password', 'cashier123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/payment-processing/index.html', timeout=10000)
    time.sleep(1.5)

    # Force backend sync & storage event
    page.evaluate('''async () => {
        if (window.StationDataBus) {
            await window.StationDataBus.syncWithBackend();
        }
        window.dispatchEvent(new Event('storage'));
    }''')
    time.sleep(1.5)

    rows = page.locator('table tbody tr').all()
    if len(rows) > 0:
        btn = rows[0].locator('button.btn-pill')
        btn.click()
        page.wait_for_selector('#paymentModal.show, #paymentModal', timeout=8000)
        time.sleep(0.8)

        select_el = page.locator('#paymentModal select.form-select')
        if select_el.is_visible():
            # 1. Cash Mode
            select_el.select_option('Cash')
            time.sleep(0.4)
            page.screenshot(path=os.path.join(artifact_dir, "cashier_mode_cash.png"))
            print("  [OK] Captured Cashier Mode: Cash")

            # 2. GCash OTC Mode
            select_el.select_option('GCash')
            time.sleep(0.4)
            page.screenshot(path=os.path.join(artifact_dir, "cashier_mode_gcash_otc.png"))
            print("  [OK] Captured Cashier Mode: GCash Over-The-Counter")

            # 3. PayMongo Hosted Gateway Mode
            select_el.select_option('PayMongo')
            time.sleep(0.4)
            page.screenshot(path=os.path.join(artifact_dir, "cashier_mode_paymongo_gateway.png"))
            print("  [OK] Captured Cashier Mode: PayMongo Hosted Gateway")

    browser.close()
    p.stop()
    print("\n[SUCCESS] Cashier mode screenshots captured successfully!")

if __name__ == '__main__':
    capture_cashier()
