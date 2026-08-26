<?php
/**
 * GNCP-SECURITY — Centralized Web Application Firewall (WAF) & Security Middleware
 * Hardens all system entry points against automated vulnerability scanners,
 * pen-testing tools (OWASP ZAP, SQLmap, Nikto, Burp Suite, Gobuster), and exploit payloads.
 */

if (!defined('GNCP_SECURITY_GUARD_LOADED')) {
    define('GNCP_SECURITY_GUARD_LOADED', true);

    // 1. Enforce Strict HTTP Response Security Headers
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        @ini_set('expose_php', 'off');

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Configure Hardened Session Cookie Parameters
        if (session_status() === PHP_SESSION_NONE) {
            @ini_set('session.cookie_httponly', 1);
            @ini_set('session.use_only_cookies', 1);
            @ini_set('session.cookie_samesite', 'Lax');
            if ($isHttps) {
                @ini_set('session.cookie_secure', 1);
            }
        }
    }

    // 2. Automated Vulnerability Scanner & Exploit Bot Interceptor
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $maliciousScanners = [
        'sqlmap', 'nikto', 'acunetix', 'dirbuster', 'gobuster', 'wpscan',
        'nessus', 'masscan', 'zgrab', 'nmap', 'arachni', 'openvas',
        'netsparker', 'fuzz', 'dirsearch', 'nuclei', 'ffuf', 'hydra'
    ];

    foreach ($maliciousScanners as $scanner) {
        if ($userAgent !== '' && strpos($userAgent, $scanner) !== false) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Automated scanning tool signature blocked by GNCP-SECURITY firewall.',
                'code' => 403
            ]);
            exit;
        }
    }

    // 3. Deep Request & Payload Inspection (WAF Filter)
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $rawPayload = $requestUri . ' ' . $queryString;

    $attackPatterns = [
        // Path / Directory Traversal
        '#(?:\.\.[\\\\/]|%2e%2e)#i',
        // PHP Wrapper Exploits
        '#(?:php://|data://|expect://|input://)#i',
        // Null Byte Injections
        '#[\x00]|%00#i',
        // Common Command Execution Fuzzers
        '#(?:;|&&|\|\|)\s*(?:cat\s+/etc|whoami|curl\s+http|wget\s+http|bash\s+-i|cmd\.exe)#i'
    ];

    foreach ($attackPatterns as $pattern) {
        if (preg_match($pattern, $rawPayload)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Security policy violation: Malicious payload intercepted.',
                'code' => 403
            ]);
            exit;
        }
    }

    // 4. Recursive Input Sanitization & Control Character Normalization
    if (!function_exists('gncp_sanitize_deep')) {
        function gncp_sanitize_deep(&$item) {
            if (is_array($item)) {
                foreach ($item as &$val) {
                    gncp_sanitize_deep($val);
                }
            } elseif (is_string($item)) {
                // Strip null bytes and non-printable control chars (except standard newlines/tabs)
                $item = str_replace(chr(0), '', $item);
                $item = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $item);
            }
        }
    }

    if (!empty($_GET)) gncp_sanitize_deep($_GET);
    if (!empty($_POST)) gncp_sanitize_deep($_POST);
    if (!empty($_COOKIE)) gncp_sanitize_deep($_COOKIE);
}
