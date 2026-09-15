# GNCP Enrollment System: System Flowcharts & Legend

This document contains the authoritative system flowcharts for the Go-on National College of the Philippines (GNCP) Enrollment System. It is divided into a High-Level Overview flowchart and four Detailed Flowcharts for each subsystem, mapped to user roles and actual code logic.

---

## 📊 1. High-Level Overview Flowchart
Shows the high-level transitions between the different stages of the enrollment lifecycle.

```mermaid
flowchart TD
    %% Styling
    classDef startEnd fill:#111827,color:#ffffff,stroke:#374151,stroke-width:2px,rx:10
    classDef process fill:#0F2C59,color:#ffffff,stroke:#1E3A8A,stroke-width:2px
    classDef database fill:#064E3B,color:#ffffff,stroke:#059669,stroke-width:2px

    Start([Start: Enrollment Period Opens]) --> PreReg[Phase 1: Online Pre-Enrollment Staging]:::process
    PreReg --> Clearances[Phase 2: Physical On-Campus Clearance Stations]:::process
    Clearances --> Promotion[Phase 3: Database Account Migration & SAS Access]:::process
    Promotion --> End([End: Enrollment Period Closes]):::startEnd
```

---

## 📈 2. Detailed Subsystem Flowcharts

### A. Online Pre-Registration & Tracking (Guest/Candidate Swimlane)
Traces the initial data submission from the browser to the staging database and status verification.

```mermaid
flowchart TD
    %% Styling
    classDef startEnd fill:#111827,color:#ffffff,stroke:#374151,stroke-width:2px,rx:10
    classDef process fill:#0F2C59,color:#ffffff,stroke:#1E3A8A,stroke-width:2px
    classDef decision fill:#78350F,color:#ffffff,stroke:#D97706,stroke-width:2px
    classDef data fill:#5B21B6,color:#ffffff,stroke:#7C3AED,stroke-width:2px

    %% Flow
    Start([Start: Applicant visits portal]) --> InputData[/Input Program, Personal, Academic, Medical, and Payment details/]:::data
    InputData --> SubCheck{Click Submit with valid inputs?}:::decision
    
    SubCheck -->|No| InputData
    SubCheck -->|Yes| ApiReg[Backend register.php: Generates tracking Ref No & PIN]:::process
    
    ApiReg --> RoadInit[Backend register.php: Seeds 7-step roadmap JSON]:::process
    RoadInit --> DBInsert[Backend register.php: Inserts record with PRE_REGISTERED status]:::process
    
    DBInsert --> Tracker[/Applicant inputs Ref No & PIN in track.php/]:::data
    Tracker --> TrackCheck{Are tracking credentials valid?}:::decision
    
    TrackCheck -->|No| ErrResp[Return 401 Unauthorized error]:::process
    TrackCheck -->|Yes| ShowRoadmap[Render live station clearances and roadmap steps]:::process
    
    ErrResp --> Tracker
    ShowRoadmap --> End([End: Applicant proceeds to campus]):::startEnd
```

---

### B. On-Campus Clearance Stations (Multi-Role Swimlanes)
Traces the physical clearances at each desk. Steps must be cleared before the IT Center executes final promotion.

