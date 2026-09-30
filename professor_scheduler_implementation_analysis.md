# Architectural & Implementation Analysis: Academic Scheduling, Professor/Teacher Subsystem, Scheduler Role, and Teacher LMS

**Document Version:** 1.0.0  
**Target System:** GNCP Academic & Enrollment Management System  
**System Architecture:** Dual-Gateway PHP 8.x + Vue 3 CDN Progressive Enhancement + MariaDB 10.x  
**File Location:** [`professor_scheduler_implementation_analysis.md`](file:///c:/xampp/htdocs/systemtest/professor_scheduler_implementation_analysis.md)  
**Date:** September 2026

---

## Executive Summary

The GNCP Academic & Enrollment Management System currently possesses mature multi-station enrollment workflows (`REGISTRAR`, `HELPDESK`, `MEDICAL`, `CASHIER`, `IT_CENTER`), academic catalog management (`programs`, `subjects`, `curriculum`), block cohort sections (`sections`, `subject_sections`), and a dedicated Student Portal (`student-portal/`). However, instructor assignments have historically relied on unlinked string literals (`subject_sections.instructor`), lacking relational professor profiles, department governance, deterministic auto-scheduling, operating-window conflict validation (7:00 AM – 7:00 PM with 12:00 PM – 1:00 PM lunch blackout), professor personal drives, and section-targeted LMS content distribution.

This document presents a comprehensive, non-destructive architectural blueprint for extending the existing system to fulfill all requirements across Phases 1 through 37 without disrupting any active enrollment, workstation, or student flows.

---

## 1. Existing Relevant Architecture

### 1.1 Architectural Topology & Dual-Gateway Pattern
The GNCP system operates on a clean, decoupled dual-gateway architecture:
- **Central REST API Gateway:** [`api/index.php`](file:///c:/xampp/htdocs/systemtest/api/index.php) handles routing via an explicit `$routes` array, providing unified CORS headers, X-Request-ID correlation tracking, JSON payload decoding, IP rate limiting (`checkRateLimit`), and global exception telemetry.
- **Station Operations Gateway:** [`stations/backend/api.php`](file:///c:/xampp/htdocs/systemtest/stations/backend/api.php) and [`stations/backend/services/`](file:///c:/xampp/htdocs/systemtest/stations/backend/services/) manage enrollment queue compilation (`QueueService.php`), staging updates (`EnrollmentService.php`), and cashier payments (`PaymentService.php`).
- **Database Driver:** [`shared/backend/config/database.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/config/database.php) provides the single source of truth connection singleton (`Database::getInstance()`).

### 1.2 Authentication & Session Architecture
- **Primary Auth Controller:** [`api/controllers/AuthController.php`](file:///c:/xampp/htdocs/systemtest/api/controllers/AuthController.php) handles credentials verification via PHP `password_verify()`, generates single-active session tokens, manages CSRF tokens, and handles `must_change_password` interception.
- **Session Guard:** [`shared/backend/utils/session_guard.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/utils/session_guard.php) enforces RBAC through `validateSession()` and `requireAuth($allowedRoles)`.
  - Roles currently supported in code: `SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `HELPDESK`, `MEDICAL`, `CASHIER`, `IT_CENTER`, `DEVELOPER`, `STUDENT`.
  - Session partitions: `$_SESSION['gncp_admin_user']` (Admin/Super Admin), `$_SESSION['gncp_station_user']` (Station staff), `$_SESSION['gncp_student']` (Students).
- **Delegated Login Gateway:** [`shared/backend/login.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/login.php) forwards requests directly to `AuthController`.

### 1.3 Workstation Frontend Design System
All employee workstations adhere strictly to a shared design system:
- **Navigation Component:** [`shared/js/components/EmployeeSidebar.js`](file:///c:/xampp/htdocs/systemtest/shared/js/components/EmployeeSidebar.js) provides the unified collapsible sidebar with brand seal, dynamic `formattedRole()` labels, avatar error handling, and nested category navigation.
- **Theme Tokens:** [`shared/css/admin_workstation_theme.css`](file:///c:/xampp/htdocs/systemtest/shared/css/admin_workstation_theme.css) and [`shared/css/sidebar.css`](file:///c:/xampp/htdocs/systemtest/shared/css/sidebar.css) define CSS custom properties (`--primary-green: #006A4E`, `--gold: #D4AF37`, `--bg-card`, `--sidebar-bg`, typography clamps, modal overlays, and status badges).
- **Client Synchronizer:** `StationDataBus.js` ensures reactive, zero-refresh queue polling without page reloads.

---

## 2. Existing Database Structures That Can Be Reused

The existing database schema in [`database/schema.sql`](file:///c:/xampp/htdocs/systemtest/database/schema.sql) contains well-structured tables that will be reused directly:

| Existing Table | Purpose in Existing System | Planned Extension / Reuse |
| :--- | :--- | :--- |
| `station_users` | Staff credentials, roles, email, avatar, `must_change_password`, session tokens. | Accommodates new roles `PROFESSOR` and `SCHEDULER`. |
| `departments` | Institutional colleges (`CCS`, `CHS`, `COB`, `GED`, `NSTP`, `PE`). | Relational foreign key anchor for professor department assignments and subject compatibility. |
| `programs` | Academic degrees (`BSIT`, `BSCS`, `BSCpE`, `BSN`, `BSBA`) linked to departments. | Used for scheduler filtering, section scoping, and prospectus validation. |
| `subjects` | Master subject catalog with lecture/lab units, fees, prerequisites, department. | Preserved entirely. Subject department mapping used for compatibility checks. |
| `curriculum` | Semester prospectus mapping subjects to programs, year levels, semesters. | Preserved entirely. Used to verify advised subjects for cohorts. |
| `academic_periods` | Semester terms (`name`, `academic_year`, `semester`, `status`). | Scopes section schedules, professor availability, and conflict checking. |
| `sections` | Cohort block sections (`code`, `program`, `year_level`, `academic_period_id`, `capacity`, `adviser`). | Target of schedule allocations and section-level announcement/material distribution. |
| `subject_sections` | Subject offering slots with `days`, `time`, `room`, `capacity`, `section_id`, `instructor`. | Add nullable relational `professor_id` while preserving `instructor` text for backward compatibility. |
| `students` | Enrolled student accounts with `enrollment_data` (storing `assignedSection`). | Target recipients for section-targeted LMS posts, materials, and announcements. |
| `pre_enrollments` | Pending student applications with `section_code` assigned by Helpdesk. | Staging queue; reflects assigned section schedule on COR and student tracker. |
| `announcements` | Campus announcements with title, content, author, media attachment. | Extended with relational section targeting (`announcement_sections`). |
| `audit_logs` | Structured audit trail with `reference_number`, `operator_username`, `station_role`, `action_performed`. | Extended to log scheduling mutations, professor assignments, drive uploads, and material publications. |

---

## 3. Existing Employee-Account Patterns

### 3.1 Provisioning Workflow
- **Admin Controller:** [`api/controllers/UserAdminController.php`](file:///c:/xampp/htdocs/systemtest/api/controllers/UserAdminController.php) controls staff creation:
  1. Validates username pattern (`/^[a-z0-9_.-]{3,30}$/`), required fields (`name`, `username`, `role`), and email format.
  2. Auto-generates temporary passwords (`Gncp#XXXX!`) if none supplied, sets `must_change_password = 1`.
  3. Hashes passwords using `password_hash($pass, PASSWORD_DEFAULT)`.
  4. Dispatches credentials notification via [`shared/backend/services/EmailService.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/services/EmailService.php) with dual-port fallback (Port 587 TLS / Port 465 SSL).
  5. Intercepts first login using [`shared/js/PasswordChangeGuard.js`](file:///c:/xampp/htdocs/systemtest/shared/js/PasswordChangeGuard.js).
- **Operator Listing:** Fetches users from `station_users` where status is `ACTIVE`, `PENDING`, or `DEACTIVATED`.

### 3.2 Integration Strategy for New Roles
- Add `PROFESSOR` and `SCHEDULER` to `$allowedRoles` in [`api/controllers/UserAdminController.php`](file:///c:/xampp/htdocs/systemtest/api/controllers/UserAdminController.php).
- For `PROFESSOR`, link the new `station_users.id` with a child record in `professors` storing `faculty_id`, `department_id`, and teaching status.
- Admin portal will provide a dedicated "Professor / Teacher Accounts" management tab/section with department assignment and teaching load preview.

---

## 4. Existing Section and Subject Structures

### 4.1 Relationship Model
```
academic_periods (Active term)
      │
      ▼
   sections (Cohort Block: e.g., Program: "BS Information Technology", Year: "1st Year", Code: "1A")
      │
      ▼
subject_sections (Offering Slot: e.g., Code: "IT-IT101-A", Subject: "Introduction to Computing",
                  Days: "MW", Time: "09:00 AM - 10:30 AM", Room: "Lab 1",
                  Capacity: 40, Section_ID: 1, Instructor: "Prof. Steve Jobs")
```

### 4.2 Legacy Instructor Field
Currently, `subject_sections.instructor` stores plain text strings (e.g., `'Prof. Steve Jobs'`, `'Prof. Dennis Ritchie'`, `'Dr. Pythagoras'`).
- **Limitation:** Cannot track faculty workload, department affiliation, overlapping assignments, or login accounts.
- **Migration Path:** Introduce `professors` table and add `professor_id INT NULL` to `subject_sections`. When a professor is assigned, both `professor_id` and `instructor` (professor's full display name) will be updated atomically. This guarantees 100% backward compatibility for any existing views or printed CORs reading `instructor`.

---

## 5. Existing Student Schedule Implementation

### 5.1 Student COR Generation
- **Script:** [`stations/payment-processing/cor_print.php`](file:///c:/xampp/htdocs/systemtest/stations/payment-processing/cor_print.php)
  - Identifies student's assigned section code from `students.enrollment_data` or `pre_enrollments.section_code`.
  - Queries `subject_sections` matching the program, year level, and section code.
  - Formats schedule times (`09:00 AM - 10:30 AM`), rooms, and instructor names.
- **Student Portal Dashboard:** [`shared/backend/services/StudentPortalService.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/services/StudentPortalService.php)
  - Compiles student schedule into `$schedule[]` containing `code`, `description`, `units`, `type` (`Lec` or `Lec/Lab`), `days`, `start`, `end`, `section`, `room`, `instructor`.
  - Displayed on the student dashboard under the "Academic Schedule & Subjects" card.

---

## 6. Existing Announcement Implementation

### 6.1 Admin Bulletin Service
- **Service:** [`shared/backend/services/AnnouncementService.php`](file:///c:/xampp/htdocs/systemtest/shared/backend/services/AnnouncementService.php)
- **Table:** `announcements` (`id`, `title`, `category`, `content`, `image_url`, `author_name`, `target_audience`, `is_pinned`, `status`, `created_at`).
- **Limitation:** `target_audience` is a single string (`ALL`, `STUDENTS`, `FACULTY`). There is no mechanism for a professor to target a specific cohort section (e.g., `BSIT-1A`).
- **Extension Strategy:** Create an associative table `announcement_sections` (`announcement_id`, `section_id`, `section_code`) and add optional `author_type` (`ADMIN` or `PROFESSOR`) and `author_id` (`station_users.id`). Students will query institutional announcements (`target_audience = 'ALL'`) UNION section announcements targeting their assigned cohort.

---

## 7. What Must Be Extended

1. **User System:**
   - Add roles `PROFESSOR` and `SCHEDULER` to `station_users`.
   - Update `AuthController.php` redirects to route `PROFESSOR` to `stations/professor/` and `SCHEDULER` to `stations/scheduler/`.
   - Update `EmployeeSidebar.js` role formatter to render `"Faculty Professor"` and `"Academic Scheduler"`.
2. **Database:**
   - Create `professors` table linked to `station_users`.
   - Create `professor_availability` table for custom schedule exceptions.
   - Add nullable `professor_id` to `subject_sections`.
   - Create `professor_files` table for personal drive storage.
   - Create `lms_materials` and `lms_material_sections` for LMS material publishing.
   - Create `announcement_sections` for relational announcement targeting.
3. **Backend Services & Conflict Engine:**
   - Create centralized `ScheduleConflictService.php` enforcing:
     - 7:00 AM – 7:00 PM operating window.
     - 12:00 PM – 1:00 PM lunch blackout rejection.
     - Section shift indicator enforcement (`M` = 7:00 AM–12:00 PM, `A` = 1:00 PM–7:00 PM, `E` = 5:00 PM–7:00 PM evening shift).
     - Professor time overlap checks on identical days.
     - Section cohort time overlap checks.
     - Room occupancy overlap checks.
     - Professor availability exceptions.
     - Professor department compatibility.
   - Create `SchedulerService.php` for manual and deterministic auto-assignment.
   - Create `ProfessorService.php` for professor dashboard, sections, subjects, drive management, and LMS publication.
4. **Frontend Workstation Interfaces:**
   - Scheduler Workstation: [`stations/scheduler/index.html`](file:///c:/xampp/htdocs/systemtest/stations/scheduler/index.html).
   - Professor Workstation: [`stations/professor/index.html`](file:///c:/xampp/htdocs/systemtest/stations/professor/index.html).
   - Admin Professor Management: Update [`admin/index.html`](file:///c:/xampp/htdocs/systemtest/admin/index.html) with dedicated Professor Accounts interface.
   - Student Portal LMS: Update [`student-portal/index.html`](file:///c:/xampp/htdocs/systemtest/student-portal/index.html) to render section learning materials and teacher announcements.

---

## 8. What New Database Structures Are Required

### 8.1 Schema Design & Integrity Constraints

```sql
-- 1. professors table
CREATE TABLE IF NOT EXISTS `professors` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNIQUE NOT NULL COMMENT 'FK to station_users.id',
    `employee_id`    VARCHAR(50) UNIQUE NOT NULL COMMENT 'e.g. FAC-2026-001',
    `name`           VARCHAR(150) NOT NULL,
    `department_id`  INT NOT NULL COMMENT 'FK to departments.id',
    `email`          VARCHAR(150) DEFAULT NULL,
    `status`         VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_prof_dept` (`department_id`, `status`),
    INDEX `idx_prof_user` (`user_id`),
    CONSTRAINT `fk_prof_user` FOREIGN KEY (`user_id`) REFERENCES `station_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_prof_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 2. professor_availability table (for explicit unavailability blocks)
CREATE TABLE IF NOT EXISTS `professor_availability` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `professor_id`   INT NOT NULL,
    `day_of_week`    VARCHAR(20) NOT NULL COMMENT 'Monday, Tuesday, Wednesday, Thursday, Friday, Saturday',
    `start_time`     TIME NOT NULL,
    `end_time`       TIME NOT NULL,
    `is_available`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = explicitly available, 0 = unavailable exception',
    `notes`          VARCHAR(255) DEFAULT NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_avail_prof_day` (`professor_id`, `day_of_week`),
    CONSTRAINT `fk_avail_prof` FOREIGN KEY (`professor_id`) REFERENCES `professors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Extend subject_sections with professor_id
ALTER TABLE `subject_sections` 
ADD COLUMN IF NOT EXISTS `professor_id` INT NULL DEFAULT NULL AFTER `instructor`,
ADD INDEX IF NOT EXISTS `idx_ss_professor` (`professor_id`);

-- 4. professor_files (Personal Drive)
CREATE TABLE IF NOT EXISTS `professor_files` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `professor_id`    INT NOT NULL,
    `filename`        VARCHAR(255) NOT NULL COMMENT 'Original client filename',
    `stored_filename` VARCHAR(255) NOT NULL COMMENT 'Sanitized cryptographic disk filename',
    `file_type`       VARCHAR(20) NOT NULL COMMENT 'pdf, pptx, docx, etc.',
    `mime_type`       VARCHAR(100) NOT NULL,
    `file_size`       BIGINT NOT NULL COMMENT 'Bytes',
    `storage_path`    VARCHAR(255) NOT NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_files_prof` (`professor_id`, `created_at`),
    CONSTRAINT `fk_file_prof` FOREIGN KEY (`professor_id`) REFERENCES `professors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. lms_materials & lms_material_sections (Publishing Drive Files to Sections)
CREATE TABLE IF NOT EXISTS `lms_materials` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `professor_id`    INT NOT NULL,
    `file_id`         INT NOT NULL,
    `title`           VARCHAR(255) NOT NULL,
    `description`     TEXT DEFAULT NULL,
    `subject_code`    VARCHAR(50) DEFAULT NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mat_prof` (`professor_id`),
    INDEX `idx_mat_file` (`file_id`),
    CONSTRAINT `fk_mat_prof` FOREIGN KEY (`professor_id`) REFERENCES `professors` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mat_file` FOREIGN KEY (`file_id`) REFERENCES `professor_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `lms_material_sections` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `material_id`     INT NOT NULL,
    `section_id`      INT NULL,
    `section_code`    VARCHAR(50) NOT NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lms_sec_mat` (`material_id`),
    INDEX `idx_lms_sec_code` (`section_code`),
    CONSTRAINT `fk_lms_sec_mat` FOREIGN KEY (`material_id`) REFERENCES `lms_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. announcement_sections (Targeted Section Announcements)
CREATE TABLE IF NOT EXISTS `announcement_sections` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `announcement_id`  INT NOT NULL,
    `section_id`       INT NULL,
    `section_code`     VARCHAR(50) NOT NULL,
    INDEX `idx_ann_sec_id` (`announcement_id`),
    INDEX `idx_ann_sec_code` (`section_code`),
    CONSTRAINT `fk_ann_sec_ann` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## 9. What New API Endpoints Are Required

All new endpoints follow the canonical REST gateway pattern in [`api/index.php`](file:///c:/xampp/htdocs/systemtest/api/index.php):

### 9.1 Admin / User Management Endpoints
- `admin/professors` (GET): List all professors with department, status, and teaching assignment metrics.
- `admin/save_professor` (POST): Create or update a professor account (`station_users` + `professors`).
- `admin/update_professor_status` (POST): Activate / Deactivate a professor.
- `admin/departments` (GET): Fetch list of departments for dropdown assignment.

### 9.2 Scheduler & Conflict Engine Endpoints
- `scheduler/catalog_context` (GET): Returns active period, programs, year levels, sections, and department-grouped professors.
- `scheduler/section_schedule` (GET): Returns all `subject_sections` for a specified section ID/code with assigned professors and conflict status.
- `scheduler/professors_for_subject` (GET): Returns eligible professors for a given subject/section, with conflict calculation flags (`is_available`, `has_conflict`, `conflict_reason`, `dept_match`).
- `scheduler/assign_professor` (POST): Authoritative manual professor assignment. Backend validates via `ScheduleConflictService` before writing.
- `scheduler/auto_assign_section` (POST): Deterministic auto-assignment of all unassigned subjects in a section.
- `scheduler/validate_slot` (POST): Real-time backend validation of a proposed time/day/room/professor combination.
- `scheduler/save_subject_section` (POST): Create or edit a subject section schedule slot.

### 9.3 Professor Portal Endpoints
- `professor/profile` (GET): Fetch authenticated professor's profile, department info, and workload summary.
- `professor/sections` (GET): Fetch sections taught by the authenticated professor, including student enrollment counts.
- `professor/subjects` (GET): Fetch subjects handled by the professor with schedule times, rooms, and units.
- `professor/schedule` (GET): Weekly master grid of professor's classes.
- `professor/announcements` (GET / POST / DELETE): Manage professor's announcements and target section bindings.
- `professor/files` (GET / POST / DELETE): Personal Drive CRUD. Handles multipart upload, MIME validation, and deletion.
- `professor/download_file` (GET): Secure file download with ownership verification.
- `professor/publish_material` (POST): Publish a drive file as LMS material to selected sections.
- `professor/materials` (GET / DELETE): List published materials and unpublish.

### 9.4 Student Portal LMS Endpoints
- `student_portal/learning_materials` (GET): Fetch materials published to the student's enrolled section.
- `student_portal/download_material` (GET): Secure material download stream with student enrollment verification.
- `student_portal/announcements` (GET): Fetch combined institutional and section-specific announcements.

---

## 10. What New Frontend Pages Are Required

### 10.1 Scheduler Workstation (`stations/scheduler/index.html`)
- **Structure:** Identical layout to TLC Helpdesk and IT Center workstations.
- **Components:**
  - `<employee-sidebar station-name="Go-on National College" station-tag="ACADEMIC SCHEDULER" ...>`
  - Main Panel with Top Bar and dynamic time-of-day greeting (`"Great Morning"`, etc.).
  - **Views:**
    1. `dashboard`: Overview of scheduled sections, professor utilization, and pending schedule conflicts.
    2. `scheduling`: Cohort schedule workbench (Select Period $\rightarrow$ Program $\rightarrow$ Year Level $\rightarrow$ Section $\rightarrow$ Subject List). Features interactive professor assignment dropdown with instant conflict badges (`Available`, `Conflict: Time Overlap`, `Conflict: Outside Window`).
    3. `auto_assign`: One-click deterministic auto-assignment wizard with preview of assignment decisions and audit log trace.
    4. `professors`: Department-filtered faculty directory showing teaching loads and active assignments.
    5. `conflict_checker`: Interactive tool to simulate and verify day/time/room/professor combinations.
    6. `profile`: Employee profile view inheriting [`shared/profile.html`](file:///c:/xampp/htdocs/systemtest/shared/profile.html).

### 10.2 Professor Workstation (`stations/professor/index.html`)
- **Structure:** Full employee workstation design adhering to `admin_workstation_theme.css`.
- **Components:**
  - `<employee-sidebar station-name="Go-on National College" station-tag="FACULTY PORTAL" ...>`
  - **Views:**
    1. `dashboard`: Today's class agenda, upcoming schedule, active section count, total teaching units, and recent LMS posts.
    2. `my_sections`: Interactive cards for each assigned section showing cohort name, program, subject, schedule, room, and student roster modal.
    3. `my_subjects`: Detailed catalog list of handled subjects, syllabus units, and schedule slots.
    4. `schedule_grid`: Visual weekly timetable (Monday to Saturday, 7:00 AM – 7:00 PM) showing class blocks and 12:00–1:00 PM lunch boundary.
    5. `announcements`: Section-targeted announcement composer with recipient checkboxes (select one, multiple, or all handled sections).
    6. `personal_drive`: Personal file storage interface (Upload PDF/PPTX/DOCX, search, preview metadata, publish to section button, delete).
    7. `published_materials`: List of active LMS material postings with recipient sections and student download tracking.
    8. `profile`: Professor account profile and password update.

### 10.3 Admin Portal Updates (`admin/index.html`)
- Add `"Professor / Teacher Accounts"` navigation item under "Administration & Users".
- Render dedicated faculty management table with filters by Department (`CCS`, `CHS`, `COB`, `GED`, `NSTP`, `PE`) and Status (`ACTIVE`, `DEACTIVATED`).
- Include "Add Professor" modal with Full Name, Email, Username, Faculty ID, and Department selection.

### 10.4 Student Portal Updates (`student-portal/index.html`)
- Add **"Learning Materials"** navigation tab and view.
- Render clean course cards containing documents published by the section's professors with file icons, upload timestamps, professor names, and download buttons.
- Update Bulletin board to display section announcements alongside institutional notices.

---

## 11. Schedule Periods, Operating Windows & Conflict Rules

### 11.1 Operating Window & Shift Rules
- **Institutional Window:** Strictly **7:00 AM – 7:00 PM** (07:00:00 to 19:00:00).
  - Any class starting before 07:00 or ending after 19:00 MUST be rejected.
- **Lunch Blackout Period:** Strictly **12:00 PM – 1:00 PM** (12:00:00 to 13:00:00).
  - Normal classes cannot overlap or span across 12:00 PM – 1:00 PM. (e.g. 11:00 AM – 1:00 PM is REJECTED; 10:30 AM – 12:00 PM is ALLOWED; 1:00 PM – 2:30 PM is ALLOWED).
- **Section Cohort Shift Indicators (`M`, `A`, `E`):**
  - Section codes ending in or containing `M` indicate **Morning Shift**: allowable window is **7:00 AM – 12:00 PM**.
  - Section codes ending in or containing `A` indicate **Afternoon Shift**: allowable window is **1:00 PM – 7:00 PM**.
  - Section codes ending in or containing `E` indicate **Evening Cohort**: allowable window is **5:00 PM – 7:00 PM** (the designated evening shift within the 7:00 PM institutional boundary).
  - Any schedule assigned to an `M` section outside Morning or an `A` section outside Afternoon is flagged/rejected unless overridden by policy.

### 11.2 Centralized Conflict Detection Engine (`ScheduleConflictService.php`)
```
Rule 1: Time Window Bound
        07:00 <= StartTime < EndTime <= 19:00
Rule 2: Lunch Period Blackout
        NOT (StartTime < 13:00 AND EndTime > 12:00)
Rule 3: Professor Overlap Prevention
        For same professor on overlapping days:
        NOT (ProposedStart < ExistingEnd AND ExistingStart < ProposedEnd)
Rule 4: Section Cohort Overlap Prevention
        For same section cohort on overlapping days:
        NOT (ProposedStart < ExistingEnd AND ExistingStart < ProposedEnd)
Rule 5: Room Overlap Prevention
        For same room (unless 'TBA') on overlapping days:
        NOT (ProposedStart < ExistingEnd AND ExistingStart < ProposedEnd)
Rule 6: Professor Availability Exception
        Check professor_availability: If explicit is_available = 0 during slot, REJECT.
Rule 7: Active Professor Check
        professors.status = 'ACTIVE' and station_users.status = 'ACTIVE'.
```

### 11.3 Deterministic Auto-Assignment Algorithm
When the Scheduler triggers automatic professor assignment for a section or subject:
1. **Fetch Candidates:** Query all `ACTIVE` professors.
2. **Filter by Department Compatibility:**
   - Group candidates by department match (Subject Department = Professor Department $\rightarrow$ Rank 1; cross-department allowed with Rank 2).
3. **Filter by Availability:**
   - Exclude professors with explicit unavailability entries in `professor_availability` for the requested day and time.
4. **Filter by Schedule Conflicts:**
   - Exclude professors who already teach an overlapping class in the active academic period on the same days.
5. **Deterministic Selection:**
   - From the remaining eligible candidates, select using transparent criteria:
     1. Primary: Department Match (Rank 1 preferred over Rank 2).
     2. Secondary: Current Teaching Load (Ascending: candidate with fewest currently assigned units/sections in the active semester is selected first to achieve load balance).
     3. Deterministic Tie-Breaker: Lowest `professors.id` ASC.
   - **No random assignment** is permitted.

---

## 12. What Existing Workflows May Be Affected

1. **Registrar & TLC Helpdesk Sectioning:**
   - When Helpdesk advises a student and assigns a section (`section_code`), the section schedule now features official, relationally assigned professors rather than placeholder text.
   - COR generation in Cashier (`cor_print.php`) and Student Portal automatically reflects the updated professor name and schedule without requiring code changes to COR layout.
2. **Admin User Management:**
   - The user creation endpoint in `UserAdminController.php` will safely accept `PROFESSOR` and `SCHEDULER` roles while preserving existing station role validation.
3. **Database Integrity:**
   - Adding `professor_id` to `subject_sections` is non-destructive (nullable column). Existing queries referencing `instructor` continue to operate seamlessly.
4. **Student Portal:**
   - Adding learning materials and section announcements enriches the portal without breaking the existing academic prospectus, assessment calculation, or payment tracking views.

---

## 13. Implementation Phases & Execution Order

Following completion and review of this analysis document, implementation will proceed systematically:
- **Phase A — Schema Migration:** Execute `create_professor_and_scheduler_tables.php` migration, creating the 6 new tables/extensions and linking the 12 existing distinct instructors to new professor records.
- **Phase B — Core Domain Services:** Implement `ScheduleConflictService.php`, `SchedulerService.php`, and `ProfessorService.php`.
- **Phase C — Central Gateway & Controllers:** Implement `SchedulerController.php` and `ProfessorController.php`, update `api/index.php`, `AuthController.php`, and `UserAdminController.php`.
- **Phase D — Scheduler Workstation UI:** Build `stations/scheduler/index.html` with full employee layout, conflict visualizer, and auto-scheduler.
- **Phase E — Professor Workstation UI:** Build `stations/professor/index.html` with schedule timetable, personal file drive, and section LMS publisher.
- **Phase F — Admin & Student Portal Extensions:** Update `admin/index.html` for faculty management and `student-portal/index.html` for learning materials.
- **Phase G — Verification & Conflict Testing:** Execute comprehensive test suite covering all 7 conflict scenarios, authentication, drive security, and regression tests.
- **Phase H — Final Audit Report:** Generate `professor_scheduler_implementation_report.md`.
