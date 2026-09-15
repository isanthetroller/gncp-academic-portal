<?php
/**
 * Email Service — Handles Gmail / SMTP email dispatch for user account creation
 * and credentials distribution with dual-port fallback (Port 587 TLS / Port 465 SSL).
 */

require_once __DIR__ . '/SocketSmtpTransport.php';

class EmailService {
    /**
     * Non-blocking email dispatch using script shutdown execution.
     * Flushes HTTP response to client immediately, then sends email in background.
     */
    public static function sendUserCredentialsAsync($recipientEmail, $recipientName, $username, $password, $role) {
        register_shutdown_function(function() use ($recipientEmail, $recipientName, $username, $password, $role) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            @self::sendUserCredentials($recipientEmail, $recipientName, $username, $password, $role);
        });
        return ['success' => true, 'message' => 'Email queued for non-blocking background delivery.'];
    }

    private static function getConfig() {
        $configFile = __DIR__ . '/../config/mail.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        // SECURITY: Never fall back to hardcoded credentials.
        // If mail.php is missing, fail loudly so the issue is caught immediately.
        if (function_exists('logAppError')) {
            logAppError('EmailService: mail.php config file is missing. Email dispatch aborted.', [
                'expected_path' => $configFile
            ]);
        }
        throw new \RuntimeException(
            'GNCP Mail configuration file not found: ' . $configFile . '. ' .
            'Create shared/backend/config/mail.php with your SMTP credentials.'
        );
    }

    /**
     * Sends a welcome email containing user credentials and forced password change notice.
     */
    public static function sendUserCredentials($recipientEmail, $recipientName, $username, $password, $role) {
        if (empty($recipientEmail)) {
            return ['success' => false, 'message' => 'Recipient email address is empty.'];
        }

        $config = self::getConfig();
        $subject = 'GNCP Station Account Created — Initial Credentials';
        
        $htmlBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>GNCP Station Account Created</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;'>
            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #f1f5f9; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
                            
                            <!-- Header Banner -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #006A4E 0%, #004D38 100%); padding: 32px 36px; text-align: left; border-bottom: 4px solid #D4AF37;'>
                                    <div style='color: #FBBF24; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px;'>
                                        Go-on National College of the Philippines
                                    </div>
                                    <div style='color: #ffffff; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;'>
                                        Official Workstation Credentials
                                    </div>
                                </td>
                            </tr>

                            <!-- Body Content -->
                            <tr>
                                <td style='padding: 36px;'>
                                    <p style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 700; line-height: 1.5;'>
                                        Hello " . htmlspecialchars($recipientName) . ",
                                    </p>
                                    <p style='margin: 0 0 24px 0; color: #334155; font-size: 15px; line-height: 1.6;'>
                                        An official administrative workstation account has been provisioned for you with the assigned role: 
                                        <span style='display: inline-block; background-color: #e6f4ed; color: #006A4E; font-weight: 700; font-size: 13px; padding: 3px 10px; border-radius: 99px; border: 1px solid #a7f3d0; margin-left: 4px;'>
                                            " . htmlspecialchars($role) . "
                                        </span>
                                    </p>

                                    <!-- Credentials Box -->
                                    <div style='background-color: #f8fafc; border: 1px solid #cbd5e1; border-left: 5px solid #006A4E; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px;'>
                                        <div style='margin-bottom: 16px;'>
                                            <div style='color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;'>
                                                Username / Station ID
                                            </div>
                                            <div style='color: #0f172a; font-family: \"Courier New\", Courier, monospace; font-size: 18px; font-weight: 800; -webkit-user-select: all; user-select: all;'>
                                                " . htmlspecialchars($username) . "
                                            </div>
                                        </div>
                                        <div>
                                            <div style='color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;'>
                                                Temporary Password (Click & Copy)
                                            </div>
                                            <div style='display: inline-block; background-color: #e6f4ed; border: 1px solid #a7f3d0; border-radius: 8px; padding: 8px 16px; margin-top: 4px;'>
                                                <code style='color: #006A4E; font-family: \"Courier New\", Courier, monospace; font-size: 20px; font-weight: 800; letter-spacing: 1px; -webkit-user-select: all; user-select: all; background: transparent;'>
                                                    " . htmlspecialchars($password) . "
                                                </code>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Direct Login Action Button -->
                                    <div style='text-align: center; margin-bottom: 24px;'>
                                        <a href='http://localhost/systemtest/' target='_blank' style='display: inline-block; background-color: #006A4E; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 15px; padding: 14px 28px; border-radius: 10px; border: 1px solid #004D38; box-shadow: 0 4px 10px rgba(0, 106, 78, 0.2); transition: all 0.2s;'>
                                            <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round' style='vertical-align: -2px; margin-right: 8px;'><rect x='3' y='11' width='18' height='11' rx='2' ry='2'></rect><path d='M7 11V7a5 5 0 0 1 10 0v4'></path></svg> Access Workstation Login Portal
                                        </a>
                                    </div>

                                    <!-- Alert Box -->
                                    <div style='background-color: #fffbe6; border: 1px solid #fde68a; border-radius: 10px; padding: 16px 20px; margin-bottom: 24px;'>
                                        <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%'>
                                            <tr>
                                                <td width='24' valign='top' style='padding-right: 12px; color: #b45309; font-size: 18px;'>
                                                    <svg width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='#b45309' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round' style='vertical-align: -3px;'><path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'></path><line x1='12' y1='9' x2='12' y2='13'></line><line x1='12' y1='17' x2='12.01' y2='17'></line></svg>
                                                </td>
                                                <td style='color: #92400e; font-size: 13px; line-height: 1.5; font-weight: 600;'>
                                                    <strong>Mandatory Password Reset:</strong> You will be required to update your temporary password immediately upon your initial system login.
                                                </td>
                                            </tr>
                                        </table>
                                    </div>

                                    <p style='margin: 0; color: #64748b; font-size: 13px; line-height: 1.5;'>
                                        If you have any questions or require assistance logging in, please contact the IT Center Helpdesk.
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 36px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                    <p style='margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                                        © " . date('Y') . " Go-on National College of the Philippines. All rights reserved.<br>
                                        This is an automated system notification. Please do not reply directly to this email.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        // Attempt Socket SMTP transmission if credentials are configured
        return SocketSmtpTransport::sendWithFallback($config, $recipientEmail, $subject, $htmlBody);
    }

    /**
     * Sends a password reset email containing a 6-digit verification code.
     */
    public static function sendPasswordResetCode($recipientEmail, $recipientName, $resetCode) {
        if (empty($recipientEmail)) {
            return ['success' => false, 'message' => 'Recipient email address is empty.'];
        }

        $config = self::getConfig();
        $subject = 'GNCP Student Portal — Password Reset Code';

        $htmlBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Password Reset Verification Code</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #f8fafc; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
                            
                            <!-- Header Banner -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #006A4E 0%, #003D2B 100%); padding: 32px 36px; text-align: left; border-bottom: 4px solid #D4AF37;'>
                                    <div style='color: #FCD34D; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px;'>
                                        Go-on National College of the Philippines
                                    </div>
                                    <div style='color: #ffffff; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;'>
                                        Student Portal Password Reset
                                    </div>
                                </td>
                            </tr>

                            <!-- Body Content -->
                            <tr>
                                <td style='padding: 36px;'>
                                    <p style='margin: 0 0 16px 0; color: #1e293b; font-size: 16px; font-weight: 700;'>
                                        Hello " . htmlspecialchars($recipientName) . ",
                                    </p>
                                    <p style='margin: 0 0 24px 0; color: #475569; font-size: 15px; line-height: 1.6;'>
                                        We received a request to reset your GNCP Student Portal account password. Use the 6-digit verification code below to authorize your password change.
                                    </p>

                                    <!-- Reset Code Box -->
                                    <div style='background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 5px solid #006A4E; border-radius: 12px; padding: 24px; text-align: center; margin-bottom: 24px;'>
                                        <div style='color: #166534; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px;'>
                                            Your Verification Code
                                        </div>
                                        <div style='display: inline-block; background-color: #ffffff; border: 2px dashed #006A4E; border-radius: 10px; padding: 12px 28px;'>
                                            <code style='color: #006A4E; font-family: \"Courier New\", Courier, monospace; font-size: 32px; font-weight: 800; letter-spacing: 6px;'>
                                                " . htmlspecialchars($resetCode) . "
                                            </code>
                                        </div>
                                        <div style='color: #64748b; font-size: 12px; margin-top: 12px; font-weight: 600;'>
                                            This code will expire in 30 minutes.
                                        </div>
                                    </div>

                                    <!-- Security Notice -->
                                    <div style='background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px;'>
                                        <p style='margin: 0; color: #991b1b; font-size: 13px; line-height: 1.5; font-weight: 600;'>
                                            <strong>Security Notice:</strong> If you did not request a password reset, please ignore this message or report it to the GNCP IT Center immediately.
                                        </p>
                                    </div>

                                    <p style='margin: 0; color: #64748b; font-size: 13px;'>
                                        Regards,<br>
                                        <strong>GNCP IT Center & Portal Administration</strong>
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 36px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                    <p style='margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                                        © " . date('Y') . " Go-on National College of the Philippines. All rights reserved.<br>
                                        Automated notification — do not reply directly to this email.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        return SocketSmtpTransport::sendWithFallback($config, $recipientEmail, $subject, $htmlBody);
    }

    /**
     * Sends an official workstation password reset notification to an employee
     * containing their generated temporary password and mandatory reset instructions.
     */
    public static function sendOperatorPasswordReset($recipientEmail, $recipientName, $username, $tempPassword, $role) {
        if (empty($recipientEmail)) {
            return ['success' => false, 'message' => 'Recipient email address is empty.'];
        }

        $config = self::getConfig();
        $subject = 'GNCP Employee Gateway — Temporary Password Reset';

        $safeRole = strtoupper(htmlspecialchars($role ?? 'OPERATOR'));
        $safeUsername = htmlspecialchars($username ?? '');
        $safeName = htmlspecialchars($recipientName ?? 'Employee');
        $safePass = htmlspecialchars($tempPassword ?? '');

        // Determine host url for portal CTA
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $loginUrl = $protocol . $host . '/systemtest/';

        $htmlBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>GNCP Workstation Password Reset</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;'>
            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #f1f5f9; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
                            
                            <!-- Header Banner -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #006A4E 0%, #004D38 100%); padding: 32px 36px; text-align: left; border-bottom: 4px solid #D4AF37;'>
                                    <div style='color: #FBBF24; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px;'>
                                        Go-on National College of the Philippines
                                    </div>
                                    <div style='color: #ffffff; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;'>
                                        Workstation Password Reset
                                    </div>
                                </td>
                            </tr>

                            <!-- Body Content -->
                            <tr>
                                <td style='padding: 36px;'>
                                    <p style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 700; line-height: 1.5;'>
                                        Hello {$safeName},
                                    </p>
                                    <p style='margin: 0 0 24px 0; color: #334155; font-size: 15px; line-height: 1.6;'>
                                        A password reset was processed for your GNCP administrative workstation account. You have been assigned the following temporary credentials to restore access:
                                    </p>

                                    <!-- Credentials Box -->
                                    <div style='background-color: #f8fafc; border: 1px solid #cbd5e1; border-left: 5px solid #006A4E; border-radius: 12px; padding: 22px 24px; margin-bottom: 24px;'>
                                        <div style='margin-bottom: 14px;'>
                                            <div style='color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;'>
                                                Station Role
                                            </div>
                                            <span style='display: inline-block; background-color: #e6f4ed; color: #006A4E; font-weight: 700; font-size: 13px; padding: 3px 10px; border-radius: 99px; border: 1px solid #a7f3d0;'>
                                                {$safeRole}
                                            </span>
                                        </div>

                                        <div style='margin-bottom: 16px;'>
                                            <div style='color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;'>
                                                Operator Username
                                            </div>
                                            <div style='color: #0f172a; font-family: \"Courier New\", Courier, monospace; font-size: 17px; font-weight: 800;'>
                                                {$safeUsername}
                                            </div>
                                        </div>

                                        <div>
                                            <div style='color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;'>
                                                Temporary Password
                                            </div>
                                            <div style='display: inline-block; background-color: #ffffff; border: 2px dashed #006A4E; border-radius: 8px; padding: 10px 20px;'>
                                                <code style='color: #006A4E; font-family: \"Courier New\", Courier, monospace; font-size: 20px; font-weight: 800; letter-spacing: 2px;'>
                                                    {$safePass}
                                                </code>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Mandatory Password Change Advisory -->
                                    <div style='background-color: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px;'>
                                        <p style='margin: 0; color: #92400e; font-size: 13px; line-height: 1.5;'>
                                            <strong>Mandatory Security Action:</strong> For institutional security, you will be prompted to replace this temporary password with a new personal password immediately upon your next login.
                                        </p>
                                    </div>

                                    <!-- Sign-in CTA Button -->
                                    <div style='text-align: center; margin-bottom: 28px;'>
                                        <a href='{$loginUrl}' target='_blank' style='display: inline-block; background-color: #006A4E; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 15px; padding: 14px 28px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 106, 78, 0.2);'>
                                            Sign In to Employee Portal
                                        </a>
                                    </div>

                                    <!-- Security Notice -->
                                    <div style='background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 12px 16px; margin-bottom: 24px;'>
                                        <p style='margin: 0; color: #991b1b; font-size: 12px; line-height: 1.5;'>
                                            <strong>Security Notice:</strong> If you did not request this password reset or suspect unauthorized activity, please alert the IT Center immediately.
                                        </p>
                                    </div>

                                    <p style='margin: 0; color: #64748b; font-size: 13px; line-height: 1.6;'>
                                        Sincerely,<br>
                                        <strong>GNCP IT Center & Portal Administration</strong>
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 36px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                    <p style='margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                                        © " . date('Y') . " Go-on National College of the Philippines. All rights reserved.<br>
                                        Automated notification — please do not reply directly to this email.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        return SocketSmtpTransport::sendWithFallback($config, $recipientEmail, $subject, $htmlBody);
    }

    /**
     * Sends an Undertaking / Document Compliance Reminder email to student
     */
    public static function sendUndertakingReminder($recipientEmail, $recipientName, $studentId, array $missingOrUndertakingDocs) {
        if (empty($recipientEmail)) {
            return ['success' => false, 'message' => 'Recipient email address is empty.'];
        }

        $config = self::getConfig();
        $subject = 'GNCP Admission Notice — Pending Document Requirements & Undertakings';

        $docsListHtml = '';
        foreach ($missingOrUndertakingDocs as $doc) {
            $title = htmlspecialchars($doc['title'] ?? 'Document Requirement');
            $status = htmlspecialchars($doc['status'] ?? 'NOT_SUBMITTED');
            $reason = !empty($doc['undertakingReason']) ? ('<br><small style="color: #d97706;">Waiver Reason: ' . htmlspecialchars($doc['undertakingReason']) . '</small>') : '';
            $deadline = !empty($doc['undertakingDeadline']) ? ('<br><small style="color: #dc2626; font-weight: bold;">Commitment Deadline: ' . htmlspecialchars($doc['undertakingDeadline']) . '</small>') : '';

            $docsListHtml .= "
                <li style='margin-bottom: 12px; padding: 10px 14px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #f59e0b;'>
                    <strong style='color: #0f172a;'>{$title}</strong> 
                    <span style='font-size: 11px; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 99px; margin-left: 6px; font-weight: 700;'>{$status}</span>
                    {$reason}
                    {$deadline}
                </li>
            ";
        }

        $htmlBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>GNCP Document Requirement Notice</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #f8fafc; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
                            
                            <!-- Header Banner -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #006A4E 0%, #003D2B 100%); padding: 32px 36px; text-align: left; border-bottom: 4px solid #D4AF37;'>
                                    <div style='color: #FCD34D; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px;'>
                                        Go-on National College of the Philippines
                                    </div>
                                    <div style='color: #ffffff; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;'>
                                        Academic Credentials Notice
                                    </div>
                                </td>
                            </tr>

                            <!-- Body Content -->
                            <tr>
                                <td style='padding: 36px;'>
                                    <p style='margin: 0 0 16px 0; color: #1e293b; font-size: 16px; font-weight: 700;'>
                                        Hello " . htmlspecialchars($recipientName) . " (" . htmlspecialchars($studentId) . "),
                                    </p>
                                    <p style='margin: 0 0 20px 0; color: #475569; font-size: 15px; line-height: 1.6;'>
                                        This is an official advisory from the <strong>Office of the College Registrar</strong> regarding your pending admission requirements and conditional undertaking commitments:
                                    </p>

                                    <!-- Documents List -->
                                    <ul style='list-style-type: none; padding-left: 0; margin-bottom: 24px;'>
                                        {$docsListHtml}
                                    </ul>

                                    <div style='background-color: #fefce8; border: 1px solid #fef08a; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px;'>
                                        <p style='margin: 0; color: #854d0e; font-size: 13px; line-height: 1.5;'>
                                            <strong>Action Required:</strong> You can upload clear digital scans of these credentials directly inside your <strong>GNCP Student Portal</strong> under the <em>Documents & Undertakings</em> tab.
                                        </p>
                                    </div>

                                    <!-- Portal CTA -->
                                    <div style='text-align: center; margin-bottom: 24px;'>
                                        <a href='http://localhost/systemtest/student-portal/login' target='_blank' style='display: inline-block; background-color: #006A4E; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 15px; padding: 14px 28px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 106, 78, 0.2);'>
                                            Upload Documents to Portal
                                        </a>
                                    </div>

                                    <p style='margin: 0; color: #64748b; font-size: 13px;'>
                                        Office of the College Registrar<br>
                                        <strong>Go-on National College of the Philippines</strong>
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 36px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                    <p style='margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                                        © " . date('Y') . " Go-on National College of the Philippines. All rights reserved.<br>
                                        Automated notification — do not reply directly to this email.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        return SocketSmtpTransport::sendWithFallback($config, $recipientEmail, $subject, $htmlBody);
    }

    /**
     * Backward-compatible delegation to SocketSmtpTransport
     */
    public static function sendViaSmtpSocket($config, $to, $subject, $body) {
        return SocketSmtpTransport::sendViaSmtpSocket($config, $to, $subject, $body);
    }
}
