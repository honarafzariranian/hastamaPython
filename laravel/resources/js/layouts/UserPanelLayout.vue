<script setup>
/**
 * The user-panel shell — the Vue equivalent of `app/templates/user-panel.html`.
 *
 * The legacy page was a single server-rendered document: a right-hand sidebar
 * (`.sidebar-right`) whose items opened modals, and a topbar carrying the live
 * clock, the notification bell, the theme toggle and the profile dropdown.
 * This layout keeps that shape — sidebar + header + content — but the sidebar
 * items now switch the active section instead of opening modals, because each
 * section is a real page component with its own data.
 *
 * Navigation is local state rather than child routes: the router mounts this
 * layout at `/user_panel` with no children (see resources/js/router/index.js),
 * so the active page is held here and rendered through `<component :is>`.  The
 * choice is mirrored into `?page=` so a refresh lands back on the same section.
 *
 * The header shows the signed-in identity from the auth store (`GET /api/me`),
 * the theme toggle from the shared composable, and a logout button that hits the
 * real root `/logout` endpoint (the legacy verb) before clearing local state.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

import DashboardPage from '@/pages/user/DashboardPage.vue';
import ProfilePage from '@/pages/user/ProfilePage.vue';
import LeaveRequestPanel from '@/pages/user/panels/LeaveRequestPanel.vue';
import LeaveReportPanel from '@/pages/user/panels/LeaveReportPanel.vue';
import OvertimeRequestPanel from '@/pages/user/panels/OvertimeRequestPanel.vue';
import OvertimeReportPanel from '@/pages/user/panels/OvertimeReportPanel.vue';
import HourlyPassRequestPanel from '@/pages/user/panels/HourlyPassRequestPanel.vue';
import HourlyPassReportPanel from '@/pages/user/panels/HourlyPassReportPanel.vue';
import AttendanceReportPanel from '@/pages/user/panels/AttendanceReportPanel.vue';
import TicketPage from '@/pages/user/TicketPage.vue';
import NotificationsPage from '@/pages/user/NotificationsPage.vue';

/* The overlay surfaces the legacy document kept as fixed, display-toggled modals. */
import ProfilePanel from '@/pages/user/panels/ProfilePanel.vue';
import UserSupportCenter from '@/pages/user/panels/UserSupportCenter.vue';
import InternalAutomationCenter from '@/pages/user/panels/InternalAutomationCenter.vue';
import NotificationCenter from '@/pages/user/panels/NotificationCenter.vue';
import TicketCreateModal from '@/pages/user/panels/TicketCreateModal.vue';

const router = useRouter();
const auth = useAuthStore();
const { isDark, toggleTheme } = useTheme();

/**
 * The eight sections.  Order matches the legacy sidebar: dashboard first,
 * then the request forms, then the report/support surfaces.
 */
const pages = [
    { id: 'dashboard', label: 'داشبورد', component: DashboardPage },
    { id: 'profile', label: 'پروفایل من', component: ProfilePage },
    { id: 'tickets', label: 'ثبت تیکت', component: TicketPage },
    { id: 'notifications', label: 'اعلان‌های من', component: NotificationsPage },
];
/*
 * `user-panel.html` has no "final report" section: its four report links open
 * the in-page pop-ups now rendered above, and the printable final report is a
 * separate document (`/final_report_page`) that the user panel never linked.
 */

const activePageId = ref('dashboard');
const sidebarOpen = ref(false);
const logoutLoading = ref(false);
const profileOpen = ref(false);
const notifOpen = ref(false);
const reportsOpen = ref(false);
const settingsOpen = ref(false);

/*
 * ── The Python modal layer ──────────────────────────────────────────────────
 *
 * `user-panel-script.js` keeps the three request modals and the four report
 * pop-ups as display-toggled containers plus one `body.leave-modal-open`
 * marker (`updateModalOverlayState()`), and opens each of them from the same
 * sidebar items the legacy document used.  The markup below is those exact
 * containers — same ids, same classes, same display values — so the
 * stylesheet's `.morakhaci-sabt` / `.popup-overlay` rules apply unchanged.
 */
const activeModal = ref('');
const activeReport = ref('');
const dashboardKey = ref(0);

