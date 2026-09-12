// Sub-page view components for the school website SPA
window.PagesView = {
    AboutPage: {
        props: ['subPage', 'data'],
        template: `
            <div class="subpage-wrapper">
                <!-- Page Banner Header -->
                <div class="subpage-banner text-center d-flex align-items-center justify-content-center">
                    <div class="container position-relative" style="z-index: 2;">
                        <span class="subpage-banner-tag text-gold text-uppercase fw-bold">About GNCP</span>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-if="subPage === 'about-mission'">Mission & Vision</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'about-history'">Our History</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'about-facilities'">Campus Facilities</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'about-admins'">Our Administrators</h1>
                    </div>
                </div>

                <!-- Mission & Vision Content -->
                <div v-if="subPage === 'about-mission'" class="container py-5">
                    <div class="row g-5">
                        <div class="col-md-6">
                            <div class="content-card shadow-sm border-0 h-100 p-5">
                                <div class="card-icon-header bg-green-light mb-4">
                                    <i class="fas fa-bullseye text-green fs-3"></i>
                                </div>
                                <h3 class="fw-bold text-green mb-4">OUR MISSION</h3>
                                <p class="text-muted leading-relaxed" style="font-size:1.1rem; line-height:1.8;">
                                    {{ data.mission }}
                                </p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="content-card shadow-sm border-0 h-100 p-5">
                                <div class="card-icon-header bg-gold-light mb-4">
                                    <i class="fas fa-eye text-gold-dark fs-3"></i>
                                </div>
                                <h3 class="fw-bold text-gold-dark mb-4">OUR VISION</h3>
                                <p class="text-muted leading-relaxed" style="font-size:1.1rem; line-height:1.8;">
                                    {{ data.vision }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Core Values -->
                    <div class="mt-5 pt-4">
                        <div class="text-center mb-5">
                            <span class="section-tagline">Collegiate Ideals</span>
                            <h2 class="section-title">Our Core Values</h2>
                        </div>
                        <div class="row g-4">
                            <div v-for="val in data.values" :key="val.title" class="col-lg-4 col-md-6">
                                <div class="value-card shadow-sm h-100 text-center p-4">
                                    <div class="value-card-icon mx-auto mb-3">
                                        <i :class="val.icon"></i>
                                    </div>
                                    <h4 class="fw-bold mb-2">{{ val.title }}</h4>
                                    <p class="text-muted mb-0">{{ val.desc }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- History Content -->
                <div v-else-if="subPage === 'about-history'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">How We Started</span>
                        <h2 class="section-title">GNCP Timeline</h2>
                    </div>
                    
                    <div class="timeline-container mx-auto" style="max-width: 800px;">
                        <div v-for="(hist, idx) in data.history" :key="hist.year" 
                             class="timeline-item position-relative mb-5" 
                             :class="{ 'text-md-end': idx % 2 === 0, 'text-md-start': idx % 2 !== 0 }">
                            <div class="row align-items-center">
                                <div class="col-md-6" :class="{ 'order-md-2': idx % 2 !== 0 }">
                                    <div class="timeline-card p-4 shadow-sm">
                                        <span class="timeline-year badge bg-green px-3 py-2 mb-2" style="font-size: 1rem;">{{ hist.year }}</span>
                                        <h4 class="fw-bold mb-2">{{ hist.title }}</h4>
                                        <p class="text-muted mb-0">{{ hist.desc }}</p>
                                    </div>
                                </div>
                                <div class="col-md-6" :class="{ 'order-md-1': idx % 2 !== 0 }">
                                    <div class="timeline-image-container p-2 text-center">
                                        <img :src="hist.image" :alt="hist.title" class="img-fluid rounded shadow-sm timeline-img-styled" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Campus Facilities Content -->
                <div v-else-if="subPage === 'about-facilities'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Learning Infrastructure</span>
                        <h2 class="section-title">Our Modern Spaces</h2>
                    </div>
                    <div class="row g-4">
                        <div v-for="fac in data.facilities" :key="fac.name" class="col-md-6">
                            <div class="facility-card shadow-sm h-100">
                                <div class="facility-img-wrapper">
                                    <img :src="fac.image" :alt="fac.name" class="facility-img">
                                </div>
                                <div class="facility-body p-4">
                                    <h4 class="fw-bold text-green mb-2">{{ fac.name }}</h4>
                                    <p class="text-muted mb-0">{{ fac.desc }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Administrators Content -->
                <div v-else-if="subPage === 'about-admins'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">College Leadership</span>
                        <h2 class="section-title">Administrative Board</h2>
                    </div>
                    <div class="row g-4 justify-content-center">
                        <div v-for="adm in data.admins" :key="adm.name" class="col-lg-3 col-md-6 col-sm-10">
                            <div class="admin-card text-center shadow-sm h-100">
                                <div class="admin-img-wrapper">
                                    <img :src="adm.image" :alt="adm.name" class="admin-img">
                                </div>
                                <div class="admin-body p-4">
                                    <h5 class="fw-bold text-green mb-1">{{ adm.name }}</h5>
                                    <p class="text-muted mb-0 small text-uppercase fw-bold">{{ adm.role }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `
    },

    AcademicsPage: {
        props: ['subPage', 'data'],
        template: `
            <div class="subpage-wrapper">
                <!-- Page Banner Header -->
                <div class="subpage-banner text-center d-flex align-items-center justify-content-center">
                    <div class="container position-relative" style="z-index: 2;">
                        <span class="subpage-banner-tag text-gold text-uppercase fw-bold">Academic Programs</span>
                        <h1 class="subpage-banner-title text-white text-uppercase">{{ data.title }}</h1>
                    </div>
                </div>

                <div class="container py-5">
                    <div class="row g-5">
                        <div class="col-lg-8">
                            <!-- Program Profile -->
                            <div class="content-card shadow-sm p-4 mb-4 border-0">
                                <h3 class="fw-bold text-green mb-3">About the Program</h3>
                                <p class="text-muted leading-relaxed" style="font-size:1.05rem; line-height: 1.8;">
                                    {{ data.desc }}
                                </p>
                            </div>

                            <!-- Curriculum Highlights -->
                            <div class="content-card shadow-sm p-4 border-0">
                                <h3 class="fw-bold text-green mb-4">Curriculum Highlights</h3>
                                <div class="accordion border-0 shadow-none" id="curriculumAccordion">
                                    <div v-for="(year, idx) in data.curriculum" :key="year.sem" class="accordion-item mb-3 border border-light shadow-sm">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button fw-bold text-dark text-uppercase bg-light" type="button" 
                                                    data-bs-toggle="collapse" :data-bs-target="'#collapse' + idx" 
                                                    :aria-expanded="idx === 0 ? 'true' : 'false'">
                                                {{ year.sem }} Subjects
                                            </button>
                                        </h2>
                                        <div :id="'collapse' + idx" class="accordion-collapse collapse" :class="{ show: idx === 0 }" data-bs-parent="#curriculumAccordion">
                                            <div class="accordion-body">
                                                <ul class="list-group list-group-flush">
                                                    <li v-for="course in year.courses" :key="course" class="list-group-item py-2">
                                                        <i class="fas fa-check text-green me-3"></i>{{ course }}
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar Info -->
                        <div class="col-lg-4">
                            <div class="sidebar-card shadow-sm bg-green text-white p-4 mb-4">
                                <h4 class="fw-bold mb-3 border-bottom border-light-subtle pb-2 text-uppercase text-gold">Career Opportunities</h4>
                                <ul class="list-unstyled">
                                    <li v-for="career in data.careers" :key="career" class="mb-3 d-flex align-items-center">
                                        <i class="fas fa-briefcase text-gold me-3"></i>
                                        <span class="fw-semibold">{{ career }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div class="sidebar-card shadow-sm border border-light-subtle p-4 text-center">
                                <h4 class="fw-bold text-green mb-3">Interested in enrolling?</h4>
                                <p class="text-muted small mb-4">Undergraduate applications are currently ongoing for the first semester of A.Y. 2026-2027.</p>
                                <a :href="'../enrollment-system/' + (subPage === 'acad-it' ? '?dept=COIT' : subPage === 'acad-business' ? '?dept=COBA' : subPage === 'acad-health' ? '?dept=COHS' : '')" class="btn btn-pill btn-pill-green w-100 py-3 shadow">APPLY ONLINE</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `
    },

    AdmissionsPage: {
        props: ['subPage', 'data', 'enrollNowUrl'],
        data() {
            return {
                activeTab: 'freshmen',
                checkedReqs: {},
                searchQuery: '',
                activeFaq: 0,
                // Tuition Calculator States
                selectedProgram: 'it',
                yearLevel: '1',
                unitsCount: 18,
                labCourses: 1,
                paymentPlan: 'full',
                calculationResult: null,
                enrollmentSteps: [
                    {
                        step: 1,
                        title: "Online Pre-Registration Wizard",
                        station: "Public Portal",
                        location: "Self-Service Online",
                        icon: "fas fa-laptop-file",
                        desc: "Submit applicant personal & academic data, select degree program, and obtain your official Reference Number (REF-2026-XXXX)."
                    },
                    {
                        step: 2,
                        title: "Document Verification & Acceptance",
                        station: "Office of the Registrar",
                        location: "Admin Building · 1st Floor",
                        icon: "fas fa-file-circle-check",
                        desc: "Present your original Form 138/SF9, Good Moral Certificate, and PSA Birth Certificate to verify academic requirements."
                    },
                    {
                        step: 3,
                        title: "Academic Advising & Section Allocation",
                        station: "TLC Helpdesk",
                        location: "Academic Wing · Room 204",
                        icon: "fas fa-chalkboard-user",
                        desc: "Lock in your official block section, select NSTP component (ROTC / CWTS), and receive your signed curriculum assessment."
                    },
                    {
                        step: 4,
                        title: "Physical Fitness & Health Clearance",
                        station: "GNCP Campus Clinic",
                        location: "Student Services Pavilion",
                        icon: "fas fa-heart-pulse",
                        desc: "Complete physical fitness exam, medical history interview, and obtain health clearance for physical education and campus activities."
                    },
                    {
                        step: 5,
                        title: "Tuition Downpayment & OR Issuance",
                        station: "Cashier & Treasury Office",
                        location: "Ground Floor · Treasury Hall",
                        icon: "fas fa-cash-register",
                        desc: "Settle minimum downpayment or full semester tuition via Over-The-Counter Cash or PayMongo QR Ph / GCash instant verification."
                    },
                    {
                        step: 6,
                        title: "Permanent ID Capture & Portal Activation",
                        station: "IT Center Workstation",
                        location: "Tech Building · Room 301",
                        icon: "fas fa-id-card",
                        desc: "Take official photo for RFID Student ID, receive permanent institutional email (user@gncp.edu.ph), and access Student Portal."
                    }
                ],
                faqs: [
                    {
                        q: "What if my Form 138 / SF9 is not yet released by my High School?",
                        a: "You may temporarily submit an Official Certificate of Candidacy for Graduation or Certified Grade Slip along with an Undertaking Form promising to submit the original Form 138 within 30 days."
                    },
                    {
                        q: "Is the PSA Birth Certificate required to be newly issued?",
                        a: "Any clear and authentic PSA Birth Certificate (formerly NSO) on official security paper with a readable barcode is accepted, regardless of issuance date."
                    },
                    {
                        q: "Can I apply for a scholarship or tuition discount during document submission?",
                        a: "Yes! If you are a Valedictorian, Salutatorian, Academic Honor graduate, or Barangay Indigent, present your Certificate of Honors or Indigency directly during Step 2 (Registrar) and Step 3 (Helpdesk) to apply discount vouchers."
                    },
                    {
                        q: "Are transferees required to take the entrance examination?",
                        a: "Transferees must submit their Transcript of Records (TOR) for course syllabus credit evaluation. An academic interview with the College Dean is conducted in lieu of the freshman entrance exam."
                    }
                ]
            };
        },
        computed: {
            classList() {
                if (this.data && this.data.classifications) {
                    return this.data.classifications;
                }
                return [];
            },
            currentClassification() {
                const list = this.classList;
                return list.find(c => c.id === this.activeTab) || list[0] || { requirements: [] };
            },
            filteredRequirements() {
                const reqs = (this.currentClassification && this.currentClassification.requirements) ? this.currentClassification.requirements : [];
                if (!this.searchQuery) return reqs;
                const q = this.searchQuery.toLowerCase().trim();
                return reqs.filter(r => r.title.toLowerCase().includes(q) || (r.note && r.note.toLowerCase().includes(q)) || (r.agency && r.agency.toLowerCase().includes(q)));
            },
            totalReqsCount() {
                return (this.currentClassification && this.currentClassification.requirements) ? this.currentClassification.requirements.length : 0;
            },
            checkedReqsCount() {
                const reqs = (this.currentClassification && this.currentClassification.requirements) ? this.currentClassification.requirements : [];
                let count = 0;
                reqs.forEach(r => {
                    if (this.checkedReqs[r.id]) count++;
                });
                return count;
            },
            readinessPercentage() {
                if (this.totalReqsCount === 0) return 0;
                return Math.round((this.checkedReqsCount / this.totalReqsCount) * 100);
            },
            readinessColor() {
                const p = this.readinessPercentage;
                if (p === 100) return '#10b981';
                if (p >= 60) return '#0284c7';
                if (p > 0) return '#f59e0b';
                return '#94a3b8';
            }
        },
        methods: {
            selectTab(tabId) {
                this.activeTab = tabId;
                this.searchQuery = '';
            },
            toggleReq(id) {
                this.checkedReqs[id] = !this.checkedReqs[id];
            },
            toggleFaq(idx) {
                this.activeFaq = this.activeFaq === idx ? -1 : idx;
            },
            printChecklist() {
                window.print();
            },
            formatBadgeClass(format) {
                if (!format) return 'adm-badge-original';
                const f = format.toUpperCase();
                if (f.includes('ORIGINAL') && !f.includes('PHOTOCOPY')) return 'adm-badge-original';
                if (f.includes('PHOTOCOPY') || f.includes('COPY')) return 'adm-badge-copy';
                if (f.includes('LEGAL') || f.includes('PSA')) return 'adm-badge-legal';
                if (f.includes('PHOTO')) return 'adm-badge-photo';
                return 'adm-badge-original';
            },
            calculateTuition() {
                const ratePerUnit = this.data.tuition.ratePerUnit;
                const baseTuition = this.unitsCount * ratePerUnit;
                
                // Calculate laboratory fees (1200 per lab course)
                const labFees = this.labCourses * 1200;
                
                // Calculate misc fees
                let miscFeesTotal = 0;
                this.data.tuition.miscFees.forEach(fee => {
                    miscFeesTotal += fee.amount;
                });
                
                const totalGross = baseTuition + miscFeesTotal + labFees;
                
                let discount = 0;
                if (this.paymentPlan === 'full') {
                    discount = Math.round(baseTuition * 0.05);
                }
                
                const totalDue = totalGross - discount;
                
                this.calculationResult = {
                    baseTuition,
                    miscFeesTotal,
                    labFees,
                    discount,
                    totalDue
                };
            }
        },
        template: `
            <div class="subpage-wrapper">
                <!-- Page Banner Header -->
                <div class="subpage-banner text-center d-flex align-items-center justify-content-center">
                    <div class="container position-relative" style="z-index: 2;">
                        <span class="subpage-banner-tag text-gold text-uppercase fw-bold">Admission Portal 2026-2027</span>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-if="subPage === 'admission-requirements'">Admission Requirements</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'admission-fees'">Tuition & Fees</h1>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════════════════
                     REDESIGNED ADMISSION REQUIREMENTS & DOCUMENT ROADMAP
                     ══════════════════════════════════════════════════════════════ -->
                <div v-if="subPage === 'admission-requirements'" class="container py-5">
                    
                    <!-- Section Header -->
                    <div class="text-center mb-4">
                        <div class="adm-hero-badge mb-2">
                            <i class="fas fa-clipboard-check text-gold"></i>
                            Official Document &amp; Enrollment Guide
                        </div>
                        <h2 class="section-title mb-2">Required Admission Credentials</h2>
                        <p class="text-muted mx-auto" style="max-width: 680px;">
                            Select your student classification below to view official documentary requirements, submission formats, and verification roadmap for Academic Year 2026–2027.
                        </p>
                    </div>

                    <!-- ── Interactive Classification Tabs ──────────────── -->
                    <div class="adm-tabs-container mb-4">
                        <button v-for="c in classList" :key="c.id"
                                class="adm-tab-pill" 
                                :class="{ 'active': activeTab === c.id }"
                                @click="selectTab(c.id)">
                            <i :class="c.icon"></i>
                            <span>{{ c.name }}</span>
                        </button>
                    </div>

                    <!-- ── Classification Overview Header ───────────────── -->
                    <div class="adm-overview-card mb-4" v-if="currentClassification">
                        <div class="row align-items-center g-3">
                            <div class="col-lg-8">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.75rem;">
                                        {{ currentClassification.badge }}
                                    </span>
                                    <span class="text-muted small">· {{ filteredRequirements.length }} Required Documents</span>
                                </div>
                                <h4 class="fw-bold text-dark mb-1">{{ currentClassification.name }} Document Checklist</h4>
                                <p class="text-muted small mb-0">{{ currentClassification.desc }}</p>
                            </div>
                            <div class="col-lg-4 text-lg-end">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" class="form-control border-start-0 ps-0" placeholder="Filter documents..." v-model="searchQuery">
                                    <button class="btn btn-outline-secondary" type="button" v-if="searchQuery" @click="searchQuery = ''"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Main Grid: Requirements Checklist & Readiness Meter ── -->
                    <div class="row g-4 mb-5">
                        
                        <!-- Left Column: Interactive Requirement Cards Grid (8 Cols) -->
                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div v-for="req in filteredRequirements" :key="req.id" class="col-md-6">
                                    <div class="adm-req-card" :class="{ 'is-checked': checkedReqs[req.id] }">
                                        <div class="d-flex align-items-start gap-3 mb-2.5">
                                            <div class="adm-req-icon-box">
                                                <i :class="req.icon"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                                                    <span class="adm-format-badge" :class="formatBadgeClass(req.format)">
                                                        <i class="fas fa-shield-alt" v-if="req.format.includes('ORIGINAL')"></i>
                                                        <i class="fas fa-copy" v-else></i>
                                                        {{ req.format }}
                                                    </span>
                                                </div>
                                                <h6 class="fw-bold text-dark mb-0" style="font-size: 0.92rem; line-height: 1.35;">
                                                    {{ req.title }}
                                                </h6>
                                            </div>
                                        </div>

                                        <p class="text-muted small mb-2" style="font-size: 0.8rem; line-height: 1.45;">
                                            {{ req.note }}
                                        </p>

                                        <div class="d-flex align-items-center justify-content-between text-muted small mb-2" style="font-size: 0.73rem;">
                                            <span><i class="fas fa-building-columns text-gold me-1"></i>Issuing: <strong>{{ req.agency }}</strong></span>
                                        </div>

                                        <!-- Interactive Checkmark Box -->
                                        <label class="adm-check-toggle" :class="{ 'checked': checkedReqs[req.id] }">
                                            <input type="checkbox" :checked="checkedReqs[req.id]" @change="toggleReq(req.id)">
                                            <span>{{ checkedReqs[req.id] ? 'Document Prepared ✓' : 'Mark as Prepared' }}</span>
                                        </label>
                                    </div>
                                </div>

                                <div v-if="filteredRequirements.length === 0" class="col-12 text-center py-5 bg-white rounded-3 border">
                                    <i class="fas fa-search fs-2 text-muted mb-2"></i>
                                    <h6 class="text-muted mb-0">No documents matched your search filter "{{ searchQuery }}".</h6>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Interactive Readiness Meter & Station Info (4 Cols) -->
                        <div class="col-lg-4">
                            
                            <!-- Readiness Meter Box -->
                            <div class="adm-readiness-box mb-4">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="text-uppercase fw-bold text-gold small letter-spacing-1">Document Readiness</span>
                                    <span class="badge bg-white text-dark fw-bold px-2 py-1">{{ checkedReqsCount }} / {{ totalReqsCount }} Prepared</span>
                                </div>
                                <h3 class="fw-bold text-white mb-0">{{ readinessPercentage }}% Ready</h3>
                                
                                <div class="adm-readiness-bar-bg">
                                    <div class="adm-readiness-bar-fill" :style="{ width: readinessPercentage + '%' }"></div>
                                </div>

                                <p class="small text-white text-opacity-85 mb-3" v-if="readinessPercentage === 100">
                                    🎉 Excellent! You have prepared all required credentials. You are ready to proceed with official Registrar validation.
                                </p>
                                <p class="small text-white text-opacity-85 mb-3" v-else-if="readinessPercentage >= 50">
                                    👍 Great progress! Keep preparing the remaining physical copies before visiting the campus Registrar.
                                </p>
                                <p class="small text-white text-opacity-85 mb-3" v-else>
                                    Check off documents as you assemble them to track your preparedness for enrollment day.
                                </p>

                                <div class="d-grid gap-2">
                                    <a :href="enrollNowUrl" class="btn btn-pill btn-pill-green shadow fw-bold py-2.5">
                                        <i class="fas fa-user-plus me-2"></i>START PRE-REGISTRATION
                                    </a>
                                    <button class="btn btn-pill btn-outline-light py-2 small" @click="printChecklist">
                                        <i class="fas fa-print me-2"></i>Print Checklist Slip
                                    </button>
                                </div>
                            </div>

                            <!-- Verification Stations Guide Card -->
                            <div class="content-card shadow-sm p-3.5 border-0 mb-4 bg-white rounded-3">
                                <h6 class="fw-bold text-dark mb-3 d-flex align-items-center">
                                    <i class="fas fa-map-location-dot text-success me-2"></i>
                                    Submission Station Guide
                                </h6>
                                <div class="d-flex flex-column gap-2.5">
                                    <div class="adm-station-card">
                                        <div class="adm-station-icon"><i class="fas fa-building-user"></i></div>
                                        <div>
                                            <h6 class="fw-bold text-dark mb-0 small">Office of the Registrar</h6>
                                            <span class="text-muted" style="font-size: 0.74rem;">Admin Hall · 8:00 AM – 5:00 PM</span>
                                        </div>
                                    </div>
                                    <div class="adm-station-card">
                                        <div class="adm-station-icon"><i class="fas fa-stethoscope"></i></div>
                                        <div>
                                            <h6 class="fw-bold text-dark mb-0 small">GNCP Campus Clinic</h6>
                                            <span class="text-muted" style="font-size: 0.74rem;">Health Pavilion · 8:30 AM – 4:30 PM</span>
                                        </div>
                                    </div>
                                    <div class="adm-station-card">
                                        <div class="adm-station-icon"><i class="fas fa-cash-register"></i></div>
                                        <div>
                                            <h6 class="fw-bold text-dark mb-0 small">Treasury &amp; Cashier</h6>
                                            <span class="text-muted" style="font-size: 0.74rem;">Ground Floor · 8:00 AM – 4:00 PM</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ── Campus Enrollment Roadmap Section ────────────── -->
                    <div class="mb-5 p-4 p-lg-5 bg-white rounded-4 border shadow-sm">
                        <div class="text-center mb-4">
                            <span class="section-tagline text-gold fw-bold text-uppercase">Step-by-Step Procedure</span>
                            <h3 class="fw-bold text-dark">Campus Enrollment Roadmap</h3>
                            <p class="text-muted small mx-auto" style="max-width: 600px;">
                                Follow our streamlined 6-stage sequential onboarding pipeline from online pre-registration to permanent student ID activation.
                            </p>
                        </div>

                        <div class="adm-flow-timeline">
                            <div v-for="step in enrollmentSteps" :key="step.step" class="adm-flow-step">
                                <div class="adm-flow-marker">
                                    <span>0{{ step.step }}</span>
                                </div>
                                <div class="adm-flow-content">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                                        <span class="adm-flow-badge">{{ step.station }} · {{ step.location }}</span>
                                        <span class="text-muted small fw-bold"><i :class="step.icon" class="text-gold me-1"></i>Station 0{{ step.step }}</span>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1.5" style="font-size: 1.05rem;">{{ step.title }}</h5>
                                    <p class="text-muted small mb-0">{{ step.desc }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Admissions FAQs Accordion ─────────────────────── -->
                    <div class="mb-5" style="max-width: 860px; margin: 0 auto;">
                        <div class="text-center mb-4">
                            <span class="section-tagline text-gold fw-bold text-uppercase">Got Questions?</span>
                            <h3 class="fw-bold text-dark">Frequently Asked Questions</h3>
                        </div>

                        <div class="d-flex flex-column gap-3">
                            <div v-for="(faq, idx) in faqs" :key="idx" 
                                 class="bg-white border rounded-3 p-3.5 shadow-sm transition-smooth"
                                 style="cursor: pointer;"
                                 @click="toggleFaq(idx)">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                        <i class="fas fa-circle-question text-success"></i>
                                        {{ faq.q }}
                                    </h6>
                                    <i class="fas" :class="activeFaq === idx ? 'fa-chevron-up text-success' : 'fa-chevron-down text-muted'"></i>
                                </div>
                                <div v-if="activeFaq === idx" class="mt-3 pt-3 border-top text-muted small" style="line-height: 1.55;">
                                    {{ faq.a }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Bottom Call-to-Action Bar ────────────────────── -->
                    <div class="text-center p-4 p-lg-5 rounded-4 shadow-sm border border-light-subtle position-relative overflow-hidden" 
                         style="background: linear-gradient(135deg, #003D2B 0%, #006A4E 100%); color: white;">
                        <div class="position-relative" style="z-index: 2;">
                            <span class="badge bg-gold text-dark text-uppercase fw-bold px-3 py-1.5 mb-2.5">Admissions Ongoing</span>
                            <h3 class="fw-bold text-white mb-2">Ready to Become a GNCP Patriot?</h3>
                            <p class="text-white text-opacity-85 mb-4 mx-auto" style="max-width: 600px;">
                                Begin your application online in under 3 minutes, or track the real-time clearance status of your submitted reference number.
                            </p>
                            <div class="d-flex justify-content-center gap-3 flex-wrap">
                                <a :href="enrollNowUrl" class="btn btn-pill btn-pill-green shadow px-4 py-3 fw-bold">
                                    <i class="fas fa-graduation-cap me-2"></i>START ONLINE PRE-REGISTRATION
                                </a>
                                <a href="../enrollment-system/tracker" class="btn btn-pill btn-outline-light px-4 py-3 fw-bold">
                                    <i class="fas fa-route me-2"></i>TRACK SUBMITTED APPLICATION
                                </a>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ══════════════════════════════════════════════════════════════
                     TUITION & FEES AND ESTIMATOR
                     ══════════════════════════════════════════════════════════════ -->
                <div v-else-if="subPage === 'admission-fees'" class="container py-5">
                    <div class="row g-5">
                        <div class="col-lg-6">
                            <!-- Fee Table -->
                            <div class="content-card shadow-sm p-4 mb-4 border-0">
                                <h3 class="fw-bold text-green mb-3">Collegiate Fee Schedule</h3>
                                <p class="text-muted mb-4">Standard base tuition rates and mandatory miscellaneous fees for the Academic Year 2026-2027.</p>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle">
                                        <thead class="table-green text-white">
                                            <tr>
                                                <th>Fee Description</th>
                                                <th class="text-end">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="fw-bold">Base Tuition (per unit)</td>
                                                <td class="text-end fw-bold text-green">₱{{ data.tuition.ratePerUnit }}.00</td>
                                            </tr>
                                            <tr v-for="fee in data.tuition.miscFees" :key="fee.name">
                                                <td>{{ fee.name }}</td>
                                                <td class="text-end text-muted">₱{{ fee.amount }}.00</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Tuition Calculator -->
                        <div class="col-lg-6">
                            <div class="content-card shadow-sm p-4 border-0">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fas fa-calculator text-gold fs-3 me-3"></i>
                                    <h3 class="fw-bold text-green mb-0">Collegiate Fee Estimator</h3>
                                </div>
                                <p class="text-muted small mb-4">Get an instant itemized breakdown of your academic tuition fees.</p>

                                <form @submit.prevent="calculateTuition">
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">College / Program</label>
                                            <select v-model="selectedProgram" class="form-select">
                                                <option value="it">BS Information Technology</option>
                                                <option value="business">BS Business Administration</option>
                                                <option value="education">Bachelor of Secondary Education</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Year Level</label>
                                            <select v-model="yearLevel" class="form-select">
                                                <option value="1">1st Year</option>
                                                <option value="2">2nd Year</option>
                                                <option value="3">3rd Year</option>
                                                <option value="4">4th Year</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Academic Units</label>
                                            <input type="number" v-model.number="unitsCount" min="3" max="26" class="form-select" />
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Laboratory Courses</label>
                                            <select v-model.number="labCourses" class="form-select">
                                                <option value="0">0 Labs</option>
                                                <option value="1">1 Lab (₱1,200)</option>
                                                <option value="2">2 Labs (₱2,400)</option>
                                                <option value="3">3 Labs (₱3,600)</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Preferred Payment Plan</label>
                                            <div class="d-flex gap-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" value="full" v-model="paymentPlan" id="planFull">
                                                    <label class="form-check-label" for="planFull">
                                                        Cash / Full Payment (5% Discount)
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" value="installment" v-model="paymentPlan" id="planInst">
                                                    <label class="form-check-label" for="planInst">
                                                        Installment Plan
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-pill btn-pill-green w-100 py-3 shadow mb-4">CALCULATE FEES</button>
                                </form>

                                <!-- Calculations Summary -->
                                <div v-if="calculationResult" class="p-4 bg-light rounded border border-light-subtle animate-fade-in">
                                    <h5 class="fw-bold text-green mb-3 border-bottom pb-2">Fee Calculation Details</h5>
                                    
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Tuition Fee ({{ unitsCount }} units × ₱450):</span>
                                        <span class="fw-semibold text-dark">₱{{ calculationResult.baseTuition.toLocaleString() }}.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2" v-if="labCourses > 0">
                                        <span class="text-muted">Laboratory Fee ({{ labCourses }} labs × ₱1,200):</span>
                                        <span class="fw-semibold text-dark">₱{{ calculationResult.labFees.toLocaleString() }}.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Miscellaneous Fees (Total):</span>
                                        <span class="fw-semibold text-dark">₱{{ calculationResult.miscFeesTotal.toLocaleString() }}.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2 text-success" v-if="calculationResult.discount > 0">
                                        <span class="fw-semibold">Cash Discount (5% on Tuition):</span>
                                        <span class="fw-bold">- ₱{{ calculationResult.discount.toLocaleString() }}.00</span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between border-top pt-3 mt-3">
                                        <h4 class="fw-bold text-green">Estimated Total:</h4>
                                        <h4 class="fw-bold text-green">₱{{ calculationResult.totalDue.toLocaleString() }}.00</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `
    },

    CampusLifePage: {
        props: ['subPage', 'data'],
        template: `
            <div class="subpage-wrapper">
                <!-- Page Banner Header -->
                <div class="subpage-banner text-center d-flex align-items-center justify-content-center">
                    <div class="container position-relative" style="z-index: 2;">
                        <span class="subpage-banner-tag text-gold text-uppercase fw-bold">Campus Environment</span>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-if="subPage === 'life-council'">Student Council</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'life-athletics'">Patriots Athletics</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'life-clubs'">Clubs & Organizations</h1>
                    </div>
                </div>

                <!-- Student Council Page -->
                <div v-if="subPage === 'life-council'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Student Voice</span>
                        <h2 class="section-title">Supreme Student Council</h2>
                        <p class="lead text-muted">{{ data.council.motto }}</p>
                    </div>

                    <div class="row g-5">
                        <!-- Projects -->
                        <div class="col-lg-6">
                            <h3 class="fw-bold text-green mb-4">Council Projects & Events</h3>
                            <div class="d-flex flex-column gap-3">
                                <div v-for="proj in data.council.projects" :key="proj.title" class="content-card shadow-sm p-4 border-0">
                                    <h5 class="fw-bold text-green mb-2">{{ proj.title }}</h5>
                                    <p class="text-muted mb-0">{{ proj.desc }}</p>
                                </div>
                            </div>
                        </div>
                        <!-- Officers -->
                        <div class="col-lg-6">
                            <h3 class="fw-bold text-green mb-4">Council Officers</h3>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle bg-white shadow-sm">
                                    <thead class="table-green text-white">
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>
                                            <th>Program</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="off in data.council.officers" :key="off.name">
                                            <td class="fw-semibold">{{ off.name }}</td>
                                            <td class="text-gold-dark font-monospace text-uppercase small">{{ off.role }}</td>
                                            <td class="text-muted small">{{ off.program }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Athletics Page -->
                <div v-else-if="subPage === 'life-athletics'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Collegiate Sports</span>
                        <h2 class="section-title">Patriots Athletics</h2>
                        <p class="lead text-muted">{{ data.athletics.description }}</p>
                    </div>

                    <div class="row g-4 align-items-center">
                        <div class="col-lg-6">
                            <div class="about-collage-container">
                                <div class="dot-grid-pattern dot-grid-top-left"></div>
                                <img src="https://images.unsplash.com/photo-1546519638-68e109498ffc?auto=format&fit=crop&w=800&q=80" alt="Varsity Team" class="collage-img-main rounded shadow">
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <h3 class="fw-bold text-green mb-4">Official Varsity Sports</h3>
                            <div class="d-flex flex-column gap-3">
                                <div v-for="sport in data.athletics.sports" :key="sport.name" class="content-card shadow-sm p-4 border-0">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h4 class="fw-bold text-green mb-0">{{ sport.name }}</h4>
                                        <span class="badge bg-gold-dark text-white px-3 py-2 small">{{ sport.status }}</span>
                                    </div>
                                    <p class="text-muted mb-0 small"><i class="fas fa-user-friends me-2"></i>{{ sport.coach }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Clubs Page -->
                <div v-else-if="subPage === 'life-clubs'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Student Activity</span>
                        <h2 class="section-title">Campus Clubs</h2>
                    </div>

                    <div class="row g-4">
                        <div v-for="club in data.clubs" :key="club.name" class="col-md-6">
                            <div class="content-card shadow-sm h-100 p-4 border-0">
                                <div class="card-icon-header bg-green-light mb-3">
                                    <i class="fas fa-users text-green fs-4"></i>
                                </div>
                                <h4 class="fw-bold text-green mb-2">{{ club.name }}</h4>
                                <p class="text-muted mb-0 leading-relaxed">{{ club.desc }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `
    },

    PaymentsPage: {
        props: ['subPage', 'data'],
        template: `
            <div class="subpage-wrapper">
                <!-- Page Banner Header -->
                <div class="subpage-banner text-center d-flex align-items-center justify-content-center">
                    <div class="container position-relative" style="z-index: 2;">
                        <span class="subpage-banner-tag text-gold text-uppercase fw-bold">Payments</span>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-if="subPage === 'payments-portals'">Payment Portals</h1>
                        <h1 class="subpage-banner-title text-white text-uppercase" v-else-if="subPage === 'payments-terms'">Payment Terms</h1>
                    </div>
                </div>

                <!-- Payment Portals Page -->
                <div v-if="subPage === 'payments-portals'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Tuition Remittance</span>
                        <h2 class="section-title">Official Payment Portals</h2>
                    </div>

                    <div class="row g-4">
                        <div v-for="portal in data.portals" :key="portal.name" class="col-md-4">
                            <div class="content-card shadow-sm h-100 p-4 border-0">
                                <div class="card-icon-header bg-gold-light mb-3">
                                    <i class="fas fa-credit-card text-gold-dark fs-4"></i>
                                </div>
                                <h4 class="fw-bold text-green mb-3">{{ portal.name }}</h4>
                                <ul class="list-group list-group-flush bg-transparent">
                                    <li v-for="channel in portal.channels" :key="channel" class="list-group-item bg-transparent px-0 border-light-subtle">
                                        <i class="fas fa-chevron-right text-gold me-2"></i> {{ channel }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Terms Page -->
                <div v-else-if="subPage === 'payments-terms'" class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Financial Schedule</span>
                        <h2 class="section-title">Collegiate Payment Terms</h2>
                    </div>

                    <div class="row g-4 justify-content-center">
                        <div v-for="term in data.terms" :key="term.plan" class="col-lg-4 col-md-6">
                            <div class="content-card shadow-sm h-100 p-4 border-0">
                                <h4 class="fw-bold text-green mb-3 border-bottom pb-2">{{ term.plan }}</h4>
                                <p class="text-muted leading-relaxed">{{ term.desc }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `
    },

    PortalsPage: {
        props: ['subPage'],
        template: `
            <div class="subpage-wrapper">
                <!-- Page Banner Header -->
                <div class="subpage-banner text-center d-flex align-items-center justify-content-center">
                    <div class="container position-relative" style="z-index: 2;">
                        <span class="subpage-banner-tag text-gold text-uppercase fw-bold">Online Services</span>
                        <h1 class="subpage-banner-title text-white text-uppercase">College Access Portals</h1>
                    </div>
                </div>

                <div class="container py-5">
                    <div class="text-center mb-5">
                        <span class="section-tagline">Student &amp; Staff Access</span>
                        <h2 class="section-title">Select Your Portal</h2>
                    </div>

                    <div class="row g-4 justify-content-center">
                        <!-- Student Portal Card -->
                        <div class="col-lg-5 col-md-6">
                            <div class="content-card shadow-sm h-100 p-4 border-0 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="card-icon-header bg-green-light mb-3">
                                        <i class="fas fa-user-graduate text-green fs-3"></i>
                                    </div>
                                    <h3 class="fw-bold text-green mb-2">Student Portal</h3>
                                    <p class="text-muted leading-relaxed mb-4" style="font-size: 0.95rem;">
                                        Sign in to view your Certificate of Registration (COR), class schedules, clearance status, and official fee breakdown.
                                    </p>
                                </div>
                                <a href="../student-portal/login" class="btn btn-pill btn-pill-green w-100 fw-bold py-3">
                                    <i class="fas fa-right-to-bracket me-2"></i>STUDENT PORTAL LOGIN
                                </a>
                            </div>
                        </div>

                        <!-- Application Tracker Card -->
                        <div class="col-lg-5 col-md-6">
                            <div class="content-card shadow-sm h-100 p-4 border-0 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="card-icon-header bg-gold-light mb-3">
                                        <i class="fas fa-search-location text-gold-dark fs-3"></i>
                                    </div>
                                    <h3 class="fw-bold text-green mb-2">Application Tracker</h3>
                                    <p class="text-muted leading-relaxed mb-4" style="font-size: 0.95rem;">
                                        Check the progress of your online pre-registration application using your reference code.
                                    </p>
                                </div>
                                <a href="../enrollment-system/tracker" class="btn btn-pill btn-pill-white border border-2 border-green text-green w-100 fw-bold py-3">
                                    <i class="fas fa-magnifying-glass me-2"></i>TRACK APPLICATION
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `
    }
};
