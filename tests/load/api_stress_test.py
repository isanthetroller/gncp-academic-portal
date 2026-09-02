import sys
import os
import time
import json
import random
import statistics
import threading
import subprocess
import requests
from urllib3.util import Retry
from requests.adapters import HTTPAdapter
import concurrent.futures

try:
    import psutil
except ImportError:
    psutil = None

BASE_URL = "http://127.0.0.1/systemtest"

# Setup high-throughput connection-pooled session factory
def create_session(pool_size=100):
    s = requests.Session()
    adapter = HTTPAdapter(
        pool_connections=pool_size,
        pool_maxsize=pool_size,
        max_retries=Retry(total=0, connect=0, read=0) # Do not mask connection errors
    )
    s.mount('http://', adapter)
    s.mount('https://', adapter)
    return s

def run_php(code):
    cmd = ["c:\\xampp\\php\\php.exe", "-r", code]
    res = subprocess.run(cmd, capture_output=True, text=True, cwd="c:/xampp/htdocs/systemtest")
    return res.stdout.strip()

def get_db_snapshot():
    script = """
    require 'shared/backend/config/database.php';
    $pdo = Database::getInstance();
    $counts = [
        'pre_enrollments' => (int)$pdo->query("SELECT COUNT(*) FROM pre_enrollments")->fetchColumn(),
        'students' => (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn(),
        'sections' => (int)$pdo->query("SELECT COUNT(*) FROM sections")->fetchColumn(),
        'curriculum' => (int)$pdo->query("SELECT COUNT(*) FROM curriculum")->fetchColumn(),
        'programs' => (int)$pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn(),
        'departments' => (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn(),
        'florence' => $pdo->query("SELECT id, course_code, year_level_applied, section_code, status FROM pre_enrollments WHERE email = 'test.florence@example.com'")->fetch(PDO::FETCH_ASSOC)
    ];
    $status = $pdo->query("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections')")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode(['counts' => $counts, 'status' => $status]);
    """
    out = run_php(script)
    try:
        return json.loads(out)
    except Exception as e:
        return {'counts': {}, 'status': {}, 'error': str(e), 'raw': out}

