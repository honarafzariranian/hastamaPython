<script setup>
/**
 * The admin panel shell — a port of `app/templates/admin.html`.
 *
 * Why the markup is copied rather than restyled: the running application loads
 * `admin.css` (12,442 lines) and twelve other sheets for this page, and every
 * one of them selects the legacy vocabulary — `.page-shell`, `.topbar`,
 * `.navarha`, `.sidebar-right`, `.icon-container`, `.management-box`.  A Vue
 * redesign cannot match it, so the shell keeps the legacy structure and the
 * ported sheets style it exactly as they style the running application.
 *
 * **URL contract.**  The legacy document is an SPA whose path names the
 * section (`SECTION_URLS` in `admin.js`): `/admin/dashboard`,
 * `/admin/coworkers`, `/admin/vacation`, `/admin/overtime`, `/admin/hourly-pass`,
 * `/admin/tickets`, `/admin/internal-automation`, `/admin/shifts`,
 * `/admin/attendance`, `/admin/payroll`.  `navTo()` pushed that path with
 * `history.pushState`, and the page read it back on load and on `popstate`.
 * Here the router owns it: the section comes from `route.params.section`, the
 * sidebar pushes the same URLs, and `/admin` itself is a 303 redirect to
 * `/admin/dashboard` on the server, exactly as the Python handler answered.
 *
 * Each section renders inside the `.management-box` wrapper carrying the id the
 * legacy document used (`dashboardBox`, `coworkerBox`, …) so the stylesheet's
 * `#id` rules — including the sidebar-hover compression in
 * `.page-shell.sidebar-expanded` — keep working.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

import DashboardPage from '@/pages/admin/DashboardPage.vue';
import UsersPage from '@/pages/admin/UsersPage.vue';
import LeavePage from '@/pages/admin/LeavePage.vue';
import OvertimePage from '@/pages/admin/OvertimePage.vue';
import HourlyPassPage from '@/pages/admin/HourlyPassPage.vue';
import ShiftsPage from '@/pages/admin/ShiftsPage.vue';
import PayrollPage from '@/pages/admin/PayrollPage.vue';
import TicketsPage from '@/pages/admin/TicketsPage.vue';
import InternalAutomationPage from '@/pages/admin/InternalAutomationPage.vue';
import AttendancePage from '@/pages/admin/AttendancePage.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { isDark, toggleTheme } = useTheme();

/*
 * One entry per legacy `icon-container`.  `box` is the id the legacy document
 * carried, `id` is the path segment `navTo()` pushed, and `accent` is the
 * `data-accent` the stylesheet keys the gradient tile off.
 */
const sections = [
    { id: 'dashboard', box: 'dashboardBox', label: 'داشبورد', accent: 'dashboard', component: DashboardPage },
    { id: 'coworkers', box: 'coworkerBox', label: 'مدیریت کارکنان', accent: 'staff', component: UsersPage },
    { id: 'vacation', box: 'vacationBox', label: 'مدیریت مرخصی ها', accent: 'leave', component: LeavePage },
    { id: 'overtime', box: 'overtimeBox', label: 'مدیریت اضافه کاری ها', accent: 'overtime', component: OvertimePage },
    { id: 'hourly-pass', box: 'hourlyPassBox', label: 'مدیریت پاس های ساعتی', accent: 'pass', component: HourlyPassPage },
    { id: 'tickets', box: 'ticketBox', label: 'مدیریت تیکت ها', accent: 'ticket', component: TicketsPage },
    {
        id: 'internal-automation',
        box: 'internalAutomationAdminBox',
        label: 'اتوماسیون داخلی',
        accent: 'automation',
        component: InternalAutomationPage,
    },
    { id: 'shifts', box: 'shiftBox', label: 'مدیریت شیفت‌ها', accent: 'shift', component: ShiftsPage },
    { id: 'attendance', box: 'hozoorbox', label: 'مدیریت ساعت زن', accent: 'attendance', component: AttendancePage },
    { id: 'payroll', box: 'payrollBox', label: 'حقوق و دستمزد', accent: 'payroll', component: PayrollPage },
];

