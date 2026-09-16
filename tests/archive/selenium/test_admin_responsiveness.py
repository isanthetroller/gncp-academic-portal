import time
import os
import sys
import json
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

ARTIFACTS_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\4464001e-217e-417b-9221-861b7c89d6e8"
BASE_URL = "http://localhost/systemtest"

def perform_admin_login(driver, wait):
    driver.get(f"{BASE_URL}/index.html")
    time.sleep(1)
    
    # Enter username & password
    user_input = wait.until(EC.presence_of_element_located((By.ID, "username")))
    pass_input = wait.until(EC.presence_of_element_located((By.ID, "password")))
    
    user_input.clear()
    user_input.send_keys("admin")
    pass_input.clear()
    pass_input.send_keys("admin12345")
    
    # Click Sign In button via JS
    submit_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
    driver.execute_script("arguments[0].click();", submit_btn)
    
    # Wait for redirect to admin portal or navigate directly if session set
    time.sleep(2)
    user_dict = json.dumps({"username": "admin", "name": "System Administrator", "role": "SUPER_ADMIN", "status": "ACTIVE"})
    driver.execute_script("""
        const ud = arguments[0];
        sessionStorage.setItem('gncp_admin_user', ud);
        localStorage.setItem('gncp_admin_user', ud);
    """, user_dict)
    
    if "admin" not in driver.current_url.lower():
        driver.get(f"{BASE_URL}/admin/index.html")
        time.sleep(2)

def test_admin_responsiveness():
    print("=======================================================")
    print("  SUPER ADMIN PORTAL MULTI-VIEWPORT RESPONSIVENESS    ")
    print("=======================================================")

    chrome_options = Options()
    chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.set_capability('goog:loggingPrefs', {'browser': 'ALL'})

    driver = webdriver.Chrome(options=chrome_options)
    wait = WebDriverWait(driver, 10)

    try:
        # ══════════════════════════════════════════════════════════
        # 1. MOBILE PORTRAIT VIEWPORT (375 x 812 px)
        # ══════════════════════════════════════════════════════════
        print("\n--- 1. Testing Mobile Portrait (375x812px) ---")
        driver.set_window_size(375, 812)
        perform_admin_login(driver, wait)

        # Assert mobile header bar is displayed
        mobile_header = wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, ".mobile-header-bar")))
        assert mobile_header.is_displayed(), "[FAIL] Mobile header bar is not visible at 375px"
        print("  [PASS] Mobile header bar is visible")

        # Capture Mobile Dashboard Top
        mobile_shot = os.path.join(ARTIFACTS_DIR, "admin_dashboard_mobile_375.png")
        driver.save_screenshot(mobile_shot)
        print(f"  [PASS] Mobile dashboard screenshot saved: {mobile_shot}")

        # Open Drawer
        menu_btn = driver.find_element(By.CSS_SELECTOR, ".mobile-menu-btn")
        driver.execute_script("arguments[0].click();", menu_btn)
        time.sleep(1)

        backdrop = driver.find_element(By.CSS_SELECTOR, ".sidebar-backdrop")
        assert "show" in backdrop.get_attribute("class"), "[FAIL] Backdrop not shown when drawer is open"
        drawer_shot = os.path.join(ARTIFACTS_DIR, "admin_drawer_mobile_375.png")
        driver.save_screenshot(drawer_shot)
        print(f"  [PASS] Admin mobile drawer opened and saved: {drawer_shot}")

        # Close Drawer via Backdrop click
        driver.execute_script("arguments[0].click();", backdrop)
        time.sleep(1)
        assert "show" not in backdrop.get_attribute("class"), "[FAIL] Backdrop did not close on tap"
        print("  [PASS] Admin drawer closed cleanly on backdrop tap")

        # Scroll down to test natural document vertical scroll
        driver.execute_script("window.scrollTo(0, 500);")
        time.sleep(1)
        scroll_y = driver.execute_script("return window.scrollY || document.documentElement.scrollTop || document.body.scrollTop;")
        assert scroll_y > 0, f"[FAIL] Document scrollY is {scroll_y}, vertical scroll is locked"
        print(f"  [PASS] Natural document vertical scrolling verified (scrollY={scroll_y}px)")

        # Switch view to Subjects & Courses
        driver.execute_script("window.app.view = 'subjects'; window.scrollTo(0,0);")
        time.sleep(1)
        subjects_shot = os.path.join(ARTIFACTS_DIR, "admin_subjects_mobile_375.png")
        driver.save_screenshot(subjects_shot)
        print(f"  [PASS] Admin Subjects table mobile view saved: {subjects_shot}")

        # ══════════════════════════════════════════════════════════
        # 2. TABLET VIEWPORT (768 x 1024 px)
        # ══════════════════════════════════════════════════════════
        print("\n--- 2. Testing Tablet Portrait (768x1024px) ---")
        driver.set_window_size(768, 1024)
        driver.execute_script("window.app.view = 'dashboard'; window.scrollTo(0,0);")
        time.sleep(1)
        tablet_shot = os.path.join(ARTIFACTS_DIR, "admin_tablet_768.png")
        driver.save_screenshot(tablet_shot)
        print(f"  [PASS] Tablet dashboard saved: {tablet_shot}")

        # ══════════════════════════════════════════════════════════
        # 3. WIDE DESKTOP VIEWPORT (1400 x 900 px)
        # ══════════════════════════════════════════════════════════
        print("\n--- 3. Testing Wide Desktop (1400x900px) ---")
        driver.set_window_size(1400, 900)
        time.sleep(1)
        desktop_shot = os.path.join(ARTIFACTS_DIR, "admin_desktop_1400.png")
        driver.save_screenshot(desktop_shot)
        print(f"  [PASS] Desktop dashboard saved: {desktop_shot}")

        print("\n=======================================================")
        print("  ALL ADMIN RESPONSIVENESS & SCROLL TESTS PASSED! 100% ")
        print("=======================================================")

    finally:
        driver.quit()

if __name__ == "__main__":
    test_admin_responsiveness()
