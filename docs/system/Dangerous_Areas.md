---
title: Dangerous Areas to Modify
date: 2026-08-21
tags:
  - architecture/warnings
  - workflow/critical
  - dangerous-areas
---

# ⚠️ Dangerous Areas to Modify

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

> [!danger] High-Risk Code Locations
> 
> ### 1. `stations/backend/services/EnrollmentService.php`
> * **Why Dangerous**: Encapsulates ACID multi-table student account promotion.
> * **Dependencies**: IT Center, Student Portal, [[Student_Workflow|State Machine]].
> * **Verification**: Run `tests/selenium/test_runner.py`.
> 
> ### 2. `shared/backend/services/AssessmentService.php`
> * **Why Dangerous**: Calculates all official billing totals and installment surcharges.
> * **Dependencies**: Registration statement, Cashier billing, Student Portal COR.
> * **Verification**: Run `php tests/test_financial_system.php`.
> 
> ### 3. `shared/backend/utils/student.php` (`getCurriculumSubjects`)
> * **Why Dangerous**: Resolves subject offerings for both advising and the student portal COR.
> * **Dependencies**: TLC Helpdesk, Cashier, Student Portal.

## Related Notes
* [[Student_Workflow|State Machine Pipeline]]
* [[Business_Rules|Core Business Rules]]
* [[Development_Rules|Mandatory Development Rules]]
