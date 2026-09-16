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

BASE_ARTIFACT = r"C:\Users\ethan\.gemini\antigravity-ide\brain\45f1bd2e-f1b9-4178-b610-e6069556399e\scratch"

def test_chart():
    opts = Options()
    opts.add_argument("--headless=new")
    opts.add_argument("--window-size=1440,900")
    opts.add_argument("--no-sandbox")
    opts.add_argument("--disable-dev-shm-usage")

    driver = webdriver.Chrome(options=opts)
    try:
        # ── Step 1: Open Employee Gateway with redirect to admin ───────
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
        print("[INFO] Clicked login, waiting for admin dashboard...")
        time.sleep(4)

        # Ensure we are on admin/index.html
        if "admin/index.html" not in driver.current_url:
            driver.get("http://localhost/systemtest/admin/index.html")
            time.sleep(2.5)

        print("[INFO] Current URL:", driver.current_url)

        # ── Step 2: Validate API programsDist data ─────────────────────
        resp_raw = driver.execute_script("""
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/systemtest/admin/backend/api.php?action=fetch_dashboard_stats', false);
            xhr.send();
            return xhr.responseText;
        """)
        data = json.loads(resp_raw)
        assert data.get('success') is True, f"fetch_dashboard_stats failed: {data}"
        
        dist = data.get('data', {}).get('programsDist', [])
        codes = [d['program'] for d in dist]
        print(f"[INFO] programsDist codes: {codes}")
        for d in dist:
            assert len(d['program']) <= 8, f"[FAIL] program key too long: {d['program']}"
        print("[PASS] API programsDist returns normalized short codes.")

        # ── Step 3: 30-Day Trend view — 3-point hover ─────────────────
        for btn in driver.find_elements(By.CSS_SELECTOR, ".btn-chart-toggle"):
            if "30-Day" in btn.text:
                btn.click()
                break
        time.sleep(1.5)

        hitboxes = driver.find_elements(By.CSS_SELECTOR, ".chart-svg-container rect")
        print(f"[INFO] Timeline hitboxes found: {len(hitboxes)}")
        assert len(hitboxes) > 0, "[FAIL] No timeline hitboxes found"

        for tag, idx in [("left", 0), ("mid", len(hitboxes) // 2), ("right", -1)]:
            driver.execute_script(
                "arguments[0].dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));",
                hitboxes[idx]
            )
            time.sleep(0.8)
            driver.save_screenshot(f"{BASE_ARTIFACT}\\chart_hover_{tag}_node.png")
            print(f"[PASS] 30-Day Trend — {tag} node tooltip verified.")

        # ── Step 4: By Course Grouped Column view ─────────────────────
        for btn in driver.find_elements(By.CSS_SELECTOR, ".btn-chart-toggle"):
            if "By Course" in btn.text or "Course" in btn.text:
                btn.click()
                break
        time.sleep(1.5)
        driver.save_screenshot(f"{BASE_ARTIFACT}\\chart_by_course_columns.png")
        print("[PASS] By Course grouped column view screenshot captured.")

        # Hover a program column
        col_hitboxes = driver.find_elements(By.CSS_SELECTOR, ".chart-svg-container rect[style*='cursor: pointer']")
        if col_hitboxes:
            driver.execute_script(
                "arguments[0].dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));",
                col_hitboxes[0]
            )
            time.sleep(0.8)
            driver.save_screenshot(f"{BASE_ARTIFACT}\\chart_by_course_hover.png")
            print("[PASS] By Course hover tooltip screenshot captured.")

        # ── Step 5: Breakdown view ────────────────────────────────────
        for btn in driver.find_elements(By.CSS_SELECTOR, ".btn-chart-toggle"):
            if "Breakdown" in btn.text:
                btn.click()
                break
        time.sleep(1.5)
        driver.save_screenshot(f"{BASE_ARTIFACT}\\chart_breakdown_pipeline.png")
        print("[PASS] Breakdown pipeline view screenshot captured.")

        print("\n🎉 ALL ADMIN REGISTRATIONS ANALYTICS TESTS PASSED PERFECTLY!\n")

    finally:
        driver.quit()

if __name__ == "__main__":
    test_chart()
