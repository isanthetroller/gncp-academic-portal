---
title: Canonical API Route Map
date: 2026-08-21
tags:
  - api/rest
  - api/routes
  - architecture/endpoints
---

# 🌐 Canonical API Route Inventory

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## Canonical Gateway (`api/index.php`)
* `POST ?action=auth/login`: Staff and student authentication. See [[Authentication|Authentication]].
* `POST ?action=student/register`: Public application registration. See [[Student_Workflow|Registration]].
* `GET  ?action=student/track`: Real-time application tracker & document checklist.
* `GET  ?action=student/documents`: Retrieves student document requirements catalog and compliance summary metrics.
* `POST ?action=student/upload_document`: Multi-format soft copy document upload (PDF, PNG, JPG, DOCX) and conditional undertaking commitment.
* `POST ?action=registrar/verify_document`: Registrar document approval, status transition, and remarks annotation.
* `GET  ?action=stations/queue`: 3s background polling queue stream. See [[Station_System|Station Subsystems]].
* `POST ?action=stations/update`: Transactional applicant update and promotion.
* `POST ?action=registrar/update_status`: Registrar admission approval.
* `GET  ?action=admin/catalog`: Academic programs and curriculum retrieval. See [[Curriculum_System|Curriculum System]].
* `POST ?action=admin/save_user`: Staff account provisioning. See [[Roles_and_Permissions|Roles]].
* `GET  /systemtest/shared/paymongo/checkout.html`: PayMongo hosted payment gateway supporting GCash, Maya, QR Ph, and Cards.

## Related Notes
* [[Architecture|Architecture Topology]]
* [[Security_Audit|Rate Limiting & Security]]
* [[Authentication|Authentication Gateway]]
