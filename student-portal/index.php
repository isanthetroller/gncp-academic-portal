<?php
/**
 * GNCP Student Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication and single-active session before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';

// Redirect unauthenticated visitors to the student portal login page
requirePageAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN'], 'login.html');

// Caller is authenticated student or admin with valid single-active session — serve portal HTML
readfile(__DIR__ . '/index.html');

