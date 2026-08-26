/**
 * ==========================================================================
 * GNCP Academic System — Unified Station Pipeline Engine (StationPipeline.js)
 * Single Source of Truth for station gating, step evaluation, and roadmap progression.
 * ==========================================================================
 */
(function(window) {
    'use strict';

    const STAGE_ORDER = {
        'PRE_REGISTERED': 0,
        'PENDING': 0,
        'VERIFIED': 1,
        'APPROVED': 1,
        'ADVISED': 2,
        'MEDICAL_CLEARED': 3,
        'PAID': 4,
        'PARTIAL': 4,
        'ENROLLED': 5,
        'PROMOTED': 5,
        'ACTIVE': 5
    };

    const STATION_CONFIG = {
        'registrar': {
            index: 1,
            key: 'registrar',
            title: 'Registrar Admissions & Verification',
            ticketPrefix: 'REG-',
            requiredUpstreamLevel: 0,
            targetStatus: 'VERIFIED',
            stepIds: ['registrar_verification', 'registrar_review'],
            stepNames: ['Registrar Verification', 'Admissions Review'],
            nextStationKey: 'helpdesk'
        },
        'helpdesk': {
            index: 2,
            key: 'helpdesk',
            title: 'TLC Helpdesk — Advising & Sectioning',
            ticketPrefix: 'ADV-',
            requiredUpstreamLevel: 1,
            targetStatus: 'ADVISED',
            stepIds: ['advising_assessment', 'academic_advising'],
            stepNames: ['Academic Advising', 'Academic Advising & Block Sectioning'],
            nextStationKey: 'medical'
        },
        'medical': {
            index: 3,
            key: 'medical',
            title: 'School Clinic — Medical Clearance',
            ticketPrefix: 'MED-',
            requiredUpstreamLevel: 2,
            targetStatus: 'MEDICAL_CLEARED',
            stepIds: ['clinic_checkup', 'medical_checkup'],
            stepNames: ['Medical Clearance', 'School Clinic — Medical Clearance'],
            nextStationKey: 'cashier'
        },
        'cashier': {
            index: 4,
            key: 'cashier',
            title: 'Treasury & Cashier — Payment Processing',
            ticketPrefix: 'CSH-',
            requiredUpstreamLevel: 3,
            targetStatus: 'PAID',
            stepIds: ['cashier_payment'],
            stepNames: ['Cashier Payment', 'Treasury / Cashier — Payment'],
            nextStationKey: 'it'
        },
        'it': {
            index: 5,
            key: 'it',
            title: 'IT Center — Account Provisioning',
            ticketPrefix: 'ITC-',
            requiredUpstreamLevel: 4,
            targetStatus: 'ENROLLED',
            stepIds: ['it_activation', 'id_email_final'],
            stepNames: ['IT Center ID', 'Student Portal Account Activation'],
            nextStationKey: null
        }
    };

    const StationPipeline = {
        STATION_CONFIG,
        STAGE_ORDER,

        getStageLevel(status) {
            if (!status) return 0;
            const norm = String(status).trim().toUpperCase();
            return STAGE_ORDER[norm] !== undefined ? STAGE_ORDER[norm] : 0;
        },

        findStep(roadmap, stepIds = [], stepNames = []) {
            if (!roadmap || !Array.isArray(roadmap)) return null;
            return roadmap.find(r => {
                if (!r) return false;
                const sid = String(r.stepId || '').toLowerCase();
                const sname = String(r.name || r.title || '').toLowerCase();
                if (stepIds.some(id => id.toLowerCase() === sid)) return true;
                if (stepNames.some(name => name.toLowerCase() === sname)) return true;
                return false;
            }) || null;
        },

        findStepIndex(roadmap, stepIds = [], stepNames = []) {
            if (!roadmap || !Array.isArray(roadmap)) return -1;
            return roadmap.findIndex(r => {
                if (!r) return false;
                const sid = String(r.stepId || '').toLowerCase();
                const sname = String(r.name || r.title || '').toLowerCase();
                if (stepIds.some(id => id.toLowerCase() === sid)) return true;
                if (stepNames.some(name => name.toLowerCase() === sname)) return true;
                return false;
            });
        },

        /**
         * Determines if a student is eligible to appear in the given station's workstation.
         * Returns true if the student has reached or passed this station stage.
         */
        isAtOrPastStation(stationKey, student) {
            if (!student) return false;
            const cfg = STATION_CONFIG[stationKey];
            if (!cfg) return false;

            const studentLevel = this.getStageLevel(student.status);
            // If overall status is at or past this station's required upstream level
            if (studentLevel >= cfg.requiredUpstreamLevel) {
                return true;
            }

            // Fallback: check if station's specific roadmap step has been unlocked
            const step = this.findStep(student.roadmap, cfg.stepIds, cfg.stepNames);
            if (step) {
                const stepStatus = String(step.status || '').toUpperCase();
                if (['IN_PROGRESS', 'COMPLETED', 'FLAGGED'].includes(stepStatus)) {
                    return true;
                }
            }

            return false;
        },

        /**
         * Resolves the current step status for a student at a specific station.
         * Returns 'COMPLETED', 'FLAGGED', 'IN_PROGRESS', or 'PENDING'.
         */
        getStepStatus(stationKey, student) {
            if (!student) return 'PENDING';
            const cfg = STATION_CONFIG[stationKey];
            if (!cfg) return 'PENDING';

            // 1. Check station-specific data objects first
            if (stationKey === 'helpdesk') {
                const h = student.helpdesk || {};
                if (['COMPLETED', 'ADVISED', 'CLEARED'].includes(String(h.status || '').toUpperCase())) return 'COMPLETED';
                if (String(h.status || '').toUpperCase() === 'FLAGGED') return 'FLAGGED';
            } else if (stationKey === 'medical') {
                const m = student.medical || {};
                if (['FIT', 'CLEARED', 'COMPLETED'].includes(String(m.status || '').toUpperCase()) || m.verifiedBy) return 'COMPLETED';
                if (['UNFIT', 'CONDITIONAL', 'FLAGGED'].includes(String(m.status || '').toUpperCase())) return 'FLAGGED';
            } else if (stationKey === 'cashier') {
                const p = student.payment || {};
                if (['PAID', 'PARTIAL', 'COMPLETED'].includes(String(p.status || '').toUpperCase())) return 'COMPLETED';
                if (String(p.status || '').toUpperCase() === 'REJECTED') return 'FLAGGED';
            } else if (stationKey === 'it') {
                const e = student.enrollment || {};
                if (e.permanentId || ['ENROLLED', 'PROMOTED', 'ACTIVE'].includes(String(student.status || '').toUpperCase())) return 'COMPLETED';
            }

            // 2. Check overall student status stage level
            const studentLevel = this.getStageLevel(student.status);
            if (studentLevel >= cfg.index) {
                return 'COMPLETED';
            }

            // 3. Check roadmap step status
            const step = this.findStep(student.roadmap, cfg.stepIds, cfg.stepNames);
            if (step) {
                const s = String(step.status || '').toUpperCase();
                if (s === 'COMPLETED') return 'COMPLETED';
                if (s === 'FLAGGED') return 'FLAGGED';
                if (s === 'IN_PROGRESS') return 'PENDING'; // Ready to be served in queue
            }

            return 'PENDING';
        },

        /**
         * Normalizes raw queue item properties into consistent fields.
         */
        normalizeStudent(s, index = 0, stationKey = null) {
            if (!s) return null;
            const padId = String(s.id || (index + 1)).padStart(3, '0');
            const cfg = stationKey ? STATION_CONFIG[stationKey] : null;

            const name = s.name || s.fullName || (s.firstName ? [s.firstName, s.middleName, s.lastName].filter(Boolean).join(' ') : '') || (s.form ? [s.form.firstName, s.form.middleName, s.form.lastName].filter(Boolean).join(' ') : '') || 'Applicant';
            const program = s.program || s.courseCode || (s.form ? s.form.courseCode : '') || '---';
            const queueTicket = (cfg && s.queueTickets && s.queueTickets[stationKey]) ? s.queueTickets[stationKey] : ((cfg ? cfg.ticketPrefix : 'Q-') + padId);
            const arrivedAt = (cfg && s.stationArrivals && s.stationArrivals[stationKey]) ? s.stationArrivals[stationKey] : (s.createdAt || s.datePreRegistered || '');

            return {
                id: s.referenceNumber || s.id,
                referenceNumber: s.referenceNumber || s.id,
                tempPin: s.tempPin || '',
                queueTicket: queueTicket,
                arrivedAt: arrivedAt,
                createdAt: s.createdAt || '',
                name: name,
                program: program,
                studentType: s.studentType || (s.form ? s.form.studentType : 'REGULAR'),
                phone: s.phone || '',
                email: s.email || '',
                roadmap: Array.isArray(s.roadmap) ? s.roadmap : (typeof s.roadmap === 'string' ? JSON.parse(s.roadmap || '[]') : []),
                status: stationKey ? this.getStepStatus(stationKey, s) : s.status,
                overallStatus: s.status || 'PRE_REGISTERED',
                form: s.form || {},
                medical: s.medical || {},
                payment: s.payment || {},
                helpdesk: s.helpdesk || {},
                enrollment: s.enrollment || {},
                prospectusSubjects: s.prospectusSubjects || [],
                availableSections: s.availableSections || []
            };
        },

        /**
         * Advances roadmap steps safely: marks current step as COMPLETED and unlocks next step.
         */
        advanceRoadmap(roadmap, stationKey) {
            if (!roadmap || !Array.isArray(roadmap)) return [];
            const cfg = STATION_CONFIG[stationKey];
            if (!cfg) return roadmap;

            const currentIdx = this.findStepIndex(roadmap, cfg.stepIds, cfg.stepNames);
            if (currentIdx !== -1) {
                roadmap[currentIdx].status = 'COMPLETED';
                roadmap[currentIdx].updatedAt = new Date().toISOString();

                // Unlock next step
                if (currentIdx + 1 < roadmap.length) {
                    const nextStep = roadmap[currentIdx + 1];
                    if (['PENDING', 'LOCKED', ''].includes(String(nextStep.status || '').toUpperCase())) {
                        nextStep.status = 'IN_PROGRESS';
                        nextStep.updatedAt = new Date().toISOString();
                    }
                }
            }
            return roadmap;
        }
    };

    window.StationPipeline = StationPipeline;

})(typeof window !== 'undefined' ? window : this);
