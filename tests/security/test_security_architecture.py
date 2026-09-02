"""
GNCP Security Architecture & Hardening Verification Suite
Tests the server-side security boundary, anti-scraping protections,
PII data minimization, RBAC authorization, and IDOR defenses.
"""

import requests
import subprocess
import json
import sys

BASE = "http://127.0.0.1/systemtest"

class Colors:
    GREEN = '\033[92m'
    RED = '\033[91m'
    YELLOW = '\033[93m'
    BLUE = '\033[94m'
    BOLD = '\033[1m'
    END = '\033[0m'

total_checks = 0
passed_checks = 0
failed_checks = 0

def check(name, condition, detail=""):
    global total_checks, passed_checks, failed_checks
    total_checks += 1
    if condition:
        passed_checks += 1
        print(f"  {Colors.GREEN}[PASS]{Colors.END} {name} {f'({detail})' if detail else ''}")
        return True
    else:
        failed_checks += 1
        print(f"  {Colors.RED}[FAIL]{Colors.END} {name} {f'({detail})' if detail else ''}")
        return False

def get_florence_pin():
    cmd = ["c:/xampp/mysql/bin/mysql.exe", "-u", "root", "gncp_portal", "-sN", "-e",
           "SELECT temp_pin FROM pre_enrollments WHERE temp_student_id = 'REF-2026-1012';"]
    res = subprocess.run(cmd, capture_output=True, text=True)
    return res.stdout.strip()

def setup_isolated_test_student():
    script = """
    require 'shared/backend/config/database.php';
    $pdo = Database::getInstance();
    $hash = password_hash('Student#2026', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO students (id, temp_reference_no, name, email, password, program, year_level, status, must_change_password) 
        VALUES ('GNCP-TEST-SEC01', 'REF-SEC-01', 'Security Tester', 'sec.tester@gncp.edu.ph', :h, 'BSIT', '1st Year', 'ENROLLED', 0)
        ON DUPLICATE KEY UPDATE password = :h2, status = 'ENROLLED', must_change_password = 0");
    $stmt->execute(['h' => $hash, 'h2' => $hash]);
    """
    subprocess.run(["c:\\xampp\\php\\php.exe", "-r", script], capture_output=True, text=True, cwd="c:/xampp/htdocs/systemtest")

def cleanup_isolated_test_student():
    script = """
    require 'shared/backend/config/database.php';
    $pdo = Database::getInstance();
    $pdo->prepare("DELETE FROM students WHERE id = 'GNCP-TEST-SEC01'")->execute();
    """
    subprocess.run(["c:\\xampp\\php\\php.exe", "-r", script], capture_output=True, text=True, cwd="c:/xampp/htdocs/systemtest")

