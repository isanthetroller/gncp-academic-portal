# GNCP — New System Concept Plan
## Course & Subject Management with Payment Integration

---

## 1. What the Current System Actually Is

After reading every module, here is a completely honest map of what exists right now:

### The 10 Current Modules

| Module | Location | Role | Backend? |
|---|---|---|---|
| **Student Enrollment Portal** | `enrollment-system/` | 6-step wizard — students pre-register and get a reference number + PIN | ✅ PHP (`register.php`) |
| **Registrar Admin Console** | `registrar/` | Reviews applications, manages `courses` table (degree programs), `sections` table, `students` table, `enrollments` table | ✅ PHP (`registrar/backend/api.php`) |
| **Requirements Verification Station** | `stations/requirements-verification/` | Removed — Registrar handles document checklist directly | Archived |
| **TLC Helpdesk / Academic Advising** | `stations/tlc-helpdesk/` | Station 1 — academic advisor advises the student | ✅ Shared station API |
| **Medical Clinic Station** | `stations/medical-checkup/` | Station 2 — physician checks fitness clearance | ✅ Shared station API |
| **Scholarship Verification Station** | `stations/scholarship-verification/` | Station 3 — verifies scholarship eligibility | ✅ Shared station API |
| **Cashier / Payment Station** | `stations/payment-processing/` | Station 4 — cashier processes payment | ✅ Shared station API |
| **IT Center Station** | `stations/it-center/` | Station 5 — finalizes enrollment, creates student account, moves record to `students` table | ✅ Shared station API |
| **Student Portal** | `student-portal/` | Enrolled students check their profile | ✅ PHP (`student-portal/backend/api.php`) |
| **Admin Dashboard** | `admin/` | System admin manages user accounts for all stations | ✅ PHP (`admin/backend/api.php`) |
| **School Website** | `school-website/` | Public-facing homepage | Static |

### How the Current Flow Works (The Real Pipeline)

```
[Student] → Pre-Register (enrollment-system) → Gets REF + PIN
    ↓
[Registrar] Reviews documents → Sets status: Approved
    ↓
[Station 1: Requirements] Checks physical documents
    ↓
[Station 2: TLC Helpdesk] Academic advising
    ↓
[Station 3: Medical] Fitness clearance
    ↓
[Station 4: Scholarship] Validates scholarship type
    ↓
[Station 5: Cashier] Collects payment (total fee stored in payment_data JSON)
    ↓
[Station 6: IT Center] Creates student account → Moves to `students` table → DONE
```

The entire state (roadmap, payment, medical, scholarship data) is stored as **JSON blobs inside one table: `pre_enrollments`**. There is no real calculation — the cashier manually types a fee amount.

---

## 2. What is BROKEN or INCOMPLETE Right Now

### Critical Issues

| Problem | Where | Impact |
|---|---|---|
| **`courses` table = Degree Programs, not Subjects** | `registrar/backend/api.php`, `database/schema.sql` | There is NO subjects table. A "Course" (BSIT, BSBA) is misnamed and misused. |
| **No subject/curriculum management** | Everywhere | Registrar can't define what subjects a student should take this semester |
| **Payment is manual and fake** | `stations/payment-processing/` | The cashier manually types a fee. There is NO fee computation, no breakdown, no receipt |
| **Sections table is disconnected** | `registrar/` | The `sections` table has `code`, `course`, `instructor` but no link to subjects or academic period |
| **No academic period/semester control** | Everywhere | There is no table to define "1st Semester 2026-2027" with dates. The system has a static "semester" field |
| **Student portal is empty** | `student-portal/` | Students can log in but see no enrolled subjects, no grades, no balance |
| **Registrar's "Courses" view = Degree Programs** | `registrar/RegistrarController.js` | The nav item "Courses" manages BSIT, BSBA etc., not academic subjects |

---

## 3. What the NEW System Should Be

The new system adds a **Course & Subject Management layer with real computed Payment** on top of the existing enrollment pipeline.

### 3.1 The Big Idea: Separate "Programs" from "Subjects"

The most important conceptual change:

