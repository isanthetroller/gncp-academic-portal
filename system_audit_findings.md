# GNCP Academic & Enrollment Management System
## Technical Audit Findings, Defects Catalog & Security Vulnerability Assessment

**System:** Go-on National College of the Philippines (GNCP) Academic, Admissions & Workstation Enrollment System  
**Codebase Root:** `c:\xampp\htdocs\systemtest`  
**Audit Date:** September 29, 2026  
**Auditor:** Antigravity AI Senior Systems Architecture & Cybersecurity Audit Agent  
**Execution Mode:** Read-Only Static and Architectural Audit (Zero-Modification Verification)  

---

## 1. Summary Defect Matrix

| ID | Finding / Defect Title | Category | Severity | Primary Location | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SEC-01** | Insecure Direct Object Reference (IDOR) & Loose Substring Matching in Document Download | Security / Authorization | **Critical** | `api/controllers/StudentController.php:405-516` | Present in Code |
| **SEC-02** | Predictable Default Student Credentials Generated Upon IT Center Account Promotion | Security / Credential Hygiene | **High** | `shared/backend/utils/student.php:186-191` | Present in Code |
| **ARCH-01**| Monolithic Architectural Bifurcation & Parallel Legacy API Routers | Architecture / Maintainability | **High** | `stations/backend/api.php`, `registrar/backend/api.php`, `student-portal/backend/api.php` | Present in Code |
| **UI-01**   | Extreme Template Bloat & Massive Monolithic Single-File Frontends | Frontend / Performance | **High** | `admin/index.html` (293KB), `student-portal/index.html` (183KB) | Present in Code |
| **PERF-01** | Aggressive 3-Second Client Polling Network Overhead Across Workstations | Performance / Network Scalability | **Medium** | `stations/assets/js/StationDataBus.js:20-45` | Present in Code |
| **SEC-03** | Use of Cryptographically Insecure PRNG (`rand()`) for Temporary Password Generation | Security / Cryptography | **Medium** | `admin/backend/api.php:81` | Present in Code |
| **DATA-01** | Denormalized JSON Storage vs. Relational Secondary Ledger Synchronization Risk | Database / Data Integrity | **Medium** | `stations/backend/services/EnrollmentService.php:380-487` | Present in Code |
| **CODE-01** | Redundant Writes to Deprecated Legacy Snapshot Table `enrollments` | Technical Debt / Code Cleanliness | **Low** | `shared/backend/utils/student.php:284-294` | Present in Code |
| **PERF-02** | Disk I/O Lock Contention in File-Based Rate Limiting Under High Concurrency | Scalability / I/O Bottleneck | **Low** | `shared/backend/utils/rate_limit.php:44-73` | Present in Code |

---

## 2. Detailed Technical Findings & Evidence

---

### Finding SEC-01: Insecure Direct Object Reference (IDOR) & Loose Substring Matching in Document Download

