from playwright.sync_api import sync_playwright
import time
import os

p = sync_playwright().start()
b = p.chromium.launch(headless=True)
page = b.new_page(viewport={"width": 1280, "height": 900})

page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
page.fill('#username', 'cashier')
page.fill('#password', 'cashier123')
page.click('button[type="submit"]')
page.wait_for_url('**/payment-processing/index.html', timeout=10000)
time.sleep(2)

page.evaluate('''async () => {
    localStorage.removeItem('gncp_enrollment_queue');
    if (window.StationDataBus) {
        await window.StationDataBus.syncWithBackend();
    }
    window.dispatchEvent(new Event('storage'));
}''')
time.sleep(2)

page.screenshot(path=r'C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage\cashier_table_debug.png')

btns = page.locator('table button').all()
print(f"Total buttons in table: {len(btns)}")
for i, btn in enumerate(btns):
    print(f"Btn {i}: {btn.inner_text().strip()}")

if len(btns) > 0:
    btns[0].click()
    time.sleep(1)
    page.screenshot(path=r'C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage\cashier_modal_debug.png')
    print("Clicked button and saved cashier_modal_debug.png")

b.close()
p.stop()
