import os
import time
import json
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8000/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def capture_ui():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        page = b.new_page(viewport={"width": 1280, "height": 900})

        page.goto(f"{BASE_URL}/index.html?clear=true")
        page.fill("#username", "cashier")
        page.fill("#password", "cashier123")
        page.click("button[type='submit']")
        page.wait_for_url("**/payment-processing/index.html", timeout=10000)
        time.sleep(2)

        # Screenshot 1: Queue Table showing "Chosen Plan / Mode" column
        path_table = os.path.join(ARTIFACT_DIR, "cashier_table_chosen_plan_column.png")
        page.screenshot(path=path_table)
        print(f"Captured: {path_table}")

        # Inject an unsettled student who chose SEMI installment
        page.evaluate("""() => {
            if (window.app) {
                let target = window.app.students[0];
                if (target) {
                    target.payment.balance = 18300;
                    target.payment.amountPaid = 0;
                    target.paymentMode = 'SEMI';
                    target.status = 'MEDICAL_CLEARED';
                    window.app.openProcess(target);
                }
            }
        }""")
        time.sleep(1.5)

        # Screenshot 2: Modal reflecting SEMI installment with Downpayment pre-selected
        path_semi = os.path.join(ARTIFACT_DIR, "cashier_modal_reflecting_semi_installment.png")
        page.screenshot(path=path_semi)
        print(f"Captured: {path_semi}")

        # Close and open with CASH student
        page.evaluate("""() => {
            if (window.app) {
                window.app.closeModal();
                let target = window.app.students[0];
                if (target) {
                    target.paymentMode = 'CASH';
                    window.app.openProcess(target);
                }
            }
        }""")
        time.sleep(1.5)

        # Screenshot 3: Modal reflecting CASH with Full settlement pre-selected
        path_cash = os.path.join(ARTIFACT_DIR, "cashier_modal_reflecting_cash_full.png")
        page.screenshot(path=path_cash)
        print(f"Captured: {path_cash}")

        b.close()

if __name__ == "__main__":
    capture_ui()
