from playwright.sync_api import sync_playwright
import time

def inspect_vue_state():
    p = sync_playwright().start()
    b = p.chromium.launch(headless=True)
    page = b.new_page(viewport={"width": 1280, "height": 900})

    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'cashier')
    page.fill('#password', 'cashier123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/payment-processing/index.html', timeout=10000)
    time.sleep(3)

    # Let's inspect Vue root
    state = page.evaluate('''() => {
        // Find vue app or trigger loadQueue
        window.dispatchEvent(new Event('storage'));
        const rows = Array.from(document.querySelectorAll('table tbody tr')).map(r => r.innerText);
        return {
            localStorageQueue: JSON.parse(localStorage.getItem('gncp_enrollment_queue') || '[]').map(s => ({ ref: s.referenceNumber, name: s.name, stat: s.status })),
            renderedRows: rows
        };
    }''')

    print("LocalStorage Queue:")
    for s in state['localStorageQueue']:
        print(f"  {s['ref']} | {s['name']} | {s['stat']}")

    print("Rendered Rows:")
    for r in state['renderedRows']:
        print("  Row:", r.replace('\n', ' -- ').encode('ascii', 'replace').decode())

    b.close()
    p.stop()

if __name__ == '__main__':
    inspect_vue_state()
