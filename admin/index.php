<?php
/**
 * GNCP Super Admin Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
initSession();

$adminUser = $_SESSION['gncp_admin_user'] ?? null;
$userRole  = '';

if ($adminUser) {
    $u = is_array($adminUser) ? $adminUser : json_decode($adminUser, true);
    $userRole = strtoupper($u['role'] ?? '');
}

if (!$adminUser || !in_array($userRole, ['ADMIN', 'SUPER_ADMIN'], true)) {
    $redirectUrl = '../?clear=true&auth_required=true&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/systemtest/admin/');
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
    exit;
}

// Caller is authenticated Admin / Super Admin — serve portal
readfile(__DIR__ . '/index.html');