/**
 * ── The standalone overlay surfaces ────────────────────────────────────────
 *
 * `user-panel.html` keeps five more containers as fixed overlays rather than
 * page sections: the profile panel (`#profilePanel`), the support centre
 * (`#userSupportCenter`), the internal-automation centre
 * (`#internalAutomationCenter`), the notification centre
 * (`#notificationCenter`) and the new-ticket form (`#ticketModal`).  The legacy
 * sidebar / dropdown / dashboard cards opened them in place, so the same entry
 * points open them here — one overlay at a time, exactly as the reference did.
 */
const activeOverlay = ref('');

function openOverlay(name) {
    activeOverlay.value = name;
    activeModal.value = '';
    activeReport.value = '';
    sidebarOpen.value = false;
    reportsOpen.value = false;
    profileOpen.value = false;
    notifOpen.value = false;
    syncOverlayState();
}

function closeOverlay() {
    activeOverlay.value = '';
    syncOverlayState();
}

function showModal(name) {
    activeModal.value = name;
    activeReport.value = '';
    sidebarOpen.value = false;
    reportsOpen.value = false;
    syncOverlayState();
}

function hideModal() {
    activeModal.value = '';
    syncOverlayState();
}

function showReport(name) {
    activeReport.value = name;
    activeModal.value = '';
    reportsOpen.value = false;
    syncOverlayState();
}

function hideReport() {
    activeReport.value = '';
    syncOverlayState();
}

/** `updateModalOverlayState()`: one body marker for any open overlay. */
function syncOverlayState() {
    document.body.classList.toggle(
        'leave-modal-open',
        activeModal.value !== ''
        || activeReport.value !== ''
        || activeOverlay.value !== '',
    );
}

/** The legacy forms reloaded `/user_panel`; the SPA remounts the dashboard. */
function refreshDashboard() {
    dashboardKey.value += 1;
}

/** The legacy escape handler closed the settings panel, the profile panel and the sidebar. */
function onEscape(event) {
    if (event.key === 'Escape') {
        hideModal();
        hideReport();
        closeOverlay();
        closeSettings();
        closeSidebar();
    }
}

const activePage = computed(
    () => pages.find((page) => page.id === activePageId.value) ?? pages[0],
);

const activeComponent = computed(() => activePage.value.component);

/**
 * Switch section.  Unknown ids fall back to the dashboard so a hand-edited
 * `?page=` never renders a blank shell.
 */
function navigate(id) {
    const exists = pages.some((page) => page.id === id);
    activePageId.value = exists ? id : 'dashboard';
    sidebarOpen.value = false;
    reportsOpen.value = false;
    router.replace({ query: { page: activePageId.value } }).catch(() => {});
}

onMounted(() => {
    const requested = router.currentRoute.value.query.page;
    if (typeof requested === 'string' && pages.some((page) => page.id === requested)) {
        activePageId.value = requested;
    }

    /*
     * The document state `user-panel.html` carries on `<body>`:
     * `class="user-panel-page" data-notification-role="user"
     * data-notification-actor="{username}"`.
     */
    document.body.classList.add('user-panel-page');
    document.body.dataset.notificationRole = 'user';
    document.body.dataset.notificationActor = auth.username || '';
    document.addEventListener('keydown', onEscape);
    document.addEventListener('click', handleSettingsAccordion);
});

onUnmounted(() => {
    document.body.classList.remove('user-panel-page', 'leave-modal-open', 'settings-panel-open');
    delete document.body.dataset.notificationRole;
    delete document.body.dataset.notificationActor;
    document.removeEventListener('keydown', onEscape);
    document.removeEventListener('click', handleSettingsAccordion);
});

/**
 * Sign out through the real root `/logout` (the verb the legacy `logout()`
 * used), then clear the local identity.  The server call is best-effort: a
 * failure must not leave the user stuck in the panel.
 */
async function logout() {
    if (logoutLoading.value) {
        return;
    }

    logoutLoading.value = true;

    try {
        await api.get('/logout', { baseURL: '' });
    } catch {
        /* the local sign-out below proceeds regardless */
    } finally {
        auth.user = null;
        logoutLoading.value = false;
        router.push('/login');
    }
}

function closeSidebar() {
    sidebarOpen.value = false;
    reportsOpen.value = false;
}

function toggleProfile() {
    profileOpen.value = !profileOpen.value;
}

function closeProfile() {
    profileOpen.value = false;
}

