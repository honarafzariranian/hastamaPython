<script setup>
/**
 * The admin panel shell — the Vue equivalent of `app/templates/admin.html`.
 *
 * The router mounts this layout at `/admin` with no child routes, so the
 * layout owns the section navigation itself: the sidebar picks a page
 * component and the main area renders it.  This reproduces the legacy
 * `toggleBox` behaviour (one section visible at a time, sidebar icon active)
 * without the legacy `display: none` box-swapping.
 *
 * The header carries the brand, a live Persian clock (the legacy
 * `updateTopbarClock`), the theme toggle, the signed-in admin's name and the
 * logout action — the brief's "sidebar + header shell with the admin's name,
 * logout, and navigation".
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

import DashboardPage from '@/pages/admin/DashboardPage.vue';
import UsersPage from '@/pages/admin/UsersPage.vue';
import LeavePage from '@/pages/admin/LeavePage.vue';
import OvertimePage from '@/pages/admin/OvertimePage.vue';
import HourlyPassPage from '@/pages/admin/HourlyPassPage.vue';
import ShiftsPage from '@/pages/admin/ShiftsPage.vue';
import ReportsPage from '@/pages/admin/ReportsPage.vue';
import PayrollPage from '@/pages/admin/PayrollPage.vue';

const router = useRouter();
const auth = useAuthStore();
const { isDark, toggleTheme } = useTheme();

const logoUrl = '/images/newlogo.png';

const navItems = [
    {
        id: 'dashboard',
        label: 'داشبورد',
        icon: 'M5 3h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm8 0h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-6zM13 13h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-6z',
    },
    {
        id: 'users',
        label: 'مدیریت کارکنان',
        icon: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0zm14-8a3 3 0 1 0-2.83-4M22 20a6 6 0 0 0-5-5.92',
    },
    {
        id: 'leave',
        label: 'مدیریت مرخصی ها',
        icon: 'M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zm0 5h14M8 3v4M16 3v4m-6 8 2 2 4-4',
    },
    {
        id: 'overtime',
        label: 'مدیریت اضافه کاری ها',
        icon: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0zM19 3v4h-4',
    },
    {
        id: 'hourly-pass',
        label: 'مدیریت پاس های ساعتی',
        icon: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0zM17 4v3h-3',
    },
    {
        id: 'shifts',
        label: 'مدیریت شیفت‌ها',
        icon: 'M5 6h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zm0 4h14M8 3v4M16 3v4m-4 8v4m-2-2h4',
    },
    {
        id: 'reports',
        label: 'گزارش‌ها',
        icon: 'M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm7 0v6h6M9 13h6M9 17h6',
    },
    {
        id: 'payroll',
        label: 'حقوق و دستمزد',
        icon: 'M4 7h16a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a1 1 0 0 1 1-1zm1-2a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v2M8 12h5m2 4a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0-1v2m0-1h.01',
    },
];

const pages = {
    dashboard: DashboardPage,
    users: UsersPage,
    leave: LeavePage,
    overtime: OvertimePage,
    'hourly-pass': HourlyPassPage,
    shifts: ShiftsPage,
    reports: ReportsPage,
    payroll: PayrollPage,
};

const activePage = ref('dashboard');
const activeComponent = computed(() => pages[activePage.value] ?? DashboardPage);

const sidebarOpen = ref(false);

function navigate(id) {
    activePage.value = id;
    sidebarOpen.value = false;
}

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}

/* Live Persian clock — the legacy `updateTopbarClock`. The interval is one
 * second so the displayed seconds stay true; the DOM is only touched when the
 * formatted text actually changed, exactly as the legacy guard did. */
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

const adminName = computed(() => auth.user?.name || auth.username || 'مدیر سیستم');
const adminInitial = computed(() => String(adminName.value).trim().charAt(0) || 'م');
</script>

