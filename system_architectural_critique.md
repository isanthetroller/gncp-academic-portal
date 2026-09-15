# System Architectural Critique & Engineering Evaluation
**Project:** Go-on National College of the Philippines (GNCP) Enrollment & Academic System  
**Evaluation Scope:** Code Quality, Architectural Rigor, Concurrency, Security, Scalability & Maintainability  
**Target Date:** August 16, 2026

---

## 🎯 Executive Verdict

The GNCP Academic System demonstrates **strong, commendable engineering** in core business areas—most notably its **authoritative financial calculation engine**, **atomic student promotion transactions**, **strict password security standards**, and **responsive HSL-themed workstation design**.

However, the codebase currently suffers from **architectural bifurcation (dual-gateway routing)**, **extreme file bloat / monolithic HTML pages**, **deep JSON denormalization**, and **duplicate legacy scripts** resulting from progressive iterations without complete technical debt consolidation.

Below is an honest, deep-dive architectural critique organized by engineering domain, with severity ratings and concrete modernization recommendations.

---

## 🚨 Critical Architecture Critiques & Code Smells

### 1. Dual/Multi-Gateway Routing Divergence (High Severity)
- **The Problem:** The system has a canonical, unified REST Gateway at `api/index.php`, but also maintains 5 separate legacy/parallel router files:
  1. `admin/backend/api.php` (524 lines)
  2. `student-portal/backend/api.php` (797 lines)
  3. `registrar/backend/api.php` (171 lines)
  4. `stations/backend/api.php` (97 lines)
  5. `enrollment-system/backend/register.php` (517 lines)
- **Why It's Harmful:**
  - **Inconsistent Auth Guards:** `admin/backend/api.php` uses `session_guard.php` checking `$_SESSION['gncp_admin_user']`, while `api/index.php` routes use `AuthController.php`. If a session token format or header authentication method changes, it must be updated in 6 different places.
  - **Bypassed Correlation Headers:** Requests made directly to legacy station endpoints bypass the central `X-Request-ID` tracing pipeline.
  - **Duplicate Business Logic:** Program saving, section generation, and user creation are implemented in both `api/controllers/*` and inline in `admin/backend/catalog/catalog.php`.
- **Recommendation:** Fully deprecate legacy `*/backend/api.php` files into 3-line forwarders delegating directly to `api/index.php`, or update all frontend `fetch()` endpoints to point exclusively to `/systemtest/api/index.php?action=...`.

---

### 2. Extreme Monolithic File Bloat & Inline Vue Logic (High Severity)
- **The Problem:**
  - `admin/index.html` is **300,059 bytes (4,799 lines of code)**. It contains ~3,000 lines of nested HTML templates and ~1,700 lines of inline Vue 3 setup JavaScript at the bottom of the file.
  - In `admin/assets/js/controllers/AdminController.js`, there is an orphaned **65,839-byte (65KB)** controller file that contains the exact duplicate of the inline script, but is **never actually loaded** by `admin/index.html`!
  - `student-portal/index.html` is **126,307 bytes (2,950 lines)** with large blocks of inline Vue scripts duplicated across `student-portal/assets/js/controllers/StudentPortalController.js`.
- **Why It's Harmful:**
  - **Developer Cognitive Overload:** Navigating a 4,800-line single file makes code reviews, Git diff merges, and bug debugging exceptionally painful.
  - **Browser Parsing Overhead:** 300KB HTML documents require larger initial downloads and cannot benefit from browser HTTP caching that separate `.js` and `.css` asset files receive.
  - **Dead Code Confusion:** Future developers or AI assistants will edit `AdminController.js` expecting changes to take effect, only to find the UI still running the inline `<script>` block inside `index.html`.
- **Recommendation:** Extract all inline scripts from `admin/index.html` and `student-portal/index.html` into modular external components/controllers, and remove dead duplicate files.

---

### 3. Deep JSON Denormalization in MariaDB Tables (Medium Severity)
- **The Problem:**
  - In `pre_enrollments` and `students`, massive chunks of application state are stored in serialized JSON columns (`roadmap`, `requirements_data`, `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`, `enrollment_data`, `personal_info`, `academic_info`).
- **Why It's Harmful:**
  - **No Relational Referential Integrity:** If a subject or section code is renamed in `subjects` or `sections`, the JSON strings inside thousands of student records remain stale.
  - **SQL Query Limitations:** Cannot easily index, aggregate, or perform fast relational `JOIN` operations on nested properties (e.g., finding all students with a specific medical allergy or scholarship discount across academic terms requires full-table regex scans or `JSON_EXTRACT`).
  - **Data Overwrite Risks:** When two workstations update the same applicant simultaneously, partial JSON replacement can clobber unmerged fields unless delta keys are meticulously handled.
