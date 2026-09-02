<?php
/**
 * GNCP TLC Helpdesk & Advising Station — Server-Side Authentication Gateway
 */
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
initSession();

$stationUser = $_SESSION['gncp_station_user'] ?? ($_SESSION['gncp_admin_user'] ?? null);
$userRole    = '';

if ($stationUser) {
    $u = is_array($stationUser) ? $stationUser : json_decode($stationUser, true);
    $userRole = strtoupper($u['role'] ?? '');
}

if (!$stationUser || !in_array($userRole, ['HELPDESK', 'ADMIN', 'SUPER_ADMIN'], true)) {
    $redirectUrl = '../../?clear=true&auth_required=true&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/systemtest/stations/tlc-helpdesk/');
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
    exit;
}

readfile(__DIR__ . '/index.html');
