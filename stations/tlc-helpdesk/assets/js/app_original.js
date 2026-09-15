const { createApp, ref, computed } = Vue;

createApp({
    setup() {
        const currentView = ref('dashboard');
        const searchQuery = ref('');
        const activeFilter = ref('All');
        const selectedStudent = ref(null);

        const students = ref([
            {
                id: 1,
                referenceNumber: 'GNCP-2025-0001',
                name: 'Maria Santos',
                program: 'BSIT',
                studentType: 'Freshmen',
                nstp: 'CWTS',
                hasScholarship: false,
                scholarshipName: '',
                hohRequired: false,
                isWalkIn: false,
                status: 'PENDING',
                tlcNotes: '',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'IN_PROGRESS' },
                    { step: 2, title: 'Requirements Verification', status: 'PENDING' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 2,
                referenceNumber: 'GNCP-2025-0002',
                name: 'John dela Cruz',
                program: 'BSA',
                studentType: 'Transferee',
                nstp: 'ROTC',
                hasScholarship: true,
                scholarshipName: 'Academic Merit',
                hohRequired: false,
                isWalkIn: true,
                status: 'COMPLETED',
                tlcNotes: 'Scholarship documents verified.',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'COMPLETED' },
                    { step: 2, title: 'Requirements Verification', status: 'COMPLETED' },
                    { step: 3, title: 'Medical Check-up', status: 'IN_PROGRESS' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 3,
                referenceNumber: 'GNCP-2025-0003',
                name: 'Angela Reyes',
                program: 'BSCRIM',
                studentType: 'New Student',
                nstp: 'LTS',
                hasScholarship: false,
                scholarshipName: '',
                hohRequired: true,
                isWalkIn: false,
                status: 'PENDING',
                tlcNotes: '',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'IN_PROGRESS' },
                    { step: 2, title: 'Requirements Verification', status: 'PENDING' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 4,
                referenceNumber: 'GNCP-2025-0004',
                name: 'Rizalyn Cruz',
                program: 'BSED',
                studentType: 'Freshmen',
                nstp: 'CWTS',
                hasScholarship: true,
                scholarshipName: 'Leadership Grant',
                hohRequired: false,
                isWalkIn: false,
                status: 'FLAGGED',
                tlcNotes: 'Needs follow-up for missing document.',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'COMPLETED' },
                    { step: 2, title: 'Requirements Verification', status: 'IN_PROGRESS' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 5,
                referenceNumber: 'GNCP-2025-0005',
                name: 'Michael Tan',
                program: 'BSHM',
                studentType: 'Freshmen',
                nstp: 'ROTC',
                hasScholarship: false,
                scholarshipName: '',
                hohRequired: true,
                isWalkIn: true,
                status: 'PENDING',
                tlcNotes: '',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'IN_PROGRESS' },
                    { step: 2, title: 'Requirements Verification', status: 'PENDING' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 6,
                referenceNumber: 'GNCP-2025-0006',
                name: 'Lianne Gomez',
                program: 'BSIT',
                studentType: 'Transfer',
                nstp: 'LTS',
                hasScholarship: true,
                scholarshipName: 'Athletic Grant',
                hohRequired: false,
                isWalkIn: false,
                status: 'COMPLETED',
                tlcNotes: 'Walk-in completed successfully.',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'COMPLETED' },
                    { step: 2, title: 'Requirements Verification', status: 'COMPLETED' },
                    { step: 3, title: 'Medical Check-up', status: 'COMPLETED' },
                    { step: 4, title: 'Payment Processing', status: 'COMPLETED' },
                    { step: 5, title: 'Email & ID Capture', status: 'COMPLETED' }
                ]
            },
            {
                id: 7,
                referenceNumber: 'GNCP-2025-0007',
                name: 'Kevin Lopez',
                program: 'BSA',
                studentType: 'New Student',
                nstp: 'CWTS',
                hasScholarship: false,
                scholarshipName: '',
                hohRequired: false,
                isWalkIn: false,
                status: 'PENDING',
                tlcNotes: '',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'IN_PROGRESS' },
                    { step: 2, title: 'Requirements Verification', status: 'PENDING' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 8,
                referenceNumber: 'GNCP-2025-0008',
                name: 'Patricia Mercado',
                program: 'BSCRIM',
                studentType: 'Freshmen',
                nstp: 'ROTC',
                hasScholarship: true,
                scholarshipName: 'Provincial Scholarship',
                hohRequired: true,
                isWalkIn: false,
                status: 'PENDING',
                tlcNotes: 'Pending HOH requirements.',
                hohDocs: { barangayCert: true, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'IN_PROGRESS' },
                    { step: 2, title: 'Requirements Verification', status: 'PENDING' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            },
            {
                id: 9,
                referenceNumber: 'GNCP-2025-0009',
                name: 'Jerome Rivera',
                program: 'BSED',
                studentType: 'New Student',
                nstp: 'LTS',
                hasScholarship: false,
                scholarshipName: '',
                hohRequired: false,
                isWalkIn: true,
                status: 'COMPLETED',
                tlcNotes: 'Walk-in student cleared.',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'COMPLETED' },
                    { step: 2, title: 'Requirements Verification', status: 'COMPLETED' },
                    { step: 3, title: 'Medical Check-up', status: 'COMPLETED' },
                    { step: 4, title: 'Payment Processing', status: 'COMPLETED' },
                    { step: 5, title: 'Email & ID Capture', status: 'COMPLETED' }
                ]
            },
            {
                id: 10,
                referenceNumber: 'GNCP-2025-0010',
                name: 'Elaine Villanueva',
                program: 'BSHM',
                studentType: 'Transfer',
                nstp: 'CWTS',
                hasScholarship: false,
                scholarshipName: '',
                hohRequired: false,
                isWalkIn: false,
                status: 'PENDING',
                tlcNotes: 'Awaiting documentation.',
                hohDocs: { barangayCert: false, itr: false, guardianId: false },
                roadmap: [
                    { step: 1, title: 'TLC Help Desk', status: 'IN_PROGRESS' },
                    { step: 2, title: 'Requirements Verification', status: 'PENDING' },
                    { step: 3, title: 'Medical Check-up', status: 'PENDING' },
                    { step: 4, title: 'Payment Processing', status: 'PENDING' },
                    { step: 5, title: 'Email & ID Capture', status: 'PENDING' }
                ]
            }
        ]);

        const filteredStudents = computed(() => {
            const query = searchQuery.value.trim().toLowerCase();
            return students.value.filter(student => {
                const matchesQuery = !query || student.name.toLowerCase().includes(query) || student.referenceNumber.toLowerCase().includes(query);
                const matchesFilter =
                    activeFilter.value === 'All' ||
                    (activeFilter.value === 'Pending' && student.status === 'PENDING') ||
                    (activeFilter.value === 'Completed' && student.status === 'COMPLETED') ||
                    (activeFilter.value === 'Flagged' && student.status === 'FLAGGED');
                return matchesQuery && matchesFilter;
            });
        });

        const pendingCount = computed(() => students.value.filter(student => student.status === 'PENDING').length);
        const completedToday = computed(() => students.value.filter(student => student.status === 'COMPLETED').length);
        const scholarshipFlags = computed(() => students.value.filter(student => student.hasScholarship).length);
        const walkInCount = computed(() => students.value.filter(student => student.isWalkIn).length);

        const totalInQueue = computed(() => students.value.filter(student => student.status !== 'COMPLETED').length);

        const queueStudents = computed(() => students.value.filter(student => student.isWalkIn === false));
        const walkinStudents = computed(() => students.value.filter(student => student.isWalkIn));
        const scholarshipStudents = computed(() => students.value.filter(student => student.hasScholarship));

        const currentTitle = computed(() => {
            if (currentView.value === 'dashboard') return 'Dashboard';
            if (currentView.value === 'queue') return 'Student Queue';
            if (currentView.value === 'walkins') return 'Walk-ins';
            if (currentView.value === 'scholarships') return 'Scholarships';
            if (currentView.value === 'forms') return 'Forms & Documents';
            if (currentView.value === 'settings') return 'Settings';
            return '';
        });

        const setView = (view) => {
            currentView.value = view;
            if (view !== 'queue') {
                activeFilter.value = 'All';
                searchQuery.value = '';
            }
        };

        const openReview = (student) => {
            selectedStudent.value = student;
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
            modal.show();
        };

        const markCompleted = () => {
            if (!selectedStudent.value) return;
            selectedStudent.value.status = 'COMPLETED';
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
            modal.hide();
        };

        const flagFollowUp = () => {
            if (!selectedStudent.value) return;
            selectedStudent.value.status = 'FLAGGED';
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
            modal.hide();
        };

        const markAllCleared = () => {
            students.value.forEach(student => {
                if (student.status !== 'COMPLETED') {
                    student.status = 'COMPLETED';
                }
            });
        };

        const setFilter = (filter) => {
            activeFilter.value = filter;
        };

        return {
            currentView,
            currentTitle,
            searchQuery,
            activeFilter,
            selectedStudent,
            students,
            filteredStudents,
            pendingCount,
            completedToday,
            scholarshipFlags,
            walkInCount,
            totalInQueue,
            queueStudents,
            walkinStudents,
            scholarshipStudents,
            setView,
            openReview,
            markCompleted,
            flagFollowUp,
            markAllCleared,
            setFilter
        };
    }
}).mount('#app');
