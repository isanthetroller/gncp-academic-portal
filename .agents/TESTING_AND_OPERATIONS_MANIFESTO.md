# GNCP System Operations & Testing Manifesto

> **Authoritative Standard Operating Procedure (SOP) & Testing Specification**  
> Go-on National College of the Philippines — Academic & Enrollment Information System

---

## 1. Core Architectural Invariants & Philosophy

Every task, bug fix, refactor, or optimization across the GNCP system MUST adhere to these non-negotiable principles:

### A. Holistic Multi-File Tracing
- **Never fix in isolation**: When an issue is reported in a UI modal or backend endpoint, always trace the full dependency chain:
  `Database Schema (MariaDB)` $\leftrightarrow$ `Backend Service / Controller (PHP)` $\leftrightarrow$ `Central API Gateway (api/index.php)` $\leftrightarrow$ `Data Bus / Event Stream (DataBus.js)` $\leftrightarrow$ `Frontend Controller (Vue 3)` $\leftrightarrow$ `CSS Theme Tokens & HTML Template`.
- **Verify syntax across all touched files**: Never conclude a change without running PHP syntax linting (`php -l`), JavaScript AST validation (`node -c`), and Playwright browser execution.

### B. Dual-Table Student Lifecycle & State Machine
- **Pipeline Progression**:
  `PRE_REGISTERED` (Staging queue) $\rightarrow$ `VERIFIED` (Registrar) $\rightarrow$ `ADVISED` (TLC Helpdesk) $\rightarrow$ `MEDICAL_CLEARED` (Clinic) $\rightarrow$ `PAID` (Cashier OR) $\rightarrow$ `ENROLLED / ACTIVE` (IT Center Promotion).
- **Dual-Table Aggregation**:
  Pending applicants live in `pre_enrollments`. Upon IT Center promotion, official accounts are established in `students`. Queries (Medical clearings, queue boards, status trackers) MUST join or query both tables as appropriate.
- **DB-Level Promotion Assertions**: Automation suites must NEVER rely solely on HTTP 200 or visual badges. They must query MariaDB directly to confirm that `students` contains the generated permanent ID (`GNCP-YYYY-XXXXX`), student email, and default credentials.

### C. Zero-Page-Refresh AJAX & Live Sync
- **Progressive Vue 3 Reactivity**: All modules must render updates without full page reloads.
- **Adaptive Event Stream**: `StationDataBus` runs an adaptive background polling cycle (5s base, exponential backoff on failure) using HTTP ETag / 304 Not Modified conditional headers.
- **Zero-Byte Polling**: Workstation queue and registrar catalog APIs must evaluate checksums in <1ms and return `HTTP 304 Not Modified` (0 bytes payload) when records are unchanged.

### D. Security, Session & RBAC Invariants
- **Unified DB Singleton**: All database queries must consume `Database::getInstance()` in `shared/backend/config/database.php`.
- **Password Hashing**: Passwords must always use `password_hash($password, PASSWORD_DEFAULT)`.
- **Single-Active Session Concurrency**: If a staff account logs in from a new browser or device, prior sessions must be invalidated immediately via `active_session_token` check.
- **Brute-Force Rate Limiting**: Gateways enforce IP and account-level throttling (10 failed attempts triggers HTTP 429).
- **Sensitive Path Shields**: `.htaccess` strictly forbids direct access to `.env`, `.git`, `tests/`, `database/`, and configuration files (HTTP 403).
- **Clean Extensionless URLs**: Browser URLs use clean canonical routes (`/admin/`, `/registrar/`, `/student-portal/login`) with automatic 301 redirection from legacy `.html` requests.

---

## 2. The Master Test Catalog ("What We Must Test")

Every test suite below represents a mandatory verification phase before any code is approved, hardened, or deployed:

### Test Suite 1: Financial & Assessment Engine Audit
- **File**: `tests/test_financial_system.php`
- **Execution**: `C:\xampp\php\php.exe tests/test_financial_system.php`
- **Coverage (28 Comprehensive Automated Tests)**:
  1. Unit assessment calculation based on enrolled units and course rate.
  2. Laboratory, miscellaneous, LMS, and OMR fee calculations.
  3. Cash payment mode (no surcharge).
  4. Installment payment mode (8% surcharge split across milestones).
  5. Downpayment calculations (35% or milestone 1 minimum).
  6. Partial payments and dynamic balance re-computation.
  7. Negative payment and overpayment safeguards.
  8. Concurrent transaction safety and ledger history aggregation.
  9. Semester and academic year isolation.
  10. Historical frozen snapshot protection for already enrolled students.
  11. Thermal receipt calculation and layout verification.
  12. Student portal Certificate of Registration (COR) financial consistency.

