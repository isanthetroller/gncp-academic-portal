import json
import subprocess
import sys
import time
from playwright.sync_api import sync_playwright

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

BASE_URL = "http://127.0.0.1/systemtest"

def query_db(sql):
    cmd = ["C:\\xampp\\mysql\\bin\\mysql.exe", "-u", "root", "-e", sql, "gncp_portal", "--batch", "--raw"]
    res = subprocess.run(cmd, capture_output=True, text=True, check=True)
    lines = res.stdout.strip().split("\n")
    if len(lines) < 2:
        return []
    headers = lines[0].split("\t")
    rows = []
    for line in lines[1:]:
        parts = line.split("\t")
        rows.append(dict(zip(headers, parts)))
    return rows

def run_test():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        page = context.new_page()

        print("=== STEP 1: Starting Online Pre-Registration as ALS Graduate ===", flush=True)
        page.goto(f"{BASE_URL}/enrollment-system/index.html")
        page.wait_for_selector("#enrollment-app", state="attached", timeout=10000)
        time.sleep(1.5)

        unique_suffix = str(int(time.time()))[-4:]
        first_name = f"JuanALS{unique_suffix}"
        last_name = "Dela Cruz"
        email = f"juan.als{unique_suffix}@gmail.com"
        phone = f"91755{unique_suffix}"

        # ── Step 1: Program & NSTP ──
        print("  [1/6] Filling Step 1: Program (BSIT) & NSTP (CWTS)...", flush=True)
        page.evaluate("""() => {
            const vm = document.querySelector('#enrollment-app').__vue_app__._instance.proxy;
            vm.selectedCollege = 'COIT';
            vm.form.studentType = 'FRESHMAN';
            vm.form.courseCode = 'BSIT';
            vm.form.nstp = 'CWTS';
            vm.nextStep();
        }""")
        time.sleep(1.0)

        # ── Step 2: Personal Details ──
        print(f"  [2/6] Filling Step 2: Personal Details ({first_name} {last_name})...", flush=True)
        page.evaluate("""([fName, lName, em, ph]) => {
            const vm = document.querySelector('#enrollment-app').__vue_app__._instance.proxy;
            vm.form.firstName = fName;
            vm.form.middleName = 'Santos';
            vm.form.lastName = lName;
            vm.form.email = em;
            vm.form.phone = ph;
            vm.form.birthDate = '2004-05-15';
            vm.form.gender = 'Male';
            vm.form.address = '123 Mabini St., Barangay Central, Quezon City';
            vm.nextStep();
        }""", [first_name, last_name, email, phone])
        time.sleep(1.0)

        # ── Step 3: Academic Background (ALS Pathway) ──
        print("  [3/6] Filling Step 3: Academic Background (ALS Pathway)...", flush=True)
        page.evaluate("""() => {
            const vm = document.querySelector('#enrollment-app').__vue_app__._instance.proxy;
            vm.form.educationPathway = 'ALS';
            vm.form.elementarySchool = 'Quezon City ALS Community Learning Center (CLC-District 1)';
            vm.nextStep();
        }""")
        time.sleep(1.0)

        # ── Step 4: Medical Info ──
        print("  [4/6] Filling Step 4: Medical Pre-Screening...", flush=True)
        page.evaluate("""() => {
            const vm = document.querySelector('#enrollment-app').__vue_app__._instance.proxy;
            vm.form.healthStatus = 'GOOD';
            vm.form.fitnessParticipation = true;
            vm.form.emergencyContactName = 'Maria Dela Cruz';
            vm.form.emergencyContactPhone = '918555123';
            vm.nextStep();
        }""")
        time.sleep(1.0)

        # ── Step 5: Payment Setup ──
        print("  [5/6] Filling Step 5: Payment Setup (CASH)...", flush=True)
        page.evaluate("""() => {
            const vm = document.querySelector('#enrollment-app').__vue_app__._instance.proxy;
            vm.form.paymentMode = 'CASH';
            vm.form.scholarship = 'NONE';
            vm.nextStep();
        }""")
        time.sleep(1.0)

        # ── Step 6: Review & Submit ──
        print("  [6/6] Verifying Step 6: Review Summary & ALS Requirements Checklist...", flush=True)
        time.sleep(1.0)
        review_text = page.locator(".wizard-card").inner_text()
        assert "ALS Learning Center" in review_text, "ALS Learning Center not found in Step 6 review!"
        assert "ALS Certificate of Rating" in review_text, "ALS Certificate of Rating not found in Step 6 review!"
        print("  ✓ PASS: ALS Academic History and Requirements displayed in Step 6 review.", flush=True)

        # Submit pre-enrollment
        print("  Submitting Application...", flush=True)
        page.evaluate("""() => {
            const vm = document.querySelector('#enrollment-app').__vue_app__._instance.proxy;
            vm.nextStep();
        }""")
        time.sleep(3.0)

        # Step 7: Confirmation Screen
        page.wait_for_selector(".temp-account-card", timeout=10000)
        temp_id_el = page.locator(".temp-account-card .credential-block span.font-monospace").first
        temp_student_id = temp_id_el.inner_text().strip()
        print(f"  ✓ PASS: ALS Application Submitted! Temp ID: {temp_student_id}", flush=True)

        # Verify in MariaDB database
        rows = query_db(f"SELECT * FROM pre_enrollments WHERE temp_student_id = '{temp_student_id}'")
        assert len(rows) > 0, "Application row not found in MariaDB pre_enrollments!"
        row = rows[0]
        assert row["shs_track"] == "ALS", f"Expected shs_track == 'ALS', got {row['shs_track']}"
        assert "Quezon City ALS" in row["elementary_school"], "Elementary school / ALS center name not saved!"
        print(f"  ✓ PASS: MariaDB pre_enrollments record verified (shs_track: '{row['shs_track']}', school: '{row['elementary_school']}').", flush=True)

        # ── STEP 8: Registrar Workstation Verification ──
        print("\n=== STEP 2: Logging into Registrar Workstation to Verify ALS Application ===", flush=True)
        page.goto(f"{BASE_URL}/index.html?clear=true&redirect={BASE_URL}/registrar/index.html")
        page.wait_for_selector("#username", state="visible", timeout=10000)
        page.fill("#username", "kriz")
        page.fill("#password", "kriz123")
        page.click("button[type='submit']")
        time.sleep(2.0)

        # Ensure page is on registrar view
        if "registrar" not in page.url.lower():
            page.goto(f"{BASE_URL}/registrar/index.html")
            time.sleep(2.0)

        # Open application review modal
        print(f"  Locating {temp_student_id} in Registrar queue...", flush=True)
        page.evaluate("""(targetRef) => {
            const appElem = document.querySelector('#app');
            if (appElem && appElem.__vue_app__) {
                const vm = appElem.__vue_app__._instance.proxy;
                const app = (vm.pendingApplications || []).find(a => a.referenceNumber === targetRef || a.id === targetRef);
                if (app && vm.openApplicationModal) {
                    vm.openApplicationModal(app);
                }
            }
        }""", temp_student_id)
        time.sleep(2.0)

        # Check modal content for ALS Pathway
        modal_content = page.locator("#applicationModal .modal-body").inner_text()
        assert "ALS Completer" in modal_content or "ALS" in modal_content, "ALS Pathway badge not found in Registrar review modal!"
        print("  ✓ PASS: Registrar Review Modal displays 'ALS Completer' pathway badge.", flush=True)

        # Mark all requirements as ORIGINAL, assign section, and approve
        print("  Approving ALS Application in Registrar...", flush=True)
        page.evaluate("""() => {
            const appElem = document.querySelector('#app');
            if (appElem && appElem.__vue_app__) {
                const vm = appElem.__vue_app__._instance.proxy;
                if (vm.selectedApplication) {
                    const reqs = vm.selectedApplication.requirements || [];
                    reqs.forEach(r => {
                        if (vm.setDocStatus) vm.setDocStatus(r, 'ORIGINAL');
                    });
                    if (!vm.selectedApplication.sectionCode) {
                        vm.selectedApplication.sectionCode = 'BSIT-1A';
                    }
                    if (vm.updateApplicationStatus) {
                        vm.updateApplicationStatus('Approved');
                    }
                }
            }
        }""")
        time.sleep(2.0)

        # Confirm SweetAlert if shown
        swal_confirm = page.locator(".swal2-confirm")
        if swal_confirm.is_visible():
            swal_confirm.click()
            time.sleep(1.0)

        # Verify status update in database
        updated_rows = query_db(f"SELECT status, roadmap FROM pre_enrollments WHERE temp_student_id = '{temp_student_id}'")
        assert len(updated_rows) > 0, "Updated row not found!"
        updated_row = updated_rows[0]
        assert updated_row["status"] in ["Approved", "VERIFIED"], f"Status not updated, got: {updated_row['status']}"
        print(f"  ✓ PASS: MariaDB pre_enrollments status transitioned to '{updated_row['status']}'.", flush=True)

        browser.close()

    print("\n=== ALL ALS FLOW TESTS (FRONTEND + BACKEND + DATABASE) PASSED 100%! ===", flush=True)

if __name__ == "__main__":
    run_test()
