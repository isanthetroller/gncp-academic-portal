const createApp = Vue.createApp;
const ref = Vue.ref;
const reactive = Vue.reactive;
const computed = Vue.computed;
const onMounted = Vue.onMounted;
const onUnmounted = Vue.onUnmounted;

window.app = createApp({
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
        const students = ref([]);

        const timeGreeting = computed(() => {
            const hour = new Date().getHours();
            if (hour < 12) return 'Great Morning';
            if (hour < 18) return 'Great Afternoon';
            return 'Great Evening';
        });

        // Authentication State
        const currentUser = ref(null);
        const isLoggingIn = ref(false);
        const loginError = ref('');
        const loginForm = reactive({
            username: '',
            password: ''
        });

        const getHelpdeskStepStatus = (s) => {
            if (typeof StationPipeline !== 'undefined') {
                return StationPipeline.getStepStatus('helpdesk', s);
            }
            if (!s) return 'PENDING';
            const help = s.helpdesk || {};
            if (['COMPLETED', 'ADVISED', 'CLEARED'].includes(String(help.status || '').toUpperCase())) return 'COMPLETED';
            if (String(help.status || '').toUpperCase() === 'FLAGGED') return 'FLAGGED';
            if (['ADVISED', 'MEDICAL_CLEARED', 'PAID', 'ENROLLED', 'PROMOTED'].includes(String(s.status || '').toUpperCase())) return 'COMPLETED';
            return 'PENDING';
        };

        const loadQueue = () => {
            const queue = StationDataBus.getQueue();
            const result = [];
            for (let i = 0; i < queue.length; i++) {
                const s = queue[i];
                if (typeof StationPipeline !== 'undefined') {
                    if (!StationPipeline.isAtOrPastStation('helpdesk', s)) continue;
                } else {
                    const statusUpper = String(s.status || '').toUpperCase();
                    const isAtOrPast = ['VERIFIED', 'APPROVED', 'ADVISED', 'MEDICAL_CLEARED', 'PAID', 'ENROLLED', 'PROMOTED'].includes(statusUpper);
                    if (!isAtOrPast) continue;
                }

                const normalized = (typeof StationPipeline !== 'undefined')
                    ? StationPipeline.normalizeStudent(s, i, 'helpdesk')
                    : {
                        id: s.referenceNumber || s.id,
                        referenceNumber: s.referenceNumber || s.id,
                        name: s.name || 'Applicant',
                        program: s.program || '---',
                        studentType: s.studentType || 'REGULAR',
                        roadmap: s.roadmap,
                        medical: s.medical,
                        payment: s.payment,
                        form: s.form,
                        prospectusSubjects: s.prospectusSubjects || [],
                        availableSections: s.availableSections || []
                    };

                const help = s.helpdesk || {};
                normalized.nstp = help.nstp || s.nstp || (s.form ? s.form.nstp : 'ROTC') || 'ROTC';
                normalized.tlcNotes = help.tlcNotes || '';
                normalized.status = getHelpdeskStepStatus(s);

                result.push(normalized);
            }
            students.value = result;
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
                    if (parsed && ['HELPDESK', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'].includes(parsed.role)) {
                        currentUser.value = parsed;
                    }
                } catch (e) {}
            }

            // Immediately load queue in parallel with background session check
            loadQueue();

            try {
                const res = await fetch('../../api/index.php?action=auth/check', { credentials: 'same-origin' });
                if (res.ok) {
                    const result = await res.json();
                    const allowedRoles = ['HELPDESK', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'];
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
                console.warn('[Helpdesk] Session check warning:', e);
            }

            if (!currentUser.value) {
                if (typeof window.SessionExpirationGuard !== 'undefined') {
                    window.SessionExpirationGuard.handleExpiredSession({
                        title: 'Session Expired',
                        message: 'Your helpdesk advising session has expired. Please sign in again to continue student evaluation.',
                        reason: 'expired'
                    });
                } else {
                    sessionStorage.removeItem('gncp_station_user');
                    sessionStorage.removeItem('gncp_admin_user');
                    window.location.href = '../../?session_expired=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                }
            }
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
                    text: 'Are you sure you want to log out of the TLC Helpdesk Workstation?',
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
            const pendingList = filteredStudents.value.filter(s => s.status === 'PENDING');
            const idx = pendingList.findIndex(s => s.referenceNumber === student.referenceNumber);
            return idx >= 0 ? idx + 1 : null;
        };

        const filteredStudents = computed(() => {
            const query = searchQuery.value.trim().toLowerCase();
            const result = [];
            for (let i = 0; i < students.value.length; i++) {
                const student = students.value[i];
                
                // Matches query
                let matchesQuery = true;
                if (query) {
                    const nameMatches = student.name.toLowerCase().indexOf(query) !== -1;
                    const refMatches = student.referenceNumber.toLowerCase().indexOf(query) !== -1;
                    const ticketMatches = student.queueTicket && student.queueTicket.toLowerCase().indexOf(query) !== -1;
                    matchesQuery = nameMatches || refMatches || ticketMatches;
                }

                // Matches filter
                let matchesFilter = false;
                if (activeFilter.value === 'All') {
                    matchesFilter = true;
                } else if (activeFilter.value === 'Pending' && student.status === 'PENDING') {
                    matchesFilter = true;
                } else if ((activeFilter.value === 'Cleared' || activeFilter.value === 'Completed') && student.status === 'COMPLETED') {
                    matchesFilter = true;
                } else if (activeFilter.value === 'Flagged' && student.status === 'FLAGGED') {
                    matchesFilter = true;
                }

                if (matchesQuery && matchesFilter) {
                    result.push(student);
                }
            }

            // FIFO (First In, First Out) / Header Sorting Logic
            return [...result].sort((a, b) => {
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
            return filteredStudents.value.find(s => s.status === 'PENDING') || filteredStudents.value[0] || null;
        });

        const callNextStudent = () => {
            if (nextInQueue.value) {
                openReview(nextInQueue.value);
            }
        };

        const pendingCount = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'PENDING') {
                    count++;
                }
            }
            return count;
        });

        const completedToday = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'COMPLETED') {
                    count++;
                }
            }
            return count;
        });

        const flaggedCount = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'FLAGGED') {
                    count++;
                }
            }
            return count;
        });

        const totalInQueue = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status !== 'COMPLETED') {
                    count++;
                }
            }
            return count;
        });

        const setView = (view) => {
            currentView.value = view;
        };

        const currentTitle = computed(() => {
            if (currentView.value === 'dashboard') return 'Dashboard';
            if (currentView.value === 'queue') return 'Student Queue';
            return '';
        });

        const openReview = (student) => {
            selectedStudent.value = student;
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
            modal.show();
        };

        const getStepIcon = (index) => {
            if (index === 0) return 'fa-solid fa-file-invoice';
            if (index === 1) return 'fa-solid fa-file-circle-check';
            if (index === 2) return 'fa-solid fa-headset';
            if (index === 3) return 'fa-solid fa-heart-pulse';
            if (index === 4) return 'fa-solid fa-credit-card';
            return 'fa-solid fa-id-card';
        };

        const persistStudentUpdate = (student) => {
            StationDataBus.updateStudent(student.referenceNumber, (s) => {
                if (!s.helpdesk || typeof s.helpdesk !== 'object') {
                    s.helpdesk = {};
                }
                s.helpdesk.status = student.status;
                s.helpdesk.nstp   = student.nstp;
                s.helpdesk.tlcNotes = student.tlcNotes;

                // Sync global roadmap advising step
                const currentStepIdx = (s.roadmap && Array.isArray(s.roadmap)) ? s.roadmap.findIndex(r => r && (r.stepId === 'advising_assessment' || r.stepId === 'academic_advising' || r.name === 'Academic Advising' || r.title === 'Academic Advising & Block Sectioning' || r.id === 3)) : -1;
                if (currentStepIdx !== -1) {
                    if (student.status === 'COMPLETED') {
                        s.roadmap[currentStepIdx].status = 'COMPLETED';
                        s.status = 'ADVISED';

                        // Compute dynamic tuition assessment based on prospectus subjects
                        const subjects = student.prospectusSubjects || [];
                        let totalUnits = 0;
                        let totalLabFee = 0;
                        subjects.forEach(sub => {
                            const lec = parseInt(sub.lecture_units) || 0;
                            const lab = parseInt(sub.lab_units) || 0;
                            totalUnits += (lec + lab);
                            totalLabFee += parseFloat(sub.lab_fee) || 0;
                        });

                        const tuition = totalUnits * 650;
                        const misc = 2300; // standard Registration (1500) + Library (800) fees
                        const totalAssessment = tuition + totalLabFee + misc;

                        // Update payment ledger dynamically
                        if (!s.payment || typeof s.payment !== 'object') {
                            s.payment = {};
                        }
                        s.payment.totalFee = totalAssessment;
                        s.payment.balance = totalAssessment;
                        s.payment.amountPaid = 0;
                        s.payment.status = 'PENDING';

                        // Save advised subjects list in helpdesk data JSON
                        s.helpdesk.advisedSubjects = subjects;

                        // Auto-open next step (Medical Clearance)
                        const nextStep = s.roadmap.slice(currentStepIdx + 1).find(r => r && (['PENDING', 'LOCKED'].includes(String(r.status || '').toUpperCase()) || r.stepId === 'clinic_checkup' || r.stepId === 'medical_checkup' || r.name === 'Medical Clearance'));
                        if (nextStep) {
                            nextStep.status = 'IN_PROGRESS';
                            nextStep.updatedAt = new Date().toISOString();
                        }
                    } else if (student.status === 'FLAGGED') {
                        s.roadmap[currentStepIdx].status = 'FLAGGED';
                        s.roadmap[currentStepIdx].updatedAt = new Date().toISOString();
                    } else {
                        s.roadmap[currentStepIdx].status = 'IN_PROGRESS';
                    }
                } else if (student.status === 'COMPLETED') {
                    s.status = 'ADVISED';
                }
            }, ['helpdesk', 'payment', 'roadmap', 'status']);
            loadQueue();
        };

        const markCompleted = () => {
            if (!selectedStudent.value) return;
            const sName = selectedStudent.value.name;
            selectedStudent.value.status = 'COMPLETED';
            persistStudentUpdate(selectedStudent.value);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: `Academic advising cleared for ${sName}`,
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            }
            if (document.activeElement) {
                document.activeElement.blur();
            }
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
            modal.hide();
        };

        const flagFollowUp = () => {
            if (!selectedStudent.value) return;
            const sName = selectedStudent.value.name;
            selectedStudent.value.status = 'FLAGGED';
            persistStudentUpdate(selectedStudent.value);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'warning',
                    title: `Student ${sName} flagged for follow-up`,
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            }
            if (document.activeElement) {
                document.activeElement.blur();
            }
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
            modal.hide();
        };

        const markAllCleared = () => {
            for (let i = 0; i < students.value.length; i++) {
                const student = students.value[i];
                if (student.status !== 'COMPLETED') {
                    student.status = 'COMPLETED';
                    persistStudentUpdate(student);
                }
            }
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'All queued students marked cleared',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            }
        };

        const setFilter = (filter) => {
            activeFilter.value = filter;
        };

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

        onMounted(() => {
            checkSession();
            updateTime();
            clockTimer = setInterval(updateTime, 1000);

            document.addEventListener('hide.bs.modal', () => {
                if (document.activeElement && typeof document.activeElement.blur === 'function') {
                    document.activeElement.blur();
                }
            });

            // Live synchronization listener
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
            if (['FLAGGED', 'DISCREPANCY', 'UNFIT', 'REJECTED'].includes(s)) return 'Flagged';
            if (s === 'SKIPPED') return 'Skipped';
            if (s === 'PENDING') return 'Pending';
            return status;
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
            const name = user.value.name || (currentUser.value ? currentUser.value.name : 'Helpdesk Staff');
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
                user.value.username = currentUser.value.username || 'helpdesk';
                user.value.role = currentUser.value.role || 'HELPDESK';
                user.value.avatar = currentUser.value.avatar || null;
            }
            try {
                const username = user.value.username || 'helpdesk';
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
                        body: JSON.stringify({ username: user.value.username || 'helpdesk', photoData: b64 })
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
            currentTitle,
            searchQuery,
            activeFilter,
            sortBy,
            sortDesc,
            toggleSort,
            getSortIcon,
            selectedStudent,
            students,
            filteredStudents,
            nextInQueue,
            callNextStudent,
            getQueueRank,
            pendingCount,
            completedToday,
            flaggedCount,
            totalInQueue,
            setView,
            openReview,
            markCompleted,
            flagFollowUp,
            markAllCleared,
            setFilter,
            getStepIcon,
            formatStatus,
            getStatusBadgeClass,
            currentUser,
            isLoggingIn,
            loginError,
            loginForm,
            handleLogout,
            showLogoutConfirm,
            confirmLogout,
            timeGreeting,
            // Profile & Security
            user, pass, saving, updatingPass, showCurrentPass, showNewPass, fileInput,
            passStrengthLevel, passStrengthLabel, passStrengthColor, passStrengthWidth,
            initials, formattedAvatar, checkPassStrength, triggerFileInput, onFileSelected,
            saveStaffProfile, updatePassword, loadProfile
        };
    }
}).mount('#app');
