---
title: Curriculum System Mechanics & Prospectus Hierarchy
date: 2026-08-22
tags:
  - curriculum/academic
  - curriculum/subjects
  - database/curriculum
  - admin/prospectus
---

# 📚 Curriculum System & Prospectus Hierarchy

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## 1. Academic Hierarchy & Architecture
Curriculums define the official 4-year subject roadmap (Prospectus) for every degree program offered by an academic department. The system models the academic catalog using a strict multi-tier hierarchy:

$$\text{Department} \longrightarrow \text{Degree Course / Program} \longrightarrow \text{Curriculum Version (e.g. 2022, 2024, 2026)} \longrightarrow \text{Year Levels (1st–4th Year)} \longrightarrow \text{Semesters (1st & 2nd Sem)} \longrightarrow \text{Subjects}$$

### Multi-Year Revision Cycle
Curriculums are revised periodically (e.g., every 2 to 4 years to align with CHED CMO requirements). 
- Active students retain their matriculation curriculum version (e.g., *2022 Curriculum*).
- Incoming freshman cohorts are assigned the newest active version (e.g., *2026 Revised Curriculum*).
- Existing subjects can be reused across different curriculum versions with updated unit weights, prerequisites, or lab allocations.

---

## 2. Database Schema & Entities

### `curriculum` Table
Represents an individual subject mapping within a specific curriculum version:
| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | `INT AUTO_INCREMENT PRIMARY KEY` | Unique mapping identifier |
| `program` | `VARCHAR(150)` | Full Degree Program Name (e.g. `BS Information Technology`) |
| `subject` | `VARCHAR(150)` | Subject Title or Code (e.g. `Computer Programming 1`) |
| `year_level` | `VARCHAR(50)` | Academic Year (`1st Year`, `2nd Year`, `3rd Year`, `4th Year`) |
| `semester` | `VARCHAR(50)` | Academic Term (`1st Semester`, `2nd Semester`, `Summer`) |
| `elective` | `TINYINT(1)` | Core (`0`) vs Elective (`1`) flag |
| `curriculum_version` | `VARCHAR(100)` | Revision label (e.g. `2022 Curriculum`, `2026 Revised Curriculum`) |

### Enhanced SQL Joining Invariant
To prevent null units or fees when a curriculum entry stores either the subject title or the subject code, all curriculum queries join to `subjects` using dual matching:
```sql
SELECT c.*, s.code AS subject_code, s.lecture_units, s.lab_units, s.lab_fee, s.prerequisites
FROM `curriculum` c
LEFT JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code)
WHERE c.program = :program AND c.curriculum_version = :version
ORDER BY c.year_level ASC, c.semester ASC, s.code ASC;
```

---

## 3. Super Admin Management Workflows

### 3-Tier Hierarchical Selector
The Super Admin Portal (`admin/index.html` $\rightarrow$ `Subjects & Courses` $\rightarrow$ `Subjects per Semester`) provides an intuitive 3-tier toolbar:
1. **Academic Department Filter**: Scopes available programs (e.g., *College of Computer Studies*, *College of Business*).
2. **Degree Course / Program Selector**: Selects the target degree (e.g., *BS Information Technology*).
3. **Curriculum Version Selector**: Switches between active and legacy curriculum revisions.

### Prospectus Matrix View
- Displays cards for all 4 academic years (**1st Year**, **2nd Year**, **3rd Year**, **4th Year**).
- Splits each year into **1st Semester** and **2nd Semester** side-by-side columns.
- Summarizes total lecture units, lab units, credit units, and lab fees per semester and across the entire 4-year degree.
- Includes inline **+ Add Subject** buttons per semester and interactive **Core / Elective** toggle pills.

### Curriculum Version Cloning Engine
Administrators can instantly clone an entire 4-year curriculum version to create a new revision:
1. Select source degree course and base revision (e.g., *2022 Curriculum*).
2. Input new revision label (e.g., *2026 Revised Curriculum*).
3. The backend executes atomic batch replication, copying all 4-year subject mappings with elective flags and instantly switching the UI to the newly provisioned version.

---

## 4. Downstream Station Consumer Resolution
- **TLC Helpdesk Station (`stations/tlc-helpdesk/`)**: Resolves required subjects and prerequisites via `getCurriculumSubjects()` during unit evaluation and section lock-in.
- **Payment Processing / Cashier Station (`stations/payment-processing/`)**: Uses curriculum subject lecture/lab units and laboratory fee rates to calculate total matriculation assessments and generate Official Receipts (OR).
- **Student Portal (`student-portal/`)**: Renders the student's personal prospectus checklist, showing completed, currently enrolled, and remaining subjects across all 4 years.

---

## 5. Related Notes
* [[Database_Schema|Database Schema & Entity Map]]
* [[Station_System|Station Processing & Advising Rules]]
* [[Roles_and_Permissions|Roles & Permissions]]
* [[Dangerous_Areas|Dangerous Areas (SQL Joining & Transactional Safety)]]
