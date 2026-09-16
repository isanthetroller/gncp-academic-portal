from playwright.sync_api import sync_playwright
import time
import json

def check_queue_api():
    p = sync_playwright().start()
    b = p.chromium.launch(headless=True)
    page = b.new_page(viewport={"width": 1280, "height": 800})

    # Log in
    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'cashier')
    page.fill('#password', 'cashier123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/payment-processing/index.html', timeout=10000)
    time.sleep(2)

    # Fetch queue API directly in page context
    data = page.evaluate('''async () => {
        const res = await fetch('../../api/index.php?action=stations/queue', { credentials: 'same-origin' });
        return await res.json();
    }''')

    print(f"API success: {data.get('success')}")
    students = data.get('data', [])
    print(f"Total students returned from API: {len(students)}")
    for s in students:
        ref = s.get('referenceNumber')
        name = s.get('name')
        stat = s.get('status')
        print(f"Student: {ref} | {name} | {stat}")

    b.close()
    p.stop()

if __name__ == '__main__':
    check_queue_api()
