/**
 * Student Portal - API Service
 * Centralized AJAX HTTP service with X-Request-ID tracking and debugging channels.
 */

window.StudentApiService = {
    /**
     * Generate unique client-side request ID for telemetry tracing
     */
    generateRequestId() {
        return 'std_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
    },

    /**
     * Core fetch wrapper with structured headers & error handling
     */
    async request(action, options = {}) {
        const reqId = this.generateRequestId();
        const url = action.includes('&') || action.includes('=')
            ? `backend/api.php?action=${action}`
            : `backend/api.php?action=${encodeURIComponent(action)}`;
        const headers = {
            'Content-Type': 'application/json',
            'X-Request-ID': reqId,
            ...(options.headers || {})
        };

        console.log(`[StudentPortal::API] 🚀 Requesting [${action}] (ID: ${reqId})`, options.body ? JSON.parse(options.body) : '');

        try {
            const response = await fetch(url, {
                method: options.method || 'GET',
                headers,
                body: options.body || null
            });

            const responseReqId = response.headers.get('X-Request-ID') || reqId;
            const data = await response.json();

            if (response.status === 401 && action !== 'login' && action !== 'student_login') {
                sessionStorage.removeItem('gncp_portal_student');
                localStorage.removeItem('gncp_portal_student');
                const isInvalidated = !!(data && (data.session_invalidated || (data.data && data.data.session_invalidated)));
                const param = isInvalidated ? 'session_invalidated=1&reason=superseded' : 'session_expired=1&reason=expired';
                if (!window.location.pathname.includes('login.html')) {
                    window.location.href = `login.html?clear=true&${param}`;
                }
                return {
                    success: false,
                    message: data.message || 'Session invalidated. Redirecting to login...',
                    code: 401,
                    session_invalidated: isInvalidated,
                    requestId: responseReqId,
                    data: null
                };
            }

            if (!response.ok || !data.success) {
                console.warn(`[StudentPortal::API] ⚠️ API returned error status for [${action}] (ID: ${responseReqId}):`, data);
                return {
                    success: false,
                    message: data.message || data.error || 'Server error processing request.',
                    requestId: responseReqId,
                    data: null
                };
            }

            console.log(`[StudentPortal::API] ✅ Response received for [${action}] (ID: ${responseReqId}):`, data);
            return {
                success: true,
                data: data.data,
                message: data.message || 'Success',
                requestId: responseReqId
            };
        } catch (error) {
            console.error(`[StudentPortal::API] ❌ Network/Server exception on [${action}] (ID: ${reqId}):`, error);
            return {
                success: false,
                message: 'Failed to connect to student portal server. Please check network connection.',
                requestId: reqId,
                data: null
            };
        }
    },

    /**
     * Authenticate student credentials
     */
    async login(studentId, password) {
        return this.request('login_student', {
            method: 'POST',
            body: JSON.stringify({ studentId, password })
        });
    },

    /**
     * Fetch complete dashboard dataset for student ID
     */
    async fetchDashboard(studentId) {
        return this.request(`get_student_dashboard&studentId=${encodeURIComponent(studentId)}`, {
            method: 'GET'
        });
    },

    /**
     * Update student personal information details
     */
    async updateProfile(studentId, profilePayload) {
        return this.request('update_student_profile', {
            method: 'POST',
            body: JSON.stringify({
                studentId,
                ...profilePayload
            })
        });
    },

    /**
     * Upload portrait photo base64
     */
    async uploadPhoto(studentId, base64PhotoData) {
        return this.request('update_student_profile', {
            method: 'POST',
            body: JSON.stringify({
                studentId,
                photoData: base64PhotoData
            })
        });
    },

    /**
     * Change student portal account password
     */
    async changePassword(studentId, currentPassword, newPassword) {
        return this.request('change_student_password', {
            method: 'POST',
            body: JSON.stringify({
                studentId,
                currentPassword,
                newPassword
            })
        });
    },

    /**
     * Fetch document requirements and undertaking compliance status
     */
    async fetchDocuments(studentId, pin = '') {
        const pinParam = pin ? `&pin=${encodeURIComponent(pin)}` : '';
        return this.request(`get_student_documents&studentId=${encodeURIComponent(studentId)}${pinParam}`, {
            method: 'GET'
        });
    },

    /**
     * Upload document soft copy or undertaking form
     */
    async uploadDocument(payload) {
        return this.request('upload_student_document', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
    },

    /**
     * Terminate student session
     */
    async logout() {
        return this.request('logout', { method: 'POST' });
    },

    /**
     * Request 6-digit password reset code via SMTP email
     */
    async requestPasswordReset(identifier) {
        return this.request('request_password_reset', {
            method: 'POST',
            body: JSON.stringify({ identifier })
        });
    },

    /**
     * Submit 6-digit verification code & new password
     */
    async resetPasswordWithCode(identifier, code, newPassword) {
        return this.request('reset_password_with_code', {
            method: 'POST',
            body: JSON.stringify({ identifier, code, newPassword })
        });
    }
};
