const { createApp, ref, computed, onMounted, onUnmounted, watch } = Vue;
createApp({
    setup() {
        const activeTab = ref('live');
        const isPolling = ref(true);
        const pollingInterval = ref(3); 
        let timer = null;
        const stats = ref({
            total_requests_24h: 0,
            total_requests_today: 0,
            unique_ips_24h: 0,
            rate_limit_hits_24h: 0,
            server_errors_24h: 0,
            client_errors_24h: 0,
            banned_ips_count: 0,
            top_ips: [],
            top_endpoints: [],
            status_distribution: { '2xx': 0, '3xx': 0, '4xx': 0, '429': 0, '5xx': 0 },
            hourly_timeline: []
        });
        const requests = ref([]);
        const activeRateLimits = ref([]);
        const bannedIps = ref([]);
        const systemHealth = ref({});
        const isLoading = ref(false);
        const searchQuery = ref('');
        const statusFilter = ref('');
        const ipFilter = ref('');
        const newBan = ref({
            ip: '',
            reason: 'Excessive Automated Requests / DDoS Flag',
            duration: 0
        });
        const currentUser = ref(null);
        const isAuthenticated = ref(false);
        const isLoggingIn = ref(false);
        const authError = ref('');
        const showPassword = ref(false);
        const loginForm = ref({
            username: 'developer',
            password: ''
        });
        const checkAuth = () => {
            const adminSession = sessionStorage.getItem('gncp_admin_user');
            if (adminSession) {
                try {
                    const parsed = JSON.parse(adminSession);
                    if (parsed && ['DEVELOPER', 'SUPER_ADMIN', 'ADMIN'].includes(parsed.role)) {
                        currentUser.value = parsed;
                        isAuthenticated.value = true;
                        return true;
                    }
                } catch (e) {}
            }
            isAuthenticated.value = false;
            return false;
        };
        const handleLogin = async () => {
            if (!loginForm.value.username || !loginForm.value.password) {
                authError.value = 'Please provide both developer username and password.';
                return;
            }
            isLoggingIn.value = true;
            authError.value = '';
            try {
                const res = await fetch('../shared/backend/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({
                        username: loginForm.value.username.trim(),
                        password: loginForm.value.password
                    })
                });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Developer authentication failed.');
                }
                const userData = json.data || json.user || {};
                if (!['DEVELOPER', 'SUPER_ADMIN', 'ADMIN'].includes(userData.role)) {
                    throw new Error('Access Denied: Account role (' + (userData.role || 'GUEST') + ') lacks developer security clearance.');
                }
                sessionStorage.setItem('gncp_admin_user', JSON.stringify(userData));
                currentUser.value = userData;
                isAuthenticated.value = true;
                loginForm.value.password = '';
                authError.value = '';
                refreshData();
                startPolling();
                if (window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Developer SOC Access Granted',
                        text: 'Live security radar connected.',
                        showConfirmButton: false,
                        timer: 2500,
                        background: '#101522',
                        color: '#f0f4f8'
                    });
                }
            } catch (err) {
                console.error('[Monitor] Login error:', err);
                authError.value = err.message || 'Security verification failed. Please try again.';
            } finally {
                isLoggingIn.value = false;
            }
        };
        const logout = async () => {
            stopPolling();
            try {
                await fetch('../shared/backend/login.php?action=logout', { credentials: 'include' });
            } catch (e) {}
            sessionStorage.removeItem('gncp_admin_user');
            currentUser.value = null;
            isAuthenticated.value = false;
            loginForm.value.password = '';
        };
        const fetchStats = async () => {
            try {
                const res = await fetch('../api/index.php?action=monitoring/stats', { credentials: 'include' });
                const json = await res.json();
                if (json.success && json.data) {
                    stats.value = json.data;
                }
            } catch (e) {
                console.error('[Monitor] Stats fetch failed:', e);
            }
        };
        const fetchRequests = async () => {
            try {
                let url = `../api/index.php?action=monitoring/requests&limit=150`;
                if (ipFilter.value) url += `&ip=${encodeURIComponent(ipFilter.value)}`;
                if (statusFilter.value) url += `&status=${encodeURIComponent(statusFilter.value)}`;
                if (searchQuery.value) url += `&search=${encodeURIComponent(searchQuery.value)}`;
                const res = await fetch(url, { credentials: 'include' });
                const json = await res.json();
                if (json.success && json.data) {
                    requests.value = json.data;
                }
            } catch (e) {
                console.error('[Monitor] Requests fetch failed:', e);
            }
        };
        const fetchRateLimits = async () => {
            try {
                const res = await fetch('../api/index.php?action=monitoring/rate_limits', { credentials: 'include' });
                const json = await res.json();
                if (json.success && json.data) {
                    activeRateLimits.value = json.data;
                }
            } catch (e) {
                console.error('[Monitor] Rate limits fetch failed:', e);
            }
        };
        const fetchBannedIps = async () => {
            try {
                const res = await fetch('../api/index.php?action=monitoring/banned_ips', { credentials: 'include' });
                const json = await res.json();
                if (json.success && json.data) {
                    bannedIps.value = json.data;
                }
            } catch (e) {
                console.error('[Monitor] Banned IPs fetch failed:', e);
            }
        };
        const fetchSystemHealth = async () => {
            try {
                const res = await fetch('../api/index.php?action=monitoring/system_health', { credentials: 'include' });
                const json = await res.json();
                if (json.success && json.data) {
                    systemHealth.value = json.data;
                }
            } catch (e) {
                console.error('[Monitor] Health fetch failed:', e);
            }
        };
        const refreshData = async () => {
            await fetchStats();
            if (activeTab.value === 'live') {
                await fetchRequests();
            } else if (activeTab.value === 'ratelimits') {
                await fetchRateLimits();
            } else if (activeTab.value === 'firewall') {
                await fetchBannedIps();
            } else if (activeTab.value === 'health') {
                await fetchSystemHealth();
            }
        };
        const startPolling = () => {
            stopPolling();
            if (isPolling.value && pollingInterval.value > 0) {
                timer = setInterval(() => {
                    refreshData();
                }, pollingInterval.value * 1000);
            }
        };
        const stopPolling = () => {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        };
        const togglePolling = () => {
            isPolling.value = !isPolling.value;
            if (isPolling.value) {
                startPolling();
                refreshData();
            } else {
                stopPolling();
            }
        };
        const handleBanIp = async (ipToBan, defaultReason = '') => {
            const { value: formValues } = await Swal.fire({
                title: 'Confirm IP Firewall Ban',
                html: `
                    <div style="text-align:left; font-size:0.85rem; color:#ddd;">
                        <p style="margin-bottom:8px;">Block IP address from all website endpoints and APIs:</p>
                        <input id="swal-ban-ip" class="swal2-input" value="${ipToBan || ''}" placeholder="IP Address (e.g. 192.168.1.1)" style="margin:0 0 12px; width:100%; color:#fff; background:#1e293b; border-color:#475569;">
                        <input id="swal-ban-reason" class="swal2-input" value="${defaultReason || 'Suspicious Bot Activity / DDoS'}" placeholder="Reason for Ban" style="margin:0 0 12px; width:100%; color:#fff; background:#1e293b; border-color:#475569;">
                        <select id="swal-ban-duration" class="swal2-select" style="margin:0; width:100%; color:#fff; background:#1e293b; border-color:#475569;">
                            <option value="0">Permanent Ban (Until manually lifted)</option>
                            <option value="3600">1 Hour Block</option>
                            <option value="86400">24 Hours Block</option>
                            <option value="604800">7 Days Block</option>
                        </select>
                    </div>
                `,
                background: '#0f172a',
                color: '#fff',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#334155',
                confirmButtonText: 'Enforce Ban',
                preConfirm: () => {
                    return {
                        ip: document.getElementById('swal-ban-ip').value.trim(),
                        reason: document.getElementById('swal-ban-reason').value.trim(),
                        duration: parseInt(document.getElementById('swal-ban-duration').value, 10)
                    };
                }
            });
            if (!formValues || !formValues.ip) return;
            try {
                const res = await fetch('../api/index.php?action=monitoring/ban_ip', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(formValues)
                });
                const json = await res.json();
                if (json.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'IP Blocked',
                        text: json.message,
                        timer: 2000,
                        showConfirmButton: false,
                        background: '#0f172a',
                        color: '#fff'
                    });
                    await fetchBannedIps();
                    await fetchStats();
                } else {
                    Swal.fire({ icon: 'error', title: 'Action Failed', text: json.message, background: '#0f172a', color: '#fff' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Network Error', text: e.message, background: '#0f172a', color: '#fff' });
            }
        };
        const handleUnbanIp = async (ipToUnban) => {
            const confirm = await Swal.fire({
                title: 'Lift IP Ban?',
                text: `Restore normal website and API access for ${ipToUnban}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#00e699',
                cancelButtonColor: '#334155',
                confirmButtonText: 'Lift Ban',
                background: '#0f172a',
                color: '#fff'
            });
            if (!confirm.isConfirmed) return;
            try {
                const res = await fetch('../api/index.php?action=monitoring/unban_ip', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ ip: ipToUnban })
                });
                const json = await res.json();
                if (json.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Ban Lifted',
                        text: json.message,
                        timer: 1500,
                        showConfirmButton: false,
                        background: '#0f172a',
                        color: '#fff'
                    });
                    await fetchBannedIps();
                    await fetchStats();
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: '#0f172a', color: '#fff' });
            }
        };
        const handleClearRateLimit = async (key = 'ALL') => {
            const confirm = await Swal.fire({
                title: key === 'ALL' ? 'Clear ALL Rate Limits?' : 'Reset Rate Limit Bucket?',
                text: key === 'ALL' ? 'This will reset all active IP lockouts and request throttling counters.' : `Reset rate limit counters for ${key}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#00d2ff',
                cancelButtonColor: '#334155',
                confirmButtonText: 'Reset Now',
                background: '#0f172a',
                color: '#fff'
            });
            if (!confirm.isConfirmed) return;
            try {
                const res = await fetch('../api/index.php?action=monitoring/clear_rate_limit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ key })
                });
                const json = await res.json();
                if (json.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Reset Completed',
                        text: json.message,
                        timer: 1500,
                        showConfirmButton: false,
                        background: '#0f172a',
                        color: '#fff'
                    });
                    await fetchRateLimits();
                    await fetchStats();
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: '#0f172a', color: '#fff' });
            }
        };
        const filterByIp = (targetIp) => {
            ipFilter.value = targetIp;
            activeTab.value = 'live';
            fetchRequests();
        };
        const clearFilters = () => {
            searchQuery.value = '';
            statusFilter.value = '';
            ipFilter.value = '';
            fetchRequests();
        };
        const setTab = (tab) => {
            activeTab.value = tab;
            refreshData();
        };
        const getStatusClass = (code) => {
            const s = parseInt(code, 10);
            if (s === 429) return 'status-429';
            if (s >= 500) return 'status-5xx';
            if (s >= 400) return 'status-4xx';
            if (s >= 300) return 'status-3xx';
            return 'status-2xx';
        };
        const getMethodClass = (m) => {
            const method = (m || 'GET').toLowerCase();
            return `badge-method ${method}`;
        };
        const formatTime = (timestamp) => {
            if (!timestamp) return '—';
            const date = new Date(timestamp * 1000);
            return date.toLocaleTimeString();
        };
        const formatDate = (timestamp) => {
            if (!timestamp) return '—';
            const date = new Date(timestamp * 1000);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        };
        watch(pollingInterval, () => {
            startPolling();
        });
        onMounted(() => {
            if (checkAuth()) {
                refreshData();
                startPolling();
            }
        });
        onUnmounted(() => {
            stopPolling();
        });
        const greeting = computed(() => {
            const hour = new Date().getHours();
            const name = currentUser.value ? (currentUser.value.name || currentUser.value.username) : 'Developer';
            if (hour < 12) return `Hello ${name}! Great Morning`;
            if (hour < 18) return `Hello ${name}! Great Afternoon`;
            return `Hello ${name}! Great Evening`;
        });
        return {
            activeTab,
            isPolling,
            pollingInterval,
            stats,
            requests,
            activeRateLimits,
            bannedIps,
            systemHealth,
            isLoading,
            searchQuery,
            statusFilter,
            ipFilter,
            newBan,
            currentUser,
            isAuthenticated,
            isLoggingIn,
            authError,
            showPassword,
            loginForm,
            greeting,
            handleLogin,
            logout,
            setTab,
            togglePolling,
            refreshData,
            handleBanIp,
            handleUnbanIp,
            handleClearRateLimit,
            filterByIp,
            clearFilters,
            getStatusClass,
            getMethodClass,
            formatTime,
            formatDate
        };
    }
}).mount('#app');
