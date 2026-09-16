# GNCP Academic Portal — Unified Automated Test Report

- **Execution Date:** 2026-09-16 08:13:25
- **Target URL:** http://127.0.0.1/systemtest
- **Total Assertions:** 33
- **Passed:** 33
- **Failed:** 0
- **Duration:** 78.17 seconds
- **Overall Status:** `PASSED`

## Suite Breakdown

| Suite Name | Assertions | Passed | Failed | Pass Rate | Verdict |
| :--- | :---: | :---: | :---: | :---: | :---: |
| Suite 01: Core 8-Stage Pipeline & Admin CRUD | 13 | 13 | 0 | 100.0% | ✅ PASS |
| Suite 02: Authentication, Single-Active Session & Security Hardening | 10 | 10 | 0 | 100.0% | ✅ PASS |
| Suite 03: Specialized Journeys & Workflows | 6 | 6 | 0 | 100.0% | ✅ PASS |
| Suite 04: PHP Backend Domain Engines | 4 | 4 | 0 | 100.0% | ✅ PASS |

## Detailed Test Assertions

### Suite 01: Core 8-Stage Pipeline & Admin CRUD

| Category | Test / Assertion | Status | Details |
| :--- | :--- | :---: | :--- |
| Preflight | MariaDB Connection | ✅ PASS | Connected to MariaDB gncp_portal on 127.0.0.1:3306 |
| Lifecycle | Stage 1: Public Pre-Registration Form Submission | ✅ PASS | Candidate registered. Ref: GNCP-2026-488705 | PIN: 774087 | Status: PRE_REGISTERED |
| Lifecycle | Stage 2: Public Application Tracker Roadmap Lookup | ✅ PASS | Tracker rendered roadmap for applicant GNCP-2026-488705 |
| Lifecycle | Stage 3: Registrar Document & Eligibility Verification | ✅ PASS | Applicant status in DB: VERIFIED |
| Lifecycle | Stage 4: TLC Helpdesk Advising & Section Allocation | ✅ PASS | Status: ADVISED | Section: BSIT 1-A |
| Lifecycle | Stage 5: Medical Clinic Physical Fitness Clearance | ✅ PASS | Status: MEDICAL_CLEARED |
| Lifecycle | Stage 6: Cashier Tuition Assessment & Official Payment | ✅ PASS | Assessment: PHP 18,300.00 | Status: PAID | Payments recorded: 2 |
| Lifecycle | Stage 7: IT Center Account Promotion to MariaDB Directory | ✅ PASS | Created student record: GNCP-2026-72998 | Status: Active |
| Lifecycle | Stage 8: Student Portal Self-Service Authentication & COR | ✅ PASS | Student portal session active for GNCP-2026-72998 |
| AdminCRUD | Announcement CREATE Operation | ✅ PASS | Announcement #50 created in DB |
| AdminCRUD | Announcement UPDATE Operation | ✅ PASS | Announcement #50 title updated to UNIFIED_TEST_ANNOUNCEMENT_72998_UPDATED |
| AdminCRUD | Announcement DELETE Operation | ✅ PASS | Announcement #50 successfully deleted from MariaDB |
| AdminCRUD | Academic Milestone CREATE & DELETE Lifecycle | ✅ PASS | Milestone #28 successfully created and purged from MariaDB |

### Suite 02: Authentication, Single-Active Session & Security Hardening

| Category | Test / Assertion | Status | Details |
| :--- | :--- | :---: | :--- |
| RBAC | Unauthenticated Queue Access Blocked | ✅ PASS | HTTP Status: 401 |
| RBAC | Unauthenticated Admin Access Blocked | ✅ PASS | HTTP Status: 401 |
| PrivilegeEscalation | Student Restricted from Administrative APIs | ✅ PASS | HTTP Status: 403 (Properly blocked) |
| PrivilegeEscalation | Student Restricted from Workstation Queue | ✅ PASS | HTTP Status: 403 |
| SessionFixation | PHP Session ID Regenerated on Login | ✅ PASS | Pre: None, Post: ke7rus3i0bt7idp8h517linnkb |
| SingleActiveSession | Atomic MariaDB Token Overwrite | ✅ PASS | Token A (6628a42a9520...) -> Token B (111935d6e51d...) |
| SingleActiveSession | Browser A Page Navigation Blocked & Intercepted | ✅ PASS | URL: http://127.0.0.1/systemtest/?clear=true&redirect=%2Fsystemtest%2Fadmin%2F |
| SingleActiveSession | Browser A Protected Data Mutation Blocked | ✅ PASS | HTTP Status: 401 (Rejected by backend) |
| SingleActiveSession | Browser B Remains Authenticated & Functional | ✅ PASS | HTTP Status: 200 |
| Logout | MariaDB Active Token Nullified on Logout | ✅ PASS | Post-logout DB Token: None |

### Suite 03: Specialized Journeys & Workflows

| Category | Test / Assertion | Status | Details |
| :--- | :--- | :---: | :--- |
| PasswordGuard | PasswordChangeGuard Component Loaded | ✅ PASS | window.PasswordChangeGuard is active on gateway |
| PasswordGuard | SweetAlert2 Password Reset Interception Modal Displayed | ✅ PASS | Modal visible in DOM: True |
| CashierStation | Cashier Workstation Active Queue Loaded | ✅ PASS | Loaded 28 applicants in queue table |
| CashierStation | Assessment Breakdown Modal Open | ✅ PASS | Modal rendered: True |
| StudentPortal | Student Portal Authentication Successful | ✅ PASS | Active URL: http://127.0.0.1/systemtest/student-portal/ |
| StudentPortal | Documents & Undertakings Hub View Rendered | ✅ PASS | Documents tab active and rendered in DOM: True |

### Suite 04: PHP Backend Domain Engines

| Category | Test / Assertion | Status | Details |
| :--- | :--- | :---: | :--- |
| BackendEngines | Financial Engine (28 Scenarios) | ✅ PASS | Exit 0 -> SUMMARY RESULTS: Passed: 28 / 28 | Failed: 0 / 28 | ================================================================================= |
| BackendEngines | Requirements & Undertakings (32 Scenarios) | ✅ PASS | Exit 0 -> ðŸŽ‰ ALL SCENARIOS PASSED PERFECTLY! | ======================================================== |
| BackendEngines | PayMongo Centavos & Arithmetic (28 Scenarios) | ✅ PASS | Exit 0 -> PAYMONGO TEST RESULTS: 28 / 28 Passed ([32m100% SUCCESS[0m) | ======================================================================== |
| BackendEngines | Medical Clearance & Clinic Flow (16 Scenarios) | ✅ PASS | Exit 0 -> TEST SUMMARY: 16 PASSED, 0 FAILED (TOTAL: 16) | ======================================================================== |

