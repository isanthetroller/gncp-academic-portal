<?php
/**
 * GNCP Student Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
initSession();

$studentUser = $_SESSION['gncp_student'] ?? null;
$adminUser   = $_SESSION['gncp_admin_user'] ?? null;
$stationUser = $_SESSION['gncp_station_user'] ?? null;

$isAuthorized = ($studentUser !== null || $adminUser !== null || $stationUser !== null);

if (!$isAuthorized) {
    $basePath = (isset($_SERVER['REQUEST_URI']) && preg_match('#^/([^/]+)#', $_SERVER['REQUEST_URI'], $m)) ? '/' . $m[1] : '';
    $redirectUrl = $basePath . '/student-portal/login?clear=true&auth_required=true&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ($basePath . '/student-portal/'));
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
    exit;
}

// Anti-caching headers for dynamic student state
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Caller is authenticated — serve portal HTML
readfile(__DIR__ . '/index.html');
