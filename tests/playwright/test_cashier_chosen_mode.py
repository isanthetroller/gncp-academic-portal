import os
import time
import json
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8000/systemtest"
ARTIFACT_DIR = r"C:\Users\ethan\.gemini\antigravity-ide\brain\b79481f9-b325-483e-b1ca-bcfa90153a88\.tempmediaStorage"

def capture_ui():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        page = b.new_page(viewport={"width": 1280, "height": 900})

        page.goto(f"{BASE_URL}/stations/payment-processing/index.html")
        page.fill("input[type='text'], input[placeholder*='username']", "cashier")
        page.fill("input[type='password']", "cashier123")
        page.click("button:has-text('LOGIN OPERATOR'), button[type='submit']")
        time.sleep(2)

        # Setup test students with different chosen payment schemes
        page.evaluate("""() => {
            if (window.app) {
                window.app.currentUser = { username: 'cashier', name: 'Finance Cashier Officer', role: 'CASHIER', status: 'ACTIVE' };
                window.app.students = [
                    {
                        id: 101,
                        referenceNumber: 'REF-2026-1001',
                        queueTicket: 'CSH-001',
                        studentName: 'Gabriel Flores Dela Cruz',
                        program: 'BSCS',
                        studentType: 'FRESHMAN',
                        paymentMode: 'SEMI',
                        status: 'MEDICAL_CLEARED',
                        payment: { totalFee: 18300, amountPaid: 0, balance: 18300, paymentType: 'Cash', history: [] },
                        roadmap: [
                            { stepId: 'online_prereg', title: 'Online Pre-Registration', status: 'COMPLETED' },
                            { stepId: 'registrar_verification', title: 'Registrar Verification', status: 'COMPLETED' },
                            { stepId: 'advising_assessment', title: 'Academic Advising', status: 'COMPLETED' },
                            { stepId: 'clinic_checkup', title: 'Medical Examination', status: 'COMPLETED' },
                            { stepId: 'cashier_payment', title: 'Treasury / Cashier', status: 'IN_PROGRESS' },
                            { stepId: 'it_activation', title: 'IT Center ID Provisioning', status: 'PENDING' }
                        ]
                    },
                    {
                        id: 102,
                        referenceNumber: 'REF-2026-1002',
                        queueTicket: 'CSH-002',
                        studentName: 'Alyssa Marie Reyes',
                        program: 'BSIT',
                        studentType: 'FRESHMAN',
                        paymentMode: 'CASH',
                        status: 'MEDICAL_CLEARED',
                        payment: { totalFee: 24000, amountPaid: 0, balance: 24000, paymentType: 'GCash', history: [] },
                        roadmap: [
                            { stepId: 'online_prereg', title: 'Online Pre-Registration', status: 'COMPLETED' },
                            { stepId: 'registrar_verification', title: 'Registrar Verification', status: 'COMPLETED' },
                            { stepId: 'advising_assessment', title: 'Academic Advising', status: 'COMPLETED' },
                            { stepId: 'clinic_checkup', title: 'Medical Examination', status: 'COMPLETED' },
                            { stepId: 'cashier_payment', title: 'Treasury / Cashier', status: 'IN_PROGRESS' },
                            { stepId: 'it_activation', title: 'IT Center ID Provisioning', status: 'PENDING' }
                        ]
                    },
                    {
                        id: 103,
                        referenceNumber: 'REF-2026-1003',
                        queueTicket: 'CSH-003',
                        studentName: 'Marcus Joaquin Santos',
                        program: 'BSEMC',
                        studentType: 'TRANSFEREE',
                        paymentMode: 'QUAD',
                        status: 'MEDICAL_CLEARED',
                        payment: { totalFee: 21500, amountPaid: 0, balance: 21500, paymentType: 'PayMongo', history: [] },
                        roadmap: [
                            { stepId: 'online_prereg', title: 'Online Pre-Registration', status: 'COMPLETED' },
                            { stepId: 'registrar_verification', title: 'Registrar Verification', status: 'COMPLETED' },
                            { stepId: 'advising_assessment', title: 'Academic Advising', status: 'COMPLETED' },
                            { stepId: 'clinic_checkup', title: 'Medical Examination', status: 'COMPLETED' },
                            { stepId: 'cashier_payment', title: 'Treasury / Cashier', status: 'IN_PROGRESS' },
                            { stepId: 'it_activation', title: 'IT Center ID Provisioning', status: 'PENDING' }
                        ]
                    }
                ];
            }
        }""")
        time.sleep(1)

        # Switch to Queue View
        page.evaluate("""() => {
            if (window.__cashierScope) {
                window.app.currentView = 'queue';
            }
        }""")
        time.sleep(1)

        # Screenshot 1: Queue Table showing "Chosen Plan / Mode" column
        path_table = os.path.join(ARTIFACT_DIR, "cashier_table_reflecting_chosen_plans.png")
        page.screenshot(path=path_table)
        print(f"Captured: {path_table}")

        # Open Modal for Student with SEMI Installment
        page.evaluate("""() => {
            if (window.__cashierScope && window.__cashierScope.students.value.length > 0) {
                let target = window.__cashierScope.students.value[0];
                target.paymentMode = 'SEMI';
                target.payment.balance = 18300;
                target.payment.amountPaid = 0;
                target.status = 'MEDICAL_CLEARED';
                window.__cashierScope.openProcess(target);
            }
        }""")
        time.sleep(1.5)

        # Screenshot 2: Modal reflecting SEMI installment with Downpayment pre-selected
        path_semi = os.path.join(ARTIFACT_DIR, "cashier_modal_reflecting_semi_installment.png")
        page.screenshot(path=path_semi)
        print(f"Captured: {path_semi}")

        # Close and Open Modal with CASH Full Payment
        page.evaluate("""() => {
            if (window.__cashierScope && window.__cashierScope.students.value.length > 0) {
                const modalEl = document.getElementById('paymentModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                setTimeout(() => {
                    let target = window.__cashierScope.students.value[0];
                    target.paymentMode = 'CASH';
                    target.payment.balance = 18300;
                    target.payment.amountPaid = 0;
                    target.status = 'MEDICAL_CLEARED';
                    window.__cashierScope.openProcess(target);
                }, 300);
            }
        }""")
        time.sleep(1.5)

        # Screenshot 3: Modal reflecting CASH full payment
        path_cash = os.path.join(ARTIFACT_DIR, "cashier_modal_reflecting_cash_full.png")
        page.screenshot(path=path_cash)
        print(f"Captured: {path_cash}")

        b.close()

if __name__ == "__main__":
    capture_ui()
