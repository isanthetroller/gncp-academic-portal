import sys
import os
import time
import json
import random
import argparse

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

# Add directory to path
CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
if CURRENT_DIR not in sys.path:
    sys.path.insert(0, CURRENT_DIR)

import config

# Randomization Choice Pools
FIRST_NAMES = ["Alexander", "Samantha", "Marcus", "Isabella", "Gabriel", "Sophia", "Christian", "Angelica", "Dominic", "Patricia", "Joshua", "Bea", "Adrian", "Kathleen", "Nathan", "Jasmine", "Liam", "Chloe", "Julian", "Elijah"]
MIDDLE_NAMES = ["Cruz", "Santos", "Reyes", "Garcia", "Mendoza", "Ramos", "Bautista", "Aquino", "Torres", "Flores", "Navarro", "Salazar"]
LAST_NAMES = ["Dela Cruz", "Gonzales", "Villanueva", "Castillo", "Navarro", "Delos Reyes", "Mercado", "Soriano", "Salazar", "Manalo", "Santiago", "Pascual"]
PROGRAMS = ["BSIT", "BSCS", "BSCpE", "BSN", "BSBA"]
PROGRAM_NAMES = {
    "BSIT": "BS Information Technology",
    "BSCS": "BS Computer Science",
    "BSCpE": "BS Computer Engineering",
    "BSN": "BS Nursing",
    "BSBA": "BS Business Administration"
}
DEPT_MAP = {
    "BSIT": "CCS",
    "BSCS": "CCS",
    "BSCpE": "CCS",
    "BSN": "CHS",
    "BSBA": "COB"
}
NSTP_OPTIONS = ["CWTS", "ROTC", "LTS"]
GENDERS = ["Male", "Female"]
SHS_TRACKS = ["STEM", "ABM", "HUMSS", "GAS", "TVL"]
PAYMENT_MODES = ["CASH", "SEMI", "QUAD"]
HEALTH_STATUSES = ["GOOD", "FAIR"]

ADDRESSES = [
    "123 Katipunan Avenue, Barangay Loyola Heights, Quezon City",
    "456 Taft Avenue, Malate, City of Manila",
    "789 Shaw Boulevard, Mandaluyong City",
    "321 Espana Boulevard, Sampaloc, Manila",
    "654 Emilio Aguinaldo Highway, Dasmarinas City, Cavite",
    "888 Governors Drive, General Trias, Cavite"
]

ELEMENTARY_SCHOOLS = [
    "Dasmarinas Integrated Elementary School",
    "Manila Central Elementary School",
    "Cavite National Primary School",
    "St. Jude Elementary School"
]

JUNIOR_HIGH_SCHOOLS = [
    "Dasmarinas National High School",
    "Manila Science Junior High School",
    "Cavite Provincial High School",
    "St. Matthew Junior Academy"
]

SENIOR_HIGH_SCHOOLS = [
    "Governor Nautical Senior High",
    "Cavite National Senior High School",
    "Dasmarinas Science Senior High",
    "Metropolitan Science Academy"
]


