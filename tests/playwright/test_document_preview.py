import sys
import time
import json
import os
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)
from playwright.sync_api import sync_playwright
import config

def test_document_preview():
    print("=" * 70)
    print("🧪 TESTING REGISTRAR DOCUMENT UPLOAD PREVIEW & MODAL")
    print("=" * 70)

    test_ref = "GNCP-TEST-DOC01"

    playwright = sync_playwright().start()
    browser = playwright.chromium.launch(
        headless=True,
        args=["--no-sandbox", "--disable-dev-shm-usage", "--allow-insecure-localhost", "--ignore-certificate-errors"]
    )
    context = browser.new_context(viewport={"width": 1440, "height": 900}, ignore_https_errors=True)
    page = context.new_page()

    try:
        # Login to Registrar
        page.goto(f"{config.BASE_URL}/index.html?clear=true&redirect={config.PAGES['REGISTRAR']}", wait_until="domcontentloaded")
        page.wait_for_selector("#username", state="visible", timeout=10000)
        page.fill("#username", config.CREDENTIALS["REGISTRAR"]["username"])
        page.fill("#password", config.CREDENTIALS["REGISTRAR"]["password"])
        page.click("button[type='submit']")
        time.sleep(2.0)

        if "registrar" not in page.url.lower():
            page.goto(config.PAGES["REGISTRAR"], wait_until="domcontentloaded")
            time.sleep(2.0)

        # Search for test student
        page.evaluate("""(targetRef) => {
            const appElem = document.querySelector('#app');
            if (appElem && appElem.__vue_app__) {
                const vm = appElem.__vue_app__._instance.proxy;
                const app = (vm.pendingApplications || []).find(a => a.referenceNumber === targetRef || a.id === targetRef);
                if (app && vm.openApplicationModal) {
                    vm.openApplicationModal(app);
                }
            }
        }""", test_ref)
        time.sleep(2.0)

        os.makedirs("scratch", exist_ok=True)
        ss_modal = os.path.abspath("scratch/registrar_modal_view.png")
        page.screenshot(path=ss_modal)
        print(f"📸 Captured modal screenshot: {ss_modal}")

        # Check soft copy badges
        soft_badges = page.evaluate("""() => {
            const badges = Array.from(document.querySelectorAll('.badge.bg-success-subtle.req-badge-tag'));
            return badges.map(b => b.innerText.trim());
        }""")
        print(f"🔎 Soft copy badges found: {soft_badges}")

        # Check preview buttons
        preview_btns_count = page.evaluate("""() => {
            const btns = document.querySelectorAll("button[title='View uploaded document']");
            return btns.length;
        }""")
        print(f"🔎 Preview buttons found count: {preview_btns_count}")

        # 1. Click Preview on First Document (Image - Form 138)
        print("\n👆 Clicking Preview on Form 138 (Image)...")
        page.evaluate("""() => {
            const btns = document.querySelectorAll("button[title='View uploaded document']");
            if (btns.length > 0) btns[0].click();
        }""")
        time.sleep(1.5)

        ss_preview1 = os.path.abspath("scratch/registrar_doc_preview_image.png")
        page.screenshot(path=ss_preview1)
        print(f"📸 Captured image preview screenshot: {ss_preview1}")

        preview_img_info = page.evaluate("""() => {
            const modal = document.querySelector('.modal.fade.show.d-block');
            const title = modal ? modal.querySelector('.modal-title span') : null;
            const img = modal ? modal.querySelector('.modal-body img') : null;
            return {
                isOpen: !!modal,
                title: title ? title.innerText : null,
                imgSrc: img ? img.src : null
            };
        }""")
        print(f"📄 Image Preview Info: {preview_img_info}")

        # Close Preview Modal
        page.evaluate("""() => {
            const btn = document.querySelector('.btn-close.btn-close-white');
            if (btn) btn.click();
        }""")
        time.sleep(1.0)

        # 2. Click Preview on Second Document (PDF - PSA)
        print("\n👆 Clicking Preview on PSA Birth Certificate (PDF)...")
        page.evaluate("""() => {
            const btns = document.querySelectorAll("button[title='View uploaded document']");
            if (btns.length > 1) btns[1].click();
        }""")
        time.sleep(1.5)

        ss_preview2 = os.path.abspath("scratch/registrar_doc_preview_pdf.png")
        page.screenshot(path=ss_preview2)
        print(f"📸 Captured PDF preview screenshot: {ss_preview2}")

        preview_pdf_info = page.evaluate("""() => {
            const modal = document.querySelector('.modal.fade.show.d-block');
            const title = modal ? modal.querySelector('.modal-title span') : null;
            const iframe = modal ? modal.querySelector('.modal-body iframe') : null;
            return {
                isOpen: !!modal,
                title: title ? title.innerText : null,
                pdfSrc: iframe ? iframe.src : null
            };
        }""")
        print(f"📄 PDF Preview Info: {preview_pdf_info}")

        print("\n" + "=" * 70)
        print("🎉 DOCUMENT PREVIEW CAPABILITY AUDIT RESULTS:")
        print(f"  • Soft Copy Badges Rendered : {'PASSED' if len(soft_badges) == 2 else 'FAILED'}")
        print(f"  • Preview Trigger Buttons   : {'PASSED' if preview_btns_count == 2 else 'FAILED'}")
        print(f"  • Image Soft Copy Embedding : {'PASSED' if preview_img_info.get('imgSrc') else 'FAILED'}")
        print(f"  • PDF Soft Copy Embedding   : {'PASSED' if preview_pdf_info.get('pdfSrc') else 'FAILED'}")
        print("=" * 70)

    finally:
        browser.close()
        playwright.stop()

if __name__ == "__main__":
    test_document_preview()
