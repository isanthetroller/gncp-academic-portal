/**
 * GNCP Academic Scheduler Workstation — Vue 3 Application Controller
 * Handles schedule planning, conflict detection, professor assignment,
 * and deterministic auto-assignment.
 */

const { createApp, ref, computed, onMounted } = Vue;

const app = createApp({
    components: {
        'employee-sidebar': EmployeeSidebar
    },
    setup() {
        const currentUser = ref(null);
        const currentView = ref('dashboard');
        const isMobileMenuOpen = ref(false);
        const isLoading = ref(false);

        // Catalog & Context
        const context = ref({
            activePeriod: null,
            periods: [],
            programs: [],
            departments: [],
            professors: [],
            sections: []
        });

        // Cohort Schedule Filters
        const filterProgram = ref('');
        const filterYearLevel = ref('1st Year');
        const selectedSectionId = ref(null);
        const activeSectionSchedule = ref(null);
        const isFetchingSchedule = ref(false);

        // Professor Assignment Modal
        const isAssignModalOpen = ref(false);
        const activeOffering = ref(null);
        const candidateProfessors = ref([]);
        const selectedCandidateId = ref(null);
        const isAssigning = ref(false);

        // Auto Assignment State
        const autoAssignSectionId = ref(null);
        const autoAssignResult = ref(null);
        const isRunningAutoAssign = ref(false);

        // Simulator / Conflict Checker
        const simForm = ref({
            days: 'MW',
            startTime: '09:00 AM',
            endTime: '10:30 AM',
            room: 'Lab 1',
            sectionCode: 'BSIT-1M',
            professorId: ''
        });
        const simResult = ref(null);
        const isCheckingSim = ref(false);

        // Toast notifications
        const toast = ref({ show: false, message: '', type: 'success' });
        const showToast = (msg, type = 'success') => {
            toast.value = { show: true, message: msg, type };
            setTimeout(() => { toast.value.show = false; }, 3500);
        };

        // Time-of-Day Greeting
        const greeting = computed(() => {
            const h = new Date().getHours();
            if (h < 12) return 'Great Morning';
            if (h < 18) return 'Great Afternoon';
            return 'Great Evening';
        });

        const currentTitle = computed(() => {
            const titles = {
                dashboard: 'Dashboard Overview & Metrics',
                cohort_schedules: 'Cohort Class Scheduling Workbench',
                auto_assign: 'Deterministic Professor Auto-Scheduler',
                professors: 'Faculty Directory & Teaching Loads',
                conflict_checker: 'Real-Time Schedule Slot Simulator',
                profile: 'My Employee Profile'
            };
            return titles[currentView.value] || 'Academic Scheduling';
        });

        // Filtered sections for dropdown
        const filteredSections = computed(() => {
            if (!context.value.sections) return [];
            return context.value.sections.filter(s => {
                const matchProg = !filterProgram.value || s.program === filterProgram.value;
                const matchYear = !filterYearLevel.value || s.year_level === filterYearLevel.value;
                return matchProg && matchYear;
            });
        });

        // Stat metrics
        const totalSections = computed(() => context.value.sections.length);
        const totalProfessors = computed(() => context.value.professors.length);
        const assignedSlotsCount = computed(() => {
            let count = 0;
            context.value.sections.forEach(s => {
                count += parseInt(s.offerings_count || 0, 10);
            });
            return count;
        });

        // Authentication & Context Loading
        const initAuthAndContext = async () => {
            isLoading.value = true;
            try {
                const authRes = await fetch('../../api/index.php?action=auth/check', { credentials: 'include' });
                const authData = await authRes.json();
                if (!authData.success || !authData.data) {
                    window.location.href = '../../index.html';
                    return;
                }
                currentUser.value = authData.data;

                // Load scheduler context
                const ctxRes = await fetch('../../api/index.php?action=scheduler/context', { credentials: 'include' });
                const ctxData = await ctxRes.json();
                if (ctxData.success && ctxData.data) {
                    context.value = ctxData.data;
                    if (context.value.programs.length > 0) {
                        filterProgram.value = context.value.programs[0].name;
                    }
                    if (filteredSections.value.length > 0) {
                        selectedSectionId.value = filteredSections.value[0].id;
                        autoAssignSectionId.value = filteredSections.value[0].id;
                        loadSectionSchedule(selectedSectionId.value);
                    }
                }
            } catch (err) {
                console.error('Initialization error:', err);
                showToast('Failed to initialize scheduler context.', 'error');
            } finally {
                isLoading.value = false;
            }
        };

        // Load section schedule
        const loadSectionSchedule = async (secId) => {
            if (!secId) return;
            isFetchingSchedule.value = true;
            try {
                const res = await fetch(`../../api/index.php?action=scheduler/section_schedule&sectionId=${secId}`, { credentials: 'include' });
                const data = await res.json();
                if (data.success) {
                    activeSectionSchedule.value = data.data;
                } else {
                    showToast(data.message || 'Failed to load schedule.', 'error');
                }
            } catch (err) {
                console.error('Error loading schedule:', err);
            } finally {
                isFetchingSchedule.value = false;
            }
        };

        const onSectionChange = () => {
            if (selectedSectionId.value) {
                loadSectionSchedule(selectedSectionId.value);
            }
        };

        // Open Assign Professor Modal
        const openAssignModal = async (offering) => {
            activeOffering.value = offering;
            selectedCandidateId.value = offering.professorId || null;
            isAssignModalOpen.value = true;
            candidateProfessors.value = [];

            try {
                const res = await fetch(`../../api/index.php?action=scheduler/professors_for_subject&subjectSectionId=${offering.id}`, { credentials: 'include' });
                const data = await res.json();
                if (data.success) {
                    candidateProfessors.value = data.data;
                }
            } catch (err) {
                console.error('Error loading candidates:', err);
                showToast('Failed to fetch eligible professors.', 'error');
            }
        };

        const selectCandidate = (cand) => {
            if (!cand.isAvailable) {
                Swal.fire({
                    title: 'Schedule Conflict Detected',
                    text: cand.conflictMessage || 'This professor cannot be assigned due to an overlapping schedule or unavailability.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }
            selectedCandidateId.value = cand.id;
        };

        const confirmProfessorAssignment = async () => {
            if (!selectedCandidateId.value || !activeOffering.value) {
                showToast('Please select an eligible professor.', 'error');
                return;
            }

            isAssigning.value = true;
            try {
                const res = await fetch('../../api/index.php?action=scheduler/assign_professor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({
                        professorId: selectedCandidateId.value,
                        subjectSectionId: activeOffering.value.id
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    isAssignModalOpen.value = false;
                    loadSectionSchedule(selectedSectionId.value);
                } else {
                    Swal.fire({
                        title: 'Assignment Rejected',
                        text: data.message,
                        icon: 'error',
                        confirmButtonColor: '#006A4E'
                    });
                }
            } catch (err) {
                console.error('Error assigning professor:', err);
                showToast('Failed to save professor assignment.', 'error');
            } finally {
                isAssigning.value = false;
            }
        };

        // Run Auto-Assignment
        const runAutoAssignment = async () => {
            if (!autoAssignSectionId.value) {
                showToast('Please select a section to auto-assign.', 'error');
                return;
            }

            const result = await Swal.fire({
                title: 'Run Deterministic Auto-Assignment?',
                text: 'The system will deterministically evaluate professor eligibility, department compatibility, and current workloads to allocate faculty without conflicts.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Run Auto-Scheduler',
                confirmButtonColor: '#006A4E'
            });

            if (!result.isConfirmed) return;

            isRunningAutoAssign.value = true;
            autoAssignResult.value = null;

            try {
                const res = await fetch('../../api/index.php?action=scheduler/auto_assign_section', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ sectionId: autoAssignSectionId.value })
                });
                const data = await res.json();
                if (data.success) {
                    autoAssignResult.value = data.data;
                    showToast(data.message, 'success');
                    if (selectedSectionId.value === autoAssignSectionId.value) {
                        loadSectionSchedule(selectedSectionId.value);
                    }
                } else {
                    Swal.fire({
                        title: 'Auto-Assignment Alert',
                        text: data.message,
                        icon: 'warning',
                        confirmButtonColor: '#006A4E'
                    });
                }
            } catch (err) {
                console.error('Error in auto-assignment:', err);
                showToast('Auto-assignment request failed.', 'error');
            } finally {
                isRunningAutoAssign.value = false;
            }
        };

        // Simulator / Conflict Checker
        const runSimulationCheck = async () => {
            isCheckingSim.value = true;
            simResult.value = null;

            const timeStr = `${simForm.value.startTime} - ${simForm.value.endTime}`;
            const payload = {
                days: simForm.value.days,
                time: timeStr,
                room: simForm.value.room,
                sectionCode: simForm.value.sectionCode,
                professorId: simForm.value.professorId ? parseInt(simForm.value.professorId, 10) : null
            };

            try {
                const res = await fetch('../../api/index.php?action=scheduler/validate_slot', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                simResult.value = data;
            } catch (err) {
                console.error('Simulation check failed:', err);
                showToast('Failed to validate slot simulation.', 'error');
            } finally {
                isCheckingSim.value = false;
            }
        };

        const setView = (view) => {
            currentView.value = view;
            if (view === 'cohort_schedules' && selectedSectionId.value) {
                loadSectionSchedule(selectedSectionId.value);
            }
        };

        const handleLogout = async () => {
            const res = await Swal.fire({
                title: 'Sign Out?',
                text: 'Are you sure you want to end your scheduling session?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Sign Out',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel'
            });
            if (res.isConfirmed) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Signing Out...',
                        text: 'Ending your session...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                }
                sessionStorage.removeItem('gncp_station_user');
                sessionStorage.removeItem('gncp_admin_user');
                sessionStorage.removeItem('gncp_was_logged_in');
                localStorage.removeItem('gncp_station_user');
                localStorage.removeItem('gncp_admin_user');
                try {
                    await fetch('../../api/index.php?action=auth/logout', { method: 'POST', keepalive: true }).catch(() => {});
                } catch (e) {}
                window.location.replace('../../?clear=true&logout=true');
            }
        };

        onMounted(() => {
            initAuthAndContext();
        });

        return {
            currentUser,
            currentView,
            isMobileMenuOpen,
            isLoading,
            greeting,
            currentTitle,
            context,
            filterProgram,
            filterYearLevel,
            selectedSectionId,
            filteredSections,
            activeSectionSchedule,
            isFetchingSchedule,
            onSectionChange,
            loadSectionSchedule,
            totalSections,
            totalProfessors,
            assignedSlotsCount,
            isAssignModalOpen,
            activeOffering,
            candidateProfessors,
            selectedCandidateId,
            isAssigning,
            openAssignModal,
            selectCandidate,
            confirmProfessorAssignment,
            autoAssignSectionId,
            autoAssignResult,
            isRunningAutoAssign,
            runAutoAssignment,
            simForm,
            simResult,
            isCheckingSim,
            runSimulationCheck,
            toast,
            showToast,
            setView,
            handleLogout
        };
    }
});

app.mount('#app');
