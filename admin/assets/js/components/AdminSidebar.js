/**
 * GNCP Admin Portal — Sidebar Navigation Component v1.4
 * Decoupled, reusable sidebar navigation component with formatted avatar linking, error fallback, and clean role labels.
 */

const AdminSidebar = {
    name: 'AdminSidebar',
    props: {
        view: {
            type: String,
            required: true
        },
        expandedCats: {
            type: Object,
            required: true
        },
        currentAdmin: {
            type: Object,
            default: () => null
        },
        isMobileMenuOpen: {
            type: Boolean,
            default: false
        }
    },
    emits: ['set-view', 'logout', 'close-mobile-menu'],
    data() {
        return {
            avatarFailed: false
        };
    },
    watch: {
        'currentAdmin.avatar': {
            handler() {
                this.avatarFailed = false;
            }
        }
    },
    methods: {
        selectView(viewName) {
            this.$emit('set-view', viewName);
            this.$emit('close-mobile-menu');
        },
        onAvatarError() {
            this.avatarFailed = true;
        },
        formattedAvatar(avatar) {
            if (!avatar) return null;
            if (avatar.startsWith('data:image') || avatar.startsWith('http://') || avatar.startsWith('https://')) {
                return avatar;
            }
            if (avatar.startsWith('../')) {
                return avatar;
            }
            if (avatar.startsWith('uploads/')) {
                return '../' + avatar;
            }
            return '../uploads/avatars/' + avatar.replace(/^\/+/, '');
        },
        formattedRole(role) {
            if (!role) return 'Executive Administrator';
            const r = String(role).toUpperCase();
            if (r === 'SUPER_ADMIN') return 'Executive Admin';
            if (r === 'ADMIN') return 'System Administrator';
            return r.replace(/_/g, ' ');
        },
        displayName(admin) {
            if (!admin) return 'System Administrator';
            const name = admin.name || admin.username || 'System Administrator';
            if (name.toLowerCase() === 'go-on super admin' || name.toLowerCase() === 'super admin') {
                return 'System Administrator';
            }
            return name;
        }
    },
    template: `
        <aside class="sidebar" :class="{ 'mobile-open': isMobileMenuOpen }">
            <div class="brand">
                <img src="../school-website/assets/images/logo-removebg-preview.png" alt="GNCP Seal" class="brand-logo">
                <div>
                    <h1>Go-on National College</h1>
                    <p>SUPER ADMIN PORTAL</p>
                </div>
            </div>

            <nav class="nav-body">
                <!-- Section 1: Main Overview -->
                <div class="nav-cat">Main Overview</div>
                <div class="nav-category-wrapper">
                    <button class="nav-cat-header nav-item-top" :class="{ active: view === 'dashboard' }" @click="selectView('dashboard')">
                        <span><i class="fa-solid fa-chart-line"></i> Dashboard Overview</span>
                    </button>
                </div>

                <!-- Section 2: Academic Catalog & Schedules -->
                <div class="nav-cat" style="margin-top: 8px;">Academic Management</div>
                
                <!-- Category 1: School Subjects & Degree Courses -->
                <div class="nav-category-wrapper" :class="{ expanded: expandedCats.catalog, 'has-active-child': ['departments_programs', 'curriculum', 'subjects'].includes(view) }">
                    <button class="nav-cat-header" :class="{ expanded: expandedCats.catalog, 'has-active-child': ['departments_programs', 'curriculum', 'subjects'].includes(view) }" @click="expandedCats.catalog = !expandedCats.catalog">
                        <span><i class="fa-solid fa-folder-open"></i>Subjects &amp; Courses</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
                    </button>
                    <div class="nav-cat-items-wrapper" :class="{ 'is-open': expandedCats.catalog }">
                        <div class="nav-cat-items">
                            <button class="nav-item sub-item" :class="{active: view === 'departments_programs'}" @click="selectView('departments_programs')">
                                <i class="fa-solid fa-graduation-cap"></i> Departments &amp; Courses
                            </button>
                            <button class="nav-item sub-item" :class="{active: view === 'curriculum'}" @click="selectView('curriculum')">
                                <i class="fa-solid fa-network-wired"></i> Subjects per Semester
                            </button>
                            <button class="nav-item sub-item" :class="{active: view === 'subjects'}" @click="selectView('subjects')">
                                <i class="fa-solid fa-book-open"></i> Master List of Subjects
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Category 2: Terms & Class Sections -->
                <div class="nav-category-wrapper" :class="{ expanded: expandedCats.sections, 'has-active-child': ['periods', 'sections'].includes(view) }">
                    <button class="nav-cat-header" :class="{ expanded: expandedCats.sections, 'has-active-child': ['periods', 'sections'].includes(view) }" @click="expandedCats.sections = !expandedCats.sections">
                        <span><i class="fa-solid fa-layer-group"></i>Terms &amp; Class Sections</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
                    </button>
                    <div class="nav-cat-items-wrapper" :class="{ 'is-open': expandedCats.sections }">
                        <div class="nav-cat-items">
                            <button class="nav-item sub-item" :class="{active: view === 'periods'}" @click="selectView('periods')">
                                <i class="fa-solid fa-calendar-days"></i> Enrollment Semesters
                            </button>
                            <button class="nav-item sub-item" :class="{active: view === 'sections'}" @click="selectView('sections')">
                                <i class="fa-solid fa-users-rectangle"></i> Class Sections
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Category 3: Fee Schedule & Tuition Matrix (Temporarily Hidden) -->
                <!--
                <div class="nav-category-wrapper" :class="{ expanded: expandedCats.fees, 'has-active-child': ['fees'].includes(view) }">
                    <button class="nav-cat-header" :class="{ expanded: expandedCats.fees, 'has-active-child': ['fees'].includes(view) }" @click="expandedCats.fees = !expandedCats.fees">
                        <span><i class="fa-solid fa-money-check-dollar"></i>Fee Schedule</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
                    </button>
                    <div class="nav-cat-items-wrapper" :class="{ 'is-open': expandedCats.fees }">
                        <div class="nav-cat-items">
                            <button class="nav-item sub-item" :class="{active: view === 'fees'}" @click="selectView('fees')">
                                <i class="fa-solid fa-receipt"></i> Tuition &amp; Misc Fees
                            </button>
                        </div>
                    </div>
                </div>
                -->

                <!-- Section 3: Administration & System -->
                <div class="nav-cat" style="margin-top: 8px;">Administration &amp; Users</div>

                <div class="nav-category-wrapper">
                    <button class="nav-cat-header nav-item-top" :class="{ active: view === 'operators' }" @click="selectView('operators')">
                        <span><i class="fa-solid fa-users-cog"></i> Staff Logins (Operators)</span>
                    </button>
                </div>

                <div class="nav-category-wrapper">
                    <button class="nav-cat-header nav-item-top" :class="{ active: view === 'student_accounts' }" @click="selectView('student_accounts')">
                        <span><i class="fa-solid fa-id-card"></i> Student Portal Accounts</span>
                    </button>
                </div>

                <div class="nav-category-wrapper">
                    <button class="nav-cat-header nav-item-top" :class="{ active: view === 'announcements' }" @click="selectView('announcements')">
                        <span><i class="fa-solid fa-bullhorn text-warning"></i> Bulletin &amp; Announcements</span>
                    </button>
                </div>

                <div class="nav-category-wrapper">
                    <button class="nav-cat-header nav-item-top" :class="{ active: view === 'profile' }" @click="selectView('profile')">
                        <span><i class="fa-solid fa-user-circle"></i> My Account Profile</span>
                    </button>
                </div>

                <div class="nav-category-wrapper" style="margin-top: 6px;">
                    <button class="nav-cat-header nav-item-top nav-logout" @click="$emit('logout')">
                        <span><i class="fa-solid fa-right-from-bracket"></i> Sign Out / Logout</span>
                    </button>
                </div>
            </nav>

            <div class="sidebar-footer" v-if="currentAdmin" @click="selectView('profile')" style="cursor:pointer;" title="View & Edit Profile">
                <div class="footer-avatar">
                    <img v-if="currentAdmin.avatar && !avatarFailed" :src="formattedAvatar(currentAdmin.avatar)" alt="" @error="onAvatarError">
                    <span v-else>{{ displayName(currentAdmin).substring(0,2).toUpperCase() }}</span>
                </div>
                <div class="footer-info">
                    <strong>{{ displayName(currentAdmin) }}</strong>
                    <span>{{ formattedRole(currentAdmin.role) }}</span>
                </div>
                <i class="fa-solid fa-gear" style="font-size:0.85rem; color:var(--gold); opacity:0.75; transition: transform 0.2s ease;" onmouseenter="this.style.transform='rotate(45deg)'" onmouseleave="this.style.transform='rotate(0deg)'"></i>
            </div>
        </aside>
    `
};

if (typeof window !== 'undefined') {
    window.AdminSidebar = AdminSidebar;
}
