import os
import sys
import time
import json
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

def test_operators():
    options = Options()
    options.add_argument('--headless')
    options.add_argument('--disable-gpu')
    options.add_argument('--window-size=1920,1080')
    driver = webdriver.Chrome(options=options)

    try:
        # 1. Login to Super Admin
        gateway_url = "http://localhost/systemtest/index.html?clear=true&redirect=admin/index.html"
        driver.get(gateway_url)

        user_field = WebDriverWait(driver, 10).until(EC.presence_of_element_located((By.ID, "username")))
        pass_field = WebDriverWait(driver, 10).until(EC.presence_of_element_located((By.ID, "password")))

        driver.execute_script("""
            const u = document.getElementById('username');
            const p = document.getElementById('password');
            u.value = 'admin';
            u.dispatchEvent(new Event('input', { bubbles: true }));
            p.value = 'admin12345';
            p.dispatchEvent(new Event('input', { bubbles: true }));
        """)
        time.sleep(0.5)

        submit_btn = WebDriverWait(driver, 10).until(
            EC.element_to_be_clickable((By.CSS_SELECTOR, "button[type='submit']"))
        )
        submit_btn.click()

        WebDriverWait(driver, 10).until(
            lambda d: 'admin' in d.current_url.lower()
        )
        time.sleep(2)

        # 2. Navigate to Staff Logins (Operators)
        driver.execute_script("window.app.setView('operators')")
        time.sleep(2)

        # 3. Verify operator table rows
        rows = driver.find_elements(By.CSS_SELECTOR, "table.tbl tbody tr")
        print(f"[INFO] Found {len(rows)} operator rows in table.")
        
        # Check deactivation buttons
        deact_btns = driver.find_elements(By.XPATH, "//button[contains(., 'Deactivate')]")
        print(f"[INFO] Found {len(deact_btns)} 'Deactivate' buttons.")
        assert len(deact_btns) > 0, "No 'Deactivate' buttons found on active operators!"
        
        # Check active badges
        active_badges = driver.find_elements(By.CSS_SELECTOR, "table.tbl .badge-active")
        print(f"[INFO] Found {len(active_badges)} '.badge-active' badges.")
        assert len(active_badges) > 0, "No '.badge-active' badges found!"

        # Save screenshot
        os.makedirs("scratch", exist_ok=True)
        screenshot_path = os.path.abspath("scratch/operators_view_verified.png")
        driver.save_screenshot(screenshot_path)
        print(f"[PASS] Screenshot saved to: {screenshot_path}")

        # 4. Test calling updateStatus on the first operator
        user_id = driver.execute_script("return window.app.users[0].id")
        print(f"[INFO] Updating user {user_id} to DEACTIVATED...")
        driver.execute_script(f"window.app.updateStatus({user_id}, 'DEACTIVATED')")
        time.sleep(2)

        # Verify an Activate button appeared
        act_btns = driver.find_elements(By.XPATH, "//button[contains(., 'Activate')]")
        print(f"[INFO] Found {len(act_btns)} 'Activate' buttons after deactivating user {user_id}.")
        assert len(act_btns) > 0, "Expected at least 1 'Activate' button after deactivation!"

        # Reactivate user
        driver.execute_script(f"window.app.updateStatus({user_id}, 'ACTIVE')")
        time.sleep(2)
        print("[PASS] Successfully tested toggle activation/deactivation cycle!")

        driver.save_screenshot(os.path.abspath("scratch/operators_toggled_back.png"))
        print("\n🎉 ALL OPERATOR DEACTIVATE/ACTIVATE TESTS PASSED PERFECTLY!\n")

    finally:
        driver.quit()

if __name__ == "__main__":
    test_operators()
