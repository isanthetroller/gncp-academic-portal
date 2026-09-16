// View components for the school website
window.MainView = {
    TopBar: {
        props: ['navigation'],
        template: `
            <div class="top-utility-bar d-none d-lg-block">
                <div class="container d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-4">
                        <span><i class="fas fa-envelope text-white me-2"></i><a :href="'mailto:' + navigation.topInfo.email">{{ navigation.topInfo.email }}</a></span>
                        <span><i class="fas fa-phone-alt text-white me-2"></i>{{ navigation.topInfo.phone }}</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="text-white-50" style="font-size: 0.82rem;"><i class="fas fa-location-dot me-1.5 text-warning"></i>Emilio Aguinaldo Highway, Cavite</span>
                    </div>
                </div>
            </div>
        `
    },

    NavBar: {
        props: ['navigation', 'currentPage', 'enrollNowUrl'],
        emits: ['navigate'],
        methods: {
            onNav(page) {
                this.$emit('navigate', page);
                const navCollapse = document.getElementById('mainNavbarContent');
                if (navCollapse && navCollapse.classList.contains('show')) {
                    try {
                        const bsCollapse = bootstrap.Collapse.getInstance(navCollapse) || new bootstrap.Collapse(navCollapse, { toggle: false });
                        bsCollapse.hide();
                    } catch (e) {
                        navCollapse.classList.remove('show');
                    }
                }
            }
        },
        template: `
            <nav class="navbar navbar-expand-lg main-navbar sticky-top">
                <div class="container">
                    <a class="navbar-brand d-flex align-items-center" href="#" @click.prevent="onNav('home')">
                        <img :src="navigation.brand.logo" alt="Logo" class="school-logo-img me-2">
                        <div class="d-flex flex-column ms-2">
                            <span class="brand-main-text">GO-ON</span>
                            <span class="brand-sub-text d-none d-sm-inline-block">NATIONAL COLLEGE</span>
                        </div>
                    </a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbarContent" aria-controls="mainNavbarContent" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="mainNavbarContent">
                        <ul class="navbar-nav mx-auto mb-3 mb-lg-0 align-items-lg-center">
                            <li v-for="item in navigation.menuItems" :key="item.text" class="nav-item" :class="{ dropdown: item.dropdown.length > 0 }">
                                <a v-if="item.dropdown.length === 0" class="nav-link nav-link-custom" 
                                   :class="{ active: currentPage === item.page }" 
                                   href="#" @click.prevent="onNav(item.page)">{{ item.text }}</a>
                                <template v-else>
                                    <a class="nav-link nav-link-custom dropdown-toggle" 
                                       :class="{ active: item.dropdown.some(sub => sub.page === currentPage) }"
                                       href="#" role="button" data-bs-toggle="dropdown">{{ item.text }}</a>
                                    <ul class="dropdown-menu border-0 shadow-sm">
                                        <li v-for="sub in item.dropdown" :key="sub.page">
                                            <a class="dropdown-item py-2 px-3" 
                                               :class="{ active: currentPage === sub.page }"
                                               href="#" @click.prevent="onNav(sub.page)">{{ sub.text }}</a>
                                        </li>
                                    </ul>
                                </template>
                            </li>
                        </ul>
                        <div class="d-flex align-items-center mt-3 mt-lg-0 w-100-mobile">
                            <a :href="enrollNowUrl" class="btn btn-pill btn-pill-green shadow-sm w-100-mobile">
                                <i class="fas fa-user-plus me-2"></i>{{ navigation.cta.text }}
                            </a>
                        </div>
                    </div>
                </div>
            </nav>
        `
    },

    HeroSlider: {
        props: ['slides', 'currentSlideIndex', 'enrollNowUrl'],
        emits: ['change-slide'],
        template: `
            <section class="hero-slider-section">
                <div v-for="(slide, index) in slides" :key="slide.id" 
                     class="hero-slide" :class="{ active: index === currentSlideIndex }"
                     :style="{ backgroundImage: 'url(' + slide.bgImage + ')' }">
                    <div class="hero-tint-overlay"></div>
                    <div class="hero-content-container container">
                        <img src="assets/images/logo-removebg-preview.png" alt="GNCP Seal" class="hero-slide-badge mb-4">
                        <h1 class="hero-title text-uppercase">{{ slide.title }}</h1>
                        <p class="hero-subtext">{{ slide.subtext }}</p>
                        <div>
                            <a :href="enrollNowUrl" class="btn btn-pill btn-pill-white me-3 mb-2">
                                <i class="fas fa-user-plus me-2"></i>ONLINE ENROLLMENT
                            </a>
                            <a href="#about" class="btn btn-pill btn-pill-outline btn-pill-white-border text-white border-2 border-white mb-2">
                                LEARN MORE
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Left & Right Arrow Navigation -->
                <button class="hero-nav-btn hero-nav-left" @click="$emit('change-slide', (currentSlideIndex - 1 + slides.length) % slides.length)" aria-label="Previous Slide">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="hero-nav-btn hero-nav-right" @click="$emit('change-slide', (currentSlideIndex + 1) % slides.length)" aria-label="Next Slide">
                    <i class="fas fa-chevron-right"></i>
                </button>
                
                <!-- Dot Navigation -->
                <div class="hero-controls">
                    <button v-for="(slide, index) in slides" :key="'dot-' + slide.id"
                            class="hero-dot" :class="{ active: index === currentSlideIndex }"
                            @click="$emit('change-slide', index)"></button>
                </div>
                
                <!-- Wave divider at bottom -->
                <div class="wave-divider-wrapper">
                    <svg viewBox="0 0 1440 120" preserveAspectRatio="none">
                        <path d="M0,90 C360,115 720,70 1080,70 C1260,70 1440,95 1440,95 L1440,120 L0,120 Z" fill="rgba(255, 255, 255, 0.22)"></path>
                        <path d="M0,95 C360,117 720,75 1080,75 C1260,75 1440,102 1440,102 L1440,120 L0,120 Z" fill="rgba(255, 255, 255, 0.45)"></path>
                        <path d="M0,100 C360,120 720,80 1080,80 C1260,80 1440,110 1440,110 L1440,120 L0,120 Z" class="wave-divider-fill-bg"></path>
                    </svg>
                </div>
            </section>
        `
    },

    FeatureGrid: {
        props: ['cards'],
        template: `
            <section class="section-cards">
                <div class="container">
                    <div class="row g-4">
                        <div v-for="card in cards" :key="card.id" class="col-md-4">
                            <div class="feature-card">
                                <div>
                                    <div class="feature-card-icon">
                                        <i :class="card.icon"></i>
                                    </div>
                                    <h3 class="feature-card-title text-uppercase">{{ card.title }}</h3>
                                    <p class="feature-card-text">{{ card.text }}</p>
                                </div>
                                <div class="text-center mt-3">
                                    <a :href="card.linkUrl" class="btn btn-pill btn-pill-outline w-100">{{ card.linkText }}</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        `
    },

    AboutSection: {
        props: ['enrollNowUrl'],
        template: `
            <section id="about" class="about-section">
                <div class="container">
                    <div class="row align-items-center g-5">
                        <div class="col-lg-6">
                            <div class="about-collage-container">
                                <div class="dot-grid-pattern dot-grid-top-left"></div>
                                <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=800&q=80" alt="Students Studying" class="collage-img-main">
                                <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=600&q=80" alt="Students Collaborating" class="collage-img-sub">
                                <div class="diagonal-stripes-pattern"></div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <span class="section-tagline">About the College</span>
                            <h2 class="section-title">Quality Education in Dasmariñas</h2>
                            <p class="lead text-dark mb-4" style="line-height:1.7;">
                                Go-on National College of the Philippines (GNCP) is dedicated to providing high-quality academic and technical education to students in Cavite.
                            </p>
                            <p class="text-muted mb-4" style="line-height:1.8;">
                                Our campus features modern learning spaces, fully equipped computer and science laboratories, and library resources to support academic development. We offer specialized undergraduate programs designed to prepare students for professional careers and licensure examinations.
                            </p>
                            <p class="text-muted mb-5" style="line-height:1.8;">
                                With a focus on academic discipline, hands-on training, and community service, GNCP continues to be a trusted institution for students in Dasmariñas and Cavite.
                            </p>
                            <div class="d-flex flex-wrap gap-3">
                                <a :href="enrollNowUrl" class="btn btn-pill btn-pill-green">ONLINE ENROLLMENT</a>
                                <a href="#" class="btn btn-pill btn-pill-outline">Read Campus Profile</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        `
    },

    CampusFeed: {
        props: ['items', 'categories', 'activeCategory'],
        emits: ['select-category'],
        template: `
            <section class="campus-section">
                <!-- Wave divider at top -->
                <div class="wave-divider-wrapper" style="top: -1px; bottom: auto; transform: rotate(180deg);">
                    <svg viewBox="0 0 1440 120" preserveAspectRatio="none">
                        <path d="M0,90 C360,115 720,70 1080,70 C1260,70 1440,95 1440,95 L1440,120 L0,120 Z" fill="rgba(255, 255, 255, 0.22)"></path>
                        <path d="M0,95 C360,117 720,75 1080,75 C1260,75 1440,102 1440,102 L1440,120 L0,120 Z" fill="rgba(255, 255, 255, 0.45)"></path>
                        <path d="M0,100 C360,120 720,80 1080,80 C1260,80 1440,110 1440,110 L1440,120 L0,120 Z" class="wave-divider-fill-white"></path>
                    </svg>
                </div>
                
                <div class="container pt-5">
                    <div class="text-center mb-5">
                        <h2 class="text-uppercase" style="font-weight: 800; font-size: 2.3rem; letter-spacing: 0.5px;">What's Happening On Campus?</h2>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="campus-tabs">
                        <button v-for="cat in categories" :key="cat"
                                class="campus-tab-btn" :class="{ active: cat === activeCategory }"
                                @click="$emit('select-category', cat)">
                            {{ cat }}
                        </button>
                    </div>
                    
                    <!-- Grid of news cards -->
                    <div class="row g-4 justify-content-center">
                        <div v-for="item in items" :key="item.id" class="col-md-6 col-lg-4">
                            <div class="news-card">
                                <div class="news-card-img-wrapper">
                                    <img :src="item.image" :alt="item.title" class="news-card-img">
                                </div>
                                <div class="news-card-body">
                                    <div>
                                        <div class="news-card-meta">
                                            <span class="news-tag">{{ item.category }}</span>
                                            <span class="news-date">| {{ item.date }}</span>
                                        </div>
                                        <h4 class="news-card-title">{{ item.title }}</h4>
                                        <p class="news-card-excerpt">{{ item.excerpt }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-5">
                        <a href="#" class="btn btn-pill btn-pill-white px-5 shadow">VIEW ALL UPDATES</a>
                    </div>
                </div>
                
                <!-- Wave divider at bottom -->
                <div class="wave-divider-wrapper">
                    <svg viewBox="0 0 1440 120" preserveAspectRatio="none">
                        <path d="M0,90 C360,115 720,70 1080,70 C1260,70 1440,95 1440,95 L1440,120 L0,120 Z" fill="rgba(255, 255, 255, 0.22)"></path>
                        <path d="M0,95 C360,117 720,75 1080,75 C1260,75 1440,102 1440,102 L1440,120 L0,120 Z" fill="rgba(255, 255, 255, 0.45)"></path>
                        <path d="M0,100 C360,120 720,80 1080,80 C1260,80 1440,110 1440,110 L1440,120 L0,120 Z" class="wave-divider-fill-white"></path>
                    </svg>
                </div>
            </section>
        `
    },

    CountdownTimer: {
        props: ['timeLeft', 'config'],
        template: `
            <section class="countdown-section">
                <!-- Background decorative elements -->
                <div class="countdown-shapes">
                    <div class="countdown-shape-circle countdown-shape-c1"></div>
                    <div class="countdown-shape-circle countdown-shape-c2"></div>
                    <img src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='30' viewBox='0 0 100 30'><path d='M0,15 Q12.5,0 25,15 T50,15 T75,15 T100,15' fill='none' stroke='%23006A4E' stroke-width='2' stroke-linecap='round'/></svg>" class="countdown-shape-wave countdown-wave-left" alt="decor">
                    <img src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='30' viewBox='0 0 100 30'><path d='M0,15 Q12.5,0 25,15 T50,15 T75,15 T100,15' fill='none' stroke='%23006A4E' stroke-width='2' stroke-linecap='round'/></svg>" class="countdown-shape-wave countdown-wave-right" alt="decor">
                </div>
                
                <div class="container position-relative" style="z-index: 2;">
                    <div class="countdown-box">
                        <h2 class="countdown-date-title">December 16, 2026</h2>
                        <div class="countdown-subtitle text-uppercase">{{ config.label }}</div>
                        
                        <div class="countdown-timer-grid">
                            <div class="countdown-time-unit">
                                <span class="countdown-number">{{ String(timeLeft.days).padStart(3, '0') }}</span>
                                <span class="countdown-label">Day(s)</span>
                            </div>
                            <div class="countdown-divider">:</div>
                            <div class="countdown-time-unit">
                                <span class="countdown-number">{{ String(timeLeft.hours).padStart(2, '0') }}</span>
                                <span class="countdown-label">Hour(s)</span>
                            </div>
                            <div class="countdown-divider">:</div>
                            <div class="countdown-time-unit">
                                <span class="countdown-number">{{ String(timeLeft.minutes).padStart(2, '0') }}</span>
                                <span class="countdown-label">Minute(s)</span>
                            </div>
                            <div class="countdown-divider">:</div>
                            <div class="countdown-time-unit">
                                <span class="countdown-number">{{ String(timeLeft.seconds).padStart(2, '0') }}</span>
                                <span class="countdown-label">Second(s)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        `
    },

    MainFooter: {
        props: ['navigation'],
        emits: ['navigate'],
        template: `
            <footer class="main-footer">
                <div class="container">
                    <div class="row g-5">
                        <div class="col-lg-5">
                            <div class="footer-logo-wordmark text-uppercase cursor-pointer" @click.prevent="$emit('navigate', 'home')">
                                {{ navigation.brand.name }}
                            </div>
                            <p class="footer-text text-muted-light mt-3">
                                Go-on National College of the Philippines — committed to providing holistic, high-quality, and affordable education for the leaders of tomorrow.
                            </p>
                        </div>
                        <div class="col-lg-3 offset-lg-1">
                            <h4 class="footer-column-title">Quick Links</h4>
                            <ul class="footer-links-list list-unstyled">
                                <li class="mb-2"><a href="#" @click.prevent="$emit('navigate', 'about-mission')" class="text-decoration-none text-muted-light"><i class="fas fa-chevron-right me-2 text-gold"></i>Mission & Vision</a></li>
                                <li class="mb-2"><a href="#" @click.prevent="$emit('navigate', 'about-history')" class="text-decoration-none text-muted-light"><i class="fas fa-chevron-right me-2 text-gold"></i>History</a></li>
                                <li class="mb-2"><a href="#" @click.prevent="$emit('navigate', 'acad-it')" class="text-decoration-none text-muted-light"><i class="fas fa-chevron-right me-2 text-gold"></i>College of IT</a></li>
                                <li class="mb-2"><a href="#" @click.prevent="$emit('navigate', 'admission-requirements')" class="text-decoration-none text-muted-light"><i class="fas fa-chevron-right me-2 text-gold"></i>Admissions</a></li>
                                <li class="mb-2"><a href="#" @click.prevent="$emit('navigate', 'payments-portals')" class="text-decoration-none text-muted-light"><i class="fas fa-chevron-right me-2 text-gold"></i>Payment Portals</a></li>
                            </ul>
                        </div>
                        <div class="col-lg-3">
                            <h4 class="footer-column-title">Contact Details</h4>
                            <ul class="footer-contact-info">
                                <li>
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span>Emilio Aguinaldo Highway, Dasmariñas, Cavite, Philippines</span>
                                </li>
                                <li>
                                    <i class="fas fa-phone-alt"></i>
                                    <span>{{ navigation.topInfo.phone }}</span>
                                </li>
                                <li>
                                    <i class="fas fa-envelope"></i>
                                    <span>{{ navigation.topInfo.email }}</span>
                                </li>
                                <li>
                                    <i class="fas fa-clock"></i>
                                    <span>Monday - Saturday 8:00 AM - 5:00 PM</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="footer-bottom text-center">
                        <div class="row align-items-center">
                            <div class="col-md-6 text-md-start mb-3 mb-md-0">
                                © 2026 Go-on National College of the Philippines. All Rights Reserved.
                            </div>
                            <div class="col-md-6 text-md-end">
                                <a href="#" class="text-decoration-none me-3 text-muted-light">Privacy Policy</a>
                                <a href="#" class="text-decoration-none text-muted-light">Terms of Use</a>
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        `
    }
};
