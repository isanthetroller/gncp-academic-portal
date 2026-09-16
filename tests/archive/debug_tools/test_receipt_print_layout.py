import os
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8000/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def test_print_layout():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        page = b.new_page(viewport={"width": 1280, "height": 900})

        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        page.fill("input[type='text'], input[placeholder*='username']", "cashier")
        page.fill("input[type='password']", "cashier123")
        page.click("button:has-text('LOGIN OPERATOR'), button[type='submit']")
        time.sleep(2)

        # Trigger receiptData in Vue
        page.evaluate("""() => {
            if (window.__cashierScope) {
                window.app.receiptData = {
                    refNo: 'GNCP-2026-238201',
                    name: 'Gabriel Cruz Ramos',
                    program: 'BSCS - Bachelor of Science in Computer Science',
                    paymentMode: 'Semi-Annual Installment (Cash)',
                    transactionRef: 'OR-2026-697635',
                    totalFee: 18300,
                    amountPaid: 3000,
                    balance: 15300,
                    date: 'August 23, 2026 09:15 PM',
                    cashier: 'Finance Cashier Officer'
                };
            }
        }""")
        time.sleep(1)

        # Screenshot 1: In-tab modal receipt viewer
        path_modal = os.path.join(ARTIFACT_DIR, "cashier_in_tab_receipt_modal_fixed.png")
        page.screenshot(path=path_modal)
        print(f"Captured: {path_modal}")

        # Emulate print media
        page.emulate_media(media="print")
        time.sleep(1)

        # Screenshot 2: Emulated Print layout showing entire receipt card without clipping
        path_print = os.path.join(ARTIFACT_DIR, "cashier_print_media_layout_fixed.png")
        page.screenshot(path=path_print, full_page=True)
        print(f"Captured: {path_print}")

        # Generate actual PDF file to verify exact PDF printing
        path_pdf = os.path.join(ARTIFACT_DIR, "official_receipt_sample.pdf")
        page.pdf(path=path_pdf, format="A4", print_background=True)
        print(f"Generated PDF: {path_pdf}")

        b.close()

if __name__ == "__main__":
    test_print_layout()
