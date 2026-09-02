/**
 * GNCP Registrar Portal — RegistrarView Module
 *
 * View layer containing presentation-only Vue components.
 * Emits events to the Controller for data actions and state changes.
 */
(function (global) {

    /* ─────────────────────────────────────────────────────────────────
       SidebarNav (Delegated to Unified EmployeeSidebar Component)
       ───────────────────────────────────────────────────────────────── */
    const SidebarNav = (typeof window !== 'undefined' && window.EmployeeSidebar) ? window.EmployeeSidebar : {};

    /* ─────────────────────────────────────────────────────────────────
       TopBar
       ───────────────────────────────────────────────────────────────── */
    const TopBar = {
        props: ['topBarEyebrow', 'topBarTitle', 'currentView', 'searchText', 'searchPlaceholder', 'isAdmin'],
        emits: [
            'update:searchText',
            'open-program-modal',
            'open-subject-modal',
            'open-curriculum-modal',
            'open-period-modal',
            'open-section-modal',
            'open-fee-modal'
        ],
        computed: {
            hasSearch() {
                return !['reports', 'enrollment-overview'].includes(this.currentView);
            }
        },
        template: `
            <header class="topbar">
                <div class="topbar-left">
                    <span class="eyebrow">{{ topBarEyebrow }}</span>
                    <span style="color:rgba(0,0,0,.2);font-size:.8rem">/</span>
                    <span class="topbar-title">{{ topBarTitle }}</span>
                </div>
                <div class="topbar-right">
                    <input v-if="hasSearch"
                           class="search-box" type="text"
                           :value="searchText"
                           @input="$emit('update:searchText', $event.target.value)"
                           :placeholder="searchPlaceholder">
                    
                    <!-- Action buttons based on current catalog view -->
                    <button v-if="isAdmin && currentView === 'subjects'" @click="$emit('open-subject-modal', 'add')" class="btn-add"><i class="fa-solid fa-plus me-1"></i> Add Subject</button>
                    <button v-if="isAdmin && currentView === 'curriculum'" @click="$emit('open-curriculum-modal', 'add')" class="btn-add"><i class="fa-solid fa-plus me-1"></i> Map Subject</button>
                    <button v-if="isAdmin && currentView === 'academic-periods'" @click="$emit('open-period-modal', 'add')" class="btn-add"><i class="fa-solid fa-plus me-1"></i> Add Period</button>
                    <button v-if="isAdmin && currentView === 'subject-sections'" @click="$emit('open-section-modal', 'add')" class="btn-add"><i class="fa-solid fa-plus me-1"></i> Add Section</button>
                    <button v-if="isAdmin && currentView === 'fee-schedule'" @click="$emit('open-fee-modal', 'add')" class="btn-add"><i class="fa-solid fa-plus me-1"></i> Add Fee</button>
                </div>
            </header>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
                                    <td><strong class="text-dark">₱{{ f.amount.toLocaleString() }}</strong></td>
                                    <td>
                                        <span v-if="f.perUnit" class="badge bg-warning-subtle text-warning-emphasis">Per Unit</span>
                                        <span v-else class="badge bg-light text-muted">Flat Semester Fee</span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm" data-bs-toggle="modal" data-bs-target="#feeModal" @click="$emit('open-fee-modal', 'edit', f)">Edit</button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm" @click="$emit('delete-fee', f.id)">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredFees.length === 0">
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-wallet"></i>
                                            <p>No fees configured.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       StudentsView
       ───────────────────────────────────────────────────────────────── */
    const StudentsView = {
        props: ['students', 'searchText'],
        emits: ['open-student-modal'],
        data() {
            return {
                sortBy: 'id',
                sortDesc: false,
                filterYear: 'ALL',
                filterProgram: 'ALL'
            };
        },
        methods: {
            toggleSort(field) {
                if (this.sortBy === field) {
                    this.sortDesc = !this.sortDesc;
                } else {
                    this.sortBy = field;
                    this.sortDesc = false;
                }
            },
            getSortIcon(field) {
                if (this.sortBy !== field) return 'fa-solid fa-sort text-muted ms-1';
                return this.sortDesc ? 'fa-solid fa-sort-down text-success ms-1' : 'fa-solid fa-sort-up text-success ms-1';
            }
        },
        computed: {
            availablePrograms() {
                const set = new Set();
                (this.students || []).forEach(s => {
                    if (s.program) set.add(s.program.trim());
                });
                ['BSIT', 'BSCS', 'BSN', 'BSBA', 'BSCpE'].forEach(p => set.add(p));
                return ['ALL', ...Array.from(set).sort()];
            },
            filteredStudents() {
                // 1. Text Search Filter
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                let list = this.students || [];

                if (query) {
                    list = list.filter(s =>
                        (s.id && s.id.toLowerCase().includes(query)) ||
                        (s.name && s.name.toLowerCase().includes(query)) ||
                        (s.program && s.program.toLowerCase().includes(query))
                    );
                }

                // 2. Year Level Filter
                if (this.filterYear !== 'ALL') {
                    list = list.filter(s => s.year_level === this.filterYear);
                }

                // 3. Program Filter
                if (this.filterProgram !== 'ALL') {
                    list = list.filter(s => s.program === this.filterProgram);
                }

                // 4. Sort logic
                return [...list].sort((a, b) => {
                    let valA = a[this.sortBy];
                    let valB = b[this.sortBy];

                    // Year level fallback matching UI display
                    if (this.sortBy === 'year_level') {
                        valA = valA || '1st Year';
                        valB = valB || '1st Year';
                    } else {
                        valA = valA || '';
                        valB = valB || '';
                    }

                    if (typeof valA === 'string') valA = valA.toLowerCase();
                    if (typeof valB === 'string') valB = valB.toLowerCase();

                    if (valA < valB) return this.sortDesc ? 1 : -1;
                    if (valA > valB) return this.sortDesc ? -1 : 1;
                    return 0;
                });
            }
        },
        template: `
            <div class="view-section">
                <!-- Premium Filters Toolbar -->
                <div class="card p-3 border border-light-subtle rounded-3 bg-white mb-3 shadow-sm">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6 d-flex flex-wrap align-items-center gap-2">
                            <span class="text-muted small fw-bold text-uppercase me-2"><i class="fa-solid fa-filter text-success me-1"></i>Year Level:</span>
                            <button v-for="y in ['ALL', '1st Year', '2nd Year', '3rd Year', '4th Year']" 
                                    :key="y" 
                                    class="btn-pill btn-pill-sm py-1"
                                    :class="filterYear === y ? 'btn-pill-green' : 'btn-pill-outline'"
                                    @click="filterYear = y">
                                {{ y === 'ALL' ? 'All Years' : y }}
                            </button>
                        </div>
                        <div class="col-md-6 d-flex flex-wrap align-items-center gap-2 justify-content-md-end">
                            <span class="text-muted small fw-bold text-uppercase me-2"><i class="fa-solid fa-graduation-cap text-success me-1"></i>Program:</span>
                            <button v-for="p in availablePrograms" 
                                    :key="p" 
                                    class="btn-pill btn-pill-sm py-1"
                                    :class="filterProgram === p ? 'btn-pill-green' : 'btn-pill-outline'"
                                    @click="filterProgram = p">
                                {{ p === 'ALL' ? 'All Programs' : p }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Student Records</h3>
                            <p>Enrolled and registered student directory (Click column headers to sort)</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            Showing {{ filteredStudents.length }} of {{ students.length }} Records
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="cursor-pointer select-none" @click="toggleSort('id')">
                                        Student ID <i :class="getSortIcon('id')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('name')">
                                        Full Name <i :class="getSortIcon('name')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('program')">
                                        Mapped Program <i :class="getSortIcon('program')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('year_level')">
                                        Year Level <i :class="getSortIcon('year_level')"></i>
                                    </th>
                                    <th>Section</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="student in filteredStudents" :key="student.id">
                                    <td><strong class="font-monospace">{{ student.id }}</strong></td>
                                    <td><strong>{{ student.name }}</strong></td>
                                    <td><span class="badge bg-light text-dark font-monospace border">{{ student.program }}</span></td>
                                    <td><span class="fw-semibold text-secondary">{{ student.year_level || '1st Year' }}</span></td>
                                    <td>
                                        <span v-if="student.assignedSection || student.sectionCode" class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 font-monospace">
                                            <i class="fa-solid fa-shapes me-1"></i>{{ student.assignedSection || student.sectionCode }}
                                        </span>
                                        <span v-else class="text-muted small">Unassigned</span>
                                    </td>
                                    <td><span class="status-badge" :class="student.status.toLowerCase()">{{ student.status }}</span></td>
                                    <td>
                                        <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#studentProfileModal" 
                                                @click="$emit('open-student-modal', student)">
                                            <i class="fa-solid fa-id-card me-1"></i> View Profile
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="filteredStudents.length === 0">
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-users-slash"></i>
                                            <p>No matching student records found.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       EnrollmentOverviewView (formerly EnrollmentView)
       ───────────────────────────────────────────────────────────────── */
    const EnrollmentOverviewView = {
        props: ['enrollments', 'pendingCount', 'totalEnrolled', 'newToday'],
        template: `
            <div class="view-section">
                <div class="summary-cards">
                    <div class="summary-card">
                        <span class="summary-title">Pending Registrar Review</span>
                        <strong class="summary-value">{{ pendingCount }}</strong>
                        <span class="summary-subtext">Applications to process</span>
                    </div>
                    <div class="summary-card">
                        <span class="summary-title">Enrollment Window</span>
                        <strong class="summary-value" style="font-size:1.1rem; line-height:1.4; color:var(--primary-green);">OPEN</strong>
                        <span class="summary-subtext">AY 2026-2027 1st Sem</span>
                    </div>
                    <div class="summary-card">
                        <span class="summary-title">Total Registered Students</span>
                        <strong class="summary-value">{{ totalEnrolled }}</strong>
                        <span class="summary-subtext">Approved and dynamic</span>
                    </div>
                    <div class="summary-card">
                        <span class="summary-title">Processed Today</span>
                        <strong class="summary-value green">{{ newToday }}</strong>
                        <span class="summary-subtext">Updated recently</span>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Enrollment Queue Snapshot</h3>
                            <p>Live registry of active application files in system</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Requested Program</th>
                                    <th>Verification Status</th>
                                    <th>Timeline</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="entry in enrollments" :key="entry.student">
                                    <td><strong>{{ entry.student }}</strong></td>
                                    <td>{{ entry.course }}</td>
                                    <td>
                                        <span class="status-badge" :class="entry.status.toLowerCase()">
                                            {{ entry.status }}
                                        </span>
                                    </td>
                                    <td>{{ entry.updated }}</td>
                                </tr>
                                <tr v-if="!enrollments || enrollments.length === 0">
                                    <td colspan="4">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-clipboard-list"></i>
                                            <p>No enrollment records registered.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       ReportsView
       ───────────────────────────────────────────────────────────────── */
    const ReportsView = {
        props: ['reports'],
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Registrar Analysis & Reports</h3>
                            <p>Key indicators and analytical database breakdowns</p>
                        </div>
                        <button class="btn-pill btn-pill-green btn-pill-sm"><i class="fa-solid fa-download"></i> Download Full Audit</button>
                    </div>
                    <div class="info-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px; margin-top:10px;">
                        <div v-for="report in reports" :key="report.title" class="info-card" style="background:var(--white); padding:18px; border-radius:var(--radius-md); border:1px solid var(--gray-border); box-shadow:var(--shadow-sm);">
                            <div class="info-card-label" style="font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700; margin-bottom:6px;">{{ report.title }}</div>
                            <div class="info-card-value" style="font-size:1.8rem; font-family:var(--font-heading); font-weight:800; color:var(--primary-green);">{{ report.value }}</div>
                            <div class="info-card-meta" style="font-size:0.75rem; color:var(--text-light); margin-top:4px;">{{ report.meta }}</div>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       PendingApplicationsView
       ───────────────────────────────────────────────────────────────── */
    const PendingApplicationsView = {
        props: ['pendingApplications', 'searchText', 'sections', 'programs'],
        emits: ['open-application-modal'],

        data() {
            return {
                sortBy: 'dateSubmitted',
                sortDesc: false, // FIFO: Earliest submissions served first (First In, First Out)
                filterStatus: 'ALL',
                filterProgram: 'ALL',
                filterAdmType: 'ALL',
                filterDateFrom: '',
                filterDateTo: '',
                showDateRange: false
            };
        },

        methods: {
            toggleSort(field) {
                if (this.sortBy === field) {
                    this.sortDesc = !this.sortDesc;
                } else {
                    this.sortBy = field;
                    this.sortDesc = false;
                }
            },
            getSortIcon(field) {
                if (this.sortBy !== field) return 'fa-solid fa-sort text-muted ms-1';
                return this.sortDesc ? 'fa-solid fa-sort-down text-success ms-1' : 'fa-solid fa-sort-up text-success ms-1';
            },
            getQueueRank(app) {
                const pendingList = this.filteredApplications.filter(a => a.status === 'PRE_REGISTERED');
                const idx = pendingList.findIndex(a => a.referenceNumber === app.referenceNumber);
                return idx >= 0 ? idx + 1 : null;
            },
            getSectionsForProgram(programCode, yearLevel, semester) {
                if (!this.sections || this.sections.length === 0) return [];
                const search = (programCode || '').trim().toLowerCase();

                // Dynamic lookup of program name and code
                const progObj = this.programs ? this.programs.find(p => (p.code || '').trim().toLowerCase() === search || (p.name || '').trim().toLowerCase() === search) : null;
                const searchName = progObj ? progObj.name.trim().toLowerCase() : search;
                const searchCode = progObj ? progObj.code.trim().toLowerCase() : search;

                const targetYear = (yearLevel || '').trim().toLowerCase();
                const targetSem = (semester || '').trim().toLowerCase();

                let matched = this.sections.filter(s => {
                    const progName = (s.program || '').trim().toLowerCase();
                    const isProgMatch = (progName === searchName ||
                        progName === searchCode ||
                        progName.includes(search) ||
                        search.includes(progName));

                    const secYear = (s.yearLevel || '').trim().toLowerCase();
                    const secSem = (s.semester || '').trim().toLowerCase();

                    const yearMatch = !targetYear || secYear === targetYear || secYear.includes(targetYear) || targetYear.includes(secYear);
                    const semMatch = !targetSem || secSem === targetSem || secSem.includes(targetSem) || targetSem.includes(secSem);

                    return isProgMatch && yearMatch && semMatch;
                });

                if (matched.length === 0) {
                    matched = this.sections.filter(s => {
                        const progName = (s.program || '').trim().toLowerCase();
                        return progName === searchName || progName === searchCode || progName.includes(search) || search.includes(progName);
                    });
                }

                return matched;
            },
            clearFilters() {
                this.filterStatus = 'ALL';
                this.filterProgram = 'ALL';
                this.filterAdmType = 'ALL';
                this.filterDateFrom = '';
                this.filterDateTo = '';
                this.showDateRange = false;
            },
            fmtDate(d) {
                if (!d) return '—';
                return new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
            },
            fmtTime(d) {
                if (!d) return '';
                return new Date(d).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
            },
            statusClass(status) {
                return (status || '').toLowerCase().replace(/[\s_]+/g, '-');
            },
            typeColor(t) {
                if (t === 'RETURNING') return { bg: 'rgba(245,158,11,0.09)', color: '#b45309', border: 'rgba(245,158,11,0.3)' };
                if (t === 'TRANSFEREE') return { bg: 'rgba(59,130,246,0.09)', color: '#1d4ed8', border: 'rgba(59,130,246,0.3)' };
                return { bg: 'rgba(22,163,74,0.08)', color: '#166534', border: 'rgba(22,163,74,0.25)' };
            }
        },

        computed: {
            activeApplications() {
                return (this.pendingApplications || []).filter(a => a.status !== 'ENROLLED');
            },
            uniquePrograms() {
                return [...new Set(this.activeApplications.map(a => a.program).filter(Boolean))].sort();
            },
            uniqueAdmTypes() {
                return [...new Set(this.activeApplications.map(a => a.studentType).filter(Boolean))].sort();
            },
            countByStatus() {
                const map = {};
                this.activeApplications.forEach(a => {
                    const s = a.status || 'UNKNOWN';
                    map[s] = (map[s] || 0) + 1;
                });
                return map;
            },
            pendingCount() { return this.countByStatus['PRE_REGISTERED'] || 0; },
            approvedCount() { return this.countByStatus['APPROVED'] || 0; },
            rejectedCount() { return this.countByStatus['REJECTED'] || 0; },
            inProgressCount() { return (this.countByStatus['IN_PROGRESS'] || 0) + (this.countByStatus['REGISTRAR_APPROVED'] || 0); },
            activeFilterCount() {
                let n = 0;
                if (this.filterStatus !== 'ALL') n++;
                if (this.filterProgram !== 'ALL') n++;
                if (this.filterAdmType !== 'ALL') n++;
                if (this.filterDateFrom || this.filterDateTo) n++;
                return n;
            },
            filteredApplications() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                let list = this.activeApplications;

                if (query) {
                    list = list.filter(a =>
                        (a.referenceNumber && a.referenceNumber.toLowerCase().includes(query)) ||
                        (a.applicantName && a.applicantName.toLowerCase().includes(query)) ||
                        (a.program && a.program.toLowerCase().includes(query)) ||
                        (a.queueTicket && a.queueTicket.toLowerCase().includes(query))
                    );
                }
                if (this.filterStatus !== 'ALL') list = list.filter(a => a.status === this.filterStatus);
                if (this.filterProgram !== 'ALL') list = list.filter(a => a.program === this.filterProgram);
                if (this.filterAdmType !== 'ALL') list = list.filter(a => a.studentType === this.filterAdmType);

                if (this.filterDateFrom) {
                    const from = new Date(this.filterDateFrom);
                    list = list.filter(a => a.dateSubmitted && new Date(a.dateSubmitted) >= from);
                }
                if (this.filterDateTo) {
                    const to = new Date(this.filterDateTo);
                    to.setHours(23, 59, 59, 999);
                    list = list.filter(a => a.dateSubmitted && new Date(a.dateSubmitted) <= to);
                }

                return [...list].sort((a, b) => {
                    let vA = a[this.sortBy] || '';
                    let vB = b[this.sortBy] || '';
                    if (this.sortBy === 'dateSubmitted' || this.sortBy === 'createdAt') {
                        vA = vA ? new Date(vA).getTime() : (a.id || 0);
                        vB = vB ? new Date(vB).getTime() : (b.id || 0);
                        return this.sortDesc ? vB - vA : vA - vB;
                    }
                    vA = String(vA).toLowerCase();
                    vB = String(vB).toLowerCase();
                    if (vA < vB) return this.sortDesc ? 1 : -1;
                    if (vA > vB) return this.sortDesc ? -1 : 1;
                    return 0;
                });
            },
            nextInQueue() {
                return this.filteredApplications.find(a => a.status === 'PRE_REGISTERED') || this.filteredApplications[0] || null;
            }
        },

        template: `
            <div class="view-section">

                <!-- ── Quick-Stat Cards ─────────────────────────────────────────── -->
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left:4px solid #f59e0b!important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Pending Review</div>
                            <div style="font-size:2rem;font-family:'Outfit',sans-serif;font-weight:900;color:#d97706;line-height:1.1;">{{ pendingCount }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">awaiting action</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left:4px solid #10b981!important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Verified / Cleared</div>
                            <div style="font-size:2rem;font-family:'Outfit',sans-serif;font-weight:900;color:#059669;line-height:1.1;">{{ inProgressCount + approvedCount }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">passed registrar</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left:4px solid #3b82f6!important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Total in Queue</div>
                            <div style="font-size:2rem;font-family:'Outfit',sans-serif;font-weight:900;color:#2563eb;line-height:1.1;">{{ activeApplications.length }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">active applicants</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left:4px solid #ef4444!important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Rejected</div>
                            <div style="font-size:2rem;font-family:'Outfit',sans-serif;font-weight:900;color:#dc2626;line-height:1.1;">{{ rejectedCount }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">disapproved</div>
                        </div>
                    </div>
                </div>

                <!-- ── Filter & Search Toolbar ────────────────────────────────────── -->
                <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button v-for="st in ['ALL', 'PRE_REGISTERED', 'VERIFIED', 'APPROVED', 'REJECTED']"
                                    :key="st"
                                    class="btn-pill btn-pill-sm py-1 font-monospace"
                                    :class="filterStatus === st ? 'btn-pill-green' : 'btn-pill-outline'"
                                    @click="filterStatus = st">
                                {{ st === 'ALL' ? 'All Status' : (st === 'PRE_REGISTERED' ? 'Pending' : st) }}
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1"
                                    @click="showDateRange = !showDateRange">
                                <i class="fa-solid fa-calendar-days me-1"></i>Date Range
                            </button>
                            <button v-if="activeFilterCount > 0"
                                    class="btn-pill btn-pill-danger btn-pill-sm py-1"
                                    @click="clearFilters">
                                <i class="fa-solid fa-xmark me-1"></i>Reset Filters ({{ activeFilterCount }})
                            </button>
                        </div>
                    </div>

                    <div v-if="showDateRange" class="row g-2 mt-2 pt-2 border-top">
                        <div class="col-6 col-md-3">
                            <label class="form-label small text-muted mb-1">From Date</label>
                            <input type="date" class="form-control form-control-sm" v-model="filterDateFrom">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small text-muted mb-1">To Date</label>
                            <input type="date" class="form-control form-control-sm" v-model="filterDateTo">
                        </div>
                    </div>
                </div>

                <!-- ── Data Table ────────────────────────────────────────────────── -->
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Registrar Review Queue</h3>
                            <p>First-Come, First-Served application review and document audit — click headers to re-sort</p>
                        </div>
                        <span class="badge rounded-pill fw-bold font-monospace px-3 py-2"
                              :class="filteredApplications.length > 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-secondary bg-opacity-10 text-secondary'">
                            {{ filteredApplications.length }} / {{ activeApplications.length }} Records
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:110px;">Queue Ticket</th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('referenceNumber')" style="white-space:nowrap;">
                                        Reference ID <i :class="getSortIcon('referenceNumber')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('applicantName')" style="white-space:nowrap;">
                                        Applicant Name <i :class="getSortIcon('applicantName')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('program')" style="white-space:nowrap;">
                                        Program <i :class="getSortIcon('program')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('studentType')" style="white-space:nowrap;">
                                        Type <i :class="getSortIcon('studentType')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('dateSubmitted')" style="white-space:nowrap;">
                                        Arrival / Date <i :class="getSortIcon('dateSubmitted')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('status')" style="white-space:nowrap;">
                                        Status <i :class="getSortIcon('status')"></i>
                                    </th>
                                    <th style="width:120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="app in filteredApplications" :key="app.referenceNumber">
                                    <!-- Queue Ticket -->
                                    <td>
                                        <span class="queue-ticket-badge" :class="getQueueRank(app) === 1 ? 'ticket-next' : 'ticket-default'">
                                            <span v-if="getQueueRank(app) === 1" class="ticket-indicator"></span>
                                            {{ app.queueTicket || ('REG-' + (app.id || '001')) }}
                                        </span>
                                    </td>
                                    <!-- Reference -->
                                    <td>
                                        <strong class="font-monospace text-success" style="font-size:0.82rem;">{{ app.referenceNumber }}</strong>
                                    </td>
                                    <!-- Applicant Name with avatar initial -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                                 style="width:28px;height:28px;background:rgba(22,163,74,0.1);font-size:0.72rem;font-weight:800;color:#16a34a;font-family:'Outfit',sans-serif;">
                                                {{ (app.applicantName || '?').charAt(0).toUpperCase() }}
                                            </div>
                                            <span class="fw-semibold" style="font-size:0.88rem;">{{ app.applicantName }}</span>
                                        </div>
                                    </td>
                                    <!-- Program -->
                                    <td>
                                        <span class="badge bg-light text-dark font-monospace border" style="font-size:0.74rem;">{{ app.program }}</span>
                                    </td>
                                    <!-- Admission Type with color coding -->
                                    <td>
                                        <span class="badge border font-monospace" style="font-size:0.72rem;"
                                              :style="{ background: typeColor(app.studentType).bg, color: typeColor(app.studentType).color, borderColor: typeColor(app.studentType).border }">
                                            {{ app.studentType }}
                                        </span>
                                    </td>
                                    <!-- Date with 2-line format -->
                                    <td>
                                        <div style="font-size:0.82rem;font-weight:500;">{{ fmtDate(app.dateSubmitted) }}</div>
                                        <div style="font-size:0.7rem;color:#9ca3af;">{{ fmtTime(app.dateSubmitted) }}</div>
                                    </td>
                                    <!-- Status + section badge stacked -->
                                    <td>
                                        <span class="status-badge d-block mb-1" :class="statusClass(app.status)">{{ app.status }}</span>
                                        <span v-if="app.sectionCode"
                                              class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 font-monospace"
                                              style="font-size:0.68rem;">
                                            <i class="fa-solid fa-layer-group me-1"></i>{{ app.sectionCode }}
                                        </span>
                                    </td>
                                    <!-- Actions -->
                                    <td>
                                        <button class="btn-pill btn-pill-green btn-pill-sm py-1"
                                                @click="$emit('open-application-modal', app)">
                                            <i class="fa-solid fa-file-shield me-1"></i>Review
                                        </button>
                                    </td>
                                </tr>

                                <tr v-if="filteredApplications.length === 0">
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-filter-circle-xmark"></i>
                                            <p v-if="activeFilterCount > 0">
                                                No applications match the active filters.&nbsp;
                                                <button class="btn-pill btn-pill-outline btn-pill-sm" @click="clearFilters">Clear filters</button>
                                            </p>
                                            <p v-else>No pending applications awaiting review.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       ProgramsView
       ───────────────────────────────────────────────────────────────── */
    const ProgramsView = {
        props: ['programs', 'searchText', 'selectedProgram', 'programModalMode', 'isAdmin'],
        emits: ['open-program-modal', 'delete-program', 'save-program'],
        data() {
            return {
                localForm: { id: null, code: '', name: '', department: '', status: 'Active' }
            };
        },
        watch: {
            selectedProgram: {
                handler(newVal) {
                    if (newVal) this.localForm = { ...newVal };
                },
                immediate: true,
                deep: true
            }
        },
        computed: {
            filteredPrograms() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                const valid = this.programs.filter(p => p.code || p.name);
                if (!query) return valid;
                return valid.filter(p =>
                    (p.code || '').toLowerCase().includes(query) ||
                    (p.name || '').toLowerCase().includes(query) ||
                    (p.department || '').toLowerCase().includes(query)
                );
            }
        },
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Degree Programs</h3>
                            <p>Academic courses and departments in GNCP</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ filteredPrograms.length }} Programs Cataloged
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Program Code</th>
                                    <th>Program Title</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th v-if="isAdmin">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in filteredPrograms" :key="p.id">
                                    <td><strong class="font-monospace text-success">{{ p.code }}</strong></td>
                                    <td><strong>{{ p.name }}</strong></td>
                                    <td><span class="text-secondary">{{ p.department }}</span></td>
                                    <td><span class="status-badge" :class="p.status.toLowerCase()">{{ p.status }}</span></td>
                                    <td v-if="isAdmin">
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" @click="$emit('open-program-modal', 'edit', p)">
                                                <i class="fa-solid fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm py-1.5 ms-1" @click="$emit('delete-program', p.id)">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredPrograms.length === 0">
                                    <td :colspan="isAdmin ? 5 : 4">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-graduation-cap"></i>
                                            <p>No programs found.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Program Edit/Add Modal -->
                <div class="modal fade" id="programModal" tabindex="-1" aria-labelledby="programModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="programModalLabel">{{ programModalMode === 'edit' ? 'Edit Program Details' : 'Add New Program' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form @submit.prevent="$emit('save-program', localForm)">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Program Code</label>
                                        <input type="text" class="form-control" v-model="localForm.code" required placeholder="e.g. BSCS">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Program Title</label>
                                        <input type="text" class="form-control" v-model="localForm.name" required placeholder="e.g. BS Computer Science">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Department</label>
                                        <input type="text" class="form-control" v-model="localForm.department" required placeholder="e.g. College of Computer Studies">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" v-model="localForm.status" required>
                                            <option value="Active">Active</option>
                                            <option value="Inactive">Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn-pill btn-pill-green">Save Details</button>
                                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       SubjectsView
       ───────────────────────────────────────────────────────────────── */
    const SubjectsView = {
        props: ['subjects', 'searchText', 'selectedSubject', 'subjectModalMode', 'isAdmin'],
        emits: ['open-subject-modal', 'delete-subject', 'save-subject'],
        data() {
            return {
                localForm: { id: null, code: '', title: '', description: '', lectureUnits: 3, labUnits: 0, labFee: 0, department: '', prerequisites: 'None' }
            };
        },
        watch: {
            selectedSubject: {
                handler(newVal) {
                    if (newVal) this.localForm = { ...newVal };
                },
                immediate: true,
                deep: true
            }
        },
        computed: {
            filteredSubjects() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                if (!query) return this.subjects;
                return this.subjects.filter(s =>
                    s.code.toLowerCase().includes(query) ||
                    s.title.toLowerCase().includes(query) ||
                    s.department.toLowerCase().includes(query)
                );
            }
        },
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Subject Catalog</h3>
                            <p>Academic subjects defined in GNCP systems</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ filteredSubjects.length }} Subjects Registered
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Subject Code</th>
                                    <th>Subject Title</th>
                                    <th>Lec Units</th>
                                    <th>Lab Units</th>
                                    <th>Lab Fee</th>
                                    <th>Prerequisites</th>
                                    <th v-if="isAdmin">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in filteredSubjects" :key="s.id">
                                    <td><strong class="font-monospace text-success">{{ s.code }}</strong></td>
                                    <td><strong>{{ s.title }}</strong></td>
                                    <td>{{ s.lectureUnits }}</td>
                                    <td>{{ s.labUnits }}</td>
                                    <td><strong>₱{{ s.labFee.toLocaleString() }}</strong></td>
                                    <td><span class="badge bg-light text-dark font-monospace border">{{ s.prerequisites }}</span></td>
                                    <td v-if="isAdmin">
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" @click="$emit('open-subject-modal', 'edit', s)">
                                                <i class="fa-solid fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm py-1.5 ms-1" @click="$emit('delete-subject', s.id)">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredSubjects.length === 0">
                                    <td :colspan="isAdmin ? 7 : 6">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-book"></i>
                                            <p>No subjects found.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Subject Edit/Add Modal -->
                <div class="modal fade" id="subjectModal" tabindex="-1" aria-labelledby="subjectModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="subjectModalLabel">{{ subjectModalMode === 'edit' ? 'Edit Subject Details' : 'Add New Subject' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form @submit.prevent="$emit('save-subject', localForm)">
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Subject Code</label>
                                            <input type="text" class="form-control" v-model="localForm.code" required placeholder="e.g. CS101">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Department/Group</label>
                                            <input type="text" class="form-control" v-model="localForm.department" required placeholder="e.g. BS Computer Science">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Subject Title</label>
                                            <input type="text" class="form-control" v-model="localForm.title" required placeholder="e.g. Introduction to Computing">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" rows="2" v-model="localForm.description" placeholder="Brief subject description..."></textarea>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Lec Units</label>
                                            <input type="number" class="form-control" v-model.number="localForm.lectureUnits" required min="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Lab Units</label>
                                            <input type="number" class="form-control" v-model.number="localForm.labUnits" required min="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Lab Fee</label>
                                            <input type="number" class="form-control" v-model.number="localForm.labFee" required min="0" step="0.01">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Prerequisites</label>
                                            <input type="text" class="form-control" v-model="localForm.prerequisites" placeholder="e.g. None or CS101">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn-pill btn-pill-green">Save Details</button>
                                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       CurriculumView
       ───────────────────────────────────────────────────────────────── */
    const CurriculumView = {
        props: ['curriculum', 'programs', 'subjects', 'searchText', 'selectedCurriculum', 'curriculumModalMode', 'isAdmin'],
        emits: ['open-curriculum-modal', 'delete-curriculum', 'save-curriculum'],
        data() {
            return {
                localForm: { id: null, program: '', subject: '', yearLevel: '1st Year', semester: '1st Semester', elective: false }
            };
        },
        watch: {
            selectedCurriculum: {
                handler(newVal) {
                    if (newVal) this.localForm = { ...newVal };
                },
                immediate: true,
                deep: true
            }
        },
        computed: {
            filteredCurriculum() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                if (!query) return this.curriculum;
                return this.curriculum.filter(c =>
                    c.program.toLowerCase().includes(query) ||
                    c.subject.toLowerCase().includes(query) ||
                    (c.subjectCode && c.subjectCode.toLowerCase().includes(query)) ||
                    c.yearLevel.toLowerCase().includes(query) ||
                    c.semester.toLowerCase().includes(query)
                );
            }
        },
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Curriculum Mapping</h3>
                            <p>Map catalog subjects to degree programs by year and semester</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ filteredCurriculum.length }} Subject Links
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Program Course</th>
                                    <th>Subject Title</th>
                                    <th>Code</th>
                                    <th>Units</th>
                                    <th>Target Period</th>
                                    <th>Elective?</th>
                                    <th v-if="isAdmin">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in filteredCurriculum" :key="c.id">
                                    <td><strong>{{ c.program }}</strong></td>
                                    <td>{{ c.subject }}</td>
                                    <td><span class="badge bg-light text-dark font-monospace border">{{ c.subjectCode }}</span></td>
                                    <td>Lec: {{ c.lectureUnits }} · Lab: {{ c.labUnits }}</td>
                                    <td><span class="fw-semibold text-secondary">{{ c.yearLevel }} · {{ c.semester }}</span></td>
                                    <td>
                                        <span v-if="c.elective" class="badge bg-warning-subtle text-warning-emphasis">Elective</span>
                                        <span v-else class="badge bg-light text-muted">Core Required</span>
                                    </td>
                                    <td v-if="isAdmin">
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" @click="$emit('open-curriculum-modal', 'edit', c)">
                                                <i class="fa-solid fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm py-1.5 ms-1" @click="$emit('delete-curriculum', c.id)">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredCurriculum.length === 0">
                                    <td :colspan="isAdmin ? 7 : 6">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-sitemap"></i>
                                            <p>No curriculum links mapped.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Curriculum Mapping Edit/Add Modal -->
                <div class="modal fade" id="curriculumModal" tabindex="-1" aria-labelledby="curriculumModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="curriculumModalLabel">{{ curriculumModalMode === 'edit' ? 'Edit Curriculum Mapping' : 'Link Subject to Program' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form @submit.prevent="$emit('save-curriculum', localForm)">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Target Program</label>
                                        <select class="form-select" v-model="localForm.program" required>
                                            <option value="" disabled>Select program...</option>
                                            <option v-for="p in programs" :key="p.id" :value="p.name">{{ p.name }} ({{ p.code }})</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Subject Link</label>
                                        <select class="form-select" v-model="localForm.subject" required>
                                            <option value="" disabled>Select subject...</option>
                                            <option v-for="s in subjects" :key="s.id" :value="s.title">{{ s.title }} [{{ s.code }}]</option>
                                        </select>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Year Level</label>
                                            <select class="form-select" v-model="localForm.yearLevel" required>
                                                <option value="1st Year">1st Year</option>
                                                <option value="2nd Year">2nd Year</option>
                                                <option value="3rd Year">3rd Year</option>
                                                <option value="4th Year">4th Year</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Semester</label>
                                            <select class="form-select" v-model="localForm.semester" required>
                                                <option value="1st Semester">1st Semester</option>
                                                <option value="2nd Semester">2nd Semester</option>
                                                <option value="Summer">Summer</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="electiveSwitch" v-model="localForm.elective">
                                        <label class="form-check-label" for="electiveSwitch">Mark as elective course</label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn-pill btn-pill-green">Save Mapping</button>
                                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       AcademicPeriodsView
       ───────────────────────────────────────────────────────────────── */
    const AcademicPeriodsView = {
        props: ['academicPeriods', 'searchText', 'selectedPeriod', 'periodModalMode', 'isAdmin'],
        emits: ['open-period-modal', 'delete-period', 'save-period'],
        data() {
            return {
                localForm: { id: null, name: '', academicYear: '', semester: '1st Semester', enrollmentStart: '', enrollmentEnd: '', status: 'Inactive' }
            };
        },
        watch: {
            selectedPeriod: {
                handler(newVal) {
                    if (newVal) this.localForm = { ...newVal };
                },
                immediate: true,
                deep: true
            }
        },
        computed: {
            filteredPeriods() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                if (!query) return this.academicPeriods;
                return this.academicPeriods.filter(p =>
                    p.name.toLowerCase().includes(query) ||
                    p.academicYear.toLowerCase().includes(query) ||
                    p.semester.toLowerCase().includes(query)
                );
            }
        },
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Academic Periods</h3>
                            <p>Manage active/inactive enrollment semesters and registration dates</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ filteredPeriods.length }} Periods Created
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Period Name</th>
                                    <th>School Year</th>
                                    <th>Semester</th>
                                    <th>Enrollment Window</th>
                                    <th>Status</th>
                                    <th v-if="isAdmin">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in filteredPeriods" :key="p.id">
                                    <td><strong>{{ p.name }}</strong></td>
                                    <td>{{ p.academicYear }}</td>
                                    <td>{{ p.semester }}</td>
                                    <td><span class="text-secondary">{{ p.enrollmentStart || 'N/A' }} to {{ p.enrollmentEnd || 'N/A' }}</span></td>
                                    <td><span class="status-badge" :class="p.status.toLowerCase()">{{ p.status }}</span></td>
                                    <td v-if="isAdmin">
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" @click="$emit('open-period-modal', 'edit', p)">
                                                <i class="fa-solid fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm py-1.5 ms-1" @click="$emit('delete-period', p.id)">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredPeriods.length === 0">
                                    <td :colspan="isAdmin ? 6 : 5">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-calendar-days"></i>
                                            <p>No academic periods created.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Academic Period Modal -->
                <div class="modal fade" id="periodModal" tabindex="-1" aria-labelledby="periodModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="periodModalLabel">{{ periodModalMode === 'edit' ? 'Edit Academic Period' : 'Add Academic Period' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form @submit.prevent="$emit('save-period', localForm)">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Period Name</label>
                                        <input type="text" class="form-control" v-model="localForm.name" required placeholder="e.g. 1st Semester, A.Y. 2026-2027">
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Academic Year</label>
                                            <input type="text" class="form-control" v-model="localForm.academicYear" required placeholder="e.g. 2026-2027">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Semester</label>
                                            <select class="form-select" v-model="localForm.semester" required>
                                                <option value="1st Semester">1st Semester</option>
                                                <option value="2nd Semester">2nd Semester</option>
                                                <option value="Summer">Summer</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Enrollment Start</label>
                                            <input type="date" class="form-control" v-model="localForm.enrollmentStart">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Enrollment End</label>
                                            <input type="date" class="form-control" v-model="localForm.enrollmentEnd">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" v-model="localForm.status" required>
                                            <option value="Active">Active</option>
                                            <option value="Inactive">Inactive</option>
                                        </select>
                                        <small class="text-muted mt-1 d-block"><i class="fas fa-circle-info me-1"></i> Saving a period as 'Active' sets all other periods to 'Inactive'.</small>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn-pill btn-pill-green">Save Period</button>
                                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       SubjectSectionsView
       ───────────────────────────────────────────────────────────────── */
    const SubjectSectionsView = {
        props: ['subjectSections', 'subjects', 'programs', 'searchText', 'selectedSection', 'sectionModalMode', 'isAdmin'],
        emits: ['open-section-modal', 'delete-section', 'save-section'],
        data() {
            return {
                localForm: { id: null, program: '', yearLevel: '1st Year', semester: '1st Semester', subject: '', code: '', instructor: '', days: '', time: '', room: '', capacity: 40 }
            };
        },
        watch: {
            selectedSection: {
                handler(newVal) {
                    if (newVal) this.localForm = { ...newVal };
                },
                immediate: true,
                deep: true
            }
        },
        computed: {
            filteredSections() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                if (!query) return this.subjectSections;
                return this.subjectSections.filter(s =>
                    s.subject.toLowerCase().includes(query) ||
                    s.code.toLowerCase().includes(query) ||
                    s.instructor.toLowerCase().includes(query) ||
                    s.room.toLowerCase().includes(query)
                );
            }
        },
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Subject Section Schedules</h3>
                            <p>Track class capacity, instructors, and room configurations</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ filteredSections.length }} Classes Active
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Section Code</th>
                                    <th>Linked Subject</th>
                                    <th>Mapped Course Info</th>
                                    <th>Instructor</th>
                                    <th>Schedule Patterns</th>
                                    <th>Room & Space</th>
                                    <th>Capacity</th>
                                    <th v-if="isAdmin">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in filteredSections" :key="s.id">
                                    <td><strong class="font-monospace text-success">{{ s.code }}</strong></td>
                                    <td><strong>{{ s.subject }}</strong></td>
                                    <td><span class="text-secondary small">{{ s.program }} · {{ s.yearLevel }} · {{ s.semester }}</span></td>
                                    <td>{{ s.instructor }}</td>
                                    <td><span class="badge bg-light text-dark font-monospace border"><i class="fa-solid fa-clock me-1"></i> {{ s.days }} {{ s.time }}</span></td>
                                    <td><span class="badge bg-light text-secondary border">{{ s.room }}</span></td>
                                    <td>
                                        <span class="fw-bold font-monospace text-dark">{{ s.capacity }} seats left</span>
                                    </td>
                                    <td v-if="isAdmin">
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" @click="$emit('open-section-modal', 'edit', s)">
                                                <i class="fa-solid fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm py-1.5 ms-1" @click="$emit('delete-section', s.id)">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredSections.length === 0">
                                    <td :colspan="isAdmin ? 8 : 7">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-chalkboard-user"></i>
                                            <p>No class sections mapped.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Subject Section Edit/Add Modal -->
                <div class="modal fade" id="sectionModal" tabindex="-1" aria-labelledby="sectionModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="sectionModalLabel">{{ sectionModalMode === 'edit' ? 'Edit Class Section Details' : 'Add Class Section offering' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form @submit.prevent="$emit('save-section', localForm)">
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Target Program</label>
                                            <select class="form-select" v-model="localForm.program" required>
                                                <option value="" disabled>Select program...</option>
                                                <option v-for="p in programs" :key="p.id" :value="p.name">{{ p.name }}</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Subject Link</label>
                                            <select class="form-select" v-model="localForm.subject" required>
                                                <option value="" disabled>Select subject...</option>
                                                <option v-for="sub in subjects" :key="sub.id" :value="sub.title">{{ sub.title }} [{{ sub.code }}]</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Year Level</label>
                                            <select class="form-select" v-model="localForm.yearLevel" required>
                                                <option value="1st Year">1st Year</option>
                                                <option value="2nd Year">2nd Year</option>
                                                <option value="3rd Year">3rd Year</option>
                                                <option value="4th Year">4th Year</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Semester</label>
                                            <select class="form-select" v-model="localForm.semester" required>
                                                <option value="1st Semester">1st Semester</option>
                                                <option value="2nd Semester">2nd Semester</option>
                                                <option value="Summer">Summer</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Section Code</label>
                                            <input type="text" class="form-control" v-model="localForm.code" required placeholder="e.g. CS101-A">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Instructor</label>
                                            <input type="text" class="form-control" v-model="localForm.instructor" required placeholder="e.g. Prof. Turing">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Days</label>
                                            <input type="text" class="form-control" v-model="localForm.days" required placeholder="e.g. MWF or TTH">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Class Hours</label>
                                            <input type="text" class="form-control" v-model="localForm.time" required placeholder="e.g. 09:00 AM - 10:00 AM">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Room Allocation</label>
                                            <input type="text" class="form-control" v-model="localForm.room" required placeholder="e.g. Lab 1 or Room 201">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Max Student Capacity</label>
                                            <input type="number" class="form-control" v-model.number="localForm.capacity" required min="1">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn-pill btn-pill-green">Save Section</button>
                                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       FeeScheduleView
       ───────────────────────────────────────────────────────────────── */
    const FeeScheduleView = {
        props: ['feeSchedule', 'searchText', 'selectedFee', 'feeModalMode', 'isAdmin'],
        emits: ['open-fee-modal', 'delete-fee', 'save-fee'],
        data() {
            return {
                localForm: { id: null, type: 'Tuition', label: '', amount: 0, perUnit: false }
            };
        },
        watch: {
            selectedFee: {
                handler(newVal) {
                    if (newVal) this.localForm = { ...newVal };
                },
                immediate: true,
                deep: true
            }
        },
        computed: {
            filteredFees() {
                const query = this.searchText ? this.searchText.trim().toLowerCase() : '';
                if (!query) return this.feeSchedule;
                return this.feeSchedule.filter(f =>
                    f.type.toLowerCase().includes(query) ||
                    f.label.toLowerCase().includes(query)
                );
            }
        },
        template: `
            <div class="view-section">
                <div class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Fee Configuration Schedule</h3>
                            <p>Set unit rates and miscellaneous registration charges</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ filteredFees.length }} Fees Managed
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fee Type</th>
                                    <th>Fee Label</th>
                                    <th>Amount</th>
                                    <th>Per Unit Pricing?</th>
                                    <th v-if="isAdmin">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="f in filteredFees" :key="f.id">
                                    <td><span class="badge bg-light text-dark font-monospace border">{{ f.type }}</span></td>
                                    <td><strong>{{ f.label }}</strong></td>
                                    <td><strong class="text-success">₱{{ f.amount.toLocaleString(undefined, {minimumFractionDigits: 2}) }}</strong></td>
                                    <td>
                                        <span v-if="f.perUnit" class="badge bg-warning-subtle text-warning-emphasis">Per Unit</span>
                                        <span v-else class="badge bg-light text-muted">Flat Semester Fee</span>
                                    </td>
                                    <td v-if="isAdmin">
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-outline btn-pill-sm py-1.5" @click="$emit('open-fee-modal', 'edit', f)">
                                                <i class="fa-solid fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn-pill btn-pill-danger btn-pill-sm py-1.5 ms-1" @click="$emit('delete-fee', f.id)">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredFees.length === 0">
                                    <td :colspan="isAdmin ? 5 : 4">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-wallet"></i>
                                            <p>No fees configured.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Fee Modal -->
                <div class="modal fade" id="feeModal" tabindex="-1" aria-labelledby="feeModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="feeModalLabel">{{ feeModalMode === 'edit' ? 'Edit Fee Item' : 'Add Fee Item' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form @submit.prevent="$emit('save-fee', localForm)">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Fee Type</label>
                                        <select class="form-select" v-model="localForm.type" required>
                                            <option value="Tuition">Tuition</option>
                                            <option value="Miscellaneous">Miscellaneous</option>
                                            <option value="Laboratory">Laboratory</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description Label</label>
                                        <input type="text" class="form-control" v-model="localForm.label" required placeholder="e.g. Registration Fee or IT Laboratory Fee">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Rate Amount (PHP)</label>
                                        <input type="number" class="form-control" v-model.number="localForm.amount" required min="0" step="0.01">
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="perUnitSwitch" v-model="localForm.perUnit">
                                        <label class="form-check-label" for="perUnitSwitch">Multiply amount by academic course units</label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn-pill btn-pill-green">Save Fee</button>
                                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        `
    };

    /* ─────────────────────────────────────────────────────────────────
       Export Views
       ───────────────────────────────────────────────────────────────── */
    global.RegistrarView = {
        SidebarNav,
        TopBar,
        StudentsView,
        PendingApplicationsView,
        EnrollmentOverviewView,
        ReportsView,
        ProgramsView,
        SubjectsView,
        CurriculumView,
        AcademicPeriodsView,
        SubjectSectionsView,
        FeeScheduleView
    };

})(typeof window !== 'undefined' ? window : this);