# Master Load Test Engine
class ApiStressTester:
    def __init__(self):
        self.registrar_session = create_session(100)
        self.admin_session = create_session(100)
        self.public_session = create_session(100)
        self.lock = threading.Lock()
        self.results = []
        self.endpoint_stats = {}
        self.rate_limit_events = []
        self.errors_sample = []
        self.active_workers = 0
        self.max_concurrency_seen = 0

    def authenticate(self):
        print("Authenticating test sessions...")
        # Authenticate registrar
        r1 = self.registrar_session.post(
            f"{BASE_URL}/shared/backend/login.php",
            json={"username": "kriz", "password": "kriz123"},
            timeout=10
        )
        # Authenticate admin
        r2 = self.admin_session.post(
            f"{BASE_URL}/shared/backend/login.php",
            json={"username": "admin", "password": "admin12345"},
            timeout=10
        )
        print(f"  Registrar Auth: {r1.status_code} | Admin Auth: {r2.status_code}")
        return r1.status_code == 200 and r2.status_code == 200

    def prepare_test_data(self):
        print("Preparing isolated test records in MariaDB...")
        setup_script = """
        require 'shared/backend/config/database.php';
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM sections WHERE code LIKE 'STRESS-%'")->execute();
        $stmt = $pdo->prepare("INSERT INTO sections (code, program, year_level, capacity, academic_period_id, adviser, curriculum_version) VALUES ('STRESS-CANARY-01', 'BSIT', '1st Year', 45, 1, 'Stress Tester', '2022 Curriculum') ON DUPLICATE KEY UPDATE capacity = 45");
        $stmt->execute();
        echo 'CANARY_READY';
        """
        out = run_php(setup_script)
        print(f"  Canary Section: {out}")

    def cleanup_test_data(self):
        print("Cleaning up stress test records from MariaDB...")
        clean_script = """
        require 'shared/backend/config/database.php';
        $pdo = Database::getInstance();
        $deleted = $pdo->exec("DELETE FROM sections WHERE code LIKE 'STRESS-%'");
        // Reset Florence Nightingale section to BSN-1TEST-B
        $pdo->prepare("UPDATE pre_enrollments SET section_code = 'BSN-1TEST-B', status = 'VERIFIED' WHERE email = 'test.florence@example.com'")->execute();
        echo "CLEANUP_DELETED_" . $deleted;
        """
        out = run_php(clean_script)
        print(f"  Cleanup result: {out}")

    def execute_request(self, req_spec):
        session = req_spec['session']
        method = req_spec['method']
        url = req_spec['url']
        name = req_spec['name']
        payload = req_spec.get('payload')
        category = req_spec.get('category', 'Read')

        t_start = time.perf_counter()
        status_code = 0
        error_msg = None
        resp_success = False

        try:
            if method == 'GET':
                resp = session.get(url, timeout=12)
            else:
                resp = session.post(url, json=payload, timeout=12)

            duration_ms = (time.perf_counter() - t_start) * 1000
            status_code = resp.status_code

            # Check rate limit header
            if status_code == 429:
                retry_after = resp.headers.get('Retry-After', 'unknown')
                with self.lock:
                    if len(self.rate_limit_events) < 5:
                        self.rate_limit_events.append({
                            'endpoint': name,
                            'status': 429,
                            'retry_after': retry_after,
                            'body': resp.text[:200]
                        })

            try:
                data = resp.json()
                resp_success = bool(data.get('success', False) or (status_code in [200, 304] and not data.get('error')))
            except Exception:
                resp_success = (200 <= status_code < 400)

        except requests.exceptions.Timeout:
            duration_ms = (time.perf_counter() - t_start) * 1000
            error_msg = "TIMEOUT"
            status_code = 504
        except requests.exceptions.ConnectionError as ce:
            duration_ms = (time.perf_counter() - t_start) * 1000
            error_msg = f"CONN_ERR: {str(ce)[:60]}"
            status_code = 503
        except Exception as e:
            duration_ms = (time.perf_counter() - t_start) * 1000
            error_msg = f"EXCEPTION: {str(e)[:60]}"
            status_code = 500

        res_entry = {
            'name': name,
            'category': category,
            'status_code': status_code,
            'duration_ms': duration_ms,
            'success': resp_success and (200 <= status_code < 300),
            'error': error_msg
        }

        with self.lock:
            self.results.append(res_entry)
            if name not in self.endpoint_stats:
                self.endpoint_stats[name] = {
                    'category': category,
                    'count': 0,
                    'success_count': 0,
                    'status_codes': {},
                    'durations': [],
                    'errors': {}
                }
            st = self.endpoint_stats[name]
            st['count'] += 1
            if res_entry['success']:
                st['success_count'] += 1
            st['status_codes'][status_code] = st['status_codes'].get(status_code, 0) + 1
            st['durations'].append(duration_ms)
            if error_msg:
                st['errors'][error_msg] = st['errors'].get(error_msg, 0) + 1
                if len(self.errors_sample) < 10:
                    self.errors_sample.append({'endpoint': name, 'status': status_code, 'error': error_msg})

        return res_entry

    def pick_request(self, index):
        # Distribution:
        # 40% Read/retrieval
        # 20% Registrar-related
        # 15% Student-related
        # 15% Course/curriculum/section
        # 10% Controlled safe writes
        dice = random.random()

        if dice < 0.40:
            # 40% Read operations
            sub = random.random()
            if sub < 0.25:
                return {
                    'name': 'admin/catalog (Read)',
                    'category': 'Read',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=admin/catalog",
                    'session': self.public_session
                }
            elif sub < 0.50:
                return {
                    'name': 'admin/terms (Read)',
                    'category': 'Read',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=admin/terms",
                    'session': self.public_session
                }
            elif sub < 0.75:
                return {
                    'name': 'announcements/list (Read)',
                    'category': 'Read',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=announcements/list",
                    'session': self.public_session
                }
            else:
                return {
                    'name': 'stations/queue (Read)',
                    'category': 'Read',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=stations/queue",
                    'session': self.registrar_session
                }

        elif dice < 0.60:
            # 20% Registrar-related
            sub = random.random()
            if sub < 0.50:
                return {
                    'name': 'registrar/fetch_all_data (Registrar)',
                    'category': 'Registrar',
                    'method': 'GET',
                    'url': f"{BASE_URL}/registrar/backend/api.php?action=fetch_all_data",
                    'session': self.registrar_session
                }
            elif sub < 0.70:
                return {
                    'name': 'registrar/get_sections_for_program (BSN, 1st Year)',
                    'category': 'Registrar',
                    'method': 'GET',
                    'url': f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSN&year_level=1st+Year",
                    'session': self.registrar_session
                }
            elif sub < 0.85:
                return {
                    'name': 'registrar/get_sections_for_program (BSIT, 3rd Year)',
                    'category': 'Registrar',
                    'method': 'GET',
                    'url': f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSIT&year_level=3rd+Year",
                    'session': self.registrar_session
                }
            else:
                return {
                    'name': 'registrar/get_sections_for_program (BSCS, 4th Year)',
                    'category': 'Registrar',
                    'method': 'GET',
                    'url': f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSCS&year_level=4th+Year",
                    'session': self.registrar_session
                }

        elif dice < 0.75:
            # 15% Student-related
            sub = random.random()
            if sub < 0.40:
                return {
                    'name': 'student/documents (Student)',
                    'category': 'Student',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=student/documents&identifier=REF-2026-1012",
                    'session': self.registrar_session
                }
            elif sub < 0.75:
                return {
                    'name': 'student_portal/dashboard (Student)',
                    'category': 'Student',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=student_portal/dashboard&studentId=GNCP-2026-0258",
                    'session': self.admin_session
                }
            else:
                return {
                    'name': 'student/track (Rate-Limited Monitor)',
                    'category': 'Student',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=student/track&ref=REF-2026-1012",
                    'session': self.public_session
                }

        elif dice < 0.90:
            # 15% Course/Curriculum/Section
            sub = random.random()
            if sub < 0.35:
                return {
                    'name': 'admin/sections (Course/Section)',
                    'category': 'Course/Section',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=admin/sections",
                    'session': self.public_session
                }
            elif sub < 0.70:
                return {
                    'name': 'registrar/sections (Central Course/Section)',
                    'category': 'Course/Section',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=registrar/sections&program=BSN&year_level=1st+Year",
                    'session': self.public_session
                }
            else:
                return {
                    'name': 'stations/stats (Station Metrics)',
                    'category': 'Course/Section',
                    'method': 'GET',
                    'url': f"{BASE_URL}/api/index.php?action=stations/stats",
                    'session': self.registrar_session
                }

        else:
            # 10% Controlled safe writes
            sub = random.random()
            if sub < 0.50:
                # Safe Section capacity/adviser update on isolated canary section
                cap = 40 + (index % 10)
                return {
                    'name': 'admin/save_section (Safe Write)',
                    'category': 'Write',
                    'method': 'POST',
                    'url': f"{BASE_URL}/api/index.php?action=admin/save_section",
                    'session': self.admin_session,
                    'payload': {
                        'code': 'STRESS-CANARY-01',
                        'program': 'BSIT',
                        'yearLevel': '1st Year',
                        'capacity': cap,
                        'adviser': f'Stress Tester #{index % 50}',
                        'academicPeriodId': 1
                    }
                }
            else:
                # Controlled section assignment toggle on Florence Nightingale (REF-2026-1012)
                target_sec = 'BSN-1TEST-A' if (index % 2 == 0) else 'BSN-1TEST-B'
                return {
                    'name': 'registrar/update_status (Safe Section Assign Write)',
                    'category': 'Write',
                    'method': 'POST',
                    'url': f"{BASE_URL}/registrar/backend/api.php?action=update_application_status",
                    'session': self.registrar_session,
                    'payload': {
                        'referenceNumber': 'REF-2026-1012',
                        'status': 'Approved',
                        'sectionCode': target_sec,
                        'notes': f'Stress test assignment toggle #{index}'
                    }
                }

    def run_stage(self, stage_name, count, concurrency, sequential_first=0):
        print(f"\n=======================================================")
        print(f"RUNNING {stage_name}: {count} Requests | Concurrency: {concurrency}")
        print(f"=======================================================")

        cpu_start = psutil.cpu_percent(interval=None) if psutil else 0
        mem_start = psutil.virtual_memory().percent if psutil else 0

        stage_results = []
        t0 = time.perf_counter()

        # 1. Sequential baseline if requested
        if sequential_first > 0:
            print(f"  -> Executing {sequential_first} sequential requests for baseline...")
            for i in range(sequential_first):
                spec = self.pick_request(i)
                res = self.execute_request(spec)
                stage_results.append(res)

        remaining = count - sequential_first
        print(f"  -> Executing {remaining} concurrent requests with {concurrency} workers...")

        with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
            futures = [executor.submit(self.execute_request, self.pick_request(sequential_first + i)) for i in range(remaining)]
            for f in concurrent.futures.as_completed(futures):
                stage_results.append(f.result())

        total_time = time.perf_counter() - t0
        rps = count / total_time if total_time > 0 else 0

        cpu_end = psutil.cpu_percent(interval=None) if psutil else 0
        mem_end = psutil.virtual_memory().percent if psutil else 0

        durations = [r['duration_ms'] for r in stage_results]
        status_counts = {}
        for r in stage_results:
            sc = r['status_code']
            status_counts[sc] = status_counts.get(sc, 0) + 1

        success_count = sum(1 for r in stage_results if r['success'])
        fail_count = len(stage_results) - success_count

        avg_ms = statistics.mean(durations) if durations else 0
        median_ms = statistics.median(durations) if durations else 0
        min_ms = min(durations) if durations else 0
        max_ms = max(durations) if durations else 0
        sorted_d = sorted(durations)
        p95_ms = sorted_d[int(len(sorted_d) * 0.95)] if sorted_d else 0
        p99_ms = sorted_d[int(len(sorted_d) * 0.99)] if sorted_d else 0

        summary = {
            'stage': stage_name,
            'total_requests': count,
            'concurrency': concurrency,
            'total_time_sec': round(total_time, 2),
            'requests_per_sec': round(rps, 2),
            'successful': success_count,
            'failed': fail_count,
            'status_codes': status_counts,
            'avg_ms': round(avg_ms, 2),
            'median_ms': round(median_ms, 2),
            'min_ms': round(min_ms, 2),
            'max_ms': round(max_ms, 2),
            'p95_ms': round(p95_ms, 2),
            'p99_ms': round(p99_ms, 2),
            'cpu_start': cpu_start,
            'cpu_end': cpu_end,
            'mem_start': mem_start,
            'mem_end': mem_end
        }

        print(f"  Summary for {stage_name}:")
        print(f"    Total: {count} | Success: {success_count} ({round(success_count/count*100, 1)}%) | Failed: {fail_count}")
        print(f"    RPS: {round(rps, 1)} req/sec | Duration: {round(total_time, 2)}s")
        print(f"    Avg: {round(avg_ms, 1)}ms | Median: {round(median_ms, 1)}ms | P95: {round(p95_ms, 1)}ms | P99: {round(p99_ms, 1)}ms")
        print(f"    Status Codes: {status_counts}")

        return summary

