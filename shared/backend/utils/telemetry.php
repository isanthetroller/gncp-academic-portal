<?php
/**
 * GNCP Developer Security & Traffic Telemetry Engine
 * 
 * Provides:
 * 1. High-speed atomic request telemetry logging
 * 2. IP Firewall & Blacklist enforcement (isIpBanned, banIp, unbanIp)
 * 3. Rate Limit state inspection and live lockout telemetry
 * 4. Aggregated traffic diagnostics (requests, unique IPs, 429 triggers, error rates)
 */

require_once __DIR__ . '/rate_limit.php';

/**
 * Returns the genuine client IP.
 */
function getTelemetryClientIp(): string {
    return getRateLimitClientIp();
}

/**
 * Path to telemetry log file and IP blacklist configuration.
 */
function getTelemetryLogPath(): string {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/telemetry.log';
}

function getIpBlacklistPath(): string {
    $dir = __DIR__ . '/../config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/ip_blacklist.json';
}

/**
 * Checks if the given IP address is banned.
 */
function isIpBanned(string $ip = ''): bool {
    if (empty($ip)) {
        $ip = getTelemetryClientIp();
    }
    $file = getIpBlacklistPath();
    if (!file_exists($file)) {
        return false;
    }
    $raw = @file_get_contents($file);
    if (!$raw) {
        return false;
    }
    $blacklist = json_decode($raw, true);
    if (!is_array($blacklist)) {
        return false;
    }

    $clientIp = trim(strtolower($ip));
    foreach ($blacklist as $banned) {
        $target = trim(strtolower($banned['ip'] ?? ''));
        if ($target === $clientIp) {
            // Check if ban is expired
            if (!empty($banned['expires_at']) && time() > $banned['expires_at']) {
                continue;
            }
            return true;
        }
    }
    return false;
}

/**
 * Bans an IP address.
 */
