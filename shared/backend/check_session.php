<?php
/**
 * GNCP Unified Session Check Endpoint
 * Validates active PHP session status and returns logged-in operator details.
 */

require_once __DIR__ . '/utils/response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$adminSession   = $_SESSION['gncp_admin_user'] ?? null;
$stationSession = $_SESSION['gncp_station_user'] ?? null;

$userJson = $adminSession ?: $stationSession;

if ($userJson) {
    $user = is_array($userJson) ? $userJson : json_decode($userJson, true);
    if ($user && isset($user['role'])) {
        sendResponse(true, $user, 'Active session found.', 200);
    }
}

sendResponse(false, null, 'No active session found.', 401);
