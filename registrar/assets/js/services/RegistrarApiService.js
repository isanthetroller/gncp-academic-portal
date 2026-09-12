/**
 * GNCP Registrar Portal — RegistrarApiService Module
 *
 * Backend API abstraction layer for all Registrar operations.
 */
(function (global) {
    function createResponse(success, data = null, error = null, meta = {}) {
        return { success, data, error, meta, timestamp: new Date().toISOString() };
    }

    const RegistrarApiService = {
        _lastDataEtag: null,

        generateRequestId() {
            return 'reg_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
        },

        async request(endpoint, options = {}) {
            const reqId = this.generateRequestId();
            const url = endpoint.startsWith('http') || endpoint.startsWith('../') || endpoint.includes('api.php')
                ? endpoint
                : `backend/api.php?action=${endpoint}`;
            const headers = {
                'Content-Type': 'application/json',
                'X-Request-ID': reqId,
                ...(options.headers || {})
            };

            try {
                const response = await fetch(url, {
                    method: options.method || 'GET',
                    headers,
                    credentials: 'same-origin',
                    body: options.body || null
                });

                if (response.status === 401) {
                    if (typeof global.SessionExpirationGuard !== 'undefined') {
                        global.SessionExpirationGuard.handleExpiredSession({
                            title: 'Session Expired',
                            message: 'Your registrar workstation session has expired. Please sign in again.',
                            reason: 'expired'
                        });
                    }
                    return createResponse(false, null, 'Authentication required. Please sign in.', { code: 401, requestId: reqId });
                }

                if (response.status === 304) {
                    return { success: true, notModified: true };
                }

                if (response.ok) {
                    const etag = response.headers.get('ETag');
                    if (etag && endpoint.includes('fetch_all_data')) {
                        this._lastDataEtag = etag;
                    }
                }

                const data = await response.json();
                return data;
            } catch (err) {
                console.error(`[RegistrarApi] Error on [${endpoint}]:`, err);
                return createResponse(false, null, err.message || 'Network error processing request.', { requestId: reqId });
            }
        },

        async fetchAllData() {
            const headers = {};
            if (this._lastDataEtag) {
                headers['If-None-Match'] = this._lastDataEtag;
            }
            return await this.request('fetch_all_data', { headers });
        },

        // Programs CRUD
        async saveProgram(programData) {
            return await this.request('save_program', {
                method: 'POST',
                body: JSON.stringify({ program: programData })
            });
        },

        async deleteProgram(programId) {
            return await this.request('delete_program', {
                method: 'POST',
                body: JSON.stringify({ id: programId })
            });
        },

        // Subjects CRUD
        async saveSubject(subjectData) {
            return await this.request('save_subject', {
                method: 'POST',
                body: JSON.stringify({ subject: subjectData })
            });
        },

        async deleteSubject(subjectId) {
            return await this.request('delete_subject', {
                method: 'POST',
                body: JSON.stringify({ id: subjectId })
            });
        },

        // Curriculum CRUD
        async saveCurriculum(curriculumData) {
            return await this.request('save_curriculum', {
                method: 'POST',
                body: JSON.stringify({ curriculum: curriculumData })
            });
        },

        async deleteCurriculum(curriculumId) {
            return await this.request('delete_curriculum', {
                method: 'POST',
                body: JSON.stringify({ id: curriculumId })
            });
        },

        // Academic Periods CRUD
        async saveAcademicPeriod(periodData) {
            return await this.request('save_academic_period', {
                method: 'POST',
                body: JSON.stringify({ period: periodData })
            });
        },

        async deleteAcademicPeriod(periodId) {
            return await this.request('delete_academic_period', {
                method: 'POST',
                body: JSON.stringify({ id: periodId })
            });
        },

        // Subject Sections CRUD
        async saveSubjectSection(sectionData) {
            return await this.request('save_subject_section', {
                method: 'POST',
                body: JSON.stringify({ section: sectionData })
            });
        },

        async deleteSubjectSection(sectionId) {
            return await this.request('delete_subject_section', {
                method: 'POST',
                body: JSON.stringify({ id: sectionId })
            });
        },

        // Fee Schedule CRUD
        async saveFee(feeData) {
            return await this.request('save_fee', {
                method: 'POST',
                body: JSON.stringify({ fee: feeData })
            });
        },

        async deleteFee(feeId) {
            return await this.request('delete_fee', {
                method: 'POST',
                body: JSON.stringify({ id: feeId })
            });
        },

        // Legacy / details / approvals
        async updateApplicationStatus(referenceNumber, status, notes, requirementsData = null, sectionCode = null) {
            return await this.request('update_application_status', {
                method: 'POST',
                body: JSON.stringify({ referenceNumber, status, registrarNotes: notes, requirementsData, sectionCode })
            });
        },

        async getSectionsForProgram(program, yearLevel, semester) {
            return await this.request(`get_sections_for_program&program=${encodeURIComponent(program)}&year_level=${encodeURIComponent(yearLevel)}&semester=${encodeURIComponent(semester)}`);
        },

        async updateRoadmapStep(referenceNumber, stepId, status) {
            return await this.request('update_roadmap_step', {
                method: 'POST',
                body: JSON.stringify({ referenceNumber, stepId, status })
            });
        },

        async checkSession() {
            return await this.request('../api/index.php?action=auth/check');
        },

        async fetchUserProfile(username) {
            return await this.request(`../api/index.php?action=auth/profile&username=${encodeURIComponent(username || '')}`);
        }
    };

    global.RegistrarApiService = RegistrarApiService;
})(typeof window !== 'undefined' ? window : this);
