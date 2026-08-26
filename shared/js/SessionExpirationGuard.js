/**
 * ==========================================================================
 * GNCP Academic System — Session Expiration & Inactivity Guard
 * Standardized session timeout notification and graceful redirect across all stations.
 * ==========================================================================
 */
(function(window) {
    'use strict';

    const SessionExpirationGuard = {
        _isNotifying: false,

        /**
         * Resolves the relative path to the root login portal (index.html).
         */
        getLoginRedirectUrl(reason = null) {
            const pathname = window.location.pathname;
            let prefix = '';
            if (pathname.includes('/stations/')) {
                prefix = '../../';
            } else if (pathname.includes('/registrar/') || pathname.includes('/admin/')) {
                prefix = '../';
            } else {
                prefix = './';
            }

            const redirectTarget = encodeURIComponent(window.location.pathname + window.location.search);
            if (reason) {
                return `${prefix}?session_expired=1&reason=${encodeURIComponent(reason)}&redirect=${redirectTarget}`;
            }
            return `${prefix}?redirect=${redirectTarget}`;
        },

        /**
         * Checks if the client had a prior session in this browser tab/window.
         */
        hasPriorSession() {
            try {
                return !!(sessionStorage.getItem('gncp_station_user') || 
                          sessionStorage.getItem('gncp_admin_user') ||
                          sessionStorage.getItem('gncp_was_logged_in') ||
                          localStorage.getItem('gncp_station_user') ||
                          localStorage.getItem('gncp_admin_user') ||
                          localStorage.getItem('gncp_was_logged_in'));
            } catch (e) {
                return false;
            }
        },

        /**
         * Notifies user that session is expired and redirects to login portal.
         * If the user was NEVER logged in (e.g. freshly opened in new browser),
         * it redirects immediately and silently to login without showing an expired alert.
         * @param {Object} options
         * @param {string} [options.title='Session Expired']
         * @param {string} [options.message]
         * @param {string} [options.reason='expired']
         * @param {number} [options.timer=4000]
         * @param {boolean} [options.forceAlert=false]
         */
        handleExpiredSession(options = {}) {
            if (this._isNotifying) return;

            const hadSession = options.forceAlert || this.hasPriorSession();

            // Clear active user credentials
            try {
                sessionStorage.removeItem('gncp_station_user');
                sessionStorage.removeItem('gncp_admin_user');
                localStorage.removeItem('gncp_station_user');
                localStorage.removeItem('gncp_admin_user');
            } catch (e) {}

            // If user never had a session in this tab/browser, redirect immediately and silently
            if (!hadSession) {
                window.location.replace(this.getLoginRedirectUrl(null));
                return;
            }

            this._isNotifying = true;

            // User had a prior session that expired: show informative alert
            const title = options.title || 'Session Expired';
            const message = options.message || 'Your workstation session has timed out or expired. Please sign in again to continue.';
            const redirectUrl = this.getLoginRedirectUrl(options.reason || 'expired');

            if (typeof window.Swal !== 'undefined') {
                window.Swal.fire({
                    title: title,
                    text: message,
                    icon: 'warning',
                    confirmButtonText: '<i class="fa-solid fa-right-to-bracket me-1"></i> Sign In Again',
                    confirmButtonColor: '#006A4E',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    timer: options.timer || 4000,
                    timerProgressBar: true
                }).then(() => {
                    window.location.href = redirectUrl;
                });

                // Fail-safe redirect timer in case alert fails
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, (options.timer || 4000) + 1000);
            } else {
                window.location.href = redirectUrl;
            }
        }
    };

    window.SessionExpirationGuard = SessionExpirationGuard;
})(window);
