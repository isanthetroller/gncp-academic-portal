import os
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
SCREENSHOTS_DIR = "C:/xampp/htdocs/systemtest/tests/playwright/screenshots"
os.makedirs(SCREENSHOTS_DIR, exist_ok=True)

def capture_station_screenshots():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        # 1. TLC Helpdesk
        print("Logging into TLC Helpdesk...")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/tlc-helpdesk/index.html")
        page.wait_for_selector("#username", timeout=10000)
        page.fill("#username", "tristan")
        page.fill("#password", "tristan123")
        page.click("button[type='submit']")
        time.sleep(2.0)
        page.goto(f"{BASE_URL}/stations/tlc-helpdesk/index.html")
        time.sleep(1.5)
        page.evaluate("() => { const vm = document.querySelector('#app')?.__vue_app__?._instance?.proxy; if(vm) vm.currentView = 'queue'; }")
        time.sleep(1.0)
        page.screenshot(path=os.path.join(SCREENSHOTS_DIR, "tlc_helpdesk_queue_refined.png"))
        print("Captured TLC Helpdesk screenshot.")

        # 2. Medical Checkup
        print("Logging into Medical Checkup...")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/medical-checkup/index.html")
        page.wait_for_selector("#username", timeout=10000)
        page.fill("#username", "ethan")
        page.fill("#password", "ethan123")
        page.click("button[type='submit']")
        time.sleep(2.0)
        page.goto(f"{BASE_URL}/stations/medical-checkup/index.html")
        time.sleep(1.5)
        page.evaluate("() => { const vm = document.querySelector('#app')?.__vue_app__?._instance?.proxy; if(vm) vm.currentView = 'queue'; }")
        time.sleep(1.0)
        page.screenshot(path=os.path.join(SCREENSHOTS_DIR, "medical_checkup_queue_refined.png"))
        print("Captured Medical Checkup screenshot.")

        # 3. Payment Processing
        print("Logging into Payment Processing...")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/payment-processing/index.html")
        page.wait_for_selector("#username", timeout=10000)
        page.fill("#username", "cashier")
        page.fill("#password", "cashier123")
        page.click("button[type='submit']")
        time.sleep(2.0)
        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        time.sleep(1.5)
        page.evaluate("() => { const vm = document.querySelector('#app')?.__vue_app__?._instance?.proxy; if(vm) vm.currentView = 'queue'; }")
        time.sleep(1.0)
        page.screenshot(path=os.path.join(SCREENSHOTS_DIR, "payment_processing_queue_refined.png"))
        print("Captured Payment Processing screenshot.")

        # 4. IT Center
        print("Logging into IT Center...")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/stations/it-center/index.html")
        page.wait_for_selector("#username", timeout=10000)
        page.fill("#username", "it_officer")
        page.fill("#password", "itpassword")
        page.click("button[type='submit']")
        time.sleep(2.0)
        page.goto(f"{BASE_URL}/stations/it-center/index.html")
        time.sleep(1.5)
        page.evaluate("() => { const vm = document.querySelector('#app')?.__vue_app__?._instance?.proxy; if(vm) vm.currentView = 'queue'; }")
        time.sleep(1.0)
        page.screenshot(path=os.path.join(SCREENSHOTS_DIR, "it_center_queue_refined.png"))
        print("Captured IT Center screenshot.")

        # 5. Registrar
        print("Logging into Registrar...")
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/registrar/index.html")
        page.wait_for_selector("#username", timeout=10000)
        page.fill("#username", "kriz")
        page.fill("#password", "kriz123")
        page.click("button[type='submit']")
        time.sleep(2.0)
        page.goto(f"{BASE_URL}/registrar/index.html")
        time.sleep(1.5)
        page.screenshot(path=os.path.join(SCREENSHOTS_DIR, "registrar_queue_refined.png"))
        print("Captured Registrar screenshot.")

        browser.close()

if __name__ == "__main__":
    capture_station_screenshots()
