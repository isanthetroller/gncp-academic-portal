from playwright.sync_api import sync_playwright
import time
import os

def capture_modes():
    artifact_dir = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"
    os.makedirs(artifact_dir, exist_ok=True)

    p = sync_playwright().start()
    b = p.chromium.launch(headless=True)
    page = b.new_page(viewport={"width": 1280, "height": 900})

    # Clear and log in
    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'cashier')
    page.fill('#password', 'cashier123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/payment-processing/index.html', timeout=10000)
    time.sleep(2)

    # Click Student Queue tab or button
    page.click('button:has-text("Open Queue"), .sidebar-nav li:has-text("Student Queue")')
    time.sleep(1)

    # Force Vue queue sync
    page.evaluate('''async () => {
        if (window.StationDataBus) {
            await window.StationDataBus.syncWithBackend();
        }
        window.dispatchEvent(new Event('storage'));
    }''')
    time.sleep(1.5)

    # Find the process button for first student in the queue
    process_btn = page.locator('button.btn-pill:has-text("Process")').first
    if process_btn.is_visible():
        process_btn.click()
        time.sleep(1)

        select_el = page.locator('#paymentModal select.form-select')

        # 1. Cash Mode
        select_el.select_option('Cash')
        time.sleep(0.5)
        page.screenshot(path=os.path.join(artifact_dir, 'cashier_mode_cash.png'))
        print("Captured Cashier Mode: Cash")

        # 2. GCash OTC Mode
        select_el.select_option('GCash')
        time.sleep(0.5)
        page.screenshot(path=os.path.join(artifact_dir, 'cashier_mode_gcash_otc.png'))
        print("Captured Cashier Mode: GCash Over-The-Counter")

        # 3. PayMongo Hosted Gateway Mode
        select_el.select_option('PayMongo')
        time.sleep(0.5)
        page.screenshot(path=os.path.join(artifact_dir, 'cashier_mode_paymongo_gateway.png'))
        print("Captured Cashier Mode: PayMongo Hosted Gateway")

    b.close()
    p.stop()
    print("Done capturing cashier payment modes!")

if __name__ == '__main__':
    capture_modes()
