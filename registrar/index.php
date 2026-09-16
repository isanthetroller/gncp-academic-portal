<?php
/**
 * GNCP Registrar Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication and single-active session before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';

requirePageAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN'], '../');

// Caller is authenticated Registrar / Admin with valid single-active session — serve portal
readfile(__DIR__ . '/index.html');

