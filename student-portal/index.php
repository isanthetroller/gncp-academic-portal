<?php
/**
 * GNCP Student Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication and single-active session before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';

$basePath = (isset($_SERVER['REQUEST_URI']) && preg_match('#^/([^/]+)#', $_SERVER['REQUEST_URI'], $m)) ? '/' . $m[1] : '';
$loginUrl = $basePath . '/student-portal/login';

requirePageAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN'], $loginUrl);

// Caller is authenticated student or admin with valid single-active session — serve portal HTML
readfile(__DIR__ . '/index.html');

