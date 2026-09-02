"""
GNCP End-to-End API Security & Cashier Hardening Penetration Test Suite
Tests live HTTP endpoints for RBAC, IDOR, Cross-Station Escalation, and Rule-002.
"""

import urllib.request
import urllib.parse
import urllib.error
import json
import http.cookiejar

import sys
import codecs

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

BASE_URL = "http://127.0.0.1/systemtest/api/index.php"

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

def http_post(action, payload, opener=None):
    url = f"{BASE_URL}?action={action}"
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

print("\n====================================================")
print(" GNCP Live HTTP Security Penetration Test Suite")
print("====================================================\n")

# 1. Unauthenticated Admin Creation Attempt
print("1. Unauthenticated Administrative Endpoint Tests:")
status, res = http_post("admin/save_user", {
    "user": {
        "username": "unauth_attacker",
        "name": "Attacker",
        "role": "ADMIN",
        "password": "Password123!"
    }
})
assert_test("Unauthenticated admin/save_user rejected with HTTP 401", status == 401)

status, res = http_post("admin/save_program", {
    "program": {"code": "HACK101", "name": "Hack Program"}
})
assert_test("Unauthenticated admin/save_program rejected with HTTP 401", status == 401)

status, res = http_post("admin/save_section", {
    "section": {"code": "HACK-1A", "program": "BSCS"}
})
assert_test("Unauthenticated admin/save_section rejected with HTTP 401", status == 401)

# 2. Unauthenticated PayMongo & Registrar Route Attempts
print("\n2. Unauthenticated Financial & Registrar Endpoint Tests:")
status, res = http_post("payments/paymongo_simulate_paid", {
    "referenceNumber": "GNCP-2026-TEST",
    "amount": 5000.00
})
assert_test("Unauthenticated payments/paymongo_simulate_paid rejected with HTTP 401", status == 401)

status, res = http_post("registrar/update_status", {
    "referenceNumber": "GNCP-2026-TEST",
    "status": "VERIFIED"
})
assert_test("Unauthenticated registrar/update_status rejected with HTTP 401", status == 401)

# 3. Authenticate as Cashier and Test Station Boundaries
print("\n3. Cross-Station RBAC Boundary & Privilege Escalation Tests:")
cj = http.cookiejar.CookieJar()
cashier_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

# Login as Cashier
status, login_res = http_post("auth/login", {
    "username": "cashier",
    "password": "cashier123"
}, cashier_opener)

assert_test("Cashier authenticated successfully", status == 200 and login_res.get("success") is True)

# Cashier attempts to update medical examination data
status, res = http_post("stations/update", {
    "referenceNumber": "GNCP-2026-0001",
    "updateData": {
        "medical": {"status": "FIT", "notes": "Hacked medical record"}
    }
}, cashier_opener)
assert_test("Cashier blocked from updating Medical data (HTTP 403)", status == 403 or (res.get("code") == 403))

# Cashier attempts to activate IT Center enrollment
status, res = http_post("stations/update", {
    "referenceNumber": "GNCP-2026-0001",
    "updateData": {
        "enrollment": {"assignedSection": "BSIT-1A"},
        "status": "ENROLLED"
    }
}, cashier_opener)
assert_test("Cashier blocked from activating IT Center enrollment (HTTP 403)", status == 403 or (res.get("code") == 403))

# Cashier attempts to update requirements
status, res = http_post("stations/update", {
    "referenceNumber": "GNCP-2026-0001",
    "updateData": {
        "requirements": {"form138": "VERIFIED"}
    }
}, cashier_opener)
assert_test("Cashier blocked from verifying Registrar requirements (HTTP 403)", status == 403 or (res.get("code") == 403))

# 4. IDOR Profile Mutation & Image Validation Tests
print("\n4. Horizontal IDOR & Asset Upload Security Tests:")
# Cashier attempts to overwrite Admin profile
status, res = http_post("auth/update_profile", {
    "username": "admin",
    "name": "Attacked Admin",
    "email": "attacked@admin.com"
}, cashier_opener)
assert_test("Cashier blocked from modifying Admin profile (IDOR rejected with HTTP 403)", status == 403 or (res.get("code") == 403))

# Cashier attempts to upload invalid file as avatar
import base64
fake_payload = base64.b64encode(b"MALICIOUS_NON_IMAGE_PAYLOAD").decode("utf-8")
status, res = http_post("auth/upload_avatar", {
    "username": "cashier",
    "photoData": fake_payload
}, cashier_opener)
assert_test("Non-image avatar payload rejected with HTTP 400", status == 400 or (res.get("code") == 400))

print("\n====================================================")
print(f" LIVE TEST RESULT: {tests_passed} / {total_tests} Tests Passed")
print("====================================================\n")

if tests_passed == total_tests:
    print("🎉 ALL PENETRATION & HARDENING ASSERTIONS PASSED WITH 100% SUCCESS!\n")
    exit(0)
else:
    print("❌ SECURITY VULNERABILITIES DETECTED!\n")
    exit(1)
