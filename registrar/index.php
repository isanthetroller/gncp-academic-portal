<?php
/**
 * GNCP Registrar Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
initSession();

$stationUser = $_SESSION['gncp_station_user'] ?? ($_SESSION['gncp_admin_user'] ?? null);
$userRole    = '';

if ($stationUser) {
    $u = is_array($stationUser) ? $stationUser : json_decode($stationUser, true);
    $userRole = strtoupper($u['role'] ?? '');
}

if (!$stationUser || !in_array($userRole, ['REGISTRAR', 'ADMIN', 'SUPER_ADMIN'], true)) {
    $redirectUrl = '../?clear=true&auth_required=true&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/systemtest/registrar/');
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
    exit;
}

// Caller is authenticated Registrar / Admin — serve portal
readfile(__DIR__ . '/index.html');
