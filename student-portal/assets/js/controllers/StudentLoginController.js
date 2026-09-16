window.StudentLoginController = {
    setup() {
        const { ref, reactive, onMounted } = Vue;
        const isLoggingIn = ref(false);
        const loginError = ref('');
        const resetSuccessMsg = ref('');
        const sessionInvalidatedNotice = ref(false);
        const showPassword = ref(false);
        const rememberMe = ref(true);
        const hasSavedCredentials = ref(false);
        const loginForm = reactive({
            studentId: '',
            password: ''
        });
        const loadSavedCredentials = () => {
            try {
                localStorage.removeItem('gncp_saved_student_credentials');
                const savedId = localStorage.getItem('gncp_saved_student_id');
                if (savedId) {
                    loginForm.studentId = savedId;
                    hasSavedCredentials.value = true;
                    rememberMe.value = true;
                }
            } catch (e) {
                console.error('[StudentLogin::Auth] Error reading saved credentials:', e);
            }
        };
        const clearSavedCredentials = () => {
            loginForm.studentId = '';
            loginForm.password = '';
            localStorage.removeItem('gncp_saved_student_id');
            localStorage.removeItem('gncp_saved_student_credentials');
            hasSavedCredentials.value = false;
        };
        const checkActiveSession = () => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('clear') === 'true' || params.get('logout') === 'true') {
                sessionStorage.removeItem('gncp_portal_student');
                localStorage.removeItem('gncp_portal_student');
            }
            if (params.get('session_invalidated') === '1' || params.get('reason') === 'superseded') {
                sessionStorage.removeItem('gncp_portal_student');
                localStorage.removeItem('gncp_portal_student');
                sessionInvalidatedNotice.value = true;
                try {
                    const cleanParams = new URLSearchParams(window.location.search);
                    cleanParams.delete('session_invalidated');
                    cleanParams.delete('reason');
                    cleanParams.delete('clear');
                    const newQuery = cleanParams.toString();
                    const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
                    window.history.replaceState({}, document.title, newUrl);
                } catch (e) {}
                return;
            }
            if (params.get('reset') === 'success') {
                resetSuccessMsg.value = 'Your password has been reset successfully. Please sign in with your new credentials.';
                if (params.get('id')) {
                    loginForm.studentId = params.get('id');
                }
            }
            localStorage.removeItem('gncp_portal_student');
            const stored = sessionStorage.getItem('gncp_portal_student');
            if (stored) {
                try {
                    const student = JSON.parse(stored);
                    if (student && (student.id || student.studentId)) {
                        console.log('[StudentLogin::Auth] Active session found in tab. Redirecting to Student Portal...');
                        window.location.href = './';
                    }
                } catch (e) {
                    sessionStorage.removeItem('gncp_portal_student');
                }
            }
        };
        const handleLogin = async () => {
            if (!loginForm.studentId || !loginForm.password) {
                loginError.value = 'Please enter your Student ID and password.';
                return;
            }
            isLoggingIn.value = true;
            loginError.value = '';
            resetSuccessMsg.value = '';
            const res = await StudentApiService.login(loginForm.studentId, loginForm.password);
            isLoggingIn.value = false;
            if (res.success && res.data) {
                console.log('[StudentLogin::Auth] Login success. Redirecting to Student Portal...');
                sessionStorage.setItem('gncp_portal_student', JSON.stringify(res.data));
                if (rememberMe.value) {
                    localStorage.setItem('gncp_saved_student_id', loginForm.studentId);
                    hasSavedCredentials.value = true;
                } else {
                    localStorage.removeItem('gncp_saved_student_id');
                    hasSavedCredentials.value = false;
                }
                window.location.href = './';
            } else {
                loginError.value = res.message || 'Failed to authenticate student credentials.';
            }
        };
        checkActiveSession();
        loadSavedCredentials();
        onMounted(() => {
        });
        return {
            isLoggingIn,
            loginError,
            resetSuccessMsg,
            sessionInvalidatedNotice,
            loginForm,
            showPassword,
            rememberMe,
            hasSavedCredentials,
            clearSavedCredentials,
            handleLogin
        };
    }
};
