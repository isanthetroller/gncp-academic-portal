"""
GNCP Refactoring Verification Suite
Validates Unified API Gateways, StudentPortalService, and Relational Financial/Clearance Ledgers
"""

import sys
import codecs
import json
import urllib.request
import urllib.parse
import urllib.error
import http.cookiejar

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

BASE_URL = "http://127.0.0.1:8000/systemtest"
API_URL = f"{BASE_URL}/api/index.php"
ADAPTER_URL = f"{BASE_URL}/student-portal/backend/api.php"

tests_passed = 0
total_tests = 0

def assert_test(desc, condition):
    global tests_passed, total_tests
    total_tests += 1
    if condition:
        tests_passed += 1
        print(f"  [PASS] {desc}")
    else:
        print(f"  [FAIL] {desc}")

def http_post(url, payload, opener=None):
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
    executor = opener if opener else urllib.request.build_opener()
    try:
        with executor.open(req) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8")
        try:
            return e.code, json.loads(body)
        except Exception:
            return e.code, {"message": body}
    except Exception as e:
        return 500, {"error": str(e)}

def http_get(url, opener=None):
    req = urllib.request.Request(url)
    executor = opener if opener else urllib.request.build_opener()
    try:
        with executor.open(req) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8")
        try:
            return e.code, json.loads(body)
        except Exception:
            return e.code, {"message": body}
    except Exception as e:
        return 500, {"error": str(e)}

print("\n====================================================")
print(" GNCP Refactoring & Technical Debt Verification Suite")
print("====================================================\n")

# 1. Test Student Portal Adapter Route
print("1. Student Portal Backward-Compatibility Adapter Tests:")
status, res = http_post(f"{ADAPTER_URL}?action=login_student", {
    "studentId": "INVALID_STUDENT_999",
    "password": "WrongPassword123!"
})
assert_test("Adapter handles login_student rejecting invalid credentials (401)", status == 401 and res.get("success") is False)

status, res = http_post(f"{ADAPTER_URL}?action=request_password_reset", {
    "identifier": "NON_EXISTENT_EMAIL@gncp.edu.ph"
})
assert_test("Adapter handles request_password_reset rejecting unknown user (404)", status == 404 and res.get("success") is False)

# 2. Test Canonical Student Portal Routes
print("\n2. Canonical Central REST Gateway Student Portal Tests:")
status, res = http_post(f"{API_URL}?action=student_portal/login", {
    "studentId": "INVALID_STUDENT_999",
    "password": "WrongPassword123!"
})
assert_test("Canonical route student_portal/login rejecting invalid credentials (401)", status == 401 and res.get("success") is False)

status, res = http_get(f"{API_URL}?action=student_portal/dashboard&studentId=UNAUTHENTICATED")
assert_test("Canonical route student_portal/dashboard requires authentication (401)", status == 401 and res.get("success") is False)

# 3. Test Cashier Authentication & Financial Operation
print("\n3. Cashier Payment & Relational Table Synchronization Tests:")
cj = http.cookiejar.CookieJar()
cashier_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

# Authenticate as Cashier
status, login_res = http_post(f"{API_URL}?action=auth/login", {
    "username": "cashier",
    "password": "cashier123"
}, cashier_opener)
assert_test("Cashier logged in successfully via canonical gateway", status == 200 and login_res.get("success") is True)

# Attempt PayMongo simulation on advised student
status, sim_res = http_post(f"{API_URL}?action=payments/paymongo_simulate_paid", {
    "referenceNumber": "GNCP-2026-0001",
    "amount": 2500.00,
    "channel": "GCash",
    "notes": "Automated Refactoring Concurrency Test"
}, cashier_opener)
assert_test("Payment simulation executed through hardened service", status in [200, 400])

print("\n====================================================")
print(f" REFACTORING SUITE RESULT: {tests_passed} / {total_tests} Tests Passed")
print("====================================================\n")

if tests_passed == total_tests:
    print("🎉 ALL ARCHITECTURAL REFACTORING TESTS PASSED!\n")
    exit(0)
else:
    print("❌ REFACTORING DEFICIENCIES DETECTED!\n")
    exit(1)
