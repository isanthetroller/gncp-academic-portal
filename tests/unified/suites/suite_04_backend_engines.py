import os
import sys
import subprocess

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
from config import PHP_EXE, TESTS_ROOT
from utils.reporter import TestReporter

def run_suite():
    reporter = TestReporter("Suite 04: PHP Backend Domain Engines")
    print("\n" + "=" * 78)
    print("  RUNNING SUITE 04: PHP BACKEND DOMAIN ENGINES (104 SCENARIOS)")
    print("=" * 78)

    php_scripts = [
        ("Financial Engine (28 Scenarios)", "test_financial_system.php"),
        ("Requirements & Undertakings (32 Scenarios)", "test_student_portal_requirements_workflow.php"),
        ("PayMongo Centavos & Arithmetic (28 Scenarios)", "test_paymongo_simulation.php"),
        ("Medical Clearance & Clinic Flow (16 Scenarios)", "test_medical_review_flow.php")
    ]

    for label, script_rel in php_scripts:
        script_path = os.path.join(TESTS_ROOT, script_rel)
        if not os.path.exists(script_path):
            reporter.record("BackendEngines", label, False, f"Script not found at {script_path}")
            continue

        try:
            res = subprocess.run([PHP_EXE, script_path], capture_output=True, text=True, timeout=30)
            passed = (res.returncode == 0) and ("FAIL" not in res.stdout or "Failed: 0" in res.stdout or "0 FAILED" in res.stdout)
            last_lines = [line.strip() for line in res.stdout.strip().split("\n") if line.strip()][-2:]
            summary_str = " | ".join(last_lines)
            reporter.record("BackendEngines", label, passed, f"Exit {res.returncode} -> {summary_str}")
        except Exception as e:
            reporter.record("BackendEngines", label, False, f"Execution exception: {str(e)}")

    reporter.print_summary()
    return reporter

if __name__ == "__main__":
    rep = run_suite()
    s = rep.get_summary()
    sys.exit(0 if s["failed"] == 0 else 1)