def main():
    print("=================================================================")
    print("STARTING REAL SYSTEM API STRESS & LOAD TEST (1,000 -> 10,000 REQS)")
    print("=================================================================")

    tester = ApiStressTester()
    if not tester.authenticate():
        print("FATAL: Session authentication failed.")
        sys.exit(1)

    tester.prepare_test_data()

    # Pre-test MariaDB snapshot
    print("\nTaking Pre-Test MariaDB Data & Thread Snapshot...")
    pre_snap = get_db_snapshot()
    print("Pre-Test DB Snapshot:", json.dumps(pre_snap, indent=2))

    stage_summaries = []

    # -------------------------------------------------------------------------
    # Stage 1: 1,000 Requests (Sequential 100 baseline + Concurrent 900 @ 25 workers)
    # -------------------------------------------------------------------------
    s1 = tester.run_stage("Stage 1 (1,000 Requests)", count=1000, concurrency=25, sequential_first=100)
    stage_summaries.append(s1)

    time.sleep(1)

    # -------------------------------------------------------------------------
    # Stage 2: 5,000 Requests (Concurrent @ 50 workers)
    # -------------------------------------------------------------------------
    s2 = tester.run_stage("Stage 2 (5,000 Requests)", count=5000, concurrency=50, sequential_first=0)
    stage_summaries.append(s2)

    time.sleep(1)

    # -------------------------------------------------------------------------
    # Stage 3: 10,000 Requests (Concurrent @ 100 workers)
    # -------------------------------------------------------------------------
    s3 = tester.run_stage("Stage 3 (10,000 Requests)", count=10000, concurrency=100, sequential_first=0)
    stage_summaries.append(s3)

    # -------------------------------------------------------------------------
    # System Recovery & Data Integrity Verification
    # -------------------------------------------------------------------------
    print("\n=======================================================")
    print("TESTING POST-STRESS SYSTEM RECOVERY & INTEGRITY AUDIT")
    print("=======================================================")
    print("Waiting 3 seconds for connection drain...")
    time.sleep(3)

    recovery_tests = []
    rec_session = create_session(10)
    r_auth = rec_session.post(f"{BASE_URL}/shared/backend/login.php", json={"username": "kriz", "password": "kriz123"}, timeout=10)
    recovery_tests.append(("Post-Load Authentication", r_auth.status_code == 200))

    t_reg0 = time.perf_counter()
    r_reg = rec_session.get(f"{BASE_URL}/registrar/backend/api.php?action=fetch_all_data", timeout=10)
    t_reg = (time.perf_counter() - t_reg0) * 1000
    recovery_tests.append(("Post-Load Registrar Loading (HTTP 200)", r_reg.status_code == 200 and t_reg < 500))

    r_sec = rec_session.get(f"{BASE_URL}/registrar/backend/api.php?action=get_sections_for_program&program=BSN&year_level=1st+Year", timeout=10)
    recovery_tests.append(("Post-Load Section Query BSN", r_sec.status_code == 200 and len(r_sec.json().get('data', [])) >= 2))

    # Post-test MariaDB snapshot
    post_snap = get_db_snapshot()
    print("Post-Test DB Snapshot:", json.dumps(post_snap, indent=2))

    pre_counts = pre_snap.get('counts', {})
    post_counts = post_snap.get('counts', {})

    integrity_checks = []
    # 1. Unrelated core tables must have 0 net change
    integrity_checks.append(("Curriculum Integrity (502 unchanged)", pre_counts.get('curriculum') == post_counts.get('curriculum') == 502))
    integrity_checks.append(("Programs Integrity (6 unchanged)", pre_counts.get('programs') == post_counts.get('programs') == 6))
    integrity_checks.append(("Departments Integrity (6 unchanged)", pre_counts.get('departments') == post_counts.get('departments') == 6))
    integrity_checks.append(("Students Directory Integrity", pre_counts.get('students') == post_counts.get('students')))
    integrity_checks.append(("Pre-Enrollment Applicants Integrity", pre_counts.get('pre_enrollments') == post_counts.get('pre_enrollments')))

    # 2. Check Florence Nightingale assignment
    fn_post = post_counts.get('florence', {})
    fn_valid_sec = fn_post.get('section_code') in ['BSN-1TEST-A', 'BSN-1TEST-B']
    integrity_checks.append(("Florence Nightingale Section Valid", fn_valid_sec))

    # Clean up stress canary sections
    tester.cleanup_test_data()

    # Aggregate Overall Statistics Across ALL 16,000 Executed Calls
    all_res = tester.results
    total_calls = len(all_res)
    total_success = sum(1 for r in all_res if r['success'])
    total_failed = total_calls - total_success

    status_2xx = sum(1 for r in all_res if 200 <= r['status_code'] < 300)
    status_3xx = sum(1 for r in all_res if 300 <= r['status_code'] < 400)
    status_4xx = sum(1 for r in all_res if 400 <= r['status_code'] < 500)
    status_5xx = sum(1 for r in all_res if 500 <= r['status_code'] < 600)
    timeouts = sum(1 for r in all_res if r.get('error') == 'TIMEOUT')
    conn_errors = sum(1 for r in all_res if r.get('error') and 'CONN_ERR' in r['error'])

    all_durations = [r['duration_ms'] for r in all_res]
    overall_avg = statistics.mean(all_durations) if all_durations else 0
    overall_median = statistics.median(all_durations) if all_durations else 0
    overall_min = min(all_durations) if all_durations else 0
    overall_max = max(all_durations) if all_durations else 0
    sorted_all = sorted(all_durations)
    overall_p95 = sorted_all[int(len(sorted_all) * 0.95)] if sorted_all else 0
    overall_p99 = sorted_all[int(len(sorted_all) * 0.99)] if sorted_all else 0

    # Read error logs
    app_errors_post = run_php("""
    $f = 'shared/backend/logs/app_errors.log';
    if (!file_exists($f)) { echo '0'; exit; }
    $lines = file($f, FILE_SKIP_EMPTY_LINES);
    $today = date('Y-m-d');
    $todayCount = 0;
    foreach ($lines as $l) {
        if (strpos($l, $today) !== false) $todayCount++;
    }
    echo $todayCount;
    """)

    # Output detailed report file
    report_data = {
        'total_requests': total_calls,
        'stages': stage_summaries,
        'overall': {
            'total_requests': total_calls,
            'successful': total_success,
            'failed': total_failed,
            'status_2xx': status_2xx,
            'status_3xx': status_3xx,
            'status_4xx': status_4xx,
            'status_5xx': status_5xx,
            'timeouts': timeouts,
            'conn_errors': conn_errors,
            'avg_ms': round(overall_avg, 2),
            'median_ms': round(overall_median, 2),
            'min_ms': round(overall_min, 2),
            'max_ms': round(overall_max, 2),
            'p95_ms': round(overall_p95, 2),
            'p99_ms': round(overall_p99, 2),
        },
        'endpoint_stats': {
            name: {
                'category': data['category'],
                'count': data['count'],
                'success_count': data['success_count'],
                'success_rate': round(data['success_count'] / data['count'] * 100, 2) if data['count'] > 0 else 0,
                'avg_ms': round(statistics.mean(data['durations']), 2) if data['durations'] else 0,
                'p95_ms': round(sorted(data['durations'])[int(len(data['durations']) * 0.95)], 2) if data['durations'] else 0,
                'status_codes': data['status_codes'],
                'errors': data['errors']
            }
            for name, data in tester.endpoint_stats.items()
        },
        'rate_limit_events': tester.rate_limit_events,
        'recovery_tests': recovery_tests,
        'integrity_checks': integrity_checks,
        'app_errors_today': int(app_errors_post or 0)
    }

    with open("tests/load/stress_test_report.json", "w") as f:
        json.dump(report_data, f, indent=2)

    print("\n=======================================================")
    print("STRESS TEST COMPLETED SUCCESSFULLY!")
    print(f"Total Requests Dispatched: {total_calls}")
    print(f"Detailed Report Saved to: tests/load/stress_test_report.json")
    print("=======================================================")

if __name__ == "__main__":
    main()
