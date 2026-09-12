"""
Playwright test — Student Portal Requirement Status Fix Verification
====================================================================
Tests all document status scenarios to verify the dual-format normalizer
in StudentModel::getStudentRequirements() works correctly.

Scenarios tested:
  1. Student with registrar Format A data (ORIGINAL/PHOTOCOPY) → "Under Review (Hard Copy)"
  2. Student with registrar Format A UNDERTAKING → "Conditional Undertaking"
  3. Student with parent status VERIFIED (Format A) → "Verified & Approved"
  4. Student with uploaded softcopy (Format B) → "Under Review"
  5. Student with no data → "Not Submitted / Missing"
  6. API response JSON validation (DB → API roundtrip)
"""

import os
import sys
import json
import time
import requests
import pymysql
from datetime import datetime
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest"
ARTIFACT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "screenshots")
os.makedirs(ARTIFACT_DIR, exist_ok=True)

DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "gncp_portal",
    "port": 3306,
    "charset": "utf8mb4"
}

PASS = "[PASS]"
FAIL = "[FAIL]"
results = []

def log(msg):
    ts = datetime.now().strftime("%H:%M:%S")
    print(f"[{ts}] {msg}")

def assert_check(label, condition, detail=""):
    status = PASS if condition else FAIL
    results.append({"label": label, "status": status, "detail": detail})
    log(f"{status}  {label}" + (f" \u2014 {detail}" if detail else ""))
    return condition

def get_db():
    return pymysql.connect(**DB_CONFIG)

def get_test_student(db):
    """Find a pre_enrollments student (not promoted to official students yet, or any row)."""
    with db.cursor(pymysql.cursors.DictCursor) as cur:
        # Prefer a pre_enrollment student still in pre_enrollments
        cur.execute("""SELECT temp_student_id, temp_pin, email, first_name, last_name, requirements_data, status
                       FROM pre_enrollments
                       WHERE status IN ('PRE_REGISTERED','VERIFIED','MEDICAL_CLEARED','ADVISED','PAID')
                       ORDER BY created_at DESC LIMIT 1""")
        row = cur.fetchone()
        if not row:
            cur.execute("""SELECT temp_student_id, temp_pin, email, first_name, last_name, requirements_data, status
                           FROM pre_enrollments ORDER BY created_at DESC LIMIT 1""")
            row = cur.fetchone()
    return row

def inject_format_a(db, ref, docs_dict, parent_status="VERIFIED"):
    payload = {
        "status": parent_status,
        "docs": docs_dict,
        "verifiedBy": "test_playwright",
        "dateVerified": datetime.now().isoformat()
    }
    with db.cursor() as cur:
        cur.execute(
            "UPDATE pre_enrollments SET requirements_data = %s WHERE temp_student_id = %s",
            (json.dumps(payload), ref)
        )
    db.commit()

def inject_format_b(db, ref, reqs_list):
    with db.cursor() as cur:
        cur.execute(
            "UPDATE pre_enrollments SET requirements_data = %s WHERE temp_student_id = %s",
            (json.dumps(reqs_list), ref)
        )
    db.commit()

def fetch_api_requirements(ref):
    """Fetch via the student/documents endpoint which accepts temp_student_id and PIN (bypasses session auth)."""
    # First get the PIN for this student
    db_tmp = get_db()
    pin = ""
    try:
        with db_tmp.cursor() as cur:
            cur.execute("SELECT temp_pin FROM pre_enrollments WHERE temp_student_id = %s LIMIT 1", (ref,))
            row = cur.fetchone()
            if row:
                pin = row[0]
    finally:
        db_tmp.close()

    url = f"{BASE_URL}/api/index.php?action=student/documents&identifier={ref}&pin={pin}"
    try:
        r = requests.get(url, timeout=10)
        data = r.json()
        if not data.get("success"):
            log(f"API returned error: {data.get('message')} | URL: {url}")
        return data.get("data", {}).get("requirements", []), data.get("data", {}).get("stats", {})
    except Exception as e:
        log(f"API call failed: {e}")
        return [], {}

def get_status_map(reqs):
    return {r["key"]: r["status"] for r in reqs if "key" in r}

