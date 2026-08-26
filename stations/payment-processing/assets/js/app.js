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
        const sortDesc = ref(false); // FIFO: Earliest cashier arrivals served first (First In, First Out)
        const selectedStudent = ref(null);
        const students = ref([]);
        const receiptData = ref(null);

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
            const pendingList = filteredStudents.value.filter(s => s.status === 'PENDING');
            const idx = pendingList.findIndex(s => s.referenceNumber === student.referenceNumber);
            return idx >= 0 ? idx + 1 : null;
        };

        // Authentication State
        const currentUser = ref(null);
        const isLoggingIn = ref(false);
        const loginError = ref('');
        const loginForm = reactive({
            username: '',
            password: ''
        });

        const loadQueue = () => {
            const queue = StationDataBus.getQueue();
            const result = [];
            for (let i = 0; i < queue.length; i++) {
                const student = queue[i];
                if (typeof StationPipeline !== 'undefined') {
                    if (!StationPipeline.isAtOrPastStation('cashier', student)) continue;
                } else {
                    const statusUpper = String(student.status || '').toUpperCase();
                    const isAtOrPastCashier = ['MEDICAL_CLEARED', 'PAID', 'ENROLLED', 'PROMOTED'].includes(statusUpper);
                    if (!isAtOrPastCashier) continue;
                }

                // Parse roadmap and payment defensively
                let roadmap = student.roadmap;
                if (typeof roadmap === 'string') {
                    try { roadmap = JSON.parse(roadmap); } catch (e) { roadmap = []; }
                }
                if (!Array.isArray(roadmap)) roadmap = [];

                let payment = student.payment;
                if (typeof payment === 'string') {
                    try { payment = JSON.parse(payment); } catch (e) { payment = {}; }
                }
                if (!payment || typeof payment !== 'object') payment = {};

                let balanceVal = payment.totalFee || 0;
                if (payment.balance != null) {
                    balanceVal = payment.balance;
                }

                // Use StationPipeline.normalizeStudent() as the unified base
                const s = (typeof StationPipeline !== 'undefined')
                    ? StationPipeline.normalizeStudent(student, i, 'cashier')
                    : {
                        id: student.referenceNumber || student.id,
                        referenceNumber: student.referenceNumber,
                        name: student.name || 'Applicant',
                        program: student.program || '---',
                        studentType: student.studentType || 'REGULAR',
                        tempPin: student.tempPin || '',
                        phone: student.phone || '',
                        email: student.email || '',
                        roadmap: roadmap,
                        form: student.form || {},
                        medical: student.medical || {},
                        payment: student.payment || {},
                        helpdesk: student.helpdesk || {},
                        enrollment: student.enrollment || {},
                        overallStatus: student.status || 'PRE_REGISTERED'
                    };

                // Merge cashier-specific fields on top of the normalized base
                s.studentName  = s.name;
                s.roadmap      = roadmap;
                s.helpdesk     = student.helpdesk || {};
                s.scholarship  = student.scholarship || {};
                s.paymentMode  = student.paymentMode || payment.paymentMode || 'CASH';
                s.orNumber     = student.orNumber || null;
                s.enrolledAt   = student.enrolledAt || null;
                s.cashierName  = student.cashierName || null;
                s.status       = payment.status || 'PENDING';
                s.payment      = {
                    totalFee:       payment.totalFee     || 0,
                    amountPaid:     payment.amountPaid   || 0,
                    balance:        balanceVal,
                    paymentType:    String(payment.paymentType || 'Cash').toUpperCase() === 'GCASH' ? 'GCash' : 'Cash',
                    transactionRef: payment.transactionRef || '',
                    cashierNotes:   payment.notes        || '',
                    history:        payment.history      || []
                };
                result.push(s);
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
                    if (parsed && ['CASHIER', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'].includes(parsed.role)) {
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
                    const allowedRoles = ['CASHIER', 'SUPER_ADMIN', 'ADMIN', 'REGISTRAR'];
                    if (result.success && result.data && allowedRoles.includes(result.data.role)) {
                        currentUser.value = result.data;
                        const sessionKey = (result.data.role === 'SUPER_ADMIN' || result.data.role === 'ADMIN') ? 'gncp_admin_user' : 'gncp_station_user';
                        sessionStorage.setItem(sessionKey, JSON.stringify(result.data));
                        fetchCurrentProfile();
                        if (result.data.must_change_password && typeof window.PasswordChangeGuard !== 'undefined') {
                            window.PasswordChangeGuard.checkAndPrompt(result.data, async function() {
                                if (window.StationDataBus) await window.StationDataBus.syncWithBackend();
                                loadQueue();
                            });
                        }
                        return;
                    }
                }
            } catch (e) {
                console.warn('[Cashier] Session check warning:', e);
            }

            if (!currentUser.value) {
                if (typeof window.SessionExpirationGuard !== 'undefined') {
                    window.SessionExpirationGuard.handleExpiredSession({
                        title: 'Session Expired',
                        message: 'Your cashier workstation session has expired. Please sign in again to continue managing payments.',
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
        let queueSyncTimer = null;
        const stopLiveSync = () => {
            if (clockTimer) {
                clearInterval(clockTimer);
                clockTimer = null;
            }
            if (queueSyncTimer) {
                clearInterval(queueSyncTimer);
                queueSyncTimer = null;
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
                    text: 'Are you sure you want to log out of the Cashier Payment Processing Workstation?',
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

        const filteredStudents = computed(() => {
            const query = searchQuery.value.toLowerCase().trim();
            const result = [];
            for (let i = 0; i < students.value.length; i++) {
                const student = students.value[i];

                let matchesQuery = true;
                if (query) {
                    const matchesRef = (student.referenceNumber || '').toLowerCase().indexOf(query) !== -1;
                    const matchesName = (student.studentName || '').toLowerCase().indexOf(query) !== -1;
                    const matchesProg = (student.program || '').toLowerCase().indexOf(query) !== -1;
                    const matchesType = (student.payment?.paymentType || '').toLowerCase().indexOf(query) !== -1;
                    const matchesTicket = (student.queueTicket || '').toLowerCase().indexOf(query) !== -1;
                    matchesQuery = matchesRef || matchesName || matchesProg || matchesType || matchesTicket;
                }

                let matchesFilter = false;
                if (activeFilter.value === 'All') {
                    matchesFilter = true;
                } else if (activeFilter.value === 'PENDING' && student.status === 'PENDING') {
                    matchesFilter = true;
                } else if (activeFilter.value === 'PAID' && student.status === 'PAID') {
                    matchesFilter = true;
                } else if (activeFilter.value === 'PARTIAL' && student.status === 'PARTIAL') {
                    matchesFilter = true;
                } else if (activeFilter.value === 'REJECTED' && student.status === 'REJECTED') {
                    matchesFilter = true;
                }

                if (matchesQuery && matchesFilter) {
                    result.push(student);
                }
            }

            // FIFO (First In, First Out) / Header Sorting Logic
            return [...result].sort((a, b) => {
                let vA = (sortBy.value === 'studentName' || sortBy.value === 'name') ? a.studentName : (a[sortBy.value] || '');
                let vB = (sortBy.value === 'studentName' || sortBy.value === 'name') ? b.studentName : (b[sortBy.value] || '');
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

        const serveNextPayee = () => {
            if (nextInQueue.value) {
                openProcess(nextInQueue.value);
            }
        };

        const totalInQueue = computed(() => {
            return students.value.length;
        });

        const pendingCount = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'PENDING') {
                    count++;
                }
            }
            return count;
        });

        const paidToday = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'PAID') {
                    count++;
                }
            }
            return count;
        });

        const partialCount = computed(() => {
            let count = 0;
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'PARTIAL') {
                    count++;
                }
            }
            return count;
        });

        const paymentPercent = computed(() => {
            if (students.value.length === 0) return 0;
            const completed = paidToday.value + partialCount.value;
            return Math.round((completed / students.value.length) * 100);
        });

        const recentTransactions = computed(() => {
            const list = [];
            for (let i = 0; i < students.value.length; i++) {
                if (students.value[i].status === 'PAID' || students.value[i].status === 'PARTIAL') {
                    list.push(students.value[i]);
                }
            }
            return list;
        });

        const billingDetails = computed(() => {
            const student = selectedStudent.value;
            if (!student) {
                return {
                    totalFees: 0,
                    scholarshipDiscount: 0,
                    grandTotal: 0
                };
            }
            const totalFees = student.payment.totalFee || 0;
            const amountPaid = student.payment.amountPaid || 0;
            const balance = student.payment.balance || 0;
            const scholarshipDiscount = Math.max(0, totalFees - (amountPaid + balance));
            const grandTotal = totalFees - scholarshipDiscount;
            return {
                totalFees,
                scholarshipDiscount,
                grandTotal
            };
        });

        const setView = (view) => {
            currentView.value = view;
        };

        const payAmountInput = ref(0);
        const cashTendered = ref(0);
        const otcReferenceNumber = ref('');
        const paymentScheme = ref('FULL');
        const paymongoSession = ref(null);
        const isProcessingPayMongo = ref(false);
        let paymongoPollingInterval = null;

        const fetchPayMongoSession = async () => {
            if (!selectedStudent.value) return;
            const student = selectedStudent.value;
            const payAmt = parseFloat(payAmountInput.value) || 0;
            if (payAmt <= 0) return;

            try {
                const res = await fetch('../../api/index.php?action=payments/paymongo_create_checkout', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        referenceNumber: student.referenceNumber,
                        amount: payAmt,
                        description: `Tuition Assessment - ${student.studentName} (${student.program})`,
                        studentData: {
                            name: student.studentName,
                            program: student.program
                        }
                    })
                });
                const data = await res.json();
                if (data.success && data.data) {
                    paymongoSession.value = data.data;
                }
            } catch (e) {
                console.error('[PayMongo] Failed to generate checkout session:', e);
            }
        };

        const launchPayMongoCheckout = async () => {
            if (!selectedStudent.value) return;
            const student = selectedStudent.value;
            const payAmt = parseFloat(payAmountInput.value) || 0;
            if (payAmt <= 0) {
                await Swal.fire({
                    title: 'Invalid Amount',
                    text: 'Please select a valid payment amount.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }

            if (!paymongoSession.value) {
                await fetchPayMongoSession();
            }

            const basePath = window.location.pathname.startsWith('/systemtest') ? '/systemtest' : '';
            const checkoutUrl = paymongoSession.value?.checkoutUrl || 
                `${basePath}/shared/paymongo/checkout.html?session_id=cs_test_${Date.now()}&ref=${encodeURIComponent(student.referenceNumber)}&amount=${encodeURIComponent(payAmt)}&desc=${encodeURIComponent('Tuition Assessment - ' + student.studentName)}`;
            
            const win = window.open(checkoutUrl, 'PayMongoCheckoutWindow', 'width=1020,height=800,scrollbars=yes');
            if (win) {
                win.focus();
            }

            // Start live settlement listener
            if (paymongoPollingInterval) clearInterval(paymongoPollingInterval);
            paymongoPollingInterval = setInterval(() => {
                checkPayMongoSettlement(false);
            }, 2500);
        };

        const copyPayMongoLink = async () => {
            if (!selectedStudent.value) return;
            const student = selectedStudent.value;
            const payAmt = parseFloat(payAmountInput.value) || 0;
            const basePath = window.location.pathname.startsWith('/systemtest') ? '/systemtest' : '';
            const checkoutUrl = (window.location.origin || '') + (paymongoSession.value?.checkoutUrl || 
                `${basePath}/shared/paymongo/checkout.html?session_id=cs_test_${Date.now()}&ref=${encodeURIComponent(student.referenceNumber)}&amount=${encodeURIComponent(payAmt)}`);

            try {
                await navigator.clipboard.writeText(checkoutUrl);
                await Swal.fire({
                    title: 'Payment Link Copied!',
                    text: 'PayMongo checkout link copied to clipboard. You can send this directly to the student via SMS or Email.',
                    icon: 'success',
                    timer: 2200,
                    showConfirmButton: false
                });
            } catch (e) {
                prompt('Copy PayMongo Checkout Link:', checkoutUrl);
            }
        };

        const checkPayMongoSettlement = async (showManualFeedback = true) => {
            if (!selectedStudent.value) return;
            const student = selectedStudent.value;

            try {
                // Fetch latest data from database
                const res = await fetch(`../../api/index.php?action=stations/queue`);
                const json = await res.json();
                if (json.success && Array.isArray(json.data)) {
                    const found = json.data.find(s => s.referenceNumber === student.referenceNumber || s.id === student.id);
                    if (found && (found.status === 'PAID' || found.status === 'PARTIAL')) {
                        // Settlement completed!
                        if (paymongoPollingInterval) {
                            clearInterval(paymongoPollingInterval);
                            paymongoPollingInterval = null;
                        }

                        selectedStudent.value = found;
                        loadQueue();

                        const lastTxn = (found.payment?.history && found.payment.history.length > 0)
                            ? found.payment.history[found.payment.history.length - 1]
                            : null;

                        receiptData.value = {
                            refNo: found.referenceNumber,
                            name: found.studentName,
                            program: found.program,
                            paymentMode: lastTxn ? lastTxn.paymentType : 'PayMongo Gateway',
                            transactionRef: lastTxn ? lastTxn.reference : (found.payment?.transactionRef || 'PM-' + Date.now()),
                            totalFee: found.payment?.totalFee || 0,
                            amountPaid: lastTxn ? lastTxn.amount : (found.payment?.amountPaid || 0),
                            balance: found.payment?.balance || 0,
                            date: new Date().toLocaleString('en-US', {
                                year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                            }),
                            cashier: lastTxn ? lastTxn.cashier : 'PayMongo Gateway'
                        };

                        closeModal();

                        await Swal.fire({
                            title: 'PayMongo Payment Verified!',
                            html: `<div class="text-start">
                                    <p class="mb-1"><strong>Status:</strong> <span class="badge bg-success">${found.status}</span></p>
                                    <p class="mb-1"><strong>Transaction Ref:</strong> <code class="font-monospace">${receiptData.value.transactionRef}</code></p>
                                    <p class="mb-0"><strong>Remaining Balance:</strong> ₱${(found.payment?.balance || 0).toLocaleString()}</p>
                                   </div>`,
                            icon: 'success',
                            confirmButtonColor: '#006A4E'
                        });
                        return;
                    }
                }

                if (showManualFeedback) {
                    await Swal.fire({
                        title: 'Awaiting Settlement',
                        text: 'PayMongo checkout session is still pending payment by the customer.',
                        icon: 'info',
                        confirmButtonColor: '#006A4E'
                    });
                }
            } catch (e) {
                console.error('[PayMongo] Settlement check error:', e);
            }
        };

        const onPaymentTypeChange = () => {
            if (selectedStudent.value?.payment?.paymentType === 'GCash') {
                fetchPayMongoSession();
            } else {
                paymongoSession.value = null;
            }
        };

        const onAmountChanged = () => {
            if (selectedStudent.value?.payment?.paymentType === 'GCash') {
                fetchPayMongoSession();
            }
        };

        const handleSchemeChange = () => {
            if (!selectedStudent.value || !selectedStudent.value.payment) return;
            const balance = selectedStudent.value.payment.balance || 0;
            if (paymentScheme.value === 'FULL') {
                payAmountInput.value = balance;
            } else if (paymentScheme.value === 'DOWNPAYMENT') {
                payAmountInput.value = balance >= 3000 ? 3000 : balance;
            }
            if (selectedStudent.value?.payment?.paymentType === 'GCash') {
                fetchPayMongoSession();
            }
        };

        const selectScheme = (scheme) => {
            paymentScheme.value = scheme;
            handleSchemeChange();
        };

        const changeDue = computed(() => {
            if (cashTendered.value <= 0 || payAmountInput.value <= 0) return 0;
            return Math.max(0, cashTendered.value - payAmountInput.value);
        });

        const newBalance = computed(() => {
            if (!selectedStudent.value || !selectedStudent.value.payment) return 0;
            const currentBal = selectedStudent.value.payment.balance || 0;
            return Math.max(0, currentBal - (payAmountInput.value || 0));
        });

        const formatPaymentMode = (mode) => {
            if (!mode) return 'Full Cash Payment Plan';
            const m = String(mode).toUpperCase();
            if (m === 'CASH') return 'Full Cash Payment Plan';
            if (m === 'SEMI') return 'Semi-Annual Installment (30% Down)';
            if (m === 'QUAD') return 'Quarterly Installment (30% Down)';
            return mode;
        };

        const getPaymentModeBadge = (mode) => {
            if (!mode) return 'Full Cash';
            const m = String(mode).toUpperCase();
            if (m === 'CASH') return 'Full Cash';
            if (m === 'SEMI') return 'Semi-Annual (30% Down)';
            if (m === 'QUAD') return 'Quarterly (30% Down)';
            return mode;
        };

        const openProcess = (student) => {
            selectedStudent.value = student;
            const currentBal = student.payment.balance || 0;

            // Reflect the student's chosen payment scheme from enrollment-system:
            // CASH -> Full Settlement
            // SEMI / QUAD -> Downpayment (30% / Min. ₱3,000 entry)
            const chosenMode = String(student.paymentMode || student.payment?.paymentMode || '').toUpperCase();
            if ((chosenMode === 'SEMI' || chosenMode === 'QUAD' || chosenMode === 'INSTALLMENT') && currentBal >= 3000) {
                paymentScheme.value = 'DOWNPAYMENT';
                payAmountInput.value = 3000;
            } else {
                paymentScheme.value = 'FULL';
                payAmountInput.value = currentBal; // Default to full outstanding balance
            }

            cashTendered.value = 0;
            otcReferenceNumber.value = '';
            paymongoSession.value = null;
            if (student.payment.paymentType === 'PayMongo') {
                fetchPayMongoSession();
            }
            setTimeout(() => {
                const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal'));
                modal.show();
            }, 0);
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

        const persistPayment = (student) => {
            StationDataBus.updateStudent(student.referenceNumber, (s) => {
                s.payment.status         = student.status;
                s.payment.amountPaid     = student.payment.amountPaid;
                s.payment.balance        = student.payment.balance;
                s.payment.paymentType    = student.payment.paymentType;
                s.payment.transactionRef = student.payment.transactionRef;
                s.payment.notes          = student.payment.cashierNotes;

                // Set database verification logs
                s.payment.verifiedBy = currentUser.value?.name || currentUser.value?.username || 'Cashier Officer';
                s.payment.dateVerified = new Date().toLocaleDateString();

                if (student.status === 'PAID' || student.status === 'PARTIAL') {
                    s.status = 'PAID';
                }

                // Mark cashier step as completed; advance IT Center step to IN_PROGRESS
                const currentStepIdx = (s.roadmap && Array.isArray(s.roadmap)) ? s.roadmap.findIndex(r => r && (r.stepId === 'cashier_payment' || r.name === 'Cashier Payment' || r.title === 'Treasury / Cashier — Payment' || r.id === 6)) : -1;
                if (currentStepIdx !== -1) {
                    if (student.status === 'PAID' || student.status === 'PARTIAL') {
                        s.roadmap[currentStepIdx].status = 'COMPLETED';
                        s.roadmap[currentStepIdx].updatedAt = new Date().toISOString();

                        // Unlock the IT Center step
                        const nextStep = s.roadmap.slice(currentStepIdx + 1).find(r => r && (['PENDING', 'LOCKED'].includes(String(r.status || '').toUpperCase()) || r.stepId === 'it_activation' || r.stepId === 'id_email_final'));
                        if (nextStep) {
                            nextStep.status = 'IN_PROGRESS';
                            nextStep.updatedAt = new Date().toISOString();
                        }
                    } else if (student.status === 'REJECTED') {
                        s.roadmap[currentStepIdx].status = 'FLAGGED';
                        s.roadmap[currentStepIdx].updatedAt = new Date().toISOString();
                    }
                }
            }, ['payment', 'roadmap', 'status']); // Delta: send payment + roadmap + status
            loadQueue();
        };

        const isRecordingPayment = ref(false);
        const recordPayment = async () => {
            if (!selectedStudent.value || isRecordingPayment.value) return;
            const student = selectedStudent.value;
            const payAmt = parseFloat(payAmountInput.value) || 0;
            const currentBal = parseFloat(student.payment.balance) || 0;

            const minAllowed = currentBal < 3000 ? currentBal : 3000;

            if (payAmt < minAllowed) {
                if (currentBal < 3000) {
                    await Swal.fire({
                        title: 'Minimum Payment Required',
                        text: `The remaining balance is ₱${currentBal.toLocaleString()}. Please enter the full remaining balance of ₱${currentBal.toLocaleString()} to settle payment.`,
                        icon: 'warning',
                        confirmButtonColor: '#006A4E'
                    });
                } else {
                    await Swal.fire({
                        title: 'Minimum Payment Limit',
                        text: 'The minimum payment amount allowed is ₱3,000.00.',
                        icon: 'warning',
                        confirmButtonColor: '#006A4E'
                    });
                }
                return;
            }

            if (payAmt > currentBal) {
                await Swal.fire({
                    title: 'Excess Amount',
                    text: 'Payment amount cannot exceed the outstanding balance of ₱' + currentBal.toLocaleString() + '.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }

            if (student.payment.paymentType === 'Cash' && cashTendered.value < payAmt) {
                await Swal.fire({
                    title: 'Insufficient Cash',
                    text: 'Cash tendered (₱' + cashTendered.value.toLocaleString() + ') is less than the payment amount (₱' + payAmt.toLocaleString() + ').',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }

            if (student.payment.paymentType === 'PayMongo') {
                await launchPayMongoCheckout();
                return;
            }

            isRecordingPayment.value = true;
            try {
                const isFull = (currentBal - payAmt) === 0;
                const newStatus = isFull ? 'PAID' : 'PARTIAL';

                // Generate a FRESH unique transaction reference for each individual payment
                const isGCash = student.payment.paymentType === 'GCash';
                const paymentType = isGCash ? 'GCash (Over-the-Counter)' : 'Cash (Over-the-Counter)';
                const transactionRef = isGCash
                    ? (otcReferenceNumber.value.trim() || ('GCASH-' + Math.floor(100000 + Math.random() * 900000)))
                    : ('TXN-' + Math.floor(100000 + Math.random() * 900000));
                student.payment.transactionRef = transactionRef;
                student.payment.paymentType = paymentType;

                const notes = student.payment.cashierNotes || (isGCash ? 'Over-the-counter GCash digital receipt verification' : 'Over-the-counter cash payment');
                const cashierName = currentUser.value ? currentUser.value.name : 'Cashier Officer';

                // 1. Update the reactive local state directly for real-time modal update
                student.payment.amountPaid = (student.payment.amountPaid || 0) + payAmt;
                student.payment.balance = currentBal - payAmt;
                student.payment.status = newStatus;
                student.status = newStatus;

                if (!student.payment.history || !Array.isArray(student.payment.history)) {
                    student.payment.history = [];
                }

                student.payment.history.push({
                    date: new Date().toISOString(),
                    amount: payAmt,
                    reference: transactionRef,
                    paymentType: paymentType,
                    cashier: cashierName,
                    notes: notes
                });

                // 2. Persist to DataBus / localStorage and trigger background sync
                StationDataBus.updateStudent(student.referenceNumber, (s) => {
                    s.status                 = newStatus;
                    s.payment.status         = newStatus;
                    s.payment.amountPaid     = student.payment.amountPaid;
                    s.payment.balance        = student.payment.balance;
                    s.payment.paymentType    = paymentType;
                    s.payment.transactionRef = transactionRef;
                    s.payment.notes          = notes;
                    s.payment.verifiedBy     = cashierName;
                    s.payment.dateVerified   = new Date().toLocaleDateString();
                    s.payment.history        = student.payment.history;

                    const currentStepIdx = (s.roadmap && Array.isArray(s.roadmap)) ? s.roadmap.findIndex(r => r.stepId === 'cashier_payment' || r.name === 'Cashier Payment' || r.id === 6) : -1;
                    if (currentStepIdx !== -1) {
                        s.roadmap[currentStepIdx].status = 'COMPLETED';
                        s.roadmap[currentStepIdx].updatedAt = new Date().toISOString();

                        const nextStep = s.roadmap.slice(currentStepIdx + 1).find(r => r.status === 'PENDING' || r.status === 'LOCKED');
                        if (nextStep) {
                            nextStep.status = 'IN_PROGRESS';
                            nextStep.updatedAt = new Date().toISOString();
                        }
                    }
                }, ['status', 'payment', 'roadmap']);

                // 3. Directly POST to backend PHP/MySQL to guarantee instant database persistence
                try {
                    await fetch('../backend/api.php?action=update_student', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            referenceNumber: student.referenceNumber,
                            updateData: {
                                payment: student.payment,
                                roadmap: student.roadmap,
                                status: newStatus
                            }
                        })
                    });
                } catch (e) {
                    console.error('[Cashier] Direct backend save failed:', e);
                }

                loadQueue();

                receiptData.value = {
                    refNo: student.referenceNumber,
                    name: student.studentName,
                    program: student.program,
                    paymentMode: paymentType,
                    transactionRef: transactionRef,
                    totalFee: student.payment.totalFee,
                    amountPaid: payAmt,
                    balance: student.payment.balance,
                    date: new Date().toLocaleString('en-US', {
                        year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                    }),
                    cashier: cashierName
                };

                closeModal();

                await Swal.fire({
                    title: 'Payment Recorded!',
                    text: `Successfully collected ₱${payAmt.toLocaleString()}. Remaining balance: ₱${student.payment.balance.toLocaleString()}.`,
                    icon: 'success',
                    confirmButtonColor: '#006A4E'
                });
            } finally {
                isRecordingPayment.value = false;
            }
        };

        const printCOR = (student) => {
            const url = `cor_print.php?ref=${student.referenceNumber}&pin=${student.tempPin}`;
            window.open(url, '_blank');
        };

        const printReceipt = () => {
            const card = document.getElementById('printable-receipt-card');
            if (!card) {
                window.print();
                return;
            }

            try {
                const printFrame = document.createElement('iframe');
                printFrame.style.position = 'fixed';
                printFrame.style.right = '0';
                printFrame.style.bottom = '0';
                printFrame.style.width = '0';
                printFrame.style.height = '0';
                printFrame.style.border = '0';
                document.body.appendChild(printFrame);

                const frameDoc = printFrame.contentWindow.document;
                frameDoc.open();
                frameDoc.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Official Receipt - ${receiptData.value?.transactionRef || 'GNCP'}</title>
                        <meta charset="utf-8">
                        <link rel="stylesheet" href="../../shared/libs/bootstrap.min.css">
                        <style>
                            @page {
                                size: A4 portrait;
                                margin: 12mm;
                            }
                            * {
                                box-sizing: border-box !important;
                                -webkit-print-color-adjust: exact !important;
                                print-color-adjust: exact !important;
                            }
                            body {
                                margin: 0;
                                padding: 10px;
                                background: #ffffff !important;
                                color: #000000 !important;
                                font-family: 'Courier New', Courier, monospace;
                            }
                            .receipt-horizontal-layout {
                                border: 2px solid #000000 !important;
                                border-radius: 8px !important;
                                padding: 24px !important;
                                background: #ffffff !important;
                                width: 100% !important;
                                max-width: 800px;
                                margin: 0 auto;
                            }
                            .table-bordered {
                                border: 1.5px solid #000000 !important;
                            }
                            .table-bordered th, .table-bordered td {
                                border: 1px solid #000000 !important;
                            }
                        </style>
                    </head>
                    <body>
                        ${card.outerHTML}
                        <script>
                            window.onload = function() {
                                window.focus();
                                window.print();
                                setTimeout(function() {
                                    if (window.frameElement && window.frameElement.parentNode) {
                                        window.frameElement.parentNode.removeChild(window.frameElement);
                                    }
                                }, 1500);
                            };
                        <\/script>
                    </body>
                    </html>
                `);
                frameDoc.close();
            } catch (e) {
                console.error('[Cashier] iframe print failed, falling back to window.print():', e);
                window.print();
            }
        };

        const closeReceipt = () => {
            receiptData.value = null;
            loadQueue();
        };

        const markEnrolledAndPrint = async (student) => {
            try {
                const cashierDisplayName = currentUser.value ? (currentUser.value.name || currentUser.value.username || 'Cashier Representative') : 'Cashier Representative';
                const res = await fetch('../backend/generate_or.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        referenceNumber: student.referenceNumber,
                        cashierName: cashierDisplayName
                    })
                });
                const data = await res.json();
                if (data.success) {
                    student.orNumber = data.data.orNumber;
                    student.enrolledAt = data.data.enrolledAt;
                    student.cashierName = cashierDisplayName;

                    StationDataBus.updateStudent(student.referenceNumber, (s) => {
                        s.orNumber = data.data.orNumber;
                        s.enrolledAt = data.data.enrolledAt;
                        s.cashierName = cashierDisplayName;
                        s.payment.status = student.status;
                        
                        const currentStepIdx = (s.roadmap && Array.isArray(s.roadmap)) ? s.roadmap.findIndex(r => r.stepId === 'cashier_payment' || r.name === 'Cashier Payment' || r.id === 6) : -1;
                        if (currentStepIdx !== -1) {
                            if (s.roadmap[currentStepIdx].status === 'COMPLETED') {
                                return;
                            }
                            s.roadmap[currentStepIdx].status = 'COMPLETED';
                            s.roadmap[currentStepIdx].updatedAt = new Date().toISOString();

                            const nextStep = s.roadmap.slice(currentStepIdx + 1).find(r => r.status === 'PENDING' || r.status === 'LOCKED');
                            if (nextStep) {
                                nextStep.status = 'IN_PROGRESS';
                                nextStep.updatedAt = new Date().toISOString();
                            }
                        }
                    }, ['orNumber', 'enrolledAt', 'cashierName', 'payment', 'roadmap']); // Delta: send cashier print attributes + payment + roadmap

                    const lastTxn = (student.payment?.history && student.payment.history.length > 0)
                        ? student.payment.history[student.payment.history.length - 1]
                        : null;

                    receiptData.value = {
                        refNo: student.referenceNumber,
                        name: student.studentName,
                        program: student.program,
                        paymentMode: lastTxn ? lastTxn.paymentType : (student.payment?.paymentType || 'Cash'),
                        transactionRef: data.data.orNumber || student.orNumber || (lastTxn ? lastTxn.reference : 'OR-' + Date.now()),
                        totalFee: student.payment?.totalFee || 0,
                        amountPaid: student.payment?.amountPaid || (lastTxn ? lastTxn.amount : (student.payment?.totalFee || 0)),
                        balance: student.payment?.balance || 0,
                        date: new Date(data.data.enrolledAt || Date.now()).toLocaleString('en-US', {
                            year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                        }),
                        cashier: cashierDisplayName
                    };

                    closeModal();
                    loadQueue();
                } else {
                    await Swal.fire({
                        title: 'Error',
                        text: 'Error generating OR: ' + (data.message || 'Unknown error'),
                        icon: 'error',
                        confirmButtonColor: '#0d6efd'
                    });
                }
            } catch (err) {
                console.error('OR Generation error:', err);
                await Swal.fire({
                    title: 'Error',
                    text: 'Connection error occurred while generating Official Receipt.',
                    icon: 'error',
                    confirmButtonColor: '#0d6efd'
                });
            }
        };

        const confirmFullPayment = () => {
            if (!selectedStudent.value) return;
            payAmountInput.value = selectedStudent.value.payment.balance || 0;
            cashTendered.value = payAmountInput.value;
            recordPayment();
        };

        const recordDownPayment = (amount) => {
            if (!selectedStudent.value) return;
            payAmountInput.value = amount || 0;
            cashTendered.value = payAmountInput.value;
            recordPayment();
        };

        const rejectPayment = () => {
            if (!selectedStudent.value) return;
            const student = selectedStudent.value;
            
            const netAssessment = student.payment.amountPaid + student.payment.balance;

            student.status = 'REJECTED';
            student.payment.balance = netAssessment;
            student.payment.amountPaid = 0;

            if (!student.payment.transactionRef) {
                student.payment.transactionRef = 'RJ-' + student.referenceNumber.substring(student.referenceNumber.length - 4);
            }
            persistPayment(student);
            closeModal();
        };

        const closeModal = () => {
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal'));
            modal.hide();
            selectedStudent.value = null;
        };

        const getStatusClass = (status) => {
            return status.toLowerCase().replace(/\s+/g, '-');
        };

        const formatCurrency = (value) => {
            return Number(value).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
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
            queueSyncTimer = setInterval(() => {
                if (currentUser.value) {
                    loadQueue();
                }
            }, 2500);

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
            const name = user.value.name || (currentUser.value ? currentUser.value.name : 'Cashier Staff');
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
                user.value.username = currentUser.value.username || 'cashier';
                user.value.role = currentUser.value.role || 'CASHIER';
                user.value.avatar = currentUser.value.avatar || null;
            }
            try {
                const username = user.value.username || 'cashier';
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
                        body: JSON.stringify({ username: user.value.username || 'cashier', photoData: b64 })
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

        window.__cashierScope = { students, filteredStudents, openProcess, loadQueue, selectedStudent };

        return {
            currentView,
            currentDateTime,
            searchQuery,
            activeFilter,
            sortBy,
            sortDesc,
            setFilter,
            toggleSort,
            getSortIcon,
            selectedStudent,
            students,
            filteredStudents,
            nextInQueue,
            serveNextPayee,
            getQueueRank,
            totalInQueue,
            pendingCount,
            paidToday,
            partialCount,
            paymentPercent,
            recentTransactions,
            billingDetails,
            setView,
            openProcess,
            getStepIcon,
            confirmFullPayment,
            recordDownPayment,
            rejectPayment,
            closeModal,
            getStatusClass,
            formatCurrency,
            formatStatus,
            getStatusBadgeClass,
            currentUser,
            isLoggingIn,
            loginError,
            loginForm,
            handleLogout,
            showLogoutConfirm,
            confirmLogout,
            receiptData,
            printReceipt,
            closeReceipt,
            payAmountInput,
            cashTendered,
            otcReferenceNumber,
            paymentScheme,
            selectScheme,
            handleSchemeChange,
            changeDue,
            newBalance,
            isRecordingPayment,
            recordPayment,
            printCOR,
            markEnrolledAndPrint,
            formatPaymentMode,
            getPaymentModeBadge,
            timeGreeting,
            // PayMongo Gateway Integration
            paymongoSession,
            isProcessingPayMongo,
            fetchPayMongoSession,
            launchPayMongoCheckout,
            copyPayMongoLink,
            checkPayMongoSettlement,
            onPaymentTypeChange,
            onAmountChanged,
            // Profile & Security
            user, pass, saving, updatingPass, showCurrentPass, showNewPass, fileInput,
            passStrengthLevel, passStrengthLabel, passStrengthColor, passStrengthWidth,
            initials, formattedAvatar, checkPassStrength, triggerFileInput, onFileSelected,
            saveStaffProfile, updatePassword, loadProfile
        };
    }
});
window.app = app.mount('#app');
