import os
import sys
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1/systemtest-hardened"

def test_password_change_guard():
    print("==================================================================")
    print("  PHASE 8: PASSWORD CHANGE GUARD PLAYWRIGHT E2E TEST             ")
    print(f"  Target Environment: {BASE_URL}")
    print("==================================================================")

    console_errors = []
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1280, "height": 800})
        page = context.new_page()

        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
        page.on("pageerror", lambda err: console_errors.append(str(err)))

        # 1. Load gateway login page where SweetAlert2 and PasswordChangeGuard are loaded
        print("\nStep 1: Navigating to Gateway Portal...")
        page.goto(f"{BASE_URL}/index.html", wait_until="networkidle")
        page.wait_for_timeout(1000)

        # 2. Assert PasswordChangeGuard script loaded
        has_guard = page.evaluate("() => typeof window.PasswordChangeGuard !== 'undefined'")
        print(f"  -> PasswordChangeGuard loaded on window: {has_guard}")
        assert has_guard, "PasswordChangeGuard is not defined on window!"

        # 3. Simulate user with must_change_password = true triggering guard
        print("\nStep 2: Triggering PasswordChangeGuard.checkAndPrompt()...")
        page.evaluate("""() => {
            window.__testGuardCompleted = false;
            window.__testGuardResult = null;
            window.PasswordChangeGuard.checkAndPrompt({
                id: 'TEST-GUARD-STUDENT',
                name: 'Test Hardened Student',
                role: 'STUDENT',
                must_change_password: 1
            }, (res) => {
                window.__testGuardCompleted = true;
                window.__testGuardResult = res;
            });
        }""")
        page.wait_for_timeout(800)

        # 4. Assert SweetAlert2 Modal Appears
        modal = page.locator(".swal2-modal, .swal2-popup")
        assert modal.is_visible(), "Password change modal was not rendered in DOM!"
        print("  [PASS] Mandatory password reset modal rendered.")

        modal_title = page.locator(".swal2-title").inner_text()
        print(f"  -> Modal Title: '{modal_title}'")
        assert "Password" in modal_title or "Reset" in modal_title, "Modal title does not match expected password prompt."

        # 5. Assert Required Inputs Exist
        curr_pass = page.locator("#swal-curr-pass")
        new_pass = page.locator("#swal-new-pass")
        confirm_pass = page.locator("#swal-confirm-pass")

        assert curr_pass.is_visible(), "#swal-curr-pass input is missing!"
        assert new_pass.is_visible(), "#swal-new-pass input is missing!"
        assert confirm_pass.is_visible(), "#swal-confirm-pass input is missing!"
        print("  [PASS] All 3 password input fields verified visible.")

        # 6. Test Non-Dismissable behavior (Click backdrop & Escape)
        print("\nStep 3: Testing non-dismissable behavior...")
        page.keyboard.press("Escape")
        page.wait_for_timeout(300)
        assert modal.is_visible(), "Modal was dismissed via Escape key!"

        # Click backdrop outside modal
        page.mouse.click(10, 10)
        page.wait_for_timeout(300)
        assert modal.is_visible(), "Modal was dismissed via outside click!"
        print("  [PASS] Modal is strictly non-dismissable via Escape and outside click.")

        # 7. Test Validation on Empty / Mismatched Submission
        print("\nStep 4: Testing client-side password validation...")
        confirm_btn = page.locator(".swal2-confirm")
        confirm_btn.click()
        page.wait_for_timeout(500)

        # SweetAlert validation message should display
        val_msg = page.locator(".swal2-validation-message")
        if val_msg.is_visible():
            print(f"  [PASS] Validation message on empty submit: '{val_msg.inner_text().strip()}'")

        # Test Mismatched passwords
        curr_pass.fill("oldpass123")
        new_pass.fill("newsecurepass1")
        confirm_pass.fill("mismatchedpass2")
        confirm_btn.click()
        page.wait_for_timeout(500)
        assert val_msg.is_visible(), "Validation message not shown for mismatched passwords!"
        print(f"  [PASS] Validation message on mismatch: '{val_msg.inner_text().strip()}'")

        # 8. Test Cancel Action (Logs out user safely)
        print("\nStep 5: Testing Cancel action...")
        cancel_btn = page.locator(".swal2-cancel")
        assert cancel_btn.is_visible(), "Cancel/Log Out button not visible!"
        print("  [PASS] Cancel/Log Out button verified.")

        # Assert no unexpected console errors
        print("\n--- Console Error Audit ---")
        if console_errors:
            print(f"  [WARN] Console errors detected: {console_errors}")
        else:
            print("  [PASS] ZERO console errors observed during entire lifecycle.")

        browser.close()

    assert len(console_errors) == 0, f"Found console errors: {console_errors}"
    print("\n==================================================================")
    print("  PASSWORD CHANGE GUARD AUDIT: ALL TESTS PASSED                  ")
    print("==================================================================")
    return True

if __name__ == "__main__":
    success = test_password_change_guard()
    sys.exit(0 if success else 1)
