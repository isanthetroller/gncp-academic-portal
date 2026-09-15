<?php
/**
 * GNCP Unified Employee Login API Endpoint (Delegator Gateway)
 * Delegates directly to the authoritative AuthController to guarantee single source of truth.
 */

require_once __DIR__ . '/utils/security_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/../../api/controllers/AuthController.php';

$pdo = Database::getInstance();
$authCtrl = new AuthController($pdo);

if (($_GET['action'] ?? '') === 'logout') {
    $res = $authCtrl->logout();
    sendResponse($res['success'], null, $res['message'] ?? 'Logged out successfully.', 200);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Method not allowed. Use POST.', 405);
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true) ?: [];

$res = $authCtrl->login($payload);
$httpCode = $res['code'] ?? ($res['success'] ? 200 : 400);

sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? ($res['error'] ?? ''), $httpCode);
