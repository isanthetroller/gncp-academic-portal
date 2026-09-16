import os
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8000/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def test_session_expiration():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        context = b.new_context(viewport={"width": 1280, "height": 900})
        page = context.new_page()

        # 1. Test Login Portal with session_expired query parameter
        page.goto(f"{BASE_URL}/index.html?session_expired=1&reason=expired")
        time.sleep(1.5)

        path_login_banner = os.path.join(ARTIFACT_DIR, "login_session_expired_banner.png")
        page.screenshot(path=path_login_banner)
        print(f"Captured Login Portal Session Expired Banner: {path_login_banner}")

        # 2. Test Workstation Session Expiration Warning Popup
        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        time.sleep(1.5)

        # Trigger SessionExpirationGuard directly on workstation
        page.evaluate("""() => {
            if (window.SessionExpirationGuard) {
                window.SessionExpirationGuard.handleExpiredSession({
                    title: 'Session Expired',
                    message: 'Your cashier workstation session has expired. Please sign in again to continue managing payments.',
                    timer: 15000
                });
            }
        }""")
        time.sleep(1)

        path_workstation_modal = os.path.join(ARTIFACT_DIR, "workstation_session_expired_popup.png")
        page.screenshot(path=path_workstation_modal)
        print(f"Captured Workstation Session Expired Popup: {path_workstation_modal}")

        b.close()

if __name__ == "__main__":
    test_session_expiration()
