const { createApp, ref, reactive, computed, onMounted } = Vue;

        createApp({
            setup() {
                const urlParams = new URLSearchParams(window.location.search);

                // Synchronously initialize currentUser if a valid session exists in sessionStorage
                let initialUser = null;
                if (!urlParams.has('clear') && !urlParams.has('logout') && !urlParams.has('session_expired')) {
                    const adminSession = sessionStorage.getItem('gncp_admin_user');
                    const stationSession = sessionStorage.getItem('gncp_station_user');
                    try {
                        if (adminSession) {
                            initialUser = JSON.parse(adminSession);
                        } else if (stationSession) {
                            initialUser = JSON.parse(stationSession);
                        }
                    } catch (e) {}
                }

                const currentUser = ref(initialUser);
                const loginError = ref('');
                const errorKey = ref(0);
                const isLoggingIn = ref(false);
                const loginStatusText = ref('Verifying...');
                const showPassword = ref(false);
                
                const hadPriorSession = !!(
                    sessionStorage.getItem('gncp_was_logged_in') ||
                    sessionStorage.getItem('gncp_station_user') ||
                    sessionStorage.getItem('gncp_admin_user') ||
                    localStorage.getItem('gncp_was_logged_in')
                );
                const hasExpiredQuery = (
                    urlParams.get('session_expired') === '1' ||
                    urlParams.get('expired') === '1' ||
                    urlParams.get('reason') === 'expired'
                );
                // Only show expiration notice if this browser actually had a previous session
                const sessionExpiredNotice = ref(hasExpiredQuery && hadPriorSession);

                // Clean the URL query params so copying the link or refreshing doesn't keep the expired flag
                if (hasExpiredQuery) {
                    try {
                        const cleanParams = new URLSearchParams(window.location.search);
                        cleanParams.delete('session_expired');
                        cleanParams.delete('expired');
                        cleanParams.delete('reason');
                        const newQuery = cleanParams.toString();
                        const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
                        window.history.replaceState({}, document.title, newUrl);
                    } catch (e) {}
                }

                const loginForm = reactive({
                    username: '',
                    password: ''
                });

                // Definitions for GNCP portals and stations
                const workstations = [
                    {
                        name: "Super Admin Console",
                        description: "Manage courses, offerings, and departments",
                        icon: "fas fa-shield-halved",
                        url: "admin/",
                        roles: ["SUPER_ADMIN", "ADMIN"]
                    },
                    {
                        name: "Registrar Dashboard",
                        description: "Evaluate applications and clean queues",
                        icon: "fas fa-folder-open",
                        url: "registrar/",
                        roles: ["SUPER_ADMIN", "ADMIN", "REGISTRAR"]
                    },
                    {
                        name: "Station 1: Advising & Evaluation",
                        description: "TLC helpdesk for unit evaluation and NSTP lock-in",
                        icon: "fas fa-chalkboard-user",
                        url: "stations/tlc-helpdesk/",
                        roles: ["SUPER_ADMIN", "ADMIN", "HELPDESK"]
                    },
                    {
                        name: "Station 2: Medical Clearance",
                        description: "School Clinic for fitness pre-screening checks",
                        icon: "fas fa-file-medical",
                        url: "stations/medical-checkup/",
                        roles: ["SUPER_ADMIN", "ADMIN", "MEDICAL"]
                    },
                    {
                        name: "Station 3: Payment Processing",
                        description: "Treasury window for processing downpayments",
                        icon: "fas fa-money-check-dollar",
                        url: "stations/payment-processing/",
                        roles: ["SUPER_ADMIN", "ADMIN", "CASHIER"]
                    },
                    {
                        name: "Station 5: Student Portal Account Activation",
                        description: "Activate permanent credentials and official student portal access",
                        icon: "fas fa-id-card",
                        url: "stations/it-center/",
                        roles: ["SUPER_ADMIN", "ADMIN", "IT_CENTER"]
                    }
                ];

                const getRoleRedirectUrl = (role) => {
                    const _bp = (window.location.pathname.match(/^\/([^\/]+)/) ? '/' + window.location.pathname.match(/^\/([^\/]+)/)[1] : '');
                    if (role === 'SUPER_ADMIN' || role === 'ADMIN') return _bp + '/admin/';
                    if (role === 'REGISTRAR') return _bp + '/registrar/';
                    if (role === 'HELPDESK') return _bp + '/stations/tlc-helpdesk/';
                    if (role === 'MEDICAL') return _bp + '/stations/medical-checkup/';
                    if (role === 'CASHIER') return _bp + '/stations/payment-processing/';
                    if (role === 'IT_CENTER') return _bp + '/stations/it-center/';
                    if (role === 'DEVELOPER') return _bp + '/monitoring/';
                    return _bp + '/admin/';
                };

                const roleAllowedWorkstations = {
                    'SUPER_ADMIN': ['admin', 'registrar', 'stations/tlc-helpdesk', 'stations/medical-checkup', 'stations/payment-processing', 'stations/it-center', 'monitoring'],
                    'ADMIN':       ['admin', 'registrar', 'stations/tlc-helpdesk', 'stations/medical-checkup', 'stations/payment-processing', 'stations/it-center', 'monitoring'],
                    'REGISTRAR':   ['registrar'],
                    'HELPDESK':    ['stations/tlc-helpdesk'],
                    'MEDICAL':     ['stations/medical-checkup'],
                    'CASHIER':     ['stations/payment-processing'],
                    'IT_CENTER':   ['stations/it-center'],
                    'DEVELOPER':   ['monitoring']
                };

                const resolveTarget = (role, redirectParam) => {
                    const defaultUrl = getRoleRedirectUrl(role);
                    if (!redirectParam) return defaultUrl;
                    let clean = decodeURIComponent(redirectParam).trim();
                    clean = clean.replace(/^https?:\/\/[^\/]+/i, '');
                    clean = clean.replace(/^\/?[^\/]+\/(admin|registrar|stations|monitoring)/i, '$1');
                    clean = clean.replace(/^\/+/, '');
                    const cleanPath = clean.split('?')[0].replace(/\/index\.html$/i, '').replace(/\/+$/, '');
                    const allowed = roleAllowedWorkstations[role] || [];
                    if (allowed.some(p => cleanPath.toLowerCase().endsWith(p.toLowerCase()) || cleanPath.toLowerCase() === p.toLowerCase())) {
                        const _bp = (window.location.pathname.match(/^\/([^\/]+)/) ? '/' + window.location.pathname.match(/^\/([^\/]+)/)[1] : '');
                        return _bp + '/' + clean;
                    }
                    return defaultUrl;
                };

                const checkActiveSession = () => {
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('clear') || urlParams.has('logout')) {
                        sessionStorage.removeItem('gncp_admin_user');
                        sessionStorage.removeItem('gncp_station_user');
                        localStorage.removeItem('gncp_admin_user');
                        localStorage.removeItem('gncp_station_user');
                        currentUser.value = null;
                        return;
                    }

                    const adminSession = sessionStorage.getItem('gncp_admin_user');
                    const stationSession = sessionStorage.getItem('gncp_station_user');
                    
                    let user = null;
                    try {
                        if (adminSession) {
                            user = JSON.parse(adminSession);
                        } else if (stationSession) {
                            user = JSON.parse(stationSession);
                        }
                    } catch (e) {
                        sessionStorage.removeItem('gncp_admin_user');
                        sessionStorage.removeItem('gncp_station_user');
                    }

                    if (user && user.role) {
                        currentUser.value = user;
                        const target = resolveTarget(user.role, urlParams.get('redirect')) || getRoleRedirectUrl(user.role) || 'admin/';
                        if (user.must_change_password && typeof PasswordChangeGuard !== 'undefined' && typeof PasswordChangeGuard.checkAndPrompt === 'function') {
                            PasswordChangeGuard.checkAndPrompt(user, function() {
                                window.location.replace(target);
                            });
                        } else {
                            window.location.replace(target);
                        }
                    }
                };

                const isAuthorized = (allowedRoles) => {
                    if (!currentUser.value) return false;
                    return allowedRoles.includes(currentUser.value.role);
                };

                // Filter workstations so users only see their authorized tools
                const filteredWorkstations = computed(() => {
                    if (!currentUser.value) return [];
                    return workstations.filter(w => isAuthorized(w.roles));
                });

                const handleLogin = () => {
                    if (isLoggingIn.value) return;
                    isLoggingIn.value = true;
                    loginStatusText.value = 'Verifying credentials...';

                    fetch('shared/backend/login.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(loginForm)
                    })
                    .then(res => res.json())
                    .then(result => {
                        if (result.success && result.data) {
                            isLoggingIn.value = true;
                            loginStatusText.value = 'Access Granted! Launching...';
                            const user = {
                                username: result.data.username,
                                name: result.data.name,
                                role: result.data.role,
                                must_change_password: !!result.data.must_change_password
                            };

                            // Store session in sessionStorage only (tab-scoped) and clear any local storage
                            localStorage.removeItem('gncp_admin_user');
                            localStorage.removeItem('gncp_station_user');
                            sessionStorage.setItem('gncp_was_logged_in', '1');
                            if (user.role === 'SUPER_ADMIN' || user.role === 'ADMIN') {
                                sessionStorage.setItem('gncp_admin_user', JSON.stringify(user));
                            } else {
                                sessionStorage.setItem('gncp_station_user', JSON.stringify(user));
                            }

                            const urlParams = new URLSearchParams(window.location.search);
                            const target = resolveTarget(user.role, urlParams.get('redirect')) || result.data.redirectUrl || getRoleRedirectUrl(user.role) || 'admin/';

                            if (user.must_change_password && typeof PasswordChangeGuard !== 'undefined' && typeof PasswordChangeGuard.checkAndPrompt === 'function') {
                                PasswordChangeGuard.checkAndPrompt(user, function() {
                                    window.location.replace(target);
                                });
                            } else {
                                window.location.replace(target);
                            }
                        } else {
                            isLoggingIn.value = false;
                            errorKey.value++;
                            loginError.value = result.error || 'Authentication failed. Please check your credentials.';
                        }
                    })
                    .catch(err => {
                        isLoggingIn.value = false;
                        errorKey.value++;
                        loginError.value = 'Failed to connect to the login portal server. Please check your connection.';
                        console.error('Login error:', err);
                    });
                };

                const handleSignOut = () => {
                    sessionStorage.removeItem('gncp_admin_user');
                    sessionStorage.removeItem('gncp_station_user');
                    currentUser.value = null;
                };

                onMounted(() => {
                    checkActiveSession();
                });

                return {
                    loginForm,
                    loginError,
                    sessionExpiredNotice,
                    errorKey,
                    isLoggingIn,
                    loginStatusText,
                    showPassword,
                    currentUser,
                    workstations,
                    filteredWorkstations,
                    isAuthorized,
                    handleLogin,
                    handleSignOut
                };
            }
        }).mount('#app');
