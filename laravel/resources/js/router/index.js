import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

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
        component: () => import('@/pages/auth/LoginPage.vue'),
        meta: { title: 'ورود به سامانه', public: true },
    },
    {
        /* Python permanently redirects the root portal URL to `/login`. */
        path: '/',
        redirect: { name: 'login' },
    },

    /* Public content pages. */
    {
        path: '/register',
        name: 'register',
        component: () => import('@/pages/public/RegisterPage.vue'),
        meta: { title: 'ثبت‌نام', public: true },
    },
    {
        path: '/rules',
        name: 'rules',
        component: () => import('@/pages/public/RulesPage.vue'),
        meta: { title: 'قوانین', public: true },
    },
    {
        path: '/training',
        name: 'training',
        component: () => import('@/pages/public/TrainingPage.vue'),
        meta: { title: 'آموزش', public: true },
    },
    {
        path: '/training/:category',
        name: 'training-category',
        component: () => import('@/pages/public/TrainingPage.vue'),
        meta: { title: 'آموزش', public: true },
    },
    {
        path: '/training/lesson/:lessonId',
        name: 'training-lesson',
        component: () => import('@/pages/public/TrainingLessonPage.vue'),
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
        component: () => import('@/pages/public/IranOnlyPage.vue'),
        meta: { title: 'دسترسی فقط از داخل کشور', public: true },
    },

    /* The call surfaces.  The kiosk and the display are public; the management
     * desk requires a master admin, exactly as `_require_call_page_access`. */
    {
        path: '/call-display',
        name: 'call-display',
        component: () => import('@/pages/call/CallDisplayPage.vue'),
        meta: { title: 'نمایش فراخوان', public: true },
    },
    {
        path: '/ticket-kiosk',
        name: 'ticket-kiosk',
        component: () => import('@/pages/call/TicketKioskPage.vue'),
        meta: { title: 'کیوسک نوبت‌دهی', public: true },
    },
    {
        path: '/ticket-print',
        name: 'ticket-print',
        component: () => import('@/pages/call/TicketPrintPage.vue'),
        meta: { title: 'چاپ برچسب', public: true },
    },
    {
        path: '/call-management',
        name: 'call-management',
        component: () => import('@/pages/call/CallManagementPage.vue'),
        /* The Python call-management template is a standalone full-screen page. */
        meta: { title: 'مدیریت فراخوان', role: 'master_admin', ownChrome: true },
    },

    /* The three panels. */
    {
        path: '/user_panel',
        name: 'user-panel',
        component: () => import('@/layouts/UserPanelLayout.vue'),
        /* `user-panel.html` owns its complete header, sidebar and page width. */
        meta: { title: 'پنل کاربری', requiresAuth: true, ownChrome: true },
    },
    /*
     * The admin panel is an SPA whose path names the section — the same
     * `/admin/<section>` space the running application serves (`/admin` is a
     * 303 to `/admin/dashboard` there as well, see routes/web.php).  The layout
     * reads `route.params.section`, so a reload, a bookmark and the back button
     * all land on the section the address names.
     *
     * `:section` is deliberately not constrained to a whitelist: the legacy
     * handler answered every `/admin/{section}` with the same document and let
     * its own map decide, and an unknown value falls back to the dashboard
     * inside the layout rather than 404-ing a panel that exists.
     */
    {
        path: '/admin',
        redirect: { name: 'admin-section', params: { section: 'dashboard' } },
    },
    {
        path: '/admin/:section',
        name: 'admin-section',
        component: () => import('@/layouts/AdminLayout.vue'),
        /* `ownChrome`: `admin.html` is a complete document — its own topbar,
           sidebar rail, background wash and full-width section boxes.  The
           application shell would add a second header and narrow the layout. */
        meta: { title: 'پنل مدیریت', role: 'admin', ownChrome: true },
    },
    {
        path: '/master-admin',
        redirect: { name: 'master-admin-section', params: { section: 'dashboard' } },
    },
    {
        /* FastAPI serves this same shell at `/master-admin/{section}`. */
        path: '/master-admin/:section',
        name: 'master-admin-section',
        component: () => import('@/layouts/ControlCentreLayout.vue'),
        /* `master-admin.html` supplies its own header, rails and full-width layout. */
        meta: { title: 'مرکز کنترل', role: 'master_admin', ownChrome: true },
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
        /*
         * The Python page guard redirects a signed-in non-master-admin to
         * `/admin` (an anonymous visitor goes to `/login`).  Preserve that
         * route for an administrator: `/admin` then resolves to its dashboard.
         * A regular user follows the same source redirect and is sent from the
         * admin guard to `/login` on the next navigation.
         */
        return auth.isAuthenticated ? { path: '/admin' } : { name: 'login' };
    }

    return true;
});

router.afterEach((to) => {
    const base = 'هستما';
    document.title = to.meta?.title ? `${to.meta.title} | ${base}` : base;
});

export default router;
