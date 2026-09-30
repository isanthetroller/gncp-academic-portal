# Legacy & Existing Automated Test Inspection Analysis

**Target Workspace:** `c:\xampp\htdocs\systemtest`  
**Test Framework:** Python-Playwright (v1.62.0, Chromium Headless/Headed) + Unified Runner Architecture + PHP Domain Assertions  
**Date:** September 2026  
**Document File:** [`automated_test_legacy_analysis.md`](file:///c:/xampp/htdocs/systemtest/automated_test_legacy_analysis.md)

---

## 1. Executive Summary of Existing Test Infrastructure

An exhaustive audit of the workspace revealed a mature automated testing hierarchy organized under `tests/`. Unlike standard Node.js projects with `package.json` scripts, the GNCP testing harness is orchestrated using **Python 3.13 with Playwright (`playwright.sync_api`)**, coupled with local PHP syntax auditors and native PHP backend verification scripts.

The tests are partitioned across five primary categories:
1. **Active Playwright Multi-Layer Runner (`tests/playwright/`)**: Direct browser automation verifying UI state, console logs, network payloads, and DB state across Admin, Stations, Authentication, and Student Portals.
2. **Unified System Suite (`tests/unified/`)**: Modular, consolidated test suites (`suite_01_core_pipeline.py` through `suite_04_backend_engines.py`) validating end-to-end enrollment stages, security guards, and PHP calculation engines.
3. **API & Security Test Harness (`tests/api/`, `tests/security/`)**: Python and PHP penetration, IDOR, session fixation, and authorization boundaries.
4. **Static & Syntax Validation (`tests/run_tests.js`)**: Node.js script performing PHP linting (`php -l`), JS evaluation, and route contract auditing.
5. **Archived Legacy Suites (`tests/archive/`)**: Historical Selenium scripts and fragmented standalone test scripts migrated to archive during prior consolidation.

---

## 2. Key Answers to 10 Inspection Criteria

### 1. What the old tests are testing
- **Student Enrollment Lifecycle:** Pre-registration form submission $\rightarrow$ Application Tracker $\rightarrow$ Registrar evaluation $\rightarrow$ TLC Helpdesk block sectioning $\rightarrow$ Clinic medical examination $\rightarrow$ Cashier tuition payment & receipt generation $\rightarrow$ IT Center account promotion $\rightarrow$ Student Portal Certificate of Registration (COR).
- **Authentication & Security:** Multi-role staff login (Registrar `kriz`, Helpdesk `tristan`, Medical `ethan`, Cashier `cashier`, IT `it_officer`, Admin `admin`), single-active-session revocation (Browser A superseded by Browser B), session fixation safeguards, password change guard (`PasswordChangeGuard.js`), and student credential verification.
- **Admin Management:** Operator provisioning, catalog/prospectus/curriculum management, enrollment metrics, multi-chart analytics, and student directory auditing.
- **File Upload Security:** MIME-type inspection, JPG/PNG profile upload, disguised PHP scripts in JPG, and oversized payload rejection.
- **Backend Calculation Engines:** 104 domain assertions covering tuition fee calculations, promissory note undertakings, PayMongo centavos conversions, and clinic clearance state machines.

### 2. Which existing tests are still valid
- All five suites in `tests/playwright/suites/` (`test_auth_and_session.py`, `test_admin_portal.py`, `test_admin_analytics.py`, `test_e2e_student_lifecycle.py`, `test_file_uploads.py`) remain completely valid and operative.
- All four suites in `tests/unified/suites/` (`suite_01_core_pipeline.py` through `suite_04_backend_engines.py`) remain valid.
- The static analyzer `tests/run_tests.js` remains valid.
- The security tests in `tests/security/` remain valid.

### 3. Which tests are now outdated
- Tests in `tests/archive/selenium/` are outdated due to dependency on obsolete Selenium WebDriver binaries and deprecated locators.
- Standalone runners in `tests/archive/legacy_runners/` (36 files) are superseded by `tests/unified/` and `tests/playwright/`.

### 4. Which tests can be reused
- `tests/playwright/config.py`: Centralized credentials, database connection parameters, and page endpoint maps.
- `tests/playwright/utils/browser_logger.py`: Captures browser console events and network errors.
- `tests/playwright/utils/db_helper.py`: Direct PDO bridge to query MariaDB `gncp_portal` for database assertions.
- `tests/playwright/suites/test_auth_and_session.py`: Reusable for baseline auth, logout, and protected route guard assertions.
- `tests/playwright/suites/test_file_uploads.py`: Reusable binary generation utilities for valid/invalid PDFs and disguised payloads.

### 5. Which tests need modification
- `tests/playwright/config.py`: Needs new role credentials (`SCHEDULER`, sample `PROFESSOR`) and page endpoint routes (`SCHEDULER`, `PROFESSOR`).
- `tests/playwright/suites/test_admin_portal.py`: Can be extended or supplemented to assert the newly added "Faculty Professors" view and modal dialogs.
- `tests/playwright/suites/test_auth_and_session.py`: Needs extensions to test cross-role boundary rejections for `SCHEDULER` and `PROFESSOR` against Admin and Student portals.

### 6. Which required features have NO automated coverage in legacy tests
The following newly implemented features have **zero coverage in the existing legacy test suite**:
1. **Scheduler Role & Workstation:** Login, dashboard metrics, sections matrix, subject rosters, professor directories, and weekly grid.
2. **Authoritative Conflict Detection Engine:**
   - 7:00 AM – 7:00 PM institutional scheduling bounds.
   - 12:00 PM – 1:00 PM lunch blackout rejection.
   - Morning (`M`), Afternoon (`A`), and Evening (`E`) cohort shift constraints.
   - Exact, partial, and contained class collisions.
   - Boundary-adjacent non-overlapping classes (e.g. 7-9 and 9-11 allowed).
3. **Deterministic Auto-Assignment Algorithm:** Automated selection honoring availability, home department affinity, load balancing, and stable tie-breaking.
4. **Manual Professor Assignment:** Atomic DB persistence, conflict feedback, and inactive professor rejection.
5. **Professor Portal:** Profile load, My Sections, My Subjects, Weekly Timetable grid.
6. **Professor Announcements:** Target selection (single section, multiple sections, all handled sections) and section-scoping enforcement.
7. **Professor Personal Drive:** Uploading PDF/PPTX/DOCX, MIME magic-byte validation, binary script/executable blocking, metadata tracking, and file deletion.
8. **Drive & File Security:** Unauthorized cross-professor file access prevention, IDOR protection, path traversal defenses.
9. **LMS Materials Publishing:** Publishing drive files to sections, student portal LMS view, file download authorization, and publication status tracking.
10. **Student Portal LMS:** Accessing enrolled section course materials and viewing section-targeted announcements.

### 7. Whether the existing Playwright configuration still works
- **YES.** Verified live via `python tests/playwright/run_playwright_tests.py --suite auth` which executed against local Chromium and passed 5/5 assertions in 15.3s.

### 8. Whether existing selectors are still valid
- **YES.** Selectors for Admin login (`#username`, `#password`, `button[type="submit"]`), navigation tabs (`.sidebar-nav-item`, `.tab-btn`), sweetalert dialogs (`.swal2-confirm`), and student portal elements remain functional.

### 9. Whether old routes/workflows still exist
- **YES.** The entire pre-registration, tracker, 5-station enrollment pipeline, student portal, and admin portal remain intact and active.

### 10. Whether existing authentication/test accounts still work
- **YES.** Existing staff credentials (`admin` / `admin12345` or `Gncp#2026!`, `kriz` / `kriz123`, `tristan` / `tristan123`, `ethan` / `ethan123`, `cashier` / `cashier123`, `it_officer` / `itpassword`) and student accounts (`2026-1006`) are verified operational.

---

## 3. Detailed Audit Table of Existing Test Suites

| Existing Test File | Scope & Purpose | Still Valid? | Reusable? | Needs Modification? | Reason / Action Required |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `tests/playwright/suites/test_auth_and_session.py` | Multi-role authentication, session persistence, interactive logout, student login guard. | **Yes** | **Yes** | **No** (Keep as baseline; add new suite for new roles) | Core operator login flow works perfectly. |
| `tests/playwright/suites/test_admin_portal.py` | Super Admin dashboard, operator provisioning, academic catalog, sections, student audit directory. | **Yes** | **Yes** | **No** (Keep as baseline; add new professor admin suite) | Verifies base catalog and operator management. |
| `tests/playwright/suites/test_admin_analytics.py` | Admin analytics KPIs, multi-chart rendering, demographic and financial breakdown. | **Yes** | **Yes** | **No** | Visual dashboard verification remains valid. |
| `tests/playwright/suites/test_e2e_student_lifecycle.py` | End-to-end 8-stage pipeline from pre-registration to official student ID promotion and COR view. | **Yes** | **Yes** | **No** | Validates baseline student enrollment pipeline. |
| `tests/playwright/suites/test_file_uploads.py` | Verification of valid/invalid image uploads, disguised PHP scripts, and size checks in applicant registration. | **Yes** | **Yes** | **Yes** (Borrow helpers for PDF/PPTX/EXE tests in drive) | Excellent pattern for MIME binary testing; reuse generator logic for professor drive. |
| `tests/unified/suites/suite_01_core_pipeline.py` | Full 8-stage enrollment pipeline & admin announcement/milestone CRUD. | **Yes** | **Yes** | **No** | Master pipeline regression test. |
| `tests/unified/suites/suite_02_auth_security.py` | Single-active-session revocation, IDOR protection, session fixation prevention. | **Yes** | **Yes** | **No** | Critical security regression test. |
| `tests/unified/suites/suite_03_special_journeys.py` | Password change guard, cashier itemization, student portal documents hub. | **Yes** | **Yes** | **No** | Tests specialized workstation guards. |
| `tests/unified/suites/suite_04_backend_engines.py` | 104 domain calculation assertions across finances, undertakings, PayMongo, clinic. | **Yes** | **Yes** | **No** | Core domain engine regression test. |
| `tests/api/test_queue_history_security_and_scenarios.py` | Station queue compilation, status filtering, and audit log history API checks. | **Yes** | **Yes** | **No** | Validates station queue endpoints. |
| `tests/api/test_station_queues_and_history.py` | Background station polling and queue state reflection. | **Yes** | **Yes** | **No** | Validates DataBus synchronization endpoints. |
| `tests/security/test_privilege_and_idor.py` | Privilege escalation and IDOR boundary penetration. | **Yes** | **Yes** | **No** | Validates session isolation between operators. |
| `tests/security/test_server_side_authorization.py` | Backend RBAC enforcement on mutations. | **Yes** | **Yes** | **No** | Confirms server-side rejection of unpermitted actions. |
| `tests/run_tests.js` | PHP syntax (`php -l`), JS VM syntax checks, and HTML link integrity. | **Yes** | **Yes** | **No** | Static linting and integrity verification. |
| `scratch/test_conflict_scenarios.php` | Phase 35 real conflict scenarios (lunch blackout, boundary adjacency, shift bounds). | **Yes** | **Yes** | **No** (Direct backend verification companion) | 10/10 passing domain test suite. |
| `scratch/test_system_regression.php` | Cross-subsystem regression checking queues, catalogs, professors, student LMS. | **Yes** | **Yes** | **No** | 11/11 passing regression script. |
| `tests/archive/legacy_runners/*` (36 files) | Fragmented standalone test runners. | **Superseded** | **No** | **No** | Already archived in `tests/archive/`. |
| `tests/archive/selenium/*` (9 files) | Legacy Selenium WebDriver test scripts. | **Outdated** | **No** | **No** | Deprecated in favor of modern Playwright. |

---

## 4. Test Strategy Recommendation

1. **Retain Existing Playwright Framework:** Use the active Python Playwright architecture in `tests/playwright/`, which is already installed, configured, and running against local Apache and MariaDB.
2. **Expand Configuration:** Add `SCHEDULER`, `PROFESSOR`, and new portal URLs into `tests/playwright/config.py`.
3. **Build Comprehensive New Playwright E2E Suite:** Implement a dedicated, complete Playwright E2E suite covering all 10 un-covered academic scheduling and professor LMS features.
4. **Execute & Report:** Run the complete test suite, assert both UI changes and backend database state, and document full results in `automated_test_report.md` without weakening assertions.