const DEFAULT_SECTION = 'dashboard';

/*
 * `toggleBox()` in `admin.js` does two things to the box it shows, and both are
 * stylesheet-visible rather than cosmetic:
 *
 *   * it adds `.is-visible` — the selector the mobile sheet keys
 *     `display: block !important` and the section's entrance animation off, and
 *     the `body:has(#x.is-visible)` rules the desktop sheet keys the other
 *     sections' fixed-position handling off;
 *   * it sets an inline `display` — `flex` for every box, except `payrollBox`
 *     and `hozoorbox`, which the legacy sets to `block`.
 *
 * The port renders exactly one box (the router owns the section), so this is
 * the same two writes applied to the active box on every navigation.
 */
const BLOCK_BOXES = new Set(['payrollBox', 'hozoorbox']);

function boxDisplay(boxId) {
    return BLOCK_BOXES.has(boxId) ? 'block' : 'flex';
}

/* Bound rather than literal: a static `src="/images/…"` is treated as a build
 * import by Vite and fails the bundle when the file lives in `public/`. */
const logoUrl = '/images/newlogo.png?v=20260928';
const avatarUrl = '/images/user.png';

const activeSection = computed(
    () => sections.find((item) => item.id === route.params.section) ?? sections.find((item) => item.id === DEFAULT_SECTION),
);

/* The rail tile the legacy `toggleBox` marked `.active`. */
function isActive(item) {
    return activeSection.value?.id === item.id;
}

/* `navTo(boxId, el, url)` — same path, same push, sidebar closes on mobile. */
function openSection(item) {
    closeSidebar();

    if (route.params.section === item.id) {
        return;
    }

    router.push(`/admin/${item.id}`);
}

/*
 * Legacy sidebar behaviour, ported from `toggleSidebar` / `closeMobileSidebar`:
 * `.open` on the rail, `mobile-sidebar-open` on <body> below 768px, and
 * `.sidebar-expanded` on `.page-shell` above it (which is what compresses the
 * management boxes).
 */
const sidebarOpen = ref(false);
const pageShell = ref(null);

function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value;
    syncSidebarClasses();
}

function closeSidebar() {
    if (!sidebarOpen.value) {
        return;
    }

    sidebarOpen.value = false;
    syncSidebarClasses();
}

function syncSidebarClasses() {
    const shell = pageShell.value;
    const narrow = typeof window !== 'undefined' && window.innerWidth <= 768;

    document.body.classList.toggle('mobile-sidebar-open', sidebarOpen.value && narrow);
    shell?.classList.toggle('sidebar-expanded', sidebarOpen.value && !narrow);

    if (sidebarOpen.value) {
        document.addEventListener('click', closeSidebarOnOutsideClick);
    } else {
        document.removeEventListener('click', closeSidebarOnOutsideClick);
    }
}

/* Desktop hover-expand (`admin.js` binds mouseenter/mouseleave on the rail). */
function expandSidebar() {
    if (typeof window !== 'undefined' && window.innerWidth > 768) {
        pageShell.value?.classList.add('sidebar-expanded');
    }
}

function collapseSidebar() {
    if (!sidebarOpen.value) {
        pageShell.value?.classList.remove('sidebar-expanded');
    }
}

function closeSidebarOnOutsideClick(event) {
    const rail = sidebarOpen.value ? document.getElementById('mainSidebar') : null;

    if (rail && !rail.contains(event.target) && !event.target.closest('.mobile-menu-toggle')) {
        closeSidebar();
    }
}

/* ── Profile dropdown (`toggleProfileDropdown`, `.profile-dropdown.open`) ── */

const profileOpen = ref(false);

function toggleProfile() {
    profileOpen.value = !profileOpen.value;
}

function closeProfileOnOutsideClick(event) {
    if (profileOpen.value && !event.target.closest('#profileButton')) {
        profileOpen.value = false;
    }
}

