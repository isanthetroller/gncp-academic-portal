/**
 * ================================================================
 *  GNCP Enrollment Stations — Shared Data Bus  v3.0
 *  - Single source of truth: REST API gateway (api/index.php)
 *  - Delta-only updates: sends only changed fields per station
 *  - Exponential backoff: adapts polling interval on failures
 * ================================================================
 */

const STORAGE_KEY = 'gncp_enrollment_queue';

class StationDataBus {
    static _syncing = false;
    static _consecutiveFailures = 0;
    static _pollTimer = null;
    static _lastEtag = null;
    static _memoryQueue = null;

    static getApiUrl(action) {
        let basePath = '';
        if (typeof window !== 'undefined' && window.location && window.location.pathname) {
            const m = window.location.pathname.match(/^\/([^\/]+)/);
            if (m && ['systemtest', 'systemtest-hardened', 'gncp-hardened', 'systemforsia'].includes(m[1].toLowerCase())) {
                basePath = '/' + m[1];
            }
        }
        return `${basePath}/api/index.php?action=${action}`;
    }

    static getQueue() {
        this.syncWithBackend();
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) return JSON.parse(raw);
        } catch (e) {
            console.warn('[DataBus] LocalStorage read failed; using in-memory queue fallback:', e);
        }
        return this._memoryQueue || [];
    }

    static saveQueue(queue) {
        this._memoryQueue = queue;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(queue));
        } catch (e) {
            console.warn('[DataBus] LocalStorage write failed (quota exceeded or storage disabled); retained in memory:', e);
        }
    }

    static getStudentByRef(refNo) {
        return this.getQueue().find(s => s.referenceNumber === refNo) || null;
    }

    /**
     * Updates a student record locally and syncs the delta to the backend.
     * @param {string} refNo - The student reference number.
     * @param {function} updateFn - Callback that mutates the student object.
     * @param {string[]} [deltaKeys] - If provided, only these keys are sent to the backend.
     *   Examples: ['medical','roadmap'], ['payment','roadmap'], ['enrollment','roadmap','status']
     *   If omitted, the full student object is sent (legacy fallback).
     */
    static updateStudent(refNo, updateFn, deltaKeys = null) {
        const queue = this.getQueue();
        const idx   = queue.findIndex(s => s.referenceNumber === refNo);
        if (idx === -1) {
            console.warn(`[DataBus] Student not found: ${refNo}`);
            return null;
        }
        updateFn(queue[idx]);
        this.saveQueue(queue);

        this.sendUpdateToBackend(refNo, queue[idx], deltaKeys);
        return queue[idx];
    }

    static async syncWithBackend() {
        if (this._syncing) return;
        this._syncing = true;

        try {
            const headers = {};
            if (this._lastEtag) {
                headers['If-None-Match'] = this._lastEtag;
            }

            const response = await fetch(this.getApiUrl('stations/queue'), { headers, credentials: 'same-origin' });

            if (response.status === 401) {
                // Session expired or superseded by another login
                StationDataBus.stopPolling();
                const result = await response.json().catch(() => ({}));
                console.warn('[DataBus] 401 Session Terminated:', result.message || 'Unauthorized');
                if (typeof window.SessionExpirationGuard !== 'undefined') {
                    window.SessionExpirationGuard.handleExpiredSession({
                        title: 'Session Expired',
                        message: result.message || 'Your workstation session has expired. Please sign in again to continue.',
                        reason: 'expired',
                        forceAlert: true
                    });
                } else {
                    const isStation = window.location.pathname.includes('/stations/');
                    const isRegistrar = window.location.pathname.includes('/registrar/');
                    const redirectTarget = (isStation ? '../../' : (isRegistrar ? '../' : './')) + '?session_expired=1';
                    if (!window.location.href.includes('session_expired=1')) {
                        window.location.href = redirectTarget;
                    }
                }
                return;
            }

            if (response.status === 304) {
                // Queue has not changed — skip JSON decoding and localStorage write
                StationDataBus._consecutiveFailures = 0;
                return;
            }

            if (response.ok) {
                const etagHeader = response.headers.get('ETag');
                if (etagHeader) {
                    this._lastEtag = etagHeader;
                }
                const result = await response.json();
                if (result.success && Array.isArray(result.data)) {
                    this._memoryQueue = result.data;
                    const dbRaw = JSON.stringify(result.data);
                    let hasChanged = true;
                    try {
                        const localRaw = localStorage.getItem(STORAGE_KEY);
                        if (localRaw === dbRaw) {
                            hasChanged = false;
                        } else {
                            localStorage.setItem(STORAGE_KEY, dbRaw);
                        }
                    } catch (e) {
                        console.warn('[DataBus] LocalStorage update bypassed; synchronized in memory:', e);
                    }
                    if (hasChanged) {
                        window.dispatchEvent(new Event('storage'));
                    }
                }
            }
            // Reset failure counter on success
            StationDataBus._consecutiveFailures = 0;
        } catch (err) {
            console.warn('[DataBus] REST API sync error:', err);
            StationDataBus._consecutiveFailures++;
        } finally {
            this._syncing = false;
        }
    }

    /**
     * Sends a student update to the backend.
     * When deltaKeys is provided, only those fields are included in updateData.
     * This prevents one station from overwriting another station's concurrent changes.
     */
    static async sendUpdateToBackend(refNo, studentData, deltaKeys = null) {
        try {
            let updateData;
            if (deltaKeys && Array.isArray(deltaKeys) && deltaKeys.length > 0) {
                // Delta mode: send only the changed fields
                updateData = {};
                for (const key of deltaKeys) {
                    if (key in studentData) {
                        updateData[key] = studentData[key];
                    }
                }
            } else {
                // Legacy fallback: send full student object
                updateData = studentData;
            }

            const response = await fetch(this.getApiUrl('stations/update'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    referenceNumber: refNo,
                    updateData: updateData
                })
            });
            if (response.ok) {
                // Immediately refresh database queue after write
                this.syncWithBackend();
            } else {
                console.error('[DataBus] REST API rejected update for student:', refNo);
            }
        } catch (err) {
            console.error('[DataBus] Failed to send REST API update:', err);
        }
    }

    static resetQueue() {
        localStorage.removeItem(STORAGE_KEY);
        this.syncWithBackend();
        return [];
    }

    static stopPolling() {
        if (StationDataBus._pollTimer) {
            clearTimeout(StationDataBus._pollTimer);
            StationDataBus._pollTimer = null;
        }
    }
}

window.StationDataBus = StationDataBus;

/**
 * Adaptive polling engine with exponential backoff.
 * On consecutive failures: 5s → 10s → 30s → 60s → 120s, then holds.
 * Resets to 5s immediately on first success.
 */
(function schedulePoll() {
    if (typeof window === 'undefined') return;

    const delays = [5000, 10000, 30000, 60000, 120000];

    async function poll() {
        await StationDataBus.syncWithBackend();
        const nextDelay = delays[Math.min(StationDataBus._consecutiveFailures, delays.length - 1)];
        StationDataBus._pollTimer = setTimeout(poll, nextDelay);
    }

    // Initial sync after 50ms, then begin adaptive polling
    setTimeout(() => {
        StationDataBus.syncWithBackend();
        StationDataBus._pollTimer = setTimeout(poll, 5000);
    }, 50);
})();
