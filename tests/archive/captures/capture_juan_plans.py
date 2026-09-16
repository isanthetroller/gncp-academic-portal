from playwright.sync_api import sync_playwright
import time

def capture_juan_plans():
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

    # Force Vue to load queue
    page.evaluate('''() => {
        window.dispatchEvent(new Event('storage'));
    }''')
    time.sleep(1)

    # Find the process button for Juan
    rows = page.locator('table tbody tr').all()
    print(f"Table rows: {len(rows)}")
    for r in rows:
        text = r.inner_text()
        print("Row content:", text.replace('\n', ' -- ').encode('ascii', 'replace').decode())
        if "GNCP-2026-133199" in text:
            btn = r.locator('button.btn-pill')
            btn.click()
            time.sleep(1)
            print("Found and clicked JuanALS1551!")

            # 1. Full Settlement screenshot
            page.screenshot(path='C:/Users/ethan/.gemini/antigravity-ide/brain/b79481f9-b325-483e-b1ca-bcfa90153a88/.tempmediaStorage/payment_plan_full_settlement.png')
            print("Captured Full Settlement UI!")

            # 2. Click Minimum Downpayment
            dp = page.locator('.plan-card:has-text("Downpayment")')
            dp.click()
            time.sleep(0.5)
            page.screenshot(path='C:/Users/ethan/.gemini/antigravity-ide/brain/b79481f9-b325-483e-b1ca-bcfa90153a88/.tempmediaStorage/payment_plan_downpayment.png')
            print("Captured Downpayment UI!")

            # 3. Click Custom
            custom = page.locator('.plan-card:has-text("Custom")')
            custom.click()
            time.sleep(0.5)
            inp = page.locator('.payment-input-control')
            inp.fill('5000')
            inp.dispatch_event('input')
            time.sleep(0.5)
            page.screenshot(path='C:/Users/ethan/.gemini/antigravity-ide/brain/b79481f9-b325-483e-b1ca-bcfa90153a88/.tempmediaStorage/payment_plan_custom.png')
            print("Captured Custom UI!")
            break

    b.close()
    p.stop()

if __name__ == '__main__':
    capture_juan_plans()
