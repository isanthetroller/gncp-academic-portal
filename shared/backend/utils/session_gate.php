<?php
/**
 * GNCP Workstation Session Gate — PHP Auth Guard (CVE-GNCP-002 Remediation)
 * Validates server-side PHP session before serving any workstation page.
 * Prevents client-side sessionStorage forgery from granting UI access.
 *
 * Usage: Call session_gate(['ROLE1','ROLE2']) at top of any workstation index.php
 */

require_once __DIR__ . '/security_guard.php';
require_once __DIR__ . '/session_guard.php';

function session_gate(array $allowed_roles = []) {
    initSession();

    $admin_user   = $_SESSION['gncp_admin_user']   ?? null;
    $station_user = $_SESSION['gncp_station_user'] ?? null;

    $user = null;
    if (is_array($admin_user) && !empty($admin_user['role'])) {
        $user = $admin_user;
    } elseif (is_array($station_user) && !empty($station_user['role'])) {
        $user = $station_user;
    }

    // No valid PHP session — redirect to gateway login
    if (!$user) {
        $base = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        header('Location: ' . $base . '/');
        exit;
    }

    // Role restriction check
    if (!empty($allowed_roles)) {
        $role = strtoupper($user['role'] ?? '');
        if (!in_array($role, $allowed_roles, true)) {
            // Authenticated but wrong role — redirect to their correct portal
            $base = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            header('HTTP/1.1 403 Forbidden');
            header('Location: ' . $base . '/');
            exit;
        }
    }
}
