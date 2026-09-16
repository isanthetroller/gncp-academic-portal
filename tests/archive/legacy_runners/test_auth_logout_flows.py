"""
End-to-End Headless Verification Suite for Authentication & Logout Flows
Tests all user roles (Super Admin, Station Operators, Student Portal) across login,
session protection, logout invalidation, and post-logout access denial.
"""

import requests
import subprocess
import sys

BASE_URL = "http://localhost/systemtest"

def run_tests():
    passed = 0
    failed = 0

    def assert_test(condition, name, details=""):
        nonlocal passed, failed
        if condition:
            print(f"  [PASS] {name}")
            passed += 1
        else:
            print(f"  [FAIL] {name} - {details}")
            failed += 1

    print("\n=======================================================")
    print("  GNCP AUTHENTICATION & LOGOUT END-TO-END VERIFICATION")
    print("=======================================================\n")

    # -------------------------------------------------------------------------
    # TEST 1: Super Admin Login & Logout via api/index.php?action=auth/logout
    # -------------------------------------------------------------------------
    print("--- 1. Super Admin Authentication & Logout Flow ---")
    session = requests.Session()

    login_res = session.post(f"{BASE_URL}/shared/backend/login.php", json={
        "username": "admin",
        "password": "admin12345"
    })
    assert_test(login_res.status_code == 200, "Super Admin Login HTTP 200", f"Status: {login_res.status_code}")
    login_data = login_res.json()
    assert_test(login_data.get("success") is True, "Super Admin Login Success Flag", str(login_data))
    assert_test(login_data.get("data", {}).get("role") == "SUPER_ADMIN", "Super Admin Role Assertion", str(login_data))

    # Access protected profile
    prof_res = session.get(f"{BASE_URL}/api/index.php?action=auth/profile")
    assert_test(prof_res.status_code == 200, "Protected Profile Access While Authenticated", f"Status: {prof_res.status_code}")

    # Logout
    logout_res = session.post(f"{BASE_URL}/api/index.php?action=auth/logout")
    assert_test(logout_res.status_code == 200, "Super Admin Logout HTTP 200", f"Status: {logout_res.status_code}")
    assert_test(logout_res.json().get("success") is True, "Super Admin Logout Success Flag", str(logout_res.json()))

    # Verify session termination
    post_prof_res = session.get(f"{BASE_URL}/api/index.php?action=auth/profile")
    prof_json = post_prof_res.json()
    assert_test(prof_json.get("success") is False or post_prof_res.status_code == 401, "Protected Profile Access Denied After Logout", str(prof_json))

    # -------------------------------------------------------------------------
    # TEST 2: Station Operators Login & Logout
    # -------------------------------------------------------------------------
    print("\n--- 2. Station Operators Authentication & Logout Flows ---")
    operators = [
        ("Registrar", "kriz", "kriz123", "REGISTRAR"),
        ("Helpdesk", "tristan", "tristan123", "HELPDESK"),
        ("Medical Doctor", "ethan", "ethan123", "MEDICAL"),
        ("Cashier", "cashier", "cashier123", "CASHIER"),
        ("IT Center", "it_officer", "itpassword", "IT_CENTER"),
    ]

    for label, username, password, expected_role in operators:
        op_sess = requests.Session()
        res = op_sess.post(f"{BASE_URL}/shared/backend/login.php", json={
            "username": username,
            "password": password
        })
        assert_test(res.status_code == 200, f"{label} ({username}) Login HTTP 200", f"Status: {res.status_code}")
        data = res.json()
        assert_test(data.get("data", {}).get("role") == expected_role, f"{label} Role Assertion == {expected_role}", str(data))

        # Logout via shared login.php?action=logout
        l_res = op_sess.get(f"{BASE_URL}/shared/backend/login.php?action=logout")
        assert_test(l_res.status_code == 200, f"{label} login.php?action=logout HTTP 200", f"Status: {l_res.status_code}")
        assert_test(l_res.json().get("success") is True, f"{label} Logout Success Flag", str(l_res.json()))

    # -------------------------------------------------------------------------
    # TEST 3: Student Portal Authentication & Logout Flow
    # -------------------------------------------------------------------------
    print("\n--- 3. Student Portal Authentication & Logout Flow ---")
    # Provision a clean test student account in MariaDB
    test_sid = "TEST-LOGOUT-2026"
    test_pass = "StudentPass123!"
    hash_cmd = f"C:\\xampp\\php\\php.exe -r \"echo password_hash('{test_pass}', PASSWORD_DEFAULT);\""
    hashed_pw = subprocess.check_output(hash_cmd, shell=True, text=True).strip()

    sql = f"""
    REPLACE INTO students (id, name, program, email, password, year_level, status)
    VALUES ('{test_sid}', 'Test Auth Student', 'Bachelor of Science in Information Technology', 'test.auth@gncp.edu.ph', '{hashed_pw}', '1st Year', 'Active');
    """
    subprocess.run(["C:\\xampp\\mysql\\bin\\mysql.exe", "-u", "root", "gncp_portal", "-e", sql], check=True)

    stud_sess = requests.Session()
    s_login_res = stud_sess.post(f"{BASE_URL}/student-portal/backend/api.php?action=login_student", json={
        "studentId": test_sid,
        "password": test_pass
    })
    assert_test(s_login_res.status_code == 200, f"Student Login for {test_sid} HTTP 200", f"Status: {s_login_res.status_code}")
    s_login_data = s_login_res.json()
    assert_test(s_login_data.get("success") is True, "Student Login Success Flag", str(s_login_data))

    # Fetch Dashboard while authenticated
    dash_res = stud_sess.get(f"{BASE_URL}/student-portal/backend/api.php?action=get_student_dashboard&studentId={test_sid}")
    assert_test(dash_res.status_code == 200 and dash_res.json().get("success"), "Student Protected Dashboard Fetch Success", str(dash_res.json()))

    # Student Logout
    s_logout_res = stud_sess.post(f"{BASE_URL}/student-portal/backend/api.php?action=logout")
    assert_test(s_logout_res.status_code == 200, "Student Logout HTTP 200", f"Status: {s_logout_res.status_code}")
    assert_test(s_logout_res.json().get("success") is True, "Student Logout Success Flag", str(s_logout_res.json()))

    # Clean up test student
    subprocess.run(["C:\\xampp\\mysql\\bin\\mysql.exe", "-u", "root", "gncp_portal", "-e", f"DELETE FROM students WHERE id = '{test_sid}';"], check=True)

    # -------------------------------------------------------------------------
    # TEST 4: Invalid Credentials & 401 Protection
    # -------------------------------------------------------------------------
    print("\n--- 4. Unauthenticated & Invalid Credential Security ---")
    bad_sess = requests.Session()
    bad_res = bad_sess.post(f"{BASE_URL}/shared/backend/login.php", json={
        "username": "fake_user",
        "password": "wrong_password"
    })
    assert_test(bad_res.status_code == 401, "Invalid User Rejected with HTTP 401", f"Status: {bad_res.status_code}")

    print("\n=======================================================")
    print(f"  TEST SUMMARY: {passed} PASSED, {failed} FAILED")
    print("=======================================================\n")
    return failed == 0

if __name__ == '__main__':
    success = run_tests()
    sys.exit(0 if success else 1)
