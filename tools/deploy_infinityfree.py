import os
import sys
import time
from ftplib import FTP, error_perm

def load_ftp_credentials():
    creds = {
        "FTP_HOST": os.environ.get("GNCP_FTP_HOST", "ftpupload.net"),
        "FTP_USER": os.environ.get("GNCP_FTP_USER", "if0_42745296"),
        "FTP_PASS": os.environ.get("GNCP_FTP_PASS", ""),
        "REMOTE_ROOT": os.environ.get("GNCP_REMOTE_ROOT", "/htdocs")
    }
    creds_file = os.path.join(os.path.dirname(__file__), ".ftp_credentials")
    if os.path.exists(creds_file):
        with open(creds_file, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    creds[k.strip()] = v.strip()
    return creds

_creds = load_ftp_credentials()
FTP_HOST = _creds["FTP_HOST"]
FTP_USER = _creds["FTP_USER"]
FTP_PASS = _creds["FTP_PASS"]
REMOTE_ROOT = _creds["REMOTE_ROOT"]
_hardened_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "systemtest-hardened"))
LOCAL_ROOT = os.environ.get("GNCP_LOCAL_ROOT", _hardened_dir if os.path.exists(_hardened_dir) else os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

EXCLUDE_DIRS = {".git", ".agents", "tests", "node_modules", ".vscode", "scratch", "uploads", "tools", "docs"}
EXCLUDE_EXTS = {".log", ".tmp", ".pyc", ".py", ".credentials", ".bat", ".map", ".ps1"}
EXCLUDE_FILES = {"tests", "inspect_modal_dom.py", ".ftp_credentials"}

class FtpDeployer:
    def __init__(self, host, user, password, remote_root, local_root):
        self.host = host
        self.user = user
        self.password = password
        self.remote_root = remote_root
        self.local_root = local_root
        self.ftp = None

    def connect(self):
        print(f"Connecting to FTP server {self.host}...")
        self.ftp = FTP(self.host, timeout=30)
        self.ftp.login(self.user, self.password)
        self.ftp.set_pasv(True)
        print(f"Logged in as {self.user}. Working directory: {self.ftp.pwd()}")

    def ensure_connected(self):
        try:
            if self.ftp is None:
                self.connect()
            else:
                self.ftp.voidcmd("NOOP")
        except Exception:
            print("Connection lost. Reconnecting...")
            time.sleep(2)
            self.connect()

    def ensure_remote_dir(self, remote_dir):
        """Recursively ensure a remote directory exists."""
        self.ensure_connected()
        parts = remote_dir.strip("/").split("/")
        current = ""
        for part in parts:
            current += "/" + part
            try:
                self.ftp.cwd(current)
            except error_perm:
                try:
                    self.ftp.mkd(current)
                    self.ftp.cwd(current)
                except Exception as e:
                    # Ignore if exists or retry
                    pass

    def get_remote_file_size(self, remote_file):
        try:
            return self.ftp.size(remote_file)
        except Exception:
            return None

    def upload_file(self, local_file, remote_file, max_retries=3):
        local_size = os.path.getsize(local_file)
        for attempt in range(1, max_retries + 1):
            try:
                self.ensure_connected()
                remote_dir = os.path.dirname(remote_file).replace("\\", "/")
                filename = os.path.basename(remote_file)
                self.ftp.cwd(remote_dir)
                with open(local_file, "rb") as fp:
                    self.ftp.storbinary(f"STOR {filename}", fp)
                return True
            except Exception as e:
                print(f"    [WARN] Upload attempt {attempt} failed for {remote_file}: {e}")
                time.sleep(2)
                try:
                    self.connect()
                except Exception:
                    pass
        return False

    def collect_local_files(self):
        files_to_sync = []
        for dirpath, dirnames, filenames in os.walk(self.local_root):
            rel_dir = os.path.relpath(dirpath, self.local_root)
            parts = rel_dir.split(os.sep) if rel_dir != "." else []
            if any(p in EXCLUDE_DIRS for p in parts):
                continue

            for f in filenames:
                ext = os.path.splitext(f)[1].lower()
                if ext in EXCLUDE_EXTS or f in EXCLUDE_FILES:
                    continue
                local_path = os.path.join(dirpath, f)
                rel_path = os.path.relpath(local_path, self.local_root).replace("\\", "/")
                files_to_sync.append((local_path, rel_path))

        return sorted(files_to_sync, key=lambda x: x[1])

    def deploy(self):
        print("==========================================================")
        print("  INFINITYFREE FTP PRODUCTION DEPLOYMENT & SYNCHRONIZER  ")
        print("==========================================================")
        print(f"Local Root:  {self.local_root}")
        print(f"Remote Root: {self.remote_root}")
        
        self.connect()
        files = self.collect_local_files()
        total_files = len(files)
        total_bytes = sum(os.path.getsize(f[0]) for f in files)
        print(f"Discovered {total_files} project files ({total_bytes / 1024 / 1024:.2f} MB) to process.\n")

        # Ensure essential remote upload directories exist
        essential_dirs = [
            f"{self.remote_root}/uploads",
            f"{self.remote_root}/uploads/announcements",
            f"{self.remote_root}/uploads/requirements",
            f"{self.remote_root}/uploads/avatars",
            f"{self.remote_root}/shared/backend/logs"
        ]
        for ed in essential_dirs:
            self.ensure_remote_dir(ed)

        uploaded_count = 0
        skipped_count = 0
        uploaded_bytes = 0
        start_time = time.time()

        for idx, (local_path, rel_path) in enumerate(files, 1):
            remote_path = f"{self.remote_root}/{rel_path}"
            remote_dir = os.path.dirname(remote_path).replace("\\", "/")
            local_size = os.path.getsize(local_path)
            
            # Check remote directory
            self.ensure_remote_dir(remote_dir)

            # Check if file needs upload:
            # We always upload files that are code (PHP, JS, HTML, CSS, SQL, JSON, .htaccess) or if size differs
            ext = os.path.splitext(local_path)[1].lower()
            fname = os.path.basename(local_path).lower()
            is_code_file = ext in {".php", ".js", ".html", ".css", ".sql", ".json"} or fname == ".htaccess"
            
            remote_size = self.get_remote_file_size(remote_path)
            if not is_code_file and remote_size == local_size:
                # Static asset with identical size: skip
                print(f"[{idx:3d}/{total_files:3d}] [SKIP] {rel_path} ({local_size:,} B - identical)")
                skipped_count += 1
                continue

            print(f"[{idx:3d}/{total_files:3d}] [UPLD] {rel_path} ({local_size:,} B)...", end="", flush=True)
            success = self.upload_file(local_path, remote_path)
            if success:
                print(" [OK]")
                uploaded_count += 1
                uploaded_bytes += local_size
            else:
                print(" [FAILED]")

        elapsed = time.time() - start_time
        print("\n==========================================================")
        print("  DEPLOYMENT COMPLETE                                     ")
        print("==========================================================")
        print(f"Total Files:    {total_files}")
        print(f"Uploaded:       {uploaded_count} files ({uploaded_bytes / 1024 / 1024:.2f} MB)")
        print(f"Skipped:        {skipped_count} identical static assets")
        print(f"Elapsed Time:   {elapsed:.1f} seconds")
        print("==========================================================\n")

        # Verify Key Files
        self.verify_deployment()

    def verify_deployment(self):
        print("--- Verifying Critical Deployment Endpoints ---")
        critical_files = [
            "admin/index.html",
            "admin/index.php",
            "admin/assets/js/controllers/AdminController.js",
            "admin/backend/api.php",
            "shared/backend/services/AnnouncementService.php",
            "shared/backend/config/mail.local.php",
            "shared/backend/config/db_config.php",
            "shared/js/PasswordChangeGuard.js",
            "student-portal/index.html",
            "student-portal/index.php",
            "student-portal/login.html",
            "student-portal/assets/js/controllers/StudentPortalController.js",
            "student-portal/assets/js/services/StudentApiService.js",
            "registrar/index.html",
            "registrar/index.php",
            "registrar/assets/js/controllers/RegistrarController.js",
            "stations/it-center/assets/js/app.js",
            "stations/medical-checkup/assets/js/app.js",
            "stations/payment-processing/assets/js/app.js",
            "stations/tlc-helpdesk/assets/js/app.js",
            "index.html",
            ".htaccess"
        ]
        
        all_ok = True
        for cf in critical_files:
            local_p = os.path.join(self.local_root, cf)
            remote_p = f"{self.remote_root}/{cf}"
            if not os.path.exists(local_p):
                continue
            local_sz = os.path.getsize(local_p)
            remote_sz = self.get_remote_file_size(remote_p)
            status = "PASS" if remote_sz == local_sz else f"MISMATCH (Local: {local_sz}, Remote: {remote_sz})"
            if remote_sz != local_sz:
                all_ok = False
            print(f"  [{status}] {cf} ({local_sz} bytes)")

        if all_ok:
            print("\n[ALL CRITICAL ENDPOINTS VERIFIED & SYNCHRONIZED!]")
        else:
            print("\n[WARN: Some files had size discrepancies.]")

if __name__ == "__main__":
    deployer = FtpDeployer(FTP_HOST, FTP_USER, FTP_PASS, REMOTE_ROOT, LOCAL_ROOT)
    deployer.deploy()