### Test Suite 2: Full-System Playwright Pipeline Audit
- **File**: `tests/playwright/test_full_system_pipeline_audit.py`
- **Execution**: `python tests/playwright/test_full_system_pipeline_audit.py`
- **Coverage (48 End-to-End Browser Tests)**:
  1. **Pre-flight Database Check**: Tables, active periods, curriculum, sections, and fixture cleanup.
  2. **Clean URLs & Sensitive Path Restrictions**: Verifies extensionless URL rewrites, 301 legacy redirects, and 403 forbidden file shields.
  3. **Role-Based Access Control (All 7 Roles)**: Validates login, session storage, and dashboard access for `SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `HELPDESK`, `MEDICAL`, `CASHIER`, and `IT_CENTER`.
  4. **5-Layer Trace**: Validates end-to-end data integrity across Database $\rightarrow$ Backend $\rightarrow$ API $\rightarrow$ Frontend State $\rightarrow$ UI DOM.
  5. **Admin CRUD Operations**: Create, Read, Update, Delete for Announcements and Academic Milestones with DB reload persistence.
  6. **Table Controls & Interactive UI**: Empty states, live search filtering, clear-query restoration, and column sorting.
  7. **Complete 8-Stage Student Journey**: Online pre-registration $\rightarrow$ Public Tracker $\rightarrow$ Registrar verification $\rightarrow$ Helpdesk advising $\rightarrow$ Medical clearance $\rightarrow$ Cashier payment $\rightarrow$ IT Center promotion $\rightarrow$ Student Portal login & COR verification.
  8. **Responsive Viewports**: Desktop (1440x900), Tablet (768x1024), and Mobile (375x812) layout rendering with 0 horizontal overflow.
  9. **Data Integrity & Deduplication**: Asserts 0 duplicate entries in `curriculum` and `fee_schedule`, and validates single-semester subject scoping.

### Test Suite 3: Multi-Station Sequential Lifecycle E2E
- **File**: `tests/playwright/test_full_station_lifecycle_e2e.py`
- **Execution**: `python tests/playwright/test_full_station_lifecycle_e2e.py`
- **Coverage (6 Sequential Workstation Lifecycle Tests)**:
  1. **Registrar Station**: Verify pending applicant, approve requirements, confirm queue removal, and verify review history persistence.
  2. **Registrar Return-for-Correction**: Return applicant with reason, verify MariaDB audit log, and confirm applicant compliance state.
  3. **TLC Helpdesk**: Advise student into block section, lock in NSTP/ROTC, and verify prospectus subject counts.
  4. **Medical Clinic**: Conduct physical exam, record FIT clearance, and verify completed clearance table.
  5. **Cashier Station**: Calculate tuition, process payment, issue OR, and verify payment history persistence.
  6. **IT Center**: Promote applicant, generate permanent ID (`GNCP-2026-XXXXX`), verify `students` table record creation, and test institutional credentials.

### Test Suite 4: Comprehensive Student Portal & Undertakings
- **File**: `tests/playwright/test_comprehensive_portal_requirements_e2e.py`
- **Execution**: `python tests/playwright/test_comprehensive_portal_requirements_e2e.py`
- **Coverage (6 Student Experience Scenarios)**:
  1. Real UI student authentication (`2026-1006` / `Password123!`).
  2. Complete requirements checklist display.
  3. In-person hardcopy submission recognition and badge verification.
  4. Conditional undertaking banner, waiver reasons, and deadline rendering.
  5. Document fulfillment via upload modal.
  6. Mobile responsive layout and audit log validation with 0 console/page errors.

### Test Suite 5: Security & Session Isolation Suite
- **File**: `tests/playwright/test_security_session_isolation.py`
- **Execution**: `python tests/playwright/test_security_session_isolation.py`
- **Coverage (5 Core Security Layers)**:
  1. Unauthenticated direct URL access blocked (HTTP 401).
  2. Staff single-active session concurrency and remote session invalidation.
  3. LocalStorage credential sanitization (no plaintext passwords).
  4. Brute-force rate limiting (HTTP 429 on 10 failed attempts).
  5. Multi-profile window isolation.

### Test Suite 6: Performance, TTFB & Navigation Benchmark
- **File**: `scratch/benchmark_all_modules.py` & `scratch/benchmark_post_fixes.py`
- **Execution**: `python scratch/benchmark_post_fixes.py`
- **Coverage**:
  1. Automated measurement of navigation duration, TTFB, DOMContentLoaded, and Page Load event across all modules.
  2. Request count and payload size breakdown per page.
  3. ETag / HTTP 304 background polling validation.
  4. Before vs. After comparison report generation.

---

## 3. The Standard Operational Playbook ("What You Always Want Me to Do")

When executing any update, maintenance, or deployment cycle, follow this exact 7-step sequence:

```
[Phase 1] Empirical Diagnostic & Baseline Benchmark
   └─ Run baseline performance or failure diagnosis via Playwright.
   └─ Record exact numbers; collect network and database traces.

