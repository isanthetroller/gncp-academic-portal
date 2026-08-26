(function () {
    const createApp = Vue.createApp;
    const ref = Vue.ref;
    const reactive = Vue.reactive;
    const computed = Vue.computed;
    const watch = Vue.watch;
    const Model = window.EnrollmentModel;
    createApp({
        setup() {
            const urlParams = new URLSearchParams(window.location.search);
            const deptParam = urlParams.get('dept') || localStorage.getItem('gncp_selected_dept') || '';
            const hasDeptParam = urlParams.has('dept') || !!localStorage.getItem('gncp_selected_dept');
            const selectedCollege = ref('');
            const collegeSelectLabel = computed(() => {
                return hasDeptParam ? 'Select Department' : 'Select College';
            });
            const collegeSelectPlaceholder = computed(() => {
                return hasDeptParam ? 'Select Department' : 'Choose a department...';
            });
            const steps = [
                { number: 1, title: 'Program & NSTP', icon: 'fas fa-university' },
                { number: 2, title: 'Personal Info',  icon: 'fas fa-user' },
                { number: 3, title: 'Academic Info',  icon: 'fas fa-graduation-cap' },
                { number: 4, title: 'Medical Info',   icon: 'fas fa-heartbeat' },
                { number: 5, title: 'Payment Term',   icon: 'fas fa-coins' },
                { number: 6, title: 'Review & Submit',icon: 'fas fa-check-double' }
            ];
            const currentStep = ref(1);
            const isSubmitting = ref(false);
            const submitError = ref('');
            const tempAccount = ref(null);
            const copiedField = ref('');
            const showPin = ref(false);
            const isLocalFile = ref(window.location.protocol === 'file:');
            const form = reactive({
                studentType: 'FRESHMAN',
                educationPathway: 'REGULAR',
                courseCode: '',
                nstp: '',
                existingStudentId: '',              
                existingStudentIdentifier: '',       
                yearLevelApplied: '',               
                returningStudentVerified: false,    
                returningStudentData: null,         
                firstName: '',
                middleName: '',
                lastName: '',
                email: '',
                phone: '',
                birthDate: '',
                gender: '',
                address: '',
                elementarySchool: '',
                juniorHighSchool: '',
                seniorHighSchool: '',
                previousCollege: '',
                shsTrack: '',
                honors: '',
                healthStatus: 'GOOD',          
                medicalConditions: [],
                allergies: '',
                currentMedication: false,
                medicationDetails: '',
                fitnessParticipation: true,
                emergencyContactName: '',
                emergencyContactPhone: '',
                paymentMode: 'CASH',           
                scholarship: 'NONE'            
            });
            const errors = reactive({
                studentType: '',
                courseCode: '',
                nstp: '',
                existingStudentId: '',   
                yearLevelApplied: '',    
                firstName: '',
                lastName: '',
                email: '',
                phone: '',
                birthDate: '',
                gender: '',
                address: '',
                elementarySchool: '',
                juniorHighSchool: '',
                seniorHighSchool: '',
                previousCollege: '',
                emergencyContactName: '',
                emergencyContactPhone: '',
                softCopyDocs: ''
            });
            const isLookingUp   = Vue.ref(false);  
            const lookupError   = Vue.ref('');     
            const lookupSuccess = Vue.ref(false);  
            const saveDraft = () => {
                const draft = {
                    currentStep: currentStep.value,
                    form: form
                };
                localStorage.setItem('gncp_enrollment_draft', JSON.stringify(draft));
            };
            const loadDraft = () => {
                const stored = localStorage.getItem('gncp_enrollment_draft');
                if (stored) {
                    try {
                        const draft = JSON.parse(stored);
                        if (draft.currentStep && draft.currentStep < 7) {
                            currentStep.value = draft.currentStep;
                            for (const key in draft.form) {
                                if (draft.form.hasOwnProperty(key)) {
                                    form[key] = draft.form[key];
                                }
                            }
                        }
                    } catch (e) {
                        console.error('Failed to restore draft:', e);
                    }
                }
            };
            loadDraft();
            watch(currentStep, saveDraft);
            watch(form, saveDraft, { deep: true });
            const colleges = ref(Model.getColleges());
            const courses = ref(Model.getCourses());
            const activePeriodInfo = ref(null);
            const programsLoadError = ref('');
            const fetchActivePrograms = () => {
                window.ApiService.getActivePrograms()
                    .then(res => {
                        if (res.success && res.data) {
                            if (res.data.programs && res.data.programs.length > 0) {
                                Model.setPrograms(res.data.programs);
                                colleges.value = Model.getColleges();
                                courses.value = Model.getCourses();
                                if (form.courseCode) {
                                    const matchedCourse = courses.value.find(c => c.code === form.courseCode);
                                    if (matchedCourse) {
                                        selectedCollege.value = matchedCourse.college;
                                    }
                                } else {
                                    const matchedDept = ['COIT', 'COBA', 'COHS'].includes(deptParam.toUpperCase()) ? deptParam.toUpperCase() : '';
                                    if (matchedDept) {
                                        selectedCollege.value = matchedDept;
                                    }
                                }
                            }
                            if (res.data.activePeriod) {
                                activePeriodInfo.value = res.data.activePeriod;
                            } else {
                                programsLoadError.value = 'Enrollment is currently closed. No active academic period is configured.';
                            }
                        } else {
                            programsLoadError.value = res.error || 'Failed to fetch active programs.';
                        }
                    })
                    .catch(err => {
                        console.error('Failed to load active programs:', err);
                        programsLoadError.value = 'Failed to connect to server for active programs.';
                    });
            };
            fetchActivePrograms();
            const admissionTypes = (Model && typeof Model.getAdmissionTypes === 'function') ? Model.getAdmissionTypes() : [];
            const nstpOptions = (Model && typeof Model.getNstpOptions === 'function') ? Model.getNstpOptions() : [];
            const paymentModes = (Model && typeof Model.getPaymentModes === 'function') ? Model.getPaymentModes() : [];
            const medicalConditionOptions = (Model && typeof Model.getMedicalConditionOptions === 'function') ? Model.getMedicalConditionOptions() : [
                'Asthma / Respiratory Condition',
                'Diabetes',
                'Heart Condition',
                'Hypertension / High Blood Pressure',
                'Epilepsy / Seizure Disorder',
                'Visual Impairment',
                'Hearing Impairment',
                'Musculoskeletal / Mobility Condition',
                'Mental Health Condition',
                'Other (specify in allergies/notes)'
            ];
            const maxBirthDate = computed(() => {
                const yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                const yyyy = yesterday.getFullYear();
                let mm = yesterday.getMonth() + 1;
                if (mm < 10) {
                    mm = '0' + mm;
                }
                let dd = yesterday.getDate();
                if (dd < 10) {
                    dd = '0' + dd;
                }
                return yyyy + '-' + mm + '-' + dd;
            });
            const filteredCourses = computed(() => {
                if (!selectedCollege.value) return [];
                const result = [];
                for (let i = 0; i < courses.value.length; i++) {
                    if (courses.value[i].college === selectedCollege.value) {
                        result.push(courses.value[i]);
                    }
                }
                return result;
            });
            const onCollegeChange = () => {
                form.courseCode = '';
            };
            const onPathwayChange = () => {
                form.juniorHighSchool = '';
                form.seniorHighSchool = '';
                form.shsTrack = '';
                errors.juniorHighSchool = '';
                errors.seniorHighSchool = '';
            };
            const lookupReturningStudent = () => {
                const identifier = (form.existingStudentIdentifier || '').trim();
                if (!identifier) {
                    lookupError.value = 'Please enter your email address before verifying.';
                    return;
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier)) {
                    lookupError.value = 'Please enter a valid email address (e.g. juan.delacruz@gncp.edu.ph).';
                    return;
                }
                isLookingUp.value             = true;
                lookupError.value             = '';
                lookupSuccess.value           = false;
                form.returningStudentVerified = false;
                form.returningStudentData     = null;
                window.ApiService.lookupReturningStudent(identifier)
                    .then(res => {
                        isLookingUp.value = false;
                        if (res.success && res.data) {
                            form.returningStudentVerified = true;
                            form.returningStudentData     = res.data;
                            form.existingStudentId        = res.data.id;
                            lookupSuccess.value           = true;
                            form.firstName  = res.data.firstName  || '';
                            form.middleName = res.data.middleName || '';
                            form.lastName   = res.data.lastName   || '';
                            form.email      = res.data.email      || '';
                            const rawPhone = res.data.phone || '';
                            form.phone = rawPhone.startsWith('09') ? rawPhone.slice(2) : rawPhone;
                            form.birthDate = res.data.birthDate || '';
                            form.gender    = res.data.gender    || '';
                            form.address   = res.data.address   || '';
                            if (res.data.year_level && !form.yearLevelApplied) {
                                form.yearLevelApplied = res.data.year_level;
                            }
                            errors.existingStudentId = '';
                            errors.yearLevelApplied  = '';
                        } else {
                            lookupError.value = res.error || 'Verification failed. Please check your email and try again.';
                        }
                    })
                    .catch(err => {
                        isLookingUp.value = false;
                        console.error('[App] Returning student lookup failed:', err);
                        lookupError.value = 'An unexpected error occurred. Please try again.';
                    });
            };
            watch(() => form.existingStudentIdentifier, (newVal, oldVal) => {
                if (newVal !== oldVal && form.returningStudentVerified) {
                    form.returningStudentVerified = false;
                    form.returningStudentData     = null;
                    lookupSuccess.value           = false;
                }
            });
            const toggleCondition = (condition) => {
                const idx = form.medicalConditions.indexOf(condition);
                if (idx === -1) {
                    form.medicalConditions.push(condition);
                } else {
                    form.medicalConditions.splice(idx, 1);
                }
            };
            const fees = computed(() => {
                return Model.calculateFees(form);
            });
            const calcTuition = computed(() => fees.value.tuition);
            const calcMisc = computed(() => fees.value.misc);
            const calcDiscount = computed(() => fees.value.discount);
            const calcCashDiscount = computed(() => fees.value.cashDiscount);
            const calcTotal = computed(() => fees.value.total);
            const calcDownpayment = computed(() => fees.value.downpayment);
            const calcInstallmentAmount = computed(() => fees.value.installmentAmount);
            const getRequirements = computed(() => {
                return Model.getRequirements(form.studentType, form.educationPathway);
            });
            const documentFiles = reactive({});
            const getDocKey = (item) => {
                if (Model && typeof Model.getDocKey === 'function') {
                    return Model.getDocKey(item);
                }
                if (!item) return 'other';
                const text = item.toLowerCase().trim();
                if (text.startsWith('form 138') || (text.includes('report card') && !text.includes('old high school'))) return 'reportCard';
                if (text.startsWith('psa birth certificate')) return 'psa';
                if (text.startsWith('original certificate of good moral')) return 'goodMoral';
                return text.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            };
            const requiredDocList = computed(() => {
                const rawList = Model.getRequirements(form.studentType, form.educationPathway);
                return rawList.map(item => ({
                    key: getDocKey(item),
                    label: item
                }));
            });
            const onFileChange = (key, event) => {
                const file = event.target.files && event.target.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'File Too Large',
                            text: 'The selected document exceeds the 5MB size limit. Please upload a smaller file.',
                            icon: 'warning',
                            confirmButtonColor: '#006A4E'
                        });
                    } else {
                        alert('File size exceeds the 5MB limit. Please upload a smaller file.');
                    }
                    event.target.value = '';
                    return;
                }
                const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
                const ext = file.name.split('.').pop().toLowerCase();
                if (!allowed.includes(ext)) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Invalid File Format',
                            text: 'Only PDF, JPG, JPEG, and PNG document files are accepted for verification.',
                            icon: 'warning',
                            confirmButtonColor: '#006A4E'
                        });
                    } else {
                        alert('Invalid file format. Allowed formats: PDF, JPG, JPEG, PNG.');
                    }
                    event.target.value = '';
                    return;
                }
                documentFiles[key] = file;
            };
            const removeDocFile = (key) => {
                delete documentFiles[key];
            };
            const formatFileSize = (bytes) => {
                if (!bytes) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
            };
            const triggerFileInput = (inputId) => {
                const el = document.getElementById(inputId);
                if (el) el.click();
            };
            const getFileTypeClass = (fileName) => {
                if (!fileName) return 'file-type-doc';
                const ext = fileName.split('.').pop().toLowerCase();
                if (ext === 'pdf') return 'file-type-pdf';
                if (['jpg', 'jpeg', 'png'].includes(ext)) return 'file-type-img';
                return 'file-type-doc';
            };
            const getFileIcon = (fileName) => {
                if (!fileName) return 'fa-file-lines';
                const ext = fileName.split('.').pop().toLowerCase();
                if (ext === 'pdf') return 'fa-file-pdf';
                if (['jpg', 'jpeg', 'png'].includes(ext)) return 'fa-file-image';
                return 'fa-file-lines';
            };
            const validateCurrentStep = () => {
                if (currentStep.value === 3) {
                    if (form.educationPathway === 'ALS') {
                        if (!form.juniorHighSchool || !form.juniorHighSchool.trim()) {
                            form.juniorHighSchool = 'N/A (ALS)';
                        }
                        if (!form.seniorHighSchool || !form.seniorHighSchool.trim()) {
                            form.seniorHighSchool = 'Alternative Learning System (ALS)';
                        }
                        if (!form.shsTrack || !form.shsTrack.trim()) {
                            form.shsTrack = 'ALS';
                        }
                    } else if (form.educationPathway === 'OLD_CURRICULUM') {
                        if (!form.seniorHighSchool || !form.seniorHighSchool.trim()) {
                            form.seniorHighSchool = 'N/A (Old Curriculum)';
                        }
                        if (!form.shsTrack || !form.shsTrack.trim()) {
                            form.shsTrack = 'OLD_CURRICULUM';
                        }
                    }
                    const uploadedCount = Object.keys(documentFiles).length;
                    if (uploadedCount > 0) {
                        const requiredKeys = requiredDocList.value.map(d => d.key);
                        const missingKeys = requiredKeys.filter(key => !documentFiles[key]);
                        if (missingKeys.length > 0) {
                            errors.softCopyDocs = 'Since you uploaded a soft copy document, please upload all required document soft copies (or remove attached files) to proceed.';
                            Model.validateStep(currentStep.value, form, errors);
                            return false;
                        }
                    }
                    errors.softCopyDocs = '';
                }
                return Model.validateStep(currentStep.value, form, errors);
            };
            const nextStep = () => {
                if (!validateCurrentStep()) {
                    setTimeout(() => {
                        const firstErrorEl = document.querySelector('.is-invalid, .text-danger');
                        if (firstErrorEl) {
                            firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 100);
                    return;
                }
                if (currentStep.value === 6) {
                    isSubmitting.value = true;
                    submitError.value = '';
                    const payload = JSON.parse(JSON.stringify(form));
                    payload.phone = '09' + form.phone;
                    payload.emergencyContactPhone = '09' + form.emergencyContactPhone;
                    const formData = new FormData();
                    formData.append('form_data', JSON.stringify(payload));
                    Object.keys(documentFiles).forEach(key => {
                        if (documentFiles[key]) {
                            formData.append('doc_' + key, documentFiles[key]);
                        }
                    });
                    window.ApiService.submitEnrollment(formData)
                        .then(res => {
                            isSubmitting.value = false;
                            if (res.success) {
                                localStorage.removeItem('gncp_enrollment_draft');
                                tempAccount.value = res.data;
                                currentStep.value = 7; 
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                            } else {
                                submitError.value = res.error || 'Failed to submit registration.';
                            }
                        })
                        .catch(err => {
                            isSubmitting.value = false;
                            console.error('Submit execution error:', err);
                            submitError.value = 'An unexpected error occurred. Please try again.';
                        });
                } else if (currentStep.value < 6) {
                    currentStep.value++;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            };
            const prevStep = () => {
                if (currentStep.value > 1 && currentStep.value <= 6) {
                    currentStep.value--;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            };
            const copyToClipboard = (text, fieldName) => {
                navigator.clipboard.writeText(text).then(() => {
                    copiedField.value = fieldName;
                    setTimeout(() => { copiedField.value = ''; }, 2000);
                }).catch(err => {
                    console.error('Copy failed:', err);
                });
            };
            const formatPrice = Model.formatPrice;
            const getCourseName = Model.getCourseName;
            const getNstpLabel = Model.getNstpLabel;
            const getStudentTypeLabel = Model.getStudentTypeLabel;
            const getPaymentModeLabel = Model.getPaymentModeLabel;
            const getScholarshipLabel = Model.getScholarshipLabel;
            return {
                steps,
                currentStep,
                isSubmitting,
                submitError,
                tempAccount,
                copiedField,
                showPin,
                form,
                colleges,
                courses,
                admissionTypes,
                documentFiles,
                requiredDocList,
                onFileChange,
                removeDocFile,
                formatFileSize,
                getFileIcon,
                getFileTypeClass,
                triggerFileInput,
                nstpOptions,
                paymentModes,
                medicalConditionOptions,
                selectedCollege,
                collegeSelectLabel,
                collegeSelectPlaceholder,
                filteredCourses,
                onCollegeChange,
                onPathwayChange,
                lookupReturningStudent,
                isLookingUp,
                lookupError,
                lookupSuccess,
                toggleCondition,
                calcTuition,
                calcMisc,
                calcDiscount,
                calcCashDiscount,
                calcTotal,
                calcDownpayment,
                calcInstallmentAmount,
                getRequirements,
                errors,
                validateCurrentStep,
                nextStep,
                prevStep,
                copyToClipboard,
                formatPrice,
                getCourseName,
                getNstpLabel,
                getStudentTypeLabel,
                getPaymentModeLabel,
                getScholarshipLabel,
                maxBirthDate,
                isLocalFile,
                activePeriodInfo,
                programsLoadError
            };
        }
    }).mount('#enrollment-app');
})();