```
OLD (Broken):
  courses table = BSIT, BSBA, BSED, BEED  ← these are Degree PROGRAMS

NEW (Correct):
  programs table  = BSIT, BSBA, BSED, BEED  ← Degree programs
  subjects table  = IT101, GE-ENG1, MATH101  ← Individual academic subjects
  curriculum_subjects = links programs ↔ subjects per year/semester
  academic_periods = "1st Sem 2026-2027" with enrollment date windows
```

---

## 4. What Should Be REMOVED from Current System

### From the Registrar Console (`registrar/`)

| Current Feature | Action | Reason |
|---|---|---|
| **"Courses" view** (manages degree programs) | **Rename → "Programs"** | It is degree programs, not subjects. Rename the nav item, rename the table. |
| **"Sections" view** (dummy unlinked table) | **Remove + Rebuild** | Current `sections` is completely disconnected from subjects and periods. Replace with `subject_sections`. |
| **"Semester" view** (static, no real data) | **Replace** | Currently displays hardcoded static text. Replace with real `academic_periods` CRUD. |
| **"Enrollment" view** (shows old `enrollments` snapshot table) | **Replace** | The old `enrollments` table is a dead snapshot. Replace with live `student_enrollments` per subject. |
| **"Reports" view** (static mock data only) | **Enhance** | Currently fake. Hook to real counts from new tables. |

### From the Cashier Station (`stations/payment-processing/`)

| Current Feature | Action | Reason |
|---|---|---|
| **Manual fee entry (cashier types a number)** | **Remove** | Replaced by auto-computed `billing_ledgers`. |
| **No receipt or audit trail** | **Add receipt + payment_transactions table** | Every payment needs a traceable record. |

### From the Database (`database/schema.sql`)

| Table | Action | Reason |
|---|---|---|
| `courses` | **Rename → `programs`** | The name was always wrong. |
| `sections` | **Keep as legacy, deprecate gradually** | Old data compatibility. New sections go in `subject_sections`. |
| `enrollments` | **Keep as legacy audit log** | New detailed records go in `student_enrollments`. |

---

## 5. What Gets ADDED (Detailed System Contents & Logic)

### 5.1 New Registrar Console Views & Forms
To support the academic catalog and scheduling, the Registrar Console will add five reactive, validation-guarded pages.

#### 5.1.1 Subjects Management View
Provides a CRUD form to manage the master list of all academic subjects.
- **Fields & Validations:**
  - `Subject Code`: Alphanumeric (e.g., `IT101`, `GE-ENG1`). Must be unique. Required.
  - `Subject Title`: Descriptive name (e.g., `Introduction to Computing`). Required.
  - `Description`: Long text details of subject contents. Optional.
  - `Lecture Units`: Number (e.g., `3.0`, `2.0`). Required. Default `3.0`.
  - `Lab Units`: Number (e.g., `1.0`, `0.0`). Required. Default `0.0`.
  - `Lab Fee`: Decimal amount (e.g., `500.00`). Checked if `Lab Units > 0`. Default `0.00`.
  - `Department`: Selection dropdown (e.g., `College of Information Technology`, `General Education`). Required.
  - `Prerequisites`: Multi-select autocomplete dropdown linking to other subject codes. Stores array of subject IDs in JSON format.

#### 5.1.2 Curriculum Mapping View
Manages the structured layout of subjects that a student must complete per program.
- **Fields & Validations:**
  - `Program Code`: Selection dropdown (sourcing active codes from `programs` table). Required.
  - `Subject Selection`: Autocomplete search of master subjects. Required.
  - `Year Level`: Numeric dropdown (`1` for 1st Year, `2` for 2nd Year, etc.). Required.
  - `Semester`: Numeric dropdown (`1` for 1st Semester, `2` for 2nd Semester, `3` for Summer). Required.
  - `Is Elective`: Checkbox toggle. Default `false`.
  - *Guard Rail:* A subject cannot be added to the same program's curriculum list more than once (handled via composite primary key constraint `uniq_curriculum`).

