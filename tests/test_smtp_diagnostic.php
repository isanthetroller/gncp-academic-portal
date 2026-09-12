<?php
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

echo "=== GNCP SMTP DIAGNOSTIC SUITE ===\n";

$cfg = require __DIR__ . '/../shared/backend/config/mail.php';

echo "Driver:     " . ($cfg['driver'] ?? 'N/A') . "\n";
echo "Host:       " . ($cfg['host'] ?? 'N/A') . "\n";
echo "Port:       " . ($cfg['port'] ?? 'N/A') . "\n";
echo "Encryption: " . ($cfg['encryption'] ?? 'N/A') . "\n";
echo "Username:   " . (!empty($cfg['username']) ? $cfg['username'] : '(EMPTY)') . "\n";
echo "Password:   " . (!empty($cfg['password']) ? '***SET (' . strlen($cfg['password']) . ' chars)***' : '(EMPTY)') . "\n";
echo "From Email: " . ($cfg['from_email'] ?? 'N/A') . "\n\n";

echo "--- Network & Socket Connectivity Test ---\n";
// Test Port 587 (TLS)
$timeout = 5;
echo "1. Connecting to smtp.gmail.com:587 (STARTTLS)... ";
$sock587 = @stream_socket_client("tcp://smtp.gmail.com:587", $errno, $errstr, $timeout);
if ($sock587) {
    $banner = fgets($sock587, 512);
    fclose($sock587);
    echo "[OK]\n   Banner: " . trim($banner) . "\n";
} else {
    echo "[FAILED] $errstr ($errno)\n";
}

// Test Port 465 (SSL)
echo "2. Connecting to smtp.gmail.com:465 (Direct SSL)... ";
$ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$sock465 = @stream_socket_client("ssl://smtp.gmail.com:465", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
if ($sock465) {
    $banner = fgets($sock465, 512);
    fclose($sock465);
    echo "[OK]\n   Banner: " . trim($banner) . "\n";
} else {
    echo "[FAILED] $errstr ($errno)\n";
}

echo "\n--- Live SMTP Dispatch to goontech1@gmail.com ---\n";
require_once __DIR__ . '/../shared/backend/services/EmailService.php';

$recipient = 'goontech1@gmail.com';
echo "Sending test password reset code to $recipient... ";
$resetRes = EmailService::sendPasswordResetCode($recipient, 'GNCP Administrator', '849201');
if ($resetRes['success']) {
    echo "[SUCCESS] Message delivered via Gmail SMTP!\n";
} else {
    echo "[FAILED] " . ($resetRes['message'] ?? 'Unknown error') . "\n";
}

echo "Sending test station credentials email to $recipient... ";
$credRes = EmailService::sendUserCredentials($recipient, 'GNCP Test Operator', 'TEST_REGISTRAR_01', 'TemporaryPass#2026', 'REGISTRAR');
if ($credRes['success']) {
    echo "[SUCCESS] Message delivered via Gmail SMTP!\n";
} else {
    echo "[FAILED] " . ($credRes['message'] ?? 'Unknown error') . "\n";
}

