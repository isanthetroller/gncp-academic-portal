# GNCP Academic Portal — Unified Automated Test Suite

## Overview
This unified test suite consolidates over 76 legacy, fragmented, and experimental test scripts across Playwright, Selenium, and PHP into a single, high-performance test architecture.

The unified suite operates directly against local XAMPP (`http://127.0.0.1/systemtest`, Apache Port 80, MariaDB Port 3306), requiring zero mocking, fake `sessionStorage` bypasses, or external mock servers.

---

## Architecture & Suites

| Suite File | Scope & Capabilities | Key Verifications |
| :--- | :--- | :--- |
| [`suites/suite_01_core_pipeline.py`](suites/suite_01_core_pipeline.py) | **Full 8-Stage Lifecycle & Admin CRUD** | Pre-Registration $\rightarrow$ Tracker $\rightarrow$ Registrar $\rightarrow$ TLC Helpdesk $\rightarrow$ Clinic $\rightarrow$ Cashier Tuition (₱18,300) $\rightarrow$ IT Center Promotion $\rightarrow$ Student Portal COR. Also verifies Admin Announcement & Milestone CRUD. |
| [`suites/suite_02_auth_security.py`](suites/suite_02_auth_security.py) | **Auth Hardening, Single-Session & RBAC** | Single-Active Session enforcement (Browser A superseded when Browser B logs in), mutation blocking on superseded sessions, IDOR boundary protection, Session Fixation prevention, Logout nullification. |
| [`suites/suite_03_special_journeys.py`](suites/suite_03_special_journeys.py) | **Specialized Journeys & UI Guards** | Mandatory Password Change Guard (`PasswordChangeGuard.js` SweetAlert2 modal), Cashier transparent assessment itemization, Student Portal Documents Hub & Conditional Undertakings view. |
| [`suites/suite_04_backend_engines.py`](suites/suite_04_backend_engines.py) | **High-Value PHP Domain Logic** | 104 granular domain assertions across: Financial calculations, Document submission workflows, PayMongo centavos integer conversions, Medical clinic clearance state engine. |

---

## Running the Unified Test Suite

### Run All Suites (Full Regression)
```bash
python tests/unified/run_test_suite.py
```

### Run Specific Suites
```bash
# Run Core 8-Stage Lifecycle & Admin CRUD
python tests/unified/run_test_suite.py --suite pipeline

# Run Single-Session & Auth Security Suite
python tests/unified/run_test_suite.py --suite security

# Run Specialized Journeys & UI Guards
python tests/unified/run_test_suite.py --suite journeys

# Run PHP Backend Domain Engines (Financial, Undertakings, PayMongo)
python tests/unified/run_test_suite.py --suite backend
```

---

## Output & Reports
- Console results with color-coded `[PASS]` / `[FAIL]` status and diagnostic metadata.
- Comprehensive Markdown execution reports are automatically generated at:
  `tests/unified/reports/UNIFIED_SYSTEM_TEST_REPORT.md`
