(() => {
    const createApp = Vue.createApp;
    const ref = Vue.ref;
    const reactive = Vue.reactive;
    const computed = Vue.computed;
    const onMounted = Vue.onMounted;
    const onUnmounted = Vue.onUnmounted;

    const App = {
        components: {
            'employee-sidebar': window.EmployeeSidebar || window.StationSidebar,
            'station-sidebar': window.StationSidebar || window.EmployeeSidebar
        },
        setup() {
            const currentView = ref('dashboard');
            const searchQuery = ref('');
            const activeFilter = ref('All');
            const sortBy = ref('arrivedAt');
            const sortDesc = ref(false); // FIFO: Earliest arrivals served first (First In, First Out)
            const selectedStudent = ref(null);
            const studentsList = ref([]);

            // Authentication State
            const getStoredUser = () => {
                try {
                    const raw = sessionStorage.getItem('gncp_station_user') || sessionStorage.getItem('gncp_admin_user');
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (parsed && ['IT_CENTER', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'].includes(parsed.role)) {
                            return parsed;
                        }
                    }
                } catch (e) {}
                return null;
            };
            const currentUser = ref(getStoredUser());
            const isLoggingIn = ref(false);
            const loginError = ref('');
            const loginForm = reactive({
                username: '',
                password: ''
            });

            const timeGreeting = computed(() => {
                const hour = new Date().getHours();
                if (hour < 12) return 'Great Morning';
                if (hour < 18) return 'Great Afternoon';
                return 'Great Evening';
            });

            const setFilter = (filter) => {
                activeFilter.value = filter;
            };

            const toggleSort = (field) => {
                if (sortBy.value === field) {
                    sortDesc.value = !sortDesc.value;
                } else {
                    sortBy.value = field;
                    sortDesc.value = false;
                }
            };

            const getSortIcon = (field) => {
                if (sortBy.value !== field) return 'fa-solid fa-sort text-muted ms-1';
                return sortDesc.value ? 'fa-solid fa-sort-down text-success ms-1' : 'fa-solid fa-sort-up text-success ms-1';
            };

            const getQueueRank = (student) => {
                const pendingList = filteredStudents.value.filter(s => getItStepStatus(s) !== 'COMPLETED');
                const idx = pendingList.findIndex(s => s.referenceNumber === student.referenceNumber);
                return idx >= 0 ? idx + 1 : null;
            };

            // Existing student accounts view state
            const accountsList = ref([]);
            const filterAccountProgram = ref('');
            const filterAccountYear = ref('');
            const filterAccountStatus = ref('');

            // Review / Enrollment Form State
            const generatedStudentId = ref('');
            const generatedEmail = ref('');
            const generatedPassword = ref('');
            const photoUploaded = ref(false);
            const photoFileName = ref('');
            const useRealCamera = ref(false);
            const capturedPhotoDataUrl = ref('');
            const mediaStream = ref(null);
            const isFinalizingEnrollment = ref(false);
            const savedPhotoWebPath = ref('');
            const selectedSections = ref({});
            const showCOR = ref(false);
            const corDetails = ref(null);

            // Dashboard stats (from backend aggregate)
            const dashboardStats = ref({
                pendingActivation: 0,
                activatedToday: 0,
                readyForIt: 0
            });

            // Toast notification state
            const toast = reactive({ show: false, message: '', type: 'success' });
            let toastTimer = null;
            const showToast = (message, type = 'success') => {
                clearTimeout(toastTimer);
                toast.message = message;
                toast.type    = type;
                toast.show    = true;
                toastTimer = setTimeout(() => { toast.show = false; }, 3800);
            };

            // Date & Time
            const currentDateTime = ref('');
            const updateTime = () => {
                currentDateTime.value = new Date().toLocaleString('en-US', {
                    timeZone: 'Asia/Manila',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });
            };

            const loadQueue = () => {
                const queue = StationDataBus.getQueue();
                const result = [];
                for (let i = 0; i < queue.length; i++) {
                    const student = queue[i];
                    if (typeof StationPipeline !== 'undefined') {
                        if (!StationPipeline.isAtOrPastStation('it', student)) continue;
                    } else {
                        const statusUpper = String(student.status || '').toUpperCase();
                        const isAtOrPastIT = ['PAID', 'ENROLLED', 'PROMOTED', 'ACTIVE'].includes(statusUpper);
                        if (!isAtOrPastIT) continue;
                    }

                    const padId = String(student.id || (i + 1)).padStart(3, '0');

                    // Normalize via StationPipeline for consistent field shape
                    const normalized = (typeof StationPipeline !== 'undefined')
                        ? StationPipeline.normalizeStudent(student, i, 'it')
                        : student;

                    // Override IT-specific ticket & arrival fields
                    normalized.queueTicket = (student.queueTickets && student.queueTickets.it_center)
                        ? student.queueTickets.it_center
                        : ('ITC-' + padId);
                    normalized.arrivedAt = (student.stationArrivals && student.stationArrivals.it_center)
                        ? student.stationArrivals.it_center
                        : (student.createdAt || student.datePreRegistered || '');

                    result.push(normalized);
                }
                studentsList.value = result;
                fetchDashboardStats();
            };

            const fetchCurrentProfile = () => {
                fetch('../../api/index.php?action=auth/profile')
                    .then(res => res.json())
                    .then(res => {
                        if (res && res.success && res.data) {
                            const prof = res.data;
                            const avatarUrl = prof.avatar || prof.photo || prof.image || null;
                            if (avatarUrl && currentUser.value) {
                                currentUser.value.avatar = avatarUrl;
                                if (prof.name) currentUser.value.name = prof.name;
                                const key = (currentUser.value.role === 'SUPER_ADMIN' || currentUser.value.role === 'ADMIN') ? 'gncp_admin_user' : 'gncp_station_user';
                                sessionStorage.setItem(key, JSON.stringify(currentUser.value));
                            }
                        }
                    }).catch(() => {});
            };

            const checkSession = async () => {
                localStorage.removeItem('gncp_station_user');
                localStorage.removeItem('gncp_admin_user');

                // Optimistically load session from tab-scoped sessionStorage for 0ms initial render
                const cachedRaw = sessionStorage.getItem('gncp_station_user') || sessionStorage.getItem('gncp_admin_user');
                if (cachedRaw) {
                    try {
                        const parsed = JSON.parse(cachedRaw);
                        if (parsed && ['IT_CENTER', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'].includes(parsed.role)) {
                            currentUser.value = parsed;
                        }
                    } catch (e) {}
                }

                // Immediately load queue & stats in parallel with background session check
                loadQueue();
                fetchDashboardStats();

                try {
                    const res = await fetch('../../api/index.php?action=auth/check', { credentials: 'same-origin' });
                    if (res.ok) {
                        const result = await res.json();
                        const allowedRoles = ['IT_CENTER', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'];
                        if (result.success && result.data && allowedRoles.includes(result.data.role)) {
                            currentUser.value = result.data;
                            const sessionKey = (result.data.role === 'SUPER_ADMIN' || result.data.role === 'ADMIN') ? 'gncp_admin_user' : 'gncp_station_user';
                            sessionStorage.setItem(sessionKey, JSON.stringify(result.data));
                            fetchCurrentProfile();
                            if (result.data.must_change_password && typeof window.PasswordChangeGuard !== 'undefined') {
                                window.PasswordChangeGuard.checkAndPrompt(result.data, function() {
                                    loadQueue();
                                });
                            }
                            return;
                        }
                    }
                } catch (e) {
                    console.warn('[IT Center] Session check warning:', e);
                }

                if (!currentUser.value) {
                    if (typeof window.SessionExpirationGuard !== 'undefined') {
                        window.SessionExpirationGuard.handleExpiredSession({
                            title: 'Session Expired',
                            message: 'Your IT Center workstation session has expired. Please sign in again to continue account activations.',
                            reason: 'expired'
                        });
                    } else {
                        sessionStorage.removeItem('gncp_station_user');
                        sessionStorage.removeItem('gncp_admin_user');
                        window.location.href = '../../?session_expired=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                    }
                }
            };



            // Fetch live stats from backend for dashboard counters
            const fetchDashboardStats = () => {
                fetch('../backend/api.php?action=get_enrollment_stats')
                    .then(res => res.json())
                    .then(result => {
                        if (result.success && result.data) {
                            dashboardStats.value = result.data;
                        }
                    })
                    .catch(() => { /* silently ignore — stats are non-critical */ });
            };

            let clockTimer = null;
            const stopLiveSync = () => {
                if (clockTimer) {
                    clearInterval(clockTimer);
                    clockTimer = null;
                }
                if (window.StationDataBus && typeof window.StationDataBus.stopPolling === 'function') {
                    window.StationDataBus.stopPolling();
                }
            };

            const showLogoutConfirm = ref(false);

            const handleLogout = () => {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Are you sure?',
                        text: 'Are you sure you want to log out of the IT Center Workstation?',
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
                    fetch('../../api/index.php?action=auth/logout', { method: 'POST', keepalive: true }).catch(() => {});
                } catch (e) {}

                window.location.replace('../../?clear=true&logout=true');
            };

            // Metrics
            const totalInQueue = computed(() => {
                let count = 0;
                for (let i = 0; i < studentsList.value.length; i++) {
                    const step = studentsList.value[i].roadmap ? studentsList.value[i].roadmap.find(r => r.stepId === 'it_activation' || r.stepId === 'id_email_final') : null;
                    if (step && step.status !== 'COMPLETED') {
                        count++;
                    }
                }
                return count;
            });

            const completedToday = computed(() => {
                let count = 0;
                for (let i = 0; i < studentsList.value.length; i++) {
                    const step = studentsList.value[i].roadmap ? studentsList.value[i].roadmap.find(r => r.stepId === 'it_activation' || r.stepId === 'id_email_final') : null;
                    if (step && step.status === 'COMPLETED') {
                        count++;
                    }
                }
                return count;
            });

            const filteredStudents = computed(() => {
                const query = searchQuery.value.trim().toLowerCase();
                const list = [];
                for (let i = 0; i < studentsList.value.length; i++) {
                    const s = studentsList.value[i];
                    const step = s.roadmap ? s.roadmap.find(r => r.stepId === 'it_activation' || r.stepId === 'id_email_final') : null;
                    
                    let matchesQuery = true;
                    if (query) {
                        matchesQuery = s.name.toLowerCase().includes(query) || 
                                       s.referenceNumber.toLowerCase().includes(query) ||
                                       (s.queueTicket && s.queueTicket.toLowerCase().includes(query));
                    }

                    let matchesFilter = false;
                    if (activeFilter.value === 'All') {
                        matchesFilter = true;
                    } else if (activeFilter.value === 'Pending' && step && step.status !== 'COMPLETED') {
                        matchesFilter = true;
                    } else if (activeFilter.value === 'Completed' && step && step.status === 'COMPLETED') {
                        matchesFilter = true;
                    }

                    if (matchesQuery && matchesFilter) {
                        list.push(s);
                    }
                }

                // FIFO (First In, First Out) / Header Sorting Logic
                return [...list].sort((a, b) => {
                    let vA = a[sortBy.value] || '';
                    let vB = b[sortBy.value] || '';
                    if (sortBy.value === 'arrivedAt' || sortBy.value === 'createdAt') {
                        vA = vA ? new Date(vA).getTime() : (a.id || 0);
                        vB = vB ? new Date(vB).getTime() : (b.id || 0);
                        return sortDesc.value ? vB - vA : vA - vB;
                    }
                    if (typeof vA === 'string') vA = vA.toLowerCase();
                    if (typeof vB === 'string') vB = vB.toLowerCase();
                    if (vA < vB) return sortDesc.value ? 1 : -1;
                    if (vA > vB) return sortDesc.value ? -1 : 1;
                    return 0;
                });
            });

            const nextInQueue = computed(() => {
                return filteredStudents.value.find(s => getItStepStatus(s) !== 'COMPLETED') || filteredStudents.value[0] || null;
            });

            const callNextForId = () => {
                if (nextInQueue.value) {
                    openReview(nextInQueue.value);
                }
            };

            const loadAccounts = () => {
                fetch('../backend/api.php?action=fetch_student_accounts')
                    .then(res => res.json())
                    .then(result => {
                        if (result.success && result.data) {
                            accountsList.value = result.data;
                        }
                    })
                    .catch(err => {
                        console.error('Failed to load student accounts:', err);
                    });
            };

            const filteredAccounts = computed(() => {
                const query = searchQuery.value.trim().toLowerCase();
                let list = accountsList.value || [];

                // Text Search Filter
                if (query) {
                    list = list.filter(acc => 
                        (acc.id && acc.id.toLowerCase().includes(query)) ||
                        (acc.name && acc.name.toLowerCase().includes(query)) ||
                        (acc.email && acc.email.toLowerCase().includes(query)) ||
                        (acc.program && acc.program.toLowerCase().includes(query))
                    );
                }

                // Program Filter
                if (filterAccountProgram.value) {
                    list = list.filter(acc => acc.program === filterAccountProgram.value);
                }

                // Year Level Filter
                if (filterAccountYear.value) {
                    list = list.filter(acc => acc.yearLevel === filterAccountYear.value || acc.year_level === filterAccountYear.value);
                }

                // Status Filter
                if (filterAccountStatus.value) {
                    list = list.filter(acc => acc.status === filterAccountStatus.value);
                }

                return list;
            });

            const uniqueAccountPrograms = computed(() => {
                return [...new Set(accountsList.value.map(acc => acc.program).filter(Boolean))].sort();
            });

            const setView = (view) => {
                currentView.value = view;
                searchQuery.value = '';
                if (view === 'accounts') {
                    loadAccounts();
                }
            };

            const openReview = (student) => {
                selectedStudent.value = student;
                photoUploaded.value = false;
                photoFileName.value = '';
                capturedPhotoDataUrl.value = '';
                useRealCamera.value = false;

                // Initialize section selectors
                selectedSections.value = {};
                const subjects = student.prospectusSubjects || [];
                const sections = student.availableSections || [];
                subjects.forEach(sub => {
                    const matches = sections.filter(sec => sec.subject === sub.title);
                    if (matches.length > 0) {
                        selectedSections.value[sub.code] = matches[0].code;
                    }
                });

                // If already completed/enrolled, load existing data
                if (student.enrollment && student.enrollment.permanentId) {
                    generatedStudentId.value = student.enrollment.permanentId;
                    generatedEmail.value = student.enrollment.institutionalEmail;
                    generatedPassword.value = student.enrollment.password;
                    photoUploaded.value = true;
                    photoFileName.value = student.enrollment.photoFile || 'id_portrait.jpg';
                    capturedPhotoDataUrl.value = student.enrollment.photoDataUrl || '';
                    if (student.enrollment.sections) {
                        selectedSections.value = { ...student.enrollment.sections };
                    }
                } else {
                    // Generate new credentials
                    const cleanFirst = student.name.split(' ')[0].toLowerCase().replace(/[^a-z]/g, '');
                    const cleanLast = student.name.split(' ').pop().toLowerCase().replace(/[^a-z]/g, '');
                    const randomSuffix = Math.floor(10 + Math.random() * 90);
                    generatedEmail.value = `${cleanFirst}.${cleanLast}${randomSuffix}@gncp.edu.ph`;

                    // Load next sequential student ID from server
                    fetch('../backend/api.php?action=get_next_student_id')
                        .then(res => res.json())
                        .then(res => {
                            if (res.success && res.data) {
                                generatedStudentId.value = res.data.nextStudentId;
                            } else {
                                throw new Error('Failed to generate secure Student ID.');
                            }
                        })
                        .catch(() => {
                            showToast('Server failed to generate a secure Student ID. Please try again.', 'error');
                            const modal = bootstrap.Modal.getInstance(document.getElementById('itReviewModal'));
                            if (modal) modal.hide();
                        });

                    const nameParts = (student.name || '').trim().split(' ');
                    const rawLast = student.lastName || nameParts[nameParts.length - 1] || 'delacruz';
                    const cleanPasswordLast = rawLast.toLowerCase().replace(/[^a-z0-9]/g, '');
                    generatedPassword.value = cleanPasswordLast || 'delacruz';
                }

                const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('itReviewModal'));
                modal.show();
            };

            const finalizeEnrollment = async () => {
                if (!selectedStudent.value) return;
                if (getItStepStatus(selectedStudent.value) === 'COMPLETED') return;
                if (isFinalizingEnrollment.value) return;

                isFinalizingEnrollment.value = true;

                try {
                    const student = selectedStudent.value;
                    const enrollmentData = {
                        permanentId:        generatedStudentId.value,
                        institutionalEmail: generatedEmail.value,
                        password:           generatedPassword.value,
                        photoFile:          photoFileName.value || null,
                        photoWebPath:       savedPhotoWebPath.value || null,
                        photoDataUrl:       capturedPhotoDataUrl.value || null,
                        activatedBy:        currentUser.value?.name || 'IT Officer',
                        dateActivated:      new Date().toLocaleDateString('en-PH', {
                            year: 'numeric', month: 'long', day: 'numeric'
                        }),
                        sections:           { ...selectedSections.value }
                    };

                    const updatePayload = JSON.parse(JSON.stringify(student));
                    updatePayload.enrollment = enrollmentData;
                    updatePayload.status = 'ENROLLED';
                    const itStepIdx = (updatePayload.roadmap && Array.isArray(updatePayload.roadmap)) ? updatePayload.roadmap.findIndex(r => r.stepId === 'it_activation' || r.stepId === 'id_email_final' || r.name === 'IT Center ID' || r.id === 7) : -1;
                    if (itStepIdx !== -1) {
                        updatePayload.roadmap[itStepIdx].status = 'COMPLETED';
                        updatePayload.roadmap[itStepIdx].updatedAt = new Date().toISOString();
                    }

                    const apiUrl = (typeof StationDataBus !== 'undefined' && StationDataBus.getApiUrl)
                        ? StationDataBus.getApiUrl('stations/update')
                        : (window.location.pathname.startsWith('/systemtest') ? '/systemtest/api/index.php?action=stations/update' : '/api/index.php?action=stations/update');

                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            referenceNumber: student.referenceNumber,
                            updateData: updatePayload
                        })
                    });

                    if (!response.ok) {
                        throw new Error('Server rejected finalization request.');
                    }

                    const result = await response.json();
                    if (!result.success) {
                        throw new Error(result.error || result.message || 'Server transaction failed.');
                    }

                    const promoCreds = result.data || {};
                    enrollmentData.permanentId = promoCreds.permanentId || enrollmentData.permanentId;
                    enrollmentData.institutionalEmail = promoCreds.institutionalEmail || enrollmentData.institutionalEmail;
                    enrollmentData.password = promoCreds.password || enrollmentData.password;

                    StationDataBus.updateStudent(student.referenceNumber, (s) => {
                        s.enrollment = enrollmentData;
                        s.status = 'ENROLLED';
                        const itStepIdx = (s.roadmap && Array.isArray(s.roadmap)) ? s.roadmap.findIndex(r => r && (r.stepId === 'it_activation' || r.stepId === 'id_email_final' || r.name === 'IT Center ID' || r.title === 'Student Portal Account Activation')) : -1;
                        if (itStepIdx !== -1) {
                            s.roadmap[itStepIdx].status = 'COMPLETED';
                            s.roadmap[itStepIdx].updatedAt = new Date().toISOString();
                        }
                    }, ['enrollment', 'roadmap', 'status']);

                    corDetails.value = {
                        student: { ...student, enrollment: enrollmentData }
                    };

                    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('itReviewModal'));
                    modal.hide();

                    showToast(`Account activated for ${student.name}`, 'success');
                    showCOR.value = true;
                    loadQueue();

                } catch (err) {
                    console.error('[IT Center] Finalization error:', err);
                    showToast(err.message || 'An error occurred during activation. Please try again.', 'error');
                } finally {
                    isFinalizingEnrollment.value = false;
                }
            };

            const getItStepStatus = (student) => {
                if (typeof StationPipeline !== 'undefined') {
                    return StationPipeline.getStepStatus('it', student);
                }
                if (!student) return 'PENDING';
                const statusUpper = String(student.status || '').toUpperCase();
                if (['ENROLLED', 'PROMOTED', 'ACTIVE'].includes(statusUpper)) {
                    return 'COMPLETED';
                }
                return 'PENDING';
            };

            const getStepIcon = (index) => {
                if (index === 0) return 'fa-solid fa-file-invoice';
                if (index === 1) return 'fa-solid fa-file-circle-check';
                if (index === 2) return 'fa-solid fa-headset';
                if (index === 3) return 'fa-solid fa-heart-pulse';
                if (index === 4) return 'fa-solid fa-award';
                if (index === 5) return 'fa-solid fa-credit-card';
                return 'fa-solid fa-id-card';
            };
            const getSortedSectionsForSubject = (subTitle) => {
                if (!selectedStudent.value || !selectedStudent.value.availableSections) return [];
                return [...selectedStudent.value.availableSections]
                    .filter(x => x.subject === subTitle)
                    .sort((a, b) => (a.code || '').localeCompare(b.code || ''));
            };

            onMounted(() => {
                checkSession();
                updateTime();
                clockTimer = setInterval(updateTime, 1000);

                document.addEventListener('hide.bs.modal', () => {
                    if (document.activeElement && typeof document.activeElement.blur === 'function') {
                        document.activeElement.blur();
                    }
                });

                window.addEventListener('storage', () => {
                    if (currentUser.value) {
                        loadQueue();
                    }
                });
            });

            onUnmounted(() => {
                stopLiveSync();
            });

            const formatStatus = (status) => {
                if (!status) return 'Pending';
                const s = String(status).toUpperCase();
                if (['COMPLETED', 'CLEARED', 'FIT', 'VERIFIED', 'PAID', 'ACTIVATED', 'ENROLLED'].includes(s)) return 'Completed';
                if (['IN_PROGRESS', 'IN-PROGRESS', 'PARTIAL', 'CONDITIONAL'].includes(s)) return 'In Progress';
                return s;
            };

            const getStatusBadgeClass = (status) => {
                if (!status) return 'pending';
                const s = String(status).toLowerCase().replace('_', '-');
                if (['completed', 'cleared', 'fit', 'verified', 'paid', 'activated', 'enrolled'].includes(s)) return 'completed';
                if (['in-progress', 'in_progress', 'partial', 'conditional'].includes(s)) return 'in-progress';
                if (['flagged', 'discrepancy', 'unfit', 'rejected'].includes(s)) return 'flagged';
                if (['skipped'].includes(s)) return 'archived';
                return s;
            };

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
                const name = user.value.name || (currentUser.value ? currentUser.value.name : 'IT Center Staff');
                const parts = name.trim().split(' ');
                return parts.length > 1 ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase() : parts[0][0].toUpperCase();
            });

            const formattedAvatar = computed(() => {
                const avatar = user.value.avatar || (currentUser.value ? currentUser.value.avatar : null);
                if (!avatar) return null;
                if (avatar.startsWith('http://') || avatar.startsWith('https://') || avatar.startsWith('data:')) return avatar;
                const filename = avatar.split('/').pop();
                return '../../uploads/avatars/' + filename;
            });

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
                    user.value.username = currentUser.value.username || 'itcenter';
                    user.value.role = currentUser.value.role || 'IT_CENTER';
                    user.value.avatar = currentUser.value.avatar || null;
                }
                try {
                    const username = user.value.username || 'itcenter';
                    const res = await fetch('../../api/index.php?action=auth/profile&username=' + encodeURIComponent(username));
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
                        const res = await fetch('../../api/index.php?action=auth/upload_avatar', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ username: user.value.username || 'itcenter', photoData: b64 })
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
                    const res = await fetch('../../api/index.php?action=auth/update_profile', {
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
                    const res = await fetch('../../api/index.php?action=auth/change_password', {
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

            return {
                currentDateTime,
                currentView,
                searchQuery,
                activeFilter,
                sortBy,
                sortDesc,
                setFilter,
                toggleSort,
                getSortIcon,
                studentsList,
                filteredStudents,
                nextInQueue,
                callNextForId,
                getQueueRank,
                totalInQueue,
                completedToday,
                selectedStudent,
                generatedStudentId,
                generatedEmail,
                generatedPassword,
                photoUploaded,
                photoFileName,
                useRealCamera,
                capturedPhotoDataUrl,
                isFinalizingEnrollment,
                selectedSections,
                showCOR,
                corDetails,
                dashboardStats,
                toast,
                setView,
                openReview,
                finalizeEnrollment,
                getStepIcon,
                getSortedSectionsForSubject,
                getItStepStatus,
                formatStatus,
                getStatusBadgeClass,
                currentUser,
                isLoggingIn,
                loginError,
                loginForm,
                handleLogout,
                showLogoutConfirm,
                confirmLogout,
                accountsList,
                filterAccountProgram,
                filterAccountYear,
                filterAccountStatus,
                filteredAccounts,
                uniqueAccountPrograms,
                loadAccounts,
                timeGreeting,
                // Profile & Security
                user, pass, saving, updatingPass, showCurrentPass, showNewPass, fileInput,
                passStrengthLevel, passStrengthLabel, passStrengthColor, passStrengthWidth,
                initials, formattedAvatar, checkPassStrength, triggerFileInput, onFileSelected,
                saveStaffProfile, updatePassword, loadProfile
            };
        }
    };

    window.app = createApp(App).mount('#app');
})();
