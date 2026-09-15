<?php
/**
 * System Mail & SMTP Configuration
 * Configures Gmail SMTP transport parameters for automated credential dispatches.
 */

require_once __DIR__ . '/env.php';

$envPass = env('GNCP_SMTP_PASS') ?: '';
$envUser = env('GNCP_SMTP_USER') ?: '';
$envHost = env('GNCP_SMTP_HOST') ?: 'smtp.gmail.com';
$envPort = intval(env('GNCP_SMTP_PORT') ?: 587);

// Local development fallback file (if present on local machine)
if (empty($envPass) && file_exists(__DIR__ . '/mail.local.php')) {
    $local = @include __DIR__ . '/mail.local.php';
    if (is_array($local)) {
        $envPass = $local['password'] ?? $envPass;
        $envUser = $local['username'] ?? $envUser;
        $envHost = $local['host'] ?? $envHost;
        $envPort = intval($local['port'] ?? $envPort);
    }
}

$envPass = str_replace(' ', '', (string)$envPass);

return [
    'driver'     => 'smtp',
    'host'       => $envHost,
    'port'       => $envPort,
    'encryption' => ($envPort === 465) ? 'ssl' : 'tls',
    'username'   => $envUser,
    'password'   => $envPass,
    'from_email' => !empty($envUser) ? $envUser : 'no-reply@gncp.edu.ph',
    'from_name'  => 'GNCP Portal Administrator',
    'debug_mode' => false,
];