<template>
    <div class="admin-shell">
        <a class="h-skip" href="#admin-main">پرش به محتوای اصلی</a>

        <header class="admin-header">
            <div class="admin-header__inner">
                <div class="admin-header__side">
                    <button
                        type="button"
                        class="admin-burger"
                        aria-label="باز کردن منو"
                        :aria-expanded="sidebarOpen"
                        @click="sidebarOpen = true"
                    >
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>

                    <div class="admin-brand">
                        <img class="admin-brand__logo" :src="logoUrl" alt="لوگوی هستما" width="44" height="44">
                        <span class="admin-brand__text">
                            <span class="admin-brand__name">سامانه هستما</span>
                            <span class="admin-brand__sub">پنل مدیریت | سامانه هوشمند حضور و غیاب</span>
                        </span>
                    </div>
                </div>

                <div class="admin-header__actions">
                    <div class="admin-clock" aria-live="polite">
                        <span class="admin-clock__date">{{ clockDate }}</span>
                        <span class="admin-clock__time">{{ clockTime }}</span>
                    </div>

                    <button
                        type="button"
                        class="h-btn h-btn-ghost admin-theme-toggle"
                        :aria-pressed="isDark"
                        :aria-label="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                        :title="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                        @click="toggleTheme"
                    >
                        <span aria-hidden="true">{{ isDark ? '☀' : '☾' }}</span>
                    </button>

                    <div class="admin-user">
                        <span class="admin-user__avatar" aria-hidden="true">{{ adminInitial }}</span>
                        <span class="admin-user__name">{{ adminName }}</span>
                        <span class="admin-user__role">ادمین</span>
                    </div>

                    <button type="button" class="h-btn h-btn-primary admin-logout" @click="logout">
                        <span aria-hidden="true">⏻</span>
                        <span>خروج</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="admin-body">
            <div v-if="sidebarOpen" class="admin-sidebar-overlay" aria-hidden="true" @click="sidebarOpen = false"></div>

            <aside class="admin-sidebar" :class="{ 'is-open': sidebarOpen }" aria-label="منوی مدیریت">
                <button type="button" class="admin-sidebar__close" aria-label="بستن منو" @click="sidebarOpen = false">×</button>

                <nav class="admin-nav">
                    <button
                        v-for="item in navItems"
                        :key="item.id"
                        type="button"
                        class="admin-nav__item"
                        :class="{ 'is-active': activePage === item.id }"
                        :aria-current="activePage === item.id ? 'page' : undefined"
                        @click="navigate(item.id)"
                    >
                        <span class="admin-nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="item.icon" />
                            </svg>
                        </span>
                        <span class="admin-nav__label">{{ item.label }}</span>
                    </button>
                </nav>
            </aside>

            <main id="admin-main" class="admin-main">
                <component :is="activeComponent" />
            </main>
        </div>
    </div>
</template>

<style>
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
</style>

<style scoped>
.admin-shell {
    display: flex;
    min-height: 100dvh;
    flex-direction: column;
}

.admin-header {
    position: sticky;
    top: 0;
    z-index: 30;
    border-bottom: 1px solid rgb(15 23 42 / 0.08);
    background: rgb(255 255 255 / 0.88);
    backdrop-filter: blur(10px);
}

[data-theme='dark'] .admin-header {
    border-bottom-color: var(--dk-border);
    background: rgb(24 34 51 / 0.88);
}

.admin-header__inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.6rem 1.1rem;
}

.admin-header__side {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    min-width: 0;
}

.admin-burger {
    display: none;
    flex-direction: column;
    justify-content: center;
    gap: 5px;
    width: 40px;
    height: 40px;
    padding: 8px;
    border: 1px solid rgb(15 23 42 / 0.12);
    border-radius: var(--radius-token-md);
    background: transparent;
}

[data-theme='dark'] .admin-burger {
    border-color: var(--dk-border);
}

.admin-burger span {
    display: block;
    height: 2px;
    border-radius: 2px;
    background: currentColor;
}

.admin-brand {
    display: inline-flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
}

.admin-brand__logo {
    width: 42px;
    height: 42px;
    object-fit: contain;
}

.admin-brand__text {
    display: flex;
    flex-direction: column;
    line-height: 1.35;
}

.admin-brand__name {
    font-size: 1.02rem;
    font-weight: 800;
    white-space: nowrap;
}

.admin-brand__sub {
    font-size: 0.72rem;
    color: #64748b;
    white-space: nowrap;
}

[data-theme='dark'] .admin-brand__sub {
    color: var(--dk-text-2);
}