#### 5.1.3 Academic Periods Management
Controls the active school year/semester window.
- **Fields & Validations:**
  - `Period Name`: String title (e.g., `1st Semester 2026-2027`).
  - `Academic Year`: Format `YYYY-YYYY` (e.g., `2026-2027`). Required.
  - `Semester`: Number (`1` = 1st, `2` = 2nd, `3` = Summer). Required.
  - `Enrollment Start Date`: Calendar date selector. Required.
  - `Enrollment End Date`: Calendar date selector. Required. Must be after Start Date.
  - `Status`: Radio toggle (`Active` or `Inactive`). 
  - *Business Logic:* Only **one** academic period can be marked `Active` at any given time. Saving a period as active automatically updates other active periods to inactive.

#### 5.1.4 Subject Sections (Scheduling) View
Offers class sections under specific subjects for the active academic period.
- **Fields & Validations:**
  - `Subject Code`: Autocomplete selector of master subjects. Required.
  - `Section Code`: Alpha string (e.g., `A`, `B`, `IT1A`). Max 10 chars. Required.
  - `Instructor Name`: String (e.g., `Prof. Ramos`). Required.
  - `Schedule/Day Pattern`: Checkbox options (M, T, W, Th, F, S) + Start/End Time pickers (e.g., `MTh 09:00 AM - 10:30 AM`).
  - `Room`: String text (e.g., `CL3`, `Room 402`). Required.
  - `Max Capacity`: Integer (e.g., `40`). Default `40`. Required.
  - *Composite Rule:* Prevents duplicate sections under the same subject and semester period.

#### 5.1.5 Fee Schedule Configuration
Defines fee rates applied to the active academic period.
- **Fields & Validations:**
  - `Fee Type`: Dropdown selection:
    - `TUITION_PER_UNIT`: Base rate charged for each academic unit (e.g., `800.00`).
    - `MISC_FEE`: Flat miscellaneous operations charge (e.g., `2500.00`).
    - `LIBRARY_FEE`: Flat resource access charge (e.g., `500.00`).
    - `ATHLETIC_FEE`: Flat sports facility charge (e.g., `300.00`).
    - `REGISTRATION_FEE`: Flat administrative processing charge (e.g., `200.00`).
  - `Label`: User-friendly display text (e.g., `Library Fee`). Required.
  - `Amount`: Decimal value. Required.
  - `Is Per Unit`: Checkbox flag indicating if rate is multiplied by units.

---

### 5.2 Upgraded Advising Station (TLC Helpdesk)
The Academic Advising station (`stations/tlc-helpdesk/`) will shift from a simple one-click verification step to an active registration console.
1. **Curriculum Fetch:** When a student's card is opened, the system queries the `curriculum_subjects` table matching the student's applied `program` (e.g., `BSIT`) and target year level (`1st Year`).
2. **Subject Checklist:** The advising officer sees a checklist of recommended subjects for the current semester.
3. **Toggle Electives/Prerequisites:** The officer can:
   - Check/uncheck subjects based on availability.
   - Run prerequisite checks (displays warning indicators if prerequisite subject IDs are not verified).
4. **Subject Enrollment Allocation:** The advisor assigns specific section offerings (`subject_sections` list) for each checked subject.
5. **Advising Confirmation:** Once satisfied, the advisor clicks "Lock Subjects." This writes the array of selected section IDs and units to the student's `enrollment_data` JSON column inside `pre_enrollments`, updating their roadmap advising status to `COMPLETED` and queueing them for subsequent medical and cashiers verification.

---

### 5.3 Upgraded Cashier Station & Auto-Billing Logic
The Cashier station (`stations/payment-processing/`) will feature an automated, transparent fee calculator.

#### 5.3.1 Auto-Billing Logic & Formula
The station fetches the selected subjects list and the current `fee_schedule` setup. The bill is computed instantly using the following rules:

$$\text{Tuition Fee} = \text{Total Enrolled Units} \times \text{Tuition Rate per Unit}$$

$$\text{Lab Fees} = \sum (\text{Subject Lab Fees for Enrolled Classes})$$

