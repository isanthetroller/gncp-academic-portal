<?php
/**
 * GNCP Monitoring Station — PHP Session Gate (CVE-GNCP-002 Remediation)
 * Server-side authentication check prevents sessionStorage forgery bypass.
 */
require_once __DIR__ . '../shared/backend/utils/session_gate.php';
session_gate(['DEVELOPER', 'ADMIN', 'SUPER_ADMIN']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNCP-SECURITY — Threat Radar & Traffic Telemetry Console</title>
    <meta name="description" content="Go-on National College of the Philippines (GNCP) — Security Operations Center & Telemetry Radar.">
    <link rel="stylesheet" href="../shared/libs/fonts/fonts.css">
    <link rel="stylesheet" href="../shared/libs/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="assets/css/monitor.css?v=1789204778">
    <script src="../shared/libs/vue.global.js"></script>
    <script src="../shared/libs/sweetalert2.all.min.js"></script>
    <script src="../shared/js/SessionExpirationGuard.js?v=1789204778"></script>
</head>
<body>
    <div id="app" v-cloak class="dashboard-shell">

        <aside class="sidebar">
            <div class="brand-block">
                <img src="../school-website/assets/images/logo-removebg-preview.png" alt="GNCP Seal" class="brand-logo" onerror="this.onerror=null;this.src='/school-website/assets/images/logo-removebg-preview.png';">
                <div class="brand-text">
                    <h1>Go-on National College</h1>
                    <span class="brand-station-tag">GNCP-SECURITY</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-category-title">RADAR &amp; TELEMETRY</div>
                <button class="nav-cat-header" :class="{ active: activeTab === 'live' }" @click="setTab('live')">
                    <span><i class="fas fa-satellite-dish"></i>Live Traffic Radar</span>
                    <span class="nav-badge">{{ requests.length }}</span>
                </button>
                <button class="nav-cat-header" :class="{ active: activeTab === 'ips' }" @click="setTab('ips')">
                    <span><i class="fas fa-network-wired"></i>Top Client IPs</span>
                    <span class="nav-badge">{{ stats.unique_ips_24h }}</span>
                </button>
                <button class="nav-cat-header" :class="{ active: activeTab === 'rate_limits' }" @click="setTab('rate_limits')">
                    <span><i class="fas fa-tachometer-alt"></i>Rate Limits &amp; 429s</span>
                    <span class="nav-badge" :style="{ background: activeRateLimits.length > 0 ? '#ef4444' : '' }">{{ activeRateLimits.length }}</span>
                </button>

                <div class="sidebar-category-title">ACTIVE FIREWALL</div>
                <button class="nav-cat-header" :class="{ active: activeTab === 'banned' }" @click="setTab('banned')">
                    <span><i class="fas fa-shield-halved"></i>Banned IP Blacklist</span>
                    <span class="nav-badge" :style="{ background: bannedIps.length > 0 ? '#ef4444' : '' }">{{ bannedIps.length }}</span>
                </button>

                <div class="sidebar-category-title">SYSTEM HEALTH</div>
                <button class="nav-cat-header" :class="{ active: activeTab === 'health' }" @click="setTab('health')">
                    <span><i class="fas fa-server"></i>Server &amp; DB Telemetry</span>
                </button>

                <div class="sidebar-category-title">ADMINISTRATION</div>
                <a href="../admin/" class="nav-cat-header">
                    <span><i class="fas fa-arrow-left"></i>Admin Console</span>
                </a>
                <button class="nav-cat-header nav-logout" @click="logout" v-if="currentUser">
                    <span><i class="fas fa-right-from-bracket"></i>Sign Out / Lock</span>
                </button>
            </nav>

            <div class="sidebar-footer" v-if="currentUser">
                <div class="footer-avatar">
                    {{ (currentUser.username || 'DEV').substring(0, 2).toUpperCase() }}
                </div>
                <div class="footer-info">
                    <strong>{{ currentUser.name || currentUser.username }}</strong>
                    <span>GNCP-SECURITY</span>
                </div>
            </div>
        </aside>

        <main class="main-panel">
            
            <header class="top-bar">
                <div class="top-bar-left">
                    <span class="eyebrow">SECURITY OPERATIONS CENTER</span>
                    <span class="text-muted mx-1">/</span>
                    <h2 class="page-title m-0">GNCP-SECURITY Radar</h2>
                </div>

                <div class="hud-controls">
                    <div class="live-beacon">
                        <div class="live-pulse" :style="{ background: isPolling ? '#10b981' : '#ef4444', animation: isPolling ? 'pulse 1.5s infinite' : 'none' }"></div>
                        <span>{{ isPolling ? 'RADAR ACTIVE (' + pollingInterval + 's)' : 'RADAR PAUSED' }}</span>
                    </div>

                    <select v-model="pollingInterval" class="hud-btn" style="padding: 5px 8px;">
                        <option :value="1">1s (Ultra Fast)</option>
                        <option :value="3">3s (Standard)</option>
                        <option :value="5">5s (Relaxed)</option>
                        <option :value="10">10s (Background)</option>
                    </select>

                    <button class="hud-btn" @click="togglePolling" :title="isPolling ? 'Pause Polling' : 'Resume Polling'">
                        <i :class="isPolling ? 'fas fa-pause' : 'fas fa-play'"></i>
                        <span>{{ isPolling ? 'Pause' : 'Resume' }}</span>
                    </button>

                    <button class="hud-btn" @click="refreshData" title="Refresh Now">
                        <i class="fas fa-sync-alt" :class="{ 'fa-spin': isLoading }"></i>
                        <span>Refresh</span>
                    </button>
                </div>
            </header>

            <section class="dashboard-hero-banner" v-if="currentUser">
                <div>
                    <h3>{{ greeting }}</h3>
                    <p>Welcome to GNCP-SECURITY Threat Radar &amp; Traffic Telemetry Console</p>
                </div>
                <div class="d-none d-md-block text-end">
                    <span class="badge" style="background: rgba(212, 175, 55, 0.2); color: #d4af37; border: 1px solid rgba(212,175,55,0.4); padding: 6px 12px; font-family: var(--font-mono); font-size: 0.75rem;">
                        <i class="fas fa-shield-alt me-1"></i> FIREWALL ACTIVE
                    </span>
                </div>
            </section>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">
                        <span>Total Requests (24h)</span>
                        <i class="fas fa-chart-line text-muted"></i>
                    </div>
                    <div class="stat-value">{{ (stats.total_requests_24h || 0).toLocaleString() }}</div>
                    <div class="stat-meta">Today: {{ (stats.total_requests_today || 0).toLocaleString() }} hits</div>
                </div>

                <div class="stat-card cyan">
                    <div class="stat-label">
                        <span>Unique Inbound IPs</span>
                        <i class="fas fa-globe text-muted"></i>
                    </div>
                    <div class="stat-value" style="color: var(--accent-cyan);">{{ (stats.unique_ips_24h || 0).toLocaleString() }}</div>
                    <div class="stat-meta">Active client endpoints</div>
                </div>

                <div class="stat-card gold">
                    <div class="stat-label">
                        <span>429 Rate Limits</span>
                        <i class="fas fa-bolt text-muted"></i>
                    </div>
                    <div class="stat-value" style="color: var(--accent-gold);">{{ stats.rate_limit_hits_24h || 0 }}</div>
                    <div class="stat-meta">Active throttles: {{ activeRateLimits.length }}</div>
                </div>

                <div class="stat-card red">
                    <div class="stat-label">
                        <span>Banned Blacklist</span>
                        <i class="fas fa-ban text-muted"></i>
                    </div>
                    <div class="stat-value" style="color: var(--accent-red);">{{ stats.banned_ips_count || 0 }}</div>
                    <div class="stat-meta">Blocked by firewall</div>
                </div>

                <div class="stat-card purple">
                    <div class="stat-label">
                        <span>5xx Server Errors</span>
                        <i class="fas fa-bug text-muted"></i>
                    </div>
                    <div class="stat-value" style="color: var(--accent-purple);">{{ stats.server_errors_24h || 0 }}</div>
                    <div class="stat-meta">Healthy status: {{ stats.server_errors_24h === 0 ? 'Optimal' : 'Needs Review' }}</div>
                </div>
            </section>

            <section v-if="activeTab === 'live'" class="content-card">
                <div class="card-header-bar">
                    <div class="card-title">
                        <i class="fas fa-satellite-dish text-success"></i>
                        <span>Live Inbound Traffic Stream</span>
                        <span class="badge bg-light text-dark border ms-2" style="font-size:0.75rem;">Showing {{ requests.length }} entries</span>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <input type="text" v-model="searchQuery" class="auth-form-input" placeholder="Search URI or Action..." style="padding:6px 12px; width:200px; font-size:0.82rem;">
                        <input type="text" v-model="ipFilter" class="auth-form-input" placeholder="Filter by IP..." style="padding:6px 12px; width:140px; font-size:0.82rem;">
                        <select v-model="statusFilter" class="hud-btn" style="padding:6px 10px;">
                            <option value="">All Statuses</option>
                            <option value="200">200 OK</option>
                            <option value="400">400 Bad Request</option>
                            <option value="401">401 Unauthorized</option>
                            <option value="429">429 Rate Limited</option>
                            <option value="500">500 Server Error</option>
                        </select>
                        <button class="hud-btn" @click="clearFilters" title="Clear Filters" v-if="searchQuery || ipFilter || statusFilter">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="station-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Client IP</th>
                                <th>Method</th>
                                <th>Action / URI</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="req in requests" :key="req.id">
                                <td style="font-family: var(--font-mono); font-size:0.75rem;">{{ formatTime(req.timestamp) }}</td>
                                <td>
                                    <strong style="font-family: var(--font-mono); cursor:pointer;" @click="filterByIp(req.ip)" :title="'Filter by ' + req.ip">
                                        {{ req.ip }}
                                    </strong>
                                </td>
                                <td>
                                    <span :class="getMethodClass(req.method)">{{ req.method }}</span>
                                </td>
                                <td style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: var(--font-mono); font-size:0.78rem;">
                                    {{ req.action || req.uri }}
                                </td>
                                <td>
                                    <span :class="getStatusClass(req.status_code)" class="status-badge-http">
                                        {{ req.status_code }}
                                    </span>
                                </td>
                                <td style="font-family: var(--font-mono); font-size:0.75rem; color:var(--text-muted);">
                                    {{ req.duration_ms ? req.duration_ms + ' ms' : '—' }}
                                </td>
                                <td class="text-end">
                                    <button class="hud-btn red-hover" @click="handleBanIp(req.ip)" title="Ban this IP" style="padding:3px 8px; font-size:0.72rem;">
                                        <i class="fas fa-ban text-danger"></i>
                                        <span>Ban</span>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="requests.length === 0">
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No requests recorded matching the active filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="activeTab === 'ips'" class="content-card">
                <div class="card-header-bar">
                    <div class="card-title">
                        <i class="fas fa-network-wired text-info"></i>
                        <span>High-Volume Inbound IP Breakdown</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="station-table">
                        <thead>
                            <tr>
                                <th>Client IP Address</th>
                                <th>24h Request Volume</th>
                                <th>Last Activity</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ip in stats.top_ips" :key="ip.ip">
                                <td>
                                    <strong style="font-family: var(--font-mono);">{{ ip.ip }}</strong>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border" style="font-family: var(--font-mono); font-size:0.80rem;">
                                        {{ ip.count.toLocaleString() }} hits
                                    </span>
                                </td>
                                <td style="font-family: var(--font-mono); font-size:0.75rem; color:var(--text-muted);">
                                    {{ formatTime(ip.last_seen) }}
                                </td>
                                <td>
                                    <span v-if="ip.is_banned" class="badge bg-danger text-white">BANNED</span>
                                    <span v-else class="badge bg-success text-white">ALLOWED</span>
                                </td>
                                <td class="text-end">
                                    <button class="hud-btn" @click="filterByIp(ip.ip)" style="padding:3px 8px; font-size:0.72rem;">
                                        <i class="fas fa-search"></i> Inspect
                                    </button>
                                    <button v-if="!ip.is_banned" class="hud-btn red-hover ms-1" @click="handleBanIp(ip.ip)" style="padding:3px 8px; font-size:0.72rem;">
                                        <i class="fas fa-ban text-danger"></i> Ban
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!stats.top_ips || stats.top_ips.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted">
                                    No IP activity recorded in the last 24 hours.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="activeTab === 'rate_limits'" class="content-card">
                <div class="card-header-bar">
                    <div class="card-title">
                        <i class="fas fa-tachometer-alt text-warning"></i>
                        <span>Active Rate Limits &amp; DDoS Throttles</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="station-table">
                        <thead>
                            <tr>
                                <th>Target IP</th>
                                <th>Hits Recorded</th>
                                <th>Threshold Limit</th>
                                <th>Window Resets In</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rl in activeRateLimits" :key="rl.ip">
                                <td style="font-family: var(--font-mono); font-weight:700;">{{ rl.ip }}</td>
                                <td><span class="badge bg-warning text-dark">{{ rl.hits }} hits</span></td>
                                <td>{{ rl.limit }} / window</td>
                                <td style="font-family: var(--font-mono); color:var(--text-muted);">{{ rl.ttl_seconds }}s remaining</td>
                                <td class="text-end">
                                    <button class="hud-btn" @click="handleClearRateLimit(rl.ip)" style="padding:3px 8px; font-size:0.72rem;">
                                        <i class="fas fa-check text-success"></i> Clear Throttle
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="activeRateLimits.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fas fa-shield-check fa-2x mb-2 d-block text-success"></i>
                                    Zero active throttles. Inbound rate limits operating normally.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="activeTab === 'banned'" class="content-card">
                <div class="card-header-bar">
                    <div class="card-title">
                        <i class="fas fa-shield-halved text-danger"></i>
                        <span>Active Firewall Blacklist</span>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <input type="text" v-model="newBan.ip" class="auth-form-input" placeholder="IP Address to ban..." style="padding:6px 12px; width:180px; font-size:0.82rem;">
                        <input type="text" v-model="newBan.reason" class="auth-form-input" placeholder="Reason..." style="padding:6px 12px; width:220px; font-size:0.82rem;">
                        <button class="hud-btn" @click="handleBanIp(newBan.ip, newBan.reason)" style="background:var(--accent-red); color:#fff; border-color:var(--accent-red);">
                            <i class="fas fa-plus"></i> Block IP
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="station-table">
                        <thead>
                            <tr>
                                <th>Blacklisted IP</th>
                                <th>Reason</th>
                                <th>Banned At</th>
                                <th>Expires</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ban in bannedIps" :key="ban.ip">
                                <td style="font-family: var(--font-mono); font-weight:700; color:var(--accent-red);">{{ ban.ip }}</td>
                                <td>{{ ban.reason }}</td>
                                <td style="font-family: var(--font-mono); font-size:0.75rem;">{{ formatDate(ban.banned_at) }}</td>
                                <td style="font-family: var(--font-mono); font-size:0.75rem;">{{ ban.expires_at ? formatDate(ban.expires_at) : 'Permanent' }}</td>
                                <td class="text-end">
                                    <button class="hud-btn" @click="handleUnbanIp(ban.ip)" style="padding:3px 8px; font-size:0.72rem;">
                                        <i class="fas fa-lock-open text-success"></i> Unban
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="bannedIps.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted">
                                    No IP addresses are currently blacklisted.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="activeTab === 'health'" class="content-card">
                <div class="card-header-bar">
                    <div class="card-title">
                        <i class="fas fa-server text-primary"></i>
                        <span>System Architecture &amp; Database Health</span>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <h6 class="fw-bold mb-3"><i class="fas fa-database me-2 text-success"></i>Database Engine</h6>
                            <table class="table table-sm table-borderless mb-0" style="font-size:0.82rem;">
                                <tr>
                                    <td class="text-muted">Driver:</td>
                                    <td><strong>MariaDB 10.x / MySQL PDO</strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Active Tables:</td>
                                    <td><strong>14 Relational InnoDB Tables</strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Connection Singleton:</td>
                                    <td><span class="badge bg-success">Database::getInstance() Active</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <h6 class="fw-bold mb-3"><i class="fas fa-shield-alt me-2 text-warning"></i>Security Invariants</h6>
                            <table class="table table-sm table-borderless mb-0" style="font-size:0.82rem;">
                                <tr>
                                    <td class="text-muted">Password Hashing:</td>
                                    <td><strong>Bcrypt (PASSWORD_DEFAULT)</strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Rate Limiting:</td>
                                    <td><span class="badge bg-success">10 Attempts / 5 Mins Armed</span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Session Isolation:</td>
                                    <td><strong>Single-Active 64-char Token Guard</strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="!isAuthenticated" class="auth-overlay">
            <div class="auth-card">
                <div class="auth-card-header">
                    <img src="../school-website/assets/images/logo-removebg-preview.png" alt="GNCP Seal" class="auth-card-logo" onerror="this.onerror=null;this.src='/school-website/assets/images/logo-removebg-preview.png';">
                    <div class="auth-card-title">
                        <h3>GNCP-SECURITY</h3>
                        <p>Security Operations Center &amp; Threat Radar</p>
                    </div>
                </div>

                <div class="auth-card-body">
                    <div v-if="authError" class="alert alert-danger py-2 px-3 mb-0" style="font-size:0.82rem;">
                        <i class="fas fa-exclamation-triangle me-2"></i>{{ authError }}
                    </div>

                    <form @submit.prevent="handleLogin">
                        <div class="auth-form-group mb-3">
                            <label><i class="fas fa-user-shield me-1"></i>Developer Handle / Username</label>
                            <div class="auth-input-wrapper">
                                <i class="fas fa-terminal prefix-icon"></i>
                                <input type="text" v-model="loginForm.username" class="auth-form-input" placeholder="e.g. developer" required autocomplete="username" autofocus>
                            </div>
                        </div>

                        <div class="auth-form-group mb-4">
                            <label><i class="fas fa-key me-1"></i>Security Key / Password</label>
                            <div class="auth-input-wrapper">
                                <i class="fas fa-lock prefix-icon"></i>
                                <input :type="showPassword ? 'text' : 'password'" v-model="loginForm.password" class="auth-form-input" placeholder="••••••••••••" required autocomplete="current-password">
                                <button type="button" class="auth-btn-eye" @click="showPassword = !showPassword">
                                    <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="auth-submit-btn w-100" :disabled="isLoggingIn">
                            <i v-if="isLoggingIn" class="fas fa-spinner fa-spin"></i>
                            <i v-else class="fas fa-shield-alt"></i>
                            <span>{{ isLoggingIn ? 'Verifying Security Clearance...' : 'Authenticate & Unlock Radar' }}</span>
                        </button>
                    </form>

                    <div class="auth-card-footer">
                        <span>Go-on National College of the Philippines</span>
                        <span class="auth-secure-tag"><i class="fas fa-lock"></i> TLS / SHA-256 Armed</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="assets/js/MonitorApp.js?v=1789204778"></script>
</body>
</html>
