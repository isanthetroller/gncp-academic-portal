import os

BASE_URL = "http://127.0.0.1/systemtest"

# Operator Credentials Map
CREDENTIALS = {
    "REGISTRAR": {"username": "kriz", "password": "kriz123"},
    "HELPDESK":  {"username": "tristan", "password": "tristan123"},
    "MEDICAL":   {"username": "ethan", "password": "ethan123"},
    "CASHIER":   {"username": "cashier", "password": "cashier123"},
    "IT_CENTER": {"username": "it_officer", "password": "itpassword"},
    "ADMIN":     {"username": "admin", "password": "admin12345"}
}

# Target UI Page Endpoints
PAGES = {
    "REGISTRATION": f"{BASE_URL}/enrollment-system/index.html",
    "REGISTRAR":    f"{BASE_URL}/registrar/index.html",
    "HELPDESK":     f"{BASE_URL}/stations/tlc-helpdesk/index.html",
    "MEDICAL":      f"{BASE_URL}/stations/medical-checkup/index.html",
    "CASHIER":      f"{BASE_URL}/stations/payment-processing/index.html",
    "IT_CENTER":    f"{BASE_URL}/stations/it-center/index.html",
    "ADMIN":        f"{BASE_URL}/admin/index.html",
    "STUDENT_PORTAL": f"{BASE_URL}/student-portal/index.html",
    "STUDENT_PORTAL_LOGIN": f"{BASE_URL}/student-portal/login.html",
    "PROFILE":      f"{BASE_URL}/admin/index.html"
}

# Artifact Directories
SELENIUM_DIR = os.path.dirname(os.path.abspath(__file__))
SCREENSHOTS_DIR = os.path.join(SELENIUM_DIR, "screenshots")
os.makedirs(SCREENSHOTS_DIR, exist_ok=True)
