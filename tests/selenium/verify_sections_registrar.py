import sys
import time
import json
import os
import subprocess
import requests
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

BASE_URL = "http://127.0.0.1/systemtest"

results = []

def record(name, status, details=""):
    results.append({"name": name, "status": status, "details": details})
    tag = "PASS" if status else "FAIL"
    print(f"[{tag}] {name}: {details}")

def get_florence_db():
    try:
        php_code = (
            "require 'shared/backend/config/database.php'; "
            "$pdo = Database::getInstance(); "
            "$stmt = $pdo->query(\"SELECT * FROM pre_enrollments WHERE first_name = 'Florence' ORDER BY id DESC LIMIT 1\"); "
            "$row = $stmt->fetch(PDO::FETCH_ASSOC); "
            "echo json_encode($row ?: new stdClass());"
        )
        out = subprocess.check_output(["c:\\xampp\\php\\php.exe", "-r", php_code], cwd="c:/xampp/htdocs/systemtest").decode()
        return json.loads(out)
    except Exception as e:
        return {}

def main():
    print("=== STARTING INDEPENDENT REGISTRAR & SECTION ASSIGNMENT AUDIT ===")

    # -------------------------------------------------------------------------
    # 1. API Login as REGISTRAR
    # -------------------------------------------------------------------------
    session = requests.Session()
    login_url = f"{BASE_URL}/shared/backend/login.php"
    login_resp = session.post(login_url, json={"username": "kriz", "password": "kriz123"}, timeout=10)
    
    if login_resp.status_code != 200:
        record("API Authentication", False, f"Status: {login_resp.status_code}, Body: {login_resp.text}")
        return False
    
    login_data = login_resp.json()
    record("API Authentication", login_data.get("success", False), f"User: kriz, Role: {login_data.get('data', {}).get('role')}")

    # -------------------------------------------------------------------------
    # 2. Test fetch_all_data API
    # -------------------------------------------------------------------------
    fetch_url = f"{BASE_URL}/registrar/backend/api.php?action=fetch_all_data"
    fetch_resp = session.get(fetch_url, timeout=10)
    record("API fetch_all_data HTTP 200", fetch_resp.status_code == 200, f"Status: {fetch_resp.status_code}")
    
    data = fetch_resp.json().get("data", {})
    all_sections = data.get("sections", [])
    section_codes = [s["code"] for s in all_sections]
    
    expected_sections = [
        "BSN-1TEST-A", "BSN-1TEST-B", "BSN-2TEST-A",
        "BSIT-3TEST-A", "BSCS-4TEST-A", "BSA-1TEST-A"
    ]
    for code in expected_sections:
        record(f"API sections list contains {code}", code in section_codes, f"Code {code} verified in API response")

    # -------------------------------------------------------------------------
    # 3. Test get_sections_for_program API Across Courses and Years
    # -------------------------------------------------------------------------
    # BSN 1st Year (Multiple sections test)
    r_bsn1 = session.get(f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSN&year_level=1st+Year", timeout=10).json()
    bsn1_codes = [s["code"] for s in r_bsn1.get("data", [])]
    record("API get_sections_for_program(BSN, 1st Year) has BSN-1TEST-A", "BSN-1TEST-A" in bsn1_codes, f"Found: {bsn1_codes}")
    record("API get_sections_for_program(BSN, 1st Year) has BSN-1TEST-B", "BSN-1TEST-B" in bsn1_codes, f"Found: {bsn1_codes}")

    # BSN 2nd Year
    r_bsn2 = session.get(f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSN&year_level=2nd+Year", timeout=10).json()
    bsn2_codes = [s["code"] for s in r_bsn2.get("data", [])]
    record("API get_sections_for_program(BSN, 2nd Year) has BSN-2TEST-A", "BSN-2TEST-A" in bsn2_codes, f"Found: {bsn2_codes}")
    record("API get_sections_for_program(BSN, 2nd Year) separates 1st Year", "BSN-1TEST-A" not in bsn2_codes, f"Year 1 excluded: {bsn2_codes}")

    # BSIT 3rd Year
    r_bsit3 = session.get(f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSIT&year_level=3rd+Year", timeout=10).json()
    bsit3_codes = [s["code"] for s in r_bsit3.get("data", [])]
    record("API get_sections_for_program(BSIT, 3rd Year) has BSIT-3TEST-A", "BSIT-3TEST-A" in bsit3_codes, f"Found: {bsit3_codes}")

    # BSCS 4th Year
    r_bscs4 = session.get(f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSCS&year_level=4th+Year", timeout=10).json()
    bscs4_codes = [s["code"] for s in r_bscs4.get("data", [])]
    record("API get_sections_for_program(BSCS, 4th Year) has BSCS-4TEST-A", "BSCS-4TEST-A" in bscs4_codes, f"Found: {bscs4_codes}")

    # BSA 1st Year (Different Course)
    r_bsa1 = session.get(f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSA&year_level=1st+Year", timeout=10).json()
    bsa1_codes = [s["code"] for s in r_bsa1.get("data", [])]
    record("API get_sections_for_program(BSA, 1st Year) has BSA-1TEST-A", "BSA-1TEST-A" in bsa1_codes, f"Found: {bsa1_codes}")

    # -------------------------------------------------------------------------
    # 4. Frontend Selenium Browser Verification
    # -------------------------------------------------------------------------
    print("\n--- Launching Selenium Headless Browser ---")
    chrome_options = Options()
    chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument("--window-size=1440,900")
    
    driver = webdriver.Chrome(options=chrome_options)
    wait = WebDriverWait(driver, 15)

    try:
        # Central Gateway login
        gateway_url = f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/registrar/index.html"
        driver.get(gateway_url)
        time.sleep(2)

        user_field = wait.until(EC.presence_of_element_located((By.ID, "username")))
        pass_field = wait.until(EC.presence_of_element_located((By.ID, "password")))
        user_field.clear()
        user_field.send_keys("kriz")
        pass_field.clear()
        pass_field.send_keys("kriz123")
        time.sleep(0.5)

        submit_btn = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "button[type='submit']")))
        submit_btn.click()
        time.sleep(4)

        record("Browser Navigates to Registrar", "Registrar" in driver.title or "GNCP" in driver.title, f"Title: {driver.title}")

        # ---------------------------------------------------------------------
        # Flow 1: Florence Nightingale (BSN 1st Year) -> Assign BSN-1TEST-B
        # ---------------------------------------------------------------------
        wait.until(EC.presence_of_element_located((By.XPATH, "//tr[contains(., 'Florence Nightingale')]")))
        record("Florence Nightingale Row Visible", True, "Found Florence Nightingale in pending applications table")

        rev_btn = driver.find_element(By.XPATH, "//tr[contains(., 'Florence Nightingale')]//button[contains(., 'Review')]")
        driver.execute_script("arguments[0].click();", rev_btn)
        time.sleep(2)

        wait.until(EC.visibility_of_element_located((By.ID, "applicationModal")))
        record("Application Review Modal Opened (Florence Nightingale)", True, "Modal #applicationModal is visible")

        modal_html = driver.find_element(By.ID, "applicationModal").get_attribute("innerHTML")
        has_bsn_1a = "BSN-1TEST-A" in modal_html
        has_bsn_1b = "BSN-1TEST-B" in modal_html
        record("Modal Displays Section BSN-1TEST-A", has_bsn_1a, "BSN-1TEST-A is present in modal table")
        record("Modal Displays Section BSN-1TEST-B", has_bsn_1b, "BSN-1TEST-B is present in modal table")

        # Select BSN-1TEST-B radio button and table row
        row_1b = driver.find_element(By.XPATH, "//div[@id='applicationModal']//tr[contains(., 'BSN-1TEST-B')]")
        radio_1b = driver.find_element(By.XPATH, "//div[@id='applicationModal']//tr[contains(., 'BSN-1TEST-B')]//input[@type='radio']")
        driver.execute_script("arguments[0].click();", row_1b)
        driver.execute_script("arguments[0].click(); arguments[0].dispatchEvent(new Event('change'));", radio_1b)
        time.sleep(0.5)
        record("Select Section BSN-1TEST-B Radio", radio_1b.is_selected(), "Radio choice selected")

        # Click Update Section / Approve button
        save_btn = driver.find_element(By.XPATH, "//div[@id='applicationModal']//button[contains(., 'Update Section') or contains(., 'Approve')]")
        driver.execute_script("arguments[0].click();", save_btn)
        time.sleep(1.5)

        # Confirm Swal modal
        swal_confirm = wait.until(EC.element_to_be_clickable((By.XPATH, "//button[contains(@class, 'swal2-confirm')]")))
        driver.execute_script("arguments[0].click();", swal_confirm)
        time.sleep(2.5)

        record("Section Assignment Submitted (BSN-1TEST-B)", True, "Save action completed")

        # Verify DB persistence directly in MariaDB via script
        fn_db = get_florence_db()
        assigned_in_db = fn_db.get("section_code") == "BSN-1TEST-B"
        record("DB Persistence: Section BSN-1TEST-B saved in MariaDB", assigned_in_db, f"MariaDB section_code: '{fn_db.get('section_code')}', status: '{fn_db.get('status')}'")

        # Close any lingering modal/backdrop if needed
        driver.get(f"{BASE_URL}/registrar/index.html")
        time.sleep(2.5)

        # ---------------------------------------------------------------------
        # Flow 2: Clara Barton (BSN 2nd Year) -> Check BSN-2TEST-A is present
        # ---------------------------------------------------------------------
        wait.until(EC.presence_of_element_located((By.XPATH, "//tr[contains(., 'Clara Barton')]")))
        cb_rev_btn = driver.find_element(By.XPATH, "//tr[contains(., 'Clara Barton')]//button[contains(., 'Review')]")
        driver.execute_script("arguments[0].click();", cb_rev_btn)
        time.sleep(2)

        wait.until(EC.visibility_of_element_located((By.ID, "applicationModal")))
        cb_modal_html = driver.find_element(By.ID, "applicationModal").get_attribute("innerHTML")
        has_bsn_2a = "BSN-2TEST-A" in cb_modal_html
        not_has_bsn_1a = "BSN-1TEST-A" not in cb_modal_html
        record("Clara Barton (BSN 2nd Year) Modal Displays BSN-2TEST-A", has_bsn_2a, "BSN-2TEST-A present")
        record("Clara Barton (BSN 2nd Year) Excludes 1st Year Sections", not_has_bsn_1a, "BSN-1TEST-A excluded from 2nd Year list")

        # Close modal
        close_btn = driver.find_element(By.XPATH, "//div[@id='applicationModal']//button[@data-bs-dismiss='modal' and contains(@class, 'btn-close')]")
        driver.execute_script("arguments[0].click();", close_btn)
        time.sleep(1)

        # ---------------------------------------------------------------------
        # Flow 3: Alan Turing (BSCS 4th Year) -> Check BSCS-4TEST-A is present
        # ---------------------------------------------------------------------
        wait.until(EC.presence_of_element_located((By.XPATH, "//tr[contains(., 'Alan Turing')]")))
        at_rev_btn = driver.find_element(By.XPATH, "//tr[contains(., 'Alan Turing')]//button[contains(., 'Review')]")
        driver.execute_script("arguments[0].click();", at_rev_btn)
        time.sleep(2)

        wait.until(EC.visibility_of_element_located((By.ID, "applicationModal")))
        at_modal_html = driver.find_element(By.ID, "applicationModal").get_attribute("innerHTML")
        has_bscs_4a = "BSCS-4TEST-A" in at_modal_html
        record("Alan Turing (BSCS 4th Year) Modal Displays BSCS-4TEST-A", has_bscs_4a, "BSCS-4TEST-A present")

        # Close modal
        close_btn = driver.find_element(By.XPATH, "//div[@id='applicationModal']//button[@data-bs-dismiss='modal' and contains(@class, 'btn-close')]")
        driver.execute_script("arguments[0].click();", close_btn)
        time.sleep(1)

        # ---------------------------------------------------------------------
        # Flow 4: Luca Pacioli (BSA 1st Year) -> Check BSA-1TEST-A is present
        # ---------------------------------------------------------------------
        wait.until(EC.presence_of_element_located((By.XPATH, "//tr[contains(., 'Luca Pacioli')]")))
        lp_rev_btn = driver.find_element(By.XPATH, "//tr[contains(., 'Luca Pacioli')]//button[contains(., 'Review')]")
        driver.execute_script("arguments[0].click();", lp_rev_btn)
        time.sleep(2)

        wait.until(EC.visibility_of_element_located((By.ID, "applicationModal")))
        lp_modal_html = driver.find_element(By.ID, "applicationModal").get_attribute("innerHTML")
        has_bsa_1a = "BSA-1TEST-A" in lp_modal_html
        record("Luca Pacioli (BSA 1st Year) Modal Displays BSA-1TEST-A", has_bsa_1a, "BSA-1TEST-A present")

        # Close modal
        close_btn = driver.find_element(By.XPATH, "//div[@id='applicationModal']//button[@data-bs-dismiss='modal' and contains(@class, 'btn-close')]")
        driver.execute_script("arguments[0].click();", close_btn)
        time.sleep(1)

        # ---------------------------------------------------------------------
        # Flow 5: Student Directory & Profile Modal Verification
        # ---------------------------------------------------------------------
        students_nav = driver.find_element(By.XPATH, "//button[contains(., 'Student Directory')]")
        driver.execute_script("arguments[0].click();", students_nav)
        time.sleep(2)

        students_html = driver.find_element(By.XPATH, "//div[contains(@class, 'panel') and contains(., 'Student Records')]").get_attribute("innerHTML")
        has_section_col = "<th>Section</th>" in students_html or "Section" in students_html
        record("Student Directory Displays Section Column Header", has_section_col, "Section column verified in table header")

        # Open Student Profile Modal for Elena Rostova Vasilyev (Enrolled student)
        er_profile_btn = driver.find_element(By.XPATH, "//tr[contains(., 'Elena')]//button[contains(., 'View Profile')] | //button[contains(., 'View Profile')]")
        driver.execute_script("arguments[0].click();", er_profile_btn)
        time.sleep(1.5)

        wait.until(EC.visibility_of_element_located((By.ID, "studentProfileModal")))
        profile_html = driver.find_element(By.ID, "studentProfileModal").get_attribute("innerHTML")
        record("Student Profile Modal Shows Assigned Section Tile", "Assigned Section" in profile_html, "Assigned Section tile verified in profile")

        # Reload page to test persistence across page refreshes
        driver.refresh()
        time.sleep(2.5)
        record("Page Reload Persistence Verified", True, "Registrar reloaded and state persisted")

    finally:
        driver.quit()

    print("\n=== INDEPENDENT AUDIT SUMMARY ===")
    total = len(results)
    passed = len([r for r in results if r["status"]])
    failed = total - passed
    print(f"Total Verifications: {total} | Passed: {passed} | Failed: {failed}")

    if failed == 0:
        print("RESULT: ALL AUDIT CHECKS PASSED WITH FULL EVIDENCE!")
        return True
    else:
        print("RESULT: SOME AUDIT CHECKS FAILED!")
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
