import requests
import json
import sys

BASE_URL = "http://localhost/systemtest"

def run_tests():
    print("=======================================================")
    print("  ANNOUNCEMENT & MILESTONE DATABASE PERSISTENCE SUITE  ")
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

    # 2. Create Official Announcement with Google Docs formatted content
    print("\n--- 2. Announcement Creation & Database Persistence ---")
    test_title = "AUTOMATED TEST: Midterm Guidelines & Campus Advisory"
    test_content = (
        "<p>Please be advised of the upcoming examination guidelines for all departments:</p>"
        "<ul>"
        "<li><strong>October 15:</strong> Core Applied Sciences</li>"
        "<li><strong>October 16:</strong> Professional Courses</li>"
        "</ul>"
        "<blockquote>Reminder: Bring your valid Examination Permit upon entering testing halls.</blockquote>"
    )
    save_announcement_payload = {
        "announcement": {
            "title": test_title,
            "category": "ACADEMIC",
            "content": test_content,
            "target_audience": "ALL",
            "is_pinned": 1,
            "status": "PUBLISHED"
        }
    }
    
    save_ann_resp = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=save_announcement",
        json=save_announcement_payload
    )
    assert save_ann_resp.status_code == 200, f"Expected 200, got {save_ann_resp.status_code}"
    save_ann_json = save_ann_resp.json()
    assert save_ann_json.get("success") is True, f"Save announcement failed: {save_ann_json}"
    announcement_id = save_ann_json.get("data")
    print(f"  [PASS] Announcement Created with ID: {announcement_id}")

    # 3. Create Academic Milestone for Campus Desk
    print("\n--- 3. Academic Milestone Creation & Campus Desk Persistence ---")
    save_milestone_payload = {
        "milestone": {
            "title": "AUTOMATED TEST: Preliminary Grade Submission",
            "academic_period_id": 1,
            "date_start": "2026-10-20",
            "date_end": "2026-10-24",
            "date_display": "October 20 – 24, 2026",
            "status": "UPCOMING",
            "display_order": 99
        }
    }
    
    save_ms_resp = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=save_milestone",
        json=save_milestone_payload
    )
    assert save_ms_resp.status_code == 200, f"Expected 200, got {save_ms_resp.status_code}"
    save_ms_json = save_ms_resp.json()
    assert save_ms_json.get("success") is True, f"Save milestone failed: {save_ms_json}"
    milestone_id = save_ms_json.get("data")
    print(f"  [PASS] Milestone Created with ID: {milestone_id}")

    # 4. Student Portal Public/Live Sync Assertion
    print("\n--- 4. Student Portal Live Sync Query ---")
    feed_resp = requests.get(f"{BASE_URL}/student-portal/backend/api.php?action=fetch_announcements")
    assert feed_resp.status_code == 200, f"Expected 200, got {feed_resp.status_code}"
    feed_json = feed_resp.json()
    assert feed_json.get("success") is True, f"Fetch announcements failed: {feed_json}"
    
    # Assert created announcement is present
    announcements_list = feed_json.get("data", [])
    matched_ann = next((a for a in announcements_list if a.get("id") == announcement_id), None)
    assert matched_ann is not None, f"Created announcement ID {announcement_id} not found in student feed!"
    assert matched_ann.get("title") == test_title
    assert int(matched_ann.get("is_pinned")) == 1
    print(f"  [PASS] Student Portal received Announcement ID {announcement_id}")

    # Assert created milestone is present
    ms_resp = requests.get(f"{BASE_URL}/student-portal/backend/api.php?action=fetch_milestones")
    assert ms_resp.status_code == 200, f"Expected 200, got {ms_resp.status_code}"
    ms_json = ms_resp.json()
    assert ms_json.get("success") is True, f"Fetch milestones failed: {ms_json}"
    
    milestones_list = ms_json.get("data", [])
    matched_ms = next((m for m in milestones_list if m.get("id") == milestone_id), None)
    assert matched_ms is not None, f"Created milestone ID {milestone_id} not found in student milestones!"
    assert matched_ms.get("status") == "UPCOMING"
    print(f"  [PASS] Student Portal received Milestone ID {milestone_id}")

    # 5. Clean up test entries
    print("\n--- 5. Cleanup Test Artifacts ---")
    del_ann = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=delete_announcement",
        json={"id": announcement_id}
    )
    assert del_ann.status_code == 200
    print(f"  [PASS] Cleaned up Announcement ID {announcement_id}")

    del_ms = session.post(
        f"{BASE_URL}/admin/backend/api.php?action=delete_milestone",
        json={"id": milestone_id}
    )
    assert del_ms.status_code == 200
    print(f"  [PASS] Cleaned up Milestone ID {milestone_id}")

    print("\n=======================================================")
    print("  ALL DATABASE & BACKEND PERSISTENCE TESTS PASSED!     ")
    print("=======================================================")

if __name__ == "__main__":
    run_tests()