$$\text{Miscellaneous Fees} = \sum (\text{Flat Fees: Misc, Library, Athletic, Registration})$$

$$\text{Gross Total} = \text{Tuition Fee} + \text{Lab Fees} + \text{Miscellaneous Fees}$$

#### 5.3.2 Scholarship Discount Application
Discounts are retrieved from the verified scholarship type from Station 4:
- `NONE`: 0% discount on Tuition.
- `HONOR` (Academic Scholar): 100% discount on Tuition fee.
- `ATHLETIC`: 50% discount on Tuition fee.
- `FINANCIAL`: 100% discount on Miscellaneous fees.

$$\text{Net Assessment} = \text{Gross Total} - \text{Scholarship Discount}$$

#### 5.3.3 Cashier Payments Form
- **Form Fields:**
  - `Payment Term Option`: Dropdown choosing:
    - `Full Cash`: 100% of Net Assessment paid now.
    - `Semi-Annual`: 50% downpayment, 50% midterm.
    - `Quarterly`: 25% downpayment, 75% split over three periods.
  - `Amount Received`: Text input. Validates that the payment covers at least the minimum required downpayment based on terms.
  - `Payment Method`: Dropdown: `Cash`, `GCash`, `Bank Transfer`.
  - `Receipt Number`: Auto-generated serial number (allows manual cashier override if tracking custom paper receipt booklets).
  - `Cashier Notes`: Optional comment.
- **Transaction Complete:**
  - Creates a row in `billing_ledgers` recording computation snapshot.
  - Inserts a row in `payment_transactions` documenting payment.
  - Automatically prints an official GNCP PDF-ready receipt template showing the detailed fee breakdown, payment date, and outstanding balance.

---

### 5.4 New Student Portal Modules
Upon successful IT activation, students logging into `student-portal/` can access three new interactive dashboards:

#### 5.4.1 "My Subjects" (Enrolled Classes)
- Displays active semester enrollment data.
- Shows a clean grid/schedule containing:
  - Subject Code & Course Title.
  - Section Code (e.g., `BSIT-1A`).
  - Units count (Lecture + Lab).
  - Schedule (Days & Time, Room location).
  - Assigned Instructor.

#### 5.4.2 "My Account Ledger" (Billing)
- Displays current financial status:
  - Total Semester Assessment (Gross Tuition, Lab Fees, Miscellaneous).
  - Applied Scholarship name and discount amount.
  - Total Paid to Date.
  - Outstanding Balance.
  - Status indicator badge (`PAID`, `PARTIALLY_PAID`, or `UNPAID`).

#### 5.4.3 "Receipt History"
- Chronological list of payments made at the Cashier Station.
- Table containing: Receipt Number, Date, Amount Paid, Payment Method, and a button to re-download the receipt details.

---

## 6. What Gets TRANSFERRED (Kept and Moved)

These features are kept but need to be connected to the new tables:

| Feature | From | To | Change |
|---|---|---|---|
| Pre-enrollment wizard | `enrollment-system/` | Keep as-is | Only change: program code dropdown must come from `programs` table |
| Registrar application review | `registrar/` Pending Applications view | Keep as-is | No change needed |
| Requirements Verification station | `stations/requirements-verification/` | Removed | Archived — document review handled by registrar |
| TLC Helpdesk / Academic Advising | `stations/tlc-helpdesk/` | Keep as-is, but add subject selection step | Advisor should now confirm which curriculum subjects the student takes this semester |
| Medical station | `stations/medical-checkup/` | Keep as-is | No change |
| Scholarship station | `stations/scholarship-verification/` | Keep, and link discount to billing | Scholarship discount must now flow into `billing_ledgers.scholarship_discount` |
| IT Center station | `stations/it-center/` | Keep as-is | Final activation still creates the `students` record |
| Admin dashboard | `admin/` | Keep as-is | No change |
| School website | `school-website/` | Keep as-is | No change |

---

## 7. The New Architecture at a Glance

