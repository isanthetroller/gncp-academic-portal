import os
import sys
import time
import io
import urllib.request
import ssl
from ftplib import FTP, error_perm

# ==============================================================================
# GNCP ACADEMIC SYSTEM - PRODUCTION FTP SYNCHRONIZER & LIVE TIMER MONITOR
# ==============================================================================

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
_hardened_path = os.path.abspath(r"C:\xampp\htdocs\systemtest-hardened")
LOCAL_ROOT = _hardened_path if ("--hardened" in sys.argv or os.environ.get("USE_HARDENED") == "1") else os.path.abspath(os.environ.get("GNCP_LOCAL_ROOT", r"C:\xampp\htdocs\systemtest"))


# Excluded paths and extensions
EXCLUDE_DIRS = {
    ".git", ".agents", "tests", "node_modules", ".vscode", 
    "scratch", "uploads", "tools", "docs"
}
EXCLUDE_EXTS = {
    ".log", ".tmp", ".pyc", ".py", ".credentials", 
    ".bat", ".map", ".ps1", ".md"
}
EXCLUDE_FILES = {
    "tests", "inspect_modal_dom.py", ".ftp_credentials", 
    ".env", "app_original.js", "scratch_search_payment.txt"
}

# CRITICAL SAFEGUARDS: Do NOT overwrite production database or mail configs!
PROTECTED_REMOTE_FILES = {
    "shared/backend/config/db_config.php",
    "shared/backend/config/mail.local.php"
}

# Code file extensions that should always be synced to ensure latest code is live
CODE_EXTENSIONS = {".php", ".js", ".html", ".css", ".json", ".sql"}

def format_duration(seconds):
    """Format seconds into MM:SS or HH:MM:SS."""
    seconds = int(seconds)
    hours = seconds // 3600
    minutes = (seconds % 3600) // 60
    secs = seconds % 60
    if hours > 0:
        return f"{hours:02d}:{minutes:02d}:{secs:02d}"
    return f"{minutes:02d}:{secs:02d}"

def format_size(num_bytes):
    """Format bytes to human readable format."""
    if num_bytes < 1024:
        return f"{num_bytes} B"
    elif num_bytes < 1024 * 1024:
        return f"{num_bytes / 1024:.1f} KB"
    else:
        return f"{num_bytes / (1024 * 1024):.2f} MB"

