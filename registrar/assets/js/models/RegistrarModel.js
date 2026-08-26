/**
 * GNCP Registrar Portal — RegistrarModel Module
 *
 * Model layer managing navigation configs and interfacing with RegistrarApiService.
 */
(function (global) {
    const ApiService = global.RegistrarApiService;

    const RegistrarModel = {
        getNavItems() {
            return [
                {
                    category: 'Core Operations',
                    items: [
                        { key: 'pending-applications', label: 'Pending Reviews', icon: 'fa-solid fa-file-signature' },
                        { key: 'students', label: 'Student Directory', icon: 'fa-solid fa-users' }
                    ]
                },
                {
                    category: 'Performance & Insights',
                    items: [
                        { key: 'enrollment-overview', label: 'Enrollment Overview', icon: 'fa-solid fa-chart-pie' },
                        { key: 'reports', label: 'Statistical Reports', icon: 'fa-solid fa-file-invoice' }
                    ]
                }
            ];
        },

        getStaticMeta() {
            // Only static scheduling config lives here.
            // Real stats (programs count, pending queue, etc.) are computed
            // from live DB data via the 'reports' computed property in RegistrarController.
            return {
                semesters: [
                    { title: 'Current Semester', value: '2026-1st', meta: 'Registration is open' },
                    { title: 'Semester Closing', value: 'June 30', meta: 'Final grade submission deadline' }
                ]
            };
        },

        async loadInitialData() {
            const response = await ApiService.fetchAllData();
            if (response && response.success && response.data) {
                return response.data;
            }
            if (response && response.meta && response.meta.code === 401) {
                return {
                    programs: [], subjects: [], curriculum: [], academicPeriods: [],
                    subjectSections: [], feeSchedule: [], students: [], enrollments: [],
                    pendingApplications: [], sections: []
                };
            }
            throw new Error(response ? (response.error || response.message || 'Failed to load initial registrar data') : 'Failed to load initial registrar data');
        },

        // Programs CRUD
        async saveProgram(program) {
            return await ApiService.saveProgram(program);
        },
        async deleteProgram(id) {
            return await ApiService.deleteProgram(id);
        },

        // Subjects CRUD
        async saveSubject(subject) {
            return await ApiService.saveSubject(subject);
        },
        async deleteSubject(id) {
            return await ApiService.deleteSubject(id);
        },

        // Curriculum CRUD
        async saveCurriculum(curr) {
            return await ApiService.saveCurriculum(curr);
        },
        async deleteCurriculum(id) {
            return await ApiService.deleteCurriculum(id);
        },

        // Academic Periods CRUD
        async saveAcademicPeriod(period) {
            return await ApiService.saveAcademicPeriod(period);
        },
        async deleteAcademicPeriod(id) {
            return await ApiService.deleteAcademicPeriod(id);
        },

        // Subject Sections CRUD
        async saveSubjectSection(sect) {
            return await ApiService.saveSubjectSection(sect);
        },
        async deleteSubjectSection(id) {
            return await ApiService.deleteSubjectSection(id);
        },

        // Fee Schedule CRUD
        async saveFee(fee) {
            return await ApiService.saveFee(fee);
        },
        async deleteFee(id) {
            return await ApiService.deleteFee(id);
        },

        async updateApplicationStatus(refNum, status, notes, requirementsData, sectionCode = null) {
            return await ApiService.updateApplicationStatus(refNum, status, notes, requirementsData, sectionCode);
        },
        async getSectionsForProgram(program, yearLevel, semester) {
            return await ApiService.getSectionsForProgram(program, yearLevel, semester);
        },
        async updateRoadmapStep(refNum, stepId, status) {
            return await ApiService.updateRoadmapStep(refNum, stepId, status);
        }
    };

    global.RegistrarModel = RegistrarModel;
})(typeof window !== 'undefined' ? window : this);
