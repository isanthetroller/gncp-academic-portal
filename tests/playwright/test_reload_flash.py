from playwright.sync_api import sync_playwright
import time

def test_reload():
    p = sync_playwright().start()
    b = p.chromium.launch(headless=True)
    page = b.new_page()

    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'kriz')
    page.fill('#password', 'kriz123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/registrar/index.html', timeout=10000)
    time.sleep(2)

    print('Reloading Registrar page...')
    page.reload()
    time.sleep(1)
    
    # Check if session expired card is visible
    expired_box = page.locator('text=Session Expired or Unauthorized')
    is_visible = expired_box.is_visible()
    print('Is Session Expired visible on reload?:', is_visible)
    assert not is_visible, "Session Expired card should not be visible on page reload!"
    print("SUCCESS: No session expired flash on reload.")

    b.close()
    p.stop()

if __name__ == '__main__':
    test_reload()
