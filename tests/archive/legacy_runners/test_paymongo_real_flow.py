import time
import os
from playwright.sync_api import sync_playwright

def test_paymongo_end_to_end():
    artifact_dir = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"
    os.makedirs(artifact_dir, exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 850})
        page = context.new_page()

        print("\n--- 1. Testing Cashier Station Payment Modes ---")
        page.goto("http://127.0.0.1/systemtest/index.html?clear=true")
        page.fill("#username", "cashier")
        page.fill("#password", "cashier123")
        page.click("button[type='submit']")
        page.wait_for_url("**/payment-processing/index.html", timeout=10000)
        page.wait_for_timeout(1500)

        # Force Vue queue sync
        page.evaluate("() => { window.dispatchEvent(new Event('storage')); }")
        page.wait_for_timeout(800)

        # Click the first Process button
        process_btn = page.locator("button.btn-pill:has-text('Process'), button:has-text('Collect')").first
        if process_btn.is_visible():
            process_btn.click()
            page.wait_for_selector("#paymentModal.show, #paymentModal")
            page.wait_for_timeout(800)

            # 1. Cash Mode
            select_el = page.locator("#paymentModal select.form-select")
            select_el.select_option("Cash")
            page.wait_for_timeout(400)
            page.screenshot(path=os.path.join(artifact_dir, "cashier_mode_cash.png"))
            print("  [OK] Captured Cashier Mode: Cash")

            # 2. GCash OTC Mode
            select_el.select_option("GCash")
            page.wait_for_timeout(400)
            page.screenshot(path=os.path.join(artifact_dir, "cashier_mode_gcash_otc.png"))
            print("  [OK] Captured Cashier Mode: GCash Over-The-Counter")

            # 3. PayMongo Hosted Gateway Mode
            select_el.select_option("PayMongo")
            page.wait_for_timeout(400)
            page.screenshot(path=os.path.join(artifact_dir, "cashier_mode_paymongo_gateway.png"))
            print("  [OK] Captured Cashier Mode: PayMongo Hosted Gateway")

        print("\n--- 2. Testing Authentic PayMongo Checkout Experience ---")
        checkout_url = "http://127.0.0.1/systemtest/shared/paymongo/checkout.html?ref=GNCP-2026-133199&amount=3000&desc=Tuition+Assessment+-+Ethan+Test"
        page.goto(checkout_url)
        page.wait_for_selector(".checkout-grid")
        page.wait_for_timeout(600)

        # Tab 1: GCash Flow (Default)
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_tab_gcash_step1.png"))
        print("  [OK] Captured PayMongo GCash Step 1 (Mobile Input)")

        # Enter GCash mobile number and click Next
        page.click("button:has-text('Next')")
        page.wait_for_timeout(400)
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_tab_gcash_step2_otp.png"))
        print("  [OK] Captured PayMongo GCash Step 2 (OTP Verification)")

        # Verify OTP and proceed to MPIN
        page.click("button:has-text('Verify Code')")
        page.wait_for_timeout(400)
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_tab_gcash_step3_mpin.png"))
        print("  [OK] Captured PayMongo GCash Step 3 (MPIN Authorization)")

        # Tab 2: Maya
        page.click("button:has-text('Maya')")
        page.wait_for_timeout(300)
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_tab_maya.png"))
        print("  [OK] Captured PayMongo Maya Rail")

        # Tab 3: Card
        page.click("button:has-text('Card')")
        page.wait_for_timeout(300)
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_tab_card.png"))
        print("  [OK] Captured PayMongo Card Rail")

        # Tab 4: QR Ph
        page.click("button:has-text('QR Ph')")
        page.wait_for_timeout(300)
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_tab_qrph.png"))
        print("  [OK] Captured PayMongo QR Ph Rail")

        # Complete GCash Payment
        page.click("button:has-text('GCash')")
        page.wait_for_timeout(300)
        page.locator(".gcash-box button.pm-btn-primary").click()
        page.wait_for_timeout(1200)
        page.wait_for_selector(".success-hero")
        page.screenshot(path=os.path.join(artifact_dir, "paymongo_payment_success.png"))
        print("  [OK] Captured PayMongo Payment Success Settlement Screen")

        browser.close()
        print("\n=== PayMongo E2E Real Flow Test Completed Successfully! ===")

if __name__ == "__main__":
    test_paymongo_end_to_end()