def run_browser_test(ref, email, name, scenario_name, expected_texts):
    log(f"Browser test: {scenario_name}")
    session_data = {
        "id": ref, "studentId": ref, "email": email,
        "name": name, "status": "ACTIVE", "must_change_password": False
    }
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(viewport={"width": 1400, "height": 900})
        page = ctx.new_page()
        page.goto(f"{BASE_URL}/student-portal/index.html")
        page.evaluate(f"sessionStorage.setItem('gncp_portal_student', JSON.stringify({json.dumps(session_data)}))")
        page.reload()
        page.wait_for_load_state("networkidle")
        time.sleep(2)

        # Switch to documents tab via Vue state (most reliable cross-nav method)
        page.evaluate("() => { const btn = [...document.querySelectorAll('button.nav-item')].find(b => b.textContent.toLowerCase().includes('document')); if (btn) btn.click(); }")
        time.sleep(2)

        ss_name = scenario_name.lower().replace(' ', '_').replace('/', '_')
        ss_path = os.path.join(ARTIFACT_DIR, f"req_status_{ss_name}.png")
        page.screenshot(path=ss_path, full_page=False)
        log(f"Screenshot: {ss_path}")

        page_text = page.inner_text("body")
        for expected in expected_texts:
            found = expected.lower() in page_text.lower()
            assert_check(f"[{scenario_name}] UI shows: '{expected}'", found)

        browser.close()

