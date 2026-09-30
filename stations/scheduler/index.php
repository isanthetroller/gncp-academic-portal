<?php
/**
 * GNCP Academic Scheduler Workstation — Server-Side Authentication Gateway
 * Enforces server-side authentication and single-active session before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

requirePageAuth(['SCHEDULER', 'REGISTRAR', 'ADMIN', 'SUPER_ADMIN'], '../../');

// Caller is authenticated with valid single-active session — serve station
readfile(__DIR__ . '/index.html');
