(function() {
            try {
                localStorage.removeItem('gncp_portal_student');
                const params = new URLSearchParams(window.location.search);
                if (params.get('clear') === 'true' || params.get('logout') === 'true') {
                    sessionStorage.removeItem('gncp_portal_student');
                    return;
                }
                const stored = sessionStorage.getItem('gncp_portal_student');
                if (stored) {
                    const student = JSON.parse(stored);
                    if (student && (student.id || student.studentId)) {
                        window.location.replace('./');
                    }
                }
            } catch (e) {}
        })();