def run():
    log("=" * 60)
    log("Req Status Fix — Playwright Verification")
    log("=" * 60)

    db = get_db()
    student = get_test_student(db)
    if not student:
        log("No student record found. Cannot run tests.")
        db.close()
        sys.exit(1)

    ref = student["temp_student_id"]
    email = student["email"]
    name = f"{student['first_name']} {student['last_name']}"
    log(f"Student: {name} | ref={ref} | status={student['status']}")

    # Scenario 1: Format A, not yet verified parent
    log("\n-- Scenario 1: Format A, ORIGINAL/PHOTOCOPY/UNDERTAKING, parent PRE_REGISTERED --")
    inject_format_a(db, ref, {
        "form_138":          {"status": "ORIGINAL"},
        "psa_birth_cert":    {"status": "PHOTOCOPY"},
        "good_moral":        {"status": "UNDERTAKING", "deadline": "2026-11-01", "remarks": "Will bring by prelims"},
        "id_pictures":       {"status": "NOT_SUBMITTED"},
        "medical_clearance": {"status": "ORIGINAL"},
    }, parent_status="PRE_REGISTERED")

    reqs, stats = fetch_api_requirements(ref)
    sm = get_status_map(reqs)
    log(f"Status map: {sm}")
    log(f"Stats: {stats}")

    assert_check("Sc1: form_138 ORIGINAL -> UNDER_REVIEW", sm.get("form_138") == "UNDER_REVIEW", str(sm.get("form_138")))
    assert_check("Sc1: psa_birth_cert PHOTOCOPY -> UNDER_REVIEW", sm.get("psa_birth_cert") == "UNDER_REVIEW", str(sm.get("psa_birth_cert")))
    assert_check("Sc1: good_moral UNDERTAKING -> UNDERTAKING", sm.get("good_moral") == "UNDERTAKING", str(sm.get("good_moral")))
    assert_check("Sc1: id_pictures NOT_SUBMITTED -> NOT_SUBMITTED", sm.get("id_pictures") == "NOT_SUBMITTED", str(sm.get("id_pictures")))
    assert_check("Sc1: medical_clearance ORIGINAL -> UNDER_REVIEW", sm.get("medical_clearance") == "UNDER_REVIEW", str(sm.get("medical_clearance")))
    assert_check("Sc1: pendingReviewCount >= 3", stats.get("pendingReviewCount", 0) >= 3, str(stats))
    assert_check("Sc1: undertakingCount == 1", stats.get("undertakingCount", 0) == 1, str(stats))
    assert_check("Sc1: missingCount == 1", stats.get("missingCount", 0) == 1, str(stats))

    run_browser_test(ref, email, name, "Sc1 HardCopy", ["Under Review", "Hard Copy", "Conditional Undertaking", "Not Submitted"])

    # Scenario 2: Format A, parent VERIFIED
    log("\n-- Scenario 2: Format A, all ORIGINAL/PHOTOCOPY, parent VERIFIED --")
    inject_format_a(db, ref, {
        "form_138":          {"status": "ORIGINAL"},
        "psa_birth_cert":    {"status": "PHOTOCOPY"},
        "good_moral":        {"status": "ORIGINAL"},
        "id_pictures":       {"status": "PHOTOCOPY"},
        "medical_clearance": {"status": "ORIGINAL"},
    }, parent_status="VERIFIED")

    reqs, stats = fetch_api_requirements(ref)
    sm = get_status_map(reqs)
    log(f"Status map: {sm}")

    all_verified = all(sm.get(k) == "VERIFIED" for k in ["form_138","psa_birth_cert","good_moral","id_pictures","medical_clearance"])
    assert_check("Sc2: All required docs VERIFIED when parent=VERIFIED", all_verified, str(sm))
    assert_check("Sc2: verifiedCount >= 4", stats.get("verifiedCount", 0) >= 4, str(stats))
    assert_check("Sc2: isFullyCompliant=True", stats.get("isFullyCompliant") == True, str(stats))

    run_browser_test(ref, email, name, "Sc2 AllVerified", ["Verified & Approved"])

    # Scenario 3: Format B student softcopy upload
    log("\n-- Scenario 3: Format B softcopy upload --")
    inject_format_b(db, ref, [
        {"key": "form_138", "title": "Form 138", "description": "", "required": True,
         "status": "UNDER_REVIEW", "softCopyUrl": "/systemtest/uploads/documents/test_form138.pdf",
         "fileName": "form138.pdf", "fileType": "application/pdf", "fileSize": 50000,
         "submittedAt": "2026-09-01 10:00:00", "isUndertaking": False},
        {"key": "psa_birth_cert", "title": "PSA Birth Certificate", "description": "", "required": True,
         "status": "UNDERTAKING", "softCopyUrl": None, "isUndertaking": True,
         "undertakingReason": "Within 30 days", "undertakingDeadline": "2026-10-15"},
    ])

    reqs, stats = fetch_api_requirements(ref)
    sm = get_status_map(reqs)
    log(f"Status map: {sm}")

    assert_check("Sc3: form_138 Format B UNDER_REVIEW preserved", sm.get("form_138") == "UNDER_REVIEW", str(sm.get("form_138")))
    assert_check("Sc3: psa_birth_cert Format B UNDERTAKING preserved", sm.get("psa_birth_cert") == "UNDERTAKING", str(sm.get("psa_birth_cert")))
    assert_check("Sc3: good_moral not in B -> NOT_SUBMITTED", sm.get("good_moral") == "NOT_SUBMITTED", str(sm.get("good_moral")))

    form138 = next((r for r in reqs if r.get("key") == "form_138"), {})
    assert_check("Sc3: softCopyUrl preserved", bool(form138.get("softCopyUrl")), str(form138.get("softCopyUrl")))

    run_browser_test(ref, email, name, "Sc3 SoftCopy", ["Under Review", "Conditional Undertaking"])

    # Scenario 4: NULL requirements_data
    log("\n-- Scenario 4: NULL requirements_data --")
    with db.cursor() as cur:
        cur.execute("UPDATE pre_enrollments SET requirements_data = NULL WHERE temp_student_id = %s", (ref,))
    db.commit()

    reqs, stats = fetch_api_requirements(ref)
    sm = get_status_map(reqs)
    log(f"Status map: {sm}")

    all_missing = all(v == "NOT_SUBMITTED" for v in sm.values())
    assert_check("Sc4: All NOT_SUBMITTED when data is NULL", all_missing, str(sm))

    run_browser_test(ref, email, name, "Sc4 AllMissing", ["Not Submitted / Missing"])

    db.close()

    # Report
    log("\n" + "=" * 60)
    log("VERIFICATION REPORT")
    log("=" * 60)
    passed = sum(1 for r in results if r["status"] == PASS)
    failed = sum(1 for r in results if r["status"] == FAIL)
    for r in results:
        log(f"  {r['status']}  {r['label']}" + (f"\n         {r['detail']}" if r["detail"] and r["status"] == FAIL else ""))
    log(f"\nTotal: {passed} passed / {failed} failed / {len(results)} total")
    log("=" * 60)

    if failed > 0:
        sys.exit(1)

if __name__ == "__main__":
    run()
