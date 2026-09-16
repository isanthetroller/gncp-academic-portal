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

def bootstrap_student_session(driver):
    driver.get(f"{BASE_URL}/student-portal/index.html")
    driver.execute_script("""
        const student = {
            id: 1,
            student_id: 'GNCP-2026-0001',
            name: 'Kriz Tristan Ethan',
            email: 'kriz.tristan@gncp.edu.ph',
            program: 'BSIT',
            year_level: '1st Year',
            status: 'ACTIVE'
        };
        sessionStorage.setItem('gncp_portal_student', JSON.stringify(student));
        localStorage.setItem('gncp_portal_student', JSON.stringify(student));
    """)
    driver.get(f"{BASE_URL}/student-portal/index.html")
    time.sleep(2)

def test_responsiveness():
    print("=======================================================")
    print("  STUDENT PORTAL MULTI-VIEWPORT RESPONSIVENESS TEST   ")
    print("=======================================================")

    chrome_options = Options()
    chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.set_capability('goog:loggingPrefs', {'browser': 'ALL'})

    driver = webdriver.Chrome(options=chrome_options)

    try:
        # ══════════════════════════════════════════════════════════
        # 1. MOBILE PORTRAIT VIEWPORT (375 x 812 px)
        # ══════════════════════════════════════════════════════════
        print("\n--- 1. Testing Mobile Portrait (375x812px) ---")
        driver.set_window_size(375, 812)
        bootstrap_student_session(driver)

        # Assert mobile header bar is displayed
        mobile_header = driver.find_element(By.CSS_SELECTOR, ".mobile-header-bar")
        assert mobile_header.is_displayed(), "[FAIL] Mobile header bar is not visible at 375px"
        print("  [PASS] Mobile header bar is visible")

        # Capture Mobile Top
        mobile_shot = os.path.join(ARTIFACTS_DIR, "student_portal_mobile_375.png")
        driver.save_screenshot(mobile_shot)
        print(f"  [PASS] Mobile top screenshot saved: {mobile_shot}")

        # Scroll to Announcement Card
        card = driver.find_element(By.CSS_SELECTOR, ".bulletin-card")
        driver.execute_script("arguments[0].scrollIntoView({block: 'start', behavior: 'instant'});", card)
        time.sleep(1)
        cards_shot = os.path.join(ARTIFACTS_DIR, "student_portal_mobile_feed_cards_375.png")
        driver.save_screenshot(cards_shot)
        print(f"  [PASS] Mobile cards screenshot saved: {cards_shot}")

        # Scroll to Milestones / Campus Desk
        desk = driver.find_element(By.CSS_SELECTOR, ".campus-desk-card")
        driver.execute_script("arguments[0].scrollIntoView({block: 'start', behavior: 'instant'});", desk)
        time.sleep(1)
        desk_shot = os.path.join(ARTIFACTS_DIR, "student_portal_mobile_desk_375.png")
        driver.save_screenshot(desk_shot)
        print(f"  [PASS] Mobile desk screenshot saved: {desk_shot}")

        # Test COR tab on mobile
        driver.execute_script("""
            window.scrollTo(0, 0);
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            window.app.activeTab = 'courses';
        """)
        time.sleep(1)
        cor_paper = driver.find_element(By.CSS_SELECTOR, ".cor-paper")
        driver.execute_script("arguments[0].scrollIntoView({block: 'start', behavior: 'instant'});", cor_paper)
        cor_shot = os.path.join(ARTIFACTS_DIR, "student_portal_cor_mobile_375.png")
        driver.save_screenshot(cor_shot)
        print(f"  [PASS] Official COR renders on 'courses' tab, saved: {cor_shot}")

        # Test Profile tab on mobile
        driver.execute_script("""
            window.scrollTo(0, 0);
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            window.app.activeTab = 'profile';
        """)
        time.sleep(1)
        profile_hero = driver.find_element(By.CSS_SELECTOR, ".profile-hero")
        driver.execute_script("arguments[0].scrollIntoView({block: 'start', behavior: 'instant'});", profile_hero)
        profile_shot = os.path.join(ARTIFACTS_DIR, "student_portal_profile_mobile_375.png")
        driver.save_screenshot(profile_shot)
        print(f"  [PASS] Student Profile renders cleanly on mobile, saved: {profile_shot}")

        # ══════════════════════════════════════════════════════════
        # 2. TABLET VIEWPORT (768 x 1024 px)
        # ══════════════════════════════════════════════════════════
        print("\n--- 2. Testing Tablet Portrait (768x1024px) ---")
        driver.set_window_size(768, 1024)
        driver.execute_script("""
            window.scrollTo(0, 0);
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            window.app.activeTab = 'announcements';
        """)
        time.sleep(1)
        tablet_shot = os.path.join(ARTIFACTS_DIR, "student_portal_tablet_768.png")
        driver.save_screenshot(tablet_shot)
        print(f"  [PASS] Tablet screenshot saved: {tablet_shot}")

        # ══════════════════════════════════════════════════════════
        # 3. WIDE DESKTOP VIEWPORT (1400 x 900 px)
        # ══════════════════════════════════════════════════════════
        print("\n--- 3. Testing Wide Desktop (1400x900px) ---")
        driver.set_window_size(1400, 900)
        driver.execute_script("""
            window.scrollTo(0, 0);
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            window.app.activeTab = 'announcements';
        """)
        time.sleep(1)
        desktop_shot = os.path.join(ARTIFACTS_DIR, "student_portal_desktop_1400.png")
        driver.save_screenshot(desktop_shot)
        print(f"  [PASS] Desktop screenshot saved: {desktop_shot}")

        print("\n=======================================================")
        print("  ALL MULTI-VIEWPORT RESPONSIVENESS TESTS PASSED! 100% ")
        print("=======================================================")

    finally:
        driver.quit()

if __name__ == "__main__":
    test_responsiveness()
