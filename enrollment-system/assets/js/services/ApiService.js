(function (global) {
    function createResponse(success, data = null, error = null, meta = {}) {
        return { success, data, error, meta, timestamp: new Date().toISOString() };
    }
    const ApiService = {
        async submitEnrollment(formData) {
            try {
                const isFormData = typeof FormData !== 'undefined' && formData instanceof FormData;
                const fetchOptions = {
                    method: 'POST',
                    body: isFormData ? formData : JSON.stringify(formData)
                };
                if (!isFormData) {
                    fetchOptions.headers = { 'Content-Type': 'application/json' };
                }
                const response = await fetch('backend/register.php', fetchOptions);
                if (!response.ok) {
                    let errorMsg = `Server error (HTTP ${response.status}).`;
                    try {
                        const errBody = await response.json();
                        errorMsg = errBody.error || errorMsg;
                    } catch (_) {}
                    return createResponse(false, null, errorMsg);
                }
                return await response.json();
            } catch (err) {
                console.error('[ApiService] Registration request failed:', err);
                return createResponse(false, null, 'Registration failed. Server unreachable.');
            }
        },
        async getEnrollment(tempStudentId, tempPin) {
            try {
                const response = await fetch(`backend/track.php?id=${encodeURIComponent(tempStudentId)}&pin=${encodeURIComponent(tempPin)}`);
                if (!response.ok) {
                    let errorMsg = `Server error (HTTP ${response.status}).`;
                    try {
                        const errBody = await response.json();
                        errorMsg = errBody.error || errorMsg;
                    } catch (_) {}
                    return createResponse(false, null, errorMsg);
                }
                return await response.json();
            } catch (err) {
                console.error('[ApiService] Tracking request failed:', err);
                return createResponse(false, null, 'Tracking failed. Server unreachable.');
            }
        },
        async lookupReturningStudent(identifier) {
            try {
                const url = `backend/register.php?action=lookup_returning_student&identifier=${encodeURIComponent(identifier)}`;
                const response = await fetch(url);
                if (!response.ok) {
                    let errorMsg = `Server error (HTTP ${response.status}).`;
                    try {
                        const errBody = await response.json();
                        errorMsg = errBody.error || errorMsg;
                    } catch (_) {}
                    return createResponse(false, null, errorMsg);
                }
                return await response.json();
            } catch (err) {
                console.error('[ApiService] Returning student lookup failed:', err);
                return createResponse(false, null, 'Lookup failed. Server unreachable.');
            }
        },
        async getActivePrograms() {
            try {
                const response = await fetch('backend/register.php?action=get_active_programs');
                if (!response.ok) {
                    let errorMsg = `Server error (HTTP ${response.status}).`;
                    try {
                        const errBody = await response.json();
                        errorMsg = errBody.error || errorMsg;
                    } catch (_) {}
                    return createResponse(false, null, errorMsg);
                }
                return await response.json();
            } catch (err) {
                console.error('[ApiService] Fetch programs request failed:', err);
                return createResponse(false, null, 'Fetch programs failed. Server unreachable.');
            }
        }
    };
    global.ApiService = ApiService;
})(window);