/*
 * The dropdown's panels (profile, security, subscription, billing, support,
 * settings) are built by `openProfilePanel()` inside the legacy `admin.js` and
 * have no ported counterpart yet.  Rather than leaving silent dead buttons, the
 * click reports the gap in the panel's own message strip.
 */
const notice = ref('');

function pendingPanel(label) {
    profileOpen.value = false;
    notice.value = `«${label}» هنوز به نسخهٔ Vue منتقل نشده است.`;
}

/* ── Clock — the legacy `updateTopbarClock` (Jalali date + 24h clock) ── */

const now = ref(new Date());
let clockTimer = null;

onMounted(() => {
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);

    document.addEventListener('click', closeProfileOnOutsideClick);

    /* `admin.html` put the notification identity on <body>; the ported
       notification system (when it lands) reads it from there. */
    document.body.dataset.notificationRole = 'admin';
    document.body.dataset.notificationActor = auth.username || 'admin';
});

onBeforeUnmount(() => {
    if (clockTimer) {
        clearInterval(clockTimer);
    }

    document.removeEventListener('click', closeProfileOnOutsideClick);
    document.removeEventListener('click', closeSidebarOnOutsideClick);
    document.body.classList.remove('mobile-sidebar-open');
    delete document.body.dataset.notificationRole;
    delete document.body.dataset.notificationActor;
});

const clockDate = computed(() =>
    toPersianDigits(
        new Intl.DateTimeFormat('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(now.value),
    ),
);
const clockTime = computed(() =>
    toPersianDigits(
        new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(
            now.value,
        ),
    ),
);

/* ── Identity ── */

const adminName = computed(() => auth.user?.name || auth.username || 'مدیر سیستم');
const adminInitial = computed(() => String(adminName.value).trim().charAt(0) || 'م');

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}

/*
 * The rail tiles, verbatim from `admin.html` (each SVG is the legacy artwork,
 * including the tinted check/cross badges that colour the tile).
 */
