import os
import shutil
import subprocess
import sys
import time

SOURCE_DIR = r"C:\xampp\htdocs\systemtest"
TARGET_DIR = r"C:\xampp\htdocs\systemtest-hardened"

EXCLUDE_DIRS = {".git", ".agents", "tests", "scratch", "tools", ".vscode"}
EXCLUDE_EXTS = {".map", ".bak", ".old", ".orig", ".tmp", ".log", ".py", ".pyc", ".md", ".credentials"}
EXCLUDE_FILES = {"inspect_modal_dom.py", ".ftp_credentials", "app_original.js"}

# Category A: Pure services, utility scripts, data models, guards (Safe for full transform including transform-object-keys and control-flow-flattening)
CATEGORY_A_FILES = [
    r"student-portal\assets\js\login-init.js",
    r"student-portal\assets\js\services\StudentApiService.js",
    r"student-portal\assets\js\models\StudentModel.js",
    r"enrollment-system\assets\js\services\ApiService.js",
    r"enrollment-system\assets\js\models\EnrollmentModel.js",
    r"registrar\assets\js\services\RegistrarApiService.js",
    r"shared\academic_constants.js",
    r"shared\js\PasswordChangeGuard.js",
    r"shared\js\SessionExpirationGuard.js",
    r"shared\js\StationPipeline.js",
    r"stations\assets\js\DataBus.js",
    r"assets\js\gateway-session.js",
    r"shared\paymongo\checkout-app.js",
]

# Category B: Vue 3 Controller and View objects (Requires transform-object-keys: false to preserve Vue reactive and template property bindings)
CATEGORY_B_FILES = [
    r"shared\js\DataCache.js",
    r"student-portal\assets\js\controllers\StudentLoginController.js",
    r"student-portal\assets\js\controllers\StudentForgotPasswordController.js",
    r"student-portal\assets\js\controllers\StudentPortalController.js",
    r"enrollment-system\assets\js\App.js",
    r"enrollment-system\assets\js\TrackerApp.js",
    r"school-website\assets\js\models\DataModel.js",
    r"school-website\assets\js\views\MainView.js",
    r"school-website\assets\js\views\PagesView.js",
    r"school-website\assets\js\controllers\AppController.js",
    r"registrar\assets\js\controllers\RegistrarController.js",
    r"registrar\assets\js\views\RegistrarView.js",
    r"admin\assets\js\controllers\AdminController.js",
    r"admin\assets\js\components\AdminSidebar.js",
    r"shared\js\components\EmployeeSidebar.js",
    r"stations\tlc-helpdesk\assets\js\app.js",
    r"stations\medical-checkup\assets\js\app.js",
    r"stations\payment-processing\assets\js\app.js",
    r"stations\it-center\assets\js\app.js",
    r"monitoring\assets\js\MonitorApp.js",
    r"assets\js\doc-viewer-app.js",
    r"assets\js\gateway-app.js",
    r"assets\js\app.js",
]

def sync_base_files():
    print("==================================================================")
    print("  STEP 1: SYNCING BASE REPOSITORY TO SYSTEMTEST-HARDENED         ")
    print("==================================================================")
    os.makedirs(TARGET_DIR, exist_ok=True)
    copied_count = 0

    for root, dirs, files in os.walk(SOURCE_DIR):
        rel_root = os.path.relpath(root, SOURCE_DIR)
        parts = rel_root.split(os.sep) if rel_root != "." else []
        if any(p in EXCLUDE_DIRS for p in parts):
            continue

        target_root = os.path.join(TARGET_DIR, rel_root)
        os.makedirs(target_root, exist_ok=True)

        for f in files:
            ext = os.path.splitext(f)[1].lower()
            if ext in EXCLUDE_EXTS or f in EXCLUDE_FILES:
                continue

            src_file = os.path.join(root, f)
            dst_file = os.path.join(target_root, f)

            # Copy file
            shutil.copy2(src_file, dst_file)
            copied_count += 1

    print(f"Copied {copied_count} production-safe files to {TARGET_DIR}.\n")

