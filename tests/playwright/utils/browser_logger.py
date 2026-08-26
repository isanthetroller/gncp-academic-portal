import time
import json

class BrowserLogger:
    def __init__(self, page, test_name=""):
        self.page = page
        self.test_name = test_name
        self.console_logs = []
        self.js_errors = []
        self.failed_requests = []
        self.api_responses = []
        self.all_responses = []
        self.attach_listeners()

    def attach_listeners(self):
        # 1. Capture Console Logs
        def on_console(msg):
            entry = {
                "timestamp": time.strftime("%H:%M:%S"),
                "type": msg.type,
                "text": msg.text,
                "location": msg.location
            }
            self.console_logs.append(entry)
            if msg.type == "error":
                # Critical JS errors
                text = msg.text
                if any(crit in text for crit in ["Uncaught", "TypeError", "ReferenceError", "SyntaxError", "Vue warn"]):
                    self.js_errors.append(entry)

        # 2. Capture Uncaught Page Exceptions
        def on_page_error(exc):
            err_entry = {
                "timestamp": time.strftime("%H:%M:%S"),
                "error": str(exc),
                "url": self.page.url if self.page else "unknown"
            }
            self.js_errors.append(err_entry)

        # 3. Capture Failed Network Requests (e.g. DNS, connection refused, CORS)
        def on_request_failed(req):
            entry = {
                "timestamp": time.strftime("%H:%M:%S"),
                "url": req.url,
                "method": req.method,
                "failure": req.failure,
                "resource_type": req.resource_type
            }
            self.failed_requests.append(entry)

        # 4. Capture Responses (monitor unexpected 4xx, 5xx)
        def on_response(res):
            status = res.status
            url = res.url
            entry = {
                "timestamp": time.strftime("%H:%M:%S"),
                "url": url,
                "status": status,
                "ok": res.ok,
                "content_type": res.headers.get("content-type", "")
            }
            self.all_responses.append(entry)
            if "api/index.php" in url or "backend" in url:
                self.api_responses.append(entry)

        self.page.on("console", on_console)
        self.page.on("pageerror", on_page_error)
        self.page.on("requestfailed", on_request_failed)
        self.page.on("response", on_response)

    def has_critical_errors(self):
        return len(self.js_errors) > 0

    def get_critical_errors(self):
        return self.js_errors

    def get_failed_network_requests(self):
        return self.failed_requests

    def get_server_5xx_errors(self):
        return [r for r in self.all_responses if r["status"] >= 500]

    def clear(self):
        self.console_logs.clear()
        self.js_errors.clear()
        self.failed_requests.clear()
        self.api_responses.clear()
        self.all_responses.clear()
