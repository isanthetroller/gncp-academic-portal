import os
import json
import time

class ReportGenerator:
    @staticmethod
    def generate_html_report(suite_results, total_duration, report_filepath):
        total_tests = len(suite_results)
        passed_tests = sum(1 for s in suite_results if s["status"] == "PASSED")
        failed_tests = sum(1 for s in suite_results if s["status"] == "FAILED")
        skipped_tests = sum(1 for s in suite_results if s["status"] == "SKIPPED")
        
        pass_rate = round((passed_tests / total_tests * 100), 1) if total_tests > 0 else 0
        status_color = "#10b981" if failed_tests == 0 else "#ef4444"
        generated_time = time.strftime("%Y-%m-%d %H:%M:%S")

        suites_html = ""
        for idx, suite in enumerate(suite_results):
            s_status = suite["status"]
            s_name = suite["name"]
            s_duration = suite.get("duration", 0)
            badge_bg = "#ecfdf5" if s_status == "PASSED" else ("#fef2f2" if s_status == "FAILED" else "#fffbeb")
            badge_fg = "#065f46" if s_status == "PASSED" else ("#991b1b" if s_status == "FAILED" else "#92400e")
            border_color = "#10b981" if s_status == "PASSED" else ("#ef4444" if s_status == "FAILED" else "#f59e0b")

            steps_html = ""
            for s_idx, step in enumerate(suite.get("steps", [])):
                step_status = step.get("status", "PASSED")
                step_name = step.get("name", f"Step {s_idx+1}")
                step_details = step.get("details", "")
                step_time = step.get("timestamp", "")
                step_ss = step.get("screenshot", "")
                step_error = step.get("error", "")

                st_badge = "#10b981" if step_status == "PASSED" else "#ef4444"
                
                ss_html = ""
                if step_ss:
                    ss_filename = os.path.basename(step_ss)
                    ss_html = f"""
                    <div style="margin-top: 10px;">
                        <a href="../screenshots/{ss_filename}" target="_blank" style="text-decoration: none;">
                            <img src="../screenshots/{ss_filename}" alt="{step_name}" style="max-width: 320px; border-radius: 6px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" />
                            <div style="font-size: 11px; color: #64748b; margin-top: 4px;">🔍 Click to enlarge screenshot ({ss_filename})</div>
                        </a>
                    </div>
                    """

                err_html = ""
                if step_error:
                    err_html = f"""
                    <div style="background: #fef2f2; border-left: 4px solid #ef4444; padding: 8px 12px; border-radius: 4px; margin-top: 8px; font-family: monospace; font-size: 12px; color: #991b1b; white-space: pre-wrap;">
                        <strong>Diagnostic Failure:</strong><br>{step_error}
                    </div>
                    """

                steps_html += f"""
                <div style="padding: 10px 14px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start;">
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: {st_badge};"></span>
                            <span style="font-weight: 600; color: #1e293b; font-size: 13px;">{step_name}</span>
                            <span style="font-size: 11px; color: #94a3b8;">{step_time}</span>
                        </div>
                        {f'<div style="font-size: 12px; color: #475569; margin-top: 4px; margin-left: 16px;">{step_details}</div>' if step_details else ''}
                        {err_html}
                        {ss_html}
                    </div>
                    <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: {'#ecfdf5' if step_status == 'PASSED' else '#fef2f2'}; color: {'#065f46' if step_status == 'PASSED' else '#991b1b'};">
                        {step_status}
                    </span>
                </div>
                """

            suites_html += f"""
            <div style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 16px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                <div style="padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid {border_color};">
                    <div>
                        <div style="font-size: 15px; font-weight: 700; color: #0f172a;">{s_name}</div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Duration: {s_duration:.2f}s | Steps: {len(suite.get('steps', []))}</div>
                    </div>
                    <span style="padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700; background: {badge_bg}; color: {badge_fg};">
                        {s_status}
                    </span>
                </div>
                <div>
                    {steps_html}
                </div>
            </div>
            """

        html_content = f"""<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playwright E2E Test Execution Report — GNCP Academic Portal</title>
    <style>
        * {{ box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }}
        body {{ background: #f1f5f9; color: #334155; padding: 24px; }}
        .container {{ max-width: 1100px; margin: 0 auto; }}
        .header {{ background: linear-gradient(135deg, #004D38 0%, #006A4E 60%, #065f46 100%); color: #ffffff; padding: 24px 28px; border-radius: 14px; margin-bottom: 20px; box-shadow: 0 10px 25px -5px rgba(0,77,56,0.3); }}
        .header h1 {{ font-size: 22px; font-weight: 800; letter-spacing: -0.5px; }}
        .header p {{ font-size: 13px; opacity: 0.9; margin-top: 4px; }}
        .metrics-grid {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }}
        .metric-card {{ background: #ffffff; border-radius: 10px; padding: 14px 18px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }}
        .metric-label {{ font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; }}
        .metric-val {{ font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px; }}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1>🎭 Playwright End-to-End Test Report</h1>
                    <p>GNCP Academic & Enrollment Management System • Real Browser Automation Suite</p>
                </div>
                <div style="text-align: right; font-size: 12px; opacity: 0.85;">
                    <div>Generated: {generated_time}</div>
                    <div>Engine: Chromium 151+</div>
                </div>
            </div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Test Suites</div>
                <div class="metric-val">{total_tests}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Passed</div>
                <div class="metric-val" style="color: #10b981;">{passed_tests}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Failed</div>
                <div class="metric-val" style="color: {'#ef4444' if failed_tests > 0 else '#64748b'};">{failed_tests}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pass Rate</div>
                <div class="metric-val" style="color: {status_color};">{pass_rate}%</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Duration</div>
                <div class="metric-val">{total_duration:.1f}s</div>
            </div>
        </div>

        <div class="suites-container">
            {suites_html}
        </div>
    </div>
</body>
</html>"""

        os.makedirs(os.path.dirname(report_filepath), exist_ok=True)
        with open(report_filepath, "w", encoding="utf-8") as f:
            f.write(html_content)

        return report_filepath
