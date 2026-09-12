import requests
import base64
import sys

BASE_URL = "http://localhost/systemtest"

def run_tests():
    print("==================================================")
    print("  ADMIN PROFILE & LOGOUT FULL LIFECYCLE E2E TEST")
    print("==================================================")
    
    session = requests.Session()
    
    # 1. Login
    res = session.post(f"{BASE_URL}/shared/backend/login.php", json={
        "username": "admin",
        "password": "admin12345"
    })
    assert res.status_code == 200, f"Login failed: {res.text}"
    data = res.json()
    assert data.get("success") is True, f"Login success flag false: {data}"
    print("  [PASS] 1. Super Admin Login Successful")
    
    # 2. Get Profile
    prof_res = session.get(f"{BASE_URL}/api/index.php?action=auth/profile")
    assert prof_res.status_code == 200, f"Get profile failed: {prof_res.text}"
    prof_data = prof_res.json()
    assert prof_data.get("success") is True, f"Profile success false: {prof_data}"
    assert prof_data.get("data", {}).get("username") == "admin"
    print(f"  [PASS] 2. Fetch Profile Successful: Name='{prof_data['data']['name']}', Email='{prof_data['data']['email']}'")
    
    # 3. Update Personal Details
    new_name = "System Administrator"
    new_email = "admin@gncp.edu.ph"
    update_res = session.post(f"{BASE_URL}/api/index.php?action=auth/update_profile", json={
        "username": "admin",
        "name": new_name,
        "email": new_email
    })
    assert update_res.status_code == 200, f"Update profile failed: {update_res.text}"
    update_data = update_res.json()
    assert update_data.get("success") is True, f"Update profile flag false: {update_data}"
    print("  [PASS] 3. Update Profile Personal Details Successful")
    
    # 4. Upload Avatar (Valid minimal JPEG base64 payload)
    dummy_b64 = "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA="
    avatar_res = session.post(f"{BASE_URL}/api/index.php?action=auth/upload_avatar", json={
        "username": "admin",
        "photoData": dummy_b64
    })
    assert avatar_res.status_code == 200, f"Upload avatar failed: {avatar_res.text}"
    avatar_data = avatar_res.json()
    assert avatar_data.get("success") is True, f"Avatar flag false: {avatar_data}"
    avatar_filename = avatar_data.get("data", {}).get("avatar")
    assert avatar_filename, "Avatar filename missing"
    print(f"  [PASS] 4. Upload Avatar Successful: Filename='{avatar_filename}'")
    
    # 5. Change Password
    temp_pass = "AdminTempPass123!"
    pw_res = session.post(f"{BASE_URL}/api/index.php?action=auth/change_password", json={
        "username": "admin",
        "current_password": "admin12345",
        "new_password": temp_pass
    })
    assert pw_res.status_code == 200, f"Change password failed: {pw_res.text}"
    pw_data = pw_res.json()
    assert pw_data.get("success") is True, f"Change password flag false: {pw_data}"
    print("  [PASS] 5. Change Password to New Password Successful")
    
    # 6. Verify Login with New Password
    new_session = requests.Session()
    login_new = new_session.post(f"{BASE_URL}/shared/backend/login.php", json={
        "username": "admin",
        "password": temp_pass
    })
    assert login_new.status_code == 200 and login_new.json().get("success") is True, f"Login with new password failed: {login_new.text}"
    print("  [PASS] 6. Verified Login with New Password")
    
    # 7. Restore Default Password
    pw_restore = new_session.post(f"{BASE_URL}/api/index.php?action=auth/change_password", json={
        "username": "admin",
        "current_password": temp_pass,
        "new_password": "admin12345"
    })
    assert pw_restore.status_code == 200 and pw_restore.json().get("success") is True, f"Password restore failed: {pw_restore.text}"
    print("  [PASS] 7. Restored Default Password (admin12345)")
    
    # 8. Logout
    logout_res = new_session.post(f"{BASE_URL}/api/index.php?action=auth/logout")
    assert logout_res.status_code == 200, f"Logout failed: {logout_res.text}"
    logout_data = logout_res.json()
    assert logout_data.get("success") is True, f"Logout flag false: {logout_data}"
    print("  [PASS] 8. Logout Successful")
    
    # 9. Verify Session Destruction
    post_check = new_session.get(f"{BASE_URL}/api/index.php?action=auth/profile")
    assert post_check.status_code in [401, 403] or post_check.json().get("success") is False, f"Protected endpoint accessible after logout: {post_check.text}"
    print("  [PASS] 9. Verified Protected Profile Access Denied After Logout")
    
    print("\n  ALL 9 PROFILE & LOGOUT LIFECYCLE TESTS PASSED PERFECTLY!\n")

if __name__ == "__main__":
    run_tests()
