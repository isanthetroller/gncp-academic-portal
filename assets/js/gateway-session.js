(function() {
            try {
                // Ensure session authentication is never persisted across tabs or browsers in localStorage
                localStorage.removeItem('gncp_admin_user');
                localStorage.removeItem('gncp_station_user');

                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('clear') || urlParams.has('logout') || urlParams.has('session_expired')) {
                    sessionStorage.removeItem('gncp_admin_user');
                    sessionStorage.removeItem('gncp_station_user');
                    return;
                }

                // If user has active session, start navigation immediately before page paint
                const adminSession = sessionStorage.getItem('gncp_admin_user');
                const stationSession = sessionStorage.getItem('gncp_station_user');
                let user = null;
                if (adminSession) {
                    user = JSON.parse(adminSession);
                } else if (stationSession) {
                    user = JSON.parse(stationSession);
                }

                if (user && user.role && !user.must_change_password) {
                    const _bp = (window.location.pathname.match(/^\/([^\/]+)/) ? '/' + window.location.pathname.match(/^\/([^\/]+)/)[1] : '');
                    const roleRedirects = {
                        'SUPER_ADMIN': _bp + '/admin/',
                        'ADMIN':       _bp + '/admin/',
                        'REGISTRAR':   _bp + '/registrar/',
                        'HELPDESK':    _bp + '/stations/tlc-helpdesk/',
                        'MEDICAL':     _bp + '/stations/medical-checkup/',
                        'CASHIER':     _bp + '/stations/payment-processing/',
                        'IT_CENTER':   _bp + '/stations/it-center/',
                        'DEVELOPER':   _bp + '/monitoring/'
                    };
                    const target = roleRedirects[user.role] || (_bp + '/admin/');
                    if (target) {
                        window.location.replace(target);
                    }
                }
            } catch (e) {
                sessionStorage.removeItem('gncp_admin_user');
                sessionStorage.removeItem('gncp_station_user');
                localStorage.removeItem('gncp_admin_user');
                localStorage.removeItem('gncp_station_user');
            }
        })();
