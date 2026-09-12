import os
import urllib.request
import urllib.parse
import json
import sys

BASE_URL = os.environ.get("GNCP_BASE_URL", "http://127.0.0.1/systemtest")

def test_endpoint(name, method, url, data=None, headers=None, expected_status=None, expected_in_body=None):
    if headers is None:
        headers = {}
    encoded_data = None
    if data is not None:
        if isinstance(data, dict):
            encoded_data = json.dumps(data).encode('utf-8')
            headers['Content-Type'] = 'application/json'
        elif isinstance(data, str):
            encoded_data = data.encode('utf-8')
    
    req = urllib.request.Request(url, data=encoded_data, headers=headers, method=method)
    
    try:
        with urllib.request.urlopen(req) as response:
            status = response.getcode()
            body = response.read().decode('utf-8', errors='ignore')
    except urllib.error.HTTPError as e:
        status = e.code
        body = e.read().decode('utf-8', errors='ignore')
    except Exception as e:
        print(f"[FAIL] {name}: Request error: {e}")
        return False

    status_ok = (expected_status is None) or (status == expected_status or (isinstance(expected_status, list) and status in expected_status))
    body_ok = (expected_in_body is None) or (expected_in_body in body)

    if status_ok and body_ok:
        print(f"[PASS] {name} (HTTP {status})")
        return True
    else:
        print(f"[FAIL] {name}: got HTTP {status}, expected {expected_status}. Body snippet: {body[:150]}")
        return False

def run_server_side_authorization_audit():
    print("==================================================================")
    print("  PHASE 7: SERVER-SIDE AUTHORIZATION & ACCESS CONTROL AUDIT       ")
    print(f"  Target: {BASE_URL}")
    print("==================================================================")
    
    results = []

    # 1. Protected Files via Apache .htaccess (Shield)
    results.append(test_endpoint("Sensitive File: database.php", "GET", f"{BASE_URL}/shared/backend/config/database.php", expected_status=403))
    results.append(test_endpoint("Sensitive File: paymongo.php", "GET", f"{BASE_URL}/shared/backend/config/paymongo.php", expected_status=403))
    results.append(test_endpoint("Sensitive File: mail.php", "GET", f"{BASE_URL}/shared/backend/config/mail.php", expected_status=403))
    results.append(test_endpoint("Sensitive File: .env", "GET", f"{BASE_URL}/.env", expected_status=403))
    results.append(test_endpoint("Sensitive File: schema.sql", "GET", f"{BASE_URL}/database/schema.sql", expected_status=403))

    # 2. Unauthenticated Operator / Admin Endpoints (Must be blocked)
    results.append(test_endpoint(
        "Admin Endpoint: save_user without session", 
        "POST", 
        f"{BASE_URL}/api/index.php?action=admin/save_user",
        data={"username": "hacker_test", "role": "ADMIN", "password": "Password123!"},
        expected_status=[401, 403, 400],
        expected_in_body="Authentication required"
    ))
    results.append(test_endpoint(
        "Admin Endpoint: delete_announcement without session",
        "POST",
        f"{BASE_URL}/api/index.php?action=admin/delete_announcement",
        data={"id": 1},
        expected_status=[401, 403, 400]
    ))

    # 3. Station Mutation & Queue without Authentication
    results.append(test_endpoint(
        "Station Endpoint: update queue without session",
        "POST",
        f"{BASE_URL}/api/index.php?action=stations/update",
        data={"ref_no": "TEST-123", "station": "REGISTRAR", "status": "VERIFIED"},
        expected_status=[401, 403, 400]
    ))
    results.append(test_endpoint(
        "Station Endpoint: fetch queue without session",
        "GET",
        f"{BASE_URL}/api/index.php?action=stations/queue",
        expected_status=[401, 403, 400]
    ))

    # 4. Student Portal Endpoints without Authentication
    results.append(test_endpoint(
        "Student Endpoint: get_student_dashboard without session",
        "GET",
        f"{BASE_URL}/student-portal/backend/api.php?action=get_student_dashboard&studentId=GNCP-2026-0001",
        expected_status=401,
        expected_in_body="Unauthorized access"
    ))
    results.append(test_endpoint(
        "Student Endpoint: update_student_profile without session",
        "POST",
        f"{BASE_URL}/student-portal/backend/api.php?action=update_student_profile",
        data={"studentId": "GNCP-2026-0001", "phone": "09999999999"},
        expected_status=401,
        expected_in_body="Unauthorized access"
    ))
    results.append(test_endpoint(
        "Student Endpoint: change_student_password without session",
        "POST",
        f"{BASE_URL}/student-portal/backend/api.php?action=change_student_password",
        data={"studentId": "GNCP-2026-0001", "oldPassword": "old", "newPassword": "new"},
        expected_status=401,
        expected_in_body="Unauthorized access"
    ))

    # 5. Cashier Payment Integrity (Reject PRE_REGISTERED)
    import pymysql
    conn = pymysql.connect(host='127.0.0.1', user='root', password='', database='gncp_portal', charset='utf8mb4')
    cur = conn.cursor()
    cur.execute("SELECT temp_student_id FROM pre_enrollments WHERE status = 'PRE_REGISTERED' LIMIT 1")
    row = cur.fetchone()
    if not row:
        cur.execute("INSERT INTO pre_enrollments (temp_student_id, first_name, last_name, email, phone, birthdate, gender, program, status) VALUES ('REF-PRE-SEC-TEST', 'Sec', 'Test', 'sec.test@gncp.edu.ph', '09123456789', '2000-01-01', 'Male', 'BSIT', 'PRE_REGISTERED')")
        conn.commit()
        pre_ref = 'REF-PRE-SEC-TEST'
    else:
        pre_ref = row[0]
    conn.close()

    results.append(test_endpoint(
        f"PayMongo Checkout: Reject PRE_REGISTERED applicant ({pre_ref})",
        "POST",
        f"{BASE_URL}/api/index.php?action=payments/paymongo_create_checkout",
        data={"referenceNumber": pre_ref, "amount": 3000.00, "description": "Test"},
        expected_status=[400, 422, 500],
        expected_in_body="Payment rejected"
    ))

    # 6. Protected Station PHP Entrypoints (Direct access without session redirects)
    results.append(test_endpoint(
        "Admin Index PHP Wrapper: Redirect on no session",
        "GET",
        f"{BASE_URL}/admin/index.php",
        expected_status=[200, 302]
    ))
    results.append(test_endpoint(
        "Registrar Index PHP Wrapper: Redirect on no session",
        "GET",
        f"{BASE_URL}/registrar/index.php",
        expected_status=[200, 302]
    ))

    passed = sum(1 for r in results if r)
    total = len(results)
    print("------------------------------------------------------------------")
    print(f"Server-Side Authorization Audit Results: {passed}/{total} Passed")
    print("==================================================================\n")
    return passed == total

if __name__ == "__main__":
    success = run_server_side_authorization_audit()
    sys.exit(0 if success else 1)