class LivePreRegistrationTest:
    def __init__(self, headless=False, delay_factor=1.0, hold_seconds=5):
        self.headless = headless
        self.delay_factor = max(0.1, delay_factor)
        self.hold_seconds = hold_seconds
        self.driver = None
        self.generated_profile = {}
        self.ref_no = None
        self.temp_pin = None

    def sleep(self, seconds):
        """Paced delay scaled by delay_factor for smooth visual observation."""
        time.sleep(seconds * self.delay_factor)

    def log(self, message, prefix="[INFO]"):
        ts = time.strftime("%H:%M:%S")
        print(f"[{ts}] {prefix} {message}", flush=True)

    def init_browser(self):
        options = Options()
        if self.headless:
            options.add_argument("--headless=new")
        
        options.add_argument("--no-sandbox")
        options.add_argument("--disable-dev-shm-usage")
        options.add_argument("--window-size=1440,920")
        options.add_argument("--disable-gpu")
        options.add_argument("--allow-insecure-localhost")
        options.add_argument("--ignore-certificate-errors")
        options.add_argument("--disable-search-engine-choice-screen")
        options.add_argument("--disable-infobars")
        options.add_argument("--disable-popup-blocking")

        try:
            self.driver = webdriver.Chrome(options=options)
        except Exception as e:
            self.log(f"Chrome initialization notice: {e}. Attempting Edge fallback...", prefix="[WARN]")
            from selenium.webdriver.edge.options import Options as EdgeOptions
            edge_opts = EdgeOptions()
            if self.headless:
                edge_opts.add_argument("--headless=new")
            self.driver = webdriver.Edge(options=edge_opts)

        self.driver.set_page_load_timeout(30)
        self.driver.implicitly_wait(0)
        self.log(f"Browser window opened ({'Headless' if self.headless else 'Live Visible UI Mode'}).")

    def scroll_into_view(self, element):
        try:
            self.driver.execute_script("arguments[0].scrollIntoView({behavior: 'smooth', block: 'center'});", element)
            time.sleep(0.15)
        except Exception:
            pass

    def safe_type(self, selector, text, delay=0.015):
        """Types with visual typing effects and handles Vue virtual DOM updates smoothly."""
        try:
            elem = WebDriverWait(self.driver, 5).until(
                EC.presence_of_element_located((By.CSS_SELECTOR, selector))
            )
            self.scroll_into_view(elem)
            try:
                elem.clear()
            except Exception:
                pass
            for char in str(text):
                elem.send_keys(char)
                time.sleep(delay)
            self.driver.execute_script("""
                arguments[0].dispatchEvent(new Event('input', { bubbles: true }));
                arguments[0].dispatchEvent(new Event('change', { bubbles: true }));
            """, elem)
        except Exception:
            self.driver.execute_script("""
                const el = document.querySelector(arguments[0]);
                if (el) {
                    el.value = arguments[1];
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            """, selector, text)

    def generate_random_applicant(self):
        ts = int(time.time() * 1000)
        fn = random.choice(FIRST_NAMES)
        mn = random.choice(MIDDLE_NAMES)
        ln = f"{random.choice(LAST_NAMES)}"
        phone_9digits = f"917{random.randint(100000, 999999)}"
        em_phone_9digits = f"918{random.randint(100000, 999999)}"
        
        self.generated_profile = {
            "first_name": fn,
            "middle_name": mn,
            "last_name": ln,
            "full_name": f"{fn} {mn} {ln}",
            "email": f"{fn.lower()}.{ln.lower().replace(' ', '')}{ts % 100000}@gmail.com",
            "phone": phone_9digits,
            "birth_date": f"{random.randint(2002, 2007)}-{random.randint(1, 12):02d}-{random.randint(1, 28):02d}",
            "gender": random.choice(GENDERS),
            "address": random.choice(ADDRESSES),
            "course": random.choice(PROGRAMS),
            "nstp": random.choice(NSTP_OPTIONS),
            "elementary": random.choice(ELEMENTARY_SCHOOLS),
            "junior_high": random.choice(JUNIOR_HIGH_SCHOOLS),
            "senior_high": random.choice(SENIOR_HIGH_SCHOOLS),
            "shs_track": random.choice(SHS_TRACKS),
            "honors": random.choice(["With Honors", "Academic Excellence", "Leadership Award", "N/A"]),
            "health_status": random.choice(HEALTH_STATUSES),
            "emergency_name": f"{random.choice(FIRST_NAMES)} {ln} (Guardian)",
            "emergency_phone": em_phone_9digits,
            "payment_mode": random.choice(PAYMENT_MODES)
        }
        return self.generated_profile

    def advance_step(self, expected_current_step):
        """Advances wizard step via nextStep() and validates step progression."""
        self.sleep(0.3)
        res = self.driver.execute_script("""
            const appElem = document.querySelector('#enrollment-app');
            if (appElem && appElem.__vue_app__) {
                const vm = appElem.__vue_app__._instance.proxy;
                const prev = vm.currentStep;
                vm.nextStep();
                return { prevStep: prev, currentStep: vm.currentStep, errors: JSON.stringify(vm.errors) };
            }
            return null;
        """)
        self.log(f"Step advancement result: {res}")
        if res and res.get('currentStep') == expected_current_step:
            self.log(f"Step {expected_current_step} validation failed! Errors: {res.get('errors')}", prefix="[ERROR]")
        self.sleep(0.4)

    def run_live_test(self):
        print("\n" + "=" * 70, flush=True)
        print("   GO-ON NATIONAL COLLEGE OF THE PHILIPPINES (GNCP)", flush=True)
        print("   Live Browser Simulation — Online Pre-Registration Process", flush=True)
        print("=" * 70 + "\n", flush=True)

        p = self.generate_random_applicant()
        self.log(f"Generated Random Enrollee: {p['full_name']}")
        self.log(f"Target Program: {p['course']} ({PROGRAM_NAMES.get(p['course'], p['course'])}) | NSTP: {p['nstp']}")
        self.log(f"Contact: {p['email']} | 09{p['phone']}")
        print("-" * 70, flush=True)

        self.init_browser()

        try:
            target_url = f"{config.BASE_URL}/enrollment-system/index.html"
            self.log(f"Navigating to Enrollment Portal: {target_url}")
            self.driver.get(target_url)
            self.sleep(1.0)

            # Wait for Vue 3 instance to mount cleanly
            WebDriverWait(self.driver, 15).until(
                lambda d: d.execute_script("return !!(document.querySelector('#enrollment-app') && document.querySelector('#enrollment-app').__vue_app__)")
            )

            # Clear any leftover draft from previous manual testing
            try:
                self.driver.execute_script("localStorage.removeItem('gncp_enrollment_draft');")
            except Exception:
                pass

            # ═══════════════════════════════════════════════════════════
            # STEP 1: Program & NSTP Selection
            # ═══════════════════════════════════════════════════════════
            self.log(">>> [STEP 1/6] Selecting Program, Department & NSTP Component...")
            target_dept = DEPT_MAP.get(p["course"], "CCS")

            # Update Vue 3 state reactively using safe argument passing
            self.driver.execute_script("""
                const appElem = document.querySelector('#enrollment-app');
                if (appElem && appElem.__vue_app__) {
                    const vm = appElem.__vue_app__._instance.proxy;
                    vm.selectedCollege = arguments[0];
                    if (vm.onCollegeChange) vm.onCollegeChange();
                    vm.form.studentType = 'FRESHMAN';
                    vm.form.courseCode = arguments[1];
                    vm.form.nstp = arguments[2];
                }
            """, target_dept, p["course"], p["nstp"])
            self.sleep(0.5)

            # Highlight selected course card smoothly on screen
            try:
                cards = self.driver.find_elements(By.CSS_SELECTOR, ".option-card")
                for c in cards:
                    if p["course"] in c.text or p["nstp"] in c.text:
                        self.scroll_into_view(c)
                        self.sleep(0.2)
            except Exception:
                pass

            self.advance_step(1)

            # ═══════════════════════════════════════════════════════════
            # STEP 2: Personal Information
            # ═══════════════════════════════════════════════════════════
            self.log(">>> [STEP 2/6] Filling out Personal Demographic Information...")
            self.sleep(0.5)

            # Type fields for visual interaction
            self.safe_type("input[placeholder*='first name']", p["first_name"])
            self.safe_type("input[placeholder*='middle name']", p["middle_name"])
            self.safe_type("input[placeholder*='last name']", p["last_name"])
            self.safe_type("input[placeholder*='example@email.com']", p["email"])
            self.safe_type("input[placeholder='xxxxxxxxx']", p["phone"])
            self.safe_type("textarea.portal-textarea", p["address"])

            # Sync Vue reactive model
            self.driver.execute_script("""
                const appElem = document.querySelector('#enrollment-app');
                if (appElem && appElem.__vue_app__) {
                    const vm = appElem.__vue_app__._instance.proxy;
                    const d = JSON.parse(arguments[0]);
                    vm.form.firstName = d.first_name;
                    vm.form.middleName = d.middle_name;
                    vm.form.lastName = d.last_name;
                    vm.form.email = d.email;
                    vm.form.phone = d.phone;
                    vm.form.birthDate = d.birth_date;
                    vm.form.gender = d.gender;
                    vm.form.address = d.address;
                }
            """, json.dumps(p))
            self.sleep(0.3)

            self.advance_step(2)

            # ═══════════════════════════════════════════════════════════
            # STEP 3: Academic Background
            # ═══════════════════════════════════════════════════════════
            self.log(">>> [STEP 3/6] Entering Academic History & School Background...")
            self.sleep(0.5)

            # Type fields for visual interaction
            self.safe_type("input[placeholder*='Elementary School']", p["elementary"])
            self.safe_type("input[placeholder*='High School / JHS']", p["junior_high"])
            self.safe_type("input[placeholder*='Senior High School']", p["senior_high"])
            self.safe_type("input[placeholder*='Valedictorian']", p["honors"])

            # High School Track & Honors via Vue model
            self.driver.execute_script("""
                const appElem = document.querySelector('#enrollment-app');
                if (appElem && appElem.__vue_app__) {
                    const vm = appElem.__vue_app__._instance.proxy;
                    const d = JSON.parse(arguments[0]);
                    vm.form.educationPathway = 'REGULAR';
                    vm.form.elementarySchool = d.elementary;
                    vm.form.juniorHighSchool = d.junior_high;
                    vm.form.seniorHighSchool = d.senior_high;
                    vm.form.shsTrack = d.shs_track;
                    vm.form.honors = d.honors;
                }
            """, json.dumps(p))
            self.sleep(0.3)

            self.advance_step(3)

            # ═══════════════════════════════════════════════════════════
            # STEP 4: Medical Pre-Screening
            # ═══════════════════════════════════════════════════════════
            self.log(">>> [STEP 4/6] Completing Health Questionnaire & Emergency Contacts...")
            self.sleep(0.5)

            # Health Status Selection & Emergency Contact
            self.driver.execute_script("""
                const appElem = document.querySelector('#enrollment-app');
                if (appElem && appElem.__vue_app__) {
                    const vm = appElem.__vue_app__._instance.proxy;
                    const d = JSON.parse(arguments[0]);
                    vm.form.healthStatus = d.health_status;
                    vm.form.fitnessParticipation = true;
                    vm.form.emergencyContactName = d.emergency_name;
                    vm.form.emergencyContactPhone = d.emergency_phone;
                }
            """, json.dumps(p))
            self.sleep(0.3)

            # Type emergency fields for visual display
            self.safe_type("input[placeholder*='Guardian']", p["emergency_name"])
            self.sleep(0.2)

            self.advance_step(4)

            # ═══════════════════════════════════════════════════════════
            # STEP 5: Tuition & Payment Scheme Setup
            # ═══════════════════════════════════════════════════════════
            self.log(">>> [STEP 5/6] Selecting Payment Scheme & Reviewing Fee Assessment...")
            self.sleep(0.5)

            # Select payment plan card
            self.driver.execute_script("""
                const appElem = document.querySelector('#enrollment-app');
                if (appElem && appElem.__vue_app__) {
                    const vm = appElem.__vue_app__._instance.proxy;
                    vm.form.paymentMode = arguments[0];
                }
            """, p["payment_mode"])
            self.sleep(0.5)

            # Scroll into fee invoice
            try:
                invoice_elem = self.driver.find_element(By.CSS_SELECTOR, ".invoice-container")
                self.scroll_into_view(invoice_elem)
            except Exception:
                pass
            self.sleep(0.6)

            self.advance_step(5)

            # ═══════════════════════════════════════════════════════════
            # STEP 6: Review & Final Submission
            # ═══════════════════════════════════════════════════════════
            self.log(">>> [STEP 6/6] Reviewing Summary Details & Submitting Application...")
            self.sleep(0.8)

            # Scroll through the review cards smoothly
            try:
                summary_sections = self.driver.find_elements(By.CSS_SELECTOR, ".summary-section")
                for sec in summary_sections:
                    self.scroll_into_view(sec)
                    self.sleep(0.15)
            except Exception:
                pass

            self.sleep(0.4)

            # Trigger final submit via Vue nextStep() which executes ApiService.submitEnrollment
            self.driver.execute_script("""
                const appElem = document.querySelector('#enrollment-app');
                if (appElem && appElem.__vue_app__) {
                    const vm = appElem.__vue_app__._instance.proxy;
                    vm.nextStep();
                }
            """)
            self.log("Triggered [Submit Enrollment]! Awaiting confirmation from server...")

            # Wait and monitor registration state
            for i in range(40):
                status_info = self.driver.execute_script("""
                    const appElem = document.querySelector('#enrollment-app');
                    if (appElem && appElem.__vue_app__) {
                        const vm = appElem.__vue_app__._instance.proxy;
                        return {
                            step: vm.currentStep,
                            isSubmitting: vm.isSubmitting,
                            submitError: vm.submitError,
                            tempAccount: vm.tempAccount
                        };
                    }
                    return null;
                """)
                if status_info:
                    if status_info.get("step") == 7:
                        self.log(f"Confirmation reached! Step 7 active (Account: {status_info.get('tempAccount')}).")
                        break
                    if status_info.get("submitError"):
                        self.log(f"Backend submission error: {status_info.get('submitError')}", prefix="[ERROR]")
                        raise Exception(f"Registration failed: {status_info.get('submitError')}")
                time.sleep(0.5)

            # ═══════════════════════════════════════════════════════════
            # STEP 7: Confirmation & Verification
            # ═══════════════════════════════════════════════════════════
            WebDriverWait(self.driver, 20).until(
                EC.presence_of_element_located((By.CSS_SELECTOR, ".temp-account-card, .receipt-card"))
            )
            self.sleep(1.0)

            # Extract generated credentials from UI
            try:
                id_elem = self.driver.find_element(By.CSS_SELECTOR, ".temp-account-card span.font-monospace")
                self.ref_no = id_elem.text.strip()
            except Exception:
                pass

            try:
                # Click eye icon to reveal PIN if hidden
                eye_btn = self.driver.find_element(By.CSS_SELECTOR, ".temp-account-card button[title*='PIN']")
                eye_btn.click()
                self.sleep(0.2)
                pin_elems = self.driver.find_elements(By.CSS_SELECTOR, ".temp-account-card span.font-monospace")
                if len(pin_elems) > 1:
                    self.temp_pin = pin_elems[1].text.strip()
            except Exception:
                pass

            # Scroll down to showcase full receipt & campus roadmap
            try:
                receipt = self.driver.find_element(By.CSS_SELECTOR, ".receipt-card")
                self.scroll_into_view(receipt)
                self.sleep(0.8)

                roadmap = self.driver.find_element(By.CSS_SELECTOR, ".campus-guide")
                self.scroll_into_view(roadmap)
                self.sleep(0.8)
            except Exception:
                pass

            # Capture artifact screenshot
            ss_dir = os.path.join(CURRENT_DIR, "screenshots")
            os.makedirs(ss_dir, exist_ok=True)
            ss_path = os.path.join(ss_dir, "live_prereg_completed.png")
            self.driver.save_screenshot(ss_path)

            print("\n" + "=" * 70, flush=True)
            print("   >>> PRE-REGISTRATION COMPLETED SUCCESSFULLY! <<<", flush=True)
            print("=" * 70, flush=True)
            print(f"   Student Full Name   : {p['full_name']}", flush=True)
            print(f"   Assigned Course     : {p['course']} ({PROGRAM_NAMES.get(p['course'], p['course'])})", flush=True)
            print(f"   NSTP Component      : {p['nstp']}", flush=True)
            print(f"   Reference Number    : {self.ref_no or 'Generated'}", flush=True)
            print(f"   Temporary PIN Code  : {self.temp_pin or '****'}", flush=True)
            print(f"   Screenshot Captured : {ss_path}", flush=True)
            print("=" * 70 + "\n", flush=True)

            if not self.headless and self.hold_seconds > 0:
                self.log(f"Holding browser open for {self.hold_seconds} seconds for visual review...")
                time.sleep(self.hold_seconds)

            return True

        except Exception as e:
            self.log(f"Live Simulation Error: {e}", prefix="[ERROR]")
            import traceback
            traceback.print_exc()
            return False

        finally:
            if self.driver:
                self.driver.quit()
                self.log("Browser closed. Test finished.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="GNCP Live Browser Pre-Registration Automated Test")
    parser.add_argument("--headless", action="store_true", help="Run in headless mode without opening a visible browser window")
    parser.add_argument("--speed", type=float, default=1.0, help="Pacing speed multiplier (default: 1.0, higher = slower and easier to watch)")
    parser.add_argument("--hold", type=int, default=8, help="Seconds to hold browser open upon completion (default: 8s)")
    args = parser.parse_args()

    test = LivePreRegistrationTest(headless=args.headless, delay_factor=args.speed, hold_seconds=args.hold)
    success = test.run_live_test()
    sys.exit(0 if success else 1)
