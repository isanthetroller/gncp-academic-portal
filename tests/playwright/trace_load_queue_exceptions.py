from playwright.sync_api import sync_playwright
import time

def trace_load_queue_exceptions():
    p = sync_playwright().start()
    b = p.chromium.launch(headless=True)
    page = b.new_page(viewport={"width": 1280, "height": 900})

    page.goto('http://127.0.0.1/systemtest/index.html?clear=true')
    page.fill('#username', 'cashier')
    page.fill('#password', 'cashier123')
    page.click('button[type="submit"]')
    page.wait_for_url('**/payment-processing/index.html', timeout=10000)
    time.sleep(3)

    log_trace = page.evaluate('''() => {
        const queue = StationDataBus.getQueue();
        const logs = [];
        for (let i = 0; i < queue.length; i++) {
            const student = queue[i];
            try {
                const step = student.roadmap ? student.roadmap.find(r => r.stepId === 'cashier_payment' || r.name === 'Cashier Payment' || r.id === 6) : null;
                if (!step || step.status === 'PENDING' || step.status === 'LOCKED') {
                    logs.push({ ref: student.referenceNumber, action: 'SKIPPED_STEP', step: step });
                    continue;
                }
                
                let balanceVal = (student.payment && student.payment.totalFee) || 0;
                if (student.payment && student.payment.balance != null) {
                    balanceVal = student.payment.balance;
                }

                logs.push({
                    ref: student.referenceNumber,
                    action: 'ADDED_TO_RESULT',
                    balance: balanceVal,
                    paymentObj: student.payment
                });
            } catch (err) {
                logs.push({ ref: student.referenceNumber, action: 'ERROR', error: err.message });
            }
        }
        return logs;
    }''')

    for l in log_trace:
        print(l)

    b.close()
    p.stop()

if __name__ == '__main__':
    trace_load_queue_exceptions()
