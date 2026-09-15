---
title: Roles and Permissions Architecture
date: 2026-08-21
tags:
  - security/rbac
  - security/permissions
  - roles/authorization
---

# 🔐 Roles & Permissions Architecture

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## Role Hierarchy

```mermaid
graph LR
    SUPER_ADMIN["SUPER_ADMIN / ADMIN"] --> REGISTRAR & HELPDESK & MEDICAL & CASHIER & IT_CENTER
    IT_CENTER --> STUDENT["STUDENT (Portal)"]
```

## Matrix Summary
* **`SUPER_ADMIN`**: Full platform control. Managed via Super Admin Portal.
* **`REGISTRAR`**: Document review and applicant admission. See [[Station_System|Registrar Desk]].
* **`HELPDESK`**: Advising and sectioning. See [[Curriculum_System|Curriculum System]].
* **`MEDICAL`**: Medical exams and clinical clearance.
* **`CASHIER`**: Tuition payment and OR issuance. See [[Business_Rules|RULE-002]].
* **`IT_CENTER`**: Student account promotion and ID provisioning.
* **`STUDENT`**: Self-service portal access for enrolled students. See [[Authentication|Student Authentication]].

## Related Notes
* [[Authentication|Authentication & Session Guards]]
* [[Station_System|Workstation Portals]]
* [[Security_Audit|Security Review]]
