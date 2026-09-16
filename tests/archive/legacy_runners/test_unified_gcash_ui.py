import os
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8000/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def test_unified_gcash():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        page = b.new_page(viewport={"width": 1400, "height": 980})

        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        page.fill("input[type='text'], input[placeholder*='username']", "cashier")
        page.fill("input[type='password']", "cashier123")
        page.click("button:has-text('LOGIN OPERATOR'), button[type='submit']")
        time.sleep(2)

        # Switch to Queue View
        page.evaluate("""() => {
            if (window.app) {
                window.app.currentView = 'queue';
            }
        }""")
        time.sleep(1)

        # Open modal for an unsettled student and set paymentType = 'GCash'
        page.evaluate("""() => {
            if (window.__cashierScope && window.__cashierScope.students.value.length > 0) {
                let target = window.__cashierScope.students.value[0];
                target.payment.balance = 18300;
                target.payment.amountPaid = 0;
                target.status = 'MEDICAL_CLEARED';
                target.payment.paymentType = 'GCash';
                window.__cashierScope.openProcess(target);
            }
        }""")
        time.sleep(1.5)

        # Scroll modal body down to show payment card and breakdown
        page.evaluate("""() => {
            const body = document.querySelector('.modal-body');
            if (body) body.scrollTop = 220;
        }""")
        time.sleep(1)

        # Screenshot: Unified GCash / PayMongo digital payment card
        path_gcash = os.path.join(ARTIFACT_DIR, "cashier_unified_gcash_clean_theme.png")
        page.screenshot(path=path_gcash)
        print(f"Captured: {path_gcash}")

        b.close()

if __name__ == "__main__":
    test_unified_gcash()