const tileIcons = {
    dashboard:
        '<rect x="3.5" y="3.5" width="10" height="8" rx="2.6" fill="#fff"/><rect x="15.5" y="3.5" width="5" height="8" rx="2.5" fill="#fff" opacity=".55"/><rect x="3.5" y="13.5" width="5" height="7" rx="2.5" fill="#fff" opacity=".55"/><rect x="10.5" y="13.5" width="10" height="7" rx="2.6" fill="#fff" opacity=".85"/>',
    staff:
        '<circle cx="9" cy="7.6" r="3.4" fill="#fff"/><path d="M3.2 20c.6-3.4 2.9-5.2 5.8-5.2s5.2 1.8 5.8 5.2" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="16.8" cy="9.2" r="2.5" fill="#fff" opacity=".6"/><path d="M16.2 14.7c2.3.5 4 2.1 4.4 4.3" stroke="#fff" stroke-opacity=".6" stroke-width="2" stroke-linecap="round"/>',
    leave:
        '<rect x="3" y="5" width="14.5" height="16" rx="3.5" stroke="#fff" stroke-width="1.9"/><path d="M3 10h14.5" stroke="#fff" stroke-width="1.9"/><path d="M7 2.8V6M13.5 2.8V6" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M6.8 14.3h3.6" stroke="#fff" stroke-width="1.7" stroke-linecap="round" opacity=".7"/><circle cx="17" cy="17" r="5.2" fill="#fff"/><path d="M14.7 17.1l1.6 1.6 3.1-3.4" stroke="#EA580C" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
    overtime:
        '<circle cx="12" cy="13" r="8" fill="#fff" opacity=".14"/><circle cx="12" cy="13" r="8" stroke="#fff" stroke-width="1.9"/><path d="M12 8.5V13l3 1.9" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18.8" cy="5.5" r="3.8" fill="#fff"/><path d="M18.8 3.7v3.6M17 5.5h3.6" stroke="#A855F7" stroke-width="1.7" stroke-linecap="round"/>',
    pass:
        '<circle cx="11" cy="13" r="8" fill="#fff" opacity=".14"/><circle cx="11" cy="13" r="8" stroke="#fff" stroke-width="1.9"/><path d="M11 8.5V13l3 1.9" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="19.2" cy="6" r="3.5" fill="#fff"/><path d="M17.8 6H20.6M19.2 4.6V7.4" stroke="#0EA5E9" stroke-width="1.5" stroke-linecap="round"/>',
    ticket:
        '<path d="M4 7.8A2.8 2.8 0 016.8 5h10.4A2.8 2.8 0 0120 7.8v1.5a2.9 2.9 0 000 5.4v1.5a2.8 2.8 0 01-2.8 2.8H6.8A2.8 2.8 0 014 16.2v-1.5a2.9 2.9 0 000-5.4V7.8z" fill="#fff" opacity=".14"/><path d="M4 7.8A2.8 2.8 0 016.8 5h10.4A2.8 2.8 0 0120 7.8v1.5a2.9 2.9 0 000 5.4v1.5a2.8 2.8 0 01-2.8 2.8H6.8A2.8 2.8 0 014 16.2v-1.5a2.9 2.9 0 000-5.4V7.8z" stroke="#fff" stroke-width="1.9"/><path d="M14.2 7.5v9" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="2 2.4"/><path d="M7.2 10.2h3.4M7.2 13.8h3.4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".8"/>',
    automation:
        '<path d="M5 5.5A2.5 2.5 0 0 1 7.5 3h9A2.5 2.5 0 0 1 19 5.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4.2A2.5 2.5 0 0 1 4 13.3V5.5Z" fill="#fff" opacity=".14"/><path d="M5 5.5A2.5 2.5 0 0 1 7.5 3h9A2.5 2.5 0 0 1 19 5.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4.2A2.5 2.5 0 0 1 4 13.3V5.5Z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 8h8M8 11.5h5" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><circle cx="17.4" cy="17.4" r="3.2" fill="#fff"/><path d="M17.4 15.8v3.2M15.8 17.4h3.2" stroke="#087F72" stroke-width="1.4" stroke-linecap="round"/>',
    shift:
        '<rect x="3" y="4.5" width="18" height="16" rx="3.5" stroke="#fff" stroke-width="1.9"/><path d="M3 9.5h18" stroke="#fff" stroke-width="1.9"/><path d="M7.5 2.8v3.4M16.5 2.8v3.4" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><circle cx="12" cy="15" r="4.6" fill="#fff"/><path d="M12 12.7v2.5l1.8 1.1" stroke="#4F46E5" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
    attendance:
        '<path d="M4 8.5V6.5A2.5 2.5 0 016.5 4h2M15.5 4h2A2.5 2.5 0 0120 6.5v2M20 15.5v2a2.5 2.5 0 01-2.5 2.5h-2M8.5 20h-2A2.5 2.5 0 014 17.5v-2" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><circle cx="9.3" cy="10.8" r="1.25" fill="#fff"/><circle cx="14.7" cy="10.8" r="1.25" fill="#fff"/><path d="M9 14.8c.9.9 1.9 1.3 3 1.3s2.1-.4 3-1.3" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>',
    payroll:
        '<rect x="3" y="7" width="18" height="13" rx="3.2" fill="#fff" opacity=".14"/><rect x="3" y="7" width="18" height="13" rx="3.2" stroke="#fff" stroke-width="1.9"/><path d="M6.5 7V6a2 2 0 012-2h8" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M6.5 11h6" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".75"/><circle cx="16.5" cy="14.5" r="4" fill="#fff"/><circle cx="16.5" cy="14.5" r="2.1" stroke="#D97706" stroke-width="1.4"/><path d="M16.5 13.4v2.2" stroke="#D97706" stroke-width="1.3" stroke-linecap="round"/>',
    exit:
        '<path d="M13.5 4.5H8a4 4 0 00-4 4v7a4 4 0 004 4h5.5" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><circle cx="9" cy="12" r="1.3" fill="#fff"/><path d="M11 12h9" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M16.8 8.8L20 12l-3.2 3.2" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
};

