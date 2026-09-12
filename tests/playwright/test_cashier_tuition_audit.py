import os
import sys
import time

# Ensure UTF-8 output on Windows
if sys.stdout.encoding != 'utf-8':
    sys.stdout.reconfigure(encoding='utf-8')

from playwright.sync_api import sync_playwright

BASE_URL = os.environ.get("GNCP_BASE_URL", "http://127.0.0.1/systemtest")
SCREENSHOT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "screenshots")
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

def run_test():
    console_errors = []
    page_errors = []

    print("=================================================================")
    print(" [*] STARTING CASHIER TUITION CALCULATION & BREAKDOWN PLAYWRIGHT TEST")
    print("=================================================================")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1366, "height": 900})
        page = context.new_page()

        # Capture console and page errors
        page.on("console", lambda msg: console_errors.append(f"[{msg.type}] {msg.text}") if msg.type == "error" else None)
        page.on("pageerror", lambda err: page_errors.append(str(err)))

        # 1. Login to Cashier Workstation
        print("1. Navigating to Cashier Station...")
        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        time.sleep(1)

        # Handle login modal if present
        if page.locator("input[placeholder*='Username'], input[type='text']").is_visible():
            print("   Logging in as Cashier...")
            page.fill("input[placeholder*='Username'], input[type='text']", "cashier")
            page.fill("input[type='password']", "cashier123")
            page.click("button:has-text('LOGIN OPERATOR'), button[type='submit']")
            time.sleep(2)

        # Ensure Cashier Station loaded
        assert page.locator("text=Payment Processing").or_(page.locator("text=Cashier Workstation")).or_(page.locator("text=Treasury")).first.is_visible(), "Cashier Workstation header not found"
        print("   [+] Cashier workstation loaded successfully.")

        # 2. Switch to queue view and wait for data
        print("2. Navigating to queue view...")
        time.sleep(2)
        page.evaluate("""() => {
            if (window.app) {
                window.app.setView('queue');
            }
        }""")
        time.sleep(1.5)
        page.wait_for_selector(".data-table tbody tr", timeout=10000)
        
        # Check queue rows and inspect tuition figures
        student_count = page.locator(".data-table tbody tr").count()
        print(f"2. Found {student_count} students in cashier queue table.")
        assert student_count > 0, "No students in queue"

        # Capture Queue Table Screenshot
        queue_screenshot = os.path.join(SCREENSHOT_DIR, "cashier_audit_queue_table.png")
        page.screenshot(path=queue_screenshot)
        print(f"   [+] Queue Table saved to {queue_screenshot}")

        # 3. Open Process Modal for a student
        print("3. Opening Payment Process Modal...")
        students_info = page.evaluate("""() => {
            return window.app.students.map(s => ({
                ref: s.referenceNumber,
                status: s.status,
                prog: s.program,
                hdCount: s.helpdesk?.advisedSubjects?.length || 0,
                prospectusCount: s.prospectusSubjects?.length || 0,
                calcCount: window.app.getAdvisedSubjects(s).length
            }));
        }""")
        print(f"   Students summary: {students_info[:5]}")

        # Pick student that has subjects or advising
        page.evaluate("""() => {
            if (window.app && window.app.students && window.app.students.length > 0) {
                let target = window.app.students.find(s => window.app.getAdvisedSubjects(s).length > 0) || window.app.students[0];
                window.app.openProcess(target);
            }
        }""")
        
        time.sleep(1.5)
        # Ensure modal is open
        assert page.locator(".station-modal, .modal, #paymentModal").first.is_visible(), "Payment modal did not open"
        print("   [+] Payment process modal opened successfully.")

        # 4. Verify Requirement 9: Transparent Calculation Breakdown Table
        print("4. Verifying Transparent Assessment Breakdown Table (Requirement 9)...")
        
        # Check that Assessment Breakdown Card is present
        breakdown_card = page.locator("text=Current Enrollment Assessment").first
        assert breakdown_card.is_visible(), "Current Enrollment Assessment card is missing from Cashier modal"
        print("   [+] 'Current Enrollment Assessment' card found.")

        # Check Academic Term and Semester display
        term_text = page.locator("text=Academic Term:").first
        sem_text = page.locator("text=Semester:").first
        assert term_text.is_visible(), "Academic Term label missing"
        assert sem_text.is_visible(), "Semester label missing"
        print("   [+] Academic Term & Semester headers displayed correctly.")

        # Verify subjects table inside modal
        subject_rows = page.locator(".table-assessment-subjects tbody tr")
        subj_count = subject_rows.count()
        print(f"   Found {subj_count} enrolled subjects in breakdown table.")
        assert 5 <= subj_count <= 10, f"Unexpected enrolled subjects count: {subj_count} (Expected 5-10 for single semester, NOT 14-28)"

        # Check subject columns: Code, Title, Lec, Lab, Units, Lab Fee, Tuition
        first_row_code = subject_rows.first.locator("td").first.inner_text()
        print(f"   First subject code: {first_row_code}")
        assert len(first_row_code) > 0, "First subject code is empty"

        # 5. Check Fee Itemization (Tuition, Lab, Misc, Total)
        print("5. Verifying itemized fees...")
        assessment_summary = page.locator("text=Total Current Semester Charges").locator("xpath=..").inner_text()
        print(f"   Assessment summary line: {assessment_summary}")
        
        # Extract total amount text from modal
        total_elem = page.locator("text=Total Current Semester Charges").first
        assert total_elem.is_visible(), "Total Current Semester Charges element is missing"

        # Verify modal total is realistic (e.g. ₱18,300.00, NOT ₱100k)
        modal_text = page.locator(".station-modal, #paymentModal").first.inner_text()
        assert "₱100," not in modal_text and "₱102," not in modal_text and "₱101," not in modal_text, "FATAL: ~₱100k inflated tuition detected in Cashier UI!"
        print("   [+] Defeated ₱100k bug: No ~₱100k amount found in modal.")

        # Capture Modal Breakdown Screenshot
        modal_screenshot = os.path.join(SCREENSHOT_DIR, "cashier_audit_breakdown_modal.png")
        page.screenshot(path=modal_screenshot)
        print(f"   [+] Breakdown Modal saved to {modal_screenshot}")

        # 6. Test Payment Plan Selection (Full vs Installment)
        print("6. Verifying Payment Plan Options...")
        # Check Full Payment option
        full_plan = page.locator(".plan-card:has-text('Full Settlement')").first
        if full_plan.is_visible():
            full_plan.click()
            time.sleep(0.5)
            print("   [+] Selected Full Settlement Plan.")

        # Check Downpayment option
        down_plan = page.locator(".plan-card:has-text('Downpayment')").first
        if down_plan.is_visible():
            down_plan.click()
            time.sleep(0.5)
            print("   [+] Selected Downpayment Plan.")

        # 7. Test Payment Processing (Downpayment of ₱3,000)
        print("7. Testing Payment Processing & Receipt Generation...")
        page.evaluate("""() => {
            if (window.app) {
                window.app.cashTendered = 3000;
            }
        }""")
        time.sleep(0.5)

        record_btn = page.locator("button:has-text('Record Payment')").first
        if record_btn.is_visible():
            record_btn.click()
            time.sleep(2)

            # Click Swal confirm if shown
            swal_btn = page.locator(".swal2-confirm")
            if swal_btn.is_visible():
                swal_btn.click()
                time.sleep(1)
            print("   [+] Payment recorded and confirmed.")

            # Capture Receipt Modal Screenshot
            receipt_screenshot = os.path.join(SCREENSHOT_DIR, "cashier_audit_receipt.png")
            page.screenshot(path=receipt_screenshot)
            print(f"   [+] Receipt screenshot saved to {receipt_screenshot}")

        # 8. Check Console & Page Errors
        print("8. Verifying zero JavaScript / Console Errors...")
        fatal_errors = [e for e in console_errors if "favicon" not in e]
        if fatal_errors:
            print(f"   [!] Warning - Console Errors: {fatal_errors}")
        else:
            print("   [+] ZERO console errors detected.")

        if page_errors:
            print(f"   [-] Page Errors: {page_errors}")
            raise AssertionError(f"Uncaught JavaScript exceptions: {page_errors}")
        else:
            print("   [+] ZERO uncaught JavaScript errors.")

        browser.close()

    print("\n=================================================================")
    print(" [PASS] PLAYWRIGHT CASHIER AUDIT TEST COMPLETED SUCCESSFULLY")
    print("=================================================================")

if __name__ == "__main__":
    try:
        run_test()
    except Exception as e:
        print(f"\n[-] TEST RUN FAILED: {str(e)}")
        sys.exit(1)
