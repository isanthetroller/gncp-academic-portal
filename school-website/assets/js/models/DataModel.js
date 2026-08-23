window.DataModel = {
    getSliderSlides() {
        return [
            {
                id: 1,
                title: "Welcome to GNCP!",
                subtext: "Dasmariñas, Cavite Campus — Undergraduate enrollment for Academic Year 2026-2027 is now open.",
                bgImage: "https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=1600&q=80"
            },
            {
                id: 2,
                title: "Academic & Technical Excellence",
                subtext: "Offering specialized undergraduate programs, professional training, and career guidance.",
                bgImage: "https://images.unsplash.com/photo-1509062522246-3755977927d7?auto=format&fit=crop&w=1600&q=80"
            },
            {
                id: 3,
                title: "Cavite's Modern Learning Hub",
                subtext: "Equipped with specialized laboratories, student computer networks, and research archives.",
                bgImage: "https://images.unsplash.com/photo-1568667256549-094345857637?auto=format&fit=crop&w=1600&q=80"
            }
        ];
    },

    getFeatureCards() {
        return [
            {
                id: 1,
                icon: "fas fa-building",
                title: "CAMPUS DEVELOPMENT",
                text: "Construction of the new laboratory extension building along Emilio Aguinaldo Highway is scheduled for completion this year.",
                linkText: "VIEW FACILITIES",
                linkUrl: "#"
            },
            {
                id: 2,
                icon: "fas fa-graduation-cap",
                title: "ACADEMIC SCHOLARSHIPS",
                text: "Financial aid, merit-based tuition discounts, and athletic grants are available for eligible undergraduate students.",
                linkText: "SCHOLARSHIP INFO",
                linkUrl: "#"
            },
            {
                id: 3,
                icon: "fas fa-university",
                title: "COLLEGE LEADERSHIP",
                text: "Read updates from our academic deans, department chairs, and collegiate administrative council.",
                linkText: "READ MESSAGES",
                linkUrl: "#"
            }
        ];
    },

    getCampusItems() {
        return [
            {
                id: 1,
                category: "NEWS",
                title: "GNCP Dasmariñas Campus Inaugurates State-of-the-Art Science and Robotics Lab",
                excerpt: "GNCP officially opened its new laboratory building on Emilio Aguinaldo Highway, featuring advanced equipment for STEM research and student experiments.",
                date: "Aug 14, 2026",
                image: "assets/images/about1.webp"
            },
            {
                id: 2,
                category: "EVENTS",
                title: "First Semester Student Orientation & Campus Briefing This Saturday",
                excerpt: "New freshmen and transferees are invited to join the campus tours, student briefings, and academic department orientations at the main college amphitheater.",
                date: "Aug 12, 2026",
                image: "assets/images/about2.webp"
            },
            {
                id: 3,
                category: "FEATURES",
                title: "Patriots Triumph: GNCP Varsity Clinches Regional Collegiate Cup Championship",
                excerpt: "Our college basketball team dominated the tournament finals, securing a stellar victory for the green-and-gold in a thrilling match last weekend.",
                date: "Aug 08, 2026",
                image: "assets/images/hero1.webp"
            },
            {
                id: 4,
                category: "NEWS",
                title: "GNCP Board Exam Topnotchers Celebrated at General Academic Assembly",
                excerpt: "We congratulate the GNCP computing and nursing education graduates for maintaining a stellar passing rate in the recent national licensure board examinations.",
                date: "Aug 04, 2026",
                image: "assets/images/hero3.webp"
            },
            {
                id: 5,
                category: "EVENTS",
                title: "Annual Arts and Cultural Festival Scheduled for Next Month",
                excerpt: "Showcasing student talent in visual arts, theater performances, and traditional music. This year's festival highlights rich Caviteño heritage.",
                date: "Jul 28, 2026",
                image: "assets/images/about2.webp"
            },
            {
                id: 6,
                category: "FEATURES",
                title: "Bridging Technology and Learning: Python & Cloud Sandboxes Integrated",
                excerpt: "A feature on how GNCP is equipping undergraduate students with modern software engineering skills to prepare them for global technology careers.",
                date: "Jul 20, 2026",
                image: "assets/images/hero2.webp"
            }
        ];
    },

    getCountdownConfig() {
        return {
            targetDate: "2026-12-16T00:00:00",
            label: "End of First Semester"
        };
    },

    getNavigation() {
        return {
            topInfo: {
                email: "info@gncp.edu.ph",
                phone: "09060969684"
            },
            brand: {
                logo: "assets/images/logo-removebg-preview.png",
                name: "GO-ON NATIONAL COLLEGE OF THE PHILIPPINES"
            },
            menuItems: [
                { 
                    text: "About", 
                    url: "#", 
                    dropdown: [
                        { text: "Mission & Vision", page: "about-mission" },
                        { text: "History", page: "about-history" },
                        { text: "Campus Facilities", page: "about-facilities" },
                        { text: "Administrators", page: "about-admins" }
                    ] 
                },
                { 
                    text: "Courses", 
                    url: "#", 
                    dropdown: (window.GNCP_DEPARTMENTS || [
                        { code: 'COIT', name: 'Information Technology' },
                        { code: 'COBA', name: 'Business Administration' },
                        { code: 'COHS', name: 'College of Nursing' }
                    ]).map(d => {
                        let page = 'acad-it';
                        if (d.code.includes('BA') || d.code.includes('COB')) page = 'acad-business';
                        if (d.code.includes('HS') || d.code.includes('COHS')) page = 'acad-health';
                        return { text: d.name, page: page };
                    })
                },
                { 
                    text: "Admissions", 
                    url: "#", 
                    dropdown: [
                        { text: "Admission Requirements", page: "admission-requirements" },
                        { text: "Tuition & Fees", page: "admission-fees" }
                    ] 
                },
                { 
                    text: "Campus Life", 
                    url: "#", 
                    dropdown: [
                        { text: "Student Council", page: "life-council" },
                        { text: "Patriots Athletics", page: "life-athletics" },
                        { text: "Clubs & Organizations", page: "life-clubs" }
                    ] 
                },
                { 
                    text: "Payments", 
                    url: "#", 
                    dropdown: [
                        { text: "Payment Portals", page: "payments-portals" },
                        { text: "Payment Terms", page: "payments-terms" }
                    ] 
                },
                { 
                    text: "Portals", 
                    url: "#", 
                    dropdown: [
                        { text: "Student Portal", page: "portal-student" },
                        { text: "Application Tracker", page: "portal-tracker" }
                    ] 
                }
            ],
            cta: {
                text: "ENROLL NOW",
                url: "../enrollment-system/index.html"
            }
        };
    },

    getAboutData() {
        return {
            mission: "To provide a holistic, high-quality, and globally-competitive education that nurtures academic discipline, technical expertise, and core ethical values among the youth of Cavite and the nation.",
            vision: "To be a leading center of academic and technical excellence in the region, recognized for producing skilled, productive, and socially responsible professionals who contribute to nation-building.",
            values: [
                { title: "Competence", desc: "We strive for excellence in academic, professional, and practical capabilities.", icon: "fas fa-award" },
                { title: "Integrity", desc: "We uphold honesty, ethical behavior, and moral standards in all actions.", icon: "fas fa-shield-alt" },
                { title: "Service", desc: "We are dedicated to building communities and contributing to the welfare of others.", icon: "fas fa-hand-holding-heart" }
            ],
            history: [
                { year: "2015", title: "Founding of GNCP", desc: "Established in Dasmariñas, Cavite, to offer career-focused certificate courses and programs.", image: "assets/images/history_founding.png" },
                { year: "2018", title: "College Expansion", desc: "Formally recognized as a higher education college, introducing Baccalaureate degrees in IT and Education.", image: "assets/images/history_expansion.png" },
                { year: "2021", title: "Emilio Aguinaldo Highway Campus", desc: "Relocated to a modern facility along the main highway with expanded computing and science laboratories.", image: "assets/images/history_campus.png" },
                { year: "2026", title: "Robotics and Science Laboratory", desc: "Inauguration of the state-of-the-art Science and Robotics Lab extension, enhancing STEM training.", image: "assets/images/history_robotics.png" }
            ],
            facilities: [
                { name: "Science & Robotics Lab", image: "https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?auto=format&fit=crop&w=600&q=80", desc: "Equipped for STEM research, engineering experiments, and robotics integration." },
                { name: "Computer Networking Lab", image: "https://images.unsplash.com/photo-1563986768609-322da13575f3?auto=format&fit=crop&w=600&q=80", desc: "High-speed workstation lab configured for databases, software design, and networking." },
                { name: "Auditorium & Events Hall", image: "https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=600&q=80", desc: "Multi-purpose collegiate auditorium supporting assemblies, cultural fests, and deans' briefings." },
                { name: "Academic Library", image: "https://images.unsplash.com/photo-1507842217343-583bb7270b66?auto=format&fit=crop&w=600&q=80", desc: "Research center containing textbooks, digital archives, and quiet learning spaces." }
            ],
            admins: [
                { name: "Ethan Matthew O. Celestra", role: "CEO & Founder / Software Engineer", image: "assets/images/celestra.png" },
                { name: "Tristan Ray S. Agoilo", role: "Vice President of Operations / Executive Director", image: "assets/images/agoilo.jpg" },
                { name: "Rogelio Manaog III R.", role: "Director of Student Affairs & Registrar", image: "assets/images/manaog.png" },
                { name: "Kriz Lawrenz P. Monares", role: "Chief Technology Officer / Dean of IT Studies", image: "assets/images/monares.jpg" }
            ]
        };
    },

    getAcademicsData() {
        return {
            "acad-it": {
                title: "Information Technology",
                desc: "Equipping students with analytical and computational expertise to design, develop, and implement modern computing systems.",
                careers: ["Software Engineer", "Systems Analyst", "Network Administrator", "Database Administrator", "Web Developer"],
                curriculum: [
                    { sem: "1st Year", courses: ["Introduction to Computing", "Computer Programming 1", "College Algebra", "Physical Education 1"] },
                    { sem: "2nd Year", courses: ["Data Structures & Algorithms", "Object-Oriented Programming", "Discrete Mathematics", "Database Management Systems"] },
                    { sem: "3rd Year", courses: ["Software Engineering 1", "Computer Networks", "Web Development", "Operating Systems"] },
                    { sem: "4th Year", courses: ["Capstone Project / Thesis", "Information Assurance & Security", "Systems Integration", "Professional Practicum (Internship)"] }
                ]
            },
            "acad-business": {
                title: "Business Administration",
                desc: "Developing business intelligence, entrepreneurial leadership, and ethical financial strategies to thrive in local and global markets.",
                careers: ["Business Analyst", "Marketing Executive", "Financial Consultant", "Human Resource Manager", "Entrepreneur"],
                curriculum: [
                    { sem: "1st Year", courses: ["Principles of Management", "Basic Accounting", "Microeconomics", "Business Math"] },
                    { sem: "2nd Year", courses: ["Financial Management", "Marketing Management", "Macroeconomics", "Business Statistics"] },
                    { sem: "3rd Year", courses: ["Operations Management", "Human Resource Management", "Strategic Management", "Business Law"] },
                    { sem: "4th Year", courses: ["Corporate Governance", "Feasibility Study", "Business Ethics", "Internship Program"] }
                ]
            },
            "acad-health": {
                title: "College of Nursing",
                desc: "Developing professional, globally-competitive nurses through rigorous scientific training, clinical practice, and ethical patient-centered care.",
                careers: ["Registered Nurse", "Clinical Nurse Specialist", "Health Administrator", "Nurse Educator", "Community Health Worker"],
                curriculum: [
                    { sem: "1st Year", courses: ["Anatomy and Physiology", "Microbiology and Parasitology", "Mathematics in the Modern World", "Understanding the Self"] },
                    { sem: "2nd Year", courses: ["Pharmacology", "Nutrition and Diet Therapy", "Readings in Philippine History", "Ethics"] },
                    { sem: "3rd Year", courses: ["Medical-Surgical Nursing 1", "Medical-Surgical Nursing 2", "Health Assessment", "Nursing Informatics"] },
                    { sem: "4th Year", courses: ["Intensive Nursing Practicum", "Nursing Research 1", "Leadership & Management", "Disaster Nursing"] }
                ]
            }
        };
    },

    getAdmissionsData() {
        return {
            classifications: [
                {
                    id: "freshmen",
                    name: "Incoming Freshmen",
                    badge: "SHS & High School Completers",
                    icon: "fas fa-user-graduate",
                    desc: "For senior high school graduates and high school completers enrolling in collegiate programs for the first time.",
                    requirements: [
                        { id: "f1", title: "Original Senior High School Report Card (Form 138 / SF9)", format: "ORIGINAL", type: "academic", icon: "fas fa-file-invoice", agency: "DepEd / SHS Principal", note: "Must have complete grades with eligibility for college admission signed by the principal." },
                        { id: "f2", title: "PSA Authenticated Birth Certificate", format: "ORIGINAL + 2 PHOTOCOPIES", type: "legal", icon: "fas fa-certificate", agency: "Philippine Statistics Authority", note: "Clear copy with readable barcode and registry numbers on standard A4 paper." },
                        { id: "f3", title: "Certificate of Good Moral Character", format: "ORIGINAL", type: "conduct", icon: "fas fa-shield-halved", agency: "SHS Guidance Office", note: "Dated within the current academic term with official school dry seal." },
                        { id: "f4", title: "2x2 Colored ID Photos (4 Copies)", format: "PHYSICAL PHOTOS", type: "id", icon: "fas fa-id-badge", agency: "Official Studio", note: "White background, formal collared attire, with printed name tag (Last Name, First Name, M.I.)." },
                        { id: "f5", title: "Passing GNCP Entrance & Aptitude Assessment", format: "INSTITUTIONAL", type: "exam", icon: "fas fa-pen-nib", agency: "GNCP Admissions Office", note: "Official entrance exam test result slip issued by the guidance and testing center." },
                        { id: "f6", title: "Medical Clearance & Physical Fitness Certificate", format: "ORIGINAL", type: "medical", icon: "fas fa-heart-pulse", agency: "GNCP Campus Clinic", note: "Issued upon physical examination and vital signs verification by campus physician." }
                    ]
                },
                {
                    id: "transferees",
                    name: "College Transferees",
                    badge: "From Other Colleges / Universities",
                    icon: "fas fa-arrow-right-arrow-left",
                    desc: "For undergraduate students transferring from CHED-accredited higher education institutions.",
                    requirements: [
                        { id: "t1", title: "Honorable Dismissal / Certificate of Transfer Credential", format: "ORIGINAL", type: "academic", icon: "fas fa-file-contract", agency: "Previous Registrar Office", note: "Must be in official sealed envelope addressed to GNCP College Registrar." },
                        { id: "t2", title: "Official Transcript of Records (TOR for Evaluation)", format: "COPY / INFORMATIVE", type: "academic", icon: "fas fa-graduation-cap", agency: "Previous Registrar Office", note: "Complete academic records showing all subjects taken and numeric grading scale." },
                        { id: "t3", title: "Course Description / Syllabus (For Subject Crediting)", format: "COPY", type: "academic", icon: "fas fa-book-bookmark", agency: "Previous Academic Dept", note: "Detailed curriculum guide to credit pre-requisite and general education subjects." },
                        { id: "t4", title: "Certificate of Good Moral Character", format: "ORIGINAL", type: "conduct", icon: "fas fa-shield-halved", agency: "Dean of Student Affairs", note: "Affirming good standing and absence of major disciplinary records." },
                        { id: "t5", title: "PSA Authenticated Birth Certificate", format: "ORIGINAL + 2 PHOTOCOPIES", type: "legal", icon: "fas fa-certificate", agency: "PSA", note: "Standard A4 clear copy with barcode intact." },
                        { id: "t6", title: "2x2 Colored ID Photos (4 Copies)", format: "PHYSICAL PHOTOS", type: "id", icon: "fas fa-id-badge", agency: "Official Studio", note: "White background, formal collared attire, with name tag." }
                    ]
                },
                {
                    id: "returnees",
                    name: "Returnees & Continuing",
                    badge: "Returning GNCP Patriots",
                    icon: "fas fa-rotate-left",
                    desc: "For previously enrolled GNCP students seeking re-admission after leave of absence or stopped terms.",
                    requirements: [
                        { id: "r1", title: "Approved Letter of Re-Admission Request", format: "ORIGINAL", type: "academic", icon: "fas fa-envelope-open-text", agency: "Office of the College Dean", note: "Stating reasons for interruption and intended course curriculum plan." },
                        { id: "r2", title: "Accounting & Cashier Office Clearance", format: "ORIGINAL", type: "financial", icon: "fas fa-receipt", agency: "GNCP Treasury Office", note: "Certification of zero outstanding balance and financial account clearance." },
                        { id: "r3", title: "Previous GNCP Student ID & Evaluation Slip", format: "PHYSICAL CARD", type: "id", icon: "fas fa-address-card", agency: "GNCP IT / Registrar", note: "Previous student identification card or affidavit of loss if misplaced." },
                        { id: "r4", title: "Updated Student Record & Curriculum Sheet", format: "INSTITUTIONAL", type: "academic", icon: "fas fa-list-check", agency: "Registrar Station", note: "Audit of remaining units and curriculum alignment under active academic year." }
                    ]
                },
                {
                    id: "second_degree",
                    name: "Second Degree & Special",
                    badge: "Bachelor Degree Holders",
                    icon: "fas fa-award",
                    desc: "For college degree holders taking an additional bachelor program or specialized certificate track.",
                    requirements: [
                        { id: "s1", title: "Official Transcript of Records (TOR) with S.O. Number", format: "ORIGINAL", type: "academic", icon: "fas fa-file-shield", agency: "Graduating University", note: "Must show Special Order (S.O.) number or Board Resolution date of graduation." },
                        { id: "s2", title: "Certified True Copy of College Diploma", format: "CERTIFIED COPY", type: "academic", icon: "fas fa-scroll", agency: "Graduating University", note: "Authenticated copy of conferred baccalaureate degree." },
                        { id: "s3", title: "PSA Authenticated Birth Certificate", format: "ORIGINAL + 2 PHOTOCOPIES", type: "legal", icon: "fas fa-certificate", agency: "PSA", note: "Readable copy on A4 paper." },
                        { id: "s4", title: "PSA Certificate of Marriage (If Applicable)", format: "ORIGINAL + PHOTOCOPY", type: "legal", icon: "fas fa-ring", agency: "PSA", note: "Required for married female applicants whose maiden name differs from records." },
                        { id: "s5", title: "2x2 Colored ID Photos (4 Copies)", format: "PHYSICAL PHOTOS", type: "id", icon: "fas fa-id-badge", agency: "Official Studio", note: "White background, formal attire, with name tag." }
                    ]
                },
                {
                    id: "scholarship",
                    name: "Scholarship & Grant Seekers",
                    badge: "Academic & Institutional Grants",
                    icon: "fas fa-hand-holding-dollar",
                    desc: "Requirements for students applying for Academic Excellence, CHED UniFAST, or Barangay Grants.",
                    requirements: [
                        { id: "sc1", title: "Principal Certificate of Academic Honors (Valedictorian / Salutatorian / With Honors)", format: "ORIGINAL", type: "academic", icon: "fas fa-medal", agency: "Senior High School", note: "Specifying overall batch rank and certified graduation honors." },
                        { id: "sc2", title: "Certificate of Low Income / Latest BIR Income Tax Return (ITR)", format: "COPY", type: "financial", icon: "fas fa-file-invoice-dollar", agency: "BIR / Barangay Hall", note: "Annual family gross income verification for financial grant eligibility." },
                        { id: "sc3", title: "Certificate of Indigency / 4Ps Household Identification", format: "ORIGINAL", type: "social", icon: "fas fa-users", agency: "Barangay / DSWD", note: "Issued by the Barangay Chairman for social welfare grant applicants." },
                        { id: "sc4", title: "Accomplished GNCP Scholarship Application Form", format: "INSTITUTIONAL", type: "application", icon: "fas fa-clipboard-question", agency: "Guidance & Scholarship Office", note: "Complete application packet with 2 recommendation letters." }
                    ]
                }
            ],
            tuition: {
                ratePerUnit: 450,
                miscFees: [
                    { name: "Registration Fee", amount: 250 },
                    { name: "Laboratory Fee (per lab course)", amount: 1200 },
                    { name: "Library & Audio-Visual Fee", amount: 600 },
                    { name: "Student Athletics / Cultural Fee", amount: 350 },
                    { name: "Medical and Dental Fee", amount: 200 },
                    { name: "Student Council / Organization Fee", amount: 150 },
                    { name: "Energy & Facilities Fee", amount: 1500 }
                ]
            }
        };
    },

    getCampusLifeData() {
        return {
            council: {
                motto: "Advancing Student Welfare, Building Collegiate Synergy.",
                projects: [
                    { title: "Student Aid Initiative", desc: "Raised relief and scholarship funds for undergraduate students in need of academic support." },
                    { title: "GNCP Clean & Green Week", desc: "Campus-wide cleanup, recycling drive, and tree planting activities along Emilio Aguinaldo highway borders." },
                    { title: "Annual Leadership Summit", desc: "Collaborative bootcamp for officers across all course student societies to coordinate collegiate projects." }
                ],
                officers: [
                    { name: "Justin M. Diaz", role: "Student Council President", program: "BS Information Technology" },
                    { name: "Sarah L. Gomez", role: "Vice President", program: "BS Business Administration" },
                    { name: "Mark Anthony Santos", role: "Secretary", program: "Bachelor of Secondary Education" },
                    { name: "Clara B. Lopez", role: "Treasurer", program: "BS Business Administration" }
                ]
            },
            athletics: {
                teamName: "GNCP Patriots",
                description: "Representing the green-and-gold with discipline, grit, and sportsmanship across regional leagues.",
                sports: [
                    { name: "Basketball", coach: "Coach Danilo Perez", status: "Regional Collegiate Cup Champions (2026)" },
                    { name: "Volleyball", coach: "Coach Melissa Reyes", status: "Semi-Finalists in Cavite Athletic Meet" },
                    { name: "Table Tennis", coach: "Coach Jose Ramos", status: "Individual Gold Medalist, Regional Meet" }
                ]
            },
            clubs: [
                { name: "Information Technology Society (ITS)", desc: "The official academic club for IT students, organizing coding bootcamps, IT seminars, and game development projects." },
                { name: "Junior Executives Business Club", desc: "Honing marketing, entrepreneurial, and management skills through financial workshops and collegiate product expos." },
                { name: "Association of Future Educators", desc: "Dedicated to pedagogical training, student-teaching support, and literacy community outreach programs." },
                { name: "Patriots Arts and Culture Guild", desc: "Showcasing student talent in dance, theater, music, and campus murals during art festivals." }
            ]
        };
    },

    getPaymentsData() {
        return {
            portals: [
                { name: "Online Payment Portals", channels: ["GCash (Bill Payments -> Search GNCP)", "Maya (Pay Bills -> School category)", "UnionBank Online Banking (Biller ID: GNCP-99)"] },
                { name: "Over-The-Counter Banks", channels: ["Landbank of the Philippines (GNCP Account: 1234-5678-90)", "Metrobank (Bill Payment Form, School code: 541)"] },
                { name: "On-Campus Cashier", channels: ["Direct Cashier payment at the Administration Hall", "Accepts Cash, Debit/Credit Cards, and checks"] }
            ],
            terms: [
                { plan: "Full Payment Plan", desc: "Pay the total tuition and miscellaneous fees upon enrollment and receive a 5% discount on the base tuition rate." },
                { plan: "Semi-Annual Installment Plan", desc: "50% due upon enrollment, remaining 50% due before the Midterm Examination week." },
                { plan: "Quarterly Installment Plan", desc: "30% due upon enrollment, 25% due before Preliminary Exams, 25% due before Midterm Exams, and 20% due before Final Exams." }
            ]
        };
    }
};