```mermaid
flowchart TD
    %% Styling
    classDef person fill:#08427B,color:#ffffff,stroke:#052E56,stroke-width:2px
    classDef process fill:#0F2C59,color:#ffffff,stroke:#1E3A8A,stroke-width:2px
    classDef decision fill:#78350F,color:#ffffff,stroke:#D97706,stroke-width:2px
    classDef db fill:#006400,color:#ffffff,stroke:#003300,stroke-width:2px

    %% Swimlane 1: Student Candidate
    subgraph Candidate_Swimlane ["Student Candidate"]
        C_Start([Reports on Campus]) --> C_S1[Presents hardcopies to Registrar]
        C_S2[Reviews course load with Advisor]
        C_S3[Completes Clinic checkup]
        C_S4[Submits scholarship voucher]
        C_S5[Pays fee downpayment at Cashier]
        C_S6[Presents for portrait capture at IT Desk]
    end

    %% Swimlane 2: Registrar Desk
    subgraph Registrar_Swimlane ["Registrar Desk (Station 1)"]
        R_Check[Verify PSA, Report Card, and Good Moral]
        R_Check --> R_Decision{Are documents valid?}:::decision
        R_Decision -->|No| R_Reject[Update status to Rejected]:::process
        R_Decision -->|Yes| R_Assign[Assign temporary Section Code based on Year/Sem]:::process
        R_Assign --> R_Approve[Mark registrar_verification COMPLETED & Status = APPROVED]:::process
    end

    %% Swimlane 3: Advising Desk
    subgraph Advising_Swimlane ["Advising Desk (Station 2)"]
        A_Review[Verify course units & lock in final NSTP]
        A_Review --> A_Approve[Mark advising_assessment COMPLETED]:::process
    end

    %% Swimlane 4: Clinic Desk
    subgraph Clinic_Swimlane ["Clinic Desk (Station 3)"]
        M_Check[Perform physical exam & review questionnaire]
        M_Check --> M_Decision{Is applicant fit to enroll?}:::decision
        M_Decision -->|No| M_Flag[Update medical notes and flag record]:::process
        M_Decision -->|Yes| M_Approve[Mark clinic_checkup COMPLETED]:::process
    end

    %% Swimlane 5: Scholarship Desk
    subgraph Scholarship_Swimlane ["Scholarship Desk (Station 4)"]
        S_Check[Review academic/merit/sports voucher]
        S_Check --> S_Approve[Apply discount rate ledger & mark scholarship_verification COMPLETED]:::process
    end

    %% Swimlane 6: Cashier Desk
    subgraph Cashier_Swimlane ["Cashier Desk (Station 5)"]
        P_Bill[Query student ledger balance]
        P_Bill --> P_Check{Settle downpayment balance?}:::decision
        P_Check -->|No| P_Pending[Keep payment status as PENDING]:::process
        P_Check -->|Yes| P_Clear[Issue official receipt & mark cashier_payment COMPLETED]:::process
    end

    %% Swimlane 7: IT Center Desk
    subgraph IT_Swimlane ["IT Center Desk (Station 6)"]
        IT_Photo[Capture portrait photograph & upload_photo]
        IT_Data[Assign sequential student ID & institutional email]
        IT_Check{Are all 7 steps COMPLETED or SKIPPED?}:::decision
        IT_Promote[Run promotePreEnrollmentToStudent transaction]:::process
        IT_DB[DB: Write student to permanent tables & delete staging record]:::db
    end

    %% Connections
    C_S1 --> R_Check
    R_Approve --> C_S2
    A_Approve --> C_S3
    M_Approve --> C_S4
    S_Approve --> C_S5
    P_Clear --> C_S6
    C_S6 --> IT_Photo
    IT_Photo --> IT_Data
    IT_Data --> IT_Check
    IT_Check -->|No| End_Hold([End: Hold account activation])
    IT_Check -->|Yes| IT_Promote
    IT_Promote --> IT_DB
    IT_DB --> End_Active([End: Student fully enrolled & active])
    R_Reject --> End_Rejected([End: Application rejected])
```

---

### C. Super Admin Configurations (Admin Swimlane)
Traces the operations configured by the Super Admin to establish school parameters.

```mermaid
flowchart TD
    %% Styling
    classDef startEnd fill:#111827,color:#ffffff,stroke:#374151,stroke-width:2px,rx:10
    classDef process fill:#0F2C59,color:#ffffff,stroke:#1E3A8A,stroke-width:2px
    classDef decision fill:#78350F,color:#ffffff,stroke:#D97706,stroke-width:2px
    classDef data fill:#5B21B6,color:#ffffff,stroke:#7C3AED,stroke-width:2px

    %% Flow
    Start([Start: Admin logs in]) --> Menu[/Selects Admin action menu/]:::data
    Menu --> Choice{Select Action}:::decision
    
    Choice -->|Curriculum Wizard| CW_Init[Select/Create department & program]:::process
    Choice -->|Term Cloner| TC_Init[Select source term, new period label, and AY]:::process
    Choice -->|Bulk Sections| BS_Init[Specify target program, year level, and count]:::process
    Choice -->|Operators CRUD| OP_Init[Enter operator details & role assignments]:::process
    
    CW_Init --> CW_Save[Overwrite mappings & bulk-insert to curriculum]:::process
    TC_Init --> TC_Save[Clone academic periods, section cohorts, and class offerings]:::process
    BS_Init --> BS_Save[Generate sequential section code blocks A, B, C]:::process
    OP_Init --> OP_Save[Create user credential & toggle status ACTIVE/INACTIVE]:::process
    
    CW_Save --> End([End: Parameters saved]):::startEnd
    TC_Save --> End
    BS_Save --> End
    OP_Save --> End
```

