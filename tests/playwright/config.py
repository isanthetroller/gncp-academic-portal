import os

BASE_URL = os.environ.get("GNCP_BASE_URL", "http://127.0.0.1/systemtest")

# Database Configuration (for direct DB verification assertions)
DB_CONFIG = {
    "host": os.environ.get("GNCP_DB_HOST", "127.0.0.1"),
    "user": os.environ.get("GNCP_DB_USER", "root"),
    "password": os.environ.get("GNCP_DB_PASS", ""),
    "database": os.environ.get("GNCP_DB_NAME", "gncp_portal"),
    "port": int(os.environ.get("GNCP_DB_PORT", 3306))
}

# Operator Credentials Map
CREDENTIALS = {
    "REGISTRAR": {
        "username": os.environ.get("GNCP_REGISTRAR_USER", "kriz"),
        "password": os.environ.get("GNCP_REGISTRAR_PASS", "kriz123")
    },
    "HELPDESK": {
        "username": os.environ.get("GNCP_HELPDESK_USER", "tristan"),
        "password": os.environ.get("GNCP_HELPDESK_PASS", "tristan123")
    },
    "MEDICAL": {
        "username": os.environ.get("GNCP_MEDICAL_USER", "ethan"),
        "password": os.environ.get("GNCP_MEDICAL_PASS", "ethan123")
    },
    "CASHIER": {
        "username": os.environ.get("GNCP_CASHIER_USER", "cashier"),
        "password": os.environ.get("GNCP_CASHIER_PASS", "cashier123")
    },
    "IT_CENTER": {
        "username": os.environ.get("GNCP_IT_USER", "it_officer"),
        "password": os.environ.get("GNCP_IT_PASS", "itpassword")
    },
    "ADMIN": {
        "username": os.environ.get("GNCP_ADMIN_USER", "admin"),
        "password": os.environ.get("GNCP_ADMIN_PASS", "admin12345")
    }
}

# Target UI Page Endpoints
PAGES = {
    "GATEWAY":              f"{BASE_URL}/index.html",
    "LOGIN":                f"{BASE_URL}/index.html",
    "REGISTRATION":         f"{BASE_URL}/enrollment-system/index.html",
    "TRACKER":              f"{BASE_URL}/enrollment-system/tracker.html",
    "REGISTRAR":            f"{BASE_URL}/registrar/index.html",
    "HELPDESK":             f"{BASE_URL}/stations/tlc-helpdesk/index.html",
    "MEDICAL":              f"{BASE_URL}/stations/medical-checkup/index.html",
    "CASHIER":              f"{BASE_URL}/stations/payment-processing/index.html",
    "IT_CENTER":            f"{BASE_URL}/stations/it-center/index.html",
    "ADMIN":                f"{BASE_URL}/admin/index.html",
    "STUDENT_PORTAL":       f"{BASE_URL}/student-portal/index.html",
    "STUDENT_PORTAL_LOGIN": f"{BASE_URL}/student-portal/login.html",
    "STUDENT_FORGOT_PASS":  f"{BASE_URL}/student-portal/forgot-password.html"
}

# Artifact & Report Directories
PLAYWRIGHT_DIR = os.path.dirname(os.path.abspath(__file__))
SCREENSHOTS_DIR = os.path.join(PLAYWRIGHT_DIR, "screenshots")
REPORTS_DIR = os.path.join(PLAYWRIGHT_DIR, "reports")
TRACES_DIR = os.path.join(PLAYWRIGHT_DIR, "traces")

for d in [SCREENSHOTS_DIR, REPORTS_DIR, TRACES_DIR]:
    os.makedirs(d, exist_ok=True)

# Timeouts
DEFAULT_TIMEOUT_MS = 30000
ACTION_TIMEOUT_MS = 15000
