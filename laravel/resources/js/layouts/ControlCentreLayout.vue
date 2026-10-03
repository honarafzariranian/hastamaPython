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
import SessionsPage from '@/pages/control/SessionsPage.vue';
import PasswordResetsPage from '@/pages/control/PasswordResetsPage.vue';
import SecurityPage from '@/pages/control/SecurityPage.vue';
import ErrorsPage from '@/pages/control/ErrorsPage.vue';
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
 * The right-rail entries with a Vue section body. Legacy `audit-logs` and
 * `tickets` still have no Vue page and remain parity gaps; subscriptions lives
 * on the Python template's separate left rail.
 */
const navItems = [
    {
        id: 'dashboard',
        label: 'داشبورد',
        icon: 'M5 3h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm8 0h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-6zM13 13h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-6z',
    },
    {
        id: 'users',
        label: 'کاربران',
        icon: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0zm14-8a3 3 0 1 0-2.83-4M22 20a6 6 0 0 0-5-5.92',
    },
    {
        id: 'sessions',
        label: 'نشست‌ها',
        icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7-3a7 7 0 1 1-14 0 7 7 0 0 1 14 0zm-7-9v2m0 12v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
    },
    {
        id: 'password-resets',
        label: 'بازیابی رمز',
        icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-7-3V9a5 5 0 0 1 10 0v3m-9 0h8a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2z',
    },
    {
        id: 'security',
        label: 'امنیت',
        icon: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zm-3-9 2 2 4-4',
    },
    {
        id: 'errors',
        label: 'خطاها',
        icon: 'M12 8v5m0 3h.01M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
    },
    {
        id: 'admin-actions',
        label: 'عملیات مدیریتی',
        icon: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm0 0v6h6M9 13h6m-6 4h4',
    },
    {
        id: 'system-settings',
        label: 'تنظیمات سامانه',
        icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7.4-3a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
    },
];

const pages = {
    dashboard: DashboardPage,
    users: UsersPage,
    sessions: SessionsPage,
    'password-resets': PasswordResetsPage,
    security: SecurityPage,
    errors: ErrorsPage,
    'admin-actions': ActionsPage,
    subscriptions: SubscriptionsPage,
    'system-settings': SettingsPage,
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
 */
function navigate(id) {
    const nextSection = pages[id] ? id : 'dashboard';
    sidebarOpen.value = false;

    if (route.params.section === nextSection) {
        return;
    }

    router.push({ name: 'master-admin-section', params: { section: nextSection } }).catch(() => {});
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
                    :class="{ active: activeSection === item.id }"
                    :data-accent="`ma-${item.id}`"
                    style="text-decoration:none;color:inherit;"
                    @click.prevent="navigate(item.id)"
                >
                    <span class="sidebar-icon-tile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="item.icon" />
                        </svg>
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