function toggleNotif() {
    notifOpen.value = !notifOpen.value;
    if (notifOpen.value) {
        loadRecentNotifications();
    }
}

function closeNotif() {
    notifOpen.value = false;
}

function toggleReports() {
    reportsOpen.value = !reportsOpen.value;
}

function toggleSettings() {
    settingsOpen.value = !settingsOpen.value;
    document.body.classList.toggle('settings-panel-open', settingsOpen.value);
}

function closeSettings() {
    settingsOpen.value = false;
    document.body.classList.remove('settings-panel-open');
}

function handleSettingsAccordion(event) {
    const toggle = event.target.closest('.settings-accordion-toggle');
    if (!toggle) return;

    const section = toggle.closest('.settings-section');
    if (!section) return;

    const isOpen = section.classList.contains('is-open');
    document.querySelectorAll('.settings-section.is-open').forEach((item) => {
        if (item !== section) {
            item.classList.remove('is-open');
        }
    });

    section.classList.toggle('is-open', !isOpen);
}

// ── Topbar clock ──
const clockDate = ref('--');
const clockTime = ref('--:--:--');
let clockTimer = null;

function updateClock() {
    const now = new Date();
    const persianDate = new Intl.DateTimeFormat('fa-IR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
    const persianTime = new Intl.DateTimeFormat('fa-IR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(now);

    clockDate.value = toPersianDigits(persianDate);
    clockTime.value = toPersianDigits(persianTime);
}

onMounted(() => {
    updateClock();
    clockTimer = setInterval(updateClock, 1000);
});

onUnmounted(() => {
    if (clockTimer) {
        clearInterval(clockTimer);
    }
});

// ── Notification bell ──
const unreadCount = ref(0);
const recentNotifications = ref([]);
const recentLoading = ref(false);

async function loadUnreadCount() {
    try {
        const response = await api.get('/notifications/unread-count');
        unreadCount.value = response?.unread ?? 0;
    } catch {
        /* badge stays at its last known value */
    }
}

async function loadRecentNotifications() {
    recentLoading.value = true;
    try {
        const params = new URLSearchParams({ page: '1', page_size: '5', state: 'all' });
        const response = await api.get(`/notifications?${params.toString()}`);
        recentNotifications.value = Array.isArray(response?.items) ? response.items : [];
        unreadCount.value = response?.unread ?? unreadCount.value;
    } catch {
        recentNotifications.value = [];
    } finally {
        recentLoading.value = false;
    }
}

async function markAllRead() {
    try {
        await api.post('/notifications/read-all');
        unreadCount.value = 0;
        recentNotifications.value = recentNotifications.value.map((item) => ({
            ...item,
            read_at: item.read_at || new Date().toISOString(),
        }));
    } catch {
        /* the bell count refreshes on the next open */
    }
}

onMounted(() => {
    loadUnreadCount().catch(() => {});
    const notifInterval = setInterval(() => {
        loadUnreadCount().catch(() => {});
    }, 60000);
    onUnmounted(() => {
        clearInterval(notifInterval);
    });
});

// ── Profile avatar ──
const DEFAULT_AVATAR = '/images/user.png';
const LOGO_URL = '/images/newlogo.png';
const avatarUrl = ref(DEFAULT_AVATAR);
const avatarFailed = ref(false);

function onAvatarError() {
    avatarFailed.value = true;
    avatarUrl.value = DEFAULT_AVATAR;
}

const displayName = computed(() => auth.user?.username || 'کاربر');
const displayRole = computed(() => auth.user?.role || 'کارشناس فناوری اطلاعات');
</script>

<template>
    <div class="app-shell">
        <!-- نوار بالایی -->
        <header class="topbar">
            <button
                type="button"
                class="mobile-menu-toggle"
                aria-label="باز کردن منو"
                aria-controls="mainSidebar"
                :aria-expanded="sidebarOpen"
                @click="sidebarOpen = true"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div class="topbar-actions">
                <div class="topbar-clock-pill" id="topbarClockBadge" aria-live="polite">
                    <span class="topbar-clock-date" id="topbarDateText">{{ clockDate }}</span>
                    <span class="topbar-clock-time" id="topbarTimeText">{{ clockTime }}</span>
                </div>
                <button
                    type="button"
                    class="topbar-icon-btn notification-bell"
                    id="notificationBell"
                    aria-label="اعلان‌ها"
                    aria-haspopup="dialog"
                    :aria-expanded="notifOpen"
                    @click="toggleNotif"
                >
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="topbar-badge NumberNotif" id="notificationUnreadBadge" :hidden="unreadCount === 0">{{ toPersianDigits(String(unreadCount)) }}</span>
                </button>
                <div
                    class="topbar-icon-btn theme-toggle"
                    id="themeToggleBtn"
                    data-action="toggle-theme"
                    role="button"
                    tabindex="0"
                    @click="toggleTheme"
                >
                    <svg class="theme-toggle-moon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg class="theme-toggle-sun" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8"/><path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div class="topbar-user" id="profileCard" @click="toggleProfile">
                    <div class="topbar-avatar-wrap">
                        <img
                            class="topbar-avatar"
                            id="topbarAvatar"
                            :src="avatarFailed ? DEFAULT_AVATAR : avatarUrl"
                            alt="پروفایل کاربر"
                            @error="onAvatarError"
                        >
                    </div>
                    <div class="topbar-user-info">
                        <span class="topbar-user-name">{{ displayName }}</span>
                        <span class="topbar-user-role">{{ displayRole }}</span>
                    </div>
                    <svg class="chevron-icon" viewBox="0 0 24 24" fill="none"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>

                    <!-- دراپ‌داون پروفایل -->
                    <div class="profile-dropdown" :class="{ 'open': profileOpen }" id="profileDropdown">
                        <div class="pd-header">
                            <div class="pd-header__avatar">
                                <img :src="avatarFailed ? DEFAULT_AVATAR : avatarUrl" alt="پروفایل">
                                <span class="pd-header__status"></span>
                            </div>
                            <div class="pd-header__info">
                                <div class="pd-header__name">{{ displayName }}</div>
                                <div class="pd-header__role">{{ displayRole }}</div>
                            </div>
                        </div>

                        <div class="pd-section">
                            <div class="pd-section__label">حساب کاربری</div>
                            <button type="button" class="pd-item" data-action="open-profile-panel" @click="closeProfile(); openOverlay('profile')">
                                <span class="pd-item__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                                <span class="pd-item__text">پروفایل من</span>
                                <span class="pd-item__arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg></span>
                            </button>
                            <button type="button" class="pd-item" data-action="open-security-panel" @click="closeProfile(); openOverlay('profile')">
                                <span class="pd-item__icon pd-item__icon--green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                                <span class="pd-item__text">امنیت و رمز عبور</span>
                                <span class="pd-item__arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg></span>
                            </button>
                        </div>

                        <div class="pd-section">
                            <div class="pd-section__label">پشتیبانی</div>
                            <button type="button" class="pd-item" data-action="open-support-center" @click="closeProfile(); openOverlay('support')">
                                <span class="pd-item__icon pd-item__icon--cyan"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
                                <span class="pd-item__text">پشتیبانی فنی</span>
                                <span class="pd-item__arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg></span>
                            </button>
                        </div>

                        <div class="pd-section">
                            <a href="/training/lesson/user-dashboard" class="pd-item pd-item--link" target="_blank" rel="noopener" @click="closeProfile()">
                                <span class="pd-item__icon pd-item__icon--blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
                                <span class="pd-item__text">آموزش این صفحه</span>
                                <span class="pd-item__arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- دراپ‌داون اعلان‌ها -->
        <section class="notification-dropdown" id="notificationDropdown" :hidden="!notifOpen" aria-label="اعلان‌های اخیر">
            <header>
                <div>
                    <strong>اعلان‌ها</strong>
                    <span id="notificationDropdownSummary">اعلان‌های اخیر شما</span>
                </div>
                <button type="button" id="notificationDropdownReadAll" @click="markAllRead">خواندن همه</button>
            </header>
            <div id="notificationRecentList" class="notification-recent-list">
                <div v-if="recentLoading" class="notification-loading">در حال دریافت…</div>
                <div v-else-if="recentNotifications.length === 0" class="notification-empty-state">
                    <span class="notification-empty-icon">✓</span>
                    <strong>اعلان تازه‌ای ندارید</strong>
                    <p>همه‌چیز را دیده‌اید.</p>
                </div>
                <article
                    v-for="item in recentNotifications"
                    :key="item.id"
                    class="notification-user-item"
                    :class="{ 'is-unread': !item.read_at }"
                >
                    <span class="notification-type-icon notification-type-icon--{{ item.type }}" aria-hidden="true"></span>
                    <div class="notification-user-content">
                        <div class="notification-user-heading">
                            <strong>{{ item.title }}</strong>
                            <span v-if="!item.read_at" class="notification-unread-label">خوانده‌نشده</span>
                        </div>
                        <p>{{ item.content }}</p>
                        <time>{{ toPersianDigits(item.published_at || '') }}</time>
                    </div>
                </article>
            </div>
            <button type="button" class="notification-view-all" data-action="open-notification-center" @click="closeNotif(); openOverlay('notifications')">مشاهده همه اعلان‌ها</button>
        </section>

        <div class="mobile-sidebar-overlay" @click="closeSidebar" aria-hidden="true"></div>

        <div class="app-body">
            <!-- باکس بالای سایدبار -->
            <div class="sidebar-top-card">
                <div class="sidebar-top-card-content">
                    <div class="sidebar-top-card-mark">
                        <img :src="LOGO_URL" alt="لوگوی هستما" class="sidebar-top-card-image">
                    </div>
                    <div class="sidebar-top-card-text">
                        <span class="sidebar-top-card-title">سامانه هستما</span>
                        <span class="sidebar-top-card-sub">اولین سامانه حضور و غیاب مجهز به هوش مصنوعی</span>
                    </div>
                </div>
            </div>

            <!-- سایدبار راست -->
            <aside class="sidebar-right" :class="{ 'open': sidebarOpen }" id="mainSidebar">
                <button type="button" class="mobile-sidebar-close" aria-label="بستن منو" @click="closeSidebar">×</button>
                <div class="sidebar-logo">
                    <span class="sidebar-logo-mark">
                        <img :src="LOGO_URL" alt="لوگوی هستما" class="sidebar-logo-image">
                    </span>
                    <div class="sidebar-logo-text">
                        <span class="sidebar-logo-title">هستما</span>
                        <span class="sidebar-logo-sub">سامانه هوشمند حضور و غیاب</span>
                    </div>
                </div>

                <nav class="sidebar-nav">
                    <a
                        href="#top"
                        class="sidebar-nav-item"
                        :class="{ 'active': activePageId === 'dashboard' }"
                        @click.prevent="navigate('dashboard')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M3 11.5 12 4l9 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sidebar-label">داشبورد</span>
                        </span>
                    </a>

                    <div
                        class="sidebar-nav-item"
                        id="showMoreMorakhc"
                        data-action="open-leave"
                        role="button"
                        tabindex="0"
                        @click="showModal('leave')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                            <span class="sidebar-label">ثبت مرخصی</span>
                        </span>
                    </div>

                    <div
                        class="sidebar-nav-item"
                        id="submitOvertime"
                        data-action="open-overtime"
                        role="button"
                        tabindex="0"
                        @click="showModal('overtime')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M4 19V10M10 19V5M16 19v-7M4 19h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sidebar-label">ثبت اضافه کاری</span>
                        </span>
                    </div>

                    <div
                        class="sidebar-nav-item"
                        id="submitPass"
                        data-action="open-hourly-pass"
                        role="button"
                        tabindex="0"
                        @click="showModal('pass')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sidebar-label">ثبت پاس ساعتی</span>
                        </span>
                    </div>

                    <div
                        class="sidebar-nav-item reports-item"
                        :class="{ 'expanded': reportsOpen }"
                        id="showMoreEzafetime"
                        @click="toggleReports"
                    >
                        <span class="sidebar-item-content" role="button" tabindex="0">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M4 19V10M10 19V5M16 19v-7M4 19h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sidebar-label">گزارشات</span>
                            <span class="submenu-caret" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" width="14" height="14"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </span>
                        <div class="sidebar-submenu" id="reportsSubmenu" :style="{ display: reportsOpen ? 'flex' : 'none' }">
                            <a href="#" class="sidebar-submenu-item" id="reportLeave" data-report="leave" @click.prevent="showReport('leave')">گزارش مرخصی</a>
                            <a href="#" class="sidebar-submenu-item" id="reportOvertime" data-report="overtime" @click.prevent="showReport('overtime')">گزارش اضافه کاری</a>
                            <a href="#" class="sidebar-submenu-item" id="reportPass" data-report="pass" @click.prevent="showReport('pass')">گزارش پاس های ساعتی</a>
                            <a href="#" class="sidebar-submenu-item" id="reportAttendance" data-report="attendance" @click.prevent="showReport('attendance')">گزارش حضور و غیاب</a>
                        </div>
                    </div>

                    <div
                        class="sidebar-nav-item"
                        id="ticketListIcon"
                        @click="openOverlay('ticket-create')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v11H8l-4 4V5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sidebar-label">ثبت تیکت</span>
                        </span>
                    </div>

                    <div
                        class="sidebar-nav-item"
                        id="internalAutomationNav"
                        data-action="open-internal-automation"
                        role="button"
                        tabindex="0"
                        @click="openOverlay('automation')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M5 5.5A2.5 2.5 0 0 1 7.5 3h9A2.5 2.5 0 0 1 19 5.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4.2A2.5 2.5 0 0 1 4 13.3V5.5A2.5 2.5 0 0 1 5 5.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 8h8M8 11.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                            <span class="sidebar-label">اتوماسیون داخلی</span>
                        </span>
                    </div>

                    <div
                        class="sidebar-nav-item"
                        data-action="open-notification-center"
                        role="button"
                        tabindex="0"
                        @click="openOverlay('notifications')"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M13.7 21a2 2 0 0 1-3.4 0" stroke="currentColor" stroke-width="1.8"/></svg></span>
                            <span class="sidebar-label">اعلان‌های من</span>
                        </span>
                    </div>

                    <div
                        class="sidebar-nav-item"
                        id="showMorepopuphourbox"
                        data-action="toggle-settings-panel"
                        role="button"
                        tabindex="0"
                        @click="toggleSettings"
                    >
                        <span class="sidebar-item-content">
                            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.56 1.04H21a2 2 0 1 1 0 4h-.09A1.7 1.7 0 0 0 19.4 15Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
                            <span class="sidebar-label">تنظیمات</span>
                        </span>
                    </div>
                </nav>

                <!-- پنل تنظیمات -->
                <div class="settings-panel" :class="{ 'is-open': settingsOpen }" id="settingsPanel" :aria-hidden="!settingsOpen" :inert="!settingsOpen">
                    <div class="settings-panel-backdrop" id="settingsPanelBackdrop" @click="closeSettings"></div>
                    <div class="settings-panel-dialog" role="dialog" aria-modal="true" aria-labelledby="settingsPanelTitle">
                        <div class="settings-panel-header">
                            <div>
                                <p class="settings-panel-kicker">⚙️ پیکربندی سیستم</p>
                                <h2 id="settingsPanelTitle">تنظیمات سامانه</h2>
                            </div>
                            <button type="button" class="settings-panel-close" data-action="close-settings-panel" aria-label="بستن تنظیمات" @click="closeSettings">×</button>
                        </div>
                        <div class="settings-panel-body">
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="appearance">
                                    <span>🎨 ظاهر</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-appearance">
                                    <ul>
                                        <li>حالت روشن / تاریک / خودکار</li>
                                        <li>رنگ اصلی سامانه</li>
                                        <li>اندازه فونت</li>
                                        <li>نوع فونت</li>
                                        <li>نمایش انیمیشن‌ها</li>
                                        <li>نمایش افکت‌های پس‌زمینه</li>
                                        <li>تراکم نمایش (Compact / Normal)</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="language">
                                    <span>🌍 زبان و منطقه</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-language">
                                    <ul>
                                        <li>زبان سامانه</li>
                                        <li>تقویم شمسی / میلادی</li>
                                        <li>قالب تاریخ</li>
                                        <li>قالب ساعت (24 یا 12)</li>
                                        <li>منطقه زمانی</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="notifications">
                                    <span>🔔 اعلان‌ها</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-notifications">
                                    <ul>
                                        <li>اعلان داخل سامانه</li>
                                        <li>ایمیل</li>
                                        <li>پیامک</li>
                                        <li>صدای اعلان</li>
                                        <li>نمایش اعلان روی دسکتاپ</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="security">
                                    <span>🔒 امنیت</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-security">
                                    <ul>
                                        <li>مدت زمان خروج خودکار</li>
                                        <li>تغییر رمز اجباری</li>
                                        <li>محدودیت تعداد ورود اشتباه</li>
                                        <li>لیست نشست‌های فعال</li>
                                        <li>مدیریت API Token (اگر داشتی)</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="panel">
                                    <span>🖥️ تنظیمات پنل</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-panel">
                                    <ul>
                                        <li>صفحه پیش‌فرض بعد از ورود</li>
                                        <li>نمایش یا مخفی کردن ویجت‌ها</li>
                                        <li>ترتیب ویجت‌ها</li>
                                        <li>تعداد ردیف‌های جدول</li>
                                        <li>ذخیره فیلترهای جداول</li>
                                        <li>نمایش ستون‌های جدول</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="reports">
                                    <span>📄 گزارش‌ها</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-reports">
                                    <ul>
                                        <li>فرمت پیش‌فرض خروجی</li>
                                        <li>PDF</li>
                                        <li>Excel</li>
                                        <li>CSV</li>
                                        <li>جهت چاپ</li>
                                        <li>سایز کاغذ</li>
                                        <li>نمایش لوگو</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="company">
                                    <span>🏢 اطلاعات شرکت (فقط مدیر)</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-company">
                                    <ul>
                                        <li>لوگو</li>
                                        <li>نام شرکت</li>
                                        <li>آدرس</li>
                                        <li>تلفن</li>
                                        <li>کد اقتصادی</li>
                                        <li>شناسه ملی</li>
                                        <li>متن پایین گزارش‌ها</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="users">
                                    <span>👥 کاربران (مدیر)</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-users">
                                    <ul>
                                        <li>مدیریت نقش‌ها</li>
                                        <li>سطح دسترسی</li>
                                        <li>گروه‌های کاربری</li>
                                        <li>واحدها</li>
                                        <li>سمت‌ها</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="attendance">
                                    <span>🕒 حضور و غیاب</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-attendance">
                                    <ul>
                                        <li>ساعات کاری</li>
                                        <li>شیفت‌ها</li>
                                        <li>تعطیلات</li>
                                        <li>قوانین تأخیر</li>
                                        <li>قوانین اضافه‌کاری</li>
                                        <li>قوانین مرخصی</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="messages">
                                    <span>📧 پیام‌ها</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-messages">
                                    <ul>
                                        <li>تنظیم SMTP</li>
                                        <li>پیامک</li>
                                        <li>قالب ایمیل‌ها</li>
                                        <li>قالب پیامک‌ها</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="integration">
                                    <span>🔌 یکپارچه‌سازی</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-integration">
                                    <ul>
                                        <li>Active Directory</li>
                                        <li>LDAP</li>
                                        <li>API</li>
                                        <li>Webhook</li>
                                        <li>سامانه پیامکی</li>
                                        <li>دستگاه حضور و غیاب</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="backup">
                                    <span>💾 پشتیبان‌گیری</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-backup">
                                    <ul>
                                        <li>تهیه نسخه پشتیبان</li>
                                        <li>بازیابی نسخه</li>
                                        <li>دانلود بکاپ</li>
                                        <li>زمان‌بندی بکاپ</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="logs">
                                    <span>📊 لاگ سامانه</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-logs">
                                    <ul>
                                        <li>لاگ ورود کاربران</li>
                                        <li>لاگ تغییرات</li>
                                        <li>خطاها</li>
                                        <li>لاگ API</li>
                                    </ul>
                                </div>
                            </section>
                            <section class="settings-section">
                                <button type="button" class="settings-accordion-toggle" data-settings-accordion="about">
                                    <span>ℹ️ درباره سامانه</span>
                                    <span class="settings-accordion-icon">+</span>
                                </button>
                                <div class="settings-accordion-content" id="settings-about">
                                    <ul>
                                        <li>نسخه هستما</li>
                                        <li>نسخه دیتابیس</li>
                                        <li>نسخه سرور</li>
                                        <li>مجوزها</li>
                                        <li>راهنما</li>
                                        <li>قوانین استفاده</li>
                                    </ul>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="sidebar-logout" @click="logout">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="sidebar-label">{{ logoutLoading ? 'در حال خروج…' : 'خروج از سیستم' }}</span>
                </div>
            </aside>

            <!-- محتوای اصلی داشبورد -->
            <main class="dashboard-main" id="top">
                <component
                    :is="activeComponent"
                    :key="activePageId + ':' + dashboardKey"
                    @navigate="navigate"
                    @open="showModal"
                    @overlay="openOverlay"
                />
            </main>
        </div>

        <!--
            ═══ The modal layer ═══

            The four containers below are `user-panel.html`'s own: the three
            request modals (`#leaveModal`, `#overtimeModal`, `#hourlyPassModal`),
            the three report pop-ups (`#popupOverlayMorakhsi`,
            `#popupOverlayezafe`, `#popupOverlay`) and the attendance report
            (`#popupHozoor`).  Their bodies are the panel components, so the
            stylesheet sees exactly the same element tree as the legacy page.
        -->

        <!-- پاپ‌آپ ثبت مرخصی -->
        <div
            id="leaveModal"
            class="morakhaci-sabt"
            :style="{ display: activeModal === 'leave' ? 'flex' : 'none' }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="leaveModalTitle"
        >
            <div class="modal" id="leaveModalContent">
                <LeaveRequestPanel
                    v-if="activeModal === 'leave'"
                    @close="hideModal"
                    @submitted="refreshDashboard"
                />
            </div>
        </div>

        <!-- پاپ‌آپ ثبت اضافه کار -->
        <div
            id="overtimeModal"
            class="morakhaci-sabt"
            :style="{ display: activeModal === 'overtime' ? 'flex' : 'none' }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="overtimeModalTitle"
        >
            <div class="modal" id="overtimeModalContentmobile">
                <OvertimeRequestPanel
                    v-if="activeModal === 'overtime'"
                    @close="hideModal"
                    @submitted="refreshDashboard"
                />
            </div>
        </div>

        <!-- پاپ‌آپ پاس ساعتی -->
        <div
            id="hourlyPassModal"
            class="morakhaci-sabt"
            :style="{ display: activeModal === 'pass' ? 'block' : 'none' }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="hourlyPassModalTitle"
        >
            <div class="modal" id="hourlyPassModalContentmobile">
                <HourlyPassRequestPanel
                    v-if="activeModal === 'pass'"
                    @close="hideModal"
                    @submitted="refreshDashboard"
                />
            </div>
        </div>

        <!-- پاپ‌اپ جدول مرخصی های کاربر -->
        <div
            id="popupOverlayMorakhsi"
            class="popup-overlay"
            :style="{ display: activeReport === 'leave' ? 'flex' : 'none' }"
        >
            <LeaveReportPanel v-if="activeReport === 'leave'" @close="hideReport" />
        </div>

        <!-- پاپ‌اپ جدول اضافه کاری های کاربر -->
        <div
            id="popupOverlayezafe"
            class="popup-overlay"
            :style="{ display: activeReport === 'overtime' ? 'flex' : 'none' }"
        >
            <OvertimeReportPanel v-if="activeReport === 'overtime'" @close="hideReport" />
        </div>

        <!-- پاپ‌اپ جدول پاس های ساعتی -->
        <div
            id="popupOverlay"
            class="popup-overlay"
            :style="{ display: activeReport === 'pass' ? 'flex' : 'none' }"
        >
            <HourlyPassReportPanel v-if="activeReport === 'pass'" @close="hideReport" />
        </div>

        <!-- پاپ اپ گزارش ساعت زن -->
        <div
            id="popupHozoor"
            class="popupHozoor-overlay"
            :style="{ display: activeReport === 'attendance' ? 'flex' : 'none' }"
        >
            <AttendanceReportPanel v-if="activeReport === 'attendance'" @close="hideReport" />
        </div>

        <!--
            ═══ The standalone overlay surfaces ═══

            `#profilePanel`, `#userSupportCenter`, `#internalAutomationCenter`,
            `#notificationCenter` and `#ticketModal` are fixed overlays in
            `user-panel.html`.  They render only while open, so the stylesheet
            sees exactly the element tree the reference built on demand.
        -->
        <ProfilePanel v-if="activeOverlay === 'profile'" @close="closeOverlay" />
        <UserSupportCenter v-if="activeOverlay === 'support'" @close="closeOverlay" @new-ticket="openOverlay('ticket-create')" />
        <InternalAutomationCenter v-if="activeOverlay === 'automation'" @close="closeOverlay" />
        <NotificationCenter v-if="activeOverlay === 'notifications'" @close="closeOverlay" />
        <TicketCreateModal
            v-if="activeOverlay === 'ticket-create'"
            @close="closeOverlay"
            @created="closeOverlay"
        />
    </div>
</template>