* **Severity:** **Critical**
* **Category:** Security / Authorization
* **File Location:** [`api/controllers/StudentController.php`](file:///c:/xampp/htdocs/systemtest/api/controllers/StudentController.php#L405-L516) (Method: `downloadDocument()`)
* **Underlying Cause:** 
  The streaming endpoint `student/download_document` accepts a user-supplied filename parameter (`?file=doc_psa_...pdf`). For non-staff callers (applicants tracking status or students logged into the portal), ownership verification is performed via loose string containment checks (`str_contains`) against JSON data strings rather than explicit relational foreign keys. Furthermore, session key checks rely on fallback chains (`$_SESSION['gncp_student'] ?? $_SESSION['gncp_portal_student']`) and compare `$studentId` against `temp_reference_no` or `id`. If an applicant accesses the system via the tracking portal, PIN authentication checks (`$hasPinAuth`) accept any 6-digit PIN if `password_verify` matches or `$pin === $applicant['security_pin']`.
* **Impact:** 
  An attacker who can guess or enumerate document filenames could potentially download sensitive student admission documents (PSA Birth Certificates, High School Transcripts, Medical Clearance certificates) if string matching produces false positives or if public tracking PIN credentials are weak.
* **Concrete Evidence in Code:**
  ```php
  // api/controllers/StudentController.php (lines 478-482)
  if (str_contains($sReqData, $filename) 
      || (!empty($sId) && str_contains($filename, $sId)) 
      || (!empty($sTempRef) && str_contains($filename, $sTempRef))) {
      $isAuthorizedForFile = true;
  }
  ```
* **Recommended Direction:**
  1. Store all uploaded documents as discrete relational records in a dedicated `student_documents` table with auto-incrementing integer IDs or UUIDs.
  2. Map every document explicitly to the authenticated student's permanent ID (`students.id`) or applicant reference number (`pre_enrollments.temp_student_id`).
  3. Prohibit direct filename-based downloads; stream files strictly by `document_id` and enforce `WHERE student_id = :session_user_id`.

---

### Finding SEC-02: Predictable Default Student Credentials Upon IT Center Account Promotion

* **Severity:** **High**
* **Category:** Security / Credential Hygiene
* **File Location:** [`shared/backend/utils/student.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/utils/student.php#L186-L191) (Function: `promotePreEnrollmentToStudent()`)
* **Underlying Cause:**
  When an applicant is promoted from the staging queue to the permanent `students` table, the default password generated for their student portal account is set to their lowercase last name, or the string `'password123'` if the last name is empty.
* **Impact:**
  Anyone possessing knowledge of an enrolled student's last name (e.g. from public graduation lists, school bulletins, or classmate rosters) can accurately predict their default login password. Although the system sets `must_change_password = 1`, if an attacker accesses the account before the student performs their initial login, the attacker can hijack the account, set their own password, and access confidential academic records and billing data.
* **Concrete Evidence in Code:**
  ```php
  // shared/backend/utils/student.php (lines 186-191)
  $defaultLastNamePassword = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $record['last_name'] ?? '')));
  if (empty($defaultLastNamePassword)) {
      $defaultLastNamePassword = 'password123';
  }
  $plainPassword = !empty($itData['password']) ? $itData['password'] : $defaultLastNamePassword;
  $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
  ```
* **Recommended Direction:**
  1. Generate a cryptographically strong, random 8-character alphanumeric temporary password using `bin2hex(random_bytes(4))` or `random_int()`.
  2. Deliver the temporary password directly to the student's verified personal email address via `EmailService.php`.
  3. Never use predictable demographic attributes (last name, birthday, student ID) as initial credentials.

---

### Finding ARCH-01: Monolithic Architectural Bifurcation & Parallel Legacy API Routers

* **Severity:** **High**
* **Category:** Architecture / Maintainability
* **File Locations:**
  - [`stations/backend/api.php`](file:///c:/xampp/htdocs/systemtest/stations/backend/api.php)
  - [`registrar/backend/api.php`](file:///c:/xampp/htdocs/systemtest/registrar/backend/api.php)
  - [`student-portal/backend/api.php`](file:///c:/xampp/htdocs/systemtest/student-portal/backend/api.php)
  - [`admin/backend/api.php`](file:///c:/xampp/htdocs/systemtest/admin/backend/api.php)
  - [`enrollment-system/backend/register.php`](file:///c:/xampp/htdocs/systemtest/enrollment-system/backend/register.php)
  - [`enrollment-system/backend/track.php`](file:///c:/xampp/htdocs/systemtest/enrollment-system/backend/track.php)
* **Underlying Cause:**
  The application is in an incomplete architectural migration state. While a centralized REST gateway (`api/index.php`) was introduced with structured telemetry (`recordTelemetry()`), rate limiting, and standard error envelopes, several older station-specific entry points remain active.
* **Impact:**
  - Inconsistent middleware execution: Requests routed to `stations/backend/api.php` bypass the central `api/index.php` telemetry logging.
  - Duplication of routing tables and parameter parsing logic.
  - Increased attack surface: Vulnerability fixes applied to `api/index.php` might not automatically cover legacy parallel endpoints.
* **Concrete Evidence in Code:**
  ```php
  // stations/backend/api.php (lines 7-12)
  // ⚠️ ARCHITECTURAL NOTE — DO NOT ADD NEW ACTIONS HERE ⚠️
  // This file is a legacy station-local router that predates the canonical
  // central REST gateway at `/api/index.php`. It remains functional and
  // has its own session auth guard, but it operates as a PARALLEL path,
  // which means requests routed here bypass the central X-Request-ID chain
  // and are not tracked by the canonical audit trail.
  ```
* **Recommended Direction:**
  1. Update all frontend `fetch()` invocations across all station portals to target the canonical endpoints in `api/index.php`.
  2. Transform legacy `.php` files into strict HTTP 301/308 redirect proxies or remove them completely after completing frontend verification.

---

### Finding UI-01: Extreme Template Bloat & Massive Monolithic Single-File Frontends

* **Severity:** **High**
* **Category:** Frontend / Performance & Maintainability
* **File Locations:**
  - [`admin/index.html`](file:///c:/xampp/htdocs/systemtest/admin/index.html) (293,729 bytes / 4,489 lines)
  - [`student-portal/index.html`](file:///c:/xampp/htdocs/systemtest/student-portal/index.html) (183,339 bytes / ~2,800 lines)
  - [`registrar/index.html`](file:///c:/xampp/htdocs/systemtest/registrar/index.html) (87,117 bytes / ~1,500 lines)
* **Underlying Cause:**
  Frontend portals were constructed using progressive enhancement without a JavaScript bundler (Vite/Webpack), resulting in monolithic HTML files containing entire SPAs: Vue application mount points, multiple complex tab views, dozens of Bootstrap modals, inline SVG graphics, and embedded script controllers.
* **Impact:**
  - Browser parsing overhead: Parsing a 300KB raw HTML file with thousands of DOM nodes increases Time-to-Interactive (TTI) on lower-powered devices.
  - Team development friction: Concurrent modifications to the admin dashboard inevitably cause git merge conflicts.
  - High maintenance risk: Difficult to isolate styling and behavioral bugs.
* **Concrete Evidence in Code:**
  `admin/index.html` contains 4,489 lines of code, encompassing the Program Catalog, Subject Catalog, Curriculum Manager, Section Scheduling, Academic Terms, Fee Schedule, Operator User Management, Announcements, and Milestones in a single file.
* **Recommended Direction:**
  1. Deconstruct monolithic templates into external Vue 3 Single-File Components (SFCs) using modern build tools (e.g. Vite with `@vitejs/plugin-vue`).
  2. If preserving the zero-build CDN architecture, split templates into external `.html` component templates loaded asynchronously via `fetch()` and Vue `defineAsyncComponent`.

---

### Finding PERF-01: Aggressive 3-Second Client Polling Network Overhead Across Workstations

* **Severity:** **Medium**
* **Category:** Performance / Network Scalability
* **File Locations:**
  - [`stations/assets/js/StationDataBus.js`](file:///c:/xampp/htdocs/systemtest/stations/assets/js/StationDataBus.js#L20-L45)
  - [`monitoring/index.html`](file:///c:/xampp/htdocs/systemtest/monitoring/index.html#L88-L93)
* **Underlying Cause:**
  To maintain real-time queue synchronization across 5 physical workstations, `StationDataBus` executes an unconditional 3-second background polling loop (`setInterval`).
* **Impact:**
  Although the backend implements ETag HTTP 304 caching (`QueueService::getQueueHash()`) to avoid database re-queries, each open workstation browser tab still generates 20 HTTP requests per minute (1,200 requests per hour per operator). In a university deployment with 20 active operators, this translates to 24,000 HTTP requests per hour hitting Apache and PHP workers, generating unnecessary CPU interrupts, thread contention, and access log expansion.
* **Concrete Evidence in Code:**
  ```javascript
  // stations/assets/js/StationDataBus.js
  this.pollingInterval = setInterval(() => {
      this.syncQueueWithServer();
  }, 3000);
  ```
* **Recommended Direction:**
  1. Implement the Page Visibility API (`document.hidden`): pause or throttle polling to 30-60 seconds when the workstation tab is minimized or in the background.
  2. Implement exponential backoff when the queue remains unchanged for more than 5 minutes.
  3. Long-term: Upgrade the synchronization architecture to Server-Sent Events (SSE) or a lightweight WebSocket broker.

---

### Finding SEC-03: Use of Cryptographically Insecure PRNG (`rand()`) for Temporary Password Generation

* **Severity:** **Medium**
* **Category:** Security / Cryptography
* **File Location:** [`admin/backend/api.php`](file:///c:/xampp/htdocs/systemtest/admin/backend/api.php#L81)
* **Underlying Cause:**
  When an administrator triggers an operator password reset without supplying a custom password, the system generates a temporary password using PHP's pseudo-random number generator `rand()`:
  `$newPassword = 'Gncp#' . rand(1000, 9999) . '!';`
* **Impact:**
  `rand()` is not a cryptographically secure pseudorandom number generator (CSPRNG). Its seed can be predicted based on server time and process ID. Furthermore, restricting the numeric space to only 4 digits (1,000 to 9,999) creates an entropy of only 9,000 possibilities, making it susceptible to brute-force guessing if rate limiting is bypassed.
* **Concrete Evidence in Code:**
  ```php
  // admin/backend/api.php (lines 80-82)
  if (empty($newPassword)) {
      $newPassword = 'Gncp#' . rand(1000, 9999) . '!';
  }
  ```
* **Recommended Direction:**
  Replace with CSPRNG `random_int(100000, 999999)` or `bin2hex(random_bytes(6))` to ensure high entropy and cryptographic unpredictability.

---

### Finding DATA-01: Denormalized JSON Storage vs. Relational Secondary Ledger Synchronization Risk

* **Severity:** **Medium**
* **Category:** Database / Data Integrity
* **File Location:** [`stations/backend/services/EnrollmentService.php`](file:///c:/xampp/htdocs/systemtest/stations/backend/services/EnrollmentService.php#L380-L487)
* **Underlying Cause:**
  The system historically stored applicant progress and transaction details inside JSON string columns (`pre_enrollments.payment_data`, `requirements_data`, `medical_data`). Recent migrations added first-class relational tables (`payments`, `official_receipts`, `student_clearances`). However, `EnrollmentService::updateStudent()` performs mutations on the JSON columns first, and wraps the secondary relational insertions in a separate `try/catch` block that suppresses exceptions and logs them via `error_log` without rolling back the transaction.
* **Impact:**
  If a secondary relational insert fails (e.g. unique constraint violation on `official_receipts.or_number`), the JSON column update is still committed to `pre_enrollments`, creating a permanent state desynchronization between the JSON column and the relational audit ledger.
* **Concrete Evidence in Code:**
  ```php
  // stations/backend/services/EnrollmentService.php (lines 380, 485-487)
  try {
      if (isset($updateData['payment'])) {
          // Insert into payments & official_receipts...
      }
      // Insert into student_clearances...
  } catch (Exception $syncEx) {
      error_log('[EnrollmentService::RelationalSync] ' . $syncEx->getMessage());
  }
  // Primary transaction still commits!
  $pdo->commit();
  ```
* **Recommended Direction:**
  Include relational mutations directly inside the primary ACID transaction. If an official receipt or clearance fails to record relationally, the entire transaction MUST roll back (`$pdo->rollBack()`) to maintain complete financial and operational integrity.

---

### Finding CODE-01: Redundant Writes to Deprecated Legacy Snapshot Table `enrollments`

* **Severity:** **Low**
* **Category:** Technical Debt / Code Cleanliness
* **File Location:** [`shared/backend/utils/student.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/utils/student.php#L284-L294)
* **Underlying Cause:**
  `promotePreEnrollmentToStudent()` maintains an insert into the legacy `enrollments` table (`id`, `student`, `course`, `status`, `updated`).
