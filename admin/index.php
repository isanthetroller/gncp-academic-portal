<?php
/**
 * GNCP Super Admin Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication and single-active session before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';

requirePageAuth(['ADMIN', 'SUPER_ADMIN'], '../');

// Caller is authenticated Admin / Super Admin with valid single-active session — serve portal
readfile(__DIR__ . '/index.html');