/** The `sidebar-icon-tile` artwork, wrapped in the SVG element the legacy tile carried. */
function tileIcon(accent) {
    return `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">${tileIcons[accent] ?? ''}</svg>`;
}
</script>

<template>
    <div ref="pageShell" class="page-shell">
        <a class="h-skip" :href="`#${activeSection?.box ?? 'admin-main'}`">پرش به محتوای اصلی</a>

        <header class="topbar admin-topbar-modern ma-header-box">
            <div class="ma-header-left">
                <button
                    type="button"
                    class="mobile-menu-toggle"
                    aria-label="باز کردن منو"
                    aria-controls="mainSidebar"
                    :aria-expanded="sidebarOpen"
                    @click.stop="toggleSidebar"
                >
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <div class="sidebar-top-card ma-header-brand">
                    <div class="sidebar-top-card-content">
                        <div class="sidebar-top-card-mark">
                            <img
                                :src="logoUrl"
                                alt="لوگوی هستما"
                                class="sidebar-top-card-image"
                            >
                        </div>
                        <div class="sidebar-top-card-text">
                            <span class="sidebar-top-card-title">سامانه هستما</span>
                            <span class="sidebar-top-card-sub">پنل مدیریت | سامانه هوشمند حضور و غیاب</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="topbar-actions">
                <div class="topbar-clock-pill" aria-live="polite">
                    <span class="topbar-clock-date">{{ clockDate }}</span>
                    <span class="topbar-clock-time">{{ clockTime }}</span>
                </div>

                <button
                    type="button"
                    class="topbar-icon-btn notification-bell"
                    aria-label="مدیریت اعلان‌ها"
                    @click="pendingPanel('مدیریت اعلان‌ها')"
                >
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 22C13.1046 22 14 21.1046 14 20H10C10 21.1046 10.8954 22 12 22Z" fill="currentColor" />
                        <path
                            d="M18 16V11C18 7.68629 16.2091 4.86798 13.25 4.21834V3.5C13.25 3.08579 12.9142 2.75 12.5 2.75C12.0858 2.75 11.75 3.08579 11.75 3.5V4.21834C8.79095 4.86798 7 7.68629 7 11V16L5 18V19H19V18L18 16Z"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <span class="topbar-badge notification-unread-badge" hidden>۰</span>
                </button>

                <div
                    class="topbar-icon-btn theme-toggle"
                    role="button"
                    tabindex="0"
                    data-action="toggle-theme"
                    :aria-label="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                    @click="toggleTheme"
                    @keydown.enter.prevent="toggleTheme"
                    @keydown.space.prevent="toggleTheme"
                >
                    <svg
                        class="theme-toggle-moon"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path
                            d="M21 12.79C20.55 12.93 20.08 13 19.59 13C15.59 13 12.29 9.7 12.29 5.7C12.29 5.21 12.36 4.74 12.5 4.29C9.21 4.84 6.82 7.83 6.82 11.32C6.82 15.14 10.17 18.49 14 18.49C17.49 18.49 20.48 16.1 21.03 12.81C21.02 12.81 21.01 12.79 21 12.79Z"
                            fill="currentColor"
                        />
                    </svg>
                    <svg
                        class="theme-toggle-sun"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8" />
                        <path
                            d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>

                <div id="profileButton" class="topbar-user" @click.stop="toggleProfile">
                    <div class="topbar-avatar-wrap">
                        <img class="topbar-avatar" :src="avatarUrl" alt="پروفایل مدیریت">
                    </div>
                    <div class="topbar-user-info">
                        <span class="topbar-user-name">{{ adminName }}</span>
                        <span class="topbar-user-role">ادمین</span>
                    </div>
                    <svg class="chevron-icon" viewBox="0 0 24 24" fill="none">
                        <path
                            d="m6 9 6 6 6-6"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                    <div id="profileDropdown" class="profile-dropdown" :class="{ open: profileOpen }">
                        <div class="pd-header">
                            <div class="pd-header__avatar">
                                <img :src="avatarUrl" alt="پروفایل">
                                <span class="pd-header__status"></span>
                            </div>
                            <div class="pd-header__info">
                                <div class="pd-header__name">{{ adminName }}</div>
                                <div class="pd-header__role">{{ auth.username || 'admin' }}</div>
                            </div>
                        </div>

                        <div class="pd-section">
                            <div class="pd-section__label">حساب کاربری</div>
                            <button type="button" class="pd-item" @click="pendingPanel('پروفایل من')">
                                <span class="pd-item__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                                </span>
                                <span class="pd-item__text">پروفایل من</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </button>
                            <button type="button" class="pd-item" @click="pendingPanel('امنیت و رمز عبور')">
                                <span class="pd-item__icon pd-item__icon--green">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>
                                </span>
                                <span class="pd-item__text">امنیت و رمز عبور</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </button>
                        </div>

                        <div class="pd-section">
                            <div class="pd-section__label">مالی و اشتراک</div>
                            <button type="button" class="pd-item" @click="pendingPanel('اشتراک من')">
                                <span class="pd-item__icon pd-item__icon--purple">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></svg>
                                </span>
                                <span class="pd-item__text">اشتراک من</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </button>
                            <button type="button" class="pd-item" @click="pendingPanel('فاکتورها و پرداخت')">
                                <span class="pd-item__icon pd-item__icon--amber">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /></svg>
                                </span>
                                <span class="pd-item__text">فاکتورها و پرداخت</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </button>
                        </div>

                        <div class="pd-section">
                            <div class="pd-section__label">پشتیبانی و تنظیمات</div>
                            <button type="button" class="pd-item" @click="pendingPanel('پشتیبانی فنی')">
                                <span class="pd-item__icon pd-item__icon--cyan">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
                                </span>
                                <span class="pd-item__text">پشتیبانی فنی</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </button>
                            <button type="button" class="pd-item" @click="pendingPanel('تنظیمات سامانه')">
                                <span class="pd-item__icon pd-item__icon--slate">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" /></svg>
                                </span>
                                <span class="pd-item__text">تنظیمات سامانه</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </button>
                        </div>

                        <div class="pd-section">
                            <a
                                href="/training/lesson/admin-dashboard"
                                class="pd-item pd-item--link"
                                target="_blank"
                                rel="noopener"
                                @click="profileOpen = false"
                            >
                                <span class="pd-item__icon pd-item__icon--blue">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
                                </span>
                                <span class="pd-item__text">آموزش این صفحه</span>
                                <span class="pd-item__arrow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="mobile-sidebar-overlay" aria-hidden="true" @click="closeSidebar"></div>

        <div class="navarha">
            <aside
                id="mainSidebar"
                class="sidebar-right rightSidebar"
                :class="{ open: sidebarOpen }"
                @mouseenter="expandSidebar"
                @mouseleave="collapseSidebar"
            >
                <button type="button" class="mobile-sidebar-close" aria-label="بستن منو" @click="closeSidebar">×</button>

                <div
                    v-for="item in sections"
                    :key="item.id"
                    class="icon-container"
                    :class="{ active: isActive(item) }"
                    :data-accent="item.accent"
                    role="button"
                    tabindex="0"
                    :aria-current="isActive(item) ? 'page' : undefined"
                    @click="openSection(item)"
                    @keydown.enter.prevent="openSection(item)"
                >
                    <!-- eslint-disable-next-line vue/no-v-html -- static legacy artwork, no user input -->
                    <span class="sidebar-icon-tile" aria-hidden="true" v-html="tileIcon(item.accent)"></span>
                    <span class="icon-label">{{ item.label }}</span>
                </div>

                <div class="sidebar-divider" aria-hidden="true"><span></span></div>

                <div class="icon-container" data-accent="exit" role="button" tabindex="0" @click="logout" @keydown.enter.prevent="logout">
                    <span class="sidebar-icon-tile" aria-hidden="true" v-html="tileIcon('exit')"></span>
                    <span class="icon-label">خروج</span>
                </div>
            </aside>
        </div>

        <p v-if="notice" class="h-alert admin-pending-notice" role="status">
            {{ notice }}
            <button type="button" class="admin-pending-notice__close" aria-label="بستن پیام" @click="notice = ''">×</button>
        </p>

        <main
            v-if="activeSection"
            :id="activeSection.box"
            class="management-box is-visible"
            :class="{
                'coworker-panel': activeSection.id === 'coworkers',
                'vacation-panel': activeSection.id === 'vacation',
                'overtime-panel': activeSection.id === 'overtime',
                'hourlyPass-panel': activeSection.id === 'hourly-pass',
                'ticketing-panel': activeSection.id === 'tickets',
                'internal-automation-admin-panel': activeSection.id === 'internal-automation',
                'attendance-panel': activeSection.id === 'attendance',
                'payroll-panel': activeSection.id === 'payroll',
                'shift-panel': activeSection.id === 'shifts',
            }"
            :style="{ display: boxDisplay(activeSection.box) }"
            aria-label="محتوای بخش"
        >
            <component :is="activeSection.component" v-if="activeSection.component" />

            <div v-else class="admin-section-pending">
                <h3 class="admin-section-pending__title">این بخش هنوز به Vue منتقل نشده است</h3>
                <p class="admin-section-pending__text">
                    صفحهٔ «{{ activeSection.label }}» در نسخهٔ فعلی Laravel ساخته نشده؛ در برنامهٔ در حال اجرا
                    (پایتون) این بخش کامل است و اندپوینت‌های آن هم پورت شده‌اند. انتقال این صفحه یک کار جداگانه است.
                </p>
            </div>
        </main>
    </div>
