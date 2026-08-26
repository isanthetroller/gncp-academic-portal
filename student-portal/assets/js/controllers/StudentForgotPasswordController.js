window.StudentForgotPasswordController = {
    setup() {
        const { ref, reactive, onMounted } = Vue;
        const currentStep = ref(1); 
        const identifier = ref('');
        const verificationCode = ref('');
        const newPassword = ref('');
        const confirmPassword = ref('');
        const showNewPass = ref(false);
        const showConfirmPass = ref(false);
        const isSubmitting = ref(false);
        const errorMessage = ref('');
        const successMessage = ref('');
        const maskedEmail = ref('');
        const studentName = ref('');
        const handleRequestCode = async () => {
            if (!identifier.value.trim()) {
                errorMessage.value = 'Please enter your Student ID or registered Email Address.';
                return;
            }
            isSubmitting.value = true;
            errorMessage.value = '';
            successMessage.value = '';
            try {
                const res = await StudentApiService.requestPasswordReset(identifier.value.trim());
                isSubmitting.value = false;
                if (!res.success) {
                    errorMessage.value = res.message || 'No account found matching that Student ID or Email address.';
                    return;
                }
                maskedEmail.value = res.data?.maskedEmail || 'your email';
                studentName.value = res.data?.studentName || '';
                successMessage.value = `A 6-digit verification code has been sent to ${maskedEmail.value}.`;
                currentStep.value = 2;
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Verification Code Sent',
                        html: `We have sent a 6-digit verification code to <strong>${maskedEmail.value}</strong>.<br><small class="text-muted mt-2 d-block">Please check your inbox (and spam folder) and enter the code below.</small>`,
                        icon: 'success',
                        confirmButtonColor: '#3085d6',
                        confirmButtonText: 'Enter Code'
                    });
                }
            } catch (err) {
                isSubmitting.value = false;
                errorMessage.value = 'An unexpected server error occurred. Please try again.';
            }
        };
        const handleResetPassword = async () => {
            if (!verificationCode.value.trim()) {
                errorMessage.value = 'Please enter the 6-digit verification code.';
                return;
            }
            if (!newPassword.value) {
                errorMessage.value = 'Please enter your new password.';
                return;
            }
            if (newPassword.value.length < 6) {
                errorMessage.value = 'New password must be at least 6 characters long.';
                return;
            }
            if (newPassword.value !== confirmPassword.value) {
                errorMessage.value = 'Passwords do not match. Please re-enter.';
                return;
            }
            isSubmitting.value = true;
            errorMessage.value = '';
            successMessage.value = '';
            try {
                const res = await StudentApiService.resetPasswordWithCode(
                    identifier.value.trim(),
                    verificationCode.value.trim(),
                    newPassword.value
                );
                isSubmitting.value = false;
                if (!res.success) {
                    errorMessage.value = res.message || 'Invalid or expired verification code.';
                    return;
                }
                successMessage.value = 'Password reset successfully! Redirecting to login...';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Password Updated!',
                        text: 'Your password has been successfully reset. You can now sign into the Student Portal.',
                        icon: 'success',
                        confirmButtonColor: '#3085d6',
                        confirmButtonText: 'Proceed to Sign In'
                    }).then(() => {
                        window.location.href = `login.html?reset=success&id=${encodeURIComponent(identifier.value.trim())}`;
                    });
                } else {
                    setTimeout(() => {
                        window.location.href = `login.html?reset=success&id=${encodeURIComponent(identifier.value.trim())}`;
                    }, 1500);
                }
            } catch (err) {
                isSubmitting.value = false;
                errorMessage.value = 'Failed to reset password. Please try again.';
            }
        };
        const goBackToStep1 = () => {
            currentStep.value = 1;
            verificationCode.value = '';
            newPassword.value = '';
            confirmPassword.value = '';
            errorMessage.value = '';
            successMessage.value = '';
        };
        return {
            currentStep,
            identifier,
            verificationCode,
            newPassword,
            confirmPassword,
            showNewPass,
            showConfirmPass,
            isSubmitting,
            errorMessage,
            successMessage,
            maskedEmail,
            studentName,
            handleRequestCode,
            handleResetPassword,
            goBackToStep1
        };
    }
};
