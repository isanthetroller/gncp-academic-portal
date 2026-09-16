const { createApp, ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } = Vue;

const API = 'backend/api.php';

let isHandling401 = false;
window.handle401SessionExpired = () => {
    if (isHandling401) return;
    isHandling401 = true;
    if (typeof window.__stopAdminLiveSync === 'function') {
        window.__stopAdminLiveSync();
    }
    sessionStorage.removeItem('gncp_admin_user');
    sessionStorage.removeItem('gncp_station_user');
    localStorage.removeItem('gncp_admin_user');
    localStorage.removeItem('gncp_station_user');

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'warning',
            title: 'Session Expired',
            text: 'Your session has expired. Please sign in to continue.',
            confirmButtonColor: '#006A4E',
            confirmButtonText: 'Sign In',
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then(() => {
            window.location.href = '../?clear=true&session_expired=true&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
        });
    } else {
        window.location.href = '../?clear=true&session_expired=true&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
    }
};

const handleFetchResponse = async (res) => {
    if (res.status === 401) {
        window.handle401SessionExpired();
        return { success: false, error: 'Session expired. Please log in.' };
    }
    const text = await res.text();
    try {
        const json = JSON.parse(text);
        if (json && (json.code === 401 || (json.error && typeof json.error === 'string' && json.error.includes('Authentication required')))) {
            window.handle401SessionExpired();
            return { success: false, error: 'Session expired. Please log in.' };
        }
        return json;
    } catch (e) {
        console.error('Invalid JSON response from server:', text);
        return { success: false, error: 'Server returned an invalid format. Check console logs for details.' };
    }
};

const get = (action, params = {}) => {
    let url = `${API}?action=${action}`;
    if (params && typeof params === 'object') {
        const qs = Object.entries(params)
            .filter(([_, v]) => v !== undefined && v !== null && v !== '')
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
            .join('&');
        if (qs) url += `&${qs}`;
    }
    return fetch(url, { credentials: 'include' }).then(handleFetchResponse);
};
const post = (action, body) => fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify(body)
}).then(handleFetchResponse);

