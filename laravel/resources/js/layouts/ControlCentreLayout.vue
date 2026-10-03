<script setup>
/**
 * The master-admin control-centre shell — the Vue equivalent of
 * `app/templates/master-admin.html`.
 *
 * The legacy page was a server-rendered shell (topbar + right sidebar + left
 * quick rail) whose sections were built client-side by `master-admin.js`.
 * This layout keeps that shape — sidebar + header + content — and renders a
 * Vue page component for each section currently ported. Section changes use
 * the same `/master-admin/{section}` path the Python links use, so reloads,
 * direct links and browser history keep the selected section.
 *
 * The header carries the brand («مرکز کنترل اصلی»), a live Persian clock (the
 * legacy `updateTopbarClock`), the theme toggle from the shared composable, the
 * signed-in master admin's name, and a logout button that hits the real root
 * `/logout` endpoint (the legacy verb) before clearing local state.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

import DashboardPage from '@/pages/control/DashboardPage.vue';
import UsersPage from '@/pages/control/UsersPage.vue';
import AuditLogsPage from '@/pages/control/AuditLogsPage.vue';
import SessionsPage from '@/pages/control/SessionsPage.vue';
import PasswordResetsPage from '@/pages/control/PasswordResetsPage.vue';
import SecurityPage from '@/pages/control/SecurityPage.vue';
import ErrorsPage from '@/pages/control/ErrorsPage.vue';
import TicketsPage from '@/pages/control/TicketsPage.vue';
import TicketDetailPage from '@/pages/control/TicketDetailPage.vue';
import ActionsPage from '@/pages/control/ActionsPage.vue';
import SubscriptionsPage from '@/pages/control/SubscriptionsPage.vue';
import SettingsPage from '@/pages/control/SettingsPage.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { isDark, toggleTheme } = useTheme();

const logoUrl = '/images/newlogo.png';
const userAvatarUrl = '/images/user.png';

/**
 * The right-rail entries, in the `master-admin.html` order, with the icons
 * transcribed from that markup.  The legacy icons are multi-element SVGs —
 * a filled body plus a stroked outline — so the markup is carried verbatim and
 * injected with `v-html` rather than collapsed into a single `d` string; a
 * one-path stand-in is a different drawing, not the same icon at a different
 * size.  `audit-logs` and `tickets` are the two entries the Python rail had
 * and this port had been missing.
 */