function banIp(string $ip, string $reason = 'Manual Security Block', string $bannedBy = 'Admin', int $durationSec = 0): bool {
    $cleanIp = trim($ip);
    if (!filter_var($cleanIp, FILTER_VALIDATE_IP)) {
        return false;
    }

    $file = getIpBlacklistPath();
    $blacklist = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $blacklist = $decoded;
            }
        }
    }

    // Filter out existing record for this IP
    $blacklist = array_values(array_filter($blacklist, function($item) use ($cleanIp) {
        return strtolower(trim($item['ip'] ?? '')) !== strtolower($cleanIp);
    }));

    $blacklist[] = [
        'ip'         => $cleanIp,
        'reason'     => $reason,
        'banned_by'  => $bannedBy,
        'banned_at'  => time(),
        'expires_at' => $durationSec > 0 ? (time() + $durationSec) : null
    ];

    return (bool) @file_put_contents($file, json_encode($blacklist, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Unbans an IP address.
 */
function unbanIp(string $ip): bool {
    $cleanIp = trim(strtolower($ip));
    $file = getIpBlacklistPath();
    if (!file_exists($file)) {
        return true;
    }
    $raw = @file_get_contents($file);
    if (!$raw) {
        return true;
    }
    $blacklist = json_decode($raw, true);
    if (!is_array($blacklist)) {
        return true;
    }

    $updated = array_values(array_filter($blacklist, function($item) use ($cleanIp) {
        return strtolower(trim($item['ip'] ?? '')) !== $cleanIp;
    }));

    return (bool) @file_put_contents($file, json_encode($updated, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Returns all banned IP addresses.
 */
function getBannedIps(): array {
    $file = getIpBlacklistPath();
    if (!file_exists($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if (!$raw) {
        return [];
    }
    $list = json_decode($raw, true);
    return is_array($list) ? $list : [];
}

/**
 * Records a fast atomic telemetry log entry.
 */
function recordTelemetry(string $method, string $uri, string $action, int $status, float $durationMs = 0.0, array $context = []): void {
    $logFile = getTelemetryLogPath();
    $ip = getTelemetryClientIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $reqId = getRequestId();

    $entry = [
        'id'          => $reqId,
        'timestamp'   => time(),
        'date'        => date('Y-m-d H:i:s'),
        'ip'          => $ip,
        'method'      => strtoupper($method),
        'uri'         => $uri,
        'action'      => $action ?: ($uri ?: '/'),
        'status'      => $status,
        'duration_ms' => round($durationMs, 2),
        'user_agent'  => substr($userAgent, 0, 255),
        'context'     => $context
    ];

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    // Opportunistically rotate log if larger than 5MB
    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        @rename($logFile, $logFile . '.' . date('Ymd_His') . '.old');
    }
}

/**
 * Retrieves recent telemetry entries with optional filtering.
 */
function getRecentTelemetry(int $limit = 100, string $filterIp = '', $filterStatus = null, string $search = ''): array {
    $logFile = getTelemetryLogPath();
    if (!file_exists($logFile)) {
        return [];
    }

    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }

    $records = [];
    $totalLines = count($lines);
    $start = max(0, $totalLines - 1000); // inspect last 1000 lines for speed

    for ($i = $totalLines - 1; $i >= $start; $i--) {
        $data = json_decode($lines[$i], true);
        if (!$data || !is_array($data)) {
            continue;
        }

        if (!empty($filterIp) && strtolower(trim($data['ip'])) !== strtolower(trim($filterIp))) {
            continue;
        }

        if ($filterStatus !== null && $filterStatus !== '' && (int)$data['status'] !== (int)$filterStatus) {
            continue;
        }

        if (!empty($search)) {
            $q = strtolower(trim($search));
            $match = (
                strpos(strtolower($data['ip']), $q) !== false ||
                strpos(strtolower($data['action']), $q) !== false ||
                strpos(strtolower($data['uri']), $q) !== false ||
                strpos(strtolower($data['user_agent']), $q) !== false
            );
            if (!$match) {
                continue;
            }
        }

        $records[] = $data;
        if (count($records) >= $limit) {
            break;
        }
    }

    return $records;
}

/**
 * Computes high-level security & traffic statistics.
 */
function getTelemetryStats(): array {
    $logFile = getTelemetryLogPath();
    $bannedList = getBannedIps();
    $bannedCount = count($bannedList);

    $now = time();
    $past24h = $now - 86400;
    $todayStart = strtotime('today midnight');

    $totalRequests24h = 0;
    $totalRequestsToday = 0;
    $rateLimitHits24h = 0;
    $errorCount24h = 0; // 5xx
    $clientErrors24h = 0; // 4xx

    $uniqueIps = [];
    $ipCounts = [];
    $endpointCounts = [];
    $statusDistribution = [
        '2xx' => 0,
        '3xx' => 0,
        '4xx' => 0,
        '429' => 0,
        '5xx' => 0
    ];

    // Hourly traffic buckets for 24h chart
    $hourlyBuckets = [];
    for ($h = 23; $h >= 0; $h--) {
        $hourTime = strtotime("-{$h} hours");
        $key = date('Y-m-d H:00', $hourTime);
        $label = date('H:00', $hourTime);
        $hourlyBuckets[$key] = [
            'label'    => $label,
            'requests' => 0,
            'blocked'  => 0,
            'errors'   => 0
        ];
    }

    if (file_exists($logFile)) {
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $totalLines = count($lines);
            $start = max(0, $totalLines - 5000); // inspect last 5000 lines for high efficiency

            for ($i = $totalLines - 1; $i >= $start; $i--) {
                $item = json_decode($lines[$i], true);
                if (!$item || !is_array($item)) {
                    continue;
                }

                $ts = (int)($item['timestamp'] ?? 0);
                if ($ts < $past24h) {
                    continue;
                }

                $totalRequests24h++;
                if ($ts >= $todayStart) {
                    $totalRequestsToday++;
                }

                $ip = $item['ip'] ?? '0.0.0.0';
                $status = (int)($item['status'] ?? 200);
                $action = $item['action'] ?? $item['uri'] ?? 'unknown';

                $uniqueIps[$ip] = true;
                $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
                $endpointCounts[$action] = ($endpointCounts[$action] ?? 0) + 1;

                if ($status === 429) {
                    $rateLimitHits24h++;
                    $statusDistribution['429']++;
                } elseif ($status >= 500) {
                    $errorCount24h++;
                    $statusDistribution['5xx']++;
                } elseif ($status >= 400) {
                    $clientErrors24h++;
                    $statusDistribution['4xx']++;
                } elseif ($status >= 300) {
                    $statusDistribution['3xx']++;
                } else {
                    $statusDistribution['2xx']++;
                }

                // Hourly bucket accumulation
                $hourKey = date('Y-m-d H:00', $ts);
                if (isset($hourlyBuckets[$hourKey])) {
                    $hourlyBuckets[$hourKey]['requests']++;
                    if ($status === 429 || $status === 403) {
                        $hourlyBuckets[$hourKey]['blocked']++;
                    }
                    if ($status >= 500) {
                        $hourlyBuckets[$hourKey]['errors']++;
                    }
                }
            }
        }
    }

    arsort($ipCounts);
    arsort($endpointCounts);

    $topIps = [];
    foreach (array_slice($ipCounts, 0, 10, true) as $ip => $hits) {
        $topIps[] = [
            'ip'        => $ip,
            'hits'      => $hits,
            'is_banned' => isIpBanned($ip)
        ];
    }

    $topEndpoints = [];
    foreach (array_slice($endpointCounts, 0, 10, true) as $ep => $hits) {
        $topEndpoints[] = ['endpoint' => $ep, 'hits' => $hits];
    }

    return [
        'total_requests_24h'   => $totalRequests24h,
        'total_requests_today' => $totalRequestsToday,
        'unique_ips_24h'       => count($uniqueIps),
        'rate_limit_hits_24h'  => $rateLimitHits24h,
        'server_errors_24h'    => $errorCount24h,
        'client_errors_24h'    => $clientErrors24h,
        'banned_ips_count'     => $bannedCount,
        'top_ips'              => $topIps,
        'top_endpoints'        => $topEndpoints,
        'status_distribution'  => $statusDistribution,
        'hourly_timeline'      => array_values($hourlyBuckets)
    ];
}

/**
 * Scans active rate limits in shared/backend/logs/rate_limits/.
 */
function getActiveRateLimitsState(): array {
    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) {
        return [];
    }

    $files = scandir($dir);
    $active = [];
    $now = time();

    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || !str_ends_with($f, '.json')) {
            continue;
        }

        $path = $dir . $f;
        $raw = @file_get_contents($path);
        if (!$raw) {
            continue;
        }

        $data = json_decode($raw, true);
        if (!$data || !is_array($data)) {
            continue;
        }

        // Parse key from filename: action_ip(_user).json
        $name = substr($f, 0, -5);

        $isLockout = isset($data['failures']);
        $hits = $isLockout ? ($data['failures'] ?? 0) : ($data['hits'] ?? 0);
        $windowStart = $data['window_start'] ?? $now;
        $windowAge = $now - $windowStart;

        // Skip records older than 10 minutes
        if ($windowAge > 600) {
            continue;
        }

        $active[] = [
            'key'          => $name,
            'file'         => $f,
            'type'         => $isLockout ? 'LOGIN_LOCKOUT' : 'REQUEST_LIMIT',
            'hits'         => $hits,
            'window_start' => $windowStart,
            'age_seconds'  => $windowAge,
            'time_left'    => max(0, 60 - $windowAge),
            'raw_data'     => $data
        ];
    }

    return $active;
}

/**
 * Clears rate limit file(s).
 */
function clearRateLimitState(string $targetKey = ''): bool {
    $dir = __DIR__ . '/../logs/rate_limits/';
    if (!is_dir($dir)) {
        return true;
    }

    if ($targetKey === 'ALL' || empty($targetKey)) {
        $files = glob($dir . '*.json');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        return true;
    }

    $safeKey = preg_replace('/[^a-zA-Z0-9_\-\.:]/', '', $targetKey);
    $file = $dir . $safeKey . '.json';
    if (file_exists($file)) {
        return @unlink($file);
    }
    return true;
}
