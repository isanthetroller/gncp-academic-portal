# GNCP Academic Portal — Archived Test Scripts

This directory contains historic, legacy, and ad-hoc test scripts that have been superseded by the **Unified Automated Test Suite** located in [`tests/unified/`](../unified/).

All functionality, validations, and security assertions from these scripts have been consolidated into:
- [`tests/unified/suites/suite_01_core_pipeline.py`](../unified/suites/suite_01_core_pipeline.py)
- [`tests/unified/suites/suite_02_auth_security.py`](../unified/suites/suite_02_auth_security.py)
- [`tests/unified/suites/suite_03_special_journeys.py`](../unified/suites/suite_03_special_journeys.py)
- [`tests/unified/suites/suite_04_backend_engines.py`](../unified/suites/suite_04_backend_engines.py)

To run the unified test suite against your local XAMPP environment:
```bash
python tests/unified/run_test_suite.py
```
