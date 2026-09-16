/**
 * GNCP Employee Workstation — Reusable Sidebar Navigation Component v2.0
 * Unified sidebar component for all employee workstations (Registrar, TLC Helpdesk, Medical Clinic, Cashier, IT Center).
 * Matches the exact design, CSS tokens, dimensions, and layout of AdminSidebar.
 */

const EmployeeSidebar = {
    name: 'EmployeeSidebar',
    props: {
        stationName: {
            type: String,
            default: 'Go-on National College'
        },
        stationTag: {
            type: String,
            default: 'EMPLOYEE WORKSTATION'
        },
        navGroups: {
            type: Array,
            required: true
        },
        currentView: {
            type: String,
            required: true
        },
        currentUser: {
            type: Object,
            default: () => null
        },
        basePath: {
            type: String,
            default: '../'
        },
        isMobileMenuOpen: {
            type: Boolean,
            default: false
        }
    },
    emits: ['set-view', 'logout', 'close-mobile-menu'],
    data() {
        return {
            avatarFailed: false,
            expandedCategories: {}
        };
    },
    computed: {
        profileUrl() {
            return (this.basePath === '../../' ? '../../shared/profile.html' : '../shared/profile.html');
        },
        logoUrl() {
            return (this.basePath === '../../' ? '../../school-website/assets/images/logo-removebg-preview.png' : '../school-website/assets/images/logo-removebg-preview.png');
        }
    },
    created() {
        this.autoExpandActiveCategory();
    },
    watch: {
        currentView() {
            this.autoExpandActiveCategory();
        },
        'currentUser.avatar'() {
            this.avatarFailed = false;
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
        getIcon(icon) {
            if (!icon) return 'fa-solid fa-circle';
            if (icon.includes(' ')) return icon;
            if (icon.startsWith('fa-')) return 'fa-solid ' + icon;
            return 'fa-solid fa-' + icon;
        },
        hasChildren(item) {
            return item && ((item.children && item.children.length > 0) || (item.subItems && item.subItems.length > 0));
        },
        getChildren(item) {
            return (item && item.children) || (item && item.subItems) || [];
        },
        hasActiveChild(item) {
            const children = this.getChildren(item);
            return children.some(c => (c.id || c.key) === this.currentView);
        },
        isCatExpanded(item) {
            const key = item.id || item.key || item.label || item.title;
            if (this.expandedCategories[key] !== undefined) {
                return this.expandedCategories[key];
            }
            return this.hasActiveChild(item);
        },
        toggleCat(item) {
            const key = item.id || item.key || item.label || item.title;
            const current = this.isCatExpanded(item);
            this.expandedCategories[key] = !current;
        },
        autoExpandActiveCategory() {
            if (!this.navGroups) return;
            this.navGroups.forEach(g => {
                if (!g.items) return;
                g.items.forEach(item => {
                    if (this.hasChildren(item) && this.hasActiveChild(item)) {
                        const key = item.id || item.key || item.label || item.title;
                        this.expandedCategories[key] = true;
                    }
                });
            });
        },
        formattedAvatar(avatar) {
            if (!avatar) return null;
            if (avatar.startsWith('data:image') || avatar.startsWith('http://') || avatar.startsWith('https://')) {
                return avatar;
            }
            const prefix = this.basePath === '../../' ? '../../' : '../';
            if (avatar.startsWith('../') || avatar.startsWith('../../')) {
                return avatar;
            }
            if (avatar.startsWith('uploads/')) {
                return prefix + avatar;
            }
            return prefix + 'uploads/avatars/' + avatar.replace(/^\/+/, '');
        },
        formattedRole(role) {
            if (!role) return 'Workstation Officer';
            const r = String(role).toUpperCase();
            if (r === 'REGISTRAR') return 'Registrar Officer';
            if (r === 'HELPDESK') return 'Advising & NSTP Officer';
            if (r === 'MEDICAL') return 'Campus Clinic Physician';
            if (r === 'CASHIER') return 'Finance Cashier';
            if (r === 'IT_CENTER') return 'IT Center Administrator';
            if (r === 'SUPER_ADMIN') return 'Executive Administrator';
            if (r === 'ADMIN') return 'System Administrator';
            return r.replace(/_/g, ' ');
        },
        displayName(user) {
            if (!user) return 'Staff Operator';
            const name = user.name || user.username || 'Staff Operator';
            if (name.toLowerCase() === 'go-on super admin' || name.toLowerCase() === 'super admin') {
                return 'System Administrator';
            }
            return name;
        }
    },
    template: `
        <aside class="sidebar" :class="{ 'mobile-open': isMobileMenuOpen }">
            <div class="brand">
                <img :src="logoUrl" alt="GNCP Seal" class="brand-logo" @error="$event.target.src='../school-website/assets/images/logo-removebg-preview.png'">
                <div>
                    <h1>{{ stationName }}</h1>
                    <p>{{ stationTag }}</p>
                </div>
            </div>

            <nav class="nav-body">
                <template v-for="(group, gIdx) in navGroups" :key="gIdx">
                    <div class="nav-cat" :style="gIdx > 0 ? 'margin-top: 8px;' : ''">{{ group.title || group.category }}</div>
                    
                    <template v-for="item in group.items" :key="item.id || item.key || item.label">
                        <!-- Accordion Category with Sub-items -->
                        <div v-if="hasChildren(item)" 
                             class="nav-category-wrapper" 
                             :class="{ expanded: isCatExpanded(item), 'has-active-child': hasActiveChild(item) }">
                            <button class="nav-cat-header" 
                                    :class="{ expanded: isCatExpanded(item), 'has-active-child': hasActiveChild(item) }" 
                                    @click="toggleCat(item)">
                                <span><i :class="getIcon(item.icon)"></i> {{ item.label || item.title }}</span>
                                <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
                            </button>
                            <div class="nav-cat-items-wrapper" :class="{ 'is-open': isCatExpanded(item) }">
                                <div class="nav-cat-items">
                                    <button v-for="sub in getChildren(item)" :key="sub.id || sub.key"
                                            class="nav-item sub-item" 
                                            :class="{ active: currentView === (sub.id || sub.key) }" 
                                            @click="selectView(sub.id || sub.key)">
                                        <i :class="getIcon(sub.icon)"></i> {{ sub.label || sub.title }}
                                        <span v-if="sub.badge" class="badge bg-gold text-dark ms-auto" style="font-size:0.65rem; font-weight:800; padding:2px 6px;">{{ sub.badge }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Top-Level Single Item -->
                        <div v-else class="nav-category-wrapper">
                            <button class="nav-cat-header nav-item-top" 
                                    :class="{ active: currentView === (item.id || item.key) }" 
                                    @click="selectView(item.id || item.key)">
                                <span><i :class="getIcon(item.icon)"></i> {{ item.label || item.title }}</span>
                                <span v-if="item.badge" class="badge bg-gold text-dark ms-auto" style="font-size:0.65rem; font-weight:800; padding:2px 6px;">{{ item.badge }}</span>
                            </button>
                        </div>
                    </template>
                </template>

                <!-- Section: Administration / Account -->
                <div class="nav-cat" style="margin-top: 8px;">Administration &amp; User</div>
                <div class="nav-category-wrapper">
                    <button class="nav-cat-header nav-item-top" :class="{ active: currentView === 'profile' }" @click="selectView('profile')">
                        <span><i class="fa-solid fa-user-circle"></i> My Account Profile</span>
                    </button>
                </div>

                <div class="nav-category-wrapper" style="margin-top: 6px;">
                    <button class="nav-cat-header nav-item-top nav-logout" @click="$emit('logout')">
                        <span><i class="fa-solid fa-right-from-bracket"></i> Sign Out / Logout</span>
                    </button>
                </div>
            </nav>

            <div class="sidebar-footer" v-if="currentUser" @click="selectView('profile')" style="cursor:pointer;" title="View & Edit Profile">
                <div class="footer-avatar">
                    <img v-if="currentUser.avatar && !avatarFailed" :src="formattedAvatar(currentUser.avatar)" alt="" @error="onAvatarError">
                    <span v-else>{{ displayName(currentUser).substring(0,2).toUpperCase() }}</span>
                </div>
                <div class="footer-info">
                    <strong>{{ displayName(currentUser) }}</strong>
                    <span>{{ formattedRole(currentUser.role) }}</span>
                </div>
                <i class="fa-solid fa-gear" style="font-size:0.85rem; color:var(--gold, #D4AF37); opacity:0.75; transition: transform 0.2s ease;" onmouseenter="this.style.transform='rotate(45deg)'" onmouseleave="this.style.transform='rotate(0deg)'"></i>
            </div>
        </aside>
    `
};

if (typeof window !== 'undefined') {
    window.EmployeeSidebar = EmployeeSidebar;
    window.StationSidebar = EmployeeSidebar;
}