.admin-header__actions {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.admin-clock {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.35rem 0.8rem;
    border: 1px solid rgb(15 23 42 / 0.1);
    border-radius: 999px;
    background: rgb(15 23 42 / 0.03);
    font-size: 0.8rem;
    white-space: nowrap;
}

[data-theme='dark'] .admin-clock {
    border-color: var(--dk-border);
    background: var(--dk-surface-2);
}

.admin-clock__date {
    font-weight: 700;
}

.admin-clock__time {
    color: #64748b;
    font-variant-numeric: tabular-nums;
}

[data-theme='dark'] .admin-clock__time {
    color: var(--dk-text-2);
}

.admin-theme-toggle {
    padding: 0.5rem 0.7rem;
    font-size: 1rem;
}

.admin-user {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.admin-user__avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid rgb(15 23 42 / 0.12);
    background: var(--c-primary);
    color: #fff;
    font-size: 1rem;
    font-weight: 800;
}

[data-theme='dark'] .admin-user__avatar {
    border-color: var(--dk-border);
    background: var(--dk-accent);
    color: #082f49;
}

.admin-user__name {
    font-size: 0.85rem;
    font-weight: 700;
    white-space: nowrap;
}

.admin-user__role {
    font-size: 0.7rem;
    color: #64748b;
}

[data-theme='dark'] .admin-user__role {
    color: var(--dk-text-2);
}

.admin-logout {
    white-space: nowrap;
}

.admin-body {
    display: flex;
    flex: 1;
    align-items: stretch;
}

.admin-sidebar-overlay {
    display: none;
}

.admin-sidebar {
    position: sticky;
    top: 65px;
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    width: 232px;
    flex-shrink: 0;
    align-self: flex-start;
    height: calc(100dvh - 65px);
    padding: 1rem 0.75rem;
    border-inline-start: 1px solid rgb(15 23 42 / 0.08);
    background: #fff;
    overflow-y: auto;
}

[data-theme='dark'] .admin-sidebar {
    border-inline-start-color: var(--dk-border);
    background: var(--dk-bg-2);
}

.admin-sidebar__close {
    display: none;
}

.admin-nav {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.admin-nav__item {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    width: 100%;
    padding: 0.65rem 0.8rem;
    border: 0;
    border-radius: var(--radius-token-md);
    background: transparent;
    color: #475569;
    font: inherit;
    font-size: 0.88rem;
    font-weight: 600;
    text-align: start;
    cursor: pointer;
    transition: background-color 150ms ease, color 150ms ease;
}

[data-theme='dark'] .admin-nav__item {
    color: var(--dk-text-2);
}

.admin-nav__item:hover {
    background: rgb(14 165 233 / 0.08);
    color: var(--c-primary-dark);
}

[data-theme='dark'] .admin-nav__item:hover {
    background: var(--dk-surface-2);
    color: var(--dk-accent);
}

.admin-nav__item.is-active {
    background: var(--c-primary);
    color: #fff;
}

[data-theme='dark'] .admin-nav__item.is-active {
    background: var(--dk-accent);
    color: #082f49;
}

.admin-nav__icon {
    display: inline-flex;
    width: 22px;
    height: 22px;
    flex-shrink: 0;
}

.admin-nav__icon svg {
    width: 100%;
    height: 100%;
}

.admin-main {
    flex: 1;
    min-width: 0;
    padding: 1.4rem 1.2rem 2.4rem;
}

@media (max-width: 900px) {
    .admin-brand__sub {
        display: none;
    }

    .admin-user__role {
        display: none;
    }
}

@media (max-width: 768px) {
    .admin-burger {
        display: flex;
    }

    .admin-sidebar-overlay {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 35;
        background: rgb(15 23 42 / 0.45);
    }

    .admin-sidebar {
        position: fixed;
        top: 0;
        bottom: 0;
        right: 0;
        z-index: 40;
        height: 100dvh;
        width: 260px;
        padding-top: 1rem;
        transform: translateX(100%);
        transition: transform 220ms ease;
        box-shadow: var(--dk-shadow);
    }

    [dir='rtl'] .admin-sidebar {
        transform: translateX(100%);
    }

    .admin-sidebar.is-open {
        transform: translateX(0);
    }

    .admin-sidebar__close {
        display: block;
        align-self: flex-end;
        margin: 0 0.6rem 0.4rem;
        border: 0;
        background: transparent;
        color: inherit;
        font-size: 1.6rem;
        line-height: 1;
        cursor: pointer;
    }

    .admin-clock {
        display: none;
    }

    .admin-user__name {
        display: none;
    }

    .admin-logout {
        padding: 0.5rem 0.7rem;
    }

    .admin-logout span:last-child {
        display: none;
    }

    .admin-main {
        padding: 1rem 0.8rem 2rem;
    }
}
</style>
