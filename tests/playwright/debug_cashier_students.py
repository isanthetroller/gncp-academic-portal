from playwright.sync_api import sync_playwright
import time

def debug_cashier_students():
    p = sync_playwright().start()
    b = p.chromium.launch(headless=True)
    page = b.new_page(viewport={"width": 1280, "height": 800})

    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'cashier')
    page.fill('#password', 'cashier123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/payment-processing/index.html', timeout=10000)
    time.sleep(2)

    data = page.evaluate('''() => {
        const busQ = StationDataBus.getQueue();
        const app = document.querySelector('#app');
        return {
            busLength: busQ.length,
            busRefs: busQ.map(s => ({ ref: s.referenceNumber, roadmap: s.roadmap ? s.roadmap.map(r => ({ id: r.stepId, st: r.status })) : null })),
            tableHtml: document.querySelector('tbody').innerHTML
        };
    }''')

    print(f"Bus Length: {data['busLength']}")
    for r in data['busRefs']:
        print(f"Ref: {r['ref']}")
        if r['roadmap']:
            for rm in r['roadmap']:
                print(f"  {rm['id']} -> {rm['st']}")
        else:
            print("  No roadmap!")

    b.close()
    p.stop()

if __name__ == '__main__':
    debug_cashier_students()
