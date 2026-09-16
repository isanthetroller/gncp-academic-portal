import os
import sys
import time
import json

if hasattr(sys.stdout, 'reconfigure'):
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

if hasattr(sys.stderr, 'reconfigure'):
    try:
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

class TestReporter:
    def __init__(self, suite_name):
        self.suite_name = suite_name
        self.results = []
        self.start_time = time.time()

    def record(self, category, name, passed, details=""):
        status = "PASS" if passed else "FAIL"
        tag = "\033[92m[PASS]\033[0m" if passed else "\033[91m[FAIL]\033[0m"
        try:
            print(f"  {tag} [{category}] {name}")
            if details:
                print(f"         -> {details}")
        except Exception:
            clean_name = name.encode('ascii', 'replace').decode('ascii')
            clean_details = str(details).encode('ascii', 'replace').decode('ascii')
            print(f"  [{status}] [{category}] {clean_name}")
            if details:
                print(f"         -> {clean_details}")
        
        self.results.append({
            "category": category,
            "name": name,
            "status": status,
            "details": str(details),
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
        })
        return passed

    def get_summary(self):
        total = len(self.results)
        passed = sum(1 for r in self.results if r["status"] == "PASS")
        failed = total - passed
        duration = time.time() - self.start_time
        return {
            "suite": self.suite_name,
            "suite_name": self.suite_name,
            "total": total,
            "passed": passed,
            "failed": failed,
            "duration": round(duration, 2),
            "verdict": "PASS" if failed == 0 else "FAIL",
            "results": self.results
        }

    def print_summary(self):
        s = self.get_summary()
        print("\n" + "=" * 78)
        print(f"  SUITE SUMMARY: {self.suite_name}")
        print("=" * 78)
        print(f"  Total Assertions : {s['total']}")
        print(f"  Passed           : \033[92m{s['passed']}\033[0m")
        print(f"  Failed           : \033[91m{s['failed']}\033[0m")
        print(f"  Duration         : {s['duration']}s")
        print(f"  Overall Verdict  : {s['verdict']}")
        print("=" * 78 + "\n")

    def export_markdown(self, filepath):
        s = self.get_summary()
        md = []
        md.append(f"# Test Suite Execution Report: {self.suite_name}\n")
        md.append(f"* **Execution Date**: `{time.strftime('%Y-%m-%d %H:%M:%S')}`")
        md.append(f"* **Duration**: `{s['duration']}s`")
        md.append(f"* **Verdict**: **{s['verdict']}** ({s['passed']}/{s['total']} passed)\n")
        md.append("## Detailed Assertions\n")
        md.append("| Category | Test / Assertion | Status | Details |")
        md.append("| :--- | :--- | :---: | :--- |")
        for r in self.results:
            st = "✅ PASS" if r["status"] == "PASS" else "❌ FAIL"
            md.append(f"| {r['category']} | {r['name']} | {st} | {r['details']} |")
        with open(filepath, "w", encoding="utf-8") as f:
            f.write("\n".join(md))
