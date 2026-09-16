import os
import sys
import time
import argparse
from datetime import datetime

sys.path.append(os.path.abspath(os.path.dirname(__file__)))
from config import BASE_URL, REPORTS_DIR
from utils.reporter import TestReporter

from suites import suite_01_core_pipeline
from suites import suite_02_auth_security
from suites import suite_03_special_journeys
from suites import suite_04_backend_engines

def print_banner():
    print("=" * 80)
    print("      GNCP ACADEMIC PORTAL — UNIFIED MASTER AUTOMATED TEST SUITE")
    print("=" * 80)
    print(f"  Target Environment : {BASE_URL}")
    print(f"  Database           : MariaDB 10.x (gncp_portal @ 127.0.0.1:3306)")
    print(f"  Execution Time     : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"  Test Architecture  : Unified Modular Runners (Playwright + PHP Engine)")
    print("=" * 80 + "\n")

def run_all(selected_suite=None):
    start_time = time.time()
    print_banner()

    suites_to_run = []
    if selected_suite in [None, "all", "pipeline", "01"]:
        suites_to_run.append(("01_pipeline", suite_01_core_pipeline.run_suite))
    if selected_suite in [None, "all", "security", "auth", "02"]:
        suites_to_run.append(("02_security", suite_02_auth_security.run_suite))
    if selected_suite in [None, "all", "journeys", "special", "03"]:
        suites_to_run.append(("03_journeys", suite_03_special_journeys.run_suite))
    if selected_suite in [None, "all", "backend", "engines", "04"]:
        suites_to_run.append(("04_backend", suite_04_backend_engines.run_suite))

    reporters = []
    for name, run_fn in suites_to_run:
        try:
            rep = run_fn()
            reporters.append(rep)
        except Exception as e:
            print(f"\n[FATAL ERROR] Suite {name} crashed: {e}")
            crash_rep = TestReporter(name)
            crash_rep.record("Crash", f"Execution Exception in {name}", False, str(e))
            reporters.append(crash_rep)

    total_duration = time.time() - start_time
    total_assertions = sum(r.get_summary()["total"] for r in reporters)
    total_passed = sum(r.get_summary()["passed"] for r in reporters)
    total_failed = sum(r.get_summary()["failed"] for r in reporters)

    # Master Console Summary
    print("\n" + "#" * 80)
    print("                  UNIFIED MASTER TEST EXECUTION SUMMARY")
    print("#" * 80)
    print(f"  Total Suites Executed : {len(reporters)}")
    print(f"  Total Assertions      : {total_assertions}")
    print(f"  Passed                : {total_passed} ({total_passed/total_assertions*100:.1f}%)" if total_assertions else "  Passed: 0")
    print(f"  Failed                : {total_failed}")
    print(f"  Total Execution Time  : {total_duration:.2f}s")
    print(f"  Overall System Verdict: {'>>> PASS <<<' if total_failed == 0 else '>>> FAIL <<<'}")
    print("#" * 80)

    # Export Master Consolidated Report
    report_file = os.path.join(REPORTS_DIR, "UNIFIED_SYSTEM_TEST_REPORT.md")
    with open(report_file, "w", encoding="utf-8") as f:
        f.write("# GNCP Academic Portal — Unified Automated Test Report\n\n")
        f.write(f"- **Execution Date:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"- **Target URL:** {BASE_URL}\n")
        f.write(f"- **Total Assertions:** {total_assertions}\n")
        f.write(f"- **Passed:** {total_passed}\n")
        f.write(f"- **Failed:** {total_failed}\n")
        f.write(f"- **Duration:** {total_duration:.2f} seconds\n")
        f.write(f"- **Overall Status:** `{'PASSED' if total_failed == 0 else 'FAILED'}`\n\n")

        f.write("## Suite Breakdown\n\n")
        f.write("| Suite Name | Assertions | Passed | Failed | Pass Rate | Verdict |\n")
        f.write("| :--- | :---: | :---: | :---: | :---: | :---: |\n")
        for r in reporters:
            sm = r.get_summary()
            pct = (sm['passed'] / sm['total'] * 100) if sm['total'] > 0 else 0
            verdict = "✅ PASS" if sm['failed'] == 0 else "❌ FAIL"
            f.write(f"| {sm['suite_name']} | {sm['total']} | {sm['passed']} | {sm['failed']} | {pct:.1f}% | {verdict} |\n")

        f.write("\n## Detailed Test Assertions\n\n")
        for r in reporters:
            sm = r.get_summary()
            f.write(f"### {sm['suite_name']}\n\n")
            f.write("| Category | Test / Assertion | Status | Details |\n")
            f.write("| :--- | :--- | :---: | :--- |\n")
            for res in sm["results"]:
                st_badge = "✅ PASS" if res["status"] else "❌ FAIL"
                f.write(f"| {res['category']} | {res['name']} | {st_badge} | {res['details']} |\n")
            f.write("\n")

    print(f"\n[+] Master Test Report successfully generated at:\n    {report_file}\n")
    return total_failed == 0

def main():
    parser = argparse.ArgumentParser(description="GNCP Academic Portal Unified Test Suite Runner")
    parser.add_argument("--suite", choices=["all", "pipeline", "security", "journeys", "backend"], default="all",
                        help="Select which test suite to run (default: all)")
    args = parser.parse_args()

    success = run_all(selected_suite=args.suite)
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()
