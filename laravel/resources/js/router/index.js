import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

import StatusPage from '@/pages/StatusPage.vue';
import LoginPage from '@/pages/auth/LoginPage.vue';
import UserPanelLayout from '@/layouts/UserPanelLayout.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ControlCentreLayout from '@/layouts/ControlCentreLayout.vue';
import CallManagementPage from '@/pages/call/CallManagementPage.vue';
import CallDisplayPage from '@/pages/call/CallDisplayPage.vue';
import TicketKioskPage from '@/pages/call/TicketKioskPage.vue';
import TicketPrintPage from '@/pages/call/TicketPrintPage.vue';
import RegisterPage from '@/pages/public/RegisterPage.vue';
import RulesPage from '@/pages/public/RulesPage.vue';
import TrainingPage from '@/pages/public/TrainingPage.vue';
import TrainingLessonPage from '@/pages/public/TrainingLessonPage.vue';
import IranOnlyPage from '@/pages/public/IranOnlyPage.vue';

/**
 * Application routes.
 *
 * History mode is used because the brief requires real deep links and working
 * browser history: Laravel serves the SPA shell for any non-API path
 * (see routes/web.php `Route::fallback`), so a reload on /anything lands back
 * in the router rather than on a 404 page.
 *
 * `meta.title` is Persian and becomes the document title.
 * `meta.requiresAuth` / `meta.role` are honoured by the guard below, but they
 * are a navigation convenience ONLY: every API call is authorised again
 * server-side, which is where the real boundary lives.
 */
const routes = [
    {
        path: '/login',
        name: 'login',
        component: LoginPage,
        meta: { title: 'ورود به سامانه', public: true },
    },
    {
        path: '/',
        name: 'home',
        component: StatusPage,
        meta: { title: 'وضعیت سامانه', public: true },
    },
    {
        path: '/status',
        name: 'status',
        component: StatusPage,
        meta: { title: 'وضعیت سامانه', public: true },
    },

    /* Public content pages. */
    {
        path: '/register',
        name: 'register',
        component: RegisterPage,
        meta: { title: 'ثبت‌نام', public: true },
    },
    {
        path: '/rules',
        name: 'rules',
        component: RulesPage,
        meta: { title: 'قوانین', public: true },
    },
    {
        path: '/training',
        name: 'training',
        component: TrainingPage,
        meta: { title: 'آموزش', public: true },
    },
    {
        path: '/training/:category',
        name: 'training-category',
        component: TrainingPage,
        meta: { title: 'آموزش', public: true },
    },
    {
        path: '/training/lesson/:lessonId',
        name: 'training-lesson',
        component: TrainingLessonPage,
        meta: { title: 'آموزش', public: true },
    },
    /*
     * The access-policy page.  Like `/offline` it is a standalone document in
     * the running application, and Laravel already serves it byte-for-byte
     * (App\Support\Connectivity\IranOnlyPage via routes/public-pages.php) —
     * the component below renders the same markup for in-app navigation.
     *
     * `/offline` deliberately has **no** router entry.  Its document carries a
     * server-computed block the SPA cannot obtain (the LAN address, rendered
     * only when the operator has switched it on), so a client-side rendering of
     * that URL would be a divergent second version of the outage page.  A full
     * page load — what the legacy link did too — reaches the real document.
     */
    {
        path: '/iran-only',
        name: 'iran-only',
        component: IranOnlyPage,
        meta: { title: 'دسترسی فقط از داخل کشور', public: true },
    },

    /* The call surfaces.  The kiosk and the display are public; the management
     * desk requires a master admin, exactly as `_require_call_page_access`. */
    {
        path: '/call-display',
        name: 'call-display',
        component: CallDisplayPage,
        meta: { title: 'نمایش فراخوان', public: true },
    },
    {
        path: '/ticket-kiosk',
        name: 'ticket-kiosk',
        component: TicketKioskPage,
        meta: { title: 'کیوسک نوبت‌دهی', public: true },
    },
    {
        path: '/ticket-print',
        name: 'ticket-print',
        component: TicketPrintPage,
        meta: { title: 'چاپ برچسب', public: true },
    },
    {
        path: '/call-management',
        name: 'call-management',
        component: CallManagementPage,
        meta: { title: 'مدیریت فراخوان', role: 'master_admin' },
    },

    /* The three panels. */
    {
        path: '/user_panel',
        name: 'user-panel',
        component: UserPanelLayout,
        meta: { title: 'پنل کاربری', requiresAuth: true },
    },
    {
        path: '/admin',
        name: 'admin',
        component: AdminLayout,
        meta: { title: 'پنل مدیریت', role: 'admin' },
    },
    {
        path: '/master-admin',
        name: 'master-admin',
        component: ControlCentreLayout,
        meta: { title: 'مرکز کنترل', role: 'master_admin' },
    },

    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFound.vue'),
        meta: { title: 'صفحه پیدا نشد', public: true },
    },
];

const router = createRouter({
    /*
     * The history base is the application root, NOT Vite's asset base.
     *
     * The Laravel Vite plugin sets Vite's `base` to `/build/` so the bundled
     * assets resolve to `/build/assets/…`, and that value lands in
     * `import.meta.env.BASE_URL`.  The application itself is served from `/`
     * (Laravel's `Route::fallback` returns the shell for any non-API path), so
     * a router built on `/build/` rewrites every navigation to `/build/…` —
     * which is why `/` landed on `/build/login`.
     */
    history: createWebHistory('/'),
    routes,
    scrollBehavior(to, from, savedPosition) {
        return savedPosition ?? { top: 0 };
    },
});

/**
 * The navigation guard.
 *
 * This is a UX convenience only — it redirects a signed-out user to the login
 * page before a panel renders an empty shell.  It is NOT the authorisation
 * boundary: every API call is checked again by Laravel's middleware and
 * policies, so a user who edits this function in devtools gains nothing.
 */
router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.ready) {
        await auth.fetchMe();
    }

    if (to.meta.public) {
        return true;
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login' };
    }

    if (to.meta.role === 'admin' && !auth.isAdmin) {
        return { name: 'login' };
    }

    if (to.meta.role === 'master_admin' && !auth.isMasterAdmin) {
        return { name: 'login' };
    }

    return true;
});

router.afterEach((to) => {
    const base = 'هستما';
    document.title = to.meta?.title ? `${to.meta.title} | ${base}` : base;
});

export default router;
