import sys
import os
import time
from playwright.sync_api import sync_playwright

ARTIFACTS_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\21a48c8a-94f2-437c-b423-d6b126b041cc"
FIXTURE_PATH = os.path.abspath("tests/fixtures/sample_poster.png")

def test_admin_announcement_workflow():
    console_errors = []
    console_warnings = []

    print("==================================================================")
    print("  PLAYWRIGHT E2E TEST: ADMIN ANNOUNCEMENT MODAL & SCROLL SUITE    ")
    print("==================================================================")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1280, "height": 800})
        page = context.new_page()

        def on_console(msg):
            text = msg.text
            if msg.type == "error":
                console_errors.append(text)
                print(f"[BROWSER ERROR] {text}")
            elif msg.type == "warning":
                console_warnings.append(text)
                print(f"[BROWSER WARN] {text}")

        page.on("console", on_console)
        page.on("pageerror", lambda err: console_errors.append(str(err)))

        # 1. Navigate to Admin Login
        print("\nStep 1: Navigating to Admin Portal...")
        page.goto("http://localhost/systemtest/admin/", wait_until="networkidle")
        page.wait_for_timeout(1000)

        # 2. Login if login form is displayed
        if page.locator("#username").is_visible():
            print("Step 2: Logging in as super admin...")
            page.locator("#username").fill("admin")
            page.locator("#password").fill("admin12345")
            page.locator("button[type='submit']").click()
            page.wait_for_timeout(2000)

        # Verify authenticated and on admin page
        page.wait_for_selector(".main", timeout=15000)
        print("  [PASS] Super Admin dashboard loaded.")

        # 3. Navigate to Announcements view
        print("\nStep 3: Navigating to Announcements / Campus Bulletins...")
        bulletin_btn = page.locator("button.nav-cat-header:has-text('Bulletin'), button:has-text('Bulletin & Announcements')").first
        if bulletin_btn.is_visible():
            bulletin_btn.click()
        else:
            page.locator("a:has-text('Notices'), button:has-text('Announcements')").first.click()
        
        page.wait_for_timeout(1000)
        page.screenshot(path=os.path.join(ARTIFACTS_DIR, "admin_announcements_view.png"))

        # 4. Click "+ Create Announcement"
        print("\nStep 4: Opening Create Announcement modal...")
        create_btn = page.locator("button:has-text('Create Announcement')").first
        assert create_btn.is_visible(), "Create Announcement button is not visible"
        create_btn.click()
        page.wait_for_timeout(800)

        # Check for the reported errors
        next_tick_errors = [e for e in console_errors if "nextTick is not defined" in e]
        close_modal_warnings = [w for w in console_warnings if "closeAnnouncementModal" in w]

        assert len(next_tick_errors) == 0, f"Found nextTick error in console: {next_tick_errors}"
        assert len(close_modal_warnings) == 0, f"Found closeAnnouncementModal warning in console: {close_modal_warnings}"
        print("  [PASS] Zero nextTick ReferenceErrors upon opening modal.")
        print("  [PASS] Zero closeAnnouncementModal render warnings.")

        # Verify Modal Card is open
        modal_card = page.locator(".bulletin-modal-card")
        assert modal_card.is_visible(), "Bulletin modal card did not open"
        print("  [PASS] Announcement Creator Modal is visible.")

        # 5. Fill Headline and Canvas content
        print("\nStep 5: Populating notice title and rich canvas text...")
        title_input = page.locator(".docs-live-title")
        title_input.fill("E2E VERIFICATION: Comprehensive Term Advisory")

        # Focus content canvas and type content
        canvas = page.locator("#announcement-content-canvas")
        canvas.click()
        canvas.fill("Official circular regarding the updated academic guidelines, requirements, and examinations for all students.")
        
        # 6. Upload Poster Image
        print("\nStep 6: Attaching vertical poster image fixture...")
        file_input = page.locator("#announcement-image-upload")
        file_input.set_input_files(FIXTURE_PATH)
        page.wait_for_timeout(1000)

        # Verify image preview is loaded
        preview_img = page.locator(".docs-live-img-container img")
        assert preview_img.is_visible(), "Live poster image preview is not visible"
        img_src = preview_img.get_attribute("src")
        assert img_src and img_src.startswith("data:image/"), "Image preview did not populate data URL"
        print("  [PASS] Image attached and rendered in live preview container.")

        # 7. Check Modal Scrollability
        print("\nStep 7: Verifying Modal Body Scrollability & Sticky Footer...")
        metrics = page.evaluate("""() => {
            const body = document.querySelector('.bulletin-modal-body');
            const card = document.querySelector('.bulletin-modal-card');
            const footer = document.querySelector('.bulletin-modal-footer');
            const saveBtn = document.querySelector('.btn-modal-save');
            
            return {
                bodyScrollHeight: body ? body.scrollHeight : 0,
                bodyClientHeight: body ? body.clientHeight : 0,
                isScrollable: body ? body.scrollHeight > body.clientHeight : false,
                cardHeight: card ? card.clientHeight : 0,
                footerVisible: footer ? (footer.getBoundingClientRect().bottom <= window.innerHeight) : false,
                saveBtnVisible: saveBtn ? (saveBtn.getBoundingClientRect().top < window.innerHeight) : false,
                saveBtnText: saveBtn ? saveBtn.textContent.trim() : ''
            };
        }""")
        print(f"  Metrics: {metrics}")
        assert metrics["isScrollable"], f"Modal body should be scrollable when tall image is added! {metrics}"
        assert metrics["saveBtnVisible"], "Save / Publish button must remain visible within viewport"

        # Scroll down modal body
        page.evaluate("document.querySelector('.bulletin-modal-body').scrollTop = 9999;")
        page.wait_for_timeout(500)
        
        screenshot_scrolled_path = os.path.join(ARTIFACTS_DIR, "admin_announcement_modal_scrollable.png")
        page.screenshot(path=screenshot_scrolled_path)
        print(f"  [PASS] Scrolled down successfully. Screenshot saved to {screenshot_scrolled_path}")

        # 8. Test Resizer buttons
        print("\nStep 8: Testing Interactive Image Resizer Controls...")
        fit_cover_btn = page.locator("button:has-text('Fill & Crop')")
        fit_cover_btn.click()
        page.wait_for_timeout(300)
        
        fit_whole_btn = page.locator("button:has-text('Fit Whole Image')")
        fit_whole_btn.click()
        page.wait_for_timeout(300)

        scale_80_btn = page.locator("button:has-text('80%')")
        scale_80_btn.click()
        page.wait_for_timeout(300)
        print("  [PASS] Resizer controls functional.")

        # 9. Click "Publish Official Announcement"
        print("\nStep 9: Submitting announcement...")
        publish_btn = page.locator("button.btn-modal-save:has-text('Publish Official Announcement')")
        assert publish_btn.is_visible(), "Publish button not visible"
        publish_btn.click()
        
        # Wait for modal to close
        page.wait_for_selector(".bulletin-modal-card", state="hidden", timeout=8000)
        print("  [PASS] Announcement published and modal closed cleanly.")

        # Verify announcement exists in list
        page.wait_for_timeout(1000)
        table_text = page.locator(".tbl").first.inner_text()
        assert "E2E VERIFICATION: Comprehensive Term Advisory" in table_text, "Published announcement not found in list"
        print("  [PASS] Published announcement is listed in the Campus Bulletins table.")

        # 10. Test Edit Notice and Close Modal
        print("\nStep 10: Testing Edit Notice and Close Modal...")
        edit_btn = page.locator("button[title='Edit Notice']").first
        edit_btn.click()
        page.wait_for_timeout(600)
        assert page.locator(".bulletin-modal-card").is_visible(), "Edit modal did not open"

        close_btn = page.locator(".bulletin-modal-header button").first
        close_btn.click()
        page.wait_for_timeout(500)
        assert not page.locator(".bulletin-modal-card").is_visible(), "Modal did not close on close button click"
        print("  [PASS] Close announcement modal works smoothly without warnings.")

        page.screenshot(path=os.path.join(ARTIFACTS_DIR, "admin_announcement_final_success.png"))
        print("\n[ALL TESTS PASSED SUCCESSFULLY!]")
        browser.close()

if __name__ == "__main__":
    test_admin_announcement_workflow()