const navItems = [
    {
        id: 'dashboard',
        accent: 'ma-dashboard',
        label: 'داشبورد',
        icon: '<rect x="3.5" y="3.5" width="10" height="8" rx="2.6" fill="#fff"/><rect x="15.5" y="3.5" width="5" height="8" rx="2.5" fill="#fff" opacity=".55"/><rect x="3.5" y="13.5" width="5" height="7" rx="2.5" fill="#fff" opacity=".55"/><rect x="10.5" y="13.5" width="10" height="7" rx="2.6" fill="#fff" opacity=".85"/>',
    },
    {
        id: 'users',
        accent: 'ma-users',
        label: 'کاربران',
        icon: '<circle cx="9" cy="7.6" r="3.4" fill="#fff"/><path d="M3.2 20c.6-3.4 2.9-5.2 5.8-5.2s5.2 1.8 5.8 5.2" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="16.8" cy="9.2" r="2.5" fill="#fff" opacity=".6"/><path d="M16.2 14.7c2.3.5 4 2.1 4.4 4.3" stroke="#fff" stroke-opacity=".6" stroke-width="2" stroke-linecap="round"/>',
    },
    {
        id: 'sessions',
        accent: 'ma-sessions',
        label: 'نشست‌ها',
        icon: '<rect x="3" y="5" width="18" height="12" rx="2.8" fill="#fff" opacity=".14"/><rect x="3" y="5" width="18" height="12" rx="2.8" stroke="#fff" stroke-width="1.9"/><path d="M8 21h8M12 17v4" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><circle cx="12" cy="11" r="2.5" fill="#fff"/><path d="M12 8.5V11" stroke="#6366F1" stroke-width="1.5" stroke-linecap="round"/>',
    },
    {
        id: 'password-resets',
        accent: 'ma-passport',
        label: 'بازیابی رمز',
        icon: '<rect x="3" y="11" width="18" height="10" rx="3" fill="#fff" opacity=".14"/><rect x="3" y="11" width="18" height="10" rx="3" stroke="#fff" stroke-width="1.9"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="#fff" stroke-width="1.9"/><circle cx="12" cy="16" r="1.8" fill="#fff"/><path d="M12 17.8V19" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>',
    },
    {
        id: 'audit-logs',
        accent: 'ma-audit',
        label: 'لاگ حسابرسی',
        icon: '<rect x="3.5" y="3.5" width="17" height="17" rx="3.5" fill="#fff" opacity=".14"/><rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="#fff" stroke-width="1.9"/><path d="M8 3v4M16 3v4M3.5 9h17" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M8 14h3M8 17.5h5" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".8"/>',
    },
    {
        id: 'security',
        accent: 'ma-security',
        label: 'امنیت',
        icon: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="#fff" opacity=".14"/><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="#fff" stroke-width="1.9"/><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
    },
    {
        id: 'errors',
        accent: 'ma-errors',
        label: 'خطاها',
        icon: '<circle cx="12" cy="12" r="8.5" fill="#fff" opacity=".14"/><circle cx="12" cy="12" r="8.5" stroke="#fff" stroke-width="1.9"/><path d="M12 8v5" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="15.5" r="1.1" fill="#fff"/>',
    },
    {
        id: 'tickets',
        accent: 'ma-ticket',
        label: 'تیکت‌ها',
        icon: '<path d="M4 7.8A2.8 2.8 0 016.8 5h10.4A2.8 2.8 0 0120 7.8v1.5a2.9 2.9 0 000 5.4v1.5a2.8 2.8 0 01-2.8 2.8H6.8A2.8 2.8 0 014 16.2v-1.5a2.9 2.9 0 000-5.4V7.8z" fill="#fff" opacity=".14"/><path d="M4 7.8A2.8 2.8 0 016.8 5h10.4A2.8 2.8 0 0120 7.8v1.5a2.9 2.9 0 000 5.4v1.5a2.8 2.8 0 01-2.8 2.8H6.8A2.8 2.8 0 014 16.2v-1.5a2.9 2.9 0 000-5.4V7.8z" stroke="#fff" stroke-width="1.9"/><path d="M14.2 7.5v9" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="2 2.4"/><path d="M7.2 10.2h3.4M7.2 13.8h3.4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".8"/>',
    },
    {
        id: 'admin-actions',
        accent: 'ma-admin-actions',
        label: 'عملیات مدیریتی',
        icon: '<rect x="3.5" y="3.5" width="17" height="17" rx="3.5" fill="#fff" opacity=".14"/><rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="#fff" stroke-width="1.9"/><path d="M8 8.5h8M8 12h5M8 15.5h3" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".8"/>',
    },
    {
        id: 'system-settings',
        accent: 'ma-settings',
        label: 'تنظیمات سامانه',
        icon: '<circle cx="12" cy="12" r="3" fill="#fff"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="#fff" stroke-width="1.5"/>',
    },
];

/**
 * The section bodies that exist as Vue pages.  `ticket-detail` is listed even
 * though it has no rail entry of its own: the Python rail marked it active
 * under `tickets`, and the queue opens it at `/master-admin/ticket-detail?t=`.
 */
const pages = {
    dashboard: DashboardPage,
    users: UsersPage,
    'audit-logs': AuditLogsPage,
    sessions: SessionsPage,
    'password-resets': PasswordResetsPage,
    security: SecurityPage,
    errors: ErrorsPage,
    tickets: TicketsPage,
    'ticket-detail': TicketDetailPage,
    'admin-actions': ActionsPage,
    subscriptions: SubscriptionsPage,
    'system-settings': SettingsPage,
};

/** Sections that a rail entry also highlights, as the Python `active_section` test did. */
const sectionAliases = {
    'ticket-detail': 'tickets',
};

const activeSection = computed(() => {
    const section = route.params.section;

    return typeof section === 'string' ? section : 'dashboard';
});
const activeComponent = computed(() => pages[activeSection.value] ?? null);

const sidebarOpen = ref(false);
const logoutLoading = ref(false);

/**
 * The Python page routes use `/master-admin/{section}` and the sidebar links
 * navigate to those exact paths. Keep the section in the path so direct links,
 * reloads and browser history select the same page without a migration-only
 * `?page=` query parameter.
 *
 * `query` carries the one legacy query key a section reads — `?t=<id>` on
 * `ticket-detail` — and is left untouched otherwise so a plain section change
 * does not leave a stale `?t=` behind.
 */
function navigate(id, query = undefined) {
    const nextSection = pages[id] ? id : 'dashboard';
    sidebarOpen.value = false;

    if (route.params.section === nextSection && !query) {
        return;
    }

    router.push({
        name: 'master-admin-section',
        params: { section: nextSection },
        query: query ?? {},
    }).catch(() => {});
}

/** The rail entry a section belongs to — `ticket-detail` highlights `tickets`. */
function navItemIdFor(section) {
    return sectionAliases[section] ?? section;
}