[Phase 2] Multi-File Dependency Tracing
   └─ Inspect the entire connected tree across templates, JS controllers, DataBus, and API services.
   └─ Check for table locks, duplicate lifecycle hooks, or over-fetching.

[Phase 3] Targeted, Surgical Fixes
   └─ Apply minimal, clean fixes adhering to single-responsibility bounds (<250 lines).
   └─ Strictly preserve security checks, authentication guards, and role permissions.

[Phase 4] Functional & Performance Verification
   └─ Run Financial Suite (28/28).
   └─ Run Full-System Pipeline Audit (48/48).
   └─ Run Station Lifecycle and Student Portal E2E tests.
   └─ Measure post-fix performance and verify 0 console errors.

[Phase 5] High-Security Hardening Pipeline
   └─ Execute execute_hardening_pipeline.py.
   └─ Obfuscate all 35 application-owned JS files with control-flow flattening & string array base64 encoding.
   └─ Validate AST syntax on all obfuscated files (node -c).
   └─ Update cache-busting version parameters in all HTML files.
   └─ Mirror files to systemtest-hardened.

[Phase 6] InfinityFree Live Deployment & Synchronization
   └─ Connect to InfinityFree FTP (sync_hardened_to_infinityfree.py).
   └─ Purge stale remote scripts.
   └─ Upload only modified/new files.
   └─ Execute verify_live_infinityfree.py to confirm remote HTTPS health, clean routes, and login gateway.

[Phase 7] Comprehensive Reporting
   └─ Document root causes, changes, performance delta table, and test outcomes in walkthrough.md.
```

---

## 4. Hardening & Obfuscation Reference

### Obfuscation Settings (`obf_high_security_config.json`):
- **Compact**: Enabled (strips whitespaces and formatting).
- **Control Flow Flattening**: Enabled (0.75 threshold).
- **Numbers to Expressions**: Enabled (transforms numeric literals to mathematical expressions).
- **Simplify**: Enabled (minifies boolean and conditional logic).
- **Split Strings**: Enabled (chunks strings to length 5).
- **String Array Encoding**: Base64 with 100% threshold, index shifting, rotation, and shuffled wrappers.
- **Identifier Names Generator**: Hexadecimal (`_0x...`).
- **Transform Object Keys**: Enabled.

### Protected File Set (35 Core Application Scripts):
- Admin: `AdminSidebar.js`, `AdminController.js`
- Gateway: `app.js`, `doc-viewer-app.js`, `gateway-app.js`, `gateway-session.js`
- Enrollment: `App.js`, `TrackerApp.js`, `EnrollmentModel.js`, `ApiService.js`
- Monitoring: `MonitorApp.js`
- Registrar: `RegistrarController.js`, `RegistrarApiService.js`, `RegistrarView.js`
- School Website: `AppController.js`, `DataModel.js`, `MainView.js`, `PagesView.js`
- Shared Security: `academic_constants.js`, `PasswordChangeGuard.js`, `SessionExpirationGuard.js`, `StationPipeline.js`, `EmployeeSidebar.js`, `checkout-app.js`
- Workstations: `DataBus.js`, `it-center/app.js`, `medical-checkup/app.js`, `payment-processing/app.js`, `tlc-helpdesk/app.js`
- Student Portal: `login-init.js`, `StudentForgotPasswordController.js`, `StudentLoginController.js`, `StudentPortalController.js`, `StudentModel.js`, `StudentApiService.js`

---

## 5. InfinityFree Remote Deployment Specification

- **FTP Server**: `ftpupload.net` (Port 21)
- **Account**: `if0_42745296`
- **Remote Root**: `/htdocs`
- **Excluded Directories**: `.git`, `.idea`, `.vscode`, `tests`, `cache`, `logs`, `scratch`, `backups`.
- **Live URL**: `https://gncp-main.site.je`
- **Remote Validation Checks**:
  1. Clean URL resolution for `/`, `/login`, `/school-website/`, `/enrollment-system/`, `/student-portal/login`.
  2. Static asset caching and compression header checks.
  3. Sensitive directory shielding (`/tests/`, `/database/`, `/.env` return HTTP 403).
  4. Live login page rendering with Vue initialization.
