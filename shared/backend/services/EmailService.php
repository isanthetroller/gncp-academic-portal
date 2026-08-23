<?php
/**
 * Email Service — Handles Gmail / SMTP email dispatch for user account creation
 * and credentials distribution with dual-port fallback (Port 587 TLS / Port 465 SSL).
 */

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
                                        <a href='http://localhost/systemtest/index.html' target='_blank' style='display: inline-block; background-color: #006A4E; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 15px; padding: 14px 28px; border-radius: 10px; border: 1px solid #004D38; box-shadow: 0 4px 10px rgba(0, 106, 78, 0.2); transition: all 0.2s;'>
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
        if (!empty($config['username']) && !empty($config['password'])) {
            // Try primary configured port (e.g. 587)
            $smtpResult = self::sendViaSmtpSocket($config, $recipientEmail, $subject, $htmlBody);
            if ($smtpResult['success']) {
                return $smtpResult;
            }

            // Fallback to alternate port (465 Direct SSL) if primary port failed
            $altConfig = $config;
            $altConfig['port'] = ($config['port'] == 587) ? 465 : 587;
            $altResult = self::sendViaSmtpSocket($altConfig, $recipientEmail, $subject, $htmlBody);
            if ($altResult['success']) {
                return $altResult;
            }

            return [
                'success' => false,
                'message' => 'Gmail SMTP dispatch failed: ' . ($altResult['message'] ?? $smtpResult['message'])
            ];
        }

        return [
            'success' => false,
            'message' => 'SMTP Username or Password missing in shared/backend/config/mail.php.'
        ];
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

        if (!empty($config['username']) && !empty($config['password'])) {
            $smtpResult = self::sendViaSmtpSocket($config, $recipientEmail, $subject, $htmlBody);
            if ($smtpResult['success']) return $smtpResult;

            $altConfig = $config;
            $altConfig['port'] = ($config['port'] == 587) ? 465 : 587;
            $altResult = self::sendViaSmtpSocket($altConfig, $recipientEmail, $subject, $htmlBody);
            if ($altResult['success']) return $altResult;

            return ['success' => false, 'message' => 'Gmail SMTP dispatch failed: ' . ($altResult['message'] ?? $smtpResult['message'])];
        }

        return ['success' => false, 'message' => 'SMTP Username or Password missing in shared/backend/config/mail.php.'];
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
                                        <a href='http://localhost/systemtest/student-portal/index.html' target='_blank' style='display: inline-block; background-color: #006A4E; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 15px; padding: 14px 28px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 106, 78, 0.2);'>
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

        if (!empty($config['username']) && !empty($config['password'])) {
            $smtpResult = self::sendViaSmtpSocket($config, $recipientEmail, $subject, $htmlBody);
            if ($smtpResult['success']) return $smtpResult;

            $altConfig = $config;
            $altConfig['port'] = ($config['port'] == 587) ? 465 : 587;
            $altResult = self::sendViaSmtpSocket($altConfig, $recipientEmail, $subject, $htmlBody);
            if ($altResult['success']) return $altResult;

            return ['success' => false, 'message' => 'Gmail SMTP dispatch failed: ' . ($altResult['message'] ?? $smtpResult['message'])];
        }

        return ['success' => false, 'message' => 'SMTP Username or Password missing in shared/backend/config/mail.php.'];
    }

    /**
     * Native PHP Socket SMTP Dispatcher for Gmail (Supports Port 587 STARTTLS & Port 465 SSL)
     */
    private static function sendViaSmtpSocket($config, $to, $subject, $body) {
        $host = $config['host'];
        $port = intval($config['port'] ?? 587);
        $timeout = 3;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $remoteAddress = ($port === 465) ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($remoteAddress, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            return ['success' => false, 'message' => "SMTP Connection Failed on port $port: $errstr ($errno)"];
        }

        stream_set_timeout($socket, $timeout);

        $getResponse = function($sock) {
            $res = '';
            while ($str = fgets($sock, 515)) {
                $res .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $res;
        };

        $sendCommand = function($sock, $cmd) use ($getResponse) {
            fputs($sock, $cmd . "\r\n");
            return $getResponse($sock);
        };

        $greeting = $getResponse($socket);
        if (empty($greeting)) {
            fclose($socket);
            return ['success' => false, 'message' => 'No response from SMTP server.'];
        }

        $sendCommand($socket, "EHLO " . gethostname());

        if ($port === 587) {
            $startTlsRes = $sendCommand($socket, "STARTTLS");
            if (strpos($startTlsRes, '220') === false) {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS rejected by SMTP server: ' . trim($startTlsRes)];
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['success' => false, 'message' => 'SMTP TLS handshake failed. Check PHP OpenSSL extension.'];
            }

            $sendCommand($socket, "EHLO " . gethostname());
        }

        $authCmdRes = $sendCommand($socket, "AUTH LOGIN");
        if (strpos($authCmdRes, '334') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'SMTP AUTH LOGIN command rejected: ' . trim($authCmdRes)];
        }

        $sendCommand($socket, base64_encode($config['username']));
        $authRes = $sendCommand($socket, base64_encode($config['password']));

        if (strpos($authRes, '235') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'SMTP Authentication failed. Invalid Gmail App Password: ' . trim($authRes)];
        }

        $mailFromRes = $sendCommand($socket, "MAIL FROM: <" . $config['from_email'] . ">");
        if (strpos($mailFromRes, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'MAIL FROM rejected: ' . trim($mailFromRes)];
        }

        $rcptToRes = $sendCommand($socket, "RCPT TO: <" . $to . ">");
        if (strpos($rcptToRes, '250') === false && strpos($rcptToRes, '251') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'RCPT TO rejected (Recipient email might be invalid): ' . trim($rcptToRes)];
        }

        $dataRes = $sendCommand($socket, "DATA");
        if (strpos($dataRes, '354') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected: ' . trim($dataRes)];
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: " . $config['from_name'] . " <" . $config['from_email'] . ">\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $subject . "\r\n";

        fputs($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $sendRes = $getResponse($socket);

        $sendCommand($socket, "QUIT");
        fclose($socket);

        if (strpos($sendRes, '250') === false) {
            return ['success' => false, 'message' => 'Message submission rejected: ' . trim($sendRes)];
        }

        return ['success' => true, 'message' => 'Email successfully delivered via Gmail SMTP.'];
    }
}
