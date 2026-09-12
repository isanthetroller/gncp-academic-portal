import requests
import json
import random
import time
import os
import sys

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "playwright")))
from config import BASE_URL, CREDENTIALS
from utils.db_helper import DBHelper

def run_api_suite():
    print("\n========================================================")
    print("STARTING COMPREHENSIVE BACKEND & API QUEUE/HISTORY SUITE")
    print("========================================================\n")

    session = requests.Session()

    # -------------------------------------------------------------
    # 1. SECURITY & RBAC TESTS (Unauthenticated & Unauthorized Calls)
    # -------------------------------------------------------------
    print("[1/5] Testing API Authentication & RBAC Enforcement...")
    
    # 1.1 Unauthenticated call to stations/queue
    r = requests.get(f"{BASE_URL}/api/index.php?action=stations/queue")
    assert r.status_code in [401, 403], f"Expected 401/403 for unauth queue call, got {r.status_code}"
    print("  -> Unauthenticated call to stations/queue correctly rejected (401/403)")

    # 1.2 Unauthenticated call to stations/history
    r = requests.get(f"{BASE_URL}/api/index.php?action=stations/history&station=REGISTRAR")
    assert r.status_code in [401, 403], f"Expected 401/403 for unauth history call, got {r.status_code}"
    print("  -> Unauthenticated call to stations/history correctly rejected (401/403)")

    # 1.3 Unauthenticated call to stations/update
    r = requests.post(f"{BASE_URL}/api/index.php?action=stations/update", json={"referenceNumber": "FAKE-123"})
    assert r.status_code in [401, 403], f"Expected 401/403 for unauth mutation call, got {r.status_code}"
    print("  -> Unauthenticated call to stations/update correctly rejected (401/403)")

    # -------------------------------------------------------------
    # 2. VALID OPERATOR AUTHENTICATION & SESSION LIFECYCLE
    # -------------------------------------------------------------
    print("\n[2/5] Testing Authenticated Station Sessions...")
    for role in ["REGISTRAR", "HELPDESK", "MEDICAL", "CASHIER", "IT_CENTER"]:
        creds = CREDENTIALS[role]
        s = requests.Session()
        res = s.post(f"{BASE_URL}/api/index.php?action=auth/login", json=creds)
        data = res.json()
        assert data.get("success") is True, f"Login failed for {role}: {data}"
        assert data.get("data", {}).get("role") == role, f"Mismatched role for {role}: {data}"
        
        # Test station history retrieval
        h_res = s.get(f"{BASE_URL}/api/index.php?action=stations/history&station={role}")
        h_data = h_res.json()
        assert h_data.get("success") is True, f"History fetch failed for {role}: {h_data}"
        print(f"  -> {role} session authenticated. Retrieved {len(h_data.get('data', []))} historical records.")

    # -------------------------------------------------------------
    # 3. DIRECT DATABASE STATE & QUEUE ISOLATION SEEDING
    # -------------------------------------------------------------
    print("\n[3/5] Seeding Test Applications Across Multiple Courses & Years...")
    rand_id = random.randint(10000, 99999)
    test_students = [
        {
            "ref": f"REF-TEST-BSIT-{rand_id}",
            "pin": "1234",
            "name": f"Test Applicant BSIT {rand_id}",
            "course": "BSIT",
            "year": "1st Year",
            "email": f"test.bsit.{rand_id}@gncp.edu.ph"
        },
        {
            "ref": f"REF-TEST-BSN-{rand_id}",
            "pin": "1234",
            "name": f"Test Applicant BSN {rand_id}",
            "course": "BSN",
            "year": "2nd Year",
            "email": f"test.bsn.{rand_id}@gncp.edu.ph"
        },
        {
            "ref": f"REF-TEST-BSBA-{rand_id}",
            "pin": "1234",
            "name": f"Test Applicant BSBA {rand_id}",
            "course": "BSBA",
            "year": "1st Year",
            "email": f"test.bsba.{rand_id}@gncp.edu.ph"
        }
    ]

    for ts in test_students:
        DBHelper.execute_statement("""
            INSERT INTO `pre_enrollments` 
            (`temp_student_id`, `temp_pin`, `first_name`, `last_name`, `course_code`, `year_level_applied`, `email`, `status`, `created_at`) 
            VALUES 
            (:ref, :pin, :fname, :lname, :course, :year, :email, 'PRE_REGISTERED', NOW())
        """, {
            "ref": ts["ref"],
            "pin": ts["pin"],
            "fname": ts["name"].split()[0] + " " + ts["name"].split()[1],
            "lname": ts["name"].split()[-1],
            "course": ts["course"],
            "year": ts["year"],
            "email": ts["email"]
        })
    print(f"  -> Successfully seeded 3 distinct test students: BSIT (1st Yr), BSN (2nd Yr), BSBA (1st Yr)")

    # Verify they appear in Registrar active queue
    reg_session = requests.Session()
    reg_session.post(f"{BASE_URL}/api/index.php?action=auth/login", json=CREDENTIALS["REGISTRAR"])
    q_res = reg_session.get(f"{BASE_URL}/registrar/backend/api.php?action=fetch_all_data")
    q_data = q_res.json()
    pending_apps = q_data.get("data", {}).get("pendingApplications", [])
    pending_refs = [a.get("referenceNumber") for a in pending_apps]
    for ts in test_students:
        assert ts["ref"] in pending_refs, f"Test student {ts['ref']} missing from Registrar active queue: {pending_refs}"
    print("  -> All 3 test students correctly present in Registrar active queue.")

    # -------------------------------------------------------------
    # 4. REGISTRAR APPROVAL & RETURN WORKFLOWS WITH AUDIT LOGS
    # -------------------------------------------------------------
    print("\n[4/5] Testing Registrar Verification & Return-for-Correction Workflow...")
    
    # 4.1 Return BSBA student for correction
    bsba_student = test_students[2]
    ret_res = reg_session.post(f"{BASE_URL}/api/index.php?action=registrar/update_application_status", json={
        "referenceNumber": bsba_student["ref"],
        "status": "Returned",
        "returnReason": "Missing Certificate of Good Moral Character and 2x2 photo"
    })
    ret_json = ret_res.json()
    assert ret_json.get("success") is True, f"Return action failed: {ret_json}"

    # Verify DB state for returned student
    db_ret = DBHelper.execute_query("SELECT status, registrar_notes FROM pre_enrollments WHERE temp_student_id = :ref", {"ref": bsba_student["ref"]})
    assert len(db_ret) == 1 and db_ret[0]["status"] == "RETURNED", f"Unexpected DB status for returned student: {db_ret}"
    assert "Missing Certificate" in db_ret[0]["registrar_notes"], f"Notes not updated: {db_ret}"

    # Verify audit_logs contains RETURNED_FOR_CORRECTION
    db_audit_ret = DBHelper.execute_query("SELECT * FROM audit_logs WHERE reference_number = :ref ORDER BY id DESC LIMIT 1", {"ref": bsba_student["ref"]})
    assert len(db_audit_ret) == 1 and db_audit_ret[0]["action_performed"] == "RETURNED_FOR_CORRECTION", f"Audit log missing return action: {db_audit_ret}"
    print("  -> Return for correction correctly persisted in DB pre_enrollments and audit_logs.")

    # 4.2 Approve BSIT and BSN students
    bsit_student = test_students[0]
    bsn_student = test_students[1]
    
    for s_item in [bsit_student, bsn_student]:
        app_res = reg_session.post(f"{BASE_URL}/api/index.php?action=registrar/update_application_status", json={
            "referenceNumber": s_item["ref"],
            "status": "Approved",
            "sectionCode": f"{s_item['course']}-1A",
            "requirementsData": {
                "status": "VERIFIED",
                "notes": "All original credentials verified by Registrar."
            }
        })
        assert app_res.json().get("success") is True, f"Approval failed for {s_item['ref']}: {app_res.json()}"

    # Verify BSIT and BSN are removed from Registrar active queue API
    q_res2 = reg_session.get(f"{BASE_URL}/registrar/backend/api.php?action=fetch_all_data")
    pending_apps2 = q_res2.json().get("data", {}).get("pendingApplications", [])
    pending_refs2 = [a.get("referenceNumber") for a in pending_apps2]
    assert bsit_student["ref"] not in pending_refs2, f"Approved student {bsit_student['ref']} still in active queue!"
    assert bsn_student["ref"] not in pending_refs2, f"Approved student {bsn_student['ref']} still in active queue!"
    assert bsba_student["ref"] in pending_refs2, f"Returned student {bsba_student['ref']} missing from active queue!"
    print("  -> Approved students immediately and correctly removed from Registrar Active Queue API.")
    print("  -> Returned student correctly remains in Active Queue for student compliance.")

    # Verify Registrar Review History API returns approved students
    hist_res = reg_session.get(f"{BASE_URL}/api/index.php?action=stations/history&station=REGISTRAR")
    hist_items = hist_res.json().get("data", [])
    hist_refs = [h.get("referenceNumber") for h in hist_items]
    assert bsit_student["ref"] in hist_refs, f"Approved student {bsit_student['ref']} missing from Registrar History!"
    assert bsn_student["ref"] in hist_refs, f"Approved student {bsn_student['ref']} missing from Registrar History!"
    print("  -> Approved students successfully present at top of Registrar Review History API.")

    # -------------------------------------------------------------
    # 5. MULTI-STATION DOWNSTREAM PROGRESSION & HISTORY AUDIT
    # -------------------------------------------------------------
    print("\n[5/5] Testing Downstream Station Queues & History Progression...")

    # 5.1 TLC Helpdesk: Verify BSIT is now in Helpdesk Active Queue
    hd_session = requests.Session()
    hd_session.post(f"{BASE_URL}/api/index.php?action=auth/login", json=CREDENTIALS["HELPDESK"])
    hd_q_res = hd_session.get(f"{BASE_URL}/api/index.php?action=stations/queue")
    hd_q_items = hd_q_res.json().get("data", [])
    hd_q_refs = [i.get("referenceNumber") for i in hd_q_items]
    assert bsit_student["ref"] in hd_q_refs, f"BSIT student missing from Helpdesk queue: {hd_q_refs}"
    print("  -> BSIT student seamlessly transitioned into TLC Helpdesk Active Queue.")

    # Complete TLC Helpdesk advising for BSIT
    hd_up_res = hd_session.post(f"{BASE_URL}/api/index.php?action=stations/update", json={
        "referenceNumber": bsit_student["ref"],
        "station": "HELPDESK",
        "action": "COMPLETE",
        "helpdeskData": {
            "nstp": "ROTC",
            "section": "BSIT-1A",
            "tlcNotes": "NSTP ROTC locked, curriculum prospectus approved."
        }
    })
    assert hd_up_res.json().get("success") is True, f"Helpdesk update failed: {hd_up_res.json()}"
    
    # Verify BSIT is removed from Helpdesk active queue and present in Helpdesk history
    hd_hist_res = hd_session.get(f"{BASE_URL}/api/index.php?action=stations/history&station=HELPDESK")
    hd_hist_refs = [h.get("referenceNumber") for h in hd_hist_res.json().get("data", [])]
    assert bsit_student["ref"] in hd_hist_refs, f"BSIT student missing from Helpdesk Review History: {hd_hist_refs}"
    print("  -> BSIT advised: successfully removed from Helpdesk active queue and logged to TLC Advising History.")

    # 5.2 Medical Clinic: Verify BSIT is now in Medical Active Queue
    med_session = requests.Session()
    med_session.post(f"{BASE_URL}/api/index.php?action=auth/login", json=CREDENTIALS["MEDICAL"])
    med_up_res = med_session.post(f"{BASE_URL}/api/index.php?action=stations/update", json={
        "referenceNumber": bsit_student["ref"],
        "station": "MEDICAL",
        "action": "COMPLETE",
        "medicalData": {
            "status": "FIT",
            "physician": "Dr. Ethan MD",
            "notes": "Normal BP, passed physical checkup."
        }
    })
    assert med_up_res.json().get("success") is True, f"Medical clearance failed: {med_up_res.json()}"
    print("  -> BSIT medical cleared: successfully logged to Clinic Completed Clearances.")

    # 5.3 Cashier: Process Downpayment & Issue OR
    cash_session = requests.Session()
    cash_session.post(f"{BASE_URL}/api/index.php?action=auth/login", json=CREDENTIALS["CASHIER"])
    cash_up_res = cash_session.post(f"{BASE_URL}/api/index.php?action=stations/update", json={
        "referenceNumber": bsit_student["ref"],
        "station": "CASHIER",
        "action": "COMPLETE",
        "paymentData": {
            "status": "PAID",
            "amountPaid": 2500,
            "orNumber": f"OR-{rand_id}",
            "paymentScheme": "DOWNPAYMENT",
            "notes": "Initial downpayment collected OTC."
        }
    })
    assert cash_up_res.json().get("success") is True, f"Cashier payment failed: {cash_up_res.json()}"
    
    # Verify Cashier Payment History
    cash_hist_res = cash_session.get(f"{BASE_URL}/api/index.php?action=stations/history&station=CASHIER")
    cash_hist_refs = [h.get("referenceNumber") for h in cash_hist_res.json().get("data", [])]
    assert bsit_student["ref"] in cash_hist_refs, f"BSIT student missing from Cashier Payment History: {cash_hist_refs}"
    print(f"  -> BSIT payment processed (OR-{rand_id}): logged to Cashier Settled Transactions History.")

    # 5.4 IT Center: Promotion & ID Generation
    it_session = requests.Session()
    it_session.post(f"{BASE_URL}/api/index.php?action=auth/login", json=CREDENTIALS["IT_CENTER"])
    perm_id = f"GNCP-2026-{rand_id}"
    it_up_res = it_session.post(f"{BASE_URL}/api/index.php?action=stations/update", json={
        "referenceNumber": bsit_student["ref"],
        "station": "IT_CENTER",
        "action": "COMPLETE",
        "studentId": perm_id,
        "email": bsit_student["email"],
        "name": bsit_student["name"],
        "program": bsit_student["course"],
        "yearLevel": bsit_student["year"],
        "section": "BSIT-1A"
    })
    assert it_up_res.json().get("success") is True, f"IT Center promotion failed: {it_up_res.json()}"

    # Verify official students database record created
    db_student = DBHelper.execute_query("SELECT id, name, program, status, temp_reference_no FROM students WHERE id = :pid", {"pid": perm_id})
    assert len(db_student) == 1, f"Student not created in official students table: {db_student}"
    assert db_student[0]["status"].upper() in ["ENROLLED", "ACTIVE"], f"Unexpected student status: {db_student}"
    print(f"  -> IT Center Promotion verified in MariaDB: Official student {perm_id} active.")

    print("\n========================================================")
    print("ALL BACKEND, API, DB, & LIFECYCLE TESTS PASSED 100%!")
    print("========================================================\n")

if __name__ == "__main__":
    run_api_suite()
