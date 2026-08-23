<?php
/**
 * GNCP Academic Portal — Shared HTTP API Response Helper
 */

// Configure system-wide PHP error handling and logging configurations
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Custom error handler to catch PHP warnings, notices, and user-generated errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    sendResponse(
        false, 
        null, 
        "Internal PHP Error: $errstr in " . basename($errfile) . " on line $errline", 
        500
    );
});

// Custom exception handler to catch uncaught PHP Exceptions
set_exception_handler(function($exception) {
    error_log("PHP Uncaught Exception: " . $exception->getMessage() . "\n" . $exception->getTraceAsString());
    sendResponse(
        false, 
        null, 
        "Internal Server Error: " . $exception->getMessage(), 
        500
    );
});

// Custom shutdown handler to catch fatal errors that bypass standard handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log("PHP Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        sendResponse(
            false, 
            null, 
            "Fatal Server Error: " . $error['message'] . " in " . basename($error['file']) . " on line " . $error['line'], 
            500
        );
    }
});

function sendResponse($success, $data = null, $error = null, $statusCode = 200) {
    // CORS headers - dynamically reflect request origin if present
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    if (!headers_sent()) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-Request-ID');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        http_response_code($statusCode);
    }
    
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
    echo json_encode([
        'success'   => (bool)$success,
        'data'      => $data,
        'message'   => $error !== null ? (string)$error : null,
        'error'     => $error !== null ? (string)$error : null,
        'timestamp' => date('c')
    ]);
    exit;
}
