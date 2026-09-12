import requests
import json
import sys

BASE_URL = "http://localhost/systemtest"
SESSION = requests.Session()

def test_login(username, password, expected_role):
    print(f"\n[TEST] Logging in as {username}...")
    res = SESSION.post(f"{BASE_URL}/api/index.php?action=auth/login", json={
        "username": username,
        "password": password
    })
    try:
        data = res.json()
        assert data.get("success") is True, f"Login failed: {data}"
        role = data.get("data", {}).get("role")
        print(f"  -> Successfully logged in as {username} (Role: {role})")
        return data.get("data")
    except Exception as e:
        print(f"  -> FAILED to parse login response: {res.status_code} {res.text}")
        raise e

def test_fetch_station_history(station_role):
    print(f"\n[TEST] Fetching history for station {station_role}...")
    res = SESSION.get(f"{BASE_URL}/api/index.php?action=stations/history&station={station_role}")
    assert res.status_code == 200, f"HTTP Error {res.status_code}: {res.text}"
    data = res.json()
    assert data.get("success") is True, f"API failed: {data}"
    records = data.get("data", [])
    print(f"  -> Successfully retrieved {len(records)} history records for {station_role}")
    if len(records) > 0:
        sample = records[0]
        print(f"  -> Sample Record: Ref={sample.get('referenceNumber')}, Name={sample.get('studentName')}, Action={sample.get('actionPerformed')}, By={sample.get('operatorUsername')}, Time={sample.get('completedAt')}")
    return records

def test_registrar_queue_filtering():
    print(f"\n[TEST] Verifying Registrar Queue filtering via API...")
    res = SESSION.get(f"{BASE_URL}/registrar/backend/api.php?action=fetch_all_data")
    assert res.status_code == 200, f"HTTP Error {res.status_code}: {res.text}"
    data = res.json()
    assert data.get("success") is True, f"API failed: {data}"
    apps = data.get("data", {}).get("pendingApplications", [])
    print(f"  -> Total pending applications retrieved: {len(apps)}")
    for a in apps:
        status = str(a.get("status", "")).upper()
        # Active queue should strictly be PRE_REGISTERED, PENDING, RETURNED, NEEDS_CORRECTION
        assert status in ['PRE_REGISTERED', 'PENDING', 'RETURNED', 'NEEDS_CORRECTION'], f"Unexpected status in active pending applications: {status} for {a.get('referenceNumber')}"
    print(f"  -> All {len(apps)} pending applications in queue have valid active review statuses!")

def main():
    try:
        # 1. Login as Admin / Super Admin to test all stations
        test_login("admin", "admin12345", "ADMIN")
        
        # 2. Test Station History for all roles
        for role in ["REGISTRAR", "HELPDESK", "MEDICAL", "CASHIER", "IT_CENTER"]:
            test_fetch_station_history(role)
        
        # 3. Test Registrar Queue filtering
        test_registrar_queue_filtering()

        print("\n========================================================")
        print("ALL BACKEND QUEUE & HISTORY API TESTS PASSED SUCCESSFULLY!")
        print("========================================================")
    except Exception as e:
        print(f"\n[ERROR] Test suite failed: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
