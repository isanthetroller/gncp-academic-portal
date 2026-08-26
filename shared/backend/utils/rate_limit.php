<?php
/**
 * GNCP Rate Limiter — File-based IP rate limiting for public endpoints.
 *
 * Uses atomic file-based counters stored in shared/backend/logs/rate_limits/.
 * No APCu, Redis, or Memcached required — works on all XAMPP setups.
 *
 * Usage:
 *   require_once __DIR__ . '/rate_limit.php';
 *   checkRateLimit('student_register', 5, 60);  // max 5 requests per 60 seconds per IP
 */

/**
 * Resolves the genuine client IP address, guarding against header spoofing on direct connections.
 */
function getRateLimitClientIp(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Only inspect X-Forwarded-For if explicitly configured to run behind a trusted reverse proxy
    if (getenv('GNCP_TRUST_FORWARDED_IP') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($forwarded[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    
    return $remoteAddr;
}

/**
 * Checks and enforces a rate limit for the given action and client IP.
 *
 * @param string $action    A short identifier for the action (e.g. 'student_register')
 * @param int    $maxHits   Maximum number of requests allowed within the window
 * @param int    $windowSec Time window in seconds (e.g. 60 = per minute)
 */
function checkRateLimit(string $action, int $maxHits = 10, int $windowSec = 60): void {
    $ip = getRateLimitClientIp();
    // Sanitize IP for use as filename
    $safeIp = preg_replace('/[^a-f0-9:.\-]/', '_', strtolower(trim($ip)));
    $safeAction = preg_replace('/[^a-z0-9_]/', '_', $action);

    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . $safeAction . '_' . $safeIp . '.json';
    $now  = time();

    // Read current state (atomic read with lock)
    $state = ['hits' => 0, 'window_start' => $now];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
    }

    // Reset window if expired
    if (($now - $state['window_start']) >= $windowSec) {
        $state = ['hits' => 0, 'window_start' => $now];
    }

    $state['hits']++;

    // Write updated state back
    @file_put_contents($file, json_encode($state), LOCK_EX);

    if ($state['hits'] > $maxHits) {
        $retryAfter = $windowSec - ($now - $state['window_start']);
        if (!headers_sent()) {
            header('Retry-After: ' . max(1, $retryAfter));
            header('X-RateLimit-Limit: ' . $maxHits);
            header('X-RateLimit-Remaining: 0');
            http_response_code(429);
        }
        echo json_encode([
            'success'   => false,
            'message'   => 'Too many requests. Please wait ' . max(1, $retryAfter) . ' seconds before trying again.',
            'code'      => 429,
            'retryAfter'=> max(1, $retryAfter)
        ]);
        exit;
    }

    // Set informational headers
    if (!headers_sent()) {
        header('X-RateLimit-Limit: ' . $maxHits);
        header('X-RateLimit-Remaining: ' . max(0, $maxHits - $state['hits']));
    }
}

/**
 * Checks if client is currently locked out from login attempts.
 */
function checkLoginRateLimit(string $action, string $identifier = '', int $maxFailed = 5, int $windowSec = 300): void {
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $safeIp = preg_replace('/[^a-f0-9:.\-]/', '_', strtolower(trim(explode(',', $ip)[0])));
    $safeAction = preg_replace('/[^a-z0-9_]/', '_', $action);
    $safeUser = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(trim($identifier)));

    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $key = $safeAction . '_' . $safeIp . ($safeUser ? '_' . $safeUser : '');
    $file = $dir . $key . '.json';
    $now  = time();

    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $state = json_decode($raw, true);
            if (is_array($state) && isset($state['failures'], $state['window_start'])) {
                if (($now - $state['window_start']) < $windowSec && $state['failures'] >= $maxFailed) {
                    $retryAfter = $windowSec - ($now - $state['window_start']);
                    if (!headers_sent()) {
                        header('Retry-After: ' . max(1, $retryAfter));
                        header('X-RateLimit-Limit: ' . $maxFailed);
                        header('X-RateLimit-Remaining: 0');
                        http_response_code(429);
                    }
                    echo json_encode([
                        'success'    => false,
                        'message'    => 'Too many failed login attempts. Please wait ' . max(1, $retryAfter) . ' seconds before trying again.',
                        'code'       => 429,
                        'retryAfter' => max(1, $retryAfter)
                    ]);
                    exit;
                }
            }
        }
    }
}

/**
 * Records a failed login attempt. If threshold exceeded, triggers 429 lockout.
 */
function recordLoginFailure(string $action, string $identifier = '', int $maxFailed = 5, int $windowSec = 300): void {
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $safeIp = preg_replace('/[^a-f0-9:.\-]/', '_', strtolower(trim(explode(',', $ip)[0])));
    $safeAction = preg_replace('/[^a-z0-9_]/', '_', $action);
    $safeUser = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(trim($identifier)));

    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $key = $safeAction . '_' . $safeIp . ($safeUser ? '_' . $safeUser : '');
    $file = $dir . $key . '.json';
    $now  = time();

    $state = ['failures' => 0, 'window_start' => $now];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
    }

    if (($now - $state['window_start']) >= $windowSec) {
        $state = ['failures' => 0, 'window_start' => $now];
    }

    $state['failures'] = ($state['failures'] ?? 0) + 1;
    @file_put_contents($file, json_encode($state), LOCK_EX);

    if ($state['failures'] >= $maxFailed) {
        $retryAfter = $windowSec - ($now - $state['window_start']);
        header('Retry-After: ' . max(1, $retryAfter));
        header('X-RateLimit-Limit: ' . $maxFailed);
        header('X-RateLimit-Remaining: 0');
        http_response_code(429);
        echo json_encode([
            'success'    => false,
            'message'    => 'Too many failed login attempts. Please wait ' . max(1, $retryAfter) . ' seconds before trying again.',
            'code'       => 429,
            'retryAfter' => max(1, $retryAfter)
        ]);
        exit;
    }
}

/**
 * Resets failed login attempt counter upon successful login.
 */
function clearLoginFailures(string $action, string $identifier = ''): void {
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $safeIp = preg_replace('/[^a-f0-9:.\-]/', '_', strtolower(trim(explode(',', $ip)[0])));
    $safeAction = preg_replace('/[^a-z0-9_]/', '_', $action);
    $safeUser = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(trim($identifier)));

    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) return;

    $key = $safeAction . '_' . $safeIp . ($safeUser ? '_' . $safeUser : '');
    $file = $dir . $key . '.json';
    if (file_exists($file)) {
        @unlink($file);
    }
    // Also clear IP-only key if any
    $fileIp = $dir . $safeAction . '_' . $safeIp . '.json';
    if (file_exists($fileIp)) {
        @unlink($fileIp);
    }
}

/**
 * Purge rate limit files older than the given TTL (called opportunistically).
 * Prevents rate_limits/ from growing unbounded.
 *
 * @param int $ttlSec Files older than this many seconds will be deleted (default 1 hour)
 */
function pruneRateLimitFiles(int $ttlSec = 3600): void {
    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) return;
    $now = time();
    foreach (glob($dir . '*.json') as $file) {
        if (($now - @filemtime($file)) > $ttlSec) {
            @unlink($file);
        }
    }
}
