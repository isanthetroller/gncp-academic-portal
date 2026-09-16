const DataModel = window.DataModel;
const MainView = window.MainView;
const PagesView = window.PagesView;

// Simplified shuffle function using traditional temp variable swaps rather than ES6 destructuring
function shuffleArray(array) {
    const arr = [];
    for (let i = 0; i < array.length; i++) {
        arr.push(array[i]);
    }
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        const temp = arr[i];
        arr[i] = arr[j];
        arr[j] = temp;
    }
    return arr;
}

const App = {
    setup() {
        const ref = Vue.ref;
        const reactive = Vue.reactive;
        const computed = Vue.computed;
        const onMounted = Vue.onMounted;
        const onBeforeUnmount = Vue.onBeforeUnmount;

        // Navigation & Layout Info
        const navigation = reactive(DataModel.getNavigation());
        
        // SPA Page Navigation State
        const currentPage = ref('home');
        const selectedDept = ref(localStorage.getItem('gncp_selected_dept') || '');

        const enrollNowUrl = computed(() => {
            if (selectedDept.value) {
                return `../enrollment-system/?dept=${selectedDept.value}`;
            }
            return '../enrollment-system/';
        });
        
        const navigate = (page) => {
            currentPage.value = page;
            if (page === 'acad-it') {
                selectedDept.value = 'COIT';
            } else if (page === 'acad-business') {
                selectedDept.value = 'COBA';
            } else if (page === 'acad-health') {
                selectedDept.value = 'COHS';
            }
            if (selectedDept.value) {
                localStorage.setItem('gncp_selected_dept', selectedDept.value);
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        // Sub-page Content Datasets
        const aboutData = reactive(DataModel.getAboutData());
        const academicsData = reactive(DataModel.getAcademicsData());
        const admissionsData = reactive(DataModel.getAdmissionsData());
        const campusLifeData = reactive(DataModel.getCampusLifeData());
        const paymentsData = reactive(DataModel.getPaymentsData());

        // Hero Carousel State (Shuffled on initialization)
        const slides = ref(shuffleArray(DataModel.getSliderSlides()));
        const currentSlideIndex = ref(0);
        let slideInterval = null;

        const changeSlide = (index) => {
            currentSlideIndex.value = index;
            resetSlideTimer();
        };

        const startSlideTimer = () => {
            slideInterval = setInterval(() => {
                if (slides.value.length > 1) {
                    let nextIndex = currentSlideIndex.value;
                    while (nextIndex === currentSlideIndex.value) {
                        nextIndex = Math.floor(Math.random() * slides.value.length);
                    }
                    currentSlideIndex.value = nextIndex;
                }
            }, 7000); // Stays on screen longer to let staggered elements load
        };

        const resetSlideTimer = () => {
            if (slideInterval) clearInterval(slideInterval);
            startSlideTimer();
        };

        // Feature Grid Cards
        const cards = ref(DataModel.getFeatureCards());

        // Campus Feed / News State
        const allCampusItems = ref(DataModel.getCampusItems());
        const categories = ref(["ALL", "NEWS", "EVENTS", "FEATURES"]);
        const activeCategory = ref("ALL");

        const filteredCampusItems = computed(() => {
            const result = [];
            const activeCat = activeCategory.value;
            const items = allCampusItems.value;

            if (activeCat === "ALL") {
                const count = Math.min(3, items.length);
                for (let i = 0; i < count; i++) {
                    result.push(items[i]);
                }
            } else {
                for (let i = 0; i < items.length; i++) {
                    if (items[i].category === activeCat) {
                        result.push(items[i]);
                    }
                }
            }
            return result;
        });

        const selectCategory = (category) => {
            activeCategory.value = category;
        };

        // Countdown Timer State
        const countdownConfig = reactive(DataModel.getCountdownConfig());
        const timeLeft = reactive({
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0
        });
        let countdownInterval = null;

        const calculateTimeLeft = () => {
            const difference = +new Date(countdownConfig.targetDate) - +new Date();
            
            if (difference > 0) {
                timeLeft.days = Math.floor(difference / (1000 * 60 * 60 * 24));
                timeLeft.hours = Math.floor((difference / (1000 * 60 * 60)) % 24);
                timeLeft.minutes = Math.floor((difference / 1000 / 60) % 60);
                timeLeft.seconds = Math.floor((difference / 1000) % 60);
            } else {
                timeLeft.days = 0;
                timeLeft.hours = 0;
                timeLeft.minutes = 0;
                timeLeft.seconds = 0;
                if (countdownInterval) clearInterval(countdownInterval);
            }
        };

        // Lifecycle Events
        onMounted(() => {
            startSlideTimer();
            calculateTimeLeft();
            countdownInterval = setInterval(calculateTimeLeft, 1000);
        });

        onBeforeUnmount(() => {
            if (slideInterval) clearInterval(slideInterval);
            if (countdownInterval) clearInterval(countdownInterval);
        });

        return {
            navigation,
            currentPage,
            navigate,
            enrollNowUrl,
            aboutData,
            academicsData,
            admissionsData,
            campusLifeData,
            paymentsData,
            slides,
            currentSlideIndex,
            changeSlide,
            cards,
            categories,
            activeCategory,
            filteredCampusItems,
            selectCategory,
            countdownConfig,
            timeLeft
        };
    }
};

// Create and mount the application
const app = Vue.createApp(App);

// Register Components
if (typeof MainView !== 'undefined' && MainView) {
    if (MainView.TopBar) app.component('top-bar', MainView.TopBar);
    if (MainView.NavBar) app.component('nav-bar', MainView.NavBar);
    if (MainView.HeroSlider) app.component('hero-slider', MainView.HeroSlider);
    if (MainView.FeatureGrid) app.component('feature-grid', MainView.FeatureGrid);
    if (MainView.AboutSection) app.component('about-section', MainView.AboutSection);
    if (MainView.CampusFeed) app.component('campus-feed', MainView.CampusFeed);
    if (MainView.CountdownTimer) app.component('countdown-timer', MainView.CountdownTimer);
    if (MainView.MainFooter) app.component('main-footer', MainView.MainFooter);
}

// Register Sub-page Components
if (typeof PagesView !== 'undefined' && PagesView) {
    if (PagesView.AboutPage) app.component('about-page', PagesView.AboutPage);
    if (PagesView.AcademicsPage) app.component('academics-page', PagesView.AcademicsPage);
    if (PagesView.AdmissionsPage) app.component('admissions-page', PagesView.AdmissionsPage);
    if (PagesView.CampusLifePage) app.component('campus-life-page', PagesView.CampusLifePage);
    if (PagesView.PaymentsPage) app.component('payments-page', PagesView.PaymentsPage);
    if (PagesView.PortalsPage) app.component('portals-page', PagesView.PortalsPage);
}

app.mount('#app');