def main():
    print(f"\n{Colors.BOLD}{'='*75}{Colors.END}")
    print(f"{Colors.BOLD}   GNCP ACADEMIC SYSTEM — SECURITY ARCHITECTURE VERIFICATION AUDIT{Colors.END}")
    print(f"{Colors.BOLD}{'='*75}{Colors.END}\n")

    setup_isolated_test_student()

    try:
        # =========================================================================
        # SUITE 1: Server-Side Portal Entrypoint Gating (HTML Scraping Resistance)
        # =========================================================================
        print(f"{Colors.BLUE}{Colors.BOLD}SUITE 1: Server-Side Portal Gating (Anti-Scraping / Zero DOM Leak){Colors.END}")
        portals = [
            ("Admin Portal Root", f"{BASE}/admin/"),
            ("Admin Portal HTML", f"{BASE}/admin/index.html"),
            ("Registrar Portal Root", f"{BASE}/registrar/"),
            ("Registrar Portal HTML", f"{BASE}/registrar/index.html"),
            ("IT Center Station Root", f"{BASE}/stations/it-center/"),
            ("IT Center Station HTML", f"{BASE}/stations/it-center/index.html"),
            ("Cashier Station Root", f"{BASE}/stations/payment-processing/"),
            ("Clinic Station Root", f"{BASE}/stations/medical-checkup/"),
            ("Helpdesk Station Root", f"{BASE}/stations/tlc-helpdesk/"),
            ("Monitoring Portal Root", f"{BASE}/monitoring/"),
            ("Monitoring Portal HTML", f"{BASE}/monitoring/index.html"),
        ]

        for label, url in portals:
            r = requests.get(url, allow_redirects=False, timeout=5)
            is_redirect = r.status_code in [301, 302, 303, 307]
            has_no_app_dom = b"id=\"app\"" not in r.content and b"sidebar" not in r.content
            check(f"Unauthenticated request to {label} redirected (302)", is_redirect, f"HTTP {r.status_code}")
            check(f"No application DOM leaked from {label}", has_no_app_dom, f"{len(r.content)} bytes")

        # =========================================================================
        # SUITE 2: Administrative & Academic API Authorization
        # =========================================================================
        print(f"\n{Colors.BLUE}{Colors.BOLD}SUITE 2: Administrative & Academic API Authorization{Colors.END}")
        protected_apis = [
            ("admin/catalog", f"{BASE}/api/index.php?action=admin/catalog"),
            ("admin/sections", f"{BASE}/api/index.php?action=admin/sections"),
            ("admin/terms", f"{BASE}/api/index.php?action=admin/terms"),
            ("registrar/sections", f"{BASE}/api/index.php?action=registrar/sections"),
            ("admin/users", f"{BASE}/api/index.php?action=admin/users"),
            ("admin/analytics", f"{BASE}/api/index.php?action=admin/analytics"),
            ("stations/queue", f"{BASE}/api/index.php?action=stations/queue"),
            ("monitoring/stats", f"{BASE}/api/index.php?action=monitoring/stats"),
            ("monitoring/system_health", f"{BASE}/api/index.php?action=monitoring/system_health"),
        ]

        for label, url in protected_apis:
            r = requests.get(url, timeout=5)
            check(f"Unauthenticated API call to {label} rejected (401)", r.status_code == 401, f"HTTP {r.status_code}")

        # =========================================================================
        # SUITE 3: Public Student Tracker Data Minimization & Scraping Resistance
        # =========================================================================
        print(f"\n{Colors.BLUE}{Colors.BOLD}SUITE 3: Public Application Tracker Data Minimization & Scraping Resistance{Colors.END}")
        r_track_anon = requests.get(f"{BASE}/api/index.php?action=student/track&ref=REF-2026-1012", timeout=5)
        check("Public tracker query returns HTTP 200", r_track_anon.status_code == 200, f"HTTP {r_track_anon.status_code}")
        
        anon_data = r_track_anon.json().get("data", {})
        check("Public tracker masks applicant full name", anon_data.get("name") == "F******* N**********", f"Name: {anon_data.get('name')}")
        check("Public tracker indicates requiresPinForDetails", anon_data.get("requiresPinForDetails") is True)
        
        sensitive_fields = ['phone', 'email', 'address', 'birthDate', 'gender', 'medical', 'scholarship', 'payment', 'requirements', 'tempPin', 'emergencyContactPhone']
        leaked = [f for f in sensitive_fields if f in anon_data]
        check("Zero PII fields leaked to anonymous scraper", len(leaked) == 0, f"Leaked: {leaked}")

        # Verified PIN check:
        florence_pin = get_florence_pin()
        r_track_pin = requests.get(f"{BASE}/api/index.php?action=student/track&ref=REF-2026-1012&pin={florence_pin}", timeout=5)
        pin_data = r_track_pin.json().get("data", {})
        check("Verified PIN unlocks full applicant profile", pin_data.get("firstName") == "Florence" and pin_data.get("phone") == "09123456789", f"Phone: {pin_data.get('phone')}")

        # =========================================================================
        # SUITE 4: Print Template & Document Protection
        # =========================================================================
        print(f"\n{Colors.BLUE}{Colors.BOLD}SUITE 4: Print Template & Document Storage Protection{Colors.END}")
        r_cor_anon = requests.get(f"{BASE}/stations/payment-processing/cor_print.php?ref=REF-2026-1012", timeout=5)
        check("Unauthenticated COR print rejected (401)", r_cor_anon.status_code == 401, f"HTTP {r_cor_anon.status_code}")

        r_rec_anon = requests.get(f"{BASE}/stations/payment-processing/receipt_print.php?ref=REF-2026-1012", timeout=5)
        check("Unauthenticated Receipt print rejected (401)", r_rec_anon.status_code == 401, f"HTTP {r_rec_anon.status_code}")

        r_doc_static = requests.get(f"{BASE}/uploads/documents/sample_birth_certificate.pdf", timeout=5)
        check("Direct static HTTP access to uploads/documents/ blocked (403)", r_doc_static.status_code == 403, f"HTTP {r_doc_static.status_code}")

        # =========================================================================
        # SUITE 5: Role-Based Access Control (RBAC) & Privilege Escalation Defenses
        # =========================================================================
        print(f"\n{Colors.BLUE}{Colors.BOLD}SUITE 5: Role-Based Access Control (RBAC) & IDOR Protection{Colors.END}")
        
        # Student session:
        s_student = requests.Session()
        s_student.post(f"{BASE}/api/index.php?action=student_portal/login", json={"studentId": "GNCP-TEST-SEC01", "password": "Student#2026"})
        
        r_std_admin = s_student.get(f"{BASE}/api/index.php?action=admin/users", timeout=5)
        check("Student cannot access Admin User Management (403)", r_std_admin.status_code == 403, f"HTTP {r_std_admin.status_code}")

        r_std_queue = s_student.get(f"{BASE}/api/index.php?action=stations/queue", timeout=5)
        check("Student cannot access Staff Workstation Queue (403)", r_std_queue.status_code == 403, f"HTTP {r_std_queue.status_code}")

        r_std_dash_idor = s_student.get(f"{BASE}/api/index.php?action=student_portal/dashboard&studentId=GNCP-2026-0258", timeout=5)
        check("Student blocked from cross-student dashboard IDOR (401/403)", r_std_dash_idor.status_code in [401, 403], f"HTTP {r_std_dash_idor.status_code}")

        r_std_doc_idor = s_student.get(f"{BASE}/api/index.php?action=student/documents&identifier=GNCP-2026-0258", timeout=5)
        check("Student blocked from cross-student documents IDOR (401/403)", r_std_doc_idor.status_code in [401, 403], f"HTTP {r_std_doc_idor.status_code}")

        # Registrar session:
        s_reg = requests.Session()
        s_reg.post(f"{BASE}/shared/backend/login.php", json={"username": "kriz", "password": "kriz123"})

        r_reg_admin = s_reg.get(f"{BASE}/api/index.php?action=admin/users", timeout=5)
        check("Registrar cannot access Admin User Management (403)", r_reg_admin.status_code == 403, f"HTTP {r_reg_admin.status_code}")

        r_reg_telemetry = s_reg.get(f"{BASE}/api/index.php?action=monitoring/stats", timeout=5)
        check("Registrar cannot access Developer Telemetry / Monitoring (403)", r_reg_telemetry.status_code == 403, f"HTTP {r_reg_telemetry.status_code}")

        # =========================================================================
        # SUITE 6: Legitimate Authorized Workflows Intact
        # =========================================================================
        print(f"\n{Colors.BLUE}{Colors.BOLD}SUITE 6: Legitimate Authorized Workflows Intact{Colors.END}")

        # Admin session:
        s_admin = requests.Session()
        s_admin.post(f"{BASE}/shared/backend/login.php", json={"username": "admin", "password": "admin12345"})

        r_admin_portal = s_admin.get(f"{BASE}/admin/", allow_redirects=False, timeout=5)
        check("Authenticated Admin can access Admin Portal (200)", r_admin_portal.status_code == 200, f"HTTP {r_admin_portal.status_code}")

        r_admin_catalog = s_admin.get(f"{BASE}/api/index.php?action=admin/catalog", timeout=5)
        check("Authenticated Admin can fetch Catalog API (200)", r_admin_catalog.status_code == 200, f"HTTP {r_admin_catalog.status_code}")

        r_admin_sections = s_admin.get(f"{BASE}/api/index.php?action=admin/sections", timeout=5)
        check("Authenticated Admin can fetch Sections API (200)", r_admin_sections.status_code == 200, f"HTTP {r_admin_sections.status_code}")

        # Registrar session:
        r_reg_portal = s_reg.get(f"{BASE}/registrar/", allow_redirects=False, timeout=5)
        check("Authenticated Registrar can access Registrar Portal (200)", r_reg_portal.status_code == 200, f"HTTP {r_reg_portal.status_code}")

        r_reg_queue = s_reg.get(f"{BASE}/api/index.php?action=stations/queue", timeout=5)
        check("Authenticated Registrar can fetch Workstation Queue (200)", r_reg_queue.status_code == 200, f"HTTP {r_reg_queue.status_code}")

        # Cashier print:
        s_cashier = requests.Session()
        s_cashier.post(f"{BASE}/shared/backend/login.php", json={"username": "cashier", "password": "cashier123"})
        r_cashier_cor = s_cashier.get(f"{BASE}/stations/payment-processing/cor_print.php?ref=REF-2026-1012", timeout=5)
        check("Authenticated Cashier can render COR print (200)", r_cashier_cor.status_code == 200, f"HTTP {r_cashier_cor.status_code}")

        r_cashier_rec = s_cashier.get(f"{BASE}/stations/payment-processing/receipt_print.php?ref=REF-2026-1012", timeout=5)
        check("Authenticated Cashier can render Receipt print (200)", r_cashier_rec.status_code == 200, f"HTTP {r_cashier_rec.status_code}")

    finally:
        cleanup_isolated_test_student()

    print(f"\n{Colors.BOLD}{'='*75}{Colors.END}")
    print(f"{Colors.BOLD}   VERIFICATION SUMMARY: {passed_checks}/{total_checks} CHECKS PASSED{Colors.END}")
    if failed_checks == 0:
        print(f"   {Colors.GREEN}{Colors.BOLD}ALL SECURITY CHECKS PASSED PERFECTLY!{Colors.END}")
    else:
        print(f"   {Colors.RED}{Colors.BOLD}{failed_checks} CHECKS FAILED!{Colors.END}")
    print(f"{Colors.BOLD}{'='*75}{Colors.END}\n")

    return 0 if failed_checks == 0 else 1

if __name__ == '__main__':
    sys.exit(main())
