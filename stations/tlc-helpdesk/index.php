<?php
/**
 * GNCP Tlc Helpdesk Station — PHP Session Gate (CVE-GNCP-002 Remediation)
 * Server-side authentication check prevents sessionStorage forgery bypass.
 */
require_once __DIR__ . '../../shared/backend/utils/session_gate.php';
session_gate(['HELPDESK', 'ADMIN', 'SUPER_ADMIN']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Academic Advising & NSTP Confirmation | Go-on National College</title>
    <link href="../../shared/libs/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../shared/libs/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../../shared/libs/fonts/fonts.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="../../shared/css/admin_workstation_theme.css?v=1789204778">
    <link rel="stylesheet" href="../../shared/css/sidebar.css?v=1789204778">
    <script src="../../shared/js/PasswordChangeGuard.js?v=1789204778"></script>
    <style>
         [v-cloak] { display: none !important; }
         /* ── Confirmation Dialog */
         .confirm-overlay { position: fixed; inset: 0; z-index: 99999; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; animation: fadeIn 0.15s ease; }
         @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
         .confirm-card { background: #0f2318; border: 1px solid rgba(255,255,255,0.12); border-radius: 18px; box-shadow: 0 25px 60px rgba(0,0,0,0.6); padding: 36px 36px 28px; width: 100%; max-width: 380px; text-align: center; animation: slideUp 0.2s ease; }
         @keyframes slideUp { from { transform: translateY(16px); opacity:0; } to { transform: translateY(0); opacity:1; } }
         .confirm-icon { font-size: 2.2rem; margin-bottom: 14px; }
         .confirm-title { font-family: 'Outfit', sans-serif; font-weight: 800; color: #fff; font-size: 1.2rem; margin: 0 0 8px; }
         .confirm-msg { color: rgba(255,255,255,0.55); font-size: 0.85rem; margin: 0 0 26px; line-height: 1.5; }
         .confirm-actions { display: flex; gap: 10px; justify-content: center; }
         .confirm-btn-cancel { flex: 1; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; color: rgba(255,255,255,0.7); font-weight: 600; padding: 11px; font-size: 0.88rem; cursor: pointer; transition: background 0.2s; }
         .confirm-btn-cancel:hover { background: rgba(255,255,255,0.12); }
         .confirm-btn-danger { flex: 1; background: linear-gradient(135deg,#ef4444,#dc2626); border: none; border-radius: 10px; color: #fff; font-weight: 700; padding: 11px; font-size: 0.88rem; cursor: pointer; transition: opacity 0.2s; }
         .confirm-btn-danger:hover { opacity: 0.88; }
         /* ── Vertical Timeline Stepper inside Modal Review Card ── */
         .vertical-stepper { display: flex; flex-direction: column; gap: 14px; padding: 6px 0; position: relative; }
         .vertical-step-node { display: flex; align-items: center; gap: 12px; position: relative; }
         .vertical-step-node:not(:last-child)::after {
             content: ''; position: absolute; left: 17px; top: 34px; bottom: -20px; width: 2px; background: #E5E8EC; z-index: 0;
         }
         .vertical-step-node.is-completed:not(:last-child)::after { background: var(--primary-green); }
         .vertical-step-marker {
             width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; background: #FFF; border: 2px solid #E5E8EC; color: #9CA3AF; z-index: 1; transition: all 0.2s ease;
         }
         .is-completed .vertical-step-marker { background: var(--primary-green); border-color: var(--primary-green); color: #FFF; }
         .is-active .vertical-step-marker { background: var(--primary-green); border-color: var(--primary-green); color: #FFF; box-shadow: none !important; }
         .vertical-step-content { display: flex; flex-direction: column; text-align: left; }
         .vertical-step-title { font-size: 0.78rem; font-weight: 700; margin: 0; color: var(--text-dark); line-height: 1.2; }
         .is-completed .vertical-step-title { color: var(--dark-green); }
         .is-active .vertical-step-title { color: var(--primary-green); }
         .vertical-step-status { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #9CA3AF; margin-top: 1px; }
         .is-completed .vertical-step-status { color: var(--primary-green); }
         .is-active .vertical-step-status { color: var(--primary-green); }
     </style>
 </head>
 <body>
     <div id="app" class="dashboard-shell" v-cloak>
         
        <template v-if="currentUser">
            <employee-sidebar 
                station-name="Go-on National College" 
                station-tag="TLC HELPDESK STATION"
                :nav-groups="[
                    {
                        title: 'Overview & Queue',
                        items: [
                            { id: 'dashboard', label: 'Dashboard Overview', icon: 'fa-gauge' },
                            { id: 'queue', label: 'Student Queue', icon: 'fa-users-line', badge: totalInQueue > 0 ? totalInQueue : null },
                            { id: 'history', label: 'Review History', icon: 'fa-clock-rotate-left' }
                        ]
                    }
                ]"
                :current-view="currentView"
                :current-user="currentUser"
                base-path="../../"
                @set-view="setView"
                @logout="handleLogout">
            </employee-sidebar>

        <main class="main-panel">
            <div class="top-bar">
                <div class="top-bar-left">
                    <span class="eyebrow">ADVISING & NSTP CONFIRMATION</span>
                    <span class="top-bar-divider">|</span>
                    <h2>{{ currentTitle }}</h2>
                </div>
                <div class="top-bar-actions">
                    <template v-if="currentView === 'queue'">
                        <input class="search-pill" type="text" v-model="searchQuery" placeholder="Search queue...">
                        <button class="btn-pill btn-pill-green btn-pill-sm" @click="markAllCleared">Mark All Cleared</button>
                    </template>
                    <template v-else>
                        <span v-if="currentView === 'dashboard'" class="search-pill">&nbsp;</span>
                    </template>
                </div>
            </div>

            <!-- Profile View -->
            <div v-if="currentView === 'profile'" class="profile-view-wrap p-3">
                <div class="profile-hero">
                    <div class="avatar-wrap" @click="triggerFileInput" title="Click to change profile picture">
                        <img v-if="formattedAvatar" :src="formattedAvatar" alt="Profile Picture">
                        <div v-else class="avatar-initials">{{ initials }}</div>
                        <div class="avatar-overlay">
                            <i class="fa-solid fa-camera mb-1" style="font-size:1.1rem"></i>
                            <span>Change Photo</span>
                        </div>
                    </div>
                    <input type="file" ref="fileInput" @change="onFileSelected" accept="image/*" style="display:none">
                    <div class="profile-hero-info">
                        <h3>{{ user.name || (currentUser ? currentUser.name : 'Helpdesk Staff') }}<span class="profile-hero-badge">{{ user.role || (currentUser ? currentUser.role : 'HELPDESK') }}</span></h3>
                        <p><i class="fa-solid fa-envelope me-2"></i>{{ user.email || (currentUser ? currentUser.email : 'helpdesk@gncp.edu.ph') }}</p>
                        <p style="margin-top:4px"><i class="fa-solid fa-at me-2"></i>{{ user.username || (currentUser ? currentUser.username : 'helpdesk') }}</p>
                    </div>
                </div>

                <div class="profile-grid">
                    <div class="card p-4">
                        <div class="card-title mb-3" style="font-weight:800; font-size:1.05rem; color:var(--primary, #006A4E);"><i class="fa-solid fa-user-edit me-2"></i> Personal Details</div>
                        <form @submit.prevent="saveStaffProfile">
                            <div style="margin-bottom:14px"><label class="f-label">Full Name</label><input class="f-input" type="text" v-model="user.name" required></div>
                            <div style="margin-bottom:14px"><label class="f-label">Email Address</label><input class="f-input" type="email" v-model="user.email" required></div>
                            <div style="margin-bottom:14px"><label class="f-label">Username / Identity Code</label><input class="f-input" type="text" :value="user.username" disabled></div>
                            <div style="margin-bottom:14px"><label class="f-label">System Role</label><input class="f-input" type="text" :value="user.role" disabled></div>
                            <div class="text-end" style="margin-top:12px">
                                <button type="submit" class="btn-primary-save" :disabled="saving">
                                    <i class="fa-solid fa-save"></i> {{ saving ? 'Saving...' : 'Save Personal Details' }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="card p-4">
                        <div class="card-title mb-3" style="font-weight:800; font-size:1.05rem; color:var(--primary, #006A4E);"><i class="fa-solid fa-shield-alt me-2"></i> Account Security &amp; Password</div>
                        <form @submit.prevent="updatePassword">
                            <div style="margin-bottom:14px">
                                <label class="f-label">Current Password</label>
                                <div style="position:relative">
                                    <input :type="showCurrentPass ? 'text' : 'password'" class="f-input" v-model="pass.current" placeholder="Enter your current password" required style="padding-right:42px">
                                    <button type="button" @click="showCurrentPass = !showCurrentPass" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.85rem;">
                                        <i :class="showCurrentPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'"></i>
                                    </button>
                                </div>
                            </div>
                            <div style="margin-bottom:14px">
                                <label class="f-label">New Password</label>
                                <div style="position:relative">
                                    <input :type="showNewPass ? 'text' : 'password'" class="f-input" v-model="pass.newPass" @input="checkPassStrength" placeholder="At least 6 characters" required style="padding-right:42px">
                                    <button type="button" @click="showNewPass = !showNewPass" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.85rem;">
                                        <i :class="showNewPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'"></i>
                                    </button>
                                </div>
                                <div v-if="pass.newPass" class="pass-strength-bar" :style="{ background: passStrengthColor, width: passStrengthWidth }"></div>
                                <div v-if="pass.newPass" class="pass-strength-text" :style="{ color: passStrengthColor }">{{ passStrengthLabel }}</div>
                            </div>
                            <div style="margin-bottom:14px">
                                <label class="f-label">Confirm New Password</label>
                                <input type="password" class="f-input" v-model="pass.confirm" placeholder="Re-enter new password" :class="{ 'is-invalid': pass.confirm && pass.confirm !== pass.newPass }" required>
                                <div v-if="pass.confirm && pass.confirm !== pass.newPass" style="font-size:0.72rem;color:#dc2626;margin-top:4px;font-weight:600">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Passwords do not match.
                                </div>
                            </div>
                            <div class="text-end" style="margin-top:12px">
                                <button type="submit" class="btn-primary-save" style="background:#003D2B" :disabled="updatingPass || (pass.confirm && pass.confirm !== pass.newPass)">
                                    <i class="fa-solid fa-key"></i> {{ updatingPass ? 'Updating...' : 'Update Password' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div v-if="currentView === 'dashboard'" class="view-section">
                <div class="greeting-banner mb-3 p-3 rounded text-white" style="background: linear-gradient(135deg, #00553e 0%, #003d2b 100%); border-left: 4px solid var(--gold, #d4af37);">
                    <h3 class="m-0 font-weight-bold" style="color: #ffffff; font-size: 1.15rem;">
                        Hello {{ currentUser ? (currentUser.name || currentUser.username) : 'Staff' }}! {{ timeGreeting }}
                    </h3>
                    <p class="m-0 small text-white-50" style="font-size: 0.82rem; margin-top: 2px;">Welcome to TLC Helpdesk Advising &amp; Sectioning Workstation.</p>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total in Queue</div>
                        <div class="stat-value">{{ totalInQueue }}</div>
                        <div class="stat-meta">Current active students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Completed Today</div>
                        <div class="stat-value">{{ completedToday }}</div>
                        <div class="stat-meta">Tasks marked completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Date & Time (PST)</div>
                        <div class="stat-value" style="font-size: 1.05rem; line-height: 1.25; margin-top: 8px; font-weight: 700; color: var(--primary-green);">{{ currentDateTime }}</div>
                        <p class="stat-meta" style="color: var(--primary-gold)">Philippine Standard Time</p>
                    </div>
                </div>

                <div class="content-grid">
                    <section class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Recent Activity</h3>
                                <p>Latest queue entries and reviews</p>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Ref No.</th>
                                        <th>Student Name</th>
                                        <th>Program</th>
                                        <th>NSTP</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Table Skeleton Loading Animation -->
                                    <tr v-if="isLoadingQueue" v-for="n in 4" :key="'skel-rec-' + n" class="table-skeleton-row" aria-hidden="true">
                                        <td><div class="skeleton-bar" style="width: 85px;"></div></td>
                                        <td><div class="skeleton-bar" style="width: 140px;"></div></td>
                                        <td><div class="skeleton-pill" style="width: 60px;"></div></td>
                                        <td><div class="skeleton-pill" style="width: 48px;"></div></td>
                                        <td><div class="skeleton-pill" style="width: 84px;"></div></td>
                                        <td><div class="skeleton-btn" style="width: 64px;"></div></td>
                                    </tr>
                                    <tr v-else v-for="student in students.slice(0, 5)" :key="student.referenceNumber" class="table-row-enter">
                                        <td><code>{{ student.referenceNumber }}</code></td>
                                        <td><strong>{{ student.name }}</strong></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary">{{ student.program }}</span></td>
                                        <td><span class="badge bg-light text-dark border">{{ student.nstp }}</span></td>
                                        <td>
                                            <span class="status-badge" :class="getStatusBadgeClass(student.status)">
                                                <i class="fa-solid fa-circle-check me-1" v-if="['COMPLETED','CLEARED'].includes(String(student.status).toUpperCase())"></i>
                                                <i class="fa-solid fa-clock me-1" v-else-if="String(student.status).toUpperCase() === 'PENDING'"></i>
                                                <i class="fa-solid fa-spinner fa-spin me-1" v-else-if="String(student.status).toUpperCase() === 'IN_PROGRESS'"></i>
                                                <i class="fa-solid fa-flag me-1" v-else-if="String(student.status).toUpperCase() === 'FLAGGED'"></i>
                                                {{ formatStatus(student.status) }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-pill btn-pill-green btn-pill-sm" @click="openReview(student)">{{ student.status === 'COMPLETED' ? 'View' : 'Review' }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="!isLoadingQueue && students.length === 0">
                                        <td colspan="6" class="empty-state text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fs-3 mb-2 d-block text-secondary"></i>
                                            <p class="mb-0 small">No active student activity in the advising queue yet.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Quick Actions</h3>
                                <p>Common advising & assessment workflows</p>
                            </div>
                        </div>
                        <div class="quick-actions-list">
                            <div class="quick-action-item" @click.prevent="setView('queue')">
                                <i class="fa-solid fa-users-line"></i>
                                <div>
                                    <strong>View Full Queue</strong>
                                    <p>Open the active student list</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <template v-else-if="currentView === 'queue'">
                <!-- Quick-Stat Cards matching Registrar style -->
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-4">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left: 4px solid #f59e0b !important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Pending Advising</div>
                            <div style="font-size:1.85rem;font-family:'Outfit',sans-serif;font-weight:900;color:#d97706;line-height:1.1;">{{ pendingCount }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">students waiting in queue</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left: 4px solid #22c55e !important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Advised / Cleared</div>
                            <div style="font-size:1.85rem;font-family:'Outfit',sans-serif;font-weight:900;color:#16a34a;line-height:1.1;">{{ completedToday }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">cleared by advising</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="card border-0 shadow-sm rounded-3 p-3 text-center h-100" style="border-left: 4px solid #ef4444 !important;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:4px;">Flagged Cases</div>
                            <div style="font-size:1.85rem;font-family:'Outfit',sans-serif;font-weight:900;color:#dc2626;line-height:1.1;">{{ flaggedCount }}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;">requires follow-up</div>
                        </div>
                    </div>
                </div>

                <!-- Filters & Sorting Toolbar Card matching Registrar style -->
                <div class="card border border-light-subtle rounded-3 shadow-sm mb-3 p-3" style="background:#fafafa;">
                    <!-- Status pills row -->
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="fw-bold text-uppercase me-1" style="font-size:0.68rem;letter-spacing:.05em;color:#9ca3af;white-space:nowrap;">
                            <i class="fa-solid fa-layer-group text-success me-1"></i>Status Filter:
                        </span>
                        <button class="btn-pill btn-pill-sm py-1"
                                :class="activeFilter === 'All' ? 'btn-pill-green' : 'btn-pill-outline'"
                                @click="setFilter('All')">
                            All <span class="ms-1 badge rounded-pill">{{ students.length }}</span>
                        </button>
                        <button class="btn-pill btn-pill-sm py-1"
                                :class="activeFilter === 'Pending' ? 'btn-pill-green' : 'btn-pill-outline'"
                                @click="setFilter('Pending')">
                            Pending <span class="ms-1 badge rounded-pill">{{ pendingCount }}</span>
                        </button>
                        <button class="btn-pill btn-pill-sm py-1"
                                :class="activeFilter === 'Completed' ? 'btn-pill-green' : 'btn-pill-outline'"
                                @click="setFilter('Completed')">
                            Completed <span class="ms-1 badge rounded-pill">{{ completedToday }}</span>
                        </button>
                        <button class="btn-pill btn-pill-sm py-1"
                                :class="activeFilter === 'Flagged' ? 'btn-pill-green' : 'btn-pill-outline'"
                                @click="setFilter('Flagged')">
                            Flagged <span class="ms-1 badge rounded-pill">{{ flaggedCount }}</span>
                        </button>
                    </div>

                    <!-- Sort Controls Sub-Bar -->
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top border-light-subtle">
                        <span class="fw-bold text-uppercase me-1" style="font-size:0.68rem;letter-spacing:.05em;color:#9ca3af;white-space:nowrap;">
                            <i class="fa-solid fa-arrow-down-a-z text-success me-1"></i>Sort By:
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm py-1" style="max-width:180px;font-size:0.8rem;border-radius:6px;" v-model="sortBy">
                                <option value="arrivedAt">Arrival Time</option>
                                <option value="referenceNumber">Reference ID</option>
                                <option value="name">Applicant Name</option>
                                <option value="program">Program Choice</option>
                                <option value="status">Status</option>
                            </select>
                            <button class="btn-pill btn-pill-sm py-1 px-2.5"
                                    :class="sortDesc ? 'btn-pill-green' : 'btn-pill-outline'"
                                    @click="sortDesc = !sortDesc"
                                    title="Toggle Sort Order">
                                <i :class="sortDesc ? 'fa-solid fa-sort-down' : 'fa-solid fa-sort-up'"></i>
                                {{ sortDesc ? 'Descending' : 'Ascending' }}
                            </button>
                        </div>
                    </div>
                </div>

                <section class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Student Advising Queue</h3>
                            <p>First-Come, First-Served advising assessments & block section assignment — click headers to sort</p>
                        </div>
                        <span class="badge rounded-pill fw-bold font-monospace px-3 py-2"
                              :class="filteredStudents.length > 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-secondary bg-opacity-10 text-secondary'">
                            Showing {{ filteredStudents.length }} of {{ students.length }} Records
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:110px;">Queue Ticket</th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('referenceNumber')">
                                        Ref No. <i :class="getSortIcon('referenceNumber')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('name')">
                                        Student Name <i :class="getSortIcon('name')"></i>
                                    </th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('program')">
                                        Program <i :class="getSortIcon('program')"></i>
                                    </th>
                                    <th>NSTP</th>
                                    <th class="cursor-pointer select-none" @click="toggleSort('status')">
                                        Status <i :class="getSortIcon('status')"></i>
                                    </th>
                                    <th style="width:120px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Table Skeleton Loading Animation -->
                                <tr v-if="isLoadingQueue" v-for="n in 5" :key="'skel-q-' + n" class="table-skeleton-row" aria-hidden="true">
                                    <td><div class="skeleton-ticket"></div></td>
                                    <td><div class="skeleton-bar" style="width: 85px;"></div></td>
                                    <td><div class="skeleton-bar" style="width: 140px;"></div></td>
                                    <td><div class="skeleton-pill" style="width: 65px;"></div></td>
                                    <td><div class="skeleton-pill" style="width: 50px;"></div></td>
                                    <td><div class="skeleton-pill" style="width: 90px;"></div></td>
                                    <td><div class="skeleton-btn" style="width: 75px;"></div></td>
                                </tr>
                                <tr v-else v-for="student in filteredStudents" :key="student.referenceNumber" class="table-row-enter">
                                    <!-- Queue Ticket -->
                                    <td>
                                        <span class="queue-ticket-badge" :class="getQueueRank(student) === 1 ? 'ticket-next' : 'ticket-default'">
                                            <span v-if="getQueueRank(student) === 1" class="ticket-indicator"></span>
                                            {{ student.queueTicket || ('ADV-' + student.id) }}
                                        </span>
                                    </td>
                                    <td><code>{{ student.referenceNumber }}</code></td>
                                    <td><strong>{{ student.name }}</strong></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary">{{ student.program }}</span></td>
                                    <td><span class="badge bg-light text-dark border">{{ student.nstp }}</span></td>
                                    <td>
                                        <span class="status-badge" :class="getStatusBadgeClass(student.status)">
                                            <i class="fa-solid fa-circle-check me-1" v-if="['COMPLETED','CLEARED'].includes(String(student.status).toUpperCase())"></i>
                                            <i class="fa-solid fa-clock me-1" v-else-if="String(student.status).toUpperCase() === 'PENDING'"></i>
                                            <i class="fa-solid fa-spinner fa-spin me-1" v-else-if="String(student.status).toUpperCase() === 'IN_PROGRESS'"></i>
                                            <i class="fa-solid fa-flag me-1" v-else-if="String(student.status).toUpperCase() === 'FLAGGED'"></i>
                                            {{ formatStatus(student.status) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-pill btn-pill-green btn-pill-sm w-100" @click="openReview(student)">
                                                <i class="fa-solid fa-user-check me-1"></i>{{ student.status === 'COMPLETED' ? 'View' : 'Advise' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!isLoadingQueue && filteredStudents.length === 0">
                                    <td colspan="7" class="empty-state text-center py-4 text-muted">
                                        <i class="fa-solid fa-inbox fs-3 mb-2 d-block text-secondary"></i>
                                        <p class="mb-0 small">No students match your filter.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <!-- ── Review History View ──────────────────────────────────────── -->
            <template v-else-if="currentView === 'history'">
                <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-1 font-monospace fw-bold" style="color:var(--dark-green);font-size:1.1rem;">
                                <i class="fa-solid fa-clock-rotate-left me-2"></i>TLC Advising History
                            </h4>
                            <p class="text-muted small mb-0">Audited records of students advised and cleared through TLC Helpdesk</p>
                        </div>
                        <button class="btn-pill btn-pill-outline btn-pill-sm" @click="fetchReviewHistory">
                            <i class="fa-solid fa-rotate me-1"></i>Refresh History
                        </button>
                    </div>
                </div>

                <section class="panel">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3>Completed Advising Records</h3>
                            <p>Students cleared for clinical evaluation and cashier assessment</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold font-monospace px-3 py-2 rounded-pill">
                            {{ completedStudents.length }} Records
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="station-table">
                            <thead>
                                <tr>
                                    <th>Ref / ID</th>
                                    <th>Student Name</th>
                                    <th>Program</th>
                                    <th>Assigned Section</th>
                                    <th>Advised By</th>
                                    <th>Completion Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Table Skeleton Loading Animation -->
                                <tr v-if="isLoadingHistory" v-for="n in 4" :key="'skel-hist-' + n" class="table-skeleton-row" aria-hidden="true">
                                    <td><div class="skeleton-bar" style="width: 85px;"></div></td>
                                    <td><div class="skeleton-bar" style="width: 140px;"></div></td>
                                    <td><div class="skeleton-pill" style="width: 65px;"></div></td>
                                    <td><div class="skeleton-bar" style="width: 90px;"></div></td>
                                    <td><div class="skeleton-bar" style="width: 100px;"></div></td>
                                    <td><div class="skeleton-bar" style="width: 110px;"></div></td>
                                    <td><div class="skeleton-pill" style="width: 75px;"></div></td>
                                </tr>
                                <tr v-else v-for="item in completedStudents" :key="item.auditId || item.referenceNumber || item.id" class="table-row-enter">
                                    <td><code>{{ item.referenceNumber || item.studentId || item.id }}</code></td>
                                    <td><strong>{{ item.studentName || item.name }}</strong></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary">{{ item.program }}</span></td>
                                    <td>
                                        <span v-if="item.sectionCode" class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 font-monospace">
                                            {{ item.sectionCode }}
                                        </span>
                                        <span v-else class="text-muted small">—</span>
                                    </td>
                                    <td>
                                        <div style="font-size:0.82rem;font-weight:600;"><i class="fa-solid fa-user-check me-1 text-muted"></i>{{ item.operatorUsername || 'Helpdesk Staff' }}</div>
                                    </td>
                                    <td>
                                        <div style="font-size:0.8rem;font-weight:500;">{{ item.completedAt ? new Date(item.completedAt).toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }) : 'Cleared' }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-success text-white font-monospace" style="font-size:0.72rem;">
                                            <i class="fa-solid fa-circle-check me-1"></i>Advised
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!isLoadingHistory && completedStudents.length === 0">
                                    <td colspan="7" class="empty-state text-center py-4 text-muted">
                                        <i class="fa-solid fa-clock-rotate-left fs-3 mb-2 d-block text-secondary"></i>
                                        <p class="mb-0 small">No completed advising history found yet.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>


        </main>

    <div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reviewModalLabel">TLC Review — {{ selectedStudent ? selectedStudent.name : '' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4" v-if="selectedStudent">
                        <!-- Column 1: Details -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-label text-muted small uppercase">Full Name</div>
                                <div class="fw-bold text-dark fs-6">{{ selectedStudent.name }}</div>
                            </div>
                            <div class="mb-3">
                                <div class="form-label text-muted small uppercase">Reference Number</div>
                                <div class="font-monospace text-secondary fw-semibold">{{ selectedStudent.referenceNumber }}</div>
                            </div>
                            <div class="mb-3">
                                <div class="form-label text-muted small uppercase">Program / Course</div>
                                <div class="fw-bold text-dark">{{ selectedStudent.program }}</div>
                            </div>
                            <div class="mb-3">
                                <div class="form-label text-muted small uppercase">Student Type</div>
                                <div class="badge bg-success-subtle text-success px-2.5 py-1.5 rounded">{{ selectedStudent.studentType }}</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small uppercase">NSTP Confirmation</label>
                                <select class="form-select" v-model="selectedStudent.nstp" :disabled="selectedStudent.status === 'COMPLETED'">
                                    <option value="ROTC">ROTC</option>
                                    <option value="CWTS">CWTS</option>
                                    <option value="LTS">LTS</option>
                                </select>
                            </div>

                        </div>

                        <!-- Column 2: Progress Timeline & Internal Notes -->
                        <div class="col-md-6">
                            <!-- Tuition Assessment Preview Card -->
                            <div class="panel p-3 mb-3 shadow-sm animate-fade-in" style="background: #FFFBEB; border: 1px solid #FDE68A;">
                                <h6 class="mb-2" style="color: #92400E; font-weight: 700;"><i class="fa-solid fa-calculator me-2"></i>Tuition &amp; Fee Assessment Preview</h6>
                                <div class="row g-2 text-dark small">
                                    <div class="col-8">Tuition Fee ({{ (selectedStudent.prospectusSubjects || []).reduce((acc, s) => acc + parseInt(s.lecture_units) + parseInt(s.lab_units), 0) }} Units × ₱650):</div>
                                    <div class="col-4 text-end fw-semibold">₱{{ ((selectedStudent.prospectusSubjects || []).reduce((acc, s) => acc + parseInt(s.lecture_units) + parseInt(s.lab_units), 0) * 650).toLocaleString('en-US') }}</div>

                                    <div class="col-8">Total Laboratory Fees:</div>
                                    <div class="col-4 text-end fw-semibold">₱{{ (selectedStudent.prospectusSubjects || []).reduce((acc, s) => acc + parseFloat(s.lab_fee), 0).toLocaleString('en-US') }}</div>

                                    <div class="col-8">Miscellaneous Fees (Reg + Library):</div>
                                    <div class="col-4 text-end fw-semibold">₱2,300</div>

                                    <div class="col-8 border-top pt-2 fw-bold text-uppercase" style="color:var(--dark-green);">Total Assessed:</div>
                                    <div class="col-4 border-top pt-2 text-end fw-bold text-dark fs-6">₱{{ ((selectedStudent.prospectusSubjects || []).reduce((acc, s) => acc + parseInt(s.lecture_units) + parseInt(s.lab_units), 0) * 650 + (selectedStudent.prospectusSubjects || []).reduce((acc, s) => acc + parseFloat(s.lab_fee), 0) + 2300).toLocaleString('en-US') }}</div>
                                </div>
                            </div>

                            <div class="panel p-3 mb-3" style="background: rgba(0, 106, 78, 0.03); border: 1px solid rgba(0, 106, 78, 0.1); border-radius: var(--radius-md);">
                                <h6 class="mb-3 text-dark fw-bold" style="font-family: var(--font-heading);">Campus Verification Progress</h6>
                                
                                <div class="vertical-stepper">
                                    <div v-for="(step, index) in selectedStudent.roadmap" :key="step.step" 
                                         class="vertical-step-node" 
                                         :class="{
                                              'is-completed': step.status === 'COMPLETED',
                                              'is-active': step.status === 'IN_PROGRESS',
                                              'is-upcoming': step.status === 'PENDING'
                                         }">
                                        <div class="vertical-step-marker">
                                            <i class="fas fa-check" v-if="step.status === 'COMPLETED'"></i>
                                            <i :class="getStepIcon(step.step)" v-else></i>
                                        </div>
                                        <div class="vertical-step-content">
                                            <span class="vertical-step-title">{{ step.title }}</span>
                                            <span class="vertical-step-status">{{ step.status }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-1">
                                <label class="form-label text-muted small uppercase">TLC Staff Notes</label>
                                <textarea class="form-control" rows="4" v-model="selectedStudent.tlcNotes" placeholder="Enter advising details or internal notes here..." :disabled="selectedStudent.status === 'COMPLETED'"></textarea>
                            </div>
                        </div>

                        <!-- Row 2: Mapped Curriculum Subjects (Full Width) -->
                        <div class="col-12 mt-4 border-top pt-3">
                            <h6 class="mb-3 text-dark fw-bold d-flex align-items-center" style="font-family: var(--font-heading);">
                                <i class="fa-solid fa-book-open text-primary me-2"></i> Advised Curriculum Block (Prospectus)
                            </h6>
                            <div class="table-responsive border rounded-3 bg-light p-1">
                                <table class="table table-sm table-borderless mb-0 align-middle style-table" style="font-size: 0.76rem;">
                                    <thead class="table-light text-muted small uppercase">
                                        <tr class="text-uppercase" style="border-bottom: 2px solid #E2E8F0;">
                                            <th class="ps-3 py-2">Code</th>
                                            <th class="py-2">Subject Title</th>
                                            <th class="text-center py-2">Lec Units</th>
                                            <th class="text-center py-2">Lab Units</th>
                                            <th class="text-end py-2">Lab Fee</th>
                                            <th class="pe-3 py-2">Prerequisites</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="subject in selectedStudent.prospectusSubjects" :key="subject.code" class="border-top bg-white">
                                            <td class="ps-3 py-2.5 fw-bold text-dark">{{ subject.code }}</td>
                                            <td class="py-2.5">{{ subject.title }}</td>
                                            <td class="text-center py-2.5">{{ subject.lecture_units }}</td>
                                            <td class="text-center py-2.5">{{ subject.lab_units }}</td>
                                            <td class="text-end py-2.5 fw-semibold text-secondary">₱{{ parseFloat(subject.lab_fee || 0).toLocaleString('en-US') }}</td>
                                            <td class="pe-3 py-2.5 text-muted">{{ subject.prerequisites || 'None' }}</td>
                                        </tr>
                                        <tr v-if="!selectedStudent.prospectusSubjects || selectedStudent.prospectusSubjects.length === 0">
                                            <td colspan="6" class="text-center text-muted py-3">No curriculum subjects mapped for this program block.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer gap-2">
                    <button type="button" class="btn-pill btn-pill-ghost" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn-pill btn-pill-warning" @click="flagFollowUp" v-if="selectedStudent && selectedStudent.status !== 'COMPLETED'"><i class="fa-solid fa-flag me-1.5"></i>Flag for Follow-up</button>
                    <button type="button" class="btn-pill btn-pill-green" @click="markCompleted" v-if="selectedStudent && selectedStudent.status !== 'COMPLETED'"><i class="fa-solid fa-circle-check me-1.5"></i>Confirm &amp; Mark Completed</button>
                </div>
            </div>
        </div>
        </template>

        <div v-if="showLogoutConfirm" class="confirm-overlay" @click.self="showLogoutConfirm = false">
            <div class="confirm-card">
                <div class="confirm-icon"><i class="fa-solid fa-right-from-bracket text-danger"></i></div>
                <h4 class="confirm-title">Confirm Logout</h4>
                <p class="confirm-msg">Are you sure you want to log out of this station?</p>
                <div class="confirm-actions">
                    <button class="confirm-btn-cancel" @click="showLogoutConfirm = false">Cancel</button>
                    <button class="confirm-btn-danger" @click="confirmLogout">Log Out</button>
                </div>
            </div>
        </div>

    </div> <!-- close app -->

    <script src="../../shared/libs/bootstrap.bundle.min.js"></script>
    <script src="../../shared/libs/vue.global.js"></script>
    <script src="../../shared/libs/sweetalert2.all.min.js"></script>
    <script src="../../shared/js/SessionExpirationGuard.js?v=1789204778"></script>
    <script src="../../shared/js/PasswordChangeGuard.js?v=1789204778"></script>
    <script src="../../shared/js/components/EmployeeSidebar.js?v=1789204778"></script>
    <script src="../../shared/js/StationPipeline.js?v=1789204778"></script>
    <script src="../assets/js/DataBus.js?v=1789204778"></script>
    <script src="assets/js/app.js?v=1789204778"></script>
</body>
</html>
