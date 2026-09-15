/**
 * ================================================================
 *  GNCP Academic & Enrollment System — DataCache  v1.0
 *  Zero-Layout-Shift Caching & Intelligent Revalidation (SWR)
 * 
 *  - Dual-tier storage: L1 In-Memory Map + L2 sessionStorage
 *  - Stale-While-Revalidate: Instant UI render with background updates
 *  - In-flight request deduplication: prevents duplicate HTTP queries
 *  - Granular mutation invalidation: clears targeted cache keys
 * ================================================================
 */

(function (global) {
    'use strict';

    const STORAGE_PREFIX = 'gncp_cache_';
    const DEFAULT_STALE_TIME = 30000; // 30 seconds
    const MAX_CACHE_AGE = 3600000; // 1 hour

    class DataCacheEngine {
        constructor() {
            this._memoryCache = new Map();
            this._pendingRequests = new Map();
        }

        /**
         * Computes a lightweight fast hash/snapshot string for deep equality checks.
         */
        _hashData(data) {
            try {
                return JSON.stringify(data);
            } catch (e) {
                return String(data);
            }
        }

        /**
         * Retrieves an entry from L1 (memory) or L2 (sessionStorage).
         * @param {string} key 
         * @returns {{ data: any, isStale: boolean, timestamp: number } | null}
         */
        get(key) {
            const now = Date.now();

            // 1. Check L1 Memory Cache
            if (this._memoryCache.has(key)) {
                const entry = this._memoryCache.get(key);
                if (now - entry.timestamp > MAX_CACHE_AGE) {
                    this.invalidate(key);
                    return null;
                }
                const isStale = (now - entry.timestamp) > entry.staleTime;
                return { data: entry.data, isStale, timestamp: entry.timestamp, hash: entry.hash };
            }

            // 2. Check L2 sessionStorage Cache
            try {
                const raw = sessionStorage.getItem(STORAGE_PREFIX + key);
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed && typeof parsed.timestamp === 'number') {
                        if (now - parsed.timestamp > MAX_CACHE_AGE) {
                            sessionStorage.removeItem(STORAGE_PREFIX + key);
                            return null;
                        }
                        const isStale = (now - parsed.timestamp) > (parsed.staleTime || DEFAULT_STALE_TIME);
                        const hash = parsed.hash || this._hashData(parsed.data);
                        // Populate L1 for subsequent zero-overhead reads
                        this._memoryCache.set(key, {
                            data: parsed.data,
                            timestamp: parsed.timestamp,
                            staleTime: parsed.staleTime || DEFAULT_STALE_TIME,
                            hash
                        });
                        return { data: parsed.data, isStale, timestamp: parsed.timestamp, hash };
                    }
                }
            } catch (e) {
                // Storage access or JSON error; fallback safely
            }

            return null;
        }

        /**
         * Sets an entry into L1 and L2 caches.
         * @param {string} key 
         * @param {any} data 
         * @param {number} [staleTime=30000] 
         */
        set(key, data, staleTime = DEFAULT_STALE_TIME) {
            if (data === undefined) return;
            const now = Date.now();
            const hash = this._hashData(data);
            const entry = {
                data,
                timestamp: now,
                staleTime,
                hash
            };

            // Save to L1 Memory
            this._memoryCache.set(key, entry);

            // Save to L2 sessionStorage
            try {
                sessionStorage.setItem(STORAGE_PREFIX + key, JSON.stringify({
                    data,
                    timestamp: now,
                    staleTime,
                    hash
                }));
            } catch (e) {
                // Storage quota exceeded or disabled; memory cache remains active
            }
        }

        /**
         * Checks if a valid cached entry exists.
         * @param {string} key 
         * @returns {boolean}
         */
        has(key) {
            return this.get(key) !== null;
        }

        /**
         * Invalidates keys matching exact key or prefix/pattern.
         * Examples:
         *   DataCache.invalidate('student:documents')
         *   DataCache.invalidate('student:')
         *   DataCache.invalidate('admin:*')
         * @param {string} patternOrKey 
         */
        invalidate(patternOrKey) {
            if (!patternOrKey) return;
            const isWildcard = patternOrKey.includes('*');
            const cleanPattern = patternOrKey.replace('*', '');

            // Invalidate in memory
            for (const k of this._memoryCache.keys()) {
                if (k === patternOrKey || (isWildcard && k.includes(cleanPattern)) || k.startsWith(cleanPattern)) {
                    this._memoryCache.delete(k);
                }
            }

            // Invalidate in sessionStorage
            try {
                const prefixLen = STORAGE_PREFIX.length;
                const keysToRemove = [];
                for (let i = 0; i < sessionStorage.length; i++) {
                    const storageKey = sessionStorage.key(i);
                    if (storageKey && storageKey.startsWith(STORAGE_PREFIX)) {
                        const actualKey = storageKey.substring(prefixLen);
                        if (actualKey === patternOrKey || (isWildcard && actualKey.includes(cleanPattern)) || actualKey.startsWith(cleanPattern)) {
                            keysToRemove.push(storageKey);
                        }
                    }
                }
                keysToRemove.forEach(k => sessionStorage.removeItem(k));
            } catch (e) {}
        }

        /**
         * Clears entire application cache.
         */
        clear() {
            this._memoryCache.clear();
            this._pendingRequests.clear();
            try {
                const keysToRemove = [];
                for (let i = 0; i < sessionStorage.length; i++) {
                    const k = sessionStorage.key(i);
                    if (k && k.startsWith(STORAGE_PREFIX)) {
                        keysToRemove.push(k);
                    }
                }
                keysToRemove.forEach(k => sessionStorage.removeItem(k));
            } catch (e) {}
        }

        /**
         * Primary Stale-While-Revalidate fetch wrapper with request deduplication.
         * 
         * @param {string} key - Unique cache key (e.g. 'student:documents:123')
         * @param {Function} fetchFn - Async function that performs the network request
         * @param {Object} [options]
         * @param {number} [options.staleTime=30000] - Duration in ms before cached data is stale
         * @param {boolean} [options.forceRefresh=false] - Bypass cache and force network request
         * @param {Function} [options.onBackgroundUpdate] - Callback(newData) triggered if background revalidation finds new data
         * @param {boolean} [options.silent=false] - Suppress console logs
         * @returns {Promise<any>} Resolves with data (either cached or fresh)
         */
        async fetchWithCache(key, fetchFn, options = {}) {
            const staleTime = options.staleTime !== undefined ? options.staleTime : DEFAULT_STALE_TIME;
            const forceRefresh = Boolean(options.forceRefresh);
            const onBackgroundUpdate = typeof options.onBackgroundUpdate === 'function' ? options.onBackgroundUpdate : null;

            // 1. Check existing cache
            if (!forceRefresh) {
                const cached = this.get(key);

                if (cached) {
                    // CACHE HIT: Fresh
                    if (!cached.isStale) {
                        return cached.data;
                    }

                    // CACHE HIT: Stale -> Return cached data immediately, revalidate quietly in background
                    this._revalidateInBackground(key, fetchFn, staleTime, cached.hash, onBackgroundUpdate);
                    return cached.data;
                }
            }

            // 2. CACHE MISS (or forced refresh)
            // Deduplicate if the same request is already in-flight
            if (this._pendingRequests.has(key)) {
                return this._pendingRequests.get(key);
            }

            const requestPromise = (async () => {
                try {
                    const freshData = await fetchFn();
                    this.set(key, freshData, staleTime);
                    return freshData;
                } finally {
                    this._pendingRequests.delete(key);
                }
            })();

            this._pendingRequests.set(key, requestPromise);
            return requestPromise;
        }

        /**
         * Internal background revalidation worker.
         */
        async _revalidateInBackground(key, fetchFn, staleTime, existingHash, onBackgroundUpdate) {
            // Do not spawn duplicate background workers for the same key
            if (this._pendingRequests.has(key)) return;

            const bgPromise = (async () => {
                try {
                    const freshData = await fetchFn();
                    const newHash = this._hashData(freshData);

                    // Check if server payload actually changed
                    if (newHash !== existingHash) {
                        this.set(key, freshData, staleTime);
                        if (onBackgroundUpdate) {
                            try {
                                onBackgroundUpdate(freshData);
                            } catch (err) {
                                console.warn(`[DataCache] onBackgroundUpdate error for "${key}":`, err);
                            }
                        }
                    } else {
                        // Data unchanged: refresh cache timestamp so it remains fresh
                        const current = this._memoryCache.get(key);
                        if (current) {
                            current.timestamp = Date.now();
                        }
                    }
                } catch (err) {
                    // Retain existing cached data on background network failure
                    console.warn(`[DataCache] Background revalidation failed for "${key}". Retaining cached data:`, err);
                } finally {
                    this._pendingRequests.delete(key);
                }
            })();

            this._pendingRequests.set(key, bgPromise);
        }
    }

    // Export singleton instance
    global.DataCache = new DataCacheEngine();

})(typeof window !== 'undefined' ? window : this);
