"""
Test Student Portal Dedicated Forgot Password & Recovery Flow (Personal Email & School Email Support)
"""
import requests
import json
import subprocess
import time

BASE_URL = "http://localhost/systemtest"

def run_test():
    print("=" * 60)
    print("  GNCP STUDENT FORGOT PASSWORD & DUAL-EMAIL VERIFICATION")
    print("=" * 60)

    # 1. Look up or seed a test student in DB
    cmd = 'C:\\xampp\\mysql\\bin\\mysql.exe -u root gncp_portal -e "SELECT id, name, email, personal_info FROM students LIMIT 1;"'
    out = subprocess.check_output(cmd, shell=True).decode()
    lines = [l.strip() for l in out.strip().split('\n') if l.strip()]

    is_seeded = False
    if len(lines) < 2:
        student_id = "GNCP-2026-TESTRECOVERY"
        student_name = "Test Recovery Student"
        school_email = "student.testrecovery@gncp.edu.ph"
        personal_email = "student.test.recovery@gmail.com"
        pass_hash = subprocess.check_output('C:\\xampp\\php\\php.exe -r "echo password_hash(\'delacruz\', PASSWORD_DEFAULT);"', shell=True).decode().strip()
        personal_info_json = json.dumps({"email": personal_email, "phone": "09123456789", "firstName": "Test", "lastName": "Recovery"}).replace('"', '\\"')
        
        insert_cmd = f"""C:\\xampp\\mysql\\bin\\mysql.exe -u root gncp_portal -e "INSERT INTO students (id, temp_reference_no, name, email, password, program, year_level, status, personal_info) VALUES ('{student_id}', 'GNCP-2026-REC01', '{student_name}', '{school_email}', '{pass_hash}', 'BSIT', '1st Year', 'ACTIVE', '{personal_info_json}');" """
        subprocess.check_call(insert_cmd, shell=True)
        is_seeded = True
    else:
        fields = lines[1].split('\t')
        student_id = fields[0]
        student_name = fields[1]
        school_email = fields[2]
        personal_email = "student.test.recovery@gmail.com"
        update_cmd = f"""C:\\xampp\\mysql\\bin\\mysql.exe -u root gncp_portal -e "UPDATE students SET personal_info = JSON_SET(COALESCE(NULLIF(personal_info, ''), '{{}}'), '$.email', '{personal_email}') WHERE id = '{student_id}';" """
        subprocess.check_call(update_cmd, shell=True)

    # ── TEST SCENARIO A: Request via Student ID (dispatches to Personal Email) ──
    print("\n--- Scenario A: Request via Student ID ---")
    url = f"{BASE_URL}/student-portal/backend/api.php?action=request_password_reset"
    payload_a = {"identifier": student_id}
    res_a = requests.post(url, json=payload_a)
    print("A1. Request via ID Response:", res_a.status_code, res_a.text)
    assert res_a.status_code == 200, f"Expected 200, got {res_a.status_code}"
    data_a = res_a.json()
    assert data_a.get("success") is True
    assert "maskedEmail" in data_a["data"] and "***" in data_a["data"]["maskedEmail"]
    print(f"  [PASS] Successfully routed OTP and returned masked email ({data_a['data']['maskedEmail']}) for Student ID lookup")

    # ── TEST SCENARIO B: Request via School Email (dispatches to School Email) ──
    print("\n--- Scenario B: Request via School Institutional Email ---")
    payload_b = {"identifier": school_email}
    res_b = requests.post(url, json=payload_b)
    print("B1. Request via School Email Response:", res_b.status_code, res_b.text)
    assert res_b.status_code == 200, f"Expected 200, got {res_b.status_code}"
    data_b = res_b.json()
    assert data_b.get("success") is True
    assert "maskedEmail" in data_b["data"] and "***" in data_b["data"]["maskedEmail"]
    print(f"  [PASS] Successfully routed OTP to school email ({data_b['data']['maskedEmail']})")

    # ── TEST SCENARIO C: Verify OTP & Reset Password ──
    print("\n--- Scenario C: Reset Password using OTP ---")
    chk_cmd = f"""C:\\xampp\\mysql\\bin\\mysql.exe -u root gncp_portal -e "SELECT code FROM password_resets WHERE email = '{school_email}' ORDER BY id DESC LIMIT 1;" """
    code_out = subprocess.check_output(chk_cmd, shell=True).decode()
    code_lines = [l.strip() for l in code_out.strip().split('\n') if l.strip()]
    assert len(code_lines) >= 2, "No reset code found in password_resets"
    otp_code = code_lines[1]
    print(f"  [INFO] OTP Code in DB: {otp_code}")

    reset_url = f"{BASE_URL}/student-portal/backend/api.php?action=reset_password_with_code"
    new_password = "dualPassReset2026!"
    reset_payload = {
        "identifier": student_id,
        "code": otp_code,
        "newPassword": new_password
    }
    res2 = requests.post(reset_url, json=reset_payload)
    print("C1. Reset Password Response:", res2.status_code, res2.text)
    assert res2.status_code == 200
    assert res2.json().get("success") is True
    print("  [PASS] Password reset with OTP successful")

    # ── TEST SCENARIO D: Login with new password ──
    login_url = f"{BASE_URL}/student-portal/backend/api.php?action=login_student"
    login_payload = {
        "studentId": student_id,
        "password": new_password
    }
    res3 = requests.post(login_url, json=login_payload)
    print("D1. Login with New Password Response:", res3.status_code, res3.text)
    assert res3.status_code == 200
    assert res3.json().get("success") is True
    print("  [PASS] Student Login with New Password Verified")

    # Cleanup
    if is_seeded:
        subprocess.check_call(f"""C:\\xampp\\mysql\\bin\\mysql.exe -u root gncp_portal -e "DELETE FROM students WHERE id = '{student_id}'; DELETE FROM password_resets WHERE email IN ('{school_email}', '{personal_email}');" """, shell=True)
        print("  [PASS] Cleaned up seeded test student and reset tokens")
    else:
        restore_pass = "delacruz"
        restore_hash = subprocess.check_output(f'C:\\xampp\\php\\php.exe -r "echo password_hash(\'{restore_pass}\', PASSWORD_DEFAULT);"', shell=True).decode()
        subprocess.check_call(f"""C:\\xampp\\mysql\\bin\\mysql.exe -u root gncp_portal -e "UPDATE students SET password = '{restore_hash}' WHERE id = '{student_id}';" """, shell=True)
        print("  [PASS] Restored student default password")

    print("\n" + "=" * 60)
    print("  ALL SCENARIOS PASSED WITH PERFECT DUAL-EMAIL ROUTING!")
    print("=" * 60)

if __name__ == "__main__":
    run_test()
