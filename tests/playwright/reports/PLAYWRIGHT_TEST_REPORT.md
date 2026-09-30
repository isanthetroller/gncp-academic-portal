# GNCP Academic Portal — Playwright Multi-Layer Test Report

- **Execution Date:** 2026-09-30 11:37:23
- **Target URL:** http://127.0.0.1/systemtest
- **Browser:** Chromium (Playwright 1.62)
- **Total Assertions:** 13
- **Passed:** 10
- **Failed:** 3
- **Total Duration:** 118.9s
- **Overall Status:** `FAIL`

---

## Suite Summary

| Suite | Assertions | Passed | Failed | Duration | Verdict |
| :--- | :---: | :---: | :---: | :---: | :---: |
| A: Page Load & Navigation Health | 13 | 10 | 3 | 110.4s | ❌ FAIL |

---

## Detailed Results

### A: Page Load & Navigation Health

| # | Test / Assertion | Status | Details |
| :---: | :--- | :---: | :--- |
| 1 | Gateway / Home | ❌ | Page.goto: Timeout 20000ms exceeded.
Call log:
  - navigating to "http://127.0.0.1/systemtest/index.html", waiting until "domcontentloaded"
 |
| 2 | Enrollment Form | ❌ | Page.goto: Timeout 20000ms exceeded.
Call log:
  - navigating to "http://127.0.0.1/systemtest/enrollment-system/index.html", waiting until "domcontentloaded"
 |
| 3 | Application Tracker | ❌ | Page.goto: Timeout 20000ms exceeded.
Call log:
  - navigating to "http://127.0.0.1/systemtest/enrollment-system/tracker.html", waiting until "domcontentloaded"
 |
| 4 | Registrar Station | ✅ | HTTP 200 | 17.04s |
| 5 | TLC Helpdesk Station | ✅ | HTTP 200 | 2.54s |
| 6 | Medical Station | ✅ | HTTP 200 | 1.02s |
| 7 | Cashier Station | ✅ | HTTP 200 | 1.55s |
| 8 | IT Center Station | ✅ | HTTP 200 | 1.31s |
| 9 | Admin Portal | ✅ | HTTP 200 | 1.10s |
| 10 | Scheduler Station | ✅ | HTTP 200 | 0.90s |
| 11 | Professor Portal | ✅ | HTTP 200 | 1.23s |
| 12 | Student Portal | ✅ | HTTP 200 | 4.22s |
| 13 | Student Login | ✅ | HTTP 200 | 0.86s |

