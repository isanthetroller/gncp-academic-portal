---
title: Station Subsystem Matrix
date: 2026-08-21
tags:
  - stations/workstations
  - workflow/stations
  - architecture/databus
---

# 🏢 Station Subsystem Matrix

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## Active Workstations
1. **Registrar Desk** (`/registrar/`): Managed by `REGISTRAR` role. Evaluates admission credentials (Form 138, PSA, Good Moral, ID Pictures, Medical Clearance), manages conditional undertakings, and approves applications (`PRE_REGISTERED` $\rightarrow$ `VERIFIED`). Mapped in [[Student_Workflow|Student Workflow]].
2. **TLC Helpdesk** (`/stations/tlc-helpdesk/`): Managed by `HELPDESK` role. Locks NSTP and assigns block sections (`VERIFIED` $\rightarrow$ `ADVISED`). Mapped in [[Curriculum_System|Curriculum System]].
3. **Medical Clinic** (`/stations/medical-checkup/`): Managed by `MEDICAL` role. Records health vitals, physical fitness, and health clearance (`ADVISED` $\rightarrow$ `MEDICAL_CLEARED`).
4. **Cashier / Treasury** (`/stations/payment-processing/`): Managed by `CASHIER` role. Issues Official Receipts (OR), handles 3 payment modes (`Cash` with change calculator, `GCash / Digital OTC` with Reference ID recording, and `PayMongo Hosted Gateway` with QR/Link generation), and validates billing via [[Business_Rules|RULE-002]].
5. **IT Center** (`/stations/it-center/`): Managed by `IT_CENTER` role. Promotes records to permanent [[Database_Schema|students table]], generates permanent Student IDs (`GNCP-YYYY-XXXX`), and emails credentials (`PAID` $\rightarrow$ `ENROLLED / ACTIVE`).

## Live Sync Architecture
* Handled by `DataBus.js` with a 3-second background polling cycle querying `/api/index.php?action=stations/queue` using ETag 304 caching. Detailed in [[Architecture|Architecture Overview]].

## Related Notes
* [[Student_Workflow|Student Lifecycle]]
* [[Roles_and_Permissions|Station User Roles]]
* [[Business_Rules|Business Rules]]
