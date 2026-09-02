import subprocess
import requests
import json

BASE = 'http://127.0.0.1/systemtest'

def setup_test_student():
    script = """
    require 'shared/backend/config/database.php';
    $pdo = Database::getInstance();
    $hash = password_hash('Student#2026', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO students (id, temp_reference_no, name, email, password, program, year_level, status, must_change_password) 
        VALUES ('GNCP-TEST-SEC01', 'REF-SEC-01', 'Security Tester', 'sec.tester@gncp.edu.ph', :h, 'BSIT', '1st Year', 'ENROLLED', 0)
        ON DUPLICATE KEY UPDATE password = :h2, status = 'ENROLLED', must_change_password = 0");
    $stmt->execute(['h' => $hash, 'h2' => $hash]);
    echo 'SEEDED';
    """
    cmd = ["c:\\xampp\\php\\php.exe", "-r", script]
    res = subprocess.run(cmd, capture_output=True, text=True, cwd="c:/xampp/htdocs/systemtest")
    return res.stdout.strip()

def cleanup_test_student():
    script = """
    require 'shared/backend/config/database.php';
    $pdo = Database::getInstance();
    $pdo->prepare("DELETE FROM students WHERE id = 'GNCP-TEST-SEC01'")->execute();
    echo 'CLEANED';
    """
    cmd = ["c:\\xampp\\php\\php.exe", "-r", script]
    res = subprocess.run(cmd, capture_output=True, text=True, cwd="c:/xampp/htdocs/systemtest")
    return res.stdout.strip()

def main():
    setup_test_student()

    # 1. Login as student GNCP-TEST-SEC01
    s_student = requests.Session()
    r_log = s_student.post(f'{BASE}/api/index.php?action=student_portal/login', json={'studentId':'GNCP-TEST-SEC01','password':'Student#2026'})
    print('Student Login Status:', r_log.status_code, r_log.text[:100])

    # 2. Try accessing Admin endpoints as student:
    test_calls = [
        ('admin/analytics', 'GET', f'{BASE}/api/index.php?action=admin/analytics', None),
        ('admin/users', 'GET', f'{BASE}/api/index.php?action=admin/users', None),
        ('stations/queue', 'GET', f'{BASE}/api/index.php?action=stations/queue', None),
        ('stations/update', 'POST', f'{BASE}/api/index.php?action=stations/update', {'referenceNumber':'REF-2026-1012','updateData':{'status':'Approved'}}),
        ('registrar/update_status', 'POST', f'{BASE}/api/index.php?action=registrar/update_status', {'referenceNumber':'REF-2026-1012','status':'Approved'}),
        ('monitoring/stats', 'GET', f'{BASE}/api/index.php?action=monitoring/stats', None),
        ('student_portal/dashboard (Ada Lovelace GNCP-2026-0258)', 'GET', f'{BASE}/api/index.php?action=student_portal/dashboard&studentId=GNCP-2026-0258', None),
        ('student_portal/dashboard (Own 2026-1006)', 'GET', f'{BASE}/api/index.php?action=student_portal/dashboard&studentId=2026-1006', None),
        ('student/documents (Ada Lovelace GNCP-2026-0258)', 'GET', f'{BASE}/api/index.php?action=student/documents&identifier=GNCP-2026-0258', None),
        ('student/documents (Own 2026-1006)', 'GET', f'{BASE}/api/index.php?action=student/documents&identifier=2026-1006', None),
    ]

    print('\n=== STUDENT PRIVILEGE ESCALATION & IDOR TESTS ===')
    for name, method, url, payload in test_calls:
        if method == 'GET':
            res = s_student.get(url, timeout=5)
        else:
            res = s_student.post(url, json=payload, timeout=5)
        try:
            j = res.json()
            msg = str(j.get('message') or j.get('error') or '')[:50]
            print(f'{res.status_code:3d} | {name:<50} | success={j.get("success")} | msg={msg}')
        except Exception:
            print(f'{res.status_code:3d} | {name:<50} | non-json len={len(res.content)}')
    # 3. Test Registrar trying to access SuperAdmin endpoints
    s_reg = requests.Session()
    r_reg = s_reg.post(f'{BASE}/shared/backend/login.php', json={'username':'kriz','password':'kriz123'})
    print(f"\nRegistrar Login: {r_reg.status_code} | success={r_reg.json().get('success')}")

    reg_to_admin_calls = [
        ('admin/users', f'{BASE}/api/index.php?action=admin/users'),
        ('admin/analytics', f'{BASE}/api/index.php?action=admin/analytics'),
        ('monitoring/stats', f'{BASE}/api/index.php?action=monitoring/stats'),
        ('monitoring/system_health', f'{BASE}/api/index.php?action=monitoring/system_health'),
        ('legacy/admin_fetch_users', f'{BASE}/admin/backend/api.php?action=fetch_users'),
    ]

    print('\n=== REGISTRAR -> SUPERADMIN PRIVILEGE ESCALATION TESTS ===')
    for name, url in reg_to_admin_calls:
        res = s_reg.get(url, timeout=5)
        try:
            j = res.json()
            msg = str(j.get('message') or j.get('error') or '')[:50]
            print(f'{res.status_code:3d} | {name:<40} | success={j.get("success")} | msg={msg}')
        except Exception:
            print(f'{res.status_code:3d} | {name:<40} | non-json len={len(res.content)}')

    cleanup_test_student()

if __name__ == '__main__':
    main()
