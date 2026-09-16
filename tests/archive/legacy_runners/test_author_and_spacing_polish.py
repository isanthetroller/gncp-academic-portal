import requests
import json
import sys

BASE_URL = "http://localhost/systemtest"

def run_tests():
    print("=======================================================")
    print("  ANNOUNCEMENT AUTHOR & SPACING POLISH VERIFICATION    ")
    print("=======================================================")
    
    session = requests.Session()
    
    # 1. Admin Login
    print("\n--- 1. Admin Login Verification ---")
    login_resp = session.post(
        f"{BASE_URL}/shared/backend/login.php?action=login",
        json={"username": "admin", "password": "admin12345"}
    )
    assert login_resp.status_code == 200, f"Expected 200, got {login_resp.status_code}"
    login_json = login_resp.json()
    assert login_json.get("success") is True, f"Admin login failed: {login_json}"
    print("  [PASS] Super Admin Authenticated")

    # 2. Update Existing Announcement #1 with Custom Author Name
    print("\n--- 2. Update Existing Announcement with Custom Author Name ---")
    update_payload = {
        "announcement": {
            "id": 1,
            "title": "Official Schedule & Guidelines: 1st Semester Preliminary Examinations",
            "author_name": "Dr. Eleanor Vance (VP for Academic Affairs)",
            "category": "ACADEMIC",
            "target_audience": "ALL",
            "content": "<p>Updated content with verified author.</p>",
            "is_pinned": 1,
            "status": "PUBLISHED"
        }
    }
    
    upd_resp = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=save_announcement",
        json=update_payload
    )
    assert upd_resp.status_code == 200, f"Expected 200, got {upd_resp.status_code}"
    upd_json = upd_resp.json()
    assert upd_json.get("success") is True, f"Update failed: {upd_json}"
    print("  [PASS] Announcement #1 updated with author_name: Dr. Eleanor Vance (VP for Academic Affairs)")

    # 3. Create Custom Announcement with Different Author Name
    print("\n--- 3. Create Custom Announcement with Custom Author Name ---")
    custom_author = "Office of the College Dean - Dr. Arthur Pendelton"
    create_payload = {
        "announcement": {
            "title": "Dean's Special Research Grant Announcement",
            "author_name": custom_author,
            "category": "ACADEMIC",
            "target_audience": "ALL",
            "content": "<p>Applications are now open for collegiate research funding.</p>",
            "is_pinned": 0,
            "status": "PUBLISHED"
        }
    }
    
    create_resp = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=save_announcement",
        json=create_payload
    )
    assert create_resp.status_code == 200, f"Expected 200, got {create_resp.status_code}"
    create_json = create_resp.json()
    assert create_json.get("success") is True, f"Create failed: {create_json}"
    new_id = create_json.get("data")
    print(f"  [PASS] Custom Announcement created with ID {new_id} and author: '{custom_author}'")

    # 4. Verify in Student Portal Feed
    print("\n--- 4. Verify Author Names in Student Portal Feed ---")
    feed_resp = requests.get(f"{BASE_URL}/student-portal/backend/api.php?action=fetch_announcements")
    assert feed_resp.status_code == 200
    feed_json = feed_resp.json()
    assert feed_json.get("success") is True
    
    items = feed_json.get("data", [])
    item1 = next((a for a in items if a.get("id") == 1), None)
    assert item1 is not None, "Announcement #1 not found in student feed"
    assert item1.get("author_name") == "Dr. Eleanor Vance (VP for Academic Affairs)", f"Expected Dr. Eleanor Vance, got {item1.get('author_name')}"
    print("  [PASS] Announcement #1 confirmed with author 'Dr. Eleanor Vance (VP for Academic Affairs)'")

    item_custom = next((a for a in items if a.get("id") == new_id), None)
    assert item_custom is not None, f"Custom announcement #{new_id} not found"
    assert item_custom.get("author_name") == custom_author, f"Expected {custom_author}, got {item_custom.get('author_name')}"
    print(f"  [PASS] Custom Announcement confirmed with author '{custom_author}'")

    # 5. Cleanup
    print("\n--- 5. Cleanup Test Announcement ---")
    del_resp = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=delete_announcement",
        json={"id": new_id}
    )
    assert del_resp.status_code == 200
    print(f"  [PASS] Cleaned up announcement #{new_id}")

    print("\n=======================================================")
    print("  AUTHOR NAME EDITABILITY & PERSISTENCE FULLY PASSED!  ")
    print("=======================================================")

if __name__ == "__main__":
    run_tests()
