---
title: Mandatory Development Rules
date: 2026-08-21
tags:
  - rules/development
  - guidelines/coding
  - architecture/standards
---

# 🛠️ Mandatory Development Rules

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

* **`DEV-RULE-001`**: Always consume `Database::getInstance()` in `shared/backend/config/database.php`. Never create alternative PDO connection classes. Detailed in [[Architecture|Architecture Overview]].
* **`DEV-RULE-002`**: In Vue 3 progressive templates, never place raw line breaks inside string literals within `{{ ... }}` mustache expressions.
* **`DEV-RULE-003`**: Always use unique named PDO parameter identifiers in SQL statements (e.g. `:set_id` vs `:where_id`). See [[Database_Schema|Database Schema]].
* **`DEV-RULE-004`**: Workstation stylesheets must consume `background: var(--sidebar-bg);` from `shared/css/admin_workstation_theme.css`.
* **`DEV-RULE-005`**: Zero-refresh updates must be preserved via `DataBus.js` custom events.

## Related Notes
* [[Dangerous_Areas|Dangerous Areas to Avoid]]
* [[Business_Rules|System Business Rules]]
* [[Architecture|Architecture Overview]]
