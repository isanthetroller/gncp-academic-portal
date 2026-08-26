(function (global) {
    let colleges = [
        { code: 'COIT', name: 'Information Technology' },
        { code: 'COBA', name: 'Business Administration' },
        { code: 'COHS', name: 'College of Nursing' }
    ];
    let courses = [
        { code: 'BSIT', college: 'COIT', name: 'BS in Information Technology', duration: '4 Years' },
        { code: 'BSCS', college: 'COIT', name: 'BS in Computer Science', duration: '4 Years' },
        { code: 'BSCpE', college: 'COIT', name: 'BS in Computer Engineering', duration: '4 Years' },
        { code: 'BSBA', college: 'COBA', name: 'BS in Business Administration', duration: '4 Years' },
        { code: 'BSN', college: 'COHS', name: 'BS in Nursing', duration: '4 Years' }
    ];
    const admissionTypes = [
        { value: 'FRESHMAN',  label: 'Incoming Freshman',  desc: 'Graduated SHS and entering college for the first time.' },
        { value: 'TRANSFEREE',label: 'College Transferee', desc: 'Enrolling from another college/university.' },
        { value: 'RETURNING', label: 'Returning Student',  desc: 'GNCP student returning after leave of absence.' }
    ];
    const nstpOptions = [
        {
            value: 'ROTC',
            label: 'ROTC',
            fullName: 'Reserve Officers\' Training Corps',
            desc: 'Military-based civic service training.',
            icon: 'fas fa-shield-halved'
        },
        {
            value: 'CWTS',
            label: 'CWTS',
            fullName: 'Civic Welfare Training Service',
            desc: 'Community-oriented service programs.',
            icon: 'fas fa-hand-holding-heart'
        },
        {
            value: 'LTS',
            label: 'LTS',
            fullName: 'Literacy Training Service',
            desc: 'Teaching literacy to out-of-school youth.',
            icon: 'fas fa-book-open-reader'
        }
    ];
    const paymentModes = [
        { value: 'CASH', label: 'Cash Payment Plan',        desc: 'Single cash payment with 5% discount.' },
        { value: 'SEMI', label: 'Semi-Annual Installment',  desc: '30% downpayment, balance in 2 installments.' },
        { value: 'QUAD', label: 'Quarterly Installment',    desc: '30% downpayment, balance in 3 installments.' }
    ];
    const medicalConditionOptions = [
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
    const EnrollmentModel = {
        getColleges() { return colleges; },
        getCourses() { return courses; },
        setPrograms(programsList) {
            const parsedCourses = [];
            programsList.forEach(prog => {
                const dept = (prog.department || '').toLowerCase();
                let collegeCode = 'COIT';
                if (dept.includes('business') || dept.includes('coba') || dept.includes('cob')) {
                    collegeCode = 'COBA';
                } else if (dept.includes('health') || dept.includes('cohs') || dept.includes('nursing') || dept.includes('chs')) {
                    collegeCode = 'COHS';
                }
                parsedCourses.push({
                    code: prog.code,
                    college: collegeCode,
                    name: prog.name,
                    duration: '4 Years'
                });
            });
            if (parsedCourses.length > 0) {
                colleges.length = 0;
                const src = window.GNCP_DEPARTMENTS || [
                    { code: 'COIT', name: 'Information Technology' },
                    { code: 'COBA', name: 'Business Administration' },
                    { code: 'COHS', name: 'College of Nursing' }
                ];
                src.forEach(c => colleges.push(c));
                courses.length = 0;
                parsedCourses.forEach(c => courses.push(c));
            }
        },
        getAdmissionTypes() { return admissionTypes; },
        getNstpOptions() { return nstpOptions; },
        getPaymentModes() { return paymentModes; },
        getMedicalConditionOptions() { return medicalConditionOptions; },
        getDocKey(item) {
            if (!item) return 'other';
            const text = item.toLowerCase().trim();
            if (text.startsWith('form 138') || (text.includes('report card') && !text.includes('old high school'))) return 'reportCard';
            if (text.startsWith('psa birth certificate')) return 'psa';
            if (text.startsWith('original certificate of good moral')) return 'goodMoral';
            return text.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        },
        getRequirements(studentType, educationPathway = 'REGULAR') {
            if (studentType === 'FRESHMAN') {
                if (educationPathway === 'ALS') {
                    return [
                        'ALS Certificate of Rating (COR) with Passing Marks (Original & Photocopy)',
                        'ALS Certificate of Completion (Original)',
                        'PSA Birth Certificate (Photocopy)',
                        '2 pieces recent 2x2 color pictures (white background with name tag)'
                    ];
                } else if (educationPathway === 'OLD_CURRICULUM') {
                    return [
                        'Old High School Report Card (Form 138-A) / Transcript of Record (Original)',
                        'Original Certificate of Good Moral Character (with dry seal)',
                        'PSA Birth Certificate (Photocopy)',
                        '2 pieces recent 2x2 color pictures (white background with name tag)'
                    ];
                } else {
                    return [
                        'Form 138 (Original Senior High School Report Card)',
                        'Original Certificate of Good Moral Character (with dry seal)',
                        'PSA Birth Certificate (Photocopy)',
                        '2 pieces recent 2x2 color pictures (white background with name tag)'
                    ];
                }
            } else if (studentType === 'TRANSFEREE') {
                return [
                    'Original Honorable Dismissal / Transfer Credentials',
                    'Official Transcript of Records (TOR) or Copy of Grades (for evaluation)',
                    'Original Certificate of Good Moral Character',
                    'PSA Birth Certificate (Photocopy)',
                    '2 pieces recent 2x2 color pictures (white background with name tag)'
                ];
            } else {
                return [
                    'GNCP Student Clearance Form from the previous semester attended',
                    'Evaluation Form signed by the Registrar Coordinator / Academic Dean',
                    'Student ID Card (for renewal)'
                ];
            }
        },
        calculateFees(form) {
            const tuition = 21 * 1200; 
            const misc = 8500;         
            let discount = 0;
            if (form.scholarship === 'HONOR') discount = tuition * 0.20;
            else if (form.scholarship === 'ATHLETIC') discount = tuition * 0.15;
            else if (form.scholarship === 'FINANCIAL') discount = tuition * 0.10;
            let cashDiscount = 0;
            if (form.paymentMode === 'CASH') {
                const subtotal = tuition + misc - discount;
                cashDiscount = subtotal * 0.05;
            }
            const total = tuition + misc - discount - cashDiscount;
            let downpayment = total;
            if (form.paymentMode !== 'CASH') {
                downpayment = total * 0.30;
            }
            let installmentAmount = 0;
            if (form.paymentMode !== 'CASH') {
                const remaining = total - downpayment;
                if (form.paymentMode === 'SEMI') installmentAmount = remaining / 2;
                else if (form.paymentMode === 'QUAD') installmentAmount = remaining / 3;
            }
            return {
                tuition,
                misc,
                discount,
                cashDiscount,
                total,
                downpayment,
                installmentAmount
            };
        },
        validateStep(step, form, errors) {
            for (let key in errors) {
                errors[key] = '';
            }
            let isValid = true;
            if (step === 1) {
                if (!form.studentType) {
                    errors.studentType = 'Please select your admission type.';
                    isValid = false;
                }
                if (form.studentType === 'RETURNING') {
                    if (!form.existingStudentName || !form.existingStudentName.trim()) {
                        errors.existingStudentId = 'Please enter your official GNCP Full Name.';
                        isValid = false;
                    } else if (!form.returningStudentVerified) {
                        errors.existingStudentId = 'Please click "Verify Name" to confirm your student record before continuing.';
                        isValid = false;
                    }
                    if (!form.yearLevelApplied) {
                        errors.yearLevelApplied = 'Please select the year level you are re-enrolling at.';
                        isValid = false;
                    }
                }
                if (!form.courseCode) {
                    errors.courseCode = 'Please select a course / major.';
                    isValid = false;
                }
                if (!form.nstp) {
                    errors.nstp = 'Please select your NSTP program component.';
                    isValid = false;
                }
            }
            if (step === 2) {
                if (!form.firstName.trim()) { errors.firstName = 'First name is required.'; isValid = false; }
                if (!form.lastName.trim())  { errors.lastName  = 'Last name is required.'; isValid = false; }
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!form.email.trim())              { errors.email = 'Email address is required.'; isValid = false; }
                else if (!emailRegex.test(form.email)){ errors.email = 'Please enter a valid email address.'; isValid = false; }
                const phoneRegex = /^\d{9}$/;
                if (!form.phone.trim())               { errors.phone = 'Mobile number is required.'; isValid = false; }
                else if (!phoneRegex.test(form.phone)){ errors.phone = 'Please enter exactly the remaining 9 digits.'; isValid = false; }
                if (!form.birthDate) {
                    errors.birthDate = 'Birth date is required.';
                    isValid = false;
                } else {
                    const birthDateObj = new Date(form.birthDate);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    if (birthDateObj >= today) {
                        errors.birthDate = 'Birth date must be in the past.';
                        isValid = false;
                    } else {
                        const age = today.getFullYear() - birthDateObj.getFullYear();
                        if (age < 15) {
                            errors.birthDate = 'Student must be at least 15 years old to enroll.';
                            isValid = false;
                        } else if (age > 100) {
                            errors.birthDate = 'Please enter a realistic birth date.';
                            isValid = false;
                        }
                    }
                }
                if (!form.gender)          { errors.gender  = 'Please select your gender.'; isValid = false; }
                if (!form.address.trim())  { errors.address = 'Residential address is required.'; isValid = false; }
            }
            if (step === 3) {
                if (form.studentType === 'TRANSFEREE') {
                    if (!form.previousCollege || !form.previousCollege.trim()) {
                        errors.previousCollege = 'Previous college or university attended is required for transferee applicants.';
                        isValid = false;
                    }
                }
                if (!form.elementarySchool.trim()) {
                    errors.elementarySchool = form.educationPathway === 'ALS' ? 'ALS Learning Center is required.' : 'Elementary school is required.';
                    isValid = false;
                }
                if (form.educationPathway !== 'ALS') {
                    if (!form.juniorHighSchool.trim()) {
                        errors.juniorHighSchool = form.educationPathway === 'OLD_CURRICULUM' ? 'High School name is required.' : 'Junior High school is required.';
                        isValid = false;
                    }
                }
                if (form.educationPathway === 'REGULAR') {
                    if (!form.seniorHighSchool.trim()) {
                        errors.seniorHighSchool = 'Senior High school is required.';
                        isValid = false;
                    }
                }
            }
            if (step === 4) {
                if (!form.emergencyContactName.trim()) {
                    errors.emergencyContactName = 'Emergency contact name is required.';
                    isValid = false;
                }
                const phoneRegex = /^\d{9}$/;
                if (!form.emergencyContactPhone.trim()) {
                    errors.emergencyContactPhone = 'Emergency contact number is required.';
                    isValid = false;
                } else if (!phoneRegex.test(form.emergencyContactPhone)) {
                    errors.emergencyContactPhone = 'Please enter exactly the remaining 9 digits.';
                    isValid = false;
                }
            }
            return isValid;
        },
        formatPrice(value) {
            return Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        getCourseName(code) {
            const c = courses.find(x => x.code === code);
            return c ? c.name : code;
        },
        getNstpLabel(value) {
            const opt = nstpOptions.find(n => n.value === value);
            return opt ? `${opt.label} — ${opt.fullName}` : value;
        },
        getStudentTypeLabel(value) {
            const t = admissionTypes.find(x => x.value === value);
            return t ? t.label : value;
        },
        getPaymentModeLabel(value) {
            const m = paymentModes.find(x => x.value === value);
            return m ? m.label : value;
        },
        getScholarshipLabel(value) {
            if (value === 'HONOR')    return 'Academic Honor (20%)';
            if (value === 'ATHLETIC') return 'Athletic Varsity (15%)';
            if (value === 'FINANCIAL')return 'Financial Assistance (10%)';
            return 'None / Standard tuition';
        }
    };
    global.EnrollmentModel = EnrollmentModel;
})(typeof window !== 'undefined' ? window : this);
