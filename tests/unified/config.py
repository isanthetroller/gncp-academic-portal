import os
import sys

BASE_URL = os.environ.get("GNCP_BASE_URL", "http://127.0.0.1/systemtest").rstrip('/')
PHP_EXE = r"C:\xampp\php\php.exe" if os.path.exists(r"C:\xampp\php\php.exe") else "php"
MYSQL_EXE = r"C:\xampp\mysql\bin\mysql.exe" if os.path.exists(r"C:\xampp\mysql\bin\mysql.exe") else "mysql"

DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "gncp_portal",
    "port": 3306
}

CREDENTIALS = {
    "admin": {"username": "admin", "password": "admin12345", "role": "ADMIN"},
    "registrar": {"username": "kriz", "password": "kriz123", "role": "REGISTRAR"},
    "helpdesk": {"username": "tristan", "password": "tristan123", "role": "HELPDESK"},
    "medical": {"username": "ethan", "password": "ethan123", "role": "MEDICAL"},
    "cashier": {"username": "cashier", "password": "cashier123", "role": "CASHIER"},
    "it_officer": {"username": "it_officer", "password": "itpassword", "role": "IT_CENTER"},
    "student": {"username": "2026-1006", "password": "student123", "role": "STUDENT"}
}

UNIFIED_DIR = os.path.abspath(os.path.dirname(__file__))
TESTS_ROOT = os.path.abspath(os.path.join(UNIFIED_DIR, ".."))
REPORTS_DIR = os.path.join(UNIFIED_DIR, "reports")
SCREENSHOTS_DIR = os.path.join(UNIFIED_DIR, "screenshots")

os.makedirs(REPORTS_DIR, exist_ok=True)
os.makedirs(SCREENSHOTS_DIR, exist_ok=True)
