<?php
/**
 * GNCP Unified Session Check Endpoint
 * Validates active PHP session status against MariaDB single-active session token and returns operator details.
 */
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/session_guard.php';

$user = requireAuth();

sendResponse(true, $user, 'Active session found.', 200);

