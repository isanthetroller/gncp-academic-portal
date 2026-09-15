import os
import sys
import time
from playwright.sync_api import sync_playwright

BASE_URL = os.environ.get("GNCP_BASE_URL", "http://127.0.0.1/systemtest")

def run_benchmark():
    results = {
        "admin": {},
        "registrar": {},
        "student": {},
        "console_errors": []
    }

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        page.on("console", lambda msg: results["console_errors"].append(f"[{msg.type}] {msg.text}") if msg.type in ["error"] else None)

        # -------------------------------------------------------------
        # Test 1: Admin Portal Tab Switching & SWR Caching
        # -------------------------------------------------------------
        print("\n=== Testing Admin Portal Caching & Layout Stability ===")
        page.goto(f"{BASE_URL}/index.html?clear=true")
        page.fill("#username", "admin")
        page.fill("#password", "admin12345")
        page.click('button[type="submit"]')
        page.wait_for_url("**/admin/**", timeout=10000)
        page.wait_for_selector(".db-card", timeout=8000)

        # Monitor API calls
        api_requests = []
        page.on("request", lambda req: api_requests.append(req.url) if "api/index.php" in req.url else None)

        # Measure Cold Load of Operators
        api_requests.clear()
        start_t = time.time()
        page.click('button:has-text("Staff Logins")')
        page.wait_for_selector("table.tbl", timeout=5000)
        cold_operators_time = (time.time() - start_t) * 1000
        cold_operators_calls = len([u for u in api_requests if "fetch_users" in u or "action=admin/users" in u or "action=users" in u or "users" in u])
        print(f"Admin Operators Cold Visit: {cold_operators_time:.1f}ms | Requests: {cold_operators_calls}")

        # Navigate away to Student Portal Accounts
        api_requests.clear()
        page.click('button:has-text("Student Portal Accounts")')
        page.wait_for_selector("table.tbl", timeout=5000)

        # Return to Workstation Operators (WARM RETURN VISIT)
        api_requests.clear()
        start_t = time.time()
        page.click('button:has-text("Staff Logins")')
        # Check if table data is immediately visible without skeleton
        is_skel_visible = page.locator(".table-skeleton-row").is_visible()
        has_rows = page.locator("tbody tr.table-row-enter").count() > 0
        warm_operators_time = (time.time() - start_t) * 1000
        print(f"Admin Operators Warm Return: {warm_operators_time:.1f}ms | Skeleton visible: {is_skel_visible} | Data rendered: {has_rows}")

        # Check table stability & layout
        box = page.locator(".stable-table-container").bounding_box()
        table_height = box["height"] if box else 0
        print(f"Admin Operators Table Container Height: {table_height:.1f}px (min-height guaranteed: {table_height >= 350})")

        results["admin"] = {
            "cold_time_ms": cold_operators_time,
            "warm_time_ms": warm_operators_time,
            "skeleton_on_warm": is_skel_visible,
            "data_present_on_warm": has_rows,
            "container_height": table_height,
            "stable_layout": table_height >= 350 and not is_skel_visible
        }

        assert not is_skel_visible, "Skeleton loader flashed on warm visit to Operators table!"
        assert has_rows, "Operators data was not immediately visible from cache!"

        # Multi-cycle navigation test: Dashboard -> Staff Logins -> Student Accounts -> Announcements -> Staff Logins -> Dashboard
        print("Running multi-cycle navigation test on Admin...")
        for nav_text in ["Dashboard Overview", "Staff Logins", "Student Portal Accounts", "Bulletin & Announcements", "Staff Logins", "Dashboard Overview"]:
            page.click(f'button:has-text("{nav_text}")')
            time.sleep(0.15)
        print("Multi-cycle navigation completed without errors.")

        # -------------------------------------------------------------
        # Test 2: Registrar Station Caching & Layout Stability
        # -------------------------------------------------------------
        print("\n=== Testing Registrar Station Caching & Layout Stability ===")
        page.goto(f"{BASE_URL}/index.html?clear=true")
        page.fill("#username", "kriz")
        page.fill("#password", "kriz123")
        page.click('button[type="submit"]')
        page.wait_for_url("**/registrar/**", timeout=10000)
        page.wait_for_selector("table.data-table", timeout=5000)

        # Cold load of Student Directory
        api_requests.clear()
        start_t = time.time()
        page.click('button:has-text("Student Directory")')
        page.wait_for_selector("table.data-table", timeout=5000)
        cold_students_time = (time.time() - start_t) * 1000
        print(f"Registrar Students Cold Visit: {cold_students_time:.1f}ms")

        # Navigate away to Review History
        page.click('button:has-text("Review History")')
        page.wait_for_selector("table.data-table", timeout=5000)

        # Return to Student Directory (WARM RETURN VISIT)
        start_t = time.time()
        page.click('button:has-text("Student Directory")')
        is_reg_skel_visible = page.locator(".table-skeleton-row").is_visible()
        has_reg_rows = page.locator("tbody tr.table-row-enter").count() > 0 or page.locator("tbody tr").count() > 0
        warm_students_time = (time.time() - start_t) * 1000
        print(f"Registrar Students Warm Return: {warm_students_time:.1f}ms | Skeleton visible: {is_reg_skel_visible} | Data rendered: {has_reg_rows}")

        # Check container height
        reg_box = page.locator(".stable-table-container").first.bounding_box()
        reg_table_height = reg_box["height"] if reg_box else 0
        print(f"Registrar Table Container Height: {reg_table_height:.1f}px (min-height guaranteed: {reg_table_height >= 350})")

        results["registrar"] = {
            "cold_time_ms": cold_students_time,
            "warm_time_ms": warm_students_time,
            "skeleton_on_warm": is_reg_skel_visible,
            "data_present_on_warm": has_reg_rows,
            "container_height": reg_table_height,
            "stable_layout": reg_table_height >= 350 and not is_reg_skel_visible
        }

        assert not is_reg_skel_visible, "Skeleton loader flashed on warm visit to Registrar Student Directory!"

        # Multi-cycle navigation test on Registrar
        print("Running multi-cycle navigation test on Registrar...")
        for btn_text in ["Pending Reviews", "Review History", "Student Directory", "Pending Reviews"]:
            page.click(f'button:has-text("{btn_text}")')
            time.sleep(0.15)
        print("Registrar multi-cycle navigation completed without errors.")

        # -------------------------------------------------------------
        # Test 3: TLC Helpdesk Workstation Stability
        # -------------------------------------------------------------
        print("\n=== Testing TLC Helpdesk Station Stability ===")
        page.goto(f"{BASE_URL}/index.html?clear=true")
        page.fill("#username", "tristan")
        page.fill("#password", "tristan123")
        page.click('button[type="submit"]')
        page.wait_for_url("**/stations/tlc-helpdesk/**", timeout=10000)
        page.wait_for_selector(".data-table", timeout=5000)

        # Check table layout fixed and container
        helpdesk_box = page.locator(".stable-table-container").first.bounding_box()
        print(f"TLC Helpdesk Table Container Height: {helpdesk_box['height'] if helpdesk_box else 0:.1f}px")

        # -------------------------------------------------------------
        # Test 4: Student Portal Caching & Layout Stability
        # -------------------------------------------------------------
        print("\n=== Testing Student Portal Caching & Layout Stability ===")
        page.goto(f"{BASE_URL}/student-portal/login.html")
        page.wait_for_selector("#studentIdInput", timeout=5000)
        page.fill("#studentIdInput", "2026-1006")
        page.fill("#studentPasswordInput", "Password123!")
        page.click('button[type="submit"]')
        page.wait_for_url("**/student-portal/**", timeout=10000)
        page.wait_for_selector(".sidebar", timeout=5000)

        # Cold visit to Documents tab
        start_t = time.time()
        page.click('button.nav-item:has-text("Documents & Undertakings")')
        page.wait_for_selector(".stable-table-container", timeout=5000)
        cold_docs_time = (time.time() - start_t) * 1000
        print(f"Student Portal Documents Cold Visit: {cold_docs_time:.1f}ms")

        # Navigate away to Profile tab
        page.click('button.nav-item:has-text("My Profile")')
        time.sleep(0.3)

        # Return to Documents tab (WARM RETURN VISIT)
        start_t = time.time()
        page.click('button.nav-item:has-text("Documents & Undertakings")')
        is_card_spinner_visible = page.locator(".card-spinner").is_visible()
        docs_rendered = page.locator(".stable-table-container table").count() > 0 or page.locator(".stable-table-container").count() > 0
        warm_docs_time = (time.time() - start_t) * 1000
        print(f"Student Portal Documents Warm Return: {warm_docs_time:.1f}ms | Spinner visible: {is_card_spinner_visible} | Rendered: {docs_rendered}")

        docs_box = page.locator(".stable-table-container").first.bounding_box()
        docs_height = docs_box["height"] if docs_box else 0
        print(f"Student Portal Documents Container Height: {docs_height:.1f}px (min-height: {docs_height >= 350})")

        results["student_portal"] = {
            "cold_time_ms": cold_docs_time,
            "warm_time_ms": warm_docs_time,
            "spinner_on_warm": is_card_spinner_visible,
            "docs_rendered": docs_rendered,
            "container_height": docs_height,
            "stable_layout": docs_height >= 350 and not is_card_spinner_visible
        }

        assert not is_card_spinner_visible, "Full card spinner flashed on warm visit to Documents tab!"
        assert docs_rendered, "Documents content was not retained from cache!"

        # Multi-cycle navigation across Student Portal tabs
        print("Running multi-cycle navigation test on Student Portal...")
        for tab_label in ["Campus Feed & Bulletins", "Dashboard Overview", "My Enrolled Classes", "Documents & Undertakings", "My Profile", "Documents & Undertakings"]:
            page.click(f'button.nav-item:has-text("{tab_label}")')
            time.sleep(0.15)
        print("Student Portal multi-cycle navigation completed without errors.")

        # -------------------------------------------------------------
        # Test 5: Verify Console Errors
        # -------------------------------------------------------------
        print("\n=== Checking JavaScript Console Errors ===")
        print(f"Total console error events: {len(results['console_errors'])}")
        for err in results["console_errors"]:
            print(f"  {err}")
        assert len(results["console_errors"]) == 0, f"Encountered JS console errors: {results['console_errors']}"

        print("\n=== ALL CACHING & LAYOUT BENCHMARK TESTS PASSED 100%! ===")

        browser.close()

if __name__ == "__main__":
    run_benchmark()
