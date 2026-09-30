<?php
/**
 * GNCP Super Admin Portal — Server-Side Authentication Gateway
 * Enforces server-side authentication and single-active session before serving portal DOM or scripts.
 */
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';

requirePageAuth(['ADMIN', 'SUPER_ADMIN'], '../');

// Caller is authenticated Admin / Super Admin with valid single-active session — serve portal with dynamic asset cache-busting
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$html = file_get_contents(__DIR__ . '/index.html');
$adminCtrlMtime = filemtime(__DIR__ . '/assets/js/controllers/AdminController.js');
$adminSidebarMtime = filemtime(__DIR__ . '/assets/js/components/AdminSidebar.js');

$html = preg_replace('/assets\/js\/controllers\/AdminController\.js(\?v=[^"\'\s>]+)?/', 'assets/js/controllers/AdminController.js?v=' . $adminCtrlMtime, $html);
$html = preg_replace('/assets\/js\/components\/AdminSidebar\.js(\?v=[^"\'\s>]+)?/', 'assets/js/components/AdminSidebar.js?v=' . $adminSidebarMtime, $html);

echo $html;

