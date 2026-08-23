from playwright.sync_api import sync_playwright
import time

p = sync_playwright().start()
b = p.chromium.launch(headless=True)
page = b.new_page(viewport={"width": 1280, "height": 900})

page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
page.fill('#username', 'cashier')
page.fill('#password', 'cashier123')
page.click('button[type="submit"]')
page.wait_for_url('**/payment-processing/index.html', timeout=10000)
time.sleep(2)

page.evaluate('''() => {
    window.dispatchEvent(new Event('storage'));
}''')
time.sleep(1)

rows = page.locator('table tbody tr').all()
print(f"Total rows: {len(rows)}")
if len(rows) > 0:
    btn = rows[0].locator('button.btn-pill')
    btn.click()
    time.sleep(1.5)
    page.screenshot(path=r'C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage\cashier_debug_modal.png')
    print("Screenshot saved to cashier_debug_modal.png")

b.close()
p.stop()