const app = createApp({
    components: {
        'admin-sidebar': window.AdminSidebar
    },
    setup() {
        // Auth
        const getStoredAdmin = () => {
            try {
                const raw = sessionStorage.getItem('gncp_admin_user') || sessionStorage.getItem('gncp_station_user');
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed && (parsed.role === 'SUPER_ADMIN' || parsed.role === 'ADMIN')) {
                        return parsed;
                    }
                }
            } catch (e) {}
            return null;
        };
        const currentAdmin = ref(getStoredAdmin());
        const isLoggingIn  = ref(false);
        const loginError   = ref('');
        const showOperatorPassword = ref(false);

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
                if (currentAdmin.value) {
                    loadAll(true);
                }
            }, 4000);
        };

        const stopLiveSync = () => {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        };
        window.__stopAdminLiveSync = stopLiveSync;
        const loginForm    = reactive({ username:'', password:'' });

        // View state
        const view       = ref('dashboard');
        const search     = ref('');
        const modal      = ref('');
        const form       = reactive({});

        // Operator Management State (Decoupled & Isolated)
        const operatorForm = reactive({ id: null, name: '', email: '', username: '', password: '', role: '' });
        const isOperatorModalOpen = ref(false);
        const isEditOperatorModalOpen = ref(false);
        const isSubmittingOperator = ref(false);

        // Students & Core Entities
        const students = ref([]);
        const departments = ref([]);
        const programs    = ref([]);
        const subjects    = ref([]);
        const curriculum  = ref([]);
        const periods     = ref([]);
        const sections    = ref([]); // cohort sections
        const classOfferings = ref([]); // class offerings
        const fees        = ref([]);
        const users       = ref([]);
        const filterUserStatus = ref('ALL');

        // Collapsible navigation
        const expandedCats = ref({ catalog: true, term: true, scheduling: true });
        const selectedDeptName = ref('');
        const filterStudentProgram = ref('');
        const filterStudentYear = ref('');
        const filterStudentStatus = ref('');
        const filterAccountProgram = ref('');
        const filterAccountYear = ref('');
        const filterAccountStatus = ref('');

        // Term Cloning State
        const cloneForm = reactive({
            fromPeriodId: '',
            newPeriodName: '',
            newAcademicYear: '',
            newSemester: '1st Semester',
            enrollmentStart: '',
            enrollmentEnd: '',
            cloneSections: true,
            cloneOfferings: true
        });

        // Bulk Sections State
        const bulkForm = reactive({
            program: '',
            curriculumVersion: '2022 Curriculum',
            yearLevel: '1st Year',
            academicPeriodId: '',
            capacity: 40,
            adviser: '',
            count: 3
        });

        // Mobile Navigation State
        const isMobileMenuOpen = ref(false);

        // Sort & Filter state
        const sortKey = ref(''); // e.g. 'programs_code'
        const sortDir = ref(1);   // 1: asc, -1: desc
        const filterProgramDept = ref('');
        const filterProgramStatus = ref('');
        const filterSubjectDept = ref('');
        const filterSubjectLab = ref('');
        const filterCurrProgram = ref('');
        const filterCurrYear = ref('');
        const filterCurrSem = ref('');
        const filterPeriodSem = ref('');
        const filterPeriodStatus = ref('');
        const filterSectPeriod = ref('');
        const filterSectProgram = ref('');
        const filterSectYear = ref('');
        const filterSectSem = ref('');
        const filterSectDays = ref('');
        
        // Views & Collapsibles
        const selectedCurrDept = ref('');
        const selectedCurrProgram = ref('');
        const selectedCurrVersion = ref('2022 Curriculum');
        const currView = ref('prospectus'); // prospectus | grouped | table
        const sectView = ref('table'); // table | cards
        const collapsedGroups = ref([]);

        // Curriculum Version Clone State
        const cloneCurrForm = reactive({
            program: '',
            fromVersion: '',
            toVersion: ''
        });

        // Dashboard Stats State
        const dashboardStats = ref(null);
        const isLoadingStats = ref(false);
        const isLoadingAcademicData = ref(true);
        const isLoadingOperators = ref(false);
        const isLoadingAnnouncements = ref(false);
        const isLoadingMilestones = ref(false);

        // Multi-Dimensional Analytics Filter State
        const analyticsFilters = reactive({
            academic_period_id: '',
            department_code: '',
            program_code: '',
            year_level: '',
            date_from: '',
            date_to: ''
        });

        const isFiltered = computed(() => {
            return !!(analyticsFilters.academic_period_id || analyticsFilters.department_code || analyticsFilters.program_code || analyticsFilters.year_level || analyticsFilters.date_from || analyticsFilters.date_to);
        });

        const activeFilterSummary = computed(() => {
            const parts = [];
            if (analyticsFilters.academic_period_id) {
                const p = (periods.value || []).find(x => String(x.id) === String(analyticsFilters.academic_period_id));
                if (p) parts.push(`Period: ${p.name || p.academic_year + ' ' + p.semester}`);
            }
            if (analyticsFilters.department_code) {
                const d = (departments.value || []).find(x => x.code === analyticsFilters.department_code);
                parts.push(`Dept: ${d ? d.name : analyticsFilters.department_code}`);
            }
            if (analyticsFilters.program_code) {
                parts.push(`Program: ${analyticsFilters.program_code}`);
            }
            if (analyticsFilters.year_level) {
                parts.push(`Year: ${analyticsFilters.year_level}`);
            }
            if (analyticsFilters.date_from || analyticsFilters.date_to) {
                parts.push(`Date: ${analyticsFilters.date_from || 'Start'} to ${analyticsFilters.date_to || 'Now'}`);
            }
            return parts.length > 0 ? parts.join(' | ') : 'All Academic Records (Unfiltered)';
        });

        const printDateFormatted = computed(() => {
            const now = new Date();
            return now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        });

        const applyAnalyticsFilters = () => {
            loadDashboard();
        };

        const resetAnalyticsFilters = () => {
            analyticsFilters.academic_period_id = '';
            analyticsFilters.department_code = '';
            analyticsFilters.program_code = '';
            analyticsFilters.year_level = '';
            analyticsFilters.date_from = '';
            analyticsFilters.date_to = '';
            loadDashboard();
        };

        const printAnalyticsReport = () => {
            window.print();
        };

        // Sleek Executive Course Chart State & Dual-Metric Logic
        const chartViewMode = ref('spline'); // 'spline' (By Course) | 'timeline' (30-Day) | 'bars' (Pipeline Breakdown)
        const hoveredChartPoint = ref(null);
        const setHoveredPoint = (p, idx) => {
            hoveredChartPoint.value = p;
        };

        const getTooltipStyle = (pt, metrics) => {
            if (!pt || !metrics) return {};
            const pctX = (pt.x / metrics.width) * 100;
            const pctY = (pt.y / metrics.height) * 100;
            
            let transformX = '-50%';
            if (pctX < 24) {
                transformX = '10px';
            } else if (pctX > 76) {
                transformX = 'calc(-100% - 10px)';
            }
            
            let transformY = '-100%';
            let marginTop = '-12px';
            if (pctY < 32) {
                transformY = '0%';
                marginTop = '14px';
            }
            
            return {
                left: `${pctX}%`,
                top: `${pctY}%`,
                transform: `translate(${transformX}, ${transformY})`,
                marginTop: marginTop
            };
        };

        const courseAnalytics = computed(() => {
            if (dashboardStats.value && dashboardStats.value.programsDist && dashboardStats.value.programsDist.length > 0 && dashboardStats.value.programsDist[0].code) {
                return dashboardStats.value.programsDist;
            }
            const aliasMap = {
                'BSCOE': 'BSCpE',
                'CS': 'BSCS',
                'IT': 'BSIT',
                'BS Computer Science': 'BSCS',
                'BS Information Technology': 'BSIT',
                'BS Nursing': 'BSN',
                'BS Business Administration': 'BSBA',
                'BS Hospitality Management': 'BSHM',
                'BS Secondary Education': 'BSEd',
                'BS Computer Engineering': 'BSCpE'
            };

            const fixedOrder = ['BSCS', 'BSIT', 'BSCpE', 'BSBA', 'BSHM', 'BSN', 'BSEd'];

            let masterCodes = [];
            if (programs.value && programs.value.length > 0) {
                programs.value.forEach(p => {
                    let code = (p.code || p.name || '').trim();
                    if (aliasMap[code]) code = aliasMap[code];
                    if (code && !masterCodes.includes(code)) {
                        masterCodes.push(code);
                    }
                });
            }
            if (masterCodes.length === 0) {
                masterCodes = [...fixedOrder];
            } else {
                fixedOrder.forEach(fc => {
                    if (!masterCodes.includes(fc)) {
                        masterCodes.push(fc);
                    }
                });
            }

            const distMap = {};
            if (dashboardStats.value && dashboardStats.value.programsDist) {
                dashboardStats.value.programsDist.forEach(d => {
                    let rawProg = (d.program || '').trim();
                    let canonicalProg = aliasMap[rawProg] || rawProg;
                    distMap[canonicalProg] = (distMap[canonicalProg] || 0) + (parseInt(d.count) || 0);
                });
            }

            const distTotal = Object.values(distMap).reduce((a, b) => a + b, 0);
            const totalCount = distTotal > 0 ? distTotal : (dashboardStats.value ? (parseInt(dashboardStats.value.total) || 1) : 1);

            return masterCodes.map(code => {
                const count = distMap[code] || 0;
                const pct = totalCount > 0 ? ((count / totalCount) * 100) : 0;
                
                let enrolledCount = 0;
                if (students.value && students.value.length > 0) {
                    enrolledCount = students.value.filter(s => {
                        let sc = (s.program || s.course || '').trim();
                        if (aliasMap[sc]) sc = aliasMap[sc];
                        return sc === code;
                    }).length;
                } else if (dashboardStats.value && dashboardStats.value.enrolled) {
                    enrolledCount = Math.min(count, Math.round(count * 0.8));
                }

                const progObj = (programs.value || []).find(p => {
                    let c = (p.code || p.name || '').trim();
                    return c === code || aliasMap[c] === code;
                });
                const name = progObj ? progObj.name : code;
                const pending = Math.max(0, count - enrolledCount);
                const conversionRate = count > 0 ? Math.round((enrolledCount / count) * 100) : 0;

                return {
                    code,
                    name,
                    count,
                    enrolled: enrolledCount,
                    pending,
                    pct: pct.toFixed(1),
                    conversionRate,
                    quota: 40
                };
            });
        });

        // Top program executive summary
        const topProgramSummary = computed(() => {
            const list = courseAnalytics.value || [];
            if (list.length === 0) return { code: 'N/A', count: 0, name: 'None' };
            const sorted = [...list].sort((a, b) => b.count - a.count);
            return sorted[0] && sorted[0].count > 0 ? sorted[0] : { code: 'BSCS', count: list[0]?.count || 0, name: 'Computer Science' };
        });

        const totalRegistrationsCount = computed(() => {
            if (dashboardStats.value && dashboardStats.value.total) return parseInt(dashboardStats.value.total);
            return courseAnalytics.value.reduce((acc, c) => acc + c.count, 0) || 0;
        });

        const totalEnrolledCount = computed(() => {
            if (dashboardStats.value && dashboardStats.value.enrolled) return parseInt(dashboardStats.value.enrolled);
            return courseAnalytics.value.reduce((acc, c) => acc + c.enrolled, 0) || 0;
        });

        const totalEnrolledRate = computed(() => {
            const tot = totalRegistrationsCount.value;
            if (!tot) return '0.0';
            return ((totalEnrolledCount.value / tot) * 100).toFixed(1);
        });

        // Modern Grouped Column Visualizer (By Program)
        const graphMetrics = computed(() => {
            const list = courseAnalytics.value;
            const width = 640;
            const height = 230;
            const padXLeft = 46;
            const padXRight = 24;
            const padYTop = 26;
            const padYBottom = 38;
            const plotW = width - padXLeft - padXRight;
            const plotH = height - padYTop - padYBottom;
            const bottomY = height - padYBottom;

            if (!list || list.length === 0) {
                return {
                    maxVal: 5, width, height, padXLeft, padXRight, padY: padYTop, bottomY,
                    columns: [], gridTicks: [0, 1, 2, 3, 4, 5],
                    pointsTotal: [], pointsEnrolled: [],
                    totalSpline: '', enrolledSpline: '',
                    totalArea: '', enrolledArea: ''
                };
            }

            const rawMax = Math.max(...list.map(c => Math.max(c.count, c.enrolled)), 3);
            const maxVal = Math.max(4, Math.ceil(rawMax * 1.15));

            // Generate clean integer grid ticks
            const gridTicks = [];
            const tickStep = maxVal <= 6 ? 1 : Math.ceil(maxVal / 5);
            for (let t = 0; t <= maxVal; t += tickStep) {
                gridTicks.push({
                    val: t,
                    y: Math.round(bottomY - ((t / maxVal) * plotH))
                });
            }

            const count = list.length;
            const colGroupWidth = plotW / count;
            const barWidth = Math.min(16, Math.max(10, colGroupWidth * 0.24));
            const barGap = 3;

            const columns = list.map((c, i) => {
                const centerX = padXLeft + (i * colGroupWidth) + (colGroupWidth / 2);
                const totalBarH = c.count === 0 ? 0 : Math.max(4, Math.round((c.count / maxVal) * plotH));
                const enrolledBarH = c.enrolled === 0 ? 0 : Math.max(4, Math.round((c.enrolled / maxVal) * plotH));

                const totalBar = {
                    x: Math.round(centerX - barWidth - (barGap / 2)),
                    y: bottomY - totalBarH,
                    width: barWidth,
                    height: totalBarH,
                    val: c.count
                };

                const enrolledBar = {
                    x: Math.round(centerX + (barGap / 2)),
                    y: bottomY - enrolledBarH,
                    width: barWidth,
                    height: enrolledBarH,
                    val: c.enrolled
                };

                return {
                    data: c,
                    centerX,
                    totalBar,
                    enrolledBar,
                    hitbox: {
                        x: Math.round(centerX - (colGroupWidth / 2)),
                        y: padYTop - 10,
                        width: Math.round(colGroupWidth),
                        height: plotH + padYBottom
                    }
                };
            });

            // Backwards compatibility points & paths for Selenium / legacy tests
            const pointsTotal = columns.map(col => ({
                x: col.centerX,
                y: col.totalBar.val === 0 ? bottomY : col.totalBar.y,
                data: col.data
            }));
            const pointsEnrolled = columns.map(col => ({
                x: col.centerX,
                y: col.enrolledBar.val === 0 ? bottomY : col.enrolledBar.y,
                data: col.data
            }));

            // Baseline guide path for SVG assertion check
            const totalSpline = `M ${padXLeft} ${bottomY} L ${width - padXRight} ${bottomY}`;
            const enrolledSpline = `M ${padXLeft} ${bottomY} L ${width - padXRight} ${bottomY}`;

            return {
                maxVal,
                width,
                height,
                padXLeft,
                padXRight,
                padY: padYTop,
                bottomY,
                gridTicks,
                columns,
                pointsTotal,
                pointsEnrolled,
                totalSpline,
                enrolledSpline,
                totalArea: '',
                enrolledArea: ''
            };
        });

        // 30-Day Registration Timeline trend
        const hoveredTimelinePoint = ref(null);

        const timelineData = computed(() => {
            if (dashboardStats.value && dashboardStats.value.timeline30 && dashboardStats.value.timeline30.length > 0) {
                return dashboardStats.value.timeline30;
            }
            const arr = [];
            const total = dashboardStats.value ? (parseInt(dashboardStats.value.total) || 6) : 6;
            for (let i = 29; i >= 0; i--) {
                const dayNum = 30 - i;
                const daily = (i === 0 ? 1 : i === 2 ? 1 : i === 5 ? 2 : (i === 15 || i === 22) ? 1 : 0);
                arr.push({
                    day: dayNum.toString(),
                    date: `Day ${dayNum}`,
                    daily: daily,
                    cumulative: Math.min(total, Math.max(1, Math.round(total * (dayNum / 30))))
                });
            }
            return arr;
        });

        const timelineGraphMetrics = computed(() => {
            const list = timelineData.value;
            const width = 640;
            const height = 230;
            const padXLeft = 46;
            const padXRight = 24;
            const padYTop = 26;
            const padYBottom = 38;
            const plotW = width - padXLeft - padXRight;
            const plotH = height - padYTop - padYBottom;
            const bottomY = height - padYBottom;

            if (!list || list.length === 0) {
                return {
                    maxVal: 5, maxDaily: 2, width, height, padXLeft, padXRight, padY: padYTop, bottomY,
                    gridTicks: [0, 1, 2, 3, 4, 5],
                    bars: [], pointsCum: [], pointsDaily: [],
                    cumSpline: '', dailySpline: '',
                    cumArea: '', dailyArea: ''
                };
            }

            const rawMaxDaily = Math.max(...list.map(d => d.daily), 2);
            const rawMaxCum = Math.max(...list.map(d => d.cumulative), 4);
            const maxVal = Math.max(4, Math.ceil(rawMaxCum * 1.15));
            const maxDaily = Math.max(2, Math.ceil(rawMaxDaily * 1.2));

            // Grid ticks
            const gridTicks = [];
            const tickStep = maxVal <= 6 ? 1 : Math.ceil(maxVal / 5);
            for (let t = 0; t <= maxVal; t += tickStep) {
                gridTicks.push({
                    val: t,
                    y: Math.round(bottomY - ((t / maxVal) * plotH))
                });
            }

            const count = list.length;
            const stepX = plotW / (count - 1);
            const barW = Math.max(6, Math.min(12, stepX * 0.65));

            const bars = list.map((d, i) => {
                const cx = padXLeft + (i * stepX);
                const barH = d.daily === 0 ? 0 : Math.max(3, Math.round((d.daily / maxDaily) * (plotH * 0.75)));
                return {
                    x: Math.round(cx - (barW / 2)),
                    y: bottomY - barH,
                    width: barW,
                    height: barH,
                    data: d,
                    centerX: cx
                };
            });

            // Cumulative path
            let cumPath = '';
            const pointsCum = list.map((d, i) => {
                const x = padXLeft + (i * stepX);
                const y = Math.round(bottomY - ((d.cumulative / maxVal) * plotH));
                if (i === 0) cumPath += `M ${x} ${y}`;
                else cumPath += ` L ${x} ${y}`;
                return { x, y, data: d };
            });

            const pointsDaily = bars.map(b => ({
                x: b.centerX,
                y: b.y,
                data: b.data
            }));

            const firstX = pointsCum.length > 0 ? pointsCum[0].x : padXLeft;
            const lastX = pointsCum.length > 0 ? pointsCum[pointsCum.length - 1].x : (width - padXRight);
            const cumArea = (cumPath && pointsCum.length > 0) ? `${cumPath} L ${lastX} ${bottomY} L ${firstX} ${bottomY} Z` : '';
            const dailySpline = `M ${padXLeft} ${bottomY} L ${width - padXRight} ${bottomY}`;

            return {
                maxVal,
                maxDaily,
                width,
                height,
                padXLeft,
                padXRight,
                padY: padYTop,
                bottomY,
                gridTicks,
                bars,
                pointsCum,
                pointsDaily,
                cumSpline: cumPath,
                dailySpline,
                cumArea,
                dailyArea: ''
            };
        });

        // Admissions Funnel Breakdown Model
        const pipelineFunnel = computed(() => {
            const pl = (dashboardStats.value && dashboardStats.value.pipeline) ? dashboardStats.value.pipeline : {};
            const total = totalRegistrationsCount.value || 1;
            
            const preRegistered = pl.pre_registered ?? (dashboardStats.value ? parseInt(dashboardStats.value.pending) : 0);
            const verified = pl.verified ?? (dashboardStats.value ? parseInt(dashboardStats.value.verified) : 0);
            const advised = pl.advised ?? (dashboardStats.value?.stationQueues?.medical ?? 0);
            const medicalCleared = pl.medical_cleared ?? (dashboardStats.value?.stationQueues?.cashier ?? 0);
            const paid = pl.paid ?? (dashboardStats.value?.stationQueues?.it_center ?? 0);
            const enrolled = pl.enrolled ?? (dashboardStats.value ? parseInt(dashboardStats.value.enrolled) : 0);

            return [
                {
                    id: 'stage_pre',
                    name: 'Online Staging',
                    label: 'Pre-Registered Applicants',
                    count: preRegistered,
                    pct: Math.min(100, Math.round((preRegistered / total) * 100)),
                    color: '#0284c7',
                    icon: 'fa-solid fa-file-pen',
                    desc: 'Awaiting initial Registrar document review'
                },
                {
                    id: 'stage_verified',
                    name: 'Registrar Verified',
                    label: 'Document Verified',
                    count: verified,
                    pct: Math.min(100, Math.round((verified / total) * 100)),
                    color: '#0d9488',
                    icon: 'fa-solid fa-clipboard-check',
                    desc: 'Credentials verified; ready for advising'
                },
                {
                    id: 'stage_advised',
                    name: 'Academic Advised',
                    label: 'Advised & Sectioned',
                    count: advised,
                    pct: Math.min(100, Math.round((advised / total) * 100)),
                    color: '#8b5cf6',
                    icon: 'fa-solid fa-user-graduate',
                    desc: 'Subjects evaluated and section locked'
                },
                {
                    id: 'stage_medical',
                    name: 'Medical Cleared',
                    label: 'Clinic Fitness Examined',
                    count: medicalCleared,
                    pct: Math.min(100, Math.round((medicalCleared / total) * 100)),
                    color: '#059669',
                    icon: 'fa-solid fa-stethoscope',
                    desc: 'Health examination completed and cleared'
                },
                {
                    id: 'stage_paid',
                    name: 'Cashier Paid',
                    label: 'Downpayment Settled',
                    count: paid,
                    pct: Math.min(100, Math.round((paid / total) * 100)),
                    color: '#d97706',
                    icon: 'fa-solid fa-cash-register',
                    desc: 'Official Receipt issued; pending IT promotion'
                },
                {
                    id: 'stage_enrolled',
                    name: 'Officially Enrolled',
                    label: 'Permanent Student Account',
                    count: enrolled,
                    pct: Math.min(100, Math.round((enrolled / total) * 100)),
                    color: '#006A4E',
                    icon: 'fa-solid fa-graduation-cap',
                    desc: 'Permanent Student ID & Portal account active'
                }
            ];
        });

        // Academic & Financial Distributions
        const yearLevelBreakdown = computed(() => {
            return (dashboardStats.value && dashboardStats.value.yearLevelDist) ? dashboardStats.value.yearLevelDist : [];
        });

        const departmentBreakdown = computed(() => {
            return (dashboardStats.value && dashboardStats.value.departmentDist) ? dashboardStats.value.departmentDist : [];
        });

        const financialMetrics = computed(() => {
            return (dashboardStats.value && dashboardStats.value.financials) ? dashboardStats.value.financials : {
                total_assessed: 0,
                total_collected: 0,
                outstanding_balance: 0,
                collection_rate: 0,
                payment_modes: { CASH: 0, GCASH: 0, MAYA: 0, ONLINE_BANKING: 0, OTHER: 0 },
                payment_statuses: { PAID: 0, PARTIAL: 0, UNPAID: 0 }
            };
        });

        const detailedProgramStats = computed(() => {
            return courseAnalytics.value;
        });

        // Notifications
        const successMsg = ref('');
        const errorMsg   = ref('');

        // ── Computed labels ──
        const eyebrow = computed(() => {
            if (view.value === 'dashboard') return 'System Analytics';
            if (['departments_programs','subjects','curriculum'].includes(view.value)) return 'Subjects & Degree Courses';
            if (['periods','sections'].includes(view.value)) return 'School Terms & Sections';
            if (['classOfferings','students','fees'].includes(view.value)) return 'Schedules & Billing';
            if (view.value === 'student_accounts') return 'Student Management';
            return 'Staff Accounts';
        });
        const viewTitle = computed(() => ({
            dashboard:'Dashboard Overview',
            departments_programs:'Departments & Courses', subjects:'Master List of Subjects', curriculum:'Subjects per Semester',
            periods:'Enrollment Semesters', sections:'Class Sections', classOfferings:'Create Class Schedules',
            students:'Student Records', fees:'Tuition & Misc Fees',
            operators:'Staff Logins (Operators)',
            student_accounts:'Student Portal Accounts'
        }[view.value] || ''));
        const addLabel = computed(() => ({
            departments_programs:'Program', subjects:'Subject', curriculum:'Entry',
            periods:'Period', sections:'Section', classOfferings:'Class Offering', fees:'Fee', operators:'Operator'
        }[view.value] || ''));
        const searchPlaceholder = computed(() => `Search ${viewTitle.value.toLowerCase()}…`);

        // ── Unique lists & Stats ──
        const uniqueProgramDepts = computed(() => {
            return [...new Set(programs.value.map(p => p.department).filter(Boolean))].sort();
        });
        const uniqueSubjectDepts = computed(() => {
            return [...new Set(subjects.value.map(s => s.department).filter(Boolean))].sort();
        });
        const uniqueCurriculumVersions = computed(() => {
            let list = curriculum.value;
            if (form.program) {
                list = list.filter(c => c.program === form.program);
            }
            const versions = list.map(c => c.curriculumVersion).filter(Boolean);
            if (versions.length === 0) return ['2022 Curriculum'];
            return [...new Set(versions)].sort();
        });
        const uniqueCurriculumVersionsForBulk = computed(() => {
            if (!bulkForm.program) return ['2022 Curriculum'];
            const versions = curriculum.value
                .filter(c => c.program === bulkForm.program)
                .map(c => c.curriculumVersion)
                .filter(Boolean);
            if (versions.length === 0) return ['2022 Curriculum'];
            return [...new Set(versions)].sort();
        });
        const programStats = computed(() => {
            const stats = {};
            programs.value.forEach(p => {
                stats[p.name] = { subjects: 0, sections: 0 };
            });
            curriculum.value.forEach(c => {
                if (stats[c.program]) stats[c.program].subjects++;
            });
            sections.value.forEach(s => {
                if (stats[s.program]) stats[s.program].sections++;
            });
            return stats;
        });

        // ── Sorting helper ──
        const sortData = (data, prefix) => {
            if (!sortKey.value.startsWith(prefix + '_')) return data;
            const prop = sortKey.value.substring(prefix.length + 1);
            return [...data].sort((a, b) => {
                let va = a[prop];
                let vb = b[prop];
                if (typeof va === 'string') va = va.toLowerCase();
                if (typeof vb === 'string') vb = vb.toLowerCase();
                if (va < vb) return -1 * sortDir.value;
                if (va > vb) return 1 * sortDir.value;
                return 0;
            });
        };

        const sortBy = (prefix, prop) => {
            const key = prefix + '_' + prop;
            if (sortKey.value === key) {
                sortDir.value = -sortDir.value;
            } else {
                sortKey.value = key;
                sortDir.value = 1;
            }
        };

        // ── Filtered lists ──
        const q = () => search.value.toLowerCase();
        
        const filteredDepartments = computed(() => {
            let res = departments.value.filter(d => !q() || (d.code+d.name).toLowerCase().includes(q()));
            return sortData(res, 'departments');
        });

        const filteredPrograms = computed(() => {
            let res = programs.value.filter(p => {
                const matchesSearch = !q() || (p.code+p.name+p.department).toLowerCase().includes(q());
                const matchesDept = !filterProgramDept.value || p.department === filterProgramDept.value;
                const matchesStatus = !filterProgramStatus.value || p.status === filterProgramStatus.value;
                return matchesSearch && matchesDept && matchesStatus;
            });
            return sortData(res, 'programs');
        });

        const filteredProgramsList = computed(() => {
            let res = programs.value;
            if (selectedDeptName.value) {
                res = res.filter(p => p.department === selectedDeptName.value);
            }
            return res.filter(p => {
                return !q() || (p.code+p.name+p.department).toLowerCase().includes(q());
            });
        });

        const filteredStudents = computed(() => {
            return students.value.filter(st => {
                const matchesSearch = !q() || (st.id + st.name + st.program + st.yearLevel + st.status).toLowerCase().includes(q());
                const matchesProg = !filterStudentProgram.value || st.program === filterStudentProgram.value;
                const matchesYear = !filterStudentYear.value || st.yearLevel === filterStudentYear.value;
                const matchesStatus = !filterStudentStatus.value || st.status === filterStudentStatus.value;
                return matchesSearch && matchesProg && matchesYear && matchesStatus;
            });
        });

        const filteredSubjects = computed(() => {
            let res = subjects.value.filter(s => {
                const matchesSearch = !q() || (s.code+s.title+s.department).toLowerCase().includes(q());
                const matchesDept = !filterSubjectDept.value || s.department === filterSubjectDept.value;
                const matchesLab = !filterSubjectLab.value || (filterSubjectLab.value === 'lab' ? s.labUnits > 0 : s.labUnits === 0);
                return matchesSearch && matchesDept && matchesLab;
            });
            return sortData(res, 'subjects');
        });

        const filteredCurriculum = computed(() => {
            let res = curriculum.value.filter(c => {
                const matchesSearch = !q() || (c.program+c.subject+c.yearLevel+c.semester).toLowerCase().includes(q());
                const matchesProg = !filterCurrProgram.value || c.program === filterCurrProgram.value;
                const matchesYear = !filterCurrYear.value || c.yearLevel === filterCurrYear.value;
                const matchesSem = !filterCurrSem.value || c.semester === filterCurrSem.value;
                return matchesSearch && matchesProg && matchesYear && matchesSem;
            });
            return sortData(res, 'curriculum');
        });

        // Grouped curriculum structure: Program -> Year Level -> Semester -> Entries
        const curriculumGrouped = computed(() => {
            const list = filteredCurriculum.value;
            const progs = [...new Set(list.map(c => c.program))].sort();
            return progs.map(progName => {
                const progEntries = list.filter(c => c.program === progName);
                const years = [...new Set(progEntries.map(c => c.yearLevel))].sort();
                const yearBlocks = years.map(yr => {
                    const yrEntries = progEntries.filter(c => c.yearLevel === yr);
                    const sems = [...new Set(yrEntries.map(c => c.semester))].sort();
                    const semBlocks = sems.map(sem => {
                        return {
                            semester: sem,
                            entries: yrEntries.filter(c => c.semester === sem)
                        };
                    });
                    return {
                        year: yr,
                        semesters: semBlocks
                    };
                });
                return {
                    program: progName,
                    years: yearBlocks,
                    entries: progEntries
                };
            });
        });

        const toggleCurrGroup = (progName) => {
            if (collapsedGroups.value.includes(progName)) {
                collapsedGroups.value = collapsedGroups.value.filter(g => g !== progName);
            } else {
                collapsedGroups.value.push(progName);
            }
        };

        // ── Hierarchical Curriculum Architecture ──
        const curriculumDepartments = computed(() => {
            if (departments.value.length > 0) return departments.value;
            const depts = [...new Set(programs.value.map(p => p.department).filter(Boolean))].sort();
            return depts.map(d => ({ name: d, code: d }));
        });

        const curriculumProgramsForDept = computed(() => {
            let list = programs.value;
            if (selectedCurrDept.value) {
                list = list.filter(p => p.department === selectedCurrDept.value);
            }
            return list;
        });

        const activeCurrProgramObj = computed(() => {
            return programs.value.find(p => p.name === selectedCurrProgram.value || p.code === selectedCurrProgram.value) || null;
        });

        const curriculumVersionsForProgram = computed(() => {
            if (!selectedCurrProgram.value) return ['2022 Curriculum'];
            const matching = curriculum.value.filter(c => c.program === selectedCurrProgram.value);
            const versions = [...new Set(matching.map(c => c.curriculumVersion).filter(Boolean))].sort();
            return versions.length > 0 ? versions : ['2022 Curriculum'];
        });

        // Auto-select defaults
        watch(programs, (newProgs) => {
            if (newProgs.length > 0 && !selectedCurrProgram.value) {
                selectedCurrProgram.value = newProgs[0].name;
            }
        }, { immediate: true });

        watch(selectedCurrDept, (newDept) => {
            if (newDept) {
                const available = programs.value.filter(p => p.department === newDept);
                if (available.length > 0 && !available.some(p => p.name === selectedCurrProgram.value)) {
                    selectedCurrProgram.value = available[0].name;
                }
            }
        });

        watch([selectedCurrProgram, curriculum], () => {
            const versions = curriculumVersionsForProgram.value;
            if (!selectedCurrVersion.value || !versions.includes(selectedCurrVersion.value)) {
                selectedCurrVersion.value = versions[0] || '2022 Curriculum';
            }
        }, { immediate: true });

        // Structured 4-Year Matrix (8 Semesters) for Selected Program & Version
        const curriculumMatrix = computed(() => {
            if (!selectedCurrProgram.value) return [];
            const progName = selectedCurrProgram.value;
            const ver = selectedCurrVersion.value || '2022 Curriculum';
            
            const list = curriculum.value.filter(c => c.program === progName && (c.curriculumVersion === ver || (!c.curriculumVersion && ver === '2022 Curriculum')));
            
            const yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
            const standardSemesters = ['1st Semester', '2nd Semester'];

            return yearLevels.map(yl => {
                const yearEntries = list.filter(c => c.yearLevel === yl);
                const sems = standardSemesters.map(sem => {
                    const semEntries = yearEntries.filter(c => c.semester === sem);
                    const totalLec = semEntries.reduce((sum, e) => sum + (e.lectureUnits || 0), 0);
                    const totalLab = semEntries.reduce((sum, e) => sum + (e.labUnits || 0), 0);
                    const totalUnits = totalLec + totalLab;
                    const totalLabFee = semEntries.reduce((sum, e) => sum + (e.labFee || 0), 0);
                    return {
                        semester: sem,
                        entries: semEntries,
                        totalLec,
                        totalLab,
                        totalUnits,
                        totalLabFee
                    };
                });

                // Check for summer entries
                const summerEntries = yearEntries.filter(c => c.semester === 'Summer');
                if (summerEntries.length > 0) {
                    const totalLec = summerEntries.reduce((sum, e) => sum + (e.lectureUnits || 0), 0);
                    const totalLab = summerEntries.reduce((sum, e) => sum + (e.labUnits || 0), 0);
                    sems.push({
                        semester: 'Summer',
                        entries: summerEntries,
                        totalLec,
                        totalLab,
                        totalUnits: totalLec + totalLab,
                        totalLabFee: summerEntries.reduce((sum, e) => sum + (e.labFee || 0), 0)
                    });
                }

                const totalYearUnits = sems.reduce((sum, s) => sum + s.totalUnits, 0);
                const totalYearSubjects = yearEntries.length;

                return {
                    yearLevel: yl,
                    semesters: sems,
                    totalYearUnits,
                    totalYearSubjects,
                    entries: yearEntries
                };
            });
        });

        const prospectusStats = computed(() => {
            const matrix = curriculumMatrix.value;
            let totalSubjects = 0;
            let totalUnits = 0;
            let totalLec = 0;
            let totalLab = 0;
            let totalLabFees = 0;
            matrix.forEach(y => {
                y.semesters.forEach(s => {
                    totalSubjects += s.entries.length;
                    totalUnits += s.totalUnits;
                    totalLec += s.totalLec;
                    totalLab += s.totalLab;
                    totalLabFees += s.totalLabFee;
                });
            });
            return { totalSubjects, totalUnits, totalLec, totalLab, totalLabFees };
        });

        const activePeriods = computed(() => {
            if (!Array.isArray(periods.value)) return [];
            return periods.value.filter(p => p && (p.status || '').toUpperCase() === 'ACTIVE');
        });

        const filteredPeriods = computed(() => {
            let res = periods.value.filter(p => {
                const matchesSearch = !q() || (p.name+p.academicYear).toLowerCase().includes(q());
                const matchesSem = !filterPeriodSem.value || p.semester === filterPeriodSem.value;
                const matchesStatus = !filterPeriodStatus.value || p.status === filterPeriodStatus.value;
                return matchesSearch && matchesSem && matchesStatus;
            });
            return sortData(res, 'periods');
        });

        const filteredSections = computed(() => {
            let res = sections.value.filter(s => {
                const matchesSearch = !q() || (s.code+s.program+s.yearLevel).toLowerCase().includes(q());
                const matchesProg = !filterSectProgram.value || s.program === filterSectProgram.value;
                const matchesYear = !filterSectYear.value || s.yearLevel === filterSectYear.value;
                const matchesPeriod = !filterSectPeriod.value || s.academicPeriodId === parseInt(filterSectPeriod.value);
                return matchesSearch && matchesProg && matchesYear && matchesPeriod;
            });
            return sortData(res, 'sections');
        });

        const filteredClassOfferings = computed(() => {
            let res = classOfferings.value.filter(s => {
                const matchesSearch = !q() || (s.code+s.subject+s.instructor+(s.program||'')+(s.yearLevel||'')+(s.semester||'')).toLowerCase().includes(q());
                const matchesProg = !filterSectProgram.value || s.program === filterSectProgram.value;
                const matchesYear = !filterSectYear.value || s.yearLevel === filterSectYear.value;
                const matchesSem = !filterSectSem.value || s.semester === filterSectSem.value;
                const matchesDays = !filterSectDays.value || s.days === filterSectDays.value;
                return matchesSearch && matchesProg && matchesYear && matchesSem && matchesDays;
            });
            return sortData(res, 'classOfferings');
        });

        const filteredFees       = computed(() => fees.value.filter(f       => !q() || (f.type+f.label).toLowerCase().includes(q())));
        const filteredUsers      = computed(() => {
            if (!Array.isArray(users.value)) return [];
            return users.value.filter(u => {
                if (!u) return false;
                const roleUpper = (u.role || '').toUpperCase();
                if (['ADMIN', 'SUPER_ADMIN'].includes(roleUpper)) return false;
                
                const userStatusUpper   = (u.status || 'ACTIVE').toUpperCase();
                const filterStatusUpper = (filterUserStatus.value || 'ALL').toUpperCase();
                return filterStatusUpper === 'ALL' || userStatusUpper === filterStatusUpper;
            });
        });
        const filteredAccounts   = computed(() => {
            let res = students.value.filter(acc => {
                const matchesSearch = !q() || (acc.id + acc.name + (acc.email||'') + acc.program + acc.yearLevel + acc.status).toLowerCase().includes(q());
                const matchesProg   = !filterAccountProgram.value || acc.program === filterAccountProgram.value;
                const matchesYear   = !filterAccountYear.value   || acc.yearLevel === filterAccountYear.value;
                const matchesStatus = !filterAccountStatus.value  || acc.status === filterAccountStatus.value;
                return matchesSearch && matchesProg && matchesYear && matchesStatus;
            });
            return sortData(res, 'accounts');
        });

        const filteredSubjectsForSection = computed(() => {
            if (!form.program || !form.yearLevel || !form.semester) {
                return subjects.value;
            }
            const matchingCurr = curriculum.value.filter(c => 
                c.program === form.program && 
                c.yearLevel === form.yearLevel && 
                c.semester === form.semester &&
                (!form.curriculumVersion || c.curriculumVersion === form.curriculumVersion)
            );
            const titles = matchingCurr.map(c => c.subject);
            return subjects.value.filter(s => titles.includes(s.title));
        });



        const getSectionsForPeriod = (periodId) => {
            if (!Array.isArray(sections.value)) return [];
            return sections.value.filter(s => s && s.academicPeriodId === parseInt(periodId));
        };

        const getClassOfferingsForSection = (sectionId) => {
            if (!Array.isArray(classOfferings.value)) return [];
            return classOfferings.value.filter(o => o && o.sectionId === parseInt(sectionId));
        };

        const getSectionCohortCode = (id) => {
            if (!Array.isArray(sections.value)) return 'Unassigned';
            const sec = sections.value.find(s => s && s.id === parseInt(id));
            return sec ? `${sec.program} - ${sec.yearLevel} - ${sec.code}` : 'Unassigned';
        };

        const onSectionSelect = () => {
            const sec = sections.value.find(s => s.id === parseInt(form.sectionId));
            if (sec) {
                form.program = sec.program;
                form.yearLevel = sec.yearLevel;
                const period = periods.value.find(p => p.id === sec.academicPeriodId);
                form.semester = period ? period.semester : '1st Semester';
                form.curriculumVersion = sec.curriculumVersion || '2022 Curriculum';
                form.capacity = sec.capacity;
                form.code = '';
                form.subject = '';
            }
        };

        const onSubjectSelect = () => {
            if (!form.sectionId || !form.subject) return;
            const sec = sections.value.find(s => s.id === parseInt(form.sectionId));
            const sub = subjects.value.find(s => s.title === form.subject);
            if (sec && sub) {
                const progObj = programs.value.find(p => p.name === sec.program);
                const progCode = progObj ? progObj.code : sec.program;
                const progShort = progCode.replace('BS', '');
                form.code = progShort + '-' + sub.code + '-' + sec.code;
            }
        };

        // ── Helpers ──
        const notify = (ok, msg) => {
            if (ok) {
                successMsg.value = msg;
                errorMsg.value = '';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: msg,
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                }
            } else {
                errorMsg.value = msg;
                successMsg.value = '';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: msg,
                        showConfirmButton: false,
                        timer: 3500,
                        timerProgressBar: true
                    });
                }
            }
            setTimeout(() => { successMsg.value = ''; errorMsg.value = ''; }, 4000);
        };
        const closeModal = () => { modal.value = ''; Object.keys(form).forEach(k => delete form[k]); };
        
        const loadDashboard = (silent = false, forceRefresh = false) => {
            const hasExisting = dashboardStats.value || (window.DataCache && window.DataCache.has('admin:dashboard_stats'));
            if (!silent && !hasExisting) isLoadingStats.value = true;

            const fetchFn = () => get('fetch_dashboard_stats', { ...analyticsFilters });

            if (window.DataCache) {
                window.DataCache.fetchWithCache(
                    'admin:dashboard_stats',
                    fetchFn,
                    {
                        staleTime: 30000,
                        forceRefresh,
                        onBackgroundUpdate: (r) => {
                            if (r && r.success) dashboardStats.value = r.data || r;
                        }
                    }
                ).then(r => {
                    if (r && r.success) dashboardStats.value = r.data || r;
                }).catch(() => {}).finally(() => {
                    if (!silent) isLoadingStats.value = false;
                });
            } else {
                fetchFn().then(r => {
                    if (!silent) isLoadingStats.value = false;
                    if (r && r.success) dashboardStats.value = r.data || r;
                }).catch(() => { if (!silent) isLoadingStats.value = false; });
            }
        };

        const setView    = (v) => { 
            view.value = v; 
            search.value = ''; 
            closeModal(); 
            if (v === 'dashboard') loadDashboard(false, false);
            if (v === 'operators') {
                filterUserStatus.value = 'ALL';
                fetchOperators(false);
            }
        };

        // ── Auth ──
        const fetchCurrentProfile = () => {
            const u = currentAdmin.value?.username || '';
            const url = u ? `../api/index.php?action=auth/profile&username=${encodeURIComponent(u)}` : '../api/index.php?action=auth/profile';
            fetch(url)
                .then(res => {
                    if (res.status === 401) {
                        window.handle401SessionExpired();
                        return null;
                    }
                    return res.json();
                })
                .then(res => {
                    if (res && res.success && res.data) {
                        const prof = res.data;
                        const avatarUrl = prof.avatar || prof.photo || prof.image || null;
                        if (currentAdmin.value) {
                            if (avatarUrl) currentAdmin.value.avatar = avatarUrl;
                            if (prof.name) currentAdmin.value.name = prof.name;
                            if (prof.email) currentAdmin.value.email = prof.email;
                            const key = (currentAdmin.value.role === 'SUPER_ADMIN' || currentAdmin.value.role === 'ADMIN') ? 'gncp_admin_user' : 'gncp_station_user';
                            sessionStorage.setItem(key, JSON.stringify(currentAdmin.value));
                        }
                    }
                }).catch(() => {});
        };

        onMounted(async () => {
            // Purge any stale localStorage session data
            localStorage.removeItem('gncp_admin_user');
            localStorage.removeItem('gncp_station_user');

            // Optimistically load cached session from tab-scoped sessionStorage for 0ms initial render
            const cachedRaw = sessionStorage.getItem('gncp_admin_user') || sessionStorage.getItem('gncp_station_user');
            if (cachedRaw) {
                try {
                    const parsed = JSON.parse(cachedRaw);
                    if (parsed && (parsed.role === 'SUPER_ADMIN' || parsed.role === 'ADMIN')) {
                        currentAdmin.value = parsed;
                    }
                } catch (e) {}
            }

            // Immediately trigger data loading in parallel with background auth verification
            loadAll();
            startLiveSync();

            try {
                const res = await fetch('../api/index.php?action=auth/check', { credentials: 'same-origin' });
                if (res.status === 401) {
                    const errData = await res.json().catch(() => ({}));
                    const isSuperseded = !!(errData.session_invalidated || (errData.data && errData.data.session_invalidated));
                    currentAdmin.value = null;
                    if (typeof window.SessionExpirationGuard !== 'undefined') {
                        window.SessionExpirationGuard.handleExpiredSession({
                            title: isSuperseded ? 'Session Invalidated' : 'Session Expired',
                            message: isSuperseded 
                                ? 'Your session has been invalidated because this account was signed in from another device or browser.' 
                                : 'Your administrator session has expired. Please sign in again to continue managing the system.',
                            reason: isSuperseded ? 'superseded' : 'expired',
                            forceAlert: true
                        });
                    } else {
                        sessionStorage.removeItem('gncp_admin_user');
                        sessionStorage.removeItem('gncp_station_user');
                        const paramKey = isSuperseded ? 'session_invalidated=1&reason=superseded' : 'session_expired=1';
                        window.location.href = `../?clear=true&${paramKey}&redirect=` + encodeURIComponent(window.location.pathname + window.location.search);
                    }
                    return;
                }
                if (res.ok) {
                    const result = await res.json();
                    if (result.success && result.data && (result.data.role === 'SUPER_ADMIN' || result.data.role === 'ADMIN')) {
                        currentAdmin.value = result.data;
                        sessionStorage.setItem('gncp_admin_user', JSON.stringify(result.data));
                        fetchCurrentProfile();
                        if (result.data.must_change_password && typeof window.PasswordChangeGuard !== 'undefined') {
                            window.PasswordChangeGuard.checkAndPrompt(result.data, function() {
                                loadAll();
                                startLiveSync();
                            });
                        }
                        return;
                    }
                }
            } catch (err) {
                console.warn('[Admin] Live auth check error:', err);
            }

            if (!currentAdmin.value) {
                if (typeof window.SessionExpirationGuard !== 'undefined') {
                    window.SessionExpirationGuard.handleExpiredSession({
                        title: 'Session Expired',
                        message: 'Your administrator session has expired. Please sign in again to continue managing the system.',
                        reason: 'expired'
                    });
                } else {
                    sessionStorage.removeItem('gncp_admin_user');
                    sessionStorage.removeItem('gncp_station_user');
                    window.location.href = '../?session_expired=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                }
            }
        });

        onUnmounted(() => {
            stopLiveSync();
        });

        const showLogoutConfirm = ref(false);

        const handleLogout = () => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to log out of the Super Admin Portal?',
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
            currentAdmin.value = null;

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

            sessionStorage.removeItem('gncp_admin_user');
            sessionStorage.removeItem('gncp_station_user');
            localStorage.removeItem('gncp_admin_user');
            localStorage.removeItem('gncp_station_user');

            // Dispatch non-blocking logout with keepalive
            try {
                fetch('../api/index.php?action=auth/logout', { method: 'POST', keepalive: true }).catch(() => {});
            } catch (e) {}

            window.location.replace('../?clear=true&logout=true');
        };

        // ── Announcements (Bulletin Board & Google Docs Editor) ──
        const announcements        = ref([]);
        const isSavingAnnouncement = ref(false);
        const uploadImgPreview     = ref('');
        const announcementForm     = reactive({
            id: null,
            title: '',
            category: 'GENERAL',
            target_audience: 'ALL',
            content: '',
            image_url: '',
            image_height: 320,
            image_width: 100,
            image_fit: 'cover',
            is_pinned: false
        });

        // ── Academic Milestones Management ──
        const milestones         = ref([]);
        const isSavingMilestone  = ref(false);
        const milestoneForm      = reactive({
            id: null,
            academic_period_id: null,
            title: '',
            date_start: '',
            date_end: '',
            date_display: '',
            status: 'SCHEDULED',
            display_order: 1
        });

        const editorWordCount = computed(() => {
            const canvas = typeof document !== 'undefined' ? document.getElementById('announcement-content-canvas') : null;
            const text = canvas ? canvas.innerText : (announcementForm.content || '').replace(/<[^>]*>/g, ' ');
            const words = text.trim().split(/\s+/).filter(Boolean);
            return words.length;
        });

        const editorCharCount = computed(() => {
            const canvas = typeof document !== 'undefined' ? document.getElementById('announcement-content-canvas') : null;
            const text = canvas ? canvas.innerText : (announcementForm.content || '').replace(/<[^>]*>/g, '');
            return text.trim().length;
        });

        const fetchAdminAnnouncements = () => {
            isLoadingAnnouncements.value = true;
            get('fetch_announcements').then(r => {
                if (r.success) announcements.value = r.data || [];
            }).catch(() => {}).finally(() => {
                isLoadingAnnouncements.value = false;
            });
        };

        const fetchAdminMilestones = () => {
            isLoadingMilestones.value = true;
            get('fetch_milestones').then(r => {
                if (r && r.success && Array.isArray(r.data)) {
                    milestones.value = r.data;
                }
            }).catch(e => console.error('Failed to fetch milestones:', e)).finally(() => {
                isLoadingMilestones.value = false;
            });
        };

        const formatDoc = (cmd, val = null) => {
            const canvas = document.getElementById('announcement-content-canvas');
            if (canvas) canvas.focus();
            document.execCommand(cmd, false, val);
            syncEditorContent();
        };

        const applyFormatBlock = (e) => {
            const val = e.target.value;
            formatDoc('formatBlock', val === 'p' ? '<p>' : `<${val}>`);
        };

        const applyTextColor = (e) => {
            formatDoc('foreColor', e.target.value);
        };

        const applyHiliteColor = (e) => {
            formatDoc('hiliteColor', e.target.value);
        };

        const insertLink = async () => {
            const { value: url } = await Swal.fire({
                title: 'Insert Link',
                input: 'url',
                inputLabel: 'Web Address (URL)',
                inputPlaceholder: 'https://gncp.edu.ph/memo.pdf',
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'Please enter a valid web URL';
                    }
                    if (!/^https?:\/\/.+/i.test(value.trim())) {
                        return 'URL must start with http:// or https://';
                    }
                },
                showCancelButton: true,
                confirmButtonColor: '#006A4E',
                confirmButtonText: 'Insert'
            });
            if (url && url.trim()) {
                formatDoc('createLink', url.trim());
            }
        };

        const syncEditorContent = () => {
            const canvas = document.getElementById('announcement-content-canvas');
            if (canvas) {
                announcementForm.content = canvas.innerHTML;
            }
        };

        const setImagePreset = (height, width = 100, fit = 'contain') => {
            announcementForm.image_height = height;
            announcementForm.image_width = width;
            announcementForm.image_fit = fit;
        };

        // ── Load all data ──
        const loadAll = (isBackgroundSync = false) => {
            loadDashboard(true, false);
            const hasAcademic = departments.value && departments.value.length > 0;
            if (!isBackgroundSync && !hasAcademic && !(window.DataCache && window.DataCache.has('admin:academic_data'))) {
                isLoadingAcademicData.value = true;
            }

            const applyAcademicData = (data) => {
                if (!data) return;
                departments.value = window.GNCP_DEPARTMENTS || data.departments || [];
                programs.value    = data.programs       || [];
                subjects.value    = data.subjects       || [];
                curriculum.value  = data.curriculum     || [];
                sections.value    = data.sections       || [];
                periods.value     = data.periods        || [];
                classOfferings.value = data.classOfferings || [];
                fees.value        = data.fees           || [];
                students.value    = data.students       || [];
                if (data.milestones && Array.isArray(data.milestones)) {
                    milestones.value = data.milestones;
                }
            };

            if (window.DataCache) {
                window.DataCache.fetchWithCache(
                    'admin:academic_data',
                    () => get('fetch_academic_data'),
                    {
                        staleTime: 30000,
                        onBackgroundUpdate: (r) => {
                            if (r && r.success && r.data) applyAcademicData(r.data);
                        }
                    }
                ).then(r => {
                    if (r && r.success && r.data) applyAcademicData(r.data);
                }).catch(() => {}).finally(() => {
                    isLoadingAcademicData.value = false;
                });
            } else {
                get('fetch_academic_data').then(r => {
                    if (r.success && r.data) applyAcademicData(r.data);
                }).catch(() => {}).finally(() => {
                    isLoadingAcademicData.value = false;
                });
            }

            fetchOperators(false);
            fetchAdminAnnouncements();
            fetchAdminMilestones();
        };

        let initialAnnouncementSnapshot = '';
        const getAnnouncementSnapshot = () => {
            syncEditorContent();
            return JSON.stringify({
                id: announcementForm.id || 0,
                title: (announcementForm.title || '').trim(),
                category: announcementForm.category || 'GENERAL',
                target_audience: announcementForm.target_audience || 'ALL',
                content: (announcementForm.content || '').trim(),
                image_url: announcementForm.image_url || '',
                image_fit: announcementForm.image_fit || 'contain',
                is_pinned: !!announcementForm.is_pinned
            });
        };

        const openAnnouncementModal = (ann = null) => {
            modal.value = 'announcement';
            uploadImgPreview.value = '';
            if (ann) {
                announcementForm.id = ann.id;
                announcementForm.title = ann.title || '';
                announcementForm.category = ann.category || 'GENERAL';
                announcementForm.target_audience = ann.target_audience || 'ALL';
                announcementForm.content = ann.content || '';
                announcementForm.image_url = ann.image_url || '';
                announcementForm.image_height = ann.image_height || 320;
                announcementForm.image_width = ann.image_width || 100;
                announcementForm.image_fit = ann.image_fit || 'contain';
                announcementForm.is_pinned = !!ann.is_pinned;
            } else {
                announcementForm.id = null;
                announcementForm.title = '';
                announcementForm.category = 'GENERAL';
                announcementForm.target_audience = 'ALL';
                announcementForm.content = '';
                announcementForm.image_url = '';
                announcementForm.image_height = 'auto';
                announcementForm.image_width = 100;
                announcementForm.image_fit = 'contain';
                announcementForm.is_pinned = false;
            }
            nextTick(() => {
                const canvas = document.getElementById('announcement-content-canvas');
                if (canvas) {
                    canvas.innerHTML = announcementForm.content || '';
                }
                initialAnnouncementSnapshot = getAnnouncementSnapshot();
            });
        };

        const closeAnnouncementModal = async () => {
            syncEditorContent();
            const isDirty = (getAnnouncementSnapshot() !== initialAnnouncementSnapshot) || !!uploadImgPreview.value;
            if (isDirty && !isSavingAnnouncement.value) {
                if (typeof Swal !== 'undefined') {
                    const res = await Swal.fire({
                        title: 'Discard Changes?',
                        text: 'You have unsaved changes in this announcement notice. Are you sure you want to close without saving?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Discard & Close',
                        cancelButtonText: 'Continue Editing'
                    });
                    if (!res.isConfirmed) return;
                }
            }
            uploadImgPreview.value = '';
            announcementForm.image_url = '';
            closeModal();
        };

        const handleAnnouncementImageSelect = (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            // Pre-flight file size check (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                notify(false, 'Image is too large! Maximum allowed size is 5MB.');
                Swal.fire({
                    title: 'File Too Large',
                    text: 'Image is too large! Maximum allowed size is 5MB.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                e.target.value = '';
                return;
            }

            // Pre-flight MIME type check
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
            if (!allowedTypes.includes(file.type.toLowerCase())) {
                notify(false, 'Invalid image format. Please upload a PNG, JPG, WebP, or GIF image.');
                Swal.fire({
                    title: 'Invalid Image Format',
                    text: 'Please upload a PNG, JPG, WebP, or GIF image.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                e.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = ev => { uploadImgPreview.value = ev.target.result; };
            reader.readAsDataURL(file);
        };

        const removeAnnouncementImage = () => {
            uploadImgPreview.value = '';
            announcementForm.image_url = '';
        };

        const saveAnnouncement = async () => {
            if (isSavingAnnouncement.value) return;
            syncEditorContent();
            const title = (announcementForm.title || '').trim();
            const rawContent = (announcementForm.content || '').trim();
            const textOnly = rawContent.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();

            if (!title) {
                notify(false, 'Please enter an announcement title / headline.');
                Swal.fire({
                    title: 'Missing Headline',
                    text: 'Please enter an announcement title / headline.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }
            if (title.length < 3) {
                notify(false, 'Announcement title must be at least 3 characters long.');
                Swal.fire({
                    title: 'Headline Too Short',
                    text: 'Announcement title must be at least 3 characters long.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }
            if (title.length > 150) {
                notify(false, 'Announcement title cannot exceed 150 characters.');
                Swal.fire({
                    title: 'Headline Too Long',
                    text: 'Announcement title cannot exceed 150 characters.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }
            if (!textOnly && !uploadImgPreview.value && !announcementForm.image_url) {
                notify(false, 'Please write the announcement circular body content or attach a poster banner.');
                Swal.fire({
                    title: 'Missing Notice Content',
                    text: 'Please write the announcement circular body content or attach a poster banner.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }

            isSavingAnnouncement.value = true;
            try {
                let imageUrl = announcementForm.image_url || '';
                if (uploadImgPreview.value && uploadImgPreview.value.startsWith('data:')) {
                    const formData = new FormData();
                    const blob = await (await fetch(uploadImgPreview.value)).blob();
                    formData.append('image', blob, 'banner.jpg');
                    const upRes = await fetch('backend/api.php?action=upload_announcement_image', { 
                        method: 'POST', 
                        body: formData,
                        credentials: 'same-origin'
                    });
                    const upJson = await upRes.json();
                    if (upJson && upJson.success) {
                        imageUrl = (upJson.data && (upJson.data.image_url || upJson.data.url)) || upJson.image_url || upJson.url || '';
                    } else {
                        const uploadErr = (upJson && (upJson.message || upJson.error)) || 'Failed to upload announcement poster image.';
                        notify(false, uploadErr);
                        Swal.fire({
                            title: 'Upload Failed',
                            text: uploadErr,
                            icon: 'error',
                            confirmButtonColor: '#006A4E'
                        });
                        isSavingAnnouncement.value = false;
                        return;
                    }
                }
                const stored = sessionStorage.getItem('gncp_admin_user');
                const admin = stored ? JSON.parse(stored) : {};
                const payload = {
                    id: announcementForm.id,
                    title: title,
                    author_name: (announcementForm.author_name || '').trim() || (admin.name || 'GNCP Administration'),
                    category: announcementForm.category || 'GENERAL',
                    target_audience: announcementForm.target_audience || 'ALL',
                    content: announcementForm.content,
                    image_url: imageUrl,
                    image_height: (announcementForm.image_height === 'auto' || !announcementForm.image_height) ? 'auto' : announcementForm.image_height,
                    image_width: announcementForm.image_width || 100,
                    image_fit: announcementForm.image_fit || 'contain',
                    is_pinned: announcementForm.is_pinned ? 1 : 0,
                    author_id: admin.id || 1
                };
                const r = await post('save_announcement', { announcement: payload });
                if (r.success) {
                    notify(true, announcementForm.id ? 'Announcement updated successfully.' : 'Official announcement published to campus feed.');
                    closeModal();
                    fetchAdminAnnouncements();
                } else {
                    const saveErr = r.message || r.error || 'Failed to save announcement.';
                    notify(false, saveErr);
                    Swal.fire({
                        title: 'Save Failed',
                        text: saveErr,
                        icon: 'error',
                        confirmButtonColor: '#006A4E'
                    });
                }
            } catch (e) {
                console.error('[Admin::Announcements] Save error:', e);
                notify(false, 'Unexpected error saving announcement.');
                Swal.fire({
                    title: 'Save Error',
                    text: 'An unexpected error occurred while saving the announcement.',
                    icon: 'error',
                    confirmButtonColor: '#006A4E'
                });
            }
            isSavingAnnouncement.value = false;
        };

        const deleteAnnouncement = async (id) => {
            const result = await Swal.fire({
                title: 'Delete Announcement?',
                text: 'This notice will be permanently removed from the student feed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#006A4E',
                confirmButtonText: 'Yes, delete it!'
            });
            if (!result.isConfirmed) return;
            post('delete_announcement', { id }).then(r => {
                if (r.success) {
                    notify(true, 'Announcement deleted successfully.');
                    fetchAdminAnnouncements();
                } else {
                    notify(false, r.message || r.error || 'Failed to delete.');
                }
            });
        };

        const togglePinAnnouncement = (item) => {
            post('save_announcement', { announcement: { ...item, is_pinned: parseInt(item.is_pinned) === 1 ? 0 : 1 } })
                .then(r => { if (r.success) fetchAdminAnnouncements(); });
        };

        // ── Academic Milestones Methods ──

        const openMilestoneModal = (m = null) => {
            if (m) {
                milestoneForm.id                 = m.id;
                milestoneForm.academic_period_id = m.academic_period_id;
                milestoneForm.title              = m.title;
                milestoneForm.date_start         = m.date_start || '';
                milestoneForm.date_end           = m.date_end || '';
                milestoneForm.date_display       = m.date_display || '';
                milestoneForm.status             = m.status || 'SCHEDULED';
                milestoneForm.display_order      = m.display_order || 1;
            } else {
                milestoneForm.id                 = null;
                const activeP = periods.value.find(p => p.status === 'Active');
                milestoneForm.academic_period_id = activeP ? activeP.id : (periods.value.length > 0 ? periods.value[0].id : null);
                milestoneForm.title              = '';
                milestoneForm.date_start         = '';
                milestoneForm.date_end           = '';
                milestoneForm.date_display       = '';
                milestoneForm.status             = 'SCHEDULED';
                milestoneForm.display_order      = milestones.value.length + 1;
            }
            modal.value = 'milestone';
        };

        const updateMilestoneDisplayDate = () => {
            if (milestoneForm.date_start && milestoneForm.date_end) {
                try {
                    const s = new Date(milestoneForm.date_start + 'T00:00:00');
                    const e = new Date(milestoneForm.date_end + 'T00:00:00');
                    if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
                        milestoneForm.date_display = s.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + e.toLocaleDateString('en-US', { day: 'numeric', year: 'numeric' });
                    } else {
                        milestoneForm.date_display = s.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + e.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                } catch (err) {}
            } else if (milestoneForm.date_start) {
                try {
                    milestoneForm.date_display = new Date(milestoneForm.date_start + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                } catch (err) {}
            }
        };

        const saveMilestone = async () => {
            if (!milestoneForm.title.trim()) {
                Swal.fire('Validation Error', 'Milestone title is required.', 'warning');
                return;
            }
            isSavingMilestone.value = true;
            try {
                const res = await post('save_milestone', { milestone: { ...milestoneForm } });
                if (res && res.success) {
                    notify(true, milestoneForm.id ? 'Academic milestone updated.' : 'Academic milestone created.');
                    closeModal();
                    fetchAdminMilestones();
                } else {
                    Swal.fire('Save Failed', res.message || res.error || 'Failed to save milestone.', 'error');
                }
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'An unexpected error occurred.', 'error');
            } finally {
                isSavingMilestone.value = false;
            }
        };

        const deleteMilestone = async (id) => {
            const result = await Swal.fire({
                title: 'Delete Milestone?',
                text: 'Are you sure you want to remove this academic deadline?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete'
            });
            if (!result.isConfirmed) return;

            try {
                const res = await post('delete_milestone', { id });
                if (res && res.success) {
                    notify(true, 'Milestone deleted.');
                    fetchAdminMilestones();
                } else {
                    Swal.fire('Delete Failed', res.message || res.error || 'Failed to delete milestone.', 'error');
                }
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'Failed to delete milestone.', 'error');
            }
        };

        const getPeriodName = (periodId) => {
            if (!periodId) return 'All Semesters / General';
            const found = periods.value.find(p => parseInt(p.id) === parseInt(periodId));
            return found ? (found.name + (found.academic_year ? ' (A.Y. ' + found.academic_year + ')' : '')) : ('Term #' + periodId);
        };

        // ── Open modals ──
        const openAddModal = () => {
            search.value = '';
            if (view.value === 'departments_programs') { Object.assign(form, {id:null,code:'',name:'',department:selectedDeptName.value || '',status:'Active'}); modal.value='program'; }
            if (view.value === 'subjects')   { Object.assign(form, {id:null,code:'',title:'',description:'',lectureUnits:3,labUnits:0,labFee:0,department:selectedDeptName.value || '',prerequisites:'None'}); modal.value='subject'; }
            if (view.value === 'curriculum') { Object.assign(form, {id:null,program:selectedCurrProgram.value || (programs.value.length > 0 ? programs.value[0].name : ''),curriculumVersion:selectedCurrVersion.value || '2022 Curriculum',subject:subjects.value.length > 0 ? subjects.value[0].title : '',yearLevel:'1st Year',semester:'1st Semester',elective:false}); modal.value='curriculum'; }
            if (view.value === 'periods')    { Object.assign(form, {id:null,name:'',academicYear:'',semester:'1st Semester',enrollmentStart:'',enrollmentEnd:'',status:'Active'}); modal.value='period'; }
            if (view.value === 'sections')   {
                const activeP = periods.value.find(p => p && (p.status || '').toUpperCase() === 'ACTIVE');
                const defaultPid = activeP ? activeP.id : '';
                Object.assign(form, {id:null,code:'',program:programs.value.length > 0 ? programs.value[0].name : '',yearLevel:'1st Year',academicPeriodId:defaultPid,curriculumVersion:'2022 Curriculum',capacity:40,adviser:''});
                modal.value='section';
            }
            if (view.value === 'classOfferings') { Object.assign(form, {id:null,sectionId:'',program:'',yearLevel:'1st Year',semester:'1st Semester',subject:'',code:'',instructor:'TBD',days:'MWF',time:'09:00 AM - 10:30 AM',room:'Room 101',capacity:40}); modal.value='classOffering'; }
            if (view.value === 'fees')       { Object.assign(form, {id:null,type:'Tuition',label:'',amount:0,perUnit:false}); modal.value='fee'; }
            if (view.value === 'operators')  { Object.assign(form, {name:'',username:'',password:'',role:''}); modal.value='operator'; }
        };
        const editDepartment = d => { Object.assign(form, {...d}); modal.value='department'; };
        const editProgram    = p => { Object.assign(form, {...p}); modal.value='program'; };
        const editSubject    = s => { Object.assign(form, {...s}); modal.value='subject'; };
        const editCurriculum = c => { Object.assign(form, {...c}); modal.value='curriculum'; };
        const editPeriod     = p => { Object.assign(form, {...p}); modal.value='period'; };
        const editSection    = s => { Object.assign(form, {...s}); modal.value='section'; };
        const editClassOffering = c => { Object.assign(form, {...c}); modal.value='classOffering'; };
        const editFee        = f => { Object.assign(form, {...f}); modal.value='fee'; };

        // Wizard & Accelerators Modals opening
        const openAddDepartmentModal = () => {
            Object.assign(form, {id:null,code:'',name:'',status:'Active'});
            modal.value = 'department';
        };
        const openAddProgramModal = () => {
            Object.assign(form, {id:null,code:'',name:'',department:selectedDeptName.value || '',status:'Active'});
            modal.value = 'program';
        };
        const openCloneTermModal = () => {
            cloneForm.fromPeriodId = periods.value.length > 0 ? periods.value[0].id : '';
            cloneForm.newPeriodName = '';
            cloneForm.newAcademicYear = '';
            cloneForm.newSemester = '1st Semester';
            cloneForm.enrollmentStart = '';
            cloneForm.enrollmentEnd = '';
            cloneForm.cloneSections = true;
            cloneForm.cloneOfferings = true;
            modal.value = 'clone-term';
        };
        const openBulkSectionsModal = () => {
            const activeP = periods.value.find(p => p && (p.status || '').toUpperCase() === 'ACTIVE');
            const defaultPid = activeP ? activeP.id : '';
            bulkForm.program = programs.value.length > 0 ? programs.value[0].name : '';
            bulkForm.curriculumVersion = '2022 Curriculum';
            bulkForm.yearLevel = '1st Year';
            bulkForm.academicPeriodId = defaultPid;
            bulkForm.capacity = 40;
            bulkForm.adviser = '';
            bulkForm.count = 3;
            modal.value = 'bulk-sections';
        };

        const submitCloneTerm = () => {
            if (!cloneForm.fromPeriodId || !cloneForm.newPeriodName || !cloneForm.newAcademicYear) {
                notify(false, 'Please fill in all required fields.');
                return;
            }
            post('clone_term', { clone: cloneForm }).then(r => {
                if (r.success && r.data) {
                    periods.value = r.data.periods || [];
                    sections.value = r.data.sections || [];
                    classOfferings.value = r.data.classOfferings || [];
                    closeModal();
                    notify(true, r.message || 'Term cloned successfully!');
                } else {
                    notify(false, r.error || 'Failed to clone term.');
                }
            });
        };

        const submitBulkSections = () => {
            if (!bulkForm.program || !bulkForm.yearLevel || !bulkForm.academicPeriodId || bulkForm.count <= 0) {
                notify(false, 'Please fill in all required fields.');
                return;
            }
            const period = periods.value.find(p => parseInt(p.id) === parseInt(bulkForm.academicPeriodId));
            if (!period || (period.status || '').toUpperCase() !== 'ACTIVE') {
                notify(false, 'The selected academic period is Inactive. Please select an Active period.');
                return;
            }
            post('bulk_generate_sections', { bulk: bulkForm }).then(r => {
                if (r.success && r.data) {
                    sections.value = r.data.sections || r.data || [];
                    closeModal();
                    notify(true, r.message || 'Sections generated successfully!');
                } else {
                    notify(false, r.error || 'Failed to generate sections.');
                }
            });
        };
        const onBulkProgramSelect = () => {
            const matching = curriculum.value.filter(c => c.program === bulkForm.program);
            if (matching.length > 0) {
                bulkForm.curriculumVersion = matching[0].curriculumVersion;
            } else {
                bulkForm.curriculumVersion = '2022 Curriculum';
            }
        };

        // ── CRUD helpers ──
        const crudSave = (action, bodyKey, dataList, payload) =>
            post(action, {[bodyKey]: {...form, ...payload}}).then(r => {
                if (r.success) {
                    dataList.value = r.data;
                    closeModal();
                    notify(true, 'Saved successfully.');
                    if (window.DataCache) {
                        window.DataCache.invalidate('admin:academic_data');
                        window.DataCache.invalidate('admin:dashboard_stats');
                    }
                }
                else notify(false, r.error || 'Save failed.');
            });
        const crudDel = async (action, id, dataList, label) => {
            const result = await Swal.fire({
                title: 'Are you sure?',
                text: `Delete this ${label}? This cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#006A4E',
                confirmButtonText: 'Yes, delete it!'
            });
            if (!result.isConfirmed) return;
            post(action, {id}).then(r => {
                if (r.success) {
                    dataList.value = r.data;
                    notify(true, `${label} deleted.`);
                    if (window.DataCache) {
                        window.DataCache.invalidate('admin:academic_data');
                        window.DataCache.invalidate('admin:dashboard_stats');
                    }
                }
                else Swal.fire({ title: 'Cannot Delete', html: r.error || 'Delete failed.', icon: 'error', confirmButtonColor: '#006A4E' });
            });
        };

        const saveDepartment = () => {
            Swal.fire({ icon: 'warning', title: 'Action Locked', text: 'Collegiate departments are fixed and cannot be modified.' });
        };
        const deleteDepartment = () => {
            Swal.fire({ icon: 'warning', title: 'Action Locked', text: 'Collegiate departments are fixed and cannot be deleted.' });
        };
        const saveProgram    = () => crudSave('save_program',   'program',    programs,   {});
        const deleteProgram = async (id) => {
            const prog = programs.value.find(p => p.id === id);
            if (!prog) return;
            const currCount = curriculum.value.filter(c => c.program === prog.name).length;
            const secCount = sections.value.filter(s => s.program === prog.name).length;
            const offCount = classOfferings.value.filter(o => o.program === prog.name).length;
            let warningHtml = `<p style="margin-bottom:8px">You are about to delete <strong>${prog.name}</strong> (${prog.code}).</p>`;
            if (currCount > 0 || secCount > 0 || offCount > 0) {
                warningHtml += `<div style="text-align:left;background:#fff3cd;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:.85rem">`;
                warningHtml += `<strong style="color:#856404"><i class="fa-solid fa-triangle-exclamation"></i> The following will also be deleted:</strong><ul style="margin:6px 0 0 16px;padding:0">`;
                if (currCount > 0) warningHtml += `<li>${currCount} curriculum mapping(s)</li>`;
                if (offCount > 0) warningHtml += `<li>${offCount} class offering(s)</li>`;
                if (secCount > 0) warningHtml += `<li>${secCount} section cohort(s)</li>`;
                warningHtml += `</ul></div>`;
            }
            const result = await Swal.fire({
                title: 'Delete Program?',
                html: warningHtml,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#006A4E',
                confirmButtonText: 'Yes, delete everything'
            });
            if (!result.isConfirmed) return;
            post('delete_program', {id}).then(r => {
                if (r.success) {
                    programs.value = r.data;
                    // Refresh dependent lists since cascading deletions occurred
                    get('fetch_academic_data').then(rd => {
                        if (rd.success && rd.data) {
                            curriculum.value = rd.data.curriculum || [];
                            sections.value = rd.data.sections || [];
                            classOfferings.value = rd.data.classOfferings || [];
                        }
                    });
                    notify(true, `Program "${prog.name}" and all dependent data deleted.`);
                } else {
                    Swal.fire({ title: 'Cannot Delete', html: r.error || 'Delete failed.', icon: 'error', confirmButtonColor: '#006A4E' });
                }
            });
        };
        const saveSubject    = () => crudSave('save_subject',   'subject',    subjects,   {});
        const deleteSubject  = id => crudDel('delete_subject',  id,           subjects,   'subject');
        const saveCurriculum = () => crudSave('save_curriculum','curriculum', curriculum, {});
        const deleteCurriculum=id => crudDel('delete_curriculum',id,          curriculum, 'entry');

        const openAddCurriculumForSemester = (yearLevel, semester) => {
            Object.assign(form, {
                id: null,
                program: selectedCurrProgram.value,
                curriculumVersion: selectedCurrVersion.value || '2022 Curriculum',
                subject: subjects.value.length > 0 ? subjects.value[0].title : '',
                yearLevel: yearLevel || '1st Year',
                semester: semester || '1st Semester',
                elective: false
            });
            modal.value = 'curriculum';
        };

        const openCloneCurriculumModal = () => {
            cloneCurrForm.program = selectedCurrProgram.value;
            cloneCurrForm.fromVersion = selectedCurrVersion.value || '2022 Curriculum';
            cloneCurrForm.toVersion = '';
            modal.value = 'clone-curriculum';
        };

        const submitCloneCurriculum = async () => {
            if (!cloneCurrForm.program || !cloneCurrForm.fromVersion || !cloneCurrForm.toVersion) {
                Swal.fire('Incomplete Form', 'Please specify program, source version, and target version.', 'warning');
                return;
            }
            if (cloneCurrForm.fromVersion.trim().toLowerCase() === cloneCurrForm.toVersion.trim().toLowerCase()) {
                Swal.fire('Invalid Name', 'Target curriculum version cannot have the same name as source version.', 'warning');
                return;
            }
            try {
                const res = await post('clone_curriculum_version', cloneCurrForm);
                if (res && res.success) {
                    curriculum.value = res.data;
                    selectedCurrVersion.value = cloneCurrForm.toVersion.trim();
                    closeModal();
                    Swal.fire({
                        icon: 'success',
                        title: 'Curriculum Cloned!',
                        text: res.message || res.error || `Successfully created ${cloneCurrForm.toVersion} based on ${cloneCurrForm.fromVersion}.`,
                        confirmButtonColor: '#006A4E'
                    });
                } else {
                    Swal.fire('Clone Failed', res.message || res.error || 'Could not clone curriculum version.', 'error');
                }
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'An error occurred while cloning curriculum.', 'error');
            }
        };

        const deleteCurrentCurriculumVersion = async () => {
            const prog = selectedCurrProgram.value;
            const ver = selectedCurrVersion.value;
            if (!prog || !ver) return;
            const count = curriculum.value.filter(c => c.program === prog && c.curriculumVersion === ver).length;
            const result = await Swal.fire({
                title: `Delete ${ver}?`,
                html: `<p>Are you sure you want to delete <strong>${ver}</strong> for <strong>${prog}</strong>?</p><p class="text-danger small">This will delete all <strong>${count}</strong> subject mapping(s) in this version.</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete version'
            });
            if (!result.isConfirmed) return;

            try {
                const res = await post('delete_curriculum_version', { program: prog, version: ver });
                if (res && res.success) {
                    curriculum.value = res.data;
                    selectedCurrVersion.value = curriculumVersionsForProgram.value[0] || '2022 Curriculum';
                    Swal.fire({ icon: 'success', title: 'Version Deleted', text: res.message || res.error || 'Version deleted successfully.', confirmButtonColor: '#006A4E' });
                } else {
                    Swal.fire('Delete Failed', res.message || res.error, 'error');
                }
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'Failed to delete curriculum version.', 'error');
            }
        };

        const quickToggleElective = (currEntry) => {
            const updated = { ...currEntry, elective: !currEntry.elective };
            post('save_curriculum', { curriculum: updated }).then(res => {
                if (res && res.success) {
                    curriculum.value = res.data;
                    notify(true, `Subject marked as ${updated.elective ? 'Elective' : 'Core'}.`);
                }
            });
        };
        const savePeriod     = () => crudSave('save_academic_period','period',periods,    {});
        const deletePeriod = async (id) => {
            const period = periods.value.find(p => p.id === id);
            if (!period) return;
            const linkedSections = sections.value.filter(s => s.academicPeriodId === id);
            const linkedOfferings = classOfferings.value.filter(o => linkedSections.some(s => s.id === o.sectionId));
            let warningHtml = `<p style="margin-bottom:8px">Delete academic period <strong>${period.name}</strong>?</p>`;
            if (linkedSections.length > 0 || linkedOfferings.length > 0) {
                warningHtml += `<div style="text-align:left;background:#f8d7da;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:.85rem">`;
                warningHtml += `<strong style="color:#721c24"><i class="fa-solid fa-shield-halved"></i> This period has active linked records:</strong><ul style="margin:6px 0 0 16px;padding:0">`;
                if (linkedSections.length > 0) warningHtml += `<li>${linkedSections.length} section cohort(s)</li>`;
                if (linkedOfferings.length > 0) warningHtml += `<li>${linkedOfferings.length} class offering(s)</li>`;
                warningHtml += `</ul><p style="margin-top:6px;color:#721c24;font-weight:600">You must remove these first before deleting the period.</p></div>`;
            }
            const result = await Swal.fire({
                title: 'Delete Period?',
                html: warningHtml,
                icon: linkedSections.length > 0 ? 'error' : 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#006A4E',
                confirmButtonText: linkedSections.length > 0 ? 'Try Anyway' : 'Yes, delete it'
            });
            if (!result.isConfirmed) return;
            post('delete_academic_period', {id}).then(r => {
                if (r.success) { periods.value = r.data; notify(true, 'Period deleted.'); }
                else Swal.fire({ title: 'Cannot Delete', html: r.error || 'Delete failed.', icon: 'error', confirmButtonColor: '#006A4E' });
            });
        };
        const saveSection = () => {
            if (!form.program || !form.yearLevel || !form.code) {
                notify(false, 'Please fill in Program, Year Level, and Section Code.');
                return;
            }
            if (!form.academicPeriodId) {
                notify(false, 'Please select an Active academic period.');
                return;
            }
            const period = periods.value.find(p => parseInt(p.id) === parseInt(form.academicPeriodId));
            if (!period || (period.status || '').toUpperCase() !== 'ACTIVE') {
                notify(false, 'The selected academic period is Inactive. Please select an Active period.');
                return;
            }
            crudSave('save_section', 'section', sections, {});
        };
        const deleteSection = async (id) => {
            const sec = sections.value.find(s => s.id === id);
            if (!sec) return;
            const linkedOfferings = classOfferings.value.filter(o => o.sectionId === id);
            let warningHtml = `<p style="margin-bottom:8px">Delete section <strong>${sec.code}</strong> (${sec.program} — ${sec.yearLevel})?</p>`;
            if (linkedOfferings.length > 0) {
                warningHtml += `<div style="text-align:left;background:#f8d7da;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:.85rem">`;
                warningHtml += `<strong style="color:#721c24"><i class="fa-solid fa-shield-halved"></i> This section has active linked records:</strong><ul style="margin:6px 0 0 16px;padding:0">`;
                if (linkedOfferings.length > 0) warningHtml += `<li>${linkedOfferings.length} class offering(s) scheduled</li>`;
                warningHtml += `</ul><p style="margin-top:6px;color:#721c24;font-weight:600">You must remove these first before deleting the section.</p></div>`;
            }
            const result = await Swal.fire({
                title: 'Delete Section?',
                html: warningHtml,
                icon: linkedOfferings.length > 0 ? 'error' : 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#006A4E',
                confirmButtonText: linkedOfferings.length > 0 ? 'Try Anyway' : 'Yes, delete it'
            });
            if (!result.isConfirmed) return;
            post('delete_section', {id}).then(r => {
                if (r.success) { sections.value = r.data; notify(true, 'Section deleted.'); }
                else Swal.fire({ title: 'Cannot Delete', html: r.error || 'Delete failed.', icon: 'error', confirmButtonColor: '#006A4E' });
            });
        };
        const saveClassOffering = () => crudSave('save_subject_section', 'section', classOfferings, {});
        const deleteClassOffering = id => crudDel('delete_subject_section', id, classOfferings, 'class offering');
        const openBlockSectionModal = () => {
            Object.assign(form, {
                program: '',
                yearLevel: '1st Year',
                semester: '1st Semester',
                sectionSuffix: '',
                capacity: 40,
                instructor: 'TBD',
                days: 'MWF',
                time: '09:00 AM - 10:30 AM',
                room: 'Room 101'
            });
            modal.value = 'block-section';
        };
        const saveBlockSection = () => {
            if (!form.program || !form.yearLevel || !form.semester || !form.sectionSuffix) {
                notify(false, 'Please fill in all required fields.');
                return;
            }
            post('save_block_section', { block: form }).then(r => {
                if (r.success) {
                    classOfferings.value = r.data;
                    closeModal();
                    notify(true, r.message || 'Block section generated successfully.');
                } else {
                    notify(false, r.error || 'Failed to generate block section.');
                }
            });
        };
        const saveFee        = () => crudSave('save_fee',       'fee',        fees,       {});
        const deleteFee      = id => crudDel('delete_fee',      id,           fees,       'fee');

        // ── Operator Management (Isolated Methods & Tracing) ──
        const fetchOperators = (forceRefresh = false) => {
            console.log('[Trace: Operators] Fetching operators list...');
            const hasUsers = users.value && users.value.length > 0;
            if (!hasUsers && !(window.DataCache && window.DataCache.has('admin:users'))) {
                isLoadingOperators.value = true;
            }
            const fetchFn = () => get('fetch_users');
            if (window.DataCache) {
                return window.DataCache.fetchWithCache('admin:users', fetchFn, {
                    staleTime: 30000,
                    forceRefresh,
                    onBackgroundUpdate: (r) => {
                        if (r && r.success) users.value = r.data || [];
                    }
                }).then(r => {
                    if (r && r.success) {
                        users.value = r.data || [];
                        console.log(`[Trace: Operators] Loaded ${users.value.length} operators:`, users.value);
                    }
                }).catch(err => {
                    console.error('[Trace: Operators] Failed to fetch operators:', err);
                }).finally(() => {
                    isLoadingOperators.value = false;
                });
            }
            return fetchFn().then(r => {
                if (r && r.success) {
                    users.value = r.data || [];
                    console.log(`[Trace: Operators] Loaded ${users.value.length} operators:`, users.value);
                } else {
                    console.error('[Trace: Operators] Failed to fetch operators:', r);
                }
            }).finally(() => {
                isLoadingOperators.value = false;
            });
        };

        const openCreateOperatorModal = () => {
            console.log('[Trace: Operators] Triggered Create Operator Modal');
            search.value = '';
            Object.assign(operatorForm, { id: null, name: '', email: '', username: '', password: '', role: '' });
            isOperatorModalOpen.value = true;
            console.log('[Trace: Operators] isOperatorModalOpen set to true');
        };

        const closeCreateOperatorModal = () => {
            if (isSubmittingOperator.value) return;
            console.log('[Trace: Operators] Closing Create Operator Modal');
            isOperatorModalOpen.value = false;
        };

        const submitCreateOperator = () => {
            console.log('[Trace: Operators] Submitting new operator:', operatorForm);
            if (!operatorForm.name || !operatorForm.username || !operatorForm.role) {
                Swal.fire({
                    title: 'Missing Information',
                    text: 'Full name, username, and assigned station role are required.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }

            const payloadName = operatorForm.name;
            const payloadUsername = operatorForm.username;
            const payloadRole = operatorForm.role;

            isSubmittingOperator.value = true;

            post('save_user', { user: { ...operatorForm } }).then(r => {
                isSubmittingOperator.value = false;
                if (r.success) {
                    console.log('[Trace: Operators] Operator account created successfully:', r);
                    closeCreateOperatorModal();
                    if (window.DataCache) window.DataCache.invalidate('admin:users');
                    fetchOperators(true);

                    const pass = (r.data && r.data.tempPassword) ? r.data.tempPassword : (operatorForm.password || '(As specified)');
                    const emailSent = r.data && r.data.emailSent;
                    const emailMsg = r.data && r.data.emailMessage ? r.data.emailMessage : '';

                    Swal.fire({
                        title: 'Operator Created Successfully!',
                        html: `
                            <div style="text-align: left; padding: 14px 18px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 10px;">
                                <div style="margin-bottom: 8px;"><strong>Operator Name:</strong> ${payloadName}</div>
                                <div style="margin-bottom: 8px;"><strong>Username:</strong> <code style="color: #006A4E; font-weight: 700; font-size: 1rem;">${payloadUsername}</code></div>
                                <div style="margin-bottom: 8px;"><strong>Station Role:</strong> <span class="badge badge-role">${payloadRole}</span></div>
                                <div style="margin-bottom: 12px;"><strong>Temporary Password:</strong><br><code style="font-size: 1.2rem; color: #006A4E; background: #e6f4ed; padding: 6px 14px; border-radius: 6px; display: inline-block; margin-top: 4px; font-weight: bold;">${pass}</code></div>
                                <div style="font-size: 0.85rem; padding: 10px 14px; border-radius: 6px; background: ${emailSent ? '#d1fae5' : '#fef3c7'}; color: ${emailSent ? '#065f46' : '#92400e'}; border: 1px solid ${emailSent ? '#a7f3d0' : '#fde68a'};">
                                    <i class="fa-solid ${emailSent ? 'fa-envelope-circle-check' : 'fa-info-circle'}" style="margin-right: 6px;"></i>
                                    ${emailSent ? 'Account credentials have been emailed to the operator.' : (emailMsg || 'No email specified or running in local mode.')}
                                </div>
                            </div>
                        `,
                        icon: 'success',
                        confirmButtonColor: '#006A4E',
                        confirmButtonText: 'Done & Return'
                    });
                } else {
                    console.error('[Trace: Operators] Create operator failed:', r);
                    Swal.fire({
                        title: 'Account Creation Failed',
                        html: `<div style="color:#dc2626;font-weight:600">${r.message || r.error || 'Failed to create operator account.'}</div>`,
                        icon: 'error',
                        confirmButtonColor: '#006A4E'
                    });
                }
            }).catch(err => {
                isSubmittingOperator.value = false;
                console.error('[Trace: Operators] Network error creating operator:', err);
                Swal.fire({
                    title: 'Server Error',
                    text: 'An error occurred while connecting to the server.',
                    icon: 'error',
                    confirmButtonColor: '#006A4E'
                });
            });
        };

        const openEditOperatorModal = (u) => {
            console.log('[Trace: Operators] Triggered Edit Operator Modal for user:', u);
            Object.assign(operatorForm, { id: u.id, name: u.name, username: u.username, email: u.email || '', role: u.role, password: '' });
            isEditOperatorModalOpen.value = true;
        };

        const closeEditOperatorModal = () => {
            if (isSubmittingOperator.value) return;
            console.log('[Trace: Operators] Closing Edit Operator Modal');
            isEditOperatorModalOpen.value = false;
        };

        const submitEditOperator = () => {
            console.log('[Trace: Operators] Submitting operator update:', operatorForm);
            if (!operatorForm.name || !operatorForm.role) {
                Swal.fire({
                    title: 'Missing Required Fields',
                    text: 'Full Name and Station Role are required.',
                    icon: 'warning',
                    confirmButtonColor: '#006A4E'
                });
                return;
            }

            const targetName = operatorForm.name;
            isSubmittingOperator.value = true;

            post('update_operator', {
                userId: operatorForm.id,
                name: operatorForm.name,
                email: operatorForm.email,
                role: operatorForm.role
            }).then(r => {
                isSubmittingOperator.value = false;
                if (r.success) {
                    console.log('[Trace: Operators] Operator updated successfully:', r);
                    closeEditOperatorModal();
                    if (window.DataCache) window.DataCache.invalidate('admin:users');
                    fetchOperators(true);
                    Swal.fire({
                        title: 'Operator Updated!',
                        text: `Account details for ${targetName} have been updated successfully.`,
                        icon: 'success',
                        confirmButtonColor: '#006A4E'
                    });
                } else {
                    console.error('[Trace: Operators] Update operator failed:', r);
                    Swal.fire({
                        title: 'Update Failed',
                        text: r.message || r.error || 'Failed to update operator account.',
                        icon: 'error',
                        confirmButtonColor: '#006A4E'
                    });
                }
            }).catch(err => {
                isSubmittingOperator.value = false;
                Swal.fire({
                    title: 'Server Error',
                    text: 'An error occurred while connecting to the server.',
                    icon: 'error',
                    confirmButtonColor: '#006A4E'
                });
            });
        };

        const resetOperatorPassword = async (u) => {
            console.log('[Trace: Operators] Initiating password reset for user:', u);
            const { value: formValues } = await Swal.fire({
                title: 'Reset Operator Password',
                html: `
                    <div style="text-align: left; font-size: 0.92rem;">
                        <p style="margin-bottom: 14px; color: #475569;">
                            Reset workstation password for <strong>${u.name}</strong> (<code>${u.username}</code> &bull; ${u.role}).
                        </p>
                        <div style="margin-bottom: 12px;">
                            <label style="display:block; font-weight: 700; margin-bottom: 4px; color: #1e293b; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">Employee Email Address</label>
                            <input id="swal-reset-email" type="email" class="swal2-input" style="margin: 0; width: 100%; box-sizing: border-box; font-size: 0.95rem;" placeholder="employee@gncp.edu.ph" value="${u.email || ''}">
                            <small style="color: #64748b; font-size: 0.78rem;">The temporary password will be dispatched to this email.</small>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <label style="display:block; font-weight: 700; margin-bottom: 4px; color: #1e293b; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">Custom Temporary Password (Optional)</label>
                            <input id="swal-reset-pass" type="text" class="swal2-input" style="margin: 0; width: 100%; box-sizing: border-box; font-size: 0.95rem;" placeholder="Leave blank to auto-generate (e.g. Gncp#4821!)">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#006A4E',
                cancelButtonColor: '#64748b',
                confirmButtonText: '<i class="fa-solid fa-paper-plane me-1"></i> Reset & Send Email',
                focusConfirm: false,
                preConfirm: () => {
                    const email = document.getElementById('swal-reset-email').value.trim();
                    const pass = document.getElementById('swal-reset-pass').value.trim();
                    if (email && !email.includes('@')) {
                        Swal.showValidationMessage('Please provide a valid email address.');
                        return false;
                    }
                    return { email, pass };
                }
            });

            if (!formValues) return;

            // Show sending progress indicator
            Swal.fire({
                title: 'Resetting Password...',
                html: 'Generating secure temporary password and dispatching notification email via Gmail SMTP.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            post('reset_operator_password', { 
                userId: u.id, 
                newPassword: formValues.pass,
                email: formValues.email
            }).then(r => {
                if (r.success) {
                    console.log('[Trace: Operators] Password reset successful:', r);
                    fetchOperators();
                    const emailNotice = r.data.emailSent 
                        ? `<div style="margin-top: 14px; padding: 12px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; color: #065f46; font-size: 0.88rem; text-align: left;">
                             <i class="fa-solid fa-circle-check me-2" style="color: #059669;"></i>
                             Temporary password delivered to <strong>${r.data.recipientEmail}</strong> via Gmail SMTP.
                           </div>`
                        : `<div style="margin-top: 14px; padding: 12px 14px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; color: #92400e; font-size: 0.88rem; text-align: left;">
                             <i class="fa-solid fa-triangle-exclamation me-2" style="color: #d97706;"></i>
                             ${r.data.emailMessage || 'Notice: Email delivery was not completed.'}
                           </div>`;

                    Swal.fire({
                        title: 'Password Reset Complete!',
                        html: `
                            <p style="color: #475569; margin-bottom: 12px;">Workstation account <strong>${u.username}</strong> has been updated.</p>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 8px;">
                                <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 4px;">Temporary Password</div>
                                <code style="font-size: 1.35rem; color: #006A4E; font-weight: 800; letter-spacing: 2px; user-select: all; -webkit-user-select: all;">${r.data.tempPassword}</code>
                            </div>
                            <small style="color: #64748b;">The operator will be required to change their password upon their next login.</small>
                            ${emailNotice}
                        `,
                        icon: 'success',
                        confirmButtonColor: '#006A4E'
                    });
                } else {
                    console.error('[Trace: Operators] Password reset failed:', r);
                    Swal.fire({
                        title: 'Reset Failed',
                        text: r.message || r.error || 'Failed to reset operator password.',
                        icon: 'error',
                        confirmButtonColor: '#006A4E'
                    });
                }
            });
        };

        const updateStatus = (userId, status) => {
            console.log(`[Trace: Operators] Updating status for user ID ${userId} -> ${status}`);
            return post('update_user_status', { userId, status }).then(r => {
                if (r.success) {
                    if (window.DataCache) window.DataCache.invalidate('admin:users');
                    fetchOperators(true);
                }
                else notify(false, r.error || 'Failed to update status.');
            });
        };

        const deleteUser = async userId => {
            console.log(`[Trace: Operators] Requesting deletion for user ID ${userId}`);
            const result = await Swal.fire({
                title: 'Are you sure?',
                text: 'Delete this operator account?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#006A4E',
                confirmButtonText: 'Yes, delete it!'
            });
            if (!result.isConfirmed) return;
            post('delete_user', { userId }).then(r => {
                if (r.success) {
                    console.log(`[Trace: Operators] User ID ${userId} deleted`);
                    if (window.DataCache) window.DataCache.invalidate('admin:users');
                    fetchOperators(true);
                } else {
                    console.error('[Trace: Operators] Delete user failed:', r);
                    notify(false, r.error || 'Failed to delete.');
                }
            });
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
        const onAvatarError = () => { avatarFailed.value = true; };

        const initials = computed(() => {
            const name = user.value.name || (currentAdmin.value ? currentAdmin.value.name : 'Super Admin');
            const parts = name.trim().split(' ').filter(Boolean);
            if (parts.length > 1) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }
            return (parts[0] ? parts[0][0] : 'SA').toUpperCase();
        });

        const formattedAvatar = computed(() => {
            if (avatarFailed.value) return null;
            const avatar = user.value.avatar || (currentAdmin.value ? currentAdmin.value.avatar : null);
            if (!avatar) return null;
            if (avatar.startsWith('http://') || avatar.startsWith('https://') || avatar.startsWith('data:')) return avatar;
            if (avatar.startsWith('../')) return avatar;
            if (avatar.startsWith('uploads/')) return '../' + avatar;
            const filename = avatar.split('/').pop();
            return '../uploads/avatars/' + filename;
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
            avatarFailed.value = false;
            if (currentAdmin.value) {
                user.value.name = currentAdmin.value.name || '';
                user.value.email = currentAdmin.value.email || '';
                user.value.username = currentAdmin.value.username || 'admin';
                user.value.role = currentAdmin.value.role || 'SUPER_ADMIN';
                user.value.avatar = currentAdmin.value.avatar || null;
            }
            try {
                const username = user.value.username || (currentAdmin.value ? currentAdmin.value.username : 'admin');
                const res = await fetch('../api/index.php?action=auth/profile&username=' + encodeURIComponent(username));
                const data = await res.json();
                if (data.success && data.data) {
                    user.value = { ...user.value, ...data.data };
                    if (data.data.avatar) {
                        user.value.avatar = data.data.avatar;
                        if (currentAdmin.value) currentAdmin.value.avatar = data.data.avatar;
                    }
                    if (data.data.name && currentAdmin.value) currentAdmin.value.name = data.data.name;
                    if (data.data.email && currentAdmin.value) currentAdmin.value.email = data.data.email;
                }
            } catch (e) { console.error('[Profile] Staff fetch failed:', e); }
        };

        const onFileSelected = async (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                Swal.fire('Invalid File', 'Please select a valid image file (JPG, PNG, WebP).', 'warning');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire('File Too Large', 'Please select an image smaller than 5MB.', 'warning');
                return;
            }
            const reader = new FileReader();
            reader.onload = async (ev) => {
                const b64 = ev.target.result;
                try {
                    const res = await fetch('../api/index.php?action=auth/upload_avatar', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ username: user.value.username || 'admin', photoData: b64 })
                    });
                    const data = await res.json();
                    if (data.success && data.data) {
                        const newFilename = data.data.avatar || data.data.photo;
                        user.value.avatar = newFilename;
                        avatarFailed.value = false;
                        if (currentAdmin.value) currentAdmin.value.avatar = newFilename;
                        const raw = sessionStorage.getItem('gncp_admin_user');
                        if (raw) {
                            const p = JSON.parse(raw);
                            p.avatar = newFilename;
                            sessionStorage.setItem('gncp_admin_user', JSON.stringify(p));
                        }
                        notify(true, 'Profile picture updated successfully.');
                    } else {
                        Swal.fire('Upload Failed', data.message || 'Unable to update profile picture.', 'error');
                    }
                } catch (err) {
                    Swal.fire('Error', 'Unable to process image upload.', 'error');
                }
            };
            reader.readAsDataURL(file);
        };

        const saveStaffProfile = async () => {
            if (!user.value.name || !user.value.email) {
                Swal.fire('Validation Error', 'Full Name and Email Address are required.', 'warning');
                return;
            }
            saving.value = true;
            try {
                const avatarFilename = user.value.avatar ? user.value.avatar.split('/').pop() : null;
                const res = await fetch('../api/index.php?action=auth/update_profile', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: user.value.username || 'admin',
                        name: user.value.name,
                        email: user.value.email,
                        avatar: avatarFilename
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (currentAdmin.value) {
                        currentAdmin.value.name = user.value.name;
                        currentAdmin.value.email = user.value.email;
                        currentAdmin.value.avatar = avatarFilename;
                    }
                    const raw = sessionStorage.getItem('gncp_admin_user');
                    if (raw) {
                        const p = JSON.parse(raw);
                        p.name = user.value.name;
                        p.email = user.value.email;
                        p.avatar = avatarFilename;
                        sessionStorage.setItem('gncp_admin_user', JSON.stringify(p));
                    }
                    notify(true, 'Personal details updated successfully.');
                } else {
                    Swal.fire('Update Failed', data.message || 'Unable to update profile.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', 'Server error while saving profile.', 'error');
            } finally {
                saving.value = false;
            }
        };

        const updatePassword = async () => {
            if (!pass.value.current) {
                Swal.fire('Current Password Required', 'Please enter your current password.', 'warning');
                return;
            }
            if (pass.value.newPass !== pass.value.confirm) {
                Swal.fire('Password Mismatch', 'New password and confirm password do not match.', 'warning');
                return;
            }
            if (pass.value.newPass.length < 6) {
                Swal.fire('Weak Password', 'New password must be at least 6 characters.', 'warning');
                return;
            }
            updatingPass.value = true;
            try {
                const res = await fetch('../api/index.php?action=auth/change_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: user.value.username || 'admin',
                        current_password: pass.value.current,
                        new_password: pass.value.newPass
                    })
                });
                const data = await res.json();
                if (data.success) {
                    pass.value = { current: '', newPass: '', confirm: '' };
                    passStrengthLevel.value = 0;
                    notify(true, 'Password changed successfully.');
                } else {
                    Swal.fire('Password Error', data.message || 'Unable to update password.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', 'Server connection error while changing password.', 'error');
            } finally {
                updatingPass.value = false;
            }
        };

        watch(view, (newV) => {
            if (newV === 'profile') {
                loadProfile();
            }
        });

        return {
            currentAdmin, isLoggingIn, loginError, loginForm, showOperatorPassword,
            view, search, modal, form, operatorForm, isOperatorModalOpen, isEditOperatorModalOpen, isSubmittingOperator,
            successMsg, errorMsg,
            departments, programs, subjects, curriculum, periods, sections, classOfferings, fees, users, students,
            eyebrow, viewTitle, addLabel, searchPlaceholder,
            filteredDepartments, filteredPrograms, filteredSubjects, filteredCurriculum,
            activePeriods,
            filteredPeriods, filteredSections, filteredClassOfferings, filteredFees, filteredUsers, filteredSubjectsForSection,
            filteredProgramsList, filteredStudents, filteredAccounts, uniqueCurriculumVersionsForBulk,
            showLogoutConfirm, handleLogout, confirmLogout, setView, openAddModal, closeModal,
            editDepartment, editProgram, editSubject, editCurriculum, editPeriod, editSection, editClassOffering, editFee,
            saveDepartment, deleteDepartment, saveProgram, deleteProgram, saveSubject, deleteSubject,
            saveCurriculum, deleteCurriculum, savePeriod, deletePeriod,
            saveSection, deleteSection, saveClassOffering, deleteClassOffering, saveFee, deleteFee,
            openBlockSectionModal, saveBlockSection,
            openCreateOperatorModal, closeCreateOperatorModal, submitCreateOperator,
            openEditOperatorModal, closeEditOperatorModal, submitEditOperator,
            resetOperatorPassword, updateStatus, deleteUser,
            dashboardStats, isLoadingStats, isLoadingAcademicData, isLoadingOperators, isLoadingAnnouncements, isLoadingMilestones, loadDashboard, loadAll,
            // Sort & Filter
            sortKey, sortDir, sortBy,
            filterUserStatus,
            filterProgramDept, filterProgramStatus,
            filterSubjectDept, filterSubjectLab,
            filterCurrProgram, filterCurrYear, filterCurrSem,
            filterPeriodSem, filterPeriodStatus,
            filterSectPeriod, filterSectProgram, filterSectYear, filterSectSem,
            filterSectDays,
            // Views & Collapsibles
            currView, sectView, collapsedGroups,
            selectedCurrDept, selectedCurrProgram, selectedCurrVersion, cloneCurrForm,
            curriculumDepartments, curriculumProgramsForDept, activeCurrProgramObj, curriculumVersionsForProgram,
            curriculumMatrix, prospectusStats,
            openAddCurriculumForSemester, openCloneCurriculumModal, submitCloneCurriculum, deleteCurrentCurriculumVersion, quickToggleElective,
            uniqueProgramDepts, uniqueSubjectDepts, uniqueCurriculumVersions, programStats,
            curriculumGrouped, toggleCurrGroup,
            getPeriodName, getSectionsForPeriod, getClassOfferingsForSection, getSectionCohortCode, onSectionSelect, onSubjectSelect,
            
            // New state & helpers
            expandedCats, selectedDeptName, filterStudentProgram, filterStudentYear, filterStudentStatus,
            filterAccountProgram, filterAccountYear, filterAccountStatus,
            cloneForm, bulkForm,
            openAddDepartmentModal, openAddProgramModal, openCloneTermModal, openBulkSectionsModal,
            submitCloneTerm, submitBulkSections, onBulkProgramSelect,
            timeGreeting,
            // Announcements (Bulletin Board & Google Docs Editor)
            announcements, announcementForm, isSavingAnnouncement, uploadImgPreview,
            openAnnouncementModal, closeAnnouncementModal, handleAnnouncementImageSelect, removeAnnouncementImage,
            saveAnnouncement, deleteAnnouncement, togglePinAnnouncement, fetchAdminAnnouncements,
            editorWordCount, editorCharCount, formatDoc, applyFormatBlock, applyTextColor,
            applyHiliteColor, insertLink, syncEditorContent, setImagePreset,
            // Academic Milestones Management
            milestones, milestoneForm, isSavingMilestone, openMilestoneModal, updateMilestoneDisplayDate,
            saveMilestone, deleteMilestone, fetchAdminMilestones,
            isMobileMenuOpen,
            // Multi-Dimensional Analytics State & Print Helpers
            analyticsFilters, isFiltered, activeFilterSummary, printDateFormatted,
            applyAnalyticsFilters, resetAnalyticsFilters, printAnalyticsReport,
            yearLevelBreakdown, departmentBreakdown, financialMetrics, detailedProgramStats,
            // Sleek Course & Timeline Spline Chart
            chartViewMode, hoveredChartPoint, hoveredTimelinePoint, setHoveredPoint,
            getTooltipStyle, courseAnalytics, graphMetrics, timelineData, timelineGraphMetrics,
            topProgramSummary, totalRegistrationsCount, totalEnrolledCount, totalEnrolledRate, pipelineFunnel,
            // Profile & Security
            user, pass, saving, updatingPass, showCurrentPass, showNewPass, fileInput,
            initials, formattedAvatar, avatarFailed, onAvatarError,
            passStrengthLevel, passStrengthLabel, passStrengthColor, passStrengthWidth,
            checkPassStrength, triggerFileInput, onFileSelected, saveStaffProfile, updatePassword, loadProfile
        };
    }
});
window.app = app.mount('#admin-app');