class LiveSyncMonitor:
    def __init__(self, host, user, password, remote_root, local_root):
        self.host = host
        self.user = user
        self.password = password
        self.remote_root = remote_root
        self.local_root = local_root
        self.ftp = None
        self.remote_dir_cache = set()

    def connect(self):
        """Establish FTP connection with passive mode."""
        print(f"[{format_duration(0)}] Connecting to FTP host: {self.host} (User: {self.user})...")
        self.ftp = FTP(self.host, timeout=30)
        self.ftp.login(self.user, self.password)
        self.ftp.set_pasv(True)
        print(f"[{format_duration(0)}] Logged in successfully. Current remote PWD: {self.ftp.pwd()}\n")

    def ensure_connected(self):
        """Verify connection or reconnect if dropped."""
        try:
            if self.ftp is None:
                self.connect()
            else:
                self.ftp.voidcmd("NOOP")
        except Exception as e:
            print(f"\n    [WARN] Connection dropped ({e}). Reconnecting...")
            time.sleep(2)
            try:
                self.connect()
            except Exception as e2:
                print(f"    [ERR] Reconnection attempt failed ({e2}). Retrying in 3s...")
                time.sleep(3)
                self.connect()

    def ensure_remote_dir(self, remote_dir):
        """Recursively ensure remote directory exists with caching to avoid duplicate FTP commands."""
        clean_dir = remote_dir.replace("\\", "/").rstrip("/")
        if clean_dir in self.remote_dir_cache:
            return
        
        self.ensure_connected()
        parts = clean_dir.strip("/").split("/")
        current = ""
        for part in parts:
            current += "/" + part
            if current in self.remote_dir_cache:
                continue
            try:
                self.ftp.cwd(current)
                self.remote_dir_cache.add(current)
            except error_perm:
                try:
                    self.ftp.mkd(current)
                    self.ftp.cwd(current)
                    self.remote_dir_cache.add(current)
                except Exception:
                    # Might have been created concurrently or permission quirk
                    try:
                        self.ftp.cwd(current)
                        self.remote_dir_cache.add(current)
                    except Exception:
                        pass

    def get_remote_file_size(self, remote_path):
        """Retrieve size of remote file, or None if not found."""
        try:
            self.ensure_connected()
            return self.ftp.size(remote_path)
        except Exception:
            return None

    def upload_file(self, local_file, remote_file, max_retries=3):
        """Upload a local file to remote destination with retries."""
        remote_dir = os.path.dirname(remote_file).replace("\\", "/")
        filename = os.path.basename(remote_file)
        self.ensure_remote_dir(remote_dir)

        for attempt in range(1, max_retries + 1):
            try:
                self.ensure_connected()
                self.ftp.cwd(remote_dir)
                with open(local_file, "rb") as fp:
                    self.ftp.storbinary(f"STOR {filename}", fp)
                return True
            except Exception as e:
                if attempt < max_retries:
                    time.sleep(2)
                    try:
                        self.connect()
                    except Exception:
                        pass
                else:
                    print(f" [ERR: {e}]", end="")
                    return False
        return False

    def collect_files(self):
        """Traverse local root and collect all deployable files."""
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
                
                # Check protected files
                if rel_path in PROTECTED_REMOTE_FILES:
                    continue
                    
                files_to_sync.append((local_path, rel_path))

        return sorted(files_to_sync, key=lambda x: x[1])

    def run_sync(self):
        """Execute synchronization with live progress timer, transfer speed, and ETA."""
        start_time = time.time()
        print("=" * 72)
        print("  INFINITYFREE SYNCHRONIZATION WITH LIVE PROGRESS & TIMER MONITOR")
        print("=" * 72)
        print(f"Local Source:   {self.local_root}")
        print(f"Remote Target:  {self.remote_root}")
        print(f"FTP Endpoint:   {self.host} ({self.user})")
        print(f"Protected Files: {', '.join(PROTECTED_REMOTE_FILES)}")
        print("=" * 72)

        self.connect()

        # Step 1: Discover files
        print(f"[{format_duration(time.time() - start_time)}] Scanning local repository for candidate files...")
        files = self.collect_files()
        total_files = len(files)
        total_bytes = sum(os.path.getsize(f[0]) for f in files)
        print(f"[{format_duration(time.time() - start_time)}] Discovered {total_files} candidate files ({format_size(total_bytes)}).\n")

        # Step 2: Ensure remote essential upload and log directories exist
        essential_dirs = [
            f"{self.remote_root}/uploads",
            f"{self.remote_root}/uploads/announcements",
            f"{self.remote_root}/uploads/requirements",
            f"{self.remote_root}/uploads/avatars",
            f"{self.remote_root}/shared/backend/logs",
            f"{self.remote_root}/api/controllers",
            f"{self.remote_root}/stations/backend/services",
            f"{self.remote_root}/shared/backend/services",
            f"{self.remote_root}/shared/backend/config",
        ]
        for ed in essential_dirs:
            self.ensure_remote_dir(ed)

        # Step 3: Synchronization loop with real-time timer
        uploaded_count = 0
        skipped_count = 0
        failed_count = 0
        uploaded_bytes = 0
        skipped_bytes = 0

        print(f"[{format_duration(time.time() - start_time)}] Starting file transfer loop...\n")

        for idx, (local_path, rel_path) in enumerate(files, 1):
            cur_time = time.time()
            elapsed = cur_time - start_time
            timer_str = format_duration(elapsed)
            pct = (idx / total_files) * 100.0

            local_size = os.path.getsize(local_path)
            remote_path = f"{self.remote_root}/{rel_path}"
            ext = os.path.splitext(local_path)[1].lower()
            fname = os.path.basename(local_path).lower()
            is_code_file = ext in CODE_EXTENSIONS or fname == ".htaccess"

            # Check if static file with identical size can be skipped
            remote_size = self.get_remote_file_size(remote_path)
            if not is_code_file and remote_size == local_size:
                skipped_count += 1
                skipped_bytes += local_size
                print(f"[{timer_str}] [{idx:3d}/{total_files:3d}] ({pct:5.1f}%) [SKIP] {rel_path} ({format_size(local_size)} identical)")
                continue

            # Calculate current transfer speed and ETA
            speed_str = "-- KB/s"
            eta_str = "--:--"
            if uploaded_bytes > 0 and elapsed > 0:
                speed_bps = uploaded_bytes / elapsed
                speed_str = f"{speed_bps / 1024:.1f} KB/s"
                remaining_bytes = max(0, total_bytes - (uploaded_bytes + skipped_bytes))
                if speed_bps > 0:
                    eta_secs = remaining_bytes / speed_bps
                    eta_str = format_duration(eta_secs)

            print(f"[{timer_str}] [{idx:3d}/{total_files:3d}] ({pct:5.1f}%) [UPLD] {rel_path} ({format_size(local_size)}) | {speed_str} | ETA: {eta_str} ...", end="", flush=True)

            t_file_start = time.time()
            success = self.upload_file(local_path, remote_path)
            t_file_dur = time.time() - t_file_start

            if success:
                uploaded_count += 1
                uploaded_bytes += local_size
                print(f" [OK in {t_file_dur:.2f}s]")
            else:
                failed_count += 1
                print(" [FAILED]")

        total_elapsed = time.time() - start_time
        avg_speed = (uploaded_bytes / total_elapsed / 1024) if total_elapsed > 0 else 0

        print("\n" + "=" * 72)
        print("  SYNCHRONIZATION COMPLETED")
        print("=" * 72)
        print(f"Total Duration:     {format_duration(total_elapsed)} ({total_elapsed:.1f}s)")
        print(f"Files Processed:    {total_files}")
        print(f"Files Uploaded:     {uploaded_count} ({format_size(uploaded_bytes)})")
        print(f"Files Skipped:      {skipped_count} ({format_size(skipped_bytes)} static identical)")
        print(f"Files Failed:       {failed_count}")
        print(f"Average Speed:      {avg_speed:.1f} KB/s")
        print("=" * 72 + "\n")

        # Step 4: Verification of critical endpoints
        self.verify_deployment()

        # Step 5: Remote HTTP Health Check
        self.verify_remote_http()

    def verify_deployment(self):
        """Verify presence and size of critical core files on remote."""
        print("--- Verifying Remote Core Deployment Files ---")
        critical_files = [
            "index.html",
            ".htaccess",
            "api/index.php",
            "api/controllers/AuthController.php",
            "api/controllers/StudentController.php",
            "api/controllers/RegistrarAdminController.php",
            "shared/backend/config/database.php",
            "shared/backend/config/env.php",
            "shared/backend/config/db_config.php",
            "shared/backend/config/mail.php",
            "shared/backend/config/mail.local.php",
            "shared/backend/services/EmailService.php",
            "shared/backend/services/SocketSmtpTransport.php",
            "shared/backend/services/StudentPortalService.php",
            "shared/js/StationPipeline.js",
            "stations/assets/js/DataBus.js",
            "stations/backend/services/QueueHydrationService.php",
            "stations/backend/services/QueueService.php",
            "stations/backend/services/EnrollmentService.php",
            "stations/it-center/index.html",
            "stations/it-center/assets/js/app.js",
            "stations/medical-checkup/assets/js/app.js",
            "stations/payment-processing/assets/js/app.js",
            "stations/tlc-helpdesk/assets/js/app.js",
            "registrar/index.html",
            "registrar/assets/js/controllers/RegistrarController.js",
            "student-portal/index.html",
            "student-portal/assets/js/controllers/StudentPortalController.js",
            "enrollment-system/index.html",
            "enrollment-system/tracker.html",
            "admin/index.html",
        ]

        verified_count = 0
        for cf in critical_files:
            remote_p = f"{self.remote_root}/{cf}"
            remote_sz = self.get_remote_file_size(remote_p)
            local_p = os.path.join(self.local_root, cf)
            
            if cf in PROTECTED_REMOTE_FILES:
                status = "PROTECTED OK" if remote_sz is not None and remote_sz > 0 else "MISSING"
                print(f"  [{status}] {cf} (Remote: {format_size(remote_sz or 0)})")
                if remote_sz:
                    verified_count += 1
                continue

            if os.path.exists(local_p):
                local_sz = os.path.getsize(local_p)
                if remote_sz == local_sz:
                    status = "PASS"
                    verified_count += 1
                elif remote_sz is not None:
                    status = f"DIFF (L:{local_sz}, R:{remote_sz})"
                else:
                    status = "MISSING ON REMOTE"
                print(f"  [{status}] {cf} ({format_size(local_sz)})")

        print(f"\nCritical Endpoint Check: {verified_count}/{len(critical_files)} verified successfully.\n")

    def verify_remote_http(self):
        """Send live HTTP requests to InfinityFree web domain to ensure portals load cleanly."""
        print("--- Performing Live Production HTTP Health Checks ---")
        endpoints = [
            ("Home Page", "https://gncp-main.site.je/"),
            ("School Website", "https://gncp-main.site.je/school-website/"),
            ("Enrollment Form", "https://gncp-main.site.je/enrollment-system/"),
            ("Application Tracker", "https://gncp-main.site.je/enrollment-system/tracker/"),
            ("Student Portal Login", "https://gncp-main.site.je/student-portal/login/"),
            ("Registrar Station", "https://gncp-main.site.je/registrar/"),
            ("Admin Login", "https://gncp-main.site.je/admin/"),
        ]

        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

        for name, url in endpoints:
            try:
                req = urllib.request.Request(
                    url, 
                    headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"}
                )
                with urllib.request.urlopen(req, context=ctx, timeout=15) as res:
                    body = res.read()
                    print(f"  [HTTP {res.status}] {name} ({url}) - Received {len(body):,} bytes")
            except urllib.error.HTTPError as he:
                print(f"  [HTTP {he.code}] {name} ({url}) - {he.reason}")
            except Exception as e:
                print(f"  [CONN FAIL] {name} ({url}) - {e}")

if __name__ == "__main__":
    monitor = LiveSyncMonitor(FTP_HOST, FTP_USER, FTP_PASS, REMOTE_ROOT, LOCAL_ROOT)
    monitor.run_sync()
