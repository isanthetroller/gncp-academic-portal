---
title: Authentication and Account Management
date: 2026-08-21
tags:
  - security/auth
  - security/sessions
  - security/passwords
---

# 🔑 Authentication & Account Management

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## Authentication Pathways
1. **Employee / Staff**: Login via `/index.html` $\rightarrow$ queries `station_users` where `status = 'ACTIVE'`. Sessions: `gncp_admin_user` / `gncp_station_user`.
2. **Student Portal**: Login via `/student-portal/login.html` $\rightarrow$ queries `students` table. Session: `gncp_student`.
3. **Password Security**: Mandatory Bcrypt (`PASSWORD_DEFAULT`). Zero plaintext passwords.
4. **First-Login Guard**: If `must_change_password === 1`, `PasswordChangeGuard.js` intercepts DOM rendering until updated.
5. **Self-Service Reset**: 6-digit OTP codes stored in `password_resets` table (expires in 30 minutes), dispatched via `EmailService.php`.

## Related Notes
* [[Roles_and_Permissions|Roles and Permissions]]
* [[Security_Audit|Security Vulnerabilities & Guards]]
* [[API_Map|Auth API Endpoints]]