def obfuscate_category_a():
    print("==================================================================")
    print("  STEP 2: HARDENING CATEGORY A (SERVICES, GUARDS, UTILITIES)     ")
    print("==================================================================")
    for rel in CATEGORY_A_FILES:
        src = os.path.join(SOURCE_DIR, rel)
        dst = os.path.join(TARGET_DIR, rel)
        if not os.path.exists(src):
            print(f"[SKIP] {rel} not found in source.")
            continue

        os.makedirs(os.path.dirname(dst), exist_ok=True)
        print(f"Hardening (Cat A): {rel}...", end="", flush=True)

        cmd = [
            "npx.cmd", "-y", "javascript-obfuscator",
            src,
            "--output", dst,
            "--compact", "true",
            "--control-flow-flattening", "true",
            "--control-flow-flattening-threshold", "0.8",
            "--numbers-to-expressions", "true",
            "--simplify", "true",
            "--string-array", "true",
            "--string-array-encoding", "rc4",
            "--string-array-threshold", "1.0",
            "--string-array-calls-transform", "true",
            "--string-array-calls-transform-threshold", "1.0",
            "--string-array-wrappers-count", "3",
            "--string-array-wrappers-type", "function",
            "--string-array-wrappers-chained-calls", "true",
            "--string-array-index-shift", "true",
            "--string-array-rotate", "true",
            "--string-array-shuffle", "true",
            "--split-strings", "true",
            "--split-strings-chunk-length", "3",
            "--identifier-names-generator", "hexadecimal",
            "--rename-globals", "false",
            "--transform-object-keys", "true"
        ]

        res = subprocess.run(cmd, capture_output=True, text=True)
        if res.returncode == 0:
            sz = os.path.getsize(dst)
            print(f" [OK] ({sz:,} bytes)")
        else:
            print(f" [FAILED]: {res.stderr[:200]}")
            # Fallback to safe options if transform-object-keys had issues
            cmd_fallback = [
                "npx.cmd", "-y", "javascript-obfuscator",
                src,
                "--output", dst,
                "--compact", "true",
                "--numbers-to-expressions", "true",
                "--simplify", "true",
                "--string-array", "true",
                "--string-array-encoding", "rc4",
                "--string-array-threshold", "1.0",
                "--string-array-calls-transform", "true",
                "--string-array-wrappers-count", "3",
                "--string-array-wrappers-type", "function",
                "--string-array-wrappers-chained-calls", "true",
                "--split-strings", "true",
                "--split-strings-chunk-length", "3",
                "--identifier-names-generator", "hexadecimal",
                "--rename-globals", "false"
            ]
            res2 = subprocess.run(cmd_fallback, capture_output=True, text=True)
            if res2.returncode == 0:
                print(f"  -> Fallback succeeded: {os.path.getsize(dst):,} bytes")
            else:
                print(f"  -> Fallback failed: {res2.stderr[:200]}")
    print()

def obfuscate_category_b():
    print("==================================================================")
    print("  STEP 3: HARDENING CATEGORY B (VUE 3 CONTROLLERS & VIEWS)       ")
    print("==================================================================")
    for rel in CATEGORY_B_FILES:
        src = os.path.join(SOURCE_DIR, rel)
        dst = os.path.join(TARGET_DIR, rel)
        if not os.path.exists(src):
            print(f"[SKIP] {rel} not found in source.")
            continue

        os.makedirs(os.path.dirname(dst), exist_ok=True)
        print(f"Hardening (Cat B): {rel}...", end="", flush=True)

        cmd = [
            "npx.cmd", "-y", "javascript-obfuscator",
            src,
            "--output", dst,
            "--compact", "true",
            "--numbers-to-expressions", "true",
            "--simplify", "true",
            "--string-array", "true",
            "--string-array-encoding", "rc4",
            "--string-array-threshold", "1.0",
            "--string-array-calls-transform", "true",
            "--string-array-calls-transform-threshold", "1.0",
            "--string-array-wrappers-count", "3",
            "--string-array-wrappers-type", "function",
            "--string-array-wrappers-chained-calls", "true",
            "--string-array-index-shift", "true",
            "--string-array-rotate", "true",
            "--string-array-shuffle", "true",
            "--split-strings", "true",
            "--split-strings-chunk-length", "3",
            "--identifier-names-generator", "hexadecimal",
            "--rename-globals", "false",
            "--transform-object-keys", "false"
        ]

        res = subprocess.run(cmd, capture_output=True, text=True)
        if res.returncode == 0:
            sz = os.path.getsize(dst)
            print(f" [OK] ({sz:,} bytes)")
        else:
            print(f" [FAILED]: {res.stderr[:200]}")
    print()

def audit_deployment_cleanliness():
    print("==================================================================")
    print("  STEP 4: AUDITING TARGET DEPLOYMENT DIRECTORY CLEANLINESS        ")
    print("==================================================================")
    leaked_files = []
    for root, dirs, files in os.walk(TARGET_DIR):
        for f in files:
            ext = os.path.splitext(f)[1].lower()
            if ext in EXCLUDE_EXTS or f in EXCLUDE_FILES:
                p = os.path.join(root, f)
                leaked_files.append(p)
                os.remove(p)

    if leaked_files:
        print(f"Removed {len(leaked_files)} disallowed files (.map, .bak, etc.) from target.")
    else:
        print("Target deployment is 100% clean: 0 source maps, 0 backups, 0 development artifacts.")
    print("==================================================================\n")

if __name__ == "__main__":
    t0 = time.time()
    sync_base_files()
    obfuscate_category_a()
    obfuscate_category_b()
    audit_deployment_cleanliness()
    print(f"Hardened Production Pipeline Build completed in {time.time() - t0:.1f} seconds.")
