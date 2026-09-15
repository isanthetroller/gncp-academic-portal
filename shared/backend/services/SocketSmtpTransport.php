<?php
/**
 * GNCP Email Transport — Native PHP Socket SMTP Dispatcher
 * Dual-port fallback implementation (Port 587 STARTTLS / Port 465 SSL) for high reliability.
 */

class SocketSmtpTransport {
    /**
     * Attempts dispatch on primary configured port; falls back automatically to alternate port if connection fails.
     */
    public static function sendWithFallback(array $config, string $to, string $subject, string $body): array {
        if (empty($config['username']) || empty($config['password'])) {
            return ['success' => false, 'message' => 'SMTP Username or Password missing in mail configuration.'];
        }

        $smtpResult = self::sendViaSmtpSocket($config, $to, $subject, $body);
        if ($smtpResult['success']) {
            return $smtpResult;
        }

        $altConfig = $config;
        $altConfig['port'] = ($config['port'] == 587) ? 465 : 587;
        $altResult = self::sendViaSmtpSocket($altConfig, $to, $subject, $body);
        if ($altResult['success']) {
            return $altResult;
        }

        return [
            'success' => false,
            'message' => 'Gmail SMTP dispatch failed: ' . ($altResult['message'] ?? $smtpResult['message'])
        ];
    }

    /**
     * Native PHP Socket SMTP Dispatcher (Supports Port 587 STARTTLS & Port 465 SSL)
     */
    public static function sendViaSmtpSocket(array $config, string $to, string $subject, string $body): array {
        $host = $config['host'];
        $port = intval($config['port'] ?? 587);
        $timeout = 5;

        $cleanTo = trim(str_replace(["\r", "\n"], '', $to));
        $cleanSubject = trim(str_replace(["\r", "\n"], '', $subject));

        if (!filter_var($cleanTo, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid recipient email address format.'];
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false
            ]
        ]);

        $remoteAddress = ($port === 465) ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($remoteAddress, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            // Fallback context for local dev environments lacking local CA cert bundle
            $contextFallback = stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ]);
            $socket = @stream_socket_client($remoteAddress, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $contextFallback);
            if (!$socket) {
                return ['success' => false, 'message' => "SMTP Connection Failed on port $port: $errstr ($errno)"];
            }
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

        $cleanFrom = trim(str_replace(["\r", "\n"], '', $config['from_email']));
        $mailFromRes = $sendCommand($socket, "MAIL FROM: <" . $cleanFrom . ">");
        if (strpos($mailFromRes, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'MAIL FROM rejected: ' . trim($mailFromRes)];
        }

        $rcptToRes = $sendCommand($socket, "RCPT TO: <" . $cleanTo . ">");
        if (strpos($rcptToRes, '250') === false && strpos($rcptToRes, '251') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'RCPT TO rejected (Recipient email might be invalid): ' . trim($rcptToRes)];
        }

        $dataRes = $sendCommand($socket, "DATA");
        if (strpos($dataRes, '354') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected: ' . trim($dataRes)];
        }

        $cleanFromName = trim(str_replace(["\r", "\n"], '', $config['from_name']));
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: " . $cleanFromName . " <" . $cleanFrom . ">\r\n";
        $headers .= "To: <" . $cleanTo . ">\r\n";
        $headers .= "Subject: " . $cleanSubject . "\r\n";

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
