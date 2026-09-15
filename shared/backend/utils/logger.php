<?php
/**
 * GNCP Academic Portal — Centralized Logger & Request Tracker
 * Logs application errors with X-Request-ID correlation headers to shared/backend/logs/app_errors.log.
 */

function getRequestId() {
    static $requestId = null;
    if ($requestId === null) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? $headers['X-Request-ID'] ?? $headers['x-request-id'] ?? null;
        if (!$requestId) {
            $requestId = 'req_' . substr(md5(uniqid(microtime(), true)), 0, 10);
        }
    }
    return $requestId;
}

function logAppError($message, $context = []) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/app_errors.log';
    
    $reqId = getRequestId();
    $timestamp = date('c');

    $sessionUser = $_SESSION['gncp_admin_user'] ?? $_SESSION['gncp_station_user'] ?? $_SESSION['gncp_student'] ?? null;
    $userRole    = is_array($sessionUser) ? ($sessionUser['role'] ?? 'STUDENT') : 'GUEST';

    $payload = [
        'timestamp'      => $timestamp,
        'correlation_id' => $reqId,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'request_uri'    => $_SERVER['REQUEST_URI'] ?? 'CLI',
        'user_role'      => $userRole,
        'message'        => $message,
        'context'        => $context
    ];

    $entry = json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
    error_log("[{$reqId}] [{$userRole}] {$message}");
}
