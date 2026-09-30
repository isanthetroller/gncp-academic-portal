/**
 * GNCP Faculty Professor Workstation — Vue 3 Application Controller
 * Handles teaching schedules, handled sections/subjects, Personal File Drive,
 * and LMS Material / Announcement publishing.
 */

const { createApp, ref, computed, onMounted } = Vue;

const app = createApp({
    components: {
        'employee-sidebar': EmployeeSidebar
    },
    setup() {
        const currentUser = ref(null);
        const professorProfile = ref(null);
        const currentView = ref('dashboard');
        const isMobileMenuOpen = ref(false);
        const isLoading = ref(false);

        // Data Models
        const sections = ref([]);
        const subjects = ref([]);
        const weeklySchedule = ref({});
        const driveFiles = ref([]);
        const publishedMaterials = ref([]);
        const announcements = ref([]);

        // Upload File State
        const isUploadModalOpen = ref(false);
        const selectedFile = ref(null);
        const isUploading = ref(false);

        // Publish Material State
        const isPublishModalOpen = ref(false);
        const publishForm = ref({
            fileId: null,
            title: '',
            description: '',
            subjectCode: '',
            sections: []
        });
        const isPublishing = ref(false);

        // Create Announcement State
        const isAnnModalOpen = ref(false);
        const annForm = ref({
            title: '',
            content: '',
            sections: []
        });
        const isPublishingAnn = ref(false);

        // View Section Students Modal State
        const isRosterModalOpen = ref(false);
        const activeRosterSection = ref(null);

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
                dashboard: 'Faculty Dashboard & Teaching Overview',
                my_sections: 'My Assigned Class Sections',
                my_subjects: 'My Handled Subjects & Course Units',
                schedule_grid: 'Master Weekly Class Timetable',
                personal_drive: 'My Personal File Drive',
                published_materials: 'Published LMS Learning Materials',
                announcements: 'Section-Targeted Announcements',
                profile: 'My Faculty Profile'
            };
            return titles[currentView.value] || 'Faculty Portal';
        });

        // Initialize Authentication & Load Profile
        const initData = async () => {
            isLoading.value = true;
            try {
                const authRes = await fetch('../../api/index.php?action=auth/check', { credentials: 'include' });
                const authData = await authRes.json();
                if (!authData.success || !authData.data) {
                    window.location.href = '../../index.html';
                    return;
                }
                currentUser.value = authData.data;

                // Load Professor Profile
                const profRes = await fetch('../../api/index.php?action=professor/profile', { credentials: 'include' });
                const profData = await profRes.json();
                if (profData.success) {
                    professorProfile.value = profData.data;
                }

                // Load initial views
                loadSections();
                loadSubjects();
                loadSchedule();
                loadDriveFiles();
                loadPublishedMaterials();
                loadAnnouncements();
            } catch (err) {
                console.error('Initialization error:', err);
                showToast('Failed to load faculty account data.', 'error');
            } finally {
                isLoading.value = false;
            }
        };

        const loadSections = async () => {
            try {
                const res = await fetch('../../api/index.php?action=professor/sections', { credentials: 'include' });
                const data = await res.json();
                if (data.success) sections.value = data.data;
            } catch (err) { console.error('Error loading sections:', err); }
        };

        const loadSubjects = async () => {
            try {
                const res = await fetch('../../api/index.php?action=professor/subjects', { credentials: 'include' });
                const data = await res.json();
                if (data.success) subjects.value = data.data;
            } catch (err) { console.error('Error loading subjects:', err); }
        };

        const loadSchedule = async () => {
            try {
                const res = await fetch('../../api/index.php?action=professor/schedule', { credentials: 'include' });
                const data = await res.json();
                if (data.success) weeklySchedule.value = data.data;
            } catch (err) { console.error('Error loading schedule:', err); }
        };

        const loadDriveFiles = async () => {
            try {
                const res = await fetch('../../api/index.php?action=professor/files', { credentials: 'include' });
                const data = await res.json();
                if (data.success) driveFiles.value = data.data;
            } catch (err) { console.error('Error loading drive files:', err); }
        };

        const loadPublishedMaterials = async () => {
            try {
                const res = await fetch('../../api/index.php?action=professor/materials', { credentials: 'include' });
                const data = await res.json();
                if (data.success) publishedMaterials.value = data.data;
            } catch (err) { console.error('Error loading materials:', err); }
        };

        const loadAnnouncements = async () => {
            try {
                const res = await fetch('../../api/index.php?action=professor/announcements', { credentials: 'include' });
                const data = await res.json();
                if (data.success) announcements.value = data.data;
            } catch (err) { console.error('Error loading announcements:', err); }
        };

        // File Drive Upload
        const onFileSelect = (e) => {
            selectedFile.value = e.target.files[0] || null;
        };

        const uploadFileToDrive = async () => {
            if (!selectedFile.value) {
                showToast('Please select a file to upload.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('file', selectedFile.value);

            isUploading.value = true;
            try {
                const res = await fetch('../../api/index.php?action=professor/upload_file', {
                    method: 'POST',
                    credentials: 'include',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showToast('File stored in personal drive.', 'success');
                    isUploadModalOpen.value = false;
                    selectedFile.value = null;
                    loadDriveFiles();
                    if (professorProfile.value) professorProfile.value.totalFiles += 1;
                } else {
                    Swal.fire({
                        title: 'Upload Rejected',
                        text: data.message,
                        icon: 'error',
                        confirmButtonColor: '#006A4E'
                    });
                }
            } catch (err) {
                console.error('File upload error:', err);
                showToast('Upload failed.', 'error');
            } finally {
                isUploading.value = false;
            }
        };

        const deleteDriveFile = async (file) => {
            const conf = await Swal.fire({
                title: 'Delete from Drive?',
                text: `Are you sure you want to permanently delete '${file.filename}'? Published LMS references will also be removed.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                confirmButtonColor: '#dc2626'
            });

            if (!conf.isConfirmed) return;

            try {
                const res = await fetch('../../api/index.php?action=professor/delete_file', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ fileId: file.id })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('File deleted successfully.', 'success');
                    loadDriveFiles();
                    loadPublishedMaterials();
                } else {
                    showToast(data.message || 'Delete failed.', 'error');
                }
            } catch (err) {
                console.error('Delete error:', err);
            }
        };

        // Publish Material to LMS
        const openPublishModal = (file) => {
            publishForm.value = {
                fileId: file.id,
                title: file.filename.replace(/\.[^/.]+$/, ""),
                description: '',
                subjectCode: subjects.value.length > 0 ? subjects.value[0].code : '',
                sections: sections.value.map(s => s.sectionCode)
            };
            isPublishModalOpen.value = true;
        };

        const submitPublishMaterial = async () => {
            if (!publishForm.value.title || publishForm.value.sections.length === 0) {
                showToast('Title and at least one target section are required.', 'error');
                return;
            }

            isPublishing.value = true;
            try {
                const res = await fetch('../../api/index.php?action=professor/publish_material', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(publishForm.value)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Material published to section LMS.', 'success');
                    isPublishModalOpen.value = false;
                    loadPublishedMaterials();
                    loadDriveFiles();
                } else {
                    Swal.fire({ title: 'Publishing Error', text: data.message, icon: 'error', confirmButtonColor: '#006A4E' });
                }
            } catch (err) {
                console.error('Publish error:', err);
                showToast('Failed to publish material.', 'error');
            } finally {
                isPublishing.value = false;
            }
        };

        const unpublishMaterial = async (mat) => {
            const conf = await Swal.fire({
                title: 'Unpublish Material?',
                text: `Remove '${mat.title}' from student portals? The original file will remain in your personal drive.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Unpublish',
                confirmButtonColor: '#dc2626'
            });

            if (!conf.isConfirmed) return;

            try {
                const res = await fetch('../../api/index.php?action=professor/unpublish_material', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ materialId: mat.id })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Material removed from student portals.', 'success');
                    loadPublishedMaterials();
                    loadDriveFiles();
                }
            } catch (err) { console.error('Unpublish error:', err); }
        };

        // Announcements
        const openAnnModal = () => {
            annForm.value = {
                title: '',
                content: '',
                subjectCode: subjects.value.length > 0 ? subjects.value[0].code : '',
                sections: sections.value.map(s => s.sectionCode)
            };
            isAnnModalOpen.value = true;
        };

        const submitAnnouncement = async () => {
            if (!annForm.value.title || !annForm.value.content || annForm.value.sections.length === 0) {
                showToast('Title, content, and at least one target section are required.', 'error');
                return;
            }

            isPublishingAnn.value = true;
            try {
                const res = await fetch('../../api/index.php?action=professor/create_announcement', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(annForm.value)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Announcement posted to student section(s).', 'success');
                    isAnnModalOpen.value = false;
                    loadAnnouncements();
                } else {
                    Swal.fire({ title: 'Post Error', text: data.message, icon: 'error', confirmButtonColor: '#006A4E' });
                }
            } catch (err) {
                console.error('Announcement post error:', err);
                showToast('Failed to post announcement.', 'error');
            } finally {
                isPublishingAnn.value = false;
            }
        };

        const deleteAnnouncement = async (ann) => {
            const conf = await Swal.fire({
                title: 'Delete Announcement?',
                text: 'Remove this announcement from student bulletin boards?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                confirmButtonColor: '#dc2626'
            });

            if (!conf.isConfirmed) return;

            try {
                const res = await fetch('../../api/index.php?action=professor/delete_announcement', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ announcementId: ann.id })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Announcement deleted.', 'success');
                    loadAnnouncements();
                }
            } catch (err) { console.error('Delete announcement error:', err); }
        };

        const setView = (view) => {
            currentView.value = view;
        };

        const handleLogout = async () => {
            const res = await Swal.fire({
                title: 'Sign Out?',
                text: 'Are you sure you want to end your faculty session?',
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
            initData();
        });

        return {
            currentUser,
            professorProfile,
            currentView,
            isMobileMenuOpen,
            isLoading,
            greeting,
            currentTitle,
            sections,
            subjects,
            weeklySchedule,
            driveFiles,
            publishedMaterials,
            announcements,
            isUploadModalOpen,
            selectedFile,
            isUploading,
            onFileSelect,
            uploadFileToDrive,
            deleteDriveFile,
            isPublishModalOpen,
            publishForm,
            isPublishing,
            openPublishModal,
            submitPublishMaterial,
            unpublishMaterial,
            isAnnModalOpen,
            annForm,
            isPublishingAnn,
            openAnnModal,
            submitAnnouncement,
            deleteAnnouncement,
            toast,
            showToast,
            setView,
            handleLogout
        };
    }
});

app.mount('#app');