- **Where It's Justified:**
  - The frozen assessment snapshot (`payment_data.assessmentSnapshot`) is an appropriate use of JSON because it represents an **immutable point-in-time financial receipt**.
- **Recommendation:** Normalize core relational entities (e.g., separate `student_requirements`, `student_medical_records`, `student_payments` tables) while reserving JSON solely for unstructured historical audit snapshots.

---

### 4. Client-Side State Synchronization via LocalStorage Polling (`DataBus.js`) (Medium Severity)
- **The Problem:**
  - `StationDataBus` continuously polls `api/index.php?action=stations/queue` every 5 seconds, serializes the **entire queue array**, and writes it into the browser's `localStorage` (`gncp_enrollment_queue`).
- **Why It's Harmful:**
  - **Storage Quota Bottleneck:** `localStorage` has a strict **5MB per domain** limit in modern browsers. When GNCP enrolls 2,000+ applicants, storing the full JSON queue in `localStorage` will throw `QuotaExceededError` and crash workstation interfaces.
  - **CPU & Memory Overhead:** JSON parsing a multi-megabyte string every 5 seconds on every open workstation tab introduces unnecessary client CPU spikes and memory garbage collection stalls.
- **Recommendation:**
  - Maintain reactive in-memory Vue state rather than persisting the entire multi-megabyte queue into `localStorage`.
  - Paginate the queue endpoint and allow workstations to filter queries by active station (e.g. Registrar only fetches `PRE_REGISTERED` and `VERIFIED` applicants).

---

### 5. Orphaned Legacy Artifacts & Backup Clutter (Low Severity)
- **The Problem:**
  - The repository contains multiple backup/dead files:
    - `stations/tlc-helpdesk/index_original.html` (30KB)
    - `shared/js/components/EmployeeSidebar_backup_v1.js` (10KB)
    - `enrollments` table in `schema.sql` (legacy table not written to by any active service)
- **Recommendation:** Clean up historical backup files to maintain repository cleanliness and prevent accidental imports.

---

## 🌟 Commendable Architecture Strengths

| Architectural Area | Implementation Assessment | Engineering Impact |
|---|---|---|
| **Authoritative Financial Calculation Engine** | `AssessmentService.php` implements strict decimal rounding, tuition-per-unit logic, NSTP fee rules, scholarship deductions, and 8% installment surcharge calculation without dangerous client-side fallbacks. | **Grade: A+** — Prevents institutional revenue leakage and cashier discrepancy errors. |
| **Atomic Multi-Table State Promotions** | `EnrollmentService.php` wraps promotions, ID generation, section decrementing, and audit logging within explicit `$pdo->beginTransaction()` and `$pdo->commit()` blocks. | **Grade: A** — Guaranteed zero orphaned records during system interruptions. |
| **Sequential Clearance Invariant** | Strict validation prevents applicants from jumping stations (e.g. paying before verification). | **Grade: A** — Enforces real-world institutional compliance. |
| **Credential & Password Safeguards** | Bcrypt hashing (`password_hash`), forced temporary password flag (`must_change_password`), and active status verification across all login gates. | **Grade: A** — Robust against brute-force and credential hijacking. |
| **Zero-Page-Refresh Workstation UX** | Vue 3 progressive enhancement with custom storage event synchronization allows multiple operators to see queue updates without full browser reloads. | **Grade: A-** — Excellent operational ergonomics for school staff. |

---

## 🗺️ Actionable Modernization Roadmap

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                           SYSTEM MODERNIZATION ROADMAP                           │
├──────────────────────┬─────────────────────────────┬─────────────────────────────┤
│   PHASE 1 (IMMEDIATE)│     PHASE 2 (REFACTORING)   │     PHASE 3 (SCALING)       │
├──────────────────────┼─────────────────────────────┼─────────────────────────────┤
│ 1. Consolidate API   │ 1. Extract Vue setup from   │ 1. Normalize JSON tables    │
│    Routes into       │    admin/index.html into    │    into dedicated tables:   │
│    api/index.php.    │    AdminController.js.      │    student_payments,        │
│ 2. Remove orphaned   │ 2. Extract inline script    │    student_medical,         │
│    backup files.     │    from student-portal/     │    student_clearances.      │
│ 3. Add station-level │    index.html.              │ 2. Implement Server-Sent    │
│    queue filtering.  │ 3. Switch DataBus from      │    Events (SSE) or Web-     │
│                      │    localStorage to reactive │    Sockets for live queue   │
│                      │    in-memory Vue state.     │    broadcasts.              │
└──────────────────────┴─────────────────────────────┴─────────────────────────────┘
```