* **Impact:**
  The `enrollments` table is completely unreferenced by all workstations, student portals, and admin analytics (which query `students` and `pre_enrollments`). Performing redundant SQL checks and inserts on every student promotion creates dead data and wastes database I/O cycles.
* **Concrete Evidence in Code:**
  ```php
  // database/schema.sql (lines 150-152)
  -- TABLE 6: enrollments
  -- LEGACY SNAPSHOT TABLE — not actively written to by any station service.
  -- Kept for historical compatibility. Do NOT build new features on this table.
  ```
* **Recommended Direction:**
  Deprecate and remove writes to `enrollments` after confirming no third-party legacy report depends on this table.

---

### Finding PERF-02: Disk I/O Lock Contention in File-Based Rate Limiting Under High Concurrency

* **Severity:** **Low**
* **Category:** Scalability / I/O Bottleneck
* **File Location:** [`shared/backend/utils/rate_limit.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/utils/rate_limit.php#L44-L73)
* **Underlying Cause:**
  `checkRateLimit()` tracks request counters by opening, reading, JSON-decoding, modifying, and re-saving individual files in `shared/backend/logs/rate_limits/` using exclusive file locks (`LOCK_EX`).
* **Impact:**
  Under high concurrent burst loads (e.g. during enrollment rush periods with hundreds of students registering simultaneously), simultaneous file lock acquisition on disk causes thread queuing and increases API latency.
* **Recommended Direction:**
  Migrate the rate-limiting counter store to an in-memory database table (`ENGINE=MEMORY` in MariaDB) or an in-memory key-value store (Redis / APCu).