```
DATABASE (gncp_portal)
├── programs            ← Degree programs (BSIT, BSBA...)
├── subjects            ← Academic subjects (IT101, GE-ENG1...)
├── curriculum_subjects ← Which subjects belong to which program/year/sem
├── academic_periods    ← Semester periods with dates
├── subject_sections    ← Subject offerings with instructor/room/schedule
├── student_enrollments ← Per-student, per-subject enrollment records
├── fee_schedule        ← Tuition rate + fees per period
├── billing_ledgers     ← One computed bill per student per semester
├── payment_transactions← Payment audit trail
├── pre_enrollments     ← Application pipeline (unchanged)
├── station_users       ← Staff accounts (unchanged)
└── students            ← Permanent student directory (unchanged)

REGISTRAR CONSOLE
├── Dashboard
├── Pending Applications (keep)
├── Programs (renamed from Courses)
├── Subjects ⭐ NEW
├── Curriculum ⭐ NEW
├── Academic Periods ⭐ NEW (replaces Semester)
├── Subject Sections ⭐ NEW (replaces Sections)
├── Fee Schedule ⭐ NEW
├── Students (keep)
├── Enrollment Overview ⭐ UPGRADED
└── Reports ⭐ UPGRADED

CASHIER STATION
├── Auto-computed Billing ⭐ NEW
├── Payment Processing ⭐ UPGRADED
├── Payment Transactions / Receipt ⭐ NEW
└── Balance Display ⭐ NEW

STUDENT PORTAL
├── My Profile (keep)
├── My Enrolled Subjects ⭐ NEW
├── My Balance / Billing ⭐ NEW
└── My Payment History ⭐ NEW
```

---

## 8. What Files Will Be Created / Modified

### New Files
- `registrar/assets/js/views/SubjectsView.js` — Subjects CRUD UI
- `registrar/assets/js/views/CurriculumView.js` — Curriculum mapping UI
- `registrar/assets/js/views/AcademicPeriodsView.js` — Semester management UI
- `registrar/assets/js/views/SubjectSectionsView.js` — Section offerings UI
- `registrar/assets/js/views/FeeScheduleView.js` — Fee configuration UI
- `registrar/backend/subjects_api.php` — New subjects/curriculum API
- `registrar/backend/fees_api.php` — Fee schedule + billing API

### Modified Files
- `database/schema.sql` — Add 8 new tables
- `registrar/assets/js/models/RegistrarModel.js` — Add new nav items (Subjects, Curriculum, Periods, Sections, Fees)
- `registrar/backend/api.php` — Rename `courses` → `programs` in all SQL queries
- `stations/payment-processing/assets/js/app.js` — Replace manual fee entry with billing ledger fetch
- `student-portal/` — Add subjects/billing view

### Files Left Completely Alone
- All 5 station apps (requirements, medical, helpdesk, scholarship, IT center)
- `enrollment-system/` (the student pre-registration wizard)
- `admin/` (user management)
- `school-website/`
- `shared/` backend utilities

---

## 9. Two-Week Execution Order

| Day | Task |
|---|---|
| **Day 1–2** | Update `schema.sql` — add 8 new tables, rename `courses` → `programs` |
| **Day 3–4** | Build **Subjects CRUD** in Registrar (API + View) |
| **Day 5** | Build **Curriculum Mapping** view (program ↔ subjects per year/sem) |
| **Day 6** | Build **Academic Periods** view (replaces static Semester view) |
| **Day 7** | Build **Subject Sections** view (replaces old Sections) |
| **Day 8** | Build **Fee Schedule** view + auto-compute billing logic |
| **Day 9–10** | Upgrade **Cashier Station** — fetch computed bill, show breakdown, save payment transaction |
| **Day 11** | Connect **Scholarship discount** into billing computation |
| **Day 12** | Upgrade **Student Portal** — show enrolled subjects + balance |
| **Day 13** | Testing all flows end-to-end |
| **Day 14** | Bug fixes + polish |

---

> **Key Rule:** Anything that works today (stations, pre-enrollment wizard, document verification, admin) stays untouched. Only the Registrar Console and Cashier Station get major upgrades. The database grows new tables without deleting the old ones.