---

### D. Enrolled Student SAS Portal (Student Swimlane)
Traces access parameters for students to view profiles and schedule listings.

```mermaid
flowchart TD
    %% Styling
    classDef startEnd fill:#111827,color:#ffffff,stroke:#374151,stroke-width:2px,rx:10
    classDef process fill:#0F2C59,color:#ffffff,stroke:#1E3A8A,stroke-width:2px
    classDef decision fill:#78350F,color:#ffffff,stroke:#D97706,stroke-width:2px
    classDef data fill:#5B21B6,color:#ffffff,stroke:#7C3AED,stroke-width:2px

    %% Flow
    Start([Start: Student logs in]) --> Input[/Presents student ID & password/]:::data
    Input --> AuthCheck{Are credentials valid?}:::decision
    
    AuthCheck -->|No| ErrMsg[Show invalid login warning]:::process
    AuthCheck -->|Yes| FetchDash[Retrieve profile and decode JSON data]:::process
    
    FetchDash --> FetchSched[Resolve active semester & load mapped subjects]:::process
    FetchSched --> RenderSAS[Display schedule grid and fee ledger balance]:::process
    
    ErrMsg --> Input
    RenderSAS --> End([End: Student logs out]):::startEnd
```

---

## 📖 3. Legend & Decision Explanations

### Swimlane Definitions
1. **Student Candidate**: Represents the prospective student applicant. They initiate the enrollment staging online and walk physically to each kiosk station on campus for validations.
2. **Registrar Desk (Station 1)**: Performed by a Registrar officer. Verifies original hardcopies and assigns a temporary Section Code (so they are temporarily mapped to a cohort but not counted in official numbers until promotion).
3. **Advising Desk (Station 2)**: Performed by an Academic Advisor. Locks in course loads and resolves NSTP choices.
4. **Clinic Desk (Station 3)**: Performed by a School Physician. Validates physical fit clearance.
5. **Scholarship Desk (Station 4)**: Performed by a Scholarship officer. Calculates ledger discount rates.
6. **Cashier Desk (Station 5)**: Performed by a Treasury clerk. Verifies fee balances and prints payment receipts.
7. **IT Center Desk (Station 6)**: Performed by an IT officer. Generates permanent accounts, captures photos, and executes database promotions.
8. **Super Admin**: Performed by the System Administrator. Governs term cloning, operator directories, and subject parameters.
9. **Student**: Represents a fully-promoted active student checking active schedules in the SAS portal.

### Core Decision Points (Diamonds)
* **SubCheck (Pre-Registration Submit)**: Verifies that form inputs comply with validation constraints before database staging.
* **TrackCheck (Status Tracking Login)**: Matches the `temp_student_id` and `temp_pin` in the database.
* **R_Decision (Registrar Validation)**: Verifies original hardcopies (Form 138, PSA). If correct, assigns a temporary Section Code based on year and semester; if incorrect, marks the application status as `Rejected`.
* **M_Decision (Medical Clearance)**: Verifies physical fitness. If unfit, flags the student record with follow-up instructions.
* **P_Check (Tuition Downpayment)**: Cashier checks if the student settled downpayments. If yes, marks cashier step `COMPLETED`.
* **IT_Check (IT Roadmap Check)**: Crucial validation loop. Checks if all roadmap checklist items are marked `COMPLETED` or `SKIPPED`. If any is pending, halts account promotion.
* **AuthCheck (Student Portal Login)**: Validates hashed password matching in the permanent `students` table.
* **Choice (Super Admin Menu)**: Routes admin parameters to catalog, term cloner, section generator, or user controls.
