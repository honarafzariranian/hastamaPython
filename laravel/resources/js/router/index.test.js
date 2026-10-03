import { createPinia, setActivePinia } from 'pinia';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import router from './index';

beforeAll(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
});

describe('legacy page routes', () => {
    it('redirects the root route to login instead of rendering an extra status page', () => {
        const root = router.getRoutes().find((route) => route.path === '/');
        const status = router.resolve('/status');

        expect(root.redirect).toEqual({ name: 'login' });
        expect(status.name).toBe('not-found');
    });

    it('resolves the master-admin root to its dashboard section', () => {
        const root = router.getRoutes().find((route) => route.path === '/master-admin');
        const route = router.resolve('/master-admin/dashboard');

        expect(root.redirect).toEqual({ name: 'master-admin-section', params: { section: 'dashboard' } });
        expect(route.name).toBe('master-admin-section');
        expect(route.params.section).toBe('dashboard');
        expect(route.meta.ownChrome).toBe(true);
    });

    it('resolves the legacy system-settings section path', () => {
        const route = router.resolve('/master-admin/system-settings');

        expect(route.name).toBe('master-admin-section');
        expect(route.params.section).toBe('system-settings');
    });

    it('sends a signed-in admin from master-admin to the admin dashboard', async () => {
        setActivePinia(createPinia());
        const auth = useAuthStore();
        auth.ready = true;
        auth.user = { username: 'administrator', is_admin: true, is_master_admin: false };

        await router.push('/master-admin/dashboard');

        expect(router.currentRoute.value.fullPath).toBe('/admin/dashboard');
    });

    it('sends an anonymous visitor from a master-admin page to login', async () => {
        setActivePinia(createPinia());
        const auth = useAuthStore();
        auth.ready = true;
        auth.user = null;

        await router.push('/master-admin/dashboard');

        expect(router.currentRoute.value.name).toBe('login');
    });

    it('does not wrap full-document panels in the application shell', () => {
        for (const path of ['/user_panel', '/master-admin/dashboard', '/call-management', '/admin/dashboard']) {
            expect(router.resolve(path).meta.ownChrome).toBe(true);
        }
    });
});