</template>

<style>
/*
 * The skip link is the one piece of chrome added to the legacy markup (the
 * legacy document has none), and it is clipped rather than pushed off-canvas:
 * in RTL an `inset-inline-start: -9999px` moves it off the **right** edge,
 * which widens the document by 10,000px and leaves the whole panel scrolled
 * sideways — visible immediately in a screenshot, invisible to a value check.
 */
.page-shell > .h-skip {
    position: absolute;
    top: 0;
    z-index: 60;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.page-shell > .h-skip:focus {
    width: auto;
    height: auto;
    clip-path: none;
    inset-inline: 0;
    margin-inline: auto;
    padding: 0.75rem 1rem;
    background: var(--c-primary, #2563eb);
    color: #fff;
    border-radius: 0 0 12px 12px;
}

/*
 * Success alert variant used by the admin pages.  The design system in
 * app.css defines `.h-alert` (error styling) but no success counterpart, so
 * the pages share this one rule rather than each redefining it.
 */
.h-alert--ok {
    background: rgb(34 197 94 / 0.12);
    color: #15803d;
}

[data-theme='dark'] .h-alert--ok {
    color: #86efac;
}

/* The three surfaces this port cannot render yet report themselves instead of
   pretending to be empty sections. */
.admin-section-pending {
    padding: 2rem 1.4rem;
    border: 1px dashed var(--border, rgb(148 163 184 / 0.45));
    border-radius: 18px;
    text-align: center;
}

.admin-section-pending__title {
    margin: 0 0 0.6rem;
    font-size: 1.05rem;
    font-weight: 800;
}

.admin-section-pending__text {
    margin: 0 auto;
    max-width: 46rem;
    font-size: 0.86rem;
    line-height: 1.9;
    color: var(--muted, #64748b);
}

.admin-pending-notice {
    position: relative;
    width: min(88%, 100%);
    margin: 0 auto 0.5rem;
    text-align: center;
}

.admin-pending-notice__close {
    position: absolute;
    inset-inline-start: 0.6rem;
    inset-block-start: 0.35rem;
    border: 0;
    background: none;
    color: inherit;
    font-size: 1.1rem;
    cursor: pointer;
}
</style>
