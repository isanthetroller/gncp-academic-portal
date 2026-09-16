/**
 * Student Portal - Data Model & Utilities
 * Handles state structures, reactive fallbacks, and calculation helpers.
 */

window.StudentModel = {
    /**
     * Create clean initial reactive state container
     */
    createInitialState() {
        return {
            profile: {
                id: '',
                name: 'Student Account',
                program: '',
                email: '',
                photo: null,
                yearLevel: '1st Year',
                status: 'ENROLLED',
                must_change_password: false,
                sectionCode: null,
                personalInfo: null
            },
            roadmap: [],
            requirements: null,
            medical: null,
            scholarship: null,
            payment: null,
            helpdesk: null,
            enrollment: null,
            subjects: [],
            activePeriod: null
        };
    },

    /**
     * Hydrate profile state from session/login payload
     */
    hydrateProfileFromSession(targetProfile, sessionStudent) {
        if (!sessionStudent) return;
        targetProfile.id = sessionStudent.id || targetProfile.id || '';
        targetProfile.name = sessionStudent.name || targetProfile.name || 'Student Account';
        targetProfile.program = sessionStudent.program || targetProfile.program || '';
        targetProfile.email = sessionStudent.email || targetProfile.email || '';
        targetProfile.photo = sessionStudent.photo || targetProfile.photo || null;
        targetProfile.status = sessionStudent.status || targetProfile.status || 'ENROLLED';
        targetProfile.must_change_password = (typeof sessionStudent.must_change_password !== 'undefined')
            ? !!sessionStudent.must_change_password
            : !!targetProfile.must_change_password;
        targetProfile.address = sessionStudent.address || (sessionStudent.personalInfo && sessionStudent.personalInfo.address) || targetProfile.address || '';
        targetProfile.phone = sessionStudent.phone || (sessionStudent.personalInfo && sessionStudent.personalInfo.phone) || targetProfile.phone || '';
        targetProfile.personalEmail = sessionStudent.personalEmail || (sessionStudent.personalInfo && (sessionStudent.personalInfo.personalEmail || sessionStudent.personalInfo.email)) || targetProfile.personalEmail || '';
        targetProfile.emergencyContactName = sessionStudent.emergencyContactName || (sessionStudent.personalInfo && sessionStudent.personalInfo.emergencyContactName) || targetProfile.emergencyContactName || '';
        targetProfile.emergencyContactPhone = sessionStudent.emergencyContactPhone || (sessionStudent.personalInfo && sessionStudent.personalInfo.emergencyContactPhone) || targetProfile.emergencyContactPhone || '';
        if (sessionStudent.personalInfo) {
            targetProfile.personalInfo = sessionStudent.personalInfo;
        }
        if (sessionStudent.sectionCode) {
            targetProfile.sectionCode = sessionStudent.sectionCode;
        }
    },

    /**
     * Calculate total enrolled units from advised subjects or subject list
     */
    calculateTotalUnits(helpdeskData, subjectsList) {
        const list = (helpdeskData && helpdeskData.advisedSubjects && helpdeskData.advisedSubjects.length > 0)
            ? helpdeskData.advisedSubjects
            : subjectsList;
        if (!list || !Array.isArray(list) || !list.length) return 0;
        return list.reduce((sum, subject) => {
            const lec = parseInt(subject.lecture_units || subject.lectureUnits || 0, 10);
            const lab = parseInt(subject.lab_units || subject.labUnits || 0, 10);
            return sum + (isNaN(lec) ? 0 : lec) + (isNaN(lab) ? 0 : lab);
        }, 0);
    },

    /**
     * Calculate total distinct assigned section blocks
     */
    calculateAssignedSectionsCount(helpdeskData, subjectsList, enrollmentData, fallbackSectionCode) {
        const list = (helpdeskData && helpdeskData.advisedSubjects && helpdeskData.advisedSubjects.length > 0)
            ? helpdeskData.advisedSubjects
            : subjectsList;
        if (!list || !Array.isArray(list) || !list.length) return 0;

        const set = new Set();
        list.forEach(subject => {
            const sec = (enrollmentData && enrollmentData.sections)
                ? (enrollmentData.sections[subject.code] || fallbackSectionCode || 'SEC-A')
                : (fallbackSectionCode || 'SEC-A');
            if (sec) set.add(sec);
        });
        return set.size || 1;
    },

    /**
     * Format currency values safely
     */
    formatCurrency(val) {
        if (val === null || val === undefined || isNaN(val)) return '0';
        return Number(val).toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }
};
