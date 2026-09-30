# Playwright Automated End-to-End Test Report

**Document ID:** `GNCP-PLAYWRIGHT-REPORT-2026-V1`  
**System:** Go-on National College of the Philippines (GNCP) Academic & Enrollment Management System  
**Repository Working Directory:** `c:\xampp\htdocs\systemtest`  
**Execution Timestamp:** 2026-09-30 00:04:47 (Local)  
**Test Framework:** Playwright (Python 3.13 / Playwright 1.62.0)  
**Overall Verdict:** **100% PASS**

---

## 1. Test Environment

| Component | Specification |
| :--- | :--- |
| **Operating System** | Microsoft Windows 11 Enterprise |
| **Browser Engine** | Chromium (Headless Engine) |
| **Playwright Version** | 1.62.0 (Python API) |
| **Python Runtime** | Python 3.13.2 |
| **Web Server** | Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12 |
| **Application Base URL**| `http://127.0.0.1/systemtest` |
| **Database Engine** | MariaDB 10.4.32 (`gncp_portal` on 127.0.0.1:3306) |
| **PHP Runtime** | PHP 8.2.12 (CLI / CGI) |

---

## 2. Test Suite Inventory & Coverage

### 2.1. Existing Legacy Test Audit
As documented in [`automated_test_legacy_analysis.md`](file:///c:/xampp/htdocs/systemtest/automated_test_legacy_analysis.md):
- **Discovered Tests:** 5 active Playwright suites, 4 unified modular suites, 45 archived test runners.
- **Reused Tests:** All 5 active suites and 4 unified modular suites maintained in active rotation.
- **Modified Tests:** `tests/playwright/config.py` extended with `SCHEDULER` and `PROFESSOR` credentials and endpoint mappings.
- **Replaced / Removed Tests:** 0 tests deleted. Archived legacy runners retained for reference.

### 2.2. New Automated Test Suite Created
- **Suite Name:** `Suite F — Academic Scheduling, Professors & LMS` ([`test_academic_scheduling_and_lms.py`](file:///c:/xampp/htdocs/systemtest/tests/playwright/suites/test_academic_scheduling_and_lms.py))
- **Test Steps Executed:** 13 Comprehensive Multi-Layer Browser & API Assertions
- **Total Assertions:** 13 / 13 Passed (100.0%)

---

## 3. Playwright Suite F Step Execution Results

| Step Index | Test Scenario / Workflow Name | Target Portal / Module | Status | Duration | Artifacts / Evidence |
| :---: | :--- | :--- | :---: | :---: | :--- |
| **01** | Scheduler Authentication & Dashboard Overview | Gateway $\rightarrow$ Scheduler Workstation | **PASS** | 4.8s | Verified sidebar brand "ACADEMIC SCHEDULER", live metrics counters. |
| **02** | Professor Authentication & Workstation Overview | Gateway $\rightarrow$ Professor Workstation | **PASS** | 4.5s | Authenticated as `alan.turing`, loaded `/stations/professor/index.html`. |
| **03** | RBAC Cross-Role Route & API Guard Isolation | Cross-Role Route Guards | **PASS** | 4.1s | Professor navigation to Admin Portal blocked; Admin API mutation rejected (403). |
| **04** | Admin Faculty Professor Account Lifecycle | Admin Portal $\rightarrow$ User Management | **PASS** | 16.2s | Created `pw_prof_816` in `station_users` & `professors`; verified active login & inactive rejection. |
| **05** | Scheduler Sections Matrix & Faculty Directory UI | Scheduler Workstation | **PASS** | 5.0s | Inspected Cohort Schedules toolbar, filter dropdowns, and Faculty Directory view. |
| **06** | Authoritative Schedule Conflict Engine | Conflict Service Backend Bridge | **PASS** | 0.8s | 10 Phase 35 real conflict scenarios verified (overlap, boundary, lunch, shifts, inactive). |
| **07** | Manual & Deterministic Auto-Assignment Execution | Scheduler API $\rightarrow$ Database | **PASS** | 4.2s | Manual assign of `IT-IT101-A` verified in DB; deterministic auto-assign committed atomically. |
| **08** | Professor Portal Sections, Subjects & Timetable | Professor Workstation | **PASS** | 4.6s | Verified "My Class Sections", "Handled Subjects", and "Weekly Timetable" grid rendering. |
| **09** | Professor Announcements & Section Targeting | Professor Workstation $\rightarrow$ Student Portal| **PASS** | 4.4s | Published announcement targeting `BSIT-1A`; persisted in `announcement_sections`. |
| **10** | Personal Drive Uploads, MIME & IDOR Security | Professor Personal Drive | **PASS** | 8.2s | Uploaded valid PDF; rejected malicious `.exe` via `finfo`; blocked cross-faculty IDOR (403). |
| **11** | LMS Materials Publishing & Student Access Hub | Professor LMS $\rightarrow$ Student Portal | **PASS** | 8.9s | Published `Lecture 1 Foundations (816)` to `BSIT-1A`; student `2026-1006` viewed and downloaded. |
| **12** | Registrar Workflow & Multi-Portal Schedule Sync | Registrar Workstation | **PASS** | 4.9s | Schedule consistency verified: Code `IT-IT101-A` on MW 09:00 AM - 10:30 AM matched across all views. |
| **13** | Full System Multi-Station Regression | Station API Gateway | **PASS** | 0.5s | Enrollment queue active (51 applicants), Academic Period active, COR intact. |

---

## 4. Security Test Results

| Security Test Case | Target Endpoint / Action | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :---: |
| **Cross-Professor IDOR Prevention** | `GET /api/index.php?action=professor/download_file&id=[Other_Prof_File]` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** |
| **Unauthenticated File Access** | `GET /api/index.php?action=professor/download_file` | HTTP 401 Unauthorized | HTTP 401 Unauthorized | **PASS** |
| **Malicious Executable Upload Block** | `POST /api/index.php?action=professor/upload_file` (`.exe`) | HTTP 400 Bad Request | HTTP 400 Bad Request | **PASS** |
| **File Extension / MIME Mismatch** | Upload `.php` renamed to `.pdf` | `finfo` MIME validation failure | Rejected: Invalid MIME | **PASS** |
| **Cross-Role Route Protection** | Non-Admin user accessing `/admin/index.html` | Immediate redirect to login | Redirected to gateway | **PASS** |
| **Least-Privilege API Enforcement** | Scheduler attempting `admin/save_user` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** |
| **Student Cross-Section LMS Isolation** | Student querying material not assigned to their section | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** |
| **Directory Traversal Neutralization** | Parameter containing `../../uploads/` | Sanitized via `basename()` | Traversal neutralized | **PASS** |

---

## 5. Regression Test Results

1. **Suite B (Authentication & Session Lifecycle):**
   - 5 / 5 Assertions Passed (100%).
   - Operator login, invalid password rejection, session timeouts, and student portal gateway verified intact.
2. **Suite 04 (PHP Backend Domain Engines):**
   - 4 / 4 Suites Passed (104 individual scenarios).
   - Financial Engine (28/28), Requirements & Undertakings (32/32), PayMongo centavos (28/28), Medical Clinic (16/16) passed without a single failure.
3. **Multi-Station Queue Synchronization:**
   - 51 applicants in active enrollment queue across Registrar, Helpdesk, Medical, Cashier, and IT Center.
   - Dual-table queries (`pre_enrollments` + `students`) remain fully synchronous.

---

## 6. Playwright Test Artifacts Generated

- **Markdown Report:** `tests/playwright/reports/PLAYWRIGHT_TEST_REPORT.md`
- **JSON Test Report:** `tests/playwright/reports/playwright_results.json`
- **Execution Screenshots Saved:**
  - `scheduler_dashboard_overview_*.png`
  - `professor_dashboard_overview_*.png`
  - `admin_professor_lifecycle_verified_*.png`
  - `scheduler_matrix_inspected_*.png`
  - `scheduler_assignment_verified_*.png`
  - `professor_sections_and_timetable_*.png`
  - `professor_drive_verified_*.png`
  - `student_lms_materials_view_*.png`
  - `registrar_and_consistency_verified_*.png`
  - `full_system_regression_verified_*.png`
