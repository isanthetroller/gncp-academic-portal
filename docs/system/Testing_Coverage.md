---
title: Testing Matrix & Coverage
date: 2026-08-21
tags:
  - testing/selenium
  - testing/phpunit
  - testing/contracts
---

# 🧪 Testing Matrix & Coverage

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## Automated Test Suites
1. **End-to-End Browser Automation**: `tests/selenium/test_runner.py` (10-step full lifecycle with MariaDB assertions). Tests [[Student_Workflow|Student Workflow]].
2. **Financial Math Matrix**: `tests/test_financial_system.php` (28 isolated Philippine college billing scenarios). Tests [[Business_Rules|Financial Rules]].
3. **AST Syntax & Contract Linter**: `tests/run_tests.js` (`php -l` and Node VM syntax validation).

## Related Notes
* [[Student_Workflow|Workflow Testing]]
* [[Business_Rules|Business Rule Assertions]]
* [[Dangerous_Areas|Areas Requiring Regression Tests]]
