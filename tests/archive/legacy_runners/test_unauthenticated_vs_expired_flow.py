import os
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8000/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def test_unauthenticated_and_expired():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        
        # 1. Fresh Browser Context (Zero cookies, Zero storage = Unauthenticated User)
        fresh_context = b.new_context(viewport={"width": 1280, "height": 900})
        page1 = fresh_context.new_page()

        print("Navigating unauthenticated browser directly to Cashier station...")
        page1.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        page1.wait_for_url("**/index.html**")
        time.sleep(1)

        current_url = page1.url
        print(f"Current URL after redirect: {current_url}")
        assert "session_expired" not in current_url, f"Unexpected session_expired in URL: {current_url}"

        # Verify that NO session expired banner is visible on the login portal
        banner_count = page1.locator(".session-expired-banner").count()
        print(f"Session Expired Banner count on clean visit: {banner_count}")
        assert banner_count == 0, "Banner should NOT be visible on a fresh unauthenticated visit!"

        path_fresh_login = os.path.join(ARTIFACT_DIR, "unauthenticated_clean_login_redirect.png")
        page1.screenshot(path=path_fresh_login)
        print(f"Captured Clean Unauthenticated Login Screen: {path_fresh_login}")
        fresh_context.close()

        # 2. Expired Context (Simulating a user that had a prior session)
        expired_context = b.new_context(viewport={"width": 1280, "height": 900})
        page2 = expired_context.new_page()

        # Initialize session storage before hitting workstation to simulate previously active session
        page2.goto(f"{BASE_URL}/index.html")
        page2.evaluate("""() => {
            sessionStorage.setItem('gncp_station_user', JSON.stringify({
                username: 'cashier_operator',
                role: 'CASHIER',
                name: 'Cashier Staff'
            }));
        }""")

        print("Navigating expired session browser to Cashier station...")
        page2.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        time.sleep(1)

        # Workstation checkSession() sees 401 from backend, recognizes prior session, and triggers modal
        path_expired_modal = os.path.join(ARTIFACT_DIR, "expired_session_legitimate_warning.png")
        page2.screenshot(path=path_expired_modal)
        # 3. Pasting an expired URL into a fresh browser (e.g. copied from Chrome to Edge)
        pasted_context = b.new_context(viewport={"width": 1280, "height": 900})
        page3 = pasted_context.new_page()

        print("Testing pasted session_expired URL in a fresh browser context...")
        page3.goto(f"{BASE_URL}/index.html?session_expired=1&reason=expired&redirect=%2Fsystemtest%2Fstations%2Fpayment-processing%2Findex.html")
        time.sleep(1)

        banner_count_pasted = page3.locator(".session-expired-banner").count()
        print(f"Session Expired Banner count on pasted URL in fresh browser: {banner_count_pasted}")
        assert banner_count_pasted == 0, "Banner should NOT be shown in a browser that was never logged in!"

        path_pasted_clean = os.path.join(ARTIFACT_DIR, "pasted_url_clean_screen.png")
        page3.screenshot(path=path_pasted_clean)
        print(f"Captured Pasted Clean Screen: {path_pasted_clean}")

        pasted_context.close()
        b.close()

if __name__ == "__main__":
    test_unauthenticated_and_expired()
