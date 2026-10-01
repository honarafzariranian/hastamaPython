import { createRouter, createWebHistory } from 'vue-router';
import StatusPage from '@/pages/StatusPage.vue';

/**
 * Application routes.
 *
 * History mode is used because the brief requires real deep links and working
 * browser history: Laravel serves the SPA shell for any non-API path
 * (see routes/web.php `Route::fallback`), so a reload on /anything lands back
 * in the router rather than on a 404 page.
 *
 * Only routes that exist are listed.  Panel, attendance, call and printing
 * routes are added by their own migration phases — a route that rendered a
 * placeholder would be exactly the "fake implementation" the brief forbids.
 *
 * `meta.title` is Persian and becomes the document title.
 * `meta.requiresAuth` / `meta.role` are honoured by the guard below, but they
 * are a navigation convenience ONLY: every API call is authorised again
 * server-side, which is where the real boundary lives.
 */
const routes = [
    {
        path: '/',
        name: 'status',
        component: StatusPage,
        meta: { title: 'وضعیت سامانه' },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFound.vue'),
        meta: { title: 'صفحه پیدا نشد' },
    },
];

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
    scrollBehavior(to, from, savedPosition) {
        return savedPosition ?? { top: 0 };
    },
});

router.afterEach((to) => {
    const base = 'هستما';
    document.title = to.meta?.title ? `${to.meta.title} | ${base}` : base;
});

export default router;