/**
 * Sign out through the real root `/logout` (the verb the legacy sidebar link
 * used), then clear the local identity.  The server call is best-effort: a
 * failure must not leave the operator stuck in the panel.
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

/* Live Persian clock — the legacy `updateTopbarClock`. */
const now = ref(new Date());
let clockTimer = null;

onMounted(() => {
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (clockTimer) {
        clearInterval(clockTimer);
    }
});

const clockDate = computed(() =>
    toPersianDigits(new Intl.DateTimeFormat('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(now.value))
);
const clockTime = computed(() =>
    toPersianDigits(new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(now.value))
);

const adminName = computed(() => auth.user?.name || auth.user?.username || 'مدیر اصلی');
</script>

<template>
    <div class="ma-page-shell">
        <header class="topbar admin-topbar-modern ma-header-box">
            <div class="ma-header-left">
                <button type="button" class="ma-hamburger" aria-label="باز کردن منو" @click="sidebarOpen = true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>

                <div class="sidebar-top-card ma-header-brand">
                    <div class="sidebar-top-card-content">
                        <div class="sidebar-top-card-mark">
                            <img :src="logoUrl" alt="لوگوی هستما" class="sidebar-top-card-image">
                        </div>
                        <div class="sidebar-top-card-text">
                            <span class="sidebar-top-card-title">مرکز کنترل اصلی</span>
                            <span class="sidebar-top-card-sub">Master Admin Panel</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="topbar-actions ma-header-actions">
                <div class="topbar-clock-pill" aria-live="polite">
                    <span class="topbar-clock-date">{{ clockDate }}</span>
                    <span class="topbar-clock-time">{{ clockTime }}</span>
                </div>

                <div
                    class="topbar-icon-btn theme-toggle"
                    data-action="toggle-theme"
                    role="button"
                    tabindex="0"
                    :aria-label="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                    :title="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                    :aria-pressed="isDark"
                    @click="toggleTheme"
                >
                    <svg class="theme-toggle-moon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M21 12.79C20.55 12.93 20.08 13 19.59 13C15.59 13 12.29 9.7 12.29 5.7C12.29 5.21 12.36 4.74 12.5 4.29C9.21 4.84 6.82 7.83 6.82 11.32C6.82 15.14 10.17 18.49 14 18.49C17.49 18.49 20.48 16.1 21.03 12.81C21.02 12.81 21.01 12.79 21 12.79Z" fill="currentColor"></path>
                    </svg>
                    <svg class="theme-toggle-sun" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8"></circle>
                        <path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path>
                    </svg>
                </div>

                <div class="topbar-user">
                    <div class="topbar-avatar-wrap">
                        <img class="topbar-avatar" :src="userAvatarUrl" alt="پروفایل مدیر اصلی">
                    </div>
                    <div class="topbar-user-info">
                        <span class="topbar-user-name">{{ adminName }}</span>
                        <span class="topbar-user-role">مدیر اصلی</span>
                    </div>
                    <svg class="chevron-icon" viewBox="0 0 24 24" fill="none"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            </div>
        </header>

        <div class="ma-sidebar-overlay" :class="{ 'is-visible': sidebarOpen }" @click="sidebarOpen = false"></div>

        <div class="navarha ma-navarha">
            <aside class="sidebar-right rightSidebar ma-sidebar-right" :class="{ open: sidebarOpen }">
                <button type="button" class="mobile-sidebar-close" aria-label="بستن منو" @click="sidebarOpen = false">×</button>

                <a
                    v-for="item in navItems"
                    :key="item.id"
                    href="#"
                    class="icon-container"
                    :class="{ active: navItemIdFor(activeSection) === item.id }"
                    :data-accent="item.accent"
                    style="text-decoration:none;color:inherit;"
                    @click.prevent="navigate(item.id)"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" v-html="item.icon"></svg>
                    </span>
                    <span class="icon-label">{{ item.label }}</span>
                </a>

                <a
                    href="/logout"
                    class="icon-container ma-logout-item"
                    data-accent="exit"
                    style="text-decoration:none;color:inherit;"
                    title="خروج از سامانه"
                    @click.prevent="logout"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M16 17l5-5-5-5" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12H9" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/></svg>
                    </span>
                    <span class="icon-label">{{ logoutLoading ? 'در حال خروج…' : 'خروج' }}</span>
                </a>
            </aside>
        </div>

        <div class="ma-navarha--left">
            <aside class="ma-sidebar-left">
                <a
                    href="#"
                    class="icon-container"
                    :class="{ active: activeSection === 'subscriptions' }"
                    data-accent="ma-subscriptions"
                    title="مدیریت اشتراک مشتریان"
                    aria-label="مدیریت اشتراک مشتریان"
                    style="text-decoration:none;color:inherit;"
                    @click.prevent="navigate('subscriptions')"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="5" width="18" height="14" rx="3" fill="#fff" opacity=".14"/><rect x="3" y="5" width="18" height="14" rx="3" stroke="#fff" stroke-width="1.9"/><path d="M3 10h18" stroke="#fff" stroke-width="1.9"/><path d="M7 15h3M15 15h2" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <span class="icon-label">اشتراک مشتریان</span>
                </a>

                <a
                    href="/call-management"
                    target="_blank"
                    rel="noopener"
                    class="icon-container"
                    data-accent="ma-call-mgmt"
                    title="سامانه فراخوان نمونه‌گیری — پنل مدیریت"
                    aria-label="مدیریت فراخوان نمونه‌گیری"
                    style="text-decoration:none;color:inherit;"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" fill="#fff" opacity=".14"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <span class="icon-label">مدیریت فراخوان</span>
                </a>

                <a
                    href="/call-display"
                    target="_blank"
                    rel="noopener"
                    class="icon-container"
                    data-accent="ma-call-display"
                    title="سامانه فراخوان نمونه‌گیری — نمایشگر"
                    aria-label="نمایشگر فراخوان نمونه‌گیری"
                    style="text-decoration:none;color:inherit;"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="4.8" width="18" height="12.7" rx="2.8" fill="#fff" opacity=".14"/><rect x="3" y="4.8" width="18" height="12.7" rx="2.8" stroke="#fff" stroke-width="1.9"/><path d="M8.2 20.5h7.6M12 17.5v3" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M10.7 8.2v4.6l3.9-2.3z" fill="#fff"/></svg>
                    </span>
                    <span class="icon-label">نمایش فراخوان</span>
                </a>

                <a
                    href="#"
                    class="icon-container"
                    data-accent="ma-label-printer"
                    title="طراحی و چاپ لیبل نوبت"
                    aria-label="طراحی و چاپ لیبل نوبت"
                    style="text-decoration:none;color:inherit;"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 8V4.5h10V8" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M5 8h14a3 3 0 013 3v3.5a2.5 2.5 0 01-2.5 2.5H4.5A2.5 2.5 0 012 14.5V11a3 3 0 013-3z" fill="#fff" opacity=".14"/>
                            <path d="M5 8h14a3 3 0 013 3v3.5a2.5 2.5 0 01-2.5 2.5H4.5A2.5 2.5 0 012 14.5V11a3 3 0 013-3z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M7 14h10v6H7z" fill="#fff" opacity=".2"/>
                            <path d="M7 14h10v6H7zM9 16.5h6" stroke="#fff" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round"/>
                            <circle cx="17.5" cy="11.5" r="1" fill="#fff"/>
                        </svg>
                    </span>
                    <span class="icon-label">طراحی لیبل</span>
                </a>

                <a
                    href="/ticket-kiosk"
                    target="_blank"
                    rel="noopener"
                    class="icon-container"
                    data-accent="ma-ticket-kiosk"
                    title="کیوسک نوبت‌دهی"
                    aria-label="کیوسک نوبت‌دهی"
                    style="text-decoration:none;color:inherit;"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="4" width="18" height="13" rx="2.8" fill="#fff" opacity=".14"/>
                            <rect x="3" y="4" width="18" height="13" rx="2.8" stroke="#fff" stroke-width="1.9"/>
                            <path d="M8 21h8M12 17v4" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/>
                            <path d="M8 8.2h8a1 1 0 011 1v1a1.5 1.5 0 000 3v1a1 1 0 01-1 1H8a1 1 0 01-1-1v-1a1.5 1.5 0 000-3v-1a1 1 0 011-1z" fill="#fff" opacity=".25"/>
                            <path d="M8 8.2h8a1 1 0 011 1v1a1.5 1.5 0 000 3v1a1 1 0 01-1 1H8a1 1 0 01-1-1v-1a1.5 1.5 0 000-3v-1a1 1 0 011-1z" stroke="#fff" stroke-width="1.5"/>
                            <path d="M13.2 8.2v7.6" stroke="#fff" stroke-width="1.3" stroke-dasharray="1.8 1.8"/>
                        </svg>
                    </span>
                    <span class="icon-label">نوبت‌دهی</span>
                </a>
            </aside>
        </div>

        <main class="ma-dashboard-main" id="top">
            <div class="ma-dashboard-inner">
                <component v-if="activeComponent" :is="activeComponent" @navigate="navigate" />
            </div>
        </main>
    </div>
</template>
