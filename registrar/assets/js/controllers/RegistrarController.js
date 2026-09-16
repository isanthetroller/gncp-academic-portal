/**
 * GNCP Registrar Portal — RegistrarController Module
 *
 * Controller managing application state, client-side persistence, and mounting.
 */
(function (global) {
    const createApp = Vue.createApp;
    const ref = Vue.ref;
    const reactive = Vue.reactive;
    const computed = Vue.computed;
    const onMounted = Vue.onMounted;
    const onUnmounted = Vue.onUnmounted;
    const watch = Vue.watch;

    const Api = global.RegistrarApiService;
    const View = global.RegistrarView;

    const App = {
        components: {
            'employee-sidebar': global.EmployeeSidebar || (typeof window !== 'undefined' ? window.EmployeeSidebar : null)
        },
        setup() {
            // ── Auth State ────────────────────────────────────────────────
            const getStoredUser = () => {
                try {
                    const raw = sessionStorage.getItem('gncp_station_user') || sessionStorage.getItem('gncp_admin_user');
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (parsed && ['REGISTRAR', 'SUPER_ADMIN', 'ADMIN'].includes(parsed.role)) {
                            return parsed;
                        }
                    }
                } catch (e) {}
                return null;
            };

            const currentUser = ref(getStoredUser());
            const isCheckingSession = ref(!currentUser.value);
            const isLoggingIn = ref(false);
            const loginError = ref('');
            const loginForm = reactive({ username: '', password: '' });

            const timeGreeting = computed(() => {
                const hour = new Date().getHours();
                if (hour < 12) return 'Great Morning';
                if (hour < 18) return 'Great Afternoon';
                return 'Great Evening';
            });

            let pollTimer = null;

            const startLiveSync = () => {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(() => {
                    if (currentUser.value) {
                        loadData();
                    }
                }, 4000);
            };

            const stopLiveSync = () => {
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            };

            // ── View State ────────────────────────────────────────────────
            const currentView = ref('pending-applications'); // Registrar: application review only
            const searchText = ref('');
            const courseSearch = ref('');
            const applicationSearch = ref('');
            const applicationFilter = ref('all');
            const selectedApplication = ref(null);
            const applicationModalMode = ref('review');
            const courseModalMode = ref('add');
            const requirementsError = ref('');
            const showRequirementsValidation = ref(false);
            const selectedStudent = ref(null);

            // ── Academic Catalog States ───────────────────────────────────
            const selectedProgram = ref(null);
            const programModalMode = ref('add');
            const selectedSubject = ref(null);
            const subjectModalMode = ref('add');
            const selectedCurriculum = ref(null);
            const curriculumModalMode = ref('add');
            const selectedPeriod = ref(null);
            const periodModalMode = ref('add');
            const selectedSection = ref(null);
            const sectionModalMode = ref('add');
            const selectedFee = ref(null);
            const feeModalMode = ref('add');

            // ── Logout Confirmation State ─────────────────────────────────
            const showLogoutConfirm = ref(false);


            // ── Data Repositories ─────────────────────────────────────────
            const navItems = ref([
                {
                    category: 'Core Operations',
                    items: [
                        { key: 'pending-applications', label: 'Pending Reviews', icon: 'fa-solid fa-file-signature' },
                        { key: 'review-history', label: 'Review History', icon: 'fa-solid fa-clock-rotate-left' },
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
            ]);
            const staticMeta = {
                semesters: [
                    { title: 'Current Semester', value: '2026-1st', meta: 'Registration is open' },
                    { title: 'Semester Closing', value: 'June 30', meta: 'Final grade submission deadline' }
                ]
            };

            const programs = ref([]);
            const subjects = ref([]);
            const curriculum = ref([]);
            const academicPeriods = ref([]);
            const subjectSections = ref([]);
            const feeSchedule = ref([]);
            const students = ref([]);
            const enrollments = ref([]);
            const pendingApplications = ref([]);
            const sections = ref([]);
            const reviewHistory = ref([]);
            const isLoadingData = ref(true);
            const isLoadingHistory = ref(true);

            const fetchReviewHistory = async (forceRefresh = false) => {
                const basePath = window.location.pathname.startsWith('/systemtest') ? '/systemtest' : '';
                const fetchFn = async () => {
                    const res = await fetch(`${basePath}/api/index.php?action=stations/history&station=REGISTRAR`, {
                        credentials: 'same-origin'
                    });
                    return await res.json();
                };

                if (typeof global.DataCache !== 'undefined') {
                    try {
                        const json = await DataCache.fetchWithCache('registrar:history', fetchFn, {
                            staleTime: 4000,
                            forceRefresh: forceRefresh,
                            onBackgroundUpdate: (freshJson) => {
                                if (freshJson && freshJson.success && Array.isArray(freshJson.data)) {
                                    reviewHistory.value = freshJson.data;
                                } else if (Array.isArray(freshJson)) {
                                    reviewHistory.value = freshJson;
                                }
                            }
                        });
                        if (json && json.success && Array.isArray(json.data)) {
                            reviewHistory.value = json.data;
                        } else if (Array.isArray(json)) {
                            reviewHistory.value = json;
                        }
                    } catch (e) {
                        console.error('Failed to fetch review history:', e);
                    } finally {
                        isLoadingHistory.value = false;
                    }
                    return;
                }

                try {
                    const json = await fetchFn();
                    if (json && json.success && Array.isArray(json.data)) {
                        reviewHistory.value = json.data;
                    }
                } catch (e) {
                    console.error('Failed to fetch review history:', e);
                } finally {
                    isLoadingHistory.value = false;
                }
            };

            const navGroups = computed(() => [
                {
                    title: 'Core Operations',
                    items: [
                        { id: 'pending-applications', label: 'Pending Reviews', icon: 'fa-solid fa-file-signature', badge: pendingApplications.value.filter(s => ['PENDING', 'PRE_REGISTERED', 'RETURNED', 'NEEDS_CORRECTION'].includes(String(s.status).toUpperCase())).length || null },
                        { id: 'review-history', label: 'Review History', icon: 'fa-solid fa-clock-rotate-left' },
                        { id: 'students', label: 'Student Directory', icon: 'fa-solid fa-users' }
                    ]
                },
                {
                    title: 'Performance & Insights',
                    items: [
                        { id: 'enrollment-overview', label: 'Enrollment Overview', icon: 'fa-solid fa-chart-pie' },
                        { id: 'reports', label: 'Statistical Reports', icon: 'fa-solid fa-file-invoice' }
                    ]
                }
            ]);

            const reports = computed(() => {
                const totalProg = programs.value.length;
                const totalSubs = subjects.value.length;
                const totalSecs = subjectSections.value.length;
                const totalStud = students.value.length;
                const pendingCount = pendingApplications.value.filter(s => ['PENDING', 'PRE_REGISTERED', 'RETURNED', 'NEEDS_CORRECTION'].includes(String(s.status).toUpperCase())).length;

                return [
                    { title: 'Registered Students', value: totalStud.toString(), meta: 'Approved and promoted' },
                    { title: 'Subject Catalog', value: totalSubs.toString(), meta: `Spread across ${totalProg} programs` },
                    { title: 'Class Sections Scheduled', value: totalSecs.toString(), meta: 'With active class capacities' },
                    { title: 'Pending Review Queue', value: pendingCount.toString(), meta: 'Applicants awaiting verification' }
                ];
            });

            const applyRegistrarData = (data) => {
                if (!data) return;
                if (data.programs) programs.value = data.programs;
                if (data.subjects) subjects.value = data.subjects;
                if (data.curriculum) curriculum.value = data.curriculum;
                if (data.academicPeriods) academicPeriods.value = data.academicPeriods;
                if (data.subjectSections) subjectSections.value = data.subjectSections;
                if (data.feeSchedule) feeSchedule.value = data.feeSchedule;
                if (data.students) students.value = data.students;
                if (data.enrollments) enrollments.value = data.enrollments;
                if (data.pendingApplications) pendingApplications.value = data.pendingApplications;
                if (data.sections) sections.value = data.sections;
            };

            let isDataRequestInFlight = false;
            const loadData = (forceRefresh = false) => {
                if (typeof global.DataCache !== 'undefined') {
                    DataCache.fetchWithCache('registrar:all_data', () => Api.fetchAllData(), {
                        staleTime: 4000,
                        forceRefresh: forceRefresh,
                        onBackgroundUpdate: (freshRes) => {
                            if (!freshRes || freshRes.notModified) return;
                            applyRegistrarData(freshRes.data || {});
                        }
                    }).then((res) => {
                        if (res && res.data) {
                            applyRegistrarData(res.data);
                            fetchReviewHistory();
                        }
                    }).catch(err => {
                        console.error('Error loading registrar data:', err);
                    }).finally(() => {
                        isLoadingData.value = false;
                    });
                    return;
                }

                if (isDataRequestInFlight) return;
                isDataRequestInFlight = true;
                Api.fetchAllData()
                    .then(res => {
                        if (res && res.notModified) {
                            return;
                        }
                        const data = (res && res.data) ? res.data : {};
                        applyRegistrarData(data);
                        fetchReviewHistory();
                    })
                    .catch(err => {
                        console.error('Error loading registrar data:', err);
                    })
                    .finally(() => {
                        isDataRequestInFlight = false;
                        isLoadingData.value = false;
                    });
            };

            const invalidateAndReload = () => {
                if (typeof global.DataCache !== 'undefined') {
                    global.DataCache.invalidate('registrar:');
                    global.DataCache.invalidate('admin:');
                    global.DataCache.invalidate('student:');
                    global.DataCache.invalidate('stations:');
                }
                loadData(true);
            };

            // ── Auth & Live Profile Handling ─────────────────────────────
            const handleSessionExpired = () => {
                isCheckingSession.value = false;
                currentUser.value = null;
                if (typeof global.SessionExpirationGuard !== 'undefined') {
                    global.SessionExpirationGuard.handleExpiredSession({
                        title: 'Session Expired',
                        message: 'Your registrar workstation session has expired. Please sign in again to continue student evaluations.',
                        reason: 'expired'
                    });
                } else {
                    sessionStorage.removeItem('gncp_station_user');
                    sessionStorage.removeItem('gncp_admin_user');
                    window.location.href = '../?session_expired=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                }
            };

            const checkSession = async () => {
                localStorage.removeItem('gncp_station_user');
                localStorage.removeItem('gncp_admin_user');

                // Optimistically render from tab-scoped sessionStorage for instant 0ms dashboard load
                const cachedRaw = sessionStorage.getItem('gncp_station_user') || sessionStorage.getItem('gncp_admin_user');
                if (cachedRaw) {
                    try {
                        const parsed = JSON.parse(cachedRaw);
                        if (parsed && parsed.role && ['REGISTRAR', 'SUPER_ADMIN', 'ADMIN'].includes(parsed.role)) {
                            currentUser.value = parsed;
                            isCheckingSession.value = false;
                        }
                    } catch (e) {}
                }

                // Immediately trigger data loading in parallel with background auth verification
                loadData();
                startLiveSync();

                try {
                    const res = await fetch('../api/index.php?action=auth/check', { credentials: 'same-origin' });
                    if (res.ok) {
                        const result = await res.json();
                        const allowedRoles = ['REGISTRAR', 'SUPER_ADMIN', 'ADMIN'];
                        if (result.success && result.data && allowedRoles.includes(result.data.role)) {
                            currentUser.value = result.data;
                            isCheckingSession.value = false;
                            const sessionKey = (result.data.role === 'SUPER_ADMIN' || result.data.role === 'ADMIN') ? 'gncp_admin_user' : 'gncp_station_user';
                            sessionStorage.setItem(sessionKey, JSON.stringify(result.data));

                            // Password change guard check
                            if (result.data.must_change_password && typeof global.PasswordChangeGuard !== 'undefined') {
                                global.PasswordChangeGuard.checkAndPrompt(result.data, function () {
                                    const updatedUser = { ...currentUser.value, must_change_password: false };
                                    currentUser.value = updatedUser;
                                    sessionStorage.setItem(sessionKey, JSON.stringify(updatedUser));
                                });
                            }
                            return;
                        } else {
                            handleSessionExpired();
                            return;
                        }
                    } else if (res.status === 401) {
                        handleSessionExpired();
                        return;
                    }
                } catch (err) {
                    console.warn('[Registrar] Live session check network warning:', err);
                }

                if (!currentUser.value) {
                    handleSessionExpired();
                }
            };

            onMounted(() => {
                checkSession();
            });

            onUnmounted(() => {
                stopLiveSync();
            });

            const handleLogout = () => {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Are you sure?',
                        text: 'Are you sure you want to log out of the Registrar Portal?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, log out',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            confirmLogout();
                        }
                    });
                } else {
                    showLogoutConfirm.value = true;
                }
            };

            const confirmLogout = () => {
                showLogoutConfirm.value = false;
                stopLiveSync();
                currentUser.value = null;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Signing Out...',
                        text: 'Ending your session...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }

                sessionStorage.removeItem('gncp_station_user');
                sessionStorage.removeItem('gncp_admin_user');
                localStorage.removeItem('gncp_station_user');
                localStorage.removeItem('gncp_admin_user');

                // Dispatch non-blocking logout with keepalive
                try {
                    fetch('../api/index.php?action=auth/logout', { method: 'POST', keepalive: true }).catch(() => {});
                } catch (e) {}

                window.location.replace('../?clear=true&logout=true');
            };

            // ── Computed Properties ───────────────────────────────────────
            const isAdmin = computed(() => {
                return currentUser.value && (currentUser.value.role === 'SUPER_ADMIN' || currentUser.value.role === 'ADMIN');
            });

            const topBarEyebrow = computed(() => {
                const view = currentView.value;
                const catalogViews = ['programs', 'subjects', 'curriculum', 'academic-periods', 'subject-sections', 'fee-schedule'];
                if (catalogViews.includes(view)) return 'Academic Catalog';
                return 'Records & Overview';
            });

            const topBarTitle = computed(() => {
                const view = currentView.value;
                if (view === 'pending-applications') return 'Pending Applications';
                if (view === 'students') return 'Students';
                if (view === 'enrollment-overview') return 'Enrollment Overview';
                if (view === 'reports') return 'Reports';
                if (view === 'programs') return 'Programs Catalog';
                if (view === 'subjects') return 'Subjects Catalog';
                if (view === 'curriculum') return 'Curriculum Mapping';
                if (view === 'academic-periods') return 'Academic Periods';
                if (view === 'subject-sections') return 'Subject Sections';
                if (view === 'fee-schedule') return 'Fee Schedule';
                return 'Dashboard';
            });

            const searchPlaceholder = computed(() => {
                const view = currentView.value;
                if (view === 'programs') return 'Search programs by code or name...';
                if (view === 'subjects') return 'Search subjects by code or title...';
                if (view === 'curriculum') return 'Search program curriculum mappings...';
                if (view === 'academic-periods') return 'Search academic periods...';
                if (view === 'subject-sections') return 'Search class schedules/instructors...';
                if (view === 'fee-schedule') return 'Search configured fees...';
                if (view === 'pending-applications') return 'Search pending applications...';
                if (view === 'students') return 'Search student records...';
                return 'Search academic data...';
            });

            // ── Helper Modal Controls ─────────────────────────────────────
            const getModalInstance = (id) => {
                const element = document.getElementById(id);
                return element ? bootstrap.Modal.getOrCreateInstance(element) : null;
            };

            const hideModal = (id) => {
                const modal = getModalInstance(id);
                if (modal) modal.hide();
                if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
                document.body.setAttribute('tabindex', '-1');
                document.body.focus();
            };

            const setView = (view) => {
                if (view === 'logout') {
                    handleLogout();
                    return;
                }
                searchText.value = '';
                // Close modals
                hideModal('programModal');
                hideModal('subjectModal');
                hideModal('curriculumModal');
                hideModal('periodModal');
                hideModal('sectionModal');
                hideModal('feeModal');
                currentView.value = view;
                if (view === 'review-history') {
                    fetchReviewHistory();
                }
            };





            const availableSectionsForApplication = ref([]);

            const ensureRoadmapNormalized = (roadmap, status) => {
                if (!roadmap || !Array.isArray(roadmap) || roadmap.length === 0) {
                    return [
                        { stepId: 'online_registration', title: 'Online Pre-Enrollment Submission', station: 'Online Portal', location: 'Remote / Web', status: 'COMPLETED' },
                        { stepId: 'registrar_verification', title: 'Registrar Application Review', station: 'Station 1: Registrar', location: 'Admin Building G/F', status: ['APPROVED', 'Approved', 'REGISTRAR_APPROVED', 'VERIFIED'].includes(status) ? 'COMPLETED' : 'IN_PROGRESS' },
                        { stepId: 'advising_assessment', title: 'Academic Advising & Block Sectioning', station: 'Station 2: Academic Advising', location: 'College Department Hall', status: 'PENDING' },
                        { stepId: 'clinic_checkup', title: 'Medical Pre-Screening & Physical Exam', station: 'Station 3: School Clinic', location: 'Health Services Building', status: 'PENDING' },
                        { stepId: 'cashier_payment', title: 'Tuition Assessment & Cashier Payment', station: 'Station 4: Cashier', location: 'Finance Building 1st Flr', status: 'PENDING' },
                        { stepId: 'id_email_final', title: 'Student ID & Institutional Email Issuance', station: 'Station 5: IT Center', location: 'Computer Lab 2', status: 'PENDING' }
                    ];
                }
                return roadmap.map((item, idx) => ({
                    stepId: item.stepId || item.id || ('step_' + idx),
                    title: item.title || item.name || ('Step ' + (idx + 1)),
                    station: item.station || item.location || ('Station ' + (idx + 1)),
                    location: item.location || 'GNCP Campus',
                    status: item.status || 'PENDING'
                }));
            };

            const openApplicationModal = (application, mode = 'review') => {
                applicationModalMode.value = mode;
                selectedApplication.value = JSON.parse(JSON.stringify(application));
                if (selectedApplication.value && !selectedApplication.value.hasOwnProperty('sectionCode')) {
                    selectedApplication.value.sectionCode = null;
                }
                if (selectedApplication.value) {
                    selectedApplication.value.roadmap = ensureRoadmapNormalized(selectedApplication.value.roadmap, selectedApplication.value.status);
                    if (!selectedApplication.value.requirements || selectedApplication.value.requirements.length === 0) {
                        if (selectedApplication.value.requirementsData && selectedApplication.value.requirementsData.requirements) {
                            selectedApplication.value.requirements = selectedApplication.value.requirementsData.requirements;
                        } else {
                            selectedApplication.value.requirements = [
                                'Form 138 (Original Senior High School Report Card)',
                                'Original Certificate of Good Moral Character (with dry seal)',
                                'PSA Birth Certificate (Photocopy)',
                                '2 pieces recent 2x2 color pictures (white background with name tag)'
                            ];
                        }
                    }
                }
                requirementsError.value = '';
                showRequirementsValidation.value = false;

                // Helper to ensure student's assigned section is in the list
                const ensureSelectedSectionInList = (list, sectionCode, program) => {
                    if (!sectionCode) return list;
                    const exists = list.some(s => s.code === sectionCode);
                    if (!exists) {
                        list.push({
                            id: 0,
                            code: sectionCode,
                            program: program || 'BSIT',
                            yearLevel: '1st Year',
                            sectionName: 'Section ' + sectionCode,
                            capacity: 40,
                            enrolledCount: 0,
                            availableSlots: 40,
                            occupancyPct: 0,
                            adviser: 'Unassigned',
                            curriculumVersion: '—',
                            semester: '1st Semester',
                            schoolYear: '—'
                        });
                    }
                    return list;
                };

                // Helper: filter sections from already-loaded data as fallback
                const getSectionsFromLoaded = (progCode, targetYearLevel) => {
                    if (!sections.value || !progCode) return [];
                    const search = progCode.trim().toLowerCase();

                    const progObj = programs.value ? programs.value.find(p => (p.code || '').trim().toLowerCase() === search || (p.name || '').trim().toLowerCase() === search) : null;
                    const searchName = progObj ? progObj.name.trim().toLowerCase() : search;
                    const searchCode = progObj ? progObj.code.trim().toLowerCase() : search;

                    const targetYear = (targetYearLevel || '').trim().toLowerCase();
                    return sections.value.filter(s => {
                        const progName = (s.program || '').trim().toLowerCase();
                        const isProgMatch = (progName === searchName ||
                            progName === searchCode ||
                            progName.includes(search) ||
                            search.includes(progName) ||
                            (searchCode && progName.includes(searchCode)));

                        const sectionYear = (s.yearLevel || '').trim().toLowerCase();
                        const isYearMatch = !targetYear || sectionYear === targetYear || sectionYear.includes(targetYear) || targetYear.includes(sectionYear);
                        return isProgMatch && isYearMatch;
                    }).map(s => ({
                        id: s.id || 0,
                        code: s.code,
                        program: s.program,
                        yearLevel: s.yearLevel || '1st Year',
                        sectionName: (s.program || progCode) + ' — Section ' + s.code,
                        capacity: s.capacity || 40,
                        enrolledCount: 0,
                        availableSlots: s.capacity || 40,
                        occupancyPct: 0,
                        adviser: s.adviser || 'Unassigned',
                        curriculumVersion: s.curriculumVersion || '—',
                        semester: s.semester || '1st Semester',
                        schoolYear: s.schoolYear || '—'
                    }));
                };

                // Pre-populate from loaded sections immediately (exact year first, then program fallback)
                let fallbackList = getSectionsFromLoaded(application.program, application.yearLevel);
                if (!fallbackList || fallbackList.length === 0) {
                    fallbackList = getSectionsFromLoaded(application.program, null);
                }
                availableSectionsForApplication.value = ensureSelectedSectionInList(fallbackList, application.sectionCode, application.program);

                // Then try to get fresh data from API (with live enrolled counts)
                if (application.program) {
                    const yLevel = application.yearLevel || '1st Year';
                    Api.getSectionsForProgram(application.program, yLevel, '1st Semester')
                        .then(res => {
                            if (res.success && res.data && res.data.length > 0) {
                                availableSectionsForApplication.value = ensureSelectedSectionInList(res.data, application.sectionCode, application.program);
                            } else if (res.allSections && res.allSections.length > 0) {
                                availableSectionsForApplication.value = ensureSelectedSectionInList(res.allSections, application.sectionCode, application.program);
                            } else if (application.sectionCode) {
                                availableSectionsForApplication.value = ensureSelectedSectionInList([], application.sectionCode, application.program);
                            }
                        })
                        .catch(err => {
                            console.error('Failed to load sections for program modal:', err);
                            // Keep the pre-populated fallback data
                        });
                }

                getModalInstance('applicationModal')?.show();
            };


            const printForm = (app) => {
                if (!app) return;
                const url = `../stations/payment-processing/cor_print.php?ref=${app.referenceNumber}&pin=${app.tempPin}`;
                window.open(url, '_blank');
            };

            const openStudentModal = (student) => {
                selectedStudent.value = JSON.parse(JSON.stringify(student));
                if (selectedStudent.value) {
                    selectedStudent.value.roadmap = ensureRoadmapNormalized(selectedStudent.value.roadmap, selectedStudent.value.status);
                }
                getModalInstance('studentProfileModal')?.show();
            };

            const getDocKey = (item) => {
                if (!item) return 'other';
                const text = item.toLowerCase().trim();
                // Legacy key mappings to preserve backward compatibility for standard freshman documents
                if (text.startsWith('form 138') || (text.includes('report card') && !text.includes('old high school'))) return 'reportCard';
                if (text.startsWith('psa birth certificate')) return 'psa';
                if (text.startsWith('original certificate of good moral')) return 'goodMoral';

                // Unique key per distinct requirement title to eliminate key collision bugs
                return text.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            };

            const activePreviewDoc = ref(null);

            const getDocFile = (item, studentRecord = null) => {
                const appRecord = studentRecord || selectedApplication.value;
                if (!appRecord || !appRecord.requirementsData) return null;
                const key = getDocKey(item);
                const reqData = appRecord.requirementsData;

                // 1. Check pre-enrollment files dictionary (e.g. reqData.files.reportCard / reqData.files.psa)
                if (reqData.files && typeof reqData.files === 'object') {
                    if (reqData.files[key]) return reqData.files[key];
                    const altKey = key === 'reportCard' ? 'form_138' : (key === 'psa' ? 'psa_birth_cert' : (key === 'goodMoral' ? 'good_moral' : key));
                    if (reqData.files[altKey]) return reqData.files[altKey];
                }

                // 2. Check Format A docs dictionary (e.g. reqData.docs.form_138 / reqData.docs.reportCard)
                if (reqData.docs && typeof reqData.docs === 'object') {
                    const docEntry = reqData.docs[key] || reqData.docs[key === 'reportCard' ? 'form_138' : (key === 'psa' ? 'psa_birth_cert' : (key === 'goodMoral' ? 'good_moral' : key))];
                    if (docEntry && typeof docEntry === 'object' && (docEntry.filePath || docEntry.softCopyUrl)) {
                        return {
                            fileName: docEntry.fileName || `${item}.pdf`,
                            filePath: docEntry.filePath || docEntry.softCopyUrl,
                            fileType: docEntry.fileType || 'application/pdf',
                            uploadedAt: docEntry.submittedAt || docEntry.uploadedAt || docEntry.dateUpdated || null
                        };
                    }
                }

                // 3. Check Format B array (e.g. [ { key: 'form_138', softCopyUrl: '...' } ])
                if (Array.isArray(reqData)) {
                    for (const req of reqData) {
                        const rKey = (req.key || '').toLowerCase();
                        if (rKey === key.toLowerCase() ||
                            rKey === (key === 'reportCard' ? 'form_138' : (key === 'psa' ? 'psa_birth_cert' : (key === 'goodMoral' ? 'good_moral' : key))) ||
                            (req.title && item && req.title.toLowerCase().includes(item.toLowerCase()))) {
                            if (req.softCopyUrl || req.filePath) {
                                return {
                                    fileName: req.fileName || `${item}.pdf`,
                                    filePath: req.softCopyUrl || req.filePath,
                                    fileType: req.fileType || 'application/pdf',
                                    uploadedAt: req.submittedAt || req.uploadedAt || null
                                };
                            }
                        }
                    }
                }

                return null;
            };

            const getDocFileUrl = (fileObj) => {
                if (!fileObj) return '';
                const path = fileObj.filePath || fileObj.softCopyUrl || '';
                if (!path) return '';
                if (path.includes('action=student/download_document') || path.includes('action=students/download-document')) {
                    return path;
                }
                if (path.includes('uploads/documents/')) {
                    const cleanName = path.split('uploads/documents/').pop().split('?')[0];
                    return '/systemtest/api/index.php?action=student/download_document&file=' + encodeURIComponent(cleanName);
                }
                if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:')) return path;
                if (path.startsWith('/systemtest/')) return path;
                if (path.startsWith('/')) return path;
                return '../' + path.replace(/^\/+/, '');
            };

            const openDocumentModal = (fileObj) => {
                activePreviewDoc.value = fileObj;
            };

            const isImageFile = (pathOrName) => {
                if (!pathOrName) return false;
                const ext = pathOrName.split('.').pop().toLowerCase();
                return ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext);
            };

            const isPdfFile = (pathOrName) => {
                if (!pathOrName) return false;
                const ext = pathOrName.split('.').pop().toLowerCase();
                return ext === 'pdf';
            };

            const getDefaultDeadline = () => {
                const d = new Date();
                d.setDate(d.getDate() + 60); // 60 days from now
                return d.toISOString().split('T')[0];
            };

            const getDocEntry = (item, studentRecord = null) => {
                const appRecord = studentRecord || selectedApplication.value;
                if (!appRecord || !appRecord.requirementsData) return null;
                const key = getDocKey(item);
                const docs = appRecord.requirementsData.docs || {};
                const val = docs[key];
                if (!val) {
                    if ((appRecord.requirementsData.status || '').toUpperCase() === 'VERIFIED') {
                        return { status: 'ORIGINAL' };
                    }
                    return null;
                }
                if (typeof val === 'string') {
                    if (val === 'verified' || val === 'ORIGINAL') return { status: 'ORIGINAL' };
                    if (val === 'submitted' || val === 'PHOTOCOPY') return { status: 'PHOTOCOPY' };
                    if (val === 'UNDERTAKING') return { status: 'UNDERTAKING', deadline: getDefaultDeadline() };
                    return { status: 'NOT_SUBMITTED' };
                }
                return val;
            };

            const getDocStatus = (item, studentRecord = null) => {
                const entry = getDocEntry(item, studentRecord);
                if (!entry) return 'NOT_SUBMITTED';
                const st = (entry.status || '').toUpperCase();
                if (st === 'VERIFIED' || st === 'ORIGINAL') return 'ORIGINAL';
                if (st === 'SUBMITTED' || st === 'PHOTOCOPY') return 'PHOTOCOPY';
                if (st === 'UNDERTAKING') return 'UNDERTAKING';
                return 'NOT_SUBMITTED';
            };

            const getDocUndertakingDeadline = (item, studentRecord = null) => {
                const entry = getDocEntry(item, studentRecord);
                return (entry && entry.deadline) ? entry.deadline : getDefaultDeadline();
            };

            const getDocRemarks = (item, studentRecord = null) => {
                const entry = getDocEntry(item, studentRecord);
                return (entry && entry.remarks) ? entry.remarks : '';
            };

            const isDocVerified = (item, studentRecord = null) => {
                const st = getDocStatus(item, studentRecord);
                return ['ORIGINAL', 'PHOTOCOPY', 'UNDERTAKING'].includes(st);
            };

            const ensureReqDataInitialized = () => {
                if (!selectedApplication.value) return;
                if (!selectedApplication.value.requirementsData) {
                    selectedApplication.value.requirementsData = {
                        status: 'PENDING',
                        docs: {},
                        transmittal: { form137Status: 'NOT_SENT', dateDispatched: '', originSchool: selectedApplication.value.seniorHighSchool || selectedApplication.value.previousCollege || '', remarks: '' }
                    };
                }
                if (!selectedApplication.value.requirementsData.docs) {
                    selectedApplication.value.requirementsData.docs = {};
                }
                if (!selectedApplication.value.requirementsData.transmittal) {
                    selectedApplication.value.requirementsData.transmittal = {
                        form137Status: 'NOT_SENT',
                        dateDispatched: '',
                        originSchool: selectedApplication.value.seniorHighSchool || selectedApplication.value.previousCollege || '',
                        remarks: ''
                    };
                }
            };

            const setDocStatus = (item, newStatus) => {
                if (!selectedApplication.value) return;
                const currentStatus = selectedApplication.value.status;
                if (['ENROLLED', 'Rejected', 'REJECTED'].includes(currentStatus)) return;

                ensureReqDataInitialized();
                const key = getDocKey(item);
                const prev = getDocEntry(item);
                const deadline = (prev && prev.deadline) ? prev.deadline : getDefaultDeadline();
                const remarks = (prev && prev.remarks) ? prev.remarks : '';

                selectedApplication.value.requirementsData.docs[key] = {
                    status: newStatus,
                    deadline: newStatus === 'UNDERTAKING' ? deadline : null,
                    remarks: remarks,
                    dateUpdated: new Date().toISOString()
                };

                const isApproved = ['Approved', 'APPROVED', 'REGISTRAR_APPROVED'].includes(currentStatus);
                if (isApproved) {
                    const refNum = selectedApplication.value.referenceNumber;
                    const notes = selectedApplication.value.registrarNotes || '';
                    const reqData = selectedApplication.value.requirementsData;
                    const sectionCode = selectedApplication.value.sectionCode;
                    Api.updateApplicationStatus(refNum, currentStatus, notes, reqData, sectionCode).then(res => {
                        if (res.success) {
                            const index = pendingApplications.value.findIndex(a => a.referenceNumber === refNum);
                            if (index !== -1) {
                                pendingApplications.value[index] = res.data;
                            }
                            loadData();
                        }
                    });
                } else {
                    if (showRequirementsValidation.value) {
                        const reqs = selectedApplication.value.requirements || [];
                        const allSatisfied = reqs.every(r => isDocVerified(r));
                        if (allSatisfied) {
                            showRequirementsValidation.value = false;
                            requirementsError.value = '';
                        }
                    }
                }
            };

            const setDocUndertakingDeadline = (item, dateVal) => {
                if (!selectedApplication.value) return;
                ensureReqDataInitialized();
                const key = getDocKey(item);
                const prev = getDocEntry(item) || { status: 'UNDERTAKING' };
                selectedApplication.value.requirementsData.docs[key] = {
                    ...prev,
                    status: 'UNDERTAKING',
                    deadline: dateVal,
                    dateUpdated: new Date().toISOString()
                };
            };

            const setDocRemarks = (item, remarksVal) => {
                if (!selectedApplication.value) return;
                ensureReqDataInitialized();
                const key = getDocKey(item);
                const prev = getDocEntry(item) || { status: 'ORIGINAL' };
                selectedApplication.value.requirementsData.docs[key] = {
                    ...prev,
                    remarks: remarksVal,
                    dateUpdated: new Date().toISOString()
                };
            };

            const toggleDocStatus = (item, checked) => {
                setDocStatus(item, checked ? 'ORIGINAL' : 'NOT_SUBMITTED');
            };

            const hasActiveUndertakings = (record = null) => {
                const appRecord = record || selectedApplication.value;
                if (!appRecord) return false;
                const reqs = appRecord.requirements || (appRecord.requirementsData && appRecord.requirementsData.requirements) || [];
                return reqs.some(r => getDocStatus(r, appRecord) === 'UNDERTAKING');
            };

            const getActiveUndertakings = (record = null) => {
                const appRecord = record || selectedApplication.value;
                if (!appRecord) return [];
                const reqs = appRecord.requirements || (appRecord.requirementsData && appRecord.requirementsData.requirements) || [];
                return reqs.filter(r => getDocStatus(r, appRecord) === 'UNDERTAKING').map(r => ({
                    title: r,
                    deadline: getDocUndertakingDeadline(r, appRecord),
                    remarks: getDocRemarks(r, appRecord)
                }));
            };

            const getTransmittalData = (record = null) => {
                const appRecord = record || selectedApplication.value;
                if (!appRecord || !appRecord.requirementsData || !appRecord.requirementsData.transmittal) {
                    return { form137Status: 'NOT_SENT', dateDispatched: '', originSchool: (appRecord && (appRecord.seniorHighSchool || appRecord.previousCollege)) || '', remarks: '' };
                }
                return appRecord.requirementsData.transmittal;
            };

            const setTransmittalStatus = (form137Status) => {
                if (!selectedApplication.value) return;
                ensureReqDataInitialized();
                selectedApplication.value.requirementsData.transmittal.form137Status = form137Status;
                if (form137Status !== 'NOT_SENT' && !selectedApplication.value.requirementsData.transmittal.dateDispatched) {
                    selectedApplication.value.requirementsData.transmittal.dateDispatched = new Date().toISOString().split('T')[0];
                }
            };

            const setTransmittalDate = (dateVal) => {
                if (!selectedApplication.value) return;
                ensureReqDataInitialized();
                selectedApplication.value.requirementsData.transmittal.dateDispatched = dateVal;
            };

            const updateApplicationStatus = async (status) => {
                if (!selectedApplication.value) return;

                const currentStatus = selectedApplication.value.status;
                if (['APPROVED', 'Approved', 'REGISTRAR_APPROVED', 'VERIFIED'].includes(currentStatus) && status === 'Approved') {
                    // Allowed to re-save block section
                } else if (['APPROVED', 'Approved', 'REGISTRAR_APPROVED', 'VERIFIED'].includes(currentStatus)) {
                    await Swal.fire({
                        title: 'Notice',
                        text: 'This application has already been verified and cleared.',
                        icon: 'info',
                        confirmButtonColor: '#198754'
                    });
                    return;
                }

                if (['Rejected', 'REJECTED'].includes(currentStatus)) {
                    await Swal.fire({
                        title: 'Error',
                        text: 'This application has already been permanently rejected and cannot be modified.',
                        icon: 'error',
                        confirmButtonColor: '#dc3545'
                    });
                    return;
                }

                if (status === 'Approved' && !selectedApplication.value.sectionCode) {
                    await Swal.fire({
                        title: 'Section Required',
                        text: 'Please assign a block section for this student before approving.',
                        icon: 'warning',
                        confirmButtonColor: '#198754'
                    });
                    return;
                }

                const refNum = selectedApplication.value.referenceNumber;

                // Build confirmation messages, with a hard block when docs aren't all satisfied
                const reqs = selectedApplication.value.requirements || [];
                const unverifiedCount = reqs.filter(item => !isDocVerified(item)).length;
                const undertakingsCount = reqs.filter(item => getDocStatus(item) === 'UNDERTAKING').length;

                const isAlreadyApproved = ['Approved', 'APPROVED', 'REGISTRAR_APPROVED', 'VERIFIED'].includes(currentStatus || '');

                // Show validation feedback in the form and hard-block approval if any document is NOT_SUBMITTED
                if (status === 'Approved' && !isAlreadyApproved && unverifiedCount > 0) {
                    showRequirementsValidation.value = true;
                    requirementsError.value = `${unverifiedCount} document(s) missing/unsubmitted. All required documents must be marked Original, Photocopy, or Promissory Undertaking before approval.`;
                    await Swal.fire({
                        title: 'Requirements Incomplete',
                        text: `This application cannot be approved because ${unverifiedCount} required document(s) have not been submitted or placed under promissory undertaking.`,
                        icon: 'error',
                        confirmButtonColor: '#dc3545'
                    });
                    return;
                } else {
                    showRequirementsValidation.value = false;
                    requirementsError.value = '';
                }

                let customNotes = undertakingsCount > 0 ? `Conditional Enrollment: ${undertakingsCount} document(s) under Promissory Undertaking.` : '';

                if (status === 'Pending' || status === 'RETURNED' || status === 'Needs_Correction') {
                    const promptRes = await Swal.fire({
                        title: 'Return for Correction',
                        input: 'textarea',
                        inputLabel: 'Reason for returning application to student',
                        inputPlaceholder: 'Detail specifically what requires correction or re-upload...',
                        inputValidator: (value) => {
                            if (!value || !value.trim()) {
                                return 'Please specify the correction reason for the student.';
                            }
                        },
                        showCancelButton: true,
                        confirmButtonColor: '#f59e0b',
                        confirmButtonText: 'Return Application',
                        cancelButtonColor: '#6c757d'
                    });
                    if (!promptRes.isConfirmed) return;
                    status = 'RETURNED';
                    customNotes = promptRes.value.trim();
                } else {
                    let msg = '';
                    if (status === 'Approved') {
                        if (isAlreadyApproved) {
                            msg = `Update block section assignment to <strong>${selectedApplication.value.sectionCode || 'Unassigned'}</strong> for application ${refNum}?`;
                        } else if (undertakingsCount > 0) {
                            msg = `Approve application ${refNum} under <strong>Conditional Promissory Undertaking</strong>? (${undertakingsCount} document(s) pending promissory compliance). This will clear the applicant for station advising and medical checkup.`;
                        } else {
                            msg = `Approve application ${refNum}? All required documents are verified and complete. This will advance the enrollment roadmap.`;
                        }
                    } else if (status === 'Rejected') {
                        msg = `Reject application ${refNum}? This action is permanent and cannot be undone. The applicant must submit a completely new pre-registration application if they wish to apply again.`;
                    }

                    const confirmRes = await Swal.fire({
                        title: 'Confirm Action',
                        html: msg.replace(/\n/g, '<br>'),
                        icon: status === 'Rejected' ? 'error' : status === 'Approved' ? 'question' : 'warning',
                        showCancelButton: true,
                        confirmButtonColor: status === 'Rejected' ? '#dc3545' : '#198754',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, proceed'
                    });
                    if (!confirmRes.isConfirmed) return;
                }

                const reqData = selectedApplication.value.requirementsData;
                const sectionCode = selectedApplication.value.sectionCode;
                Api.updateApplicationStatus(refNum, status, customNotes, reqData, sectionCode).then(res => {
                    if (res.success) {
                        if (['APPROVED', 'Approved', 'VERIFIED', 'Rejected', 'REJECTED'].includes(status)) {
                            pendingApplications.value = pendingApplications.value.filter(a => a.referenceNumber !== refNum);
                        } else if (res.data) {
                            const index = pendingApplications.value.findIndex(a => a.referenceNumber === refNum);
                            if (index !== -1) {
                                pendingApplications.value[index] = res.data;
                            } else {
                                pendingApplications.value.unshift(res.data);
                            }
                        }
                        if (typeof global.DataCache !== 'undefined') {
                            global.DataCache.invalidate('registrar:');
                            global.DataCache.invalidate('admin:');
                            global.DataCache.invalidate('student:');
                            global.DataCache.invalidate('stations:');
                        }
                        fetchReviewHistory(true);
                        loadData(true);
                        hideModal('applicationModal');
                        Swal.fire({
                            title: 'Success',
                            text: `Application ${refNum} has been ${status === 'Approved' ? 'approved & verified' : (status === 'RETURNED' ? 'returned for correction' : 'rejected')} successfully.`,
                            icon: 'success',
                            confirmButtonColor: '#198754',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            title: 'Failed',
                            text: res.message || 'An error occurred while updating the status.',
                            icon: 'error',
                            confirmButtonColor: '#dc3545'
                        });
                    }
                }).catch(err => {
                    console.error('Status update failed:', err);
                    Swal.fire({
                        title: 'Error',
                        text: 'An unexpected network error occurred.',
                        icon: 'error',
                        confirmButtonColor: '#dc3545'
                    });
                });
            };

            // ── Academic Catalog CRUD Methods ─────────────────────────────
            const openProgramModal = (mode, program = null) => {
                programModalMode.value = mode;
                selectedProgram.value = program ? JSON.parse(JSON.stringify(program)) : { code: '', name: '', department: '', status: 'Active' };
                getModalInstance('programModal')?.show();
            };
            const saveProgram = (prog) => {
                Api.saveProgram(prog).then(res => {
                    if (res.success) {
                        programs.value = res.data || [];
                        hideModal('programModal');
                        invalidateAndReload();
                    }
                });
            };
            const deleteProgram = async (id) => {
                const confirmRes = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to delete this program?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (!confirmRes.isConfirmed) return;
                Api.deleteProgram(id).then(res => {
                    if (res.success) {
                        programs.value = res.data || [];
                        invalidateAndReload();
                    }
                });
            };

            const openSubjectModal = (mode, subject = null) => {
                subjectModalMode.value = mode;
                selectedSubject.value = subject ? JSON.parse(JSON.stringify(subject)) : { code: '', title: '', description: '', lectureUnits: 3, labUnits: 0, labFee: 0, department: '', prerequisites: 'None' };
                getModalInstance('subjectModal')?.show();
            };
            const saveSubject = (sub) => {
                Api.saveSubject(sub).then(res => {
                    if (res.success) {
                        subjects.value = res.data || [];
                        hideModal('subjectModal');
                        invalidateAndReload();
                    }
                });
            };
            const deleteSubject = async (id) => {
                const confirmRes = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to delete this subject?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (!confirmRes.isConfirmed) return;
                Api.deleteSubject(id).then(res => {
                    if (res.success) {
                        subjects.value = res.data || [];
                        invalidateAndReload();
                    }
                });
            };

            const openCurriculumModal = (mode, curr = null) => {
                curriculumModalMode.value = mode;
                selectedCurriculum.value = curr ? JSON.parse(JSON.stringify(curr)) : { program: '', subject: '', yearLevel: '1st Year', semester: '1st Semester', elective: false };
                getModalInstance('curriculumModal')?.show();
            };
            const saveCurriculum = (curr) => {
                Api.saveCurriculum(curr).then(res => {
                    if (res.success) {
                        curriculum.value = res.data || [];
                        hideModal('curriculumModal');
                        invalidateAndReload();
                    }
                });
            };
            const deleteCurriculum = async (id) => {
                const confirmRes = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to delete this curriculum mapping?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (!confirmRes.isConfirmed) return;
                Api.deleteCurriculum(id).then(res => {
                    if (res.success) {
                        curriculum.value = res.data || [];
                        invalidateAndReload();
                    }
                });
            };

            const openPeriodModal = (mode, period = null) => {
                periodModalMode.value = mode;
                selectedPeriod.value = period ? JSON.parse(JSON.stringify(period)) : { name: '', academicYear: '', semester: '1st Semester', enrollmentStart: '', enrollmentEnd: '', status: 'Inactive' };
                getModalInstance('periodModal')?.show();
            };
            const saveAcademicPeriod = (period) => {
                Api.saveAcademicPeriod(period).then(res => {
                    if (res.success) {
                        academicPeriods.value = res.data || [];
                        hideModal('periodModal');
                        invalidateAndReload();
                    }
                });
            };
            const deleteAcademicPeriod = async (id) => {
                const confirmRes = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to delete this academic period?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (!confirmRes.isConfirmed) return;
                Api.deleteAcademicPeriod(id).then(res => {
                    if (res.success) {
                        academicPeriods.value = res.data || [];
                        invalidateAndReload();
                    }
                });
            };

            const openSectionModal = (mode, section = null) => {
                sectionModalMode.value = mode;
                selectedSection.value = section ? JSON.parse(JSON.stringify(section)) : { program: '', yearLevel: '1st Year', semester: '1st Semester', subject: '', code: '', instructor: '', days: '', time: '', room: '', capacity: 40 };
                getModalInstance('sectionModal')?.show();
            };
            const saveSubjectSection = (sect) => {
                Api.saveSubjectSection(sect).then(res => {
                    if (res.success) {
                        subjectSections.value = res.data || [];
                        hideModal('sectionModal');
                        invalidateAndReload();
                    }
                });
            };
            const deleteSubjectSection = async (id) => {
                const confirmRes = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to delete this class section?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (!confirmRes.isConfirmed) return;
                Api.deleteSubjectSection(id).then(res => {
                    if (res.success) {
                        subjectSections.value = res.data || [];
                        invalidateAndReload();
                    }
                });
            };

            const openFeeModal = (mode, fee = null) => {
                feeModalMode.value = mode;
                selectedFee.value = fee ? JSON.parse(JSON.stringify(fee)) : { type: 'Tuition', label: '', amount: 0, perUnit: false };
                getModalInstance('feeModal')?.show();
            };
            const saveFee = (fee) => {
                Api.saveFee(fee).then(res => {
                    if (res.success) {
                        feeSchedule.value = res.data || [];
                        hideModal('feeModal');
                        invalidateAndReload();
                    }
                });
            };
            const deleteFee = async (id) => {
                const confirmRes = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to delete this fee item?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (!confirmRes.isConfirmed) return;
                Api.deleteFee(id).then(res => {
                    if (res.success) {
                        feeSchedule.value = res.data || [];
                        invalidateAndReload();
                    }
                });
            };

            const updateRoadmapStep = (refNum, stepId, status) => {
                Api.updateRoadmapStep(refNum, stepId, status).then(res => {
                    if (res.success) {
                        invalidateAndReload();
                    }
                });
            };

            const pendingCount = computed(() => {
                return pendingApplications.value.filter(a => ['PENDING', 'PRE_REGISTERED'].includes(String(a.status).toUpperCase())).length;
            });

            const totalEnrolled = computed(() => {
                return students.value.filter(s => s.status === 'Active').length;
            });

            const newToday = computed(() => {
                return enrollments.value.filter(e => e.status === 'Enrolled').length;
            });

            // ── IN-APP ACCOUNT PROFILE & SECURITY MANAGEMENT ──────────────────────
            const user = ref({ name: '', email: '', username: '', role: '', avatar: null });
            const pass = ref({ current: '', newPass: '', confirm: '' });
            const saving = ref(false);
            const updatingPass = ref(false);
            const showCurrentPass = ref(false);
            const showNewPass = ref(false);
            const fileInput = ref(null);
            const passStrengthLevel = ref(0);
            const avatarFailed = ref(false);

            const initials = computed(() => {
                const name = user.value.name || (currentUser.value ? currentUser.value.name : 'Registrar Staff');
                const parts = name.trim().split(' ');
                return parts.length > 1 ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase() : parts[0][0].toUpperCase();
            });

            const formattedAvatar = computed(() => {
                const avatar = user.value.avatar || (currentUser.value ? currentUser.value.avatar : null);
                if (!avatar) return null;
                if (avatar.startsWith('http://') || avatar.startsWith('https://') || avatar.startsWith('data:')) return avatar;
                const filename = avatar.split('/').pop();
                return '../uploads/avatars/' + filename;
            });

            const formattedStudentPhoto = (photo) => {
                if (!photo) return '';
                if (photo.startsWith('http://') || photo.startsWith('https://') || photo.startsWith('data:')) return photo;
                const filename = photo.split('/').pop();
                return '../uploads/avatars/' + filename;
            };

            const passStrengthLabel = computed(() => {
                const l = passStrengthLevel.value;
                if (l <= 1) return 'Weak'; if (l === 2) return 'Fair'; if (l === 3) return 'Good'; return 'Strong';
            });
            const passStrengthColor = computed(() => {
                const l = passStrengthLevel.value;
                if (l <= 1) return '#ef4444'; if (l === 2) return '#f59e0b'; if (l === 3) return '#10b981'; return '#059669';
            });
            const passStrengthWidth = computed(() => (passStrengthLevel.value / 4 * 100) + '%');

            function checkPassStrength() {
                const p = pass.value.newPass;
                let score = 0;
                if (p.length >= 8) score++; if (/[A-Z]/.test(p)) score++; if (/[0-9]/.test(p)) score++; if (/[^A-Za-z0-9]/.test(p)) score++;
                passStrengthLevel.value = Math.max(p.length >= 6 ? 1 : 0, score);
            }

            function triggerFileInput() { if (fileInput.value) fileInput.value.click(); }

            const loadProfile = async () => {
                if (currentUser.value) {
                    user.value.name = currentUser.value.name || '';
                    user.value.email = currentUser.value.email || '';
                    user.value.username = currentUser.value.username || 'registrar';
                    user.value.role = currentUser.value.role || 'REGISTRAR';
                    user.value.avatar = currentUser.value.avatar || null;
                }
                try {
                    const username = user.value.username || 'registrar';
                    const res = await fetch('../api/index.php?action=auth/profile&username=' + encodeURIComponent(username));
                    const data = await res.json();
                    if (data.success && data.data) {
                        user.value = { ...user.value, ...data.data };
                    }
                } catch (e) { console.error('[Profile] Staff fetch failed:', e); }
            };

            const onFileSelected = async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) { Swal.fire('File Too Large', 'Please select an image smaller than 5MB.', 'warning'); return; }
                const reader = new FileReader();
                reader.onload = async (ev) => {
                    const b64 = ev.target.result;
                    try {
                        const res = await fetch('../api/index.php?action=auth/upload_avatar', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ username: user.value.username || 'registrar', photoData: b64 })
                        });
                        const data = await res.json();
                        if (data.success && data.data) {
                            const newFilename = data.data.avatar || data.data.photo;
                            user.value.avatar = newFilename;
                            if (currentUser.value) currentUser.value.avatar = newFilename;
                            const raw = sessionStorage.getItem('gncp_station_user');
                            if (raw) {
                                const p = JSON.parse(raw);
                                p.avatar = newFilename;
                                sessionStorage.setItem('gncp_station_user', JSON.stringify(p));
                            }
                            Swal.fire('Success', 'Profile picture updated successfully.', 'success');
                        } else { Swal.fire('Upload Failed', data.message || 'Unable to update profile picture.', 'error'); }
                    } catch (err) { Swal.fire('Error', 'Unable to process image upload.', 'error'); }
                };
                reader.readAsDataURL(file);
            };

            const saveStaffProfile = async () => {
                saving.value = true;
                try {
                    const avatarFilename = user.value.avatar ? user.value.avatar.split('/').pop() : null;
                    const res = await fetch('../api/index.php?action=auth/update_profile', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            username: user.value.username,
                            name: user.value.name,
                            email: user.value.email,
                            avatar: avatarFilename
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        if (currentUser.value) {
                            currentUser.value.name = user.value.name;
                            currentUser.value.email = user.value.email;
                            currentUser.value.avatar = avatarFilename;
                        }
                        const raw = sessionStorage.getItem('gncp_station_user');
                        if (raw) {
                            const p = JSON.parse(raw);
                            p.name = user.value.name;
                            p.email = user.value.email;
                            p.avatar = avatarFilename;
                            sessionStorage.setItem('gncp_station_user', JSON.stringify(p));
                        }
                        Swal.fire('Success', 'Personal details updated successfully.', 'success');
                    } else { Swal.fire('Update Failed', data.message || 'Unable to update profile.', 'error'); }
                } catch (e) { Swal.fire('Error', 'Server error while saving profile.', 'error'); }
                finally { saving.value = false; }
            };

            const updatePassword = async () => {
                if (pass.value.newPass !== pass.value.confirm) { Swal.fire('Password Mismatch', 'New password and confirm password do not match.', 'warning'); return; }
                if (pass.value.newPass.length < 6) { Swal.fire('Weak Password', 'New password must be at least 6 characters.', 'warning'); return; }
                updatingPass.value = true;
                try {
                    const res = await fetch('../api/index.php?action=auth/change_password', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ username: user.value.username, current_password: pass.value.current, new_password: pass.value.newPass })
                    });
                    const data = await res.json();
                    if (data.success) {
                        pass.value = { current: '', newPass: '', confirm: '' };
                        passStrengthLevel.value = 0;
                        Swal.fire('Success', 'Password changed successfully.', 'success');
                    } else { Swal.fire('Password Error', data.message || 'Unable to update password.', 'error'); }
                } catch (e) { Swal.fire('Error', 'Server connection error while changing password.', 'error'); }
                finally { updatingPass.value = false; }
            };

            watch(currentView, (newV) => {
                if (newV === 'profile') {
                    loadProfile();
                }
            });

            return {
                currentUser,
                isCheckingSession,
                isLoggingIn,
                loginError,
                loginForm,
                handleLogout,


                currentView,
                searchText,

                navItems,
                reports,

                programs,
                subjects,
                curriculum,
                academicPeriods,
                subjectSections,
                feeSchedule,
                students,
                enrollments,
                pendingApplications,
                sections,

                topBarEyebrow,
                topBarTitle,
                searchPlaceholder,

                currentView,
                setView,

                pendingCount,
                totalEnrolled,
                newToday,
                openApplicationModal,
                printForm,
                updateApplicationStatus,
                updateRoadmapStep,
                getDocKey,
                getDocEntry,
                getDocStatus,
                setDocStatus,
                getDocUndertakingDeadline,
                setDocUndertakingDeadline,
                getDocRemarks,
                setDocRemarks,
                hasActiveUndertakings,
                getActiveUndertakings,
                getTransmittalData,
                setTransmittalStatus,
                setTransmittalDate,
                isDocVerified,
                toggleDocStatus,
                requirementsError,
                showRequirementsValidation,
                selectedApplication,
                openApplicationModal,
                selectedStudent,
                openStudentModal,
                availableSectionsForApplication,
                isAdmin,

                // Logout confirmation
                showLogoutConfirm,
                handleLogout,
                confirmLogout,

                // Profile & Security Management
                user, pass, saving, updatingPass, showCurrentPass, showNewPass, fileInput,
                passStrengthLevel, passStrengthLabel, passStrengthColor, passStrengthWidth,
                initials, formattedAvatar, formattedStudentPhoto, checkPassStrength, triggerFileInput, onFileSelected,
                saveStaffProfile, updatePassword, loadProfile,

                activePreviewDoc,
                getDocFile,
                getDocFileUrl,
                openDocumentModal,
                isImageFile,
                isPdfFile,

                // Academic Catalog States & Methods
                selectedProgram,
                programModalMode,
                selectedSubject,
                subjectModalMode,
                selectedCurriculum,
                curriculumModalMode,
                selectedPeriod,
                periodModalMode,
                selectedSection,
                sectionModalMode,
                selectedFee,
                feeModalMode,

                openProgramModal,
                saveProgram,
                deleteProgram,
                openSubjectModal,
                saveSubject,
                deleteSubject,
                openCurriculumModal,
                saveCurriculum,
                deleteCurriculum,
                openPeriodModal,
                saveAcademicPeriod,
                deleteAcademicPeriod,
                openSectionModal,
                saveSubjectSection,
                deleteSubjectSection,
                reviewHistory,
                fetchReviewHistory,
                isLoadingData,
                isLoadingHistory,
                openFeeModal,
                saveFee,
                deleteFee,
                timeGreeting,
                navGroups
            };
        }
    };

    const app = createApp(App);

    if (typeof window !== 'undefined' && window.EmployeeSidebar) {
        app.component('employee-sidebar', window.EmployeeSidebar);
    }

    // Component registration mapped to views
    if (typeof View !== 'undefined' && View) {
        if (View.SidebarNav) app.component('sidebar-nav', View.SidebarNav);
        if (View.TopBar) app.component('top-bar', View.TopBar);
        if (View.StudentsView) app.component('students-view', View.StudentsView);
        if (View.PendingApplicationsView) app.component('pending-applications-view', View.PendingApplicationsView);
        if (View.ReviewHistoryView) app.component('review-history-view', View.ReviewHistoryView);
        if (View.EnrollmentOverviewView) app.component('enrollment-overview-view', View.EnrollmentOverviewView);
        if (View.ReportsView) app.component('reports-view', View.ReportsView);
        if (View.ProgramsView) app.component('programs-view', View.ProgramsView);
        if (View.SubjectsView) app.component('subjects-view', View.SubjectsView);
        if (View.CurriculumView) app.component('curriculum-view', View.CurriculumView);
        if (View.AcademicPeriodsView) app.component('academic-periods-view', View.AcademicPeriodsView);
        if (View.SubjectSectionsView) app.component('subject-sections-view', View.SubjectSectionsView);
        if (View.FeeScheduleView) app.component('fee-schedule-view', View.FeeScheduleView);
    }

    const mountApp = () => {
        if (document.getElementById('app')) {
            const vm = app.mount('#app');
            if (typeof window !== 'undefined') window.app = vm;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountApp);
    } else {
        mountApp();
    }
})(typeof window !== 'undefined' ? window : this);
