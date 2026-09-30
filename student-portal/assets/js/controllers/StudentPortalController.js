/**
 * Student Portal - Authenticated Dashboard Controller
 * Glues Vue 3 reactive state, StudentModel helpers, and StudentApiService calls.
 * Enforces session validation and redirects unauthenticated requests to login.html.
 */

window.StudentPortalController = {
    setup() {
        const { ref, reactive, computed, watch, onMounted, onUnmounted } = Vue;

        // Session & Auth State
        const currentStudent = ref(null);

        // Navigation & Layout
        const activeTab = ref('announcements');
        const showLogoutConfirm = ref(false);
        const isMobileMenuOpen = ref(false);
        const photoInput = ref(null);
        const isUploadingPhoto = ref(false);
        const updateSuccessMsg = ref('');
        const avatarLoadError = ref(false);
        const onAvatarError = () => {
            avatarLoadError.value = true;
        };

        // Data State
        const state = reactive({
            ...StudentModel.createInitialState(),
            corData: null
        });

        const studentForm = reactive({
            phone: '',
            personalEmail: '',
            address: '',
            emergencyContactName: '',
            emergencyContactPhone: ''
        });

        // Password Change Form State
        const updatingPass = ref(false);
        const passError = ref('');
        const showCurrentPass = ref(false);
        const showNewPass = ref(false);
        const passStrengthLevel = ref(0);

        const pass = ref({
            current: '',
            newPass: '',
            confirm: ''
        });

        const passStrengthLabel = computed(() => {
            switch (passStrengthLevel.value) {
                case 1: return 'Weak';
                case 2: return 'Moderate';
                case 3: return 'Strong';
                default: return '';
            }
        });

        const passStrengthColor = computed(() => {
            switch (passStrengthLevel.value) {
                case 1: return '#dc2626';
                case 2: return '#d97706';
                case 3: return '#059669';
                default: return '#e4e4e7';
            }
        });

        const passStrengthWidth = computed(() => {
            switch (passStrengthLevel.value) {
                case 1: return '33%';
                case 2: return '66%';
                case 3: return '100%';
                default: return '0%';
            }
        });

        const checkPassStrength = () => {
            const val = pass.value.newPass;
            if (!val) {
                passStrengthLevel.value = 0;
                return;
            }
            let score = 0;
            if (val.length >= 6) score++;
            if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val) && val.length >= 8) score++;
            passStrengthLevel.value = Math.max(1, score);
        };

        // Time Greeting Computed
        const timeGreeting = computed(() => {
            const hour = new Date().getHours();
            if (hour < 12) return 'Great Morning';
            if (hour < 18) return 'Great Afternoon';
            return 'Great Evening';
        });

        // Tab Title Computed
        const currentTabTitle = computed(() => {
            switch (activeTab.value) {
                case 'announcements': return 'Campus Feed & Bulletins';
                case 'dashboard': return 'Dashboard Overview & COR';
                case 'courses': return 'My Enrolled Classes (COR)';
                case 'materials':
                case 'subjects':
                    if (currentSubject.value) {
                        return 'Subjects / ' + (currentSubject.value.code || 'Classroom');
                    }
                    return 'Enrolled Subjects';
                case 'documents': return 'Documents & Undertakings Hub';
                case 'profile': return 'My Student Profile';
                default: return 'Student Portal';
            }
        });

        // ── Subjects & LMS Classroom State (Google Classroom-Style Grid) ─────
        const currentSubject = ref(null);
        const subjectSearch = ref('');
        const subjectsLoadingError = ref('');
        const learningMaterials = ref([]);
        const isLoadingMaterials = ref(false);
        const materialsSearch = ref('');
        const selectedSubjectFilter = ref('ALL');
        const materialsViewMode = ref('grid'); // 'grid' | 'list'

        const academicThemes = [
            { id: 'navy', primary: '#1B3676', secondary: '#284B9B', accent: '#60A5FA', lightBg: '#eff6ff', icon: 'fa-book-bookmark' },
            { id: 'emerald', primary: '#006A4E', secondary: '#008B66', accent: '#34D399', lightBg: '#ecfdf5', icon: 'fa-laptop-code' },
            { id: 'amber', primary: '#854D0E', secondary: '#A16207', accent: '#FBBF24', lightBg: '#fffbeb', icon: 'fa-cubes' },
            { id: 'slate', primary: '#1E293B', secondary: '#334155', accent: '#94A3B8', lightBg: '#f8fafc', icon: 'fa-microchip' },
            { id: 'crimson', primary: '#7F1D1D', secondary: '#991B1B', accent: '#F87171', lightBg: '#fef2f2', icon: 'fa-graduation-cap' },
            { id: 'indigo', primary: '#312E81', secondary: '#3730A3', accent: '#818CF8', lightBg: '#eef2ff', icon: 'fa-network-wired' },
            { id: 'teal', primary: '#0F766E', secondary: '#115E59', accent: '#2DD4BF', lightBg: '#f0fdfa', icon: 'fa-atom' },
            { id: 'purple', primary: '#581C87', secondary: '#6B21A8', accent: '#C084FC', lightBg: '#faf5ff', icon: 'fa-brain' }
        ];

        const fetchLearningMaterials = async () => {
            isLoadingMaterials.value = true;
            try {
                const res = await fetch('../api/index.php?action=student_portal/materials');
                const json = await res.json();
                if (json.success && Array.isArray(json.data)) {
                    learningMaterials.value = json.data;
                } else {
                    learningMaterials.value = [];
                }
            } catch (e) {
                console.error('[StudentPortal] Failed to fetch learning materials:', e);
                learningMaterials.value = [];
            } finally {
                isLoadingMaterials.value = false;
            }
        };

        const downloadMaterial = (material) => {
            const fId = material?.file_id || material?.fileId;
            if (!fId) return;
            window.open('../api/index.php?action=student_portal/download_material&fileId=' + encodeURIComponent(fId), '_blank');
        };

        // Enrolled Subjects List: Maps authoritatively from official COR schedule or curriculum subjects
        const enrolledSubjectsList = computed(() => {
            let rawList = [];
            if (state.corData && Array.isArray(state.corData.schedule) && state.corData.schedule.length > 0) {
                rawList = state.corData.schedule;
            } else if (Array.isArray(state.subjects) && state.subjects.length > 0) {
                rawList = state.subjects;
            }

            const allMaterials = Array.isArray(learningMaterials.value) ? learningMaterials.value : [];
            const allAnnouncements = Array.isArray(announcements.value) ? announcements.value : [];

            return rawList.map((item, idx) => {
                const code = (item.code || item.subject_code || '').trim();
                const name = (item.description || item.title || item.name || code).trim();
                const section = (item.section || state.profile?.section || 'BSIT').trim();
                const instructor = (item.instructor || item.professor || 'Prof. Faculty').trim();
                const days = (item.days || '').trim();
                const start = (item.start || '').trim();
                const end = (item.end || '').trim();
                const timeStr = (start && end && start !== 'TBA') ? `${start} - ${end}` : (item.time || 'Schedule TBA');
                const room = (item.room || 'TBA').trim();
                const units = item.units ? item.units : '3.00';
                const type = item.type || 'Lec';

                // Stable academic theme deterministically based on subject code characters + index
                const charSum = code.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);
                const theme = academicThemes[Math.abs(charSum + idx) % academicThemes.length];

                // Clean instructor initials for classroom avatar badge
                const cleanInst = instructor.replace(/^(Prof\.|Dr\.|Engr\.|Mr\.|Ms\.|Mrs\.)\s*/i, '').trim();
                const parts = cleanInst.split(/\s+/).filter(Boolean);
                const initials = parts.length >= 2 
                    ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase() 
                    : (cleanInst.substring(0, 2).toUpperCase() || 'PR');

                // Subject-isolated materials
                const subjectMats = allMaterials.filter(m => {
                    const mCode = (m.subject_code || m.subjectCode || '').trim().toLowerCase();
                    return mCode === code.toLowerCase();
                });

                // Subject-isolated announcements
                const subjectAnns = allAnnouncements.filter(a => {
                    const aCode = (a.subject_code || a.subjectCode || '').trim().toLowerCase();
                    return aCode === code.toLowerCase();
                });

                // Latest Activity Preview: Priority -> Latest subject announcement, then latest material, else quiet state
                let latestActivity = null;
                if (subjectAnns.length > 0) {
                    const sorted = [...subjectAnns].sort((a, b) => {
                        const da = new Date(a.created_at || a.createdAt || 0).getTime() || 0;
                        const db = new Date(b.created_at || b.createdAt || 0).getTime() || 0;
                        return db - da;
                    });
                    const latest = sorted[0];
                    const rawDate = latest.created_at || latest.createdAt;
                    let dateStr = 'Recent';
                    if (rawDate) {
                        try {
                            dateStr = new Date(rawDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        } catch (e) {
                            dateStr = 'Recent';
                        }
                    }
                    latestActivity = {
                        type: 'announcement',
                        label: 'Latest announcement',
                        title: latest.title || 'Announcement',
                        preview: latest.content ? (latest.content.length > 55 ? latest.content.substring(0, 55) + '...' : latest.content) : '',
                        date: dateStr
                    };
                } else if (subjectMats.length > 0) {
                    const sorted = [...subjectMats].sort((a, b) => {
                        const da = new Date(a.published_at || a.publishedAt || 0).getTime() || 0;
                        const db = new Date(b.published_at || b.publishedAt || 0).getTime() || 0;
                        return db - da;
                    });
                    const latest = sorted[0];
                    const rawDate = latest.published_at || latest.publishedAt;
                    let dateStr = 'Recent';
                    if (rawDate) {
                        try {
                            dateStr = new Date(rawDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        } catch (e) {
                            dateStr = 'Recent';
                        }
                    }
                    latestActivity = {
                        type: 'material',
                        label: 'New material',
                        title: latest.title || latest.filename || 'Course Document',
                        preview: latest.filename || latest.description || '',
                        date: dateStr
                    };
                } else {
                    latestActivity = {
                        type: 'none',
                        label: 'Class status',
                        title: 'No announcements yet',
                        preview: 'Course is up to date',
                        date: null
                    };
                }

                return {
                    code,
                    name,
                    description: name,
                    section,
                    instructor,
                    initials,
                    days,
                    start,
                    end,
                    timeStr,
                    room,
                    units,
                    type,
                    theme,
                    materialsCount: subjectMats.length,
                    announcementsCount: subjectAnns.length,
                    materials: subjectMats,
                    announcements: subjectAnns,
                    latestActivity
                };
            });
        });

        // Filtered Subjects for search box
        const filteredSubjects = computed(() => {
            const list = enrolledSubjectsList.value;
            const q = (subjectSearch.value || '').trim().toLowerCase();
            if (!q) return list;
            return list.filter(s => 
                (s.code || '').toLowerCase().includes(q) ||
                (s.name || '').toLowerCase().includes(q) ||
                (s.instructor || '').toLowerCase().includes(q) ||
                (s.section || '').toLowerCase().includes(q) ||
                (s.room || '').toLowerCase().includes(q)
            );
        });

        // Open specific subject classroom view (with authorization check)
        const openSubject = (subjectOrCode) => {
            if (!subjectOrCode) return;
            let target = null;
            if (typeof subjectOrCode === 'string') {
                target = enrolledSubjectsList.value.find(s => 
                    (s.code || '').toLowerCase().trim() === subjectOrCode.toLowerCase().trim()
                );
            } else if (typeof subjectOrCode === 'object') {
                target = enrolledSubjectsList.value.find(s => 
                    (s.code || '').toLowerCase().trim() === (subjectOrCode.code || '').toLowerCase().trim()
                ) || subjectOrCode;
            }

            if (!target) {
                console.warn('[StudentPortal] Unauthorized or un-enrolled subject:', subjectOrCode);
                subjectsLoadingError.value = 'You are not enrolled in this subject or access is restricted.';
                return;
            }

            subjectsLoadingError.value = '';
            currentSubject.value = target;
            activeTab.value = 'subjects';
            materialsSearch.value = '';
            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (isMobileMenuOpen) isMobileMenuOpen.value = false;
        };

        const closeSubject = () => {
            currentSubject.value = null;
            subjectsLoadingError.value = '';
            materialsSearch.value = '';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        // Backward compatibility for viewSubjectMaterials(code)
        const viewSubjectMaterials = (subjectCode) => {
            if (!subjectCode) return;
            openSubject(subjectCode);
        };

        // Materials strictly isolated to the open classroom
        const currentSubjectMaterials = computed(() => {
            if (!currentSubject.value) return [];
            const targetCode = (currentSubject.value.code || '').toLowerCase().trim();
            const allMats = Array.isArray(learningMaterials.value) ? learningMaterials.value : [];
            let list = allMats.filter(m => {
                const c = (m.subject_code || m.subjectCode || '').toLowerCase().trim();
                return c === targetCode;
            });
            if (materialsSearch.value.trim()) {
                const q = materialsSearch.value.toLowerCase().trim();
                list = list.filter(m => 
                    (m.title || '').toLowerCase().includes(q) ||
                    (m.description || '').toLowerCase().includes(q) ||
                    (m.filename || '').toLowerCase().includes(q)
                );
            }
            return list;
        });

        // Announcements strictly isolated to the open classroom
        const currentSubjectAnnouncements = computed(() => {
            if (!currentSubject.value) return [];
            const targetCode = (currentSubject.value.code || '').toLowerCase().trim();
            const allAnns = Array.isArray(announcements.value) ? announcements.value : [];
            return allAnns.filter(a => {
                const c = (a.subject_code || a.subjectCode || '').toLowerCase().trim();
                return c === targetCode;
            });
        });

        const filteredMaterials = computed(() => {
            let list = learningMaterials.value;
            if (selectedSubjectFilter.value !== 'ALL') {
                const targetSub = selectedSubjectFilter.value.toLowerCase().trim();
                list = list.filter(m => {
                    const code = (m.subject_code || m.subjectCode || '').toLowerCase().trim();
                    return code === targetSub;
                });
            }
            if (materialsSearch.value.trim()) {
                const q = materialsSearch.value.toLowerCase().trim();
                list = list.filter(m => {
                    const title = (m.title || '').toLowerCase();
                    const desc = (m.description || '').toLowerCase();
                    const code = (m.subject_code || m.subjectCode || '').toLowerCase();
                    const prof = (m.professor_name || m.professorName || '').toLowerCase();
                    const fname = (m.filename || m.original_filename || '').toLowerCase();
                    return title.includes(q) || desc.includes(q) || code.includes(q) || prof.includes(q) || fname.includes(q);
                });
            }
            return list;
        });

        const studentSubjects = computed(() => {
            return enrolledSubjectsList.value.map(s => s.code);
        });

        const materialsStats = computed(() => {
            const list = Array.isArray(learningMaterials.value) ? learningMaterials.value : [];
            const total = list.length;
            const subjectsCount = Array.isArray(enrolledSubjectsList.value) ? enrolledSubjectsList.value.length : 0;
            let latest = null;
            if (list.length > 0) {
                const sorted = [...list].sort((a, b) => {
                    const da = new Date(a.published_at || a.publishedAt || 0).getTime() || 0;
                    const db = new Date(b.published_at || b.publishedAt || 0).getTime() || 0;
                    return db - da;
                });
                latest = sorted[0].published_at || sorted[0].publishedAt || null;
            }
            return {
                total,
                subjectsCount,
                latest: latest || null
            };
        });

        // ── Documents & Undertaking Management State ──────────────────────────
        const documentsData = reactive({
            requirements: [],
            pendingRequirements: [],
            completedRequirements: [],
            stats: {
                totalRequired: 0,
                verifiedCount: 0,
                pendingReviewCount: 0,
                undertakingCount: 0,
                missingCount: 0,
                actionableCount: 0,
                isFullyCompliant: false,
                isComplete: false
            },
            loading: false
        });

        const missingDocsCount = computed(() => {
            // Only count genuinely actionable required documents.
            // When all requirements are cleared or processed by registrar, count is 0.
            return documentsData.stats?.actionableCount !== undefined
                ? documentsData.stats.actionableCount
                : (documentsData.pendingRequirements?.length || 0);
        });

        const showCompletedDocs = ref(false);
        const toggleShowCompletedDocs = () => {
            showCompletedDocs.value = !showCompletedDocs.value;
        };

        const showUploadDocModal = ref(false);
        const selectedDocRequirement = ref(null);
        const docFileInput = ref(null);
        const isUploadingDoc = ref(false);
        const uploadDocError = ref('');
        const uploadDocSuccess = ref('');

        const docUploadForm = reactive({
            docKey: '',
            fileName: '',
            fileType: '',
            fileSize: 0,
            fileData: '',
            isUndertaking: false,
            undertakingReason: '',
            undertakingDeadline: ''
        });

        const previewDocModal = reactive({
            show: false,
            title: '',
            url: '',
            fileName: '',
            fileType: '',
            zoom: 100,
            rotation: 0
        });

        const loadStudentDocuments = async (forceRefresh = false) => {
            if (!currentStudent.value) return;
            const studentId = currentStudent.value.id || currentStudent.value.studentId || currentStudent.value.temp_reference_no;
            const pin = currentStudent.value.temp_pin || currentStudent.value.personalInfo?.temp_pin || '';
            const cacheKey = `student:documents:${studentId}`;

            const hasExisting = documentsData.requirements && documentsData.requirements.length > 0;
            if (!hasExisting && !(window.DataCache && window.DataCache.has(cacheKey))) {
                documentsData.loading = true;
            }

            const applyData = (data) => {
                if (!data) return;
                documentsData.requirements = data.requirements || [];
                documentsData.pendingRequirements = data.pendingRequirements || [];
                documentsData.completedRequirements = data.completedRequirements || [];
                documentsData.stats = data.stats || {
                    totalRequired: 0,
                    verifiedCount: 0,
                    pendingReviewCount: 0,
                    undertakingCount: 0,
                    missingCount: 0,
                    actionableCount: 0,
                    isFullyCompliant: false,
                    isComplete: false
                };
            };

            try {
                if (window.DataCache) {
                    const res = await window.DataCache.fetchWithCache(
                        cacheKey,
                        () => StudentApiService.fetchDocuments(studentId, pin),
                        {
                            staleTime: 30000,
                            forceRefresh,
                            onBackgroundUpdate: (freshRes) => {
                                if (freshRes && freshRes.success && freshRes.data) {
                                    applyData(freshRes.data);
                                }
                            }
                        }
                    );
                    if (res && res.success && res.data) {
                        applyData(res.data);
                    }
                } else {
                    const res = await StudentApiService.fetchDocuments(studentId, pin);
                    if (res && res.success && res.data) {
                        applyData(res.data);
                    }
                }
            } catch (e) {
                console.error('[StudentPortal::Documents] Failed to load document requirements:', e);
            } finally {
                documentsData.loading = false;
            }
        };

        watch(activeTab, (newTab) => {
            if (newTab === 'documents') {
                loadStudentDocuments(false);
            } else if (newTab === 'materials' || newTab === 'subjects') {
                fetchLearningMaterials();
            }
        });

        const openUploadDocModal = (doc = null) => {
            selectedDocRequirement.value = doc;
            docUploadForm.docKey = doc ? doc.key : (documentsData.pendingRequirements[0]?.key || documentsData.requirements[0]?.key || 'form_138');
            docUploadForm.fileName = '';
            docUploadForm.fileType = '';
            docUploadForm.fileSize = 0;
            docUploadForm.fileData = '';
            docUploadForm.isUndertaking = doc ? doc.isUndertaking : false;
            docUploadForm.undertakingReason = doc ? (doc.undertakingReason || '') : '';
            docUploadForm.undertakingDeadline = doc ? (doc.undertakingDeadline || '') : '';
            uploadDocError.value = '';
            uploadDocSuccess.value = '';
            showUploadDocModal.value = true;
        };

        const closeUploadDocModal = () => {
            showUploadDocModal.value = false;
            uploadDocError.value = '';
            uploadDocSuccess.value = '';
        };

        const triggerDocFileSelect = () => {
            if (docFileInput.value) {
                docFileInput.value.click();
            }
        };

        const handleDocFileSelect = (event) => {
            const file = event.target.files && event.target.files[0];
            if (!file) return;

            if (file.size > 10 * 1024 * 1024) {
                uploadDocError.value = 'File exceeds maximum 10MB limit. Please choose a smaller file.';
                return;
            }

            uploadDocError.value = '';
            docUploadForm.fileName = file.name;
            docUploadForm.fileType = file.type || 'application/octet-stream';
            docUploadForm.fileSize = file.size;

            const reader = new FileReader();
            reader.onload = (e) => {
                docUploadForm.fileData = e.target.result;
            };
            reader.onerror = () => {
                uploadDocError.value = 'Failed to read the selected file. Please try again.';
            };
            reader.readAsDataURL(file);
        };

        const submitDocumentUpload = async () => {
            if (!docUploadForm.docKey) {
                uploadDocError.value = 'Please select a document requirement.';
                return;
            }
            if (!docUploadForm.fileData && !docUploadForm.isUndertaking) {
                uploadDocError.value = 'Please choose a document file to upload, or specify undertaking details.';
                return;
            }

            isUploadingDoc.value = true;
            uploadDocError.value = '';
            uploadDocSuccess.value = '';

            const studentId = currentStudent.value.id || currentStudent.value.studentId || currentStudent.value.temp_reference_no;
            const pin = currentStudent.value.temp_pin || currentStudent.value.personalInfo?.temp_pin || '';
            const payload = {
                studentId,
                pin,
                docKey: docUploadForm.docKey,
                fileName: docUploadForm.fileName,
                fileType: docUploadForm.fileType,
                fileData: docUploadForm.fileData,
                isUndertaking: docUploadForm.isUndertaking,
                undertakingReason: docUploadForm.undertakingReason,
                undertakingDeadline: docUploadForm.undertakingDeadline
            };

            const res = await StudentApiService.uploadDocument(payload);
            isUploadingDoc.value = false;

            if (res.success) {
                uploadDocSuccess.value = res.message || 'Document uploaded successfully!';
                if (window.DataCache) {
                    const sId = currentStudent.value?.id || currentStudent.value?.studentId || currentStudent.value?.temp_reference_no;
                    window.DataCache.invalidate(`student:documents:${sId}`);
                    window.DataCache.invalidate(`student:dashboard:${sId}`);
                }
                await loadStudentDocuments(true);
                setTimeout(() => {
                    closeUploadDocModal();
                }, 1200);

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Document Submitted!',
                        text: 'Your document has been submitted for Registrar review.',
                        confirmButtonColor: '#006A4E',
                        timer: 2500
                    });
                }
            } else {
                uploadDocError.value = res.message || 'Failed to upload document. Please try again.';
            }
        };

        const getDocDownloadUrl = (url) => {
            if (!url) return '';
            const rawPath = typeof url === 'string' ? url : (url.filePath || url.softCopyUrl || url.url || '');
            if (!rawPath) return '';

            if (rawPath.startsWith('data:') || (/^https?:\/\//i.test(rawPath) && !rawPath.includes(window.location.host))) {
                return rawPath;
            }

            let basePath = '';
            if (typeof window !== 'undefined' && window.location && window.location.pathname) {
                const pathParts = window.location.pathname.split('/').filter(Boolean);
                const excludedFolders = ['api', 'stations', 'registrar', 'admin', 'student-portal', 'enrollment-system', 'shared', 'tests'];
                if (pathParts.length > 0 && !excludedFolders.includes(pathParts[0].toLowerCase())) {
                    basePath = '/' + pathParts[0];
                }
            }

            let cleanName = '';
            if (rawPath.includes('file=')) {
                const m = rawPath.match(/[?&]file=([^&#]+)/);
                if (m && m[1]) cleanName = decodeURIComponent(m[1]);
            } else if (rawPath.includes('path=')) {
                const m = rawPath.match(/[?&]path=([^&#]+)/);
                if (m && m[1]) cleanName = decodeURIComponent(m[1]);
            }

            if (!cleanName) {
                if (rawPath.includes('uploads/documents/')) {
                    cleanName = rawPath.split('uploads/documents/').pop().split('?')[0];
                } else if (rawPath.includes('uploads/docs/')) {
                    cleanName = rawPath.split('uploads/docs/').pop().split('?')[0];
                } else if (rawPath.includes('uploads/')) {
                    cleanName = rawPath.split('uploads/').pop().split('?')[0];
                } else {
                    cleanName = rawPath.split('/').pop().split('\\').pop().split('?')[0];
                }
            }

            cleanName = cleanName.replace(/^.*[\\\/]/, '').trim();

            if (cleanName && /\.(pdf|jpg|jpeg|png|webp|gif)$/i.test(cleanName)) {
                return `${basePath}/api/index.php?action=student/download_document&file=${encodeURIComponent(cleanName)}`;
            }

            if (rawPath.startsWith('/')) {
                return rawPath;
            }
            return `${basePath}/${rawPath.replace(/^\/+/, '')}`;
        };

        const openDocPreview = (doc) => {
            if (!doc || !doc.softCopyUrl) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'info',
                        title: 'No Document Uploaded',
                        text: 'No soft copy file has been uploaded for this requirement yet.',
                        confirmButtonColor: '#006A4E'
                    });
                }
                return;
            }

            const docUrl = getDocDownloadUrl(doc.softCopyUrl);
            previewDocModal.show = true;
            previewDocModal.title = doc.title;
            previewDocModal.url = docUrl;
            previewDocModal.fileName = doc.fileName || 'document';
            previewDocModal.fileType = doc.fileType || (docUrl.toLowerCase().includes('.pdf') ? 'application/pdf' : 'image/jpeg');
            previewDocModal.zoom = 100;
            previewDocModal.rotation = 0;
        };

        const closeDocPreview = () => {
            previewDocModal.show = false;
            previewDocModal.zoom = 100;
            previewDocModal.rotation = 0;
        };

        const zoomInDoc = () => {
            if (previewDocModal.zoom < 250) previewDocModal.zoom += 25;
        };
        const zoomOutDoc = () => {
            if (previewDocModal.zoom > 50) previewDocModal.zoom -= 25;
        };
        const resetDocZoom = () => {
            previewDocModal.zoom = 100;
            previewDocModal.rotation = 0;
        };
        const rotateDoc = () => {
            previewDocModal.rotation = (previewDocModal.rotation + 90) % 360;
        };

        const downloadDoc = (doc) => {
            if (!doc || !doc.softCopyUrl) return;
            const a = document.createElement('a');
            a.href = getDocDownloadUrl(doc.softCopyUrl);
            a.download = doc.fileName || (doc.key + '.pdf');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        };

        // Print Form Trigger
        const printForm = () => {
            window.print();
        };

        // Computed Enrolled Units
        const totalUnits = computed(() => {
            return StudentModel.calculateTotalUnits(state.helpdesk, state.subjects);
        });

        // Computed Assigned Class Blocks
        const totalAssignedSections = computed(() => {
            return StudentModel.calculateAssignedSectionsCount(
                state.helpdesk,
                state.subjects,
                state.enrollment,
                state.profile.sectionCode
            );
        });

        // Session Check with Auto-Redirect to login.html
        const checkSession = () => {
            localStorage.removeItem('gncp_portal_student');
            const stored = sessionStorage.getItem('gncp_portal_student');
            if (stored) {
                try {
                    currentStudent.value = JSON.parse(stored);
                    StudentModel.hydrateProfileFromSession(state.profile, currentStudent.value);
                    syncStudentFormFromProfile();
                    fetchDashboardData();
                    loadStudentDocuments();

                    if (currentStudent.value && currentStudent.value.must_change_password) {
                        if (typeof window.PasswordChangeGuard !== 'undefined') {
                            window.PasswordChangeGuard.checkAndPrompt(currentStudent.value, (changed) => {
                                if (changed) {
                                    currentStudent.value.must_change_password = false;
                                    state.profile.must_change_password = false;
                                }
                            });
                        }
                    }
                } catch (e) {
                    console.error('[StudentPortal::Session] Invalid stored session JSON:', e);
                    sessionStorage.removeItem('gncp_portal_student');
                    window.location.href = 'login';
                }
            } else {
                console.warn('[StudentPortal::Session] No active student session found. Redirecting to login...');
                window.location.href = 'login';
            }
        };

        const syncStudentFormFromProfile = () => {
            if (state.profile) {
                studentForm.phone = state.profile.phone || '';
                studentForm.personalEmail = state.profile.personalEmail || (state.profile.personalInfo && (state.profile.personalInfo.personalEmail || state.profile.personalInfo.email)) || '';
                studentForm.address = state.profile.address || '';
                studentForm.emergencyContactName = state.profile.emergencyContactName || (state.profile.personalInfo && state.profile.personalInfo.emergencyContactName) || '';
                studentForm.emergencyContactPhone = state.profile.emergencyContactPhone || (state.profile.personalInfo && state.profile.personalInfo.emergencyContactPhone) || '';
            }
        };

        const isRefreshing = ref(false);
        const isLoadingCor = ref(true);

        // Fetch Complete Dashboard Data
        const fetchDashboardData = async (isBackgroundPoll = false) => {
            if (!currentStudent.value) return;
            const studentId = currentStudent.value.id;
            const cacheKey = `student:dashboard:${studentId}`;

            const applyDashboardData = (data) => {
                if (!data) return;
                if (data.profile) {
                    StudentModel.hydrateProfileFromSession(state.profile, data.profile);
                    avatarLoadError.value = false;
                    syncStudentFormFromProfile();

                    if (data.profile.must_change_password && (!currentStudent.value || !currentStudent.value.must_change_password)) {
                        if (currentStudent.value) currentStudent.value.must_change_password = true;
                        if (typeof window.PasswordChangeGuard !== 'undefined') {
                            window.PasswordChangeGuard.checkAndPrompt(currentStudent.value || data.profile, (changed) => {
                                if (changed) {
                                    if (currentStudent.value) currentStudent.value.must_change_password = false;
                                    state.profile.must_change_password = false;
                                }
                            });
                        }
                    }
                }
                state.roadmap = data.roadmap || [];
                state.requirements = data.requirements || null;
                state.medical = data.medical || null;
                state.scholarship = data.scholarship || null;
                state.payment = data.payment || null;
                state.helpdesk = data.helpdesk || null;
                state.enrollment = data.enrollment || null;
                state.subjects = data.subjects || [];
                state.corData = data.corData || null;
                state.activePeriod = data.activePeriod || null;
                if (data.milestones && Array.isArray(data.milestones)) {
                    milestones.value = data.milestones;
                }
            };

            if (!isBackgroundPoll) {
                isRefreshing.value = true;
                const hasExisting = state.corData || (window.DataCache && window.DataCache.has(cacheKey));
                if (!hasExisting) {
                    isLoadingCor.value = true;
                }
            }

            try {
                if (window.DataCache) {
                    const res = await window.DataCache.fetchWithCache(
                        cacheKey,
                        () => StudentApiService.fetchDashboard(studentId),
                        {
                            staleTime: 25000,
                            forceRefresh: false,
                            onBackgroundUpdate: (freshRes) => {
                                if (freshRes && freshRes.success && freshRes.data) {
                                    applyDashboardData(freshRes.data);
                                }
                            }
                        }
                    );
                    if (res && res.success && res.data) {
                        applyDashboardData(res.data);
                        if (!isBackgroundPoll) {
                            console.log('[StudentPortal::Dashboard] Data loaded successfully.', state);
                        }
                    }
                } else {
                    const res = await StudentApiService.fetchDashboard(studentId);
                    if (res && res.success && res.data) {
                        applyDashboardData(res.data);
                    }
                }
            } catch (err) {
                console.error('[StudentPortal::Dashboard] Error fetching dashboard data:', err);
            } finally {
                isLoadingCor.value = false;
                if (!isBackgroundPoll) isRefreshing.value = false;
            }
        };

        // Photo Upload Handlers
        const triggerPhotoUpload = () => {
            if (photoInput.value) {
                photoInput.value.click();
            }
        };

        const handlePhotoSelect = async (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'File Too Large',
                        text: 'Profile photo size exceeds 5MB limit. Please select a smaller photo.',
                        confirmButtonColor: '#006A4E'
                    });
                } else {
                    alert('Profile photo size exceeds 5MB limit. Please select a smaller photo.');
                }
                if (e.target) e.target.value = '';
                return;
            }

            if (file.type && !file.type.startsWith('image/')) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid File',
                        text: 'Please select an image file (JPG, PNG, WebP).',
                        confirmButtonColor: '#006A4E'
                    });
                } else {
                    alert('Please select an image file (JPG, PNG, WebP).');
                }
                if (e.target) e.target.value = '';
                return;
            }

            isUploadingPhoto.value = true;
            const reader = new FileReader();
            reader.onload = async (evt) => {
                try {
                    const base64Data = evt.target.result;
                    const studentId = currentStudent.value?.id || state.profile?.id;
                    const res = await StudentApiService.uploadPhoto(studentId, base64Data);

                    if (res.success && res.data) {
                        const newPhoto = res.data.photo;
                        state.profile.photo = newPhoto;
                        avatarLoadError.value = false;
                        if (currentStudent.value) {
                            currentStudent.value.photo = newPhoto;
                            sessionStorage.setItem('gncp_portal_student', JSON.stringify(currentStudent.value));
                            localStorage.setItem('gncp_portal_student', JSON.stringify(currentStudent.value));
                        }
                        if (window.DataCache) {
                            window.DataCache.invalidate(`student:dashboard:${studentId}`);
                        }

                        updateSuccessMsg.value = 'Profile picture updated successfully!';
                        setTimeout(() => { updateSuccessMsg.value = ''; }, 3500);

                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Profile Picture Updated!',
                                text: 'Your new profile portrait has been uploaded and applied.',
                                timer: 2200,
                                showConfirmButton: false
                            });
                        }
                    } else {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Upload Failed',
                                text: res.message || 'Failed to upload photo.',
                                confirmButtonColor: '#006A4E'
                            });
                        } else {
                            alert(res.message || 'Failed to upload photo.');
                        }
                    }
                } catch (err) {
                    console.error('[StudentPortal] Photo upload exception:', err);
                } finally {
                    isUploadingPhoto.value = false;
                    if (e.target) e.target.value = '';
                }
            };
            reader.readAsDataURL(file);
        };

        const formattedProfilePhoto = (photo) => {
            if (!photo) return null;
            if (photo.startsWith('data:image') || photo.startsWith('http://') || photo.startsWith('https://')) return photo;
            if (photo.startsWith('../') || photo.startsWith('../../')) return photo;
            if (photo.startsWith('uploads/')) return '../' + photo;
            if (photo.startsWith('stations/')) return '../' + photo;
            if (photo.startsWith('/')) return '..' + photo;
            return '../uploads/avatars/' + photo.replace(/^\/+/, '');
        };

        const saving = ref(false);

        const saveStudentPersonalInfo = async () => {
            if (!currentStudent.value) return;
            saving.value = true;
            updateSuccessMsg.value = '';

            const payload = {
                phone: studentForm.phone,
                personalEmail: studentForm.personalEmail,
                email: studentForm.personalEmail,
                address: studentForm.address,
                emergencyContactName: studentForm.emergencyContactName,
                emergencyContactPhone: studentForm.emergencyContactPhone
            };

            const res = await StudentApiService.updateProfile(currentStudent.value.id, payload);
            saving.value = false;

            if (res.success && res.data) {
                StudentModel.hydrateProfileFromSession(state.profile, res.data);
                if (currentStudent.value) {
                    Object.assign(currentStudent.value, res.data);
                    sessionStorage.setItem('gncp_portal_student', JSON.stringify(currentStudent.value));
                    localStorage.setItem('gncp_portal_student', JSON.stringify(currentStudent.value));
                }
                if (window.DataCache) {
                    window.DataCache.invalidate(`student:dashboard:${currentStudent.value?.id}`);
                }
                updateSuccessMsg.value = 'Personal information updated successfully!';
                setTimeout(() => { updateSuccessMsg.value = ''; }, 3500);
            } else {
                alert(res.message || 'Failed to update profile information.');
            }
        };

        const updatePassword = async () => {
            passError.value = '';
            if (!pass.value.current || !pass.value.newPass || !pass.value.confirm) {
                passError.value = 'Please fill out all password fields.';
                return;
            }
            if (pass.value.newPass !== pass.value.confirm) {
                passError.value = 'New password and confirmation password do not match.';
                return;
            }
            if (pass.value.newPass.length < 6) {
                passError.value = 'New password must be at least 6 characters.';
                return;
            }

            updatingPass.value = true;
            const res = await StudentApiService.changePassword(
                currentStudent.value.id,
                pass.value.current,
                pass.value.newPass
            );
            updatingPass.value = false;

            if (res.success) {
                if (currentStudent.value) currentStudent.value.must_change_password = false;
                if (state.profile) state.profile.must_change_password = false;
                sessionStorage.setItem('gncp_portal_student', JSON.stringify(currentStudent.value));
                localStorage.setItem('gncp_portal_student', JSON.stringify(currentStudent.value));
                updateSuccessMsg.value = 'Password changed successfully.';
                pass.value.current = '';
                pass.value.newPass = '';
                pass.value.confirm = '';
                passStrengthLevel.value = 0;
                setTimeout(() => { updateSuccessMsg.value = ''; }, 4000);
            } else {
                passError.value = res.message || 'Failed to update password.';
            }
        };

        // Logout Handlers — Activates Branded Modal & Purges Session
        const handleLogout = () => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Are you sure?',
                    text: 'Are you sure you want to sign out of the Student Portal?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, sign out',
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

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Signing Out...',
                    text: 'Ending your student session...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }

            try {
                fetch('backend/api.php?action=logout', { method: 'POST', keepalive: true }).catch(() => {});
            } catch (e) {}

            currentStudent.value = null;
            sessionStorage.removeItem('gncp_portal_student');
            localStorage.removeItem('gncp_portal_student');
            window.location.replace('login?clear=true&logout=true');
        };

        // Announcements State & Methods
        const announcements = ref([]);
        const milestones = ref([]);
        const activeCategory = ref('ALL');
        const bulletinSearchQuery = ref('');
        const activeImageModal = ref('');
        const showHandbookModal = ref(false);

        // Persistent Acknowledgements & Bookmarks
        const acknowledgedMemos = ref(JSON.parse(localStorage.getItem('gncp_acknowledged_memos') || '[]'));
        const bookmarkedMemos = ref(JSON.parse(localStorage.getItem('gncp_bookmarked_memos') || '[]'));

        const toggleAcknowledgeMemo = (id) => {
            const idx = acknowledgedMemos.value.indexOf(id);
            if (idx === -1) {
                acknowledgedMemos.value.push(id);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Memorandum Acknowledged',
                        text: 'Your acknowledgement has been recorded.',
                        showConfirmButton: false,
                        timer: 2500
                    });
                }
            } else {
                acknowledgedMemos.value.splice(idx, 1);
            }
            localStorage.setItem('gncp_acknowledged_memos', JSON.stringify(acknowledgedMemos.value));
        };

        const isMemoAcknowledged = (id) => acknowledgedMemos.value.includes(id);

        const toggleBookmarkMemo = (id) => {
            const idx = bookmarkedMemos.value.indexOf(id);
            if (idx === -1) {
                bookmarkedMemos.value.push(id);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Saved to Reading List',
                        showConfirmButton: false,
                        timer: 2000
                    });
                }
            } else {
                bookmarkedMemos.value.splice(idx, 1);
            }
            localStorage.setItem('gncp_bookmarked_memos', JSON.stringify(bookmarkedMemos.value));
        };

        const isMemoBookmarked = (id) => bookmarkedMemos.value.includes(id);

        const copyMemoLink = (post) => {
            const refText = `GNCP Official Memorandum [Ref #${post.id}]: ${post.title} — Go-on National College of the Philippines`;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(refText).then(() => {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Reference Copied to Clipboard',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    }
                }).catch(() => {});
            }
        };

        const isOfficeOpen = computed(() => {
            const now = new Date();
            const day = now.getDay(); // 0 is Sunday, 6 is Saturday
            const hour = now.getHours();
            return (day >= 1 && day <= 5 && hour >= 8 && hour < 17);
        });

        const categoryCounts = computed(() => {
            const counts = {
                ALL: announcements.value.length,
                ACADEMIC: 0,
                ENROLLMENT: 0,
                EVENT: 0,
                MEDICAL: 0,
                FINANCIAL: 0,
                BOOKMARKED: 0
            };
            announcements.value.forEach(p => {
                const cat = (p.category || 'GENERAL').toUpperCase();
                if (counts[cat] !== undefined) counts[cat]++;
                if (bookmarkedMemos.value.includes(p.id)) counts.BOOKMARKED++;
            });
            return counts;
        });

        const fetchAnnouncements = async () => {
            try {
                const res = await fetch('../api/index.php?action=student_portal/announcements');
                const json = await res.json();
                if (json.success && Array.isArray(json.data)) {
                    announcements.value = json.data;
                } else {
                    const fb = await fetch('../api/index.php?action=announcements/list');
                    const fbJson = await fb.json();
                    announcements.value = (fbJson.success && Array.isArray(fbJson.data)) ? fbJson.data : [];
                }
            } catch (e) {
                console.error('[StudentPortal] Failed to fetch announcements:', e);
                announcements.value = [];
            }
        };

        const filteredAnnouncements = computed(() => {
            let list = announcements.value;
            if (activeCategory.value === 'BOOKMARKED') {
                list = list.filter(p => bookmarkedMemos.value.includes(p.id));
            } else if (activeCategory.value !== 'ALL') {
                list = list.filter(p => p.category === activeCategory.value);
            }
            if (bulletinSearchQuery.value.trim()) {
                const q = bulletinSearchQuery.value.toLowerCase().trim();
                list = list.filter(p =>
                    (p.title && p.title.toLowerCase().includes(q)) ||
                    (p.content && p.content.toLowerCase().includes(q)) ||
                    (p.author_name && p.author_name.toLowerCase().includes(q)) ||
                    (p.category && p.category.toLowerCase().includes(q))
                );
            }
            return list;
        });

        const fetchMilestones = async () => {
            try {
                const res = await fetch('../api/index.php?action=milestones/list');
                const json = await res.json();
                if (json.success && Array.isArray(json.data)) {
                    milestones.value = json.data;
                }
            } catch (e) {
                console.error('[StudentPortal] Failed to fetch milestones:', e);
            }
        };

        const formatTimeAgo = (dateStr) => {
            if (!dateStr) return 'Recently';
            const date = new Date(dateStr);
            const now = new Date();
            const diffSec = Math.floor((now - date) / 1000);
            if (diffSec < 60) return 'Just now';
            if (diffSec < 3600) return Math.floor(diffSec / 60) + 'm ago';
            if (diffSec < 86400) return Math.floor(diffSec / 3600) + 'h ago';
            if (diffSec < 604800) return Math.floor(diffSec / 86400) + 'd ago';
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        };

        const formatDateRange = (startStr, endStr) => {
            if (!startStr || !endStr) return 'Schedule according to academic calendar';
            try {
                const s = new Date(startStr);
                const e = new Date(endStr);
                return s.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + e.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            } catch (err) {
                return `${startStr} – ${endStr}`;
            }
        };

        let pollTimer = null;
        const startLiveSync = () => {
            stopLiveSync();
            pollTimer = setInterval(() => {
                fetchDashboardData(true);
                fetchAnnouncements();
                fetchMilestones();
                fetchLearningMaterials();
            }, 10000);
        };

        const stopLiveSync = () => {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        };

        onMounted(() => {
            checkSession();
            fetchAnnouncements();
            fetchMilestones();
            fetchLearningMaterials();
            startLiveSync();
        });

        onUnmounted(() => {
            stopLiveSync();
        });

        // Forgot Password Modal State
        const showForgotPasswordModal = ref(false);
        const forgotPasswordStep = ref(1);
        const forgotIdentifier = ref('');
        const forgotCode = ref('');
        const forgotNewPassword = ref('');
        const forgotConfirmPassword = ref('');
        const forgotShowNewPass = ref(false);
        const isRequestingCode = ref(false);
        const isResettingPass = ref(false);
        const forgotMsg = ref('');
        const forgotError = ref('');
        const maskedEmailSent = ref('');

        const closeForgotPasswordModal = () => {
            showForgotPasswordModal.value = false;
            forgotError.value = '';
            forgotMsg.value = '';
            forgotCode.value = '';
            forgotNewPassword.value = '';
            forgotConfirmPassword.value = '';
        };

        const handleRequestResetCode = async () => {
            if (!forgotIdentifier.value) return;
            isRequestingCode.value = true;
            forgotError.value = '';
            forgotMsg.value = '';
            try {
                const res = await fetch('../api/index.php?action=student/request_password_reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ identifier: forgotIdentifier.value })
                });
                const data = await res.json();
                if (data.success) {
                    forgotPasswordStep.value = 2;
                    forgotMsg.value = data.message || 'Verification code sent to your email.';
                    maskedEmailSent.value = data.maskedEmail || '';
                } else {
                    forgotError.value = data.message || 'Account not found.';
                }
            } catch (e) {
                forgotError.value = 'Failed to send reset code. Please try again.';
            } finally {
                isRequestingCode.value = false;
            }
        };

        const handleVerifyAndResetPassword = async () => {
            if (forgotNewPassword.value !== forgotConfirmPassword.value) {
                forgotError.value = 'Passwords do not match.';
                return;
            }
            isResettingPass.value = true;
            forgotError.value = '';
            forgotMsg.value = '';
            try {
                const res = await fetch('../api/index.php?action=student/verify_password_reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        identifier: forgotIdentifier.value,
                        code: forgotCode.value,
                        newPassword: forgotNewPassword.value
                    })
                });
                const data = await res.json();
                if (data.success) {
                    forgotMsg.value = 'Password reset successfully!';
                    setTimeout(() => {
                        closeForgotPasswordModal();
                    }, 1500);
                } else {
                    forgotError.value = data.message || 'Invalid verification code.';
                }
            } catch (e) {
                forgotError.value = 'Failed to reset password. Please try again.';
            } finally {
                isResettingPass.value = false;
            }
        };

        return {
            currentStudent,
            profile: state.profile,
            roadmap: computed(() => state.roadmap),
            requirements: computed(() => state.requirements),
            medical: computed(() => state.medical),
            scholarship: computed(() => state.scholarship),
            payment: computed(() => state.payment),
            studentFinancials: computed(() => state.payment),
            helpdesk: computed(() => state.helpdesk),
            enrollment: computed(() => state.enrollment),
            subjects: computed(() => state.subjects),
            enrolledSubjects: computed(() => state.subjects),
            enrolledUnits: computed(() => totalUnits.value),
            corData: computed(() => state.corData),
            activePeriod: computed(() => state.activePeriod),
            state,
            activeTab,
            currentTabTitle,
            totalUnits,
            totalAssignedSections,
            timeGreeting,
            isRefreshing,
            isLoadingCor,
            fetchDashboardData,
            printForm,
            handleLogout,
            showLogoutConfirm,
            confirmLogout,
            formatCurrency: StudentModel.formatCurrency,
            photoInput,
            isUploadingPhoto,
            updateSuccessMsg,
            triggerPhotoUpload,
            handlePhotoSelect,
            isMobileMenuOpen,
            studentForm,
            saving,
            updatingPass,
            pass,
            showCurrentPass,
            showNewPass,
            passStrengthLabel,
            passStrengthColor,
            passStrengthWidth,
            checkPassStrength,
            saveStudentPersonalInfo,
            updatePassword,
            passError,

            // Forgot Password Modal State & Handlers
            showForgotPasswordModal,
            forgotPasswordStep,
            forgotIdentifier,
            forgotCode,
            forgotNewPassword,
            forgotConfirmPassword,
            forgotShowNewPass,
            isRequestingCode,
            isResettingPass,
            forgotMsg,
            forgotError,
            maskedEmailSent,
            closeForgotPasswordModal,
            handleRequestResetCode,
            handleVerifyAndResetPassword,

            // Announcements & Campus Bulletins & Milestones
            announcements,
            milestones,
            filteredAnnouncements,
            activeCategory,
            bulletinSearchQuery,
            activeImageModal,
            showHandbookModal,
            acknowledgedMemos,
            bookmarkedMemos,
            toggleAcknowledgeMemo,
            isMemoAcknowledged,
            toggleBookmarkMemo,
            isMemoBookmarked,
            copyMemoLink,
            isOfficeOpen,
            categoryCounts,
            fetchAnnouncements,
            fetchMilestones,
            formatTimeAgo,
            formatDateRange,
            formattedProfilePhoto,
            avatarLoadError,
            onAvatarError,

            // Documents & Undertakings Hub
            documentsData,
            missingDocsCount,
            showCompletedDocs,
            toggleShowCompletedDocs,
            showUploadDocModal,
            selectedDocRequirement,
            docFileInput,
            isUploadingDoc,
            uploadDocError,
            uploadDocSuccess,
            docUploadForm,
            previewDocModal,
            loadStudentDocuments,
            openUploadDocModal,
            closeUploadDocModal,
            triggerDocFileSelect,
            handleDocFileSelect,
            submitDocumentUpload,
            openDocPreview,
            closeDocPreview,
            zoomInDoc,
            zoomOutDoc,
            resetDocZoom,
            rotateDoc,
            downloadDoc,

            // Subjects & LMS Classroom Hub
            currentSubject,
            subjectSearch,
            subjectsLoadingError,
            enrolledSubjectsList,
            filteredSubjects,
            currentSubjectMaterials,
            currentSubjectAnnouncements,
            openSubject,
            closeSubject,
            learningMaterials,
            isLoadingMaterials,
            materialsSearch,
            selectedSubjectFilter,
            materialsViewMode,
            viewSubjectMaterials,
            materialsStats,
            filteredMaterials,
            studentSubjects,
            fetchLearningMaterials,
            downloadMaterial
        };
    }
};
