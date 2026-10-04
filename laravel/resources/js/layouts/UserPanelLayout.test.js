import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import api from '@/services/api';
import UserPanelLayout from '@/layouts/UserPanelLayout.vue';

/**
 * Behavioural parity for the `/user_panel` entry points.
 *
 * In `user-panel.html` the sidebar, the profile dropdown and the dashboard cards
 * open fixed overlays — they never navigate to another document:
 *   * `#internalAutomationNav` → `openCenter()` (internal-automation.js)
 *   * `[data-action="open-notification-center"]` → `openCenter()` (notification-system.js)
 *   * `#ticketListIcon` → `openTicketModal()` (user-panel-script.js)
 *   * `.pd-item[data-action="open-profile-panel"]` → `openProfilePanel()`
 *   * `.pd-item[data-action="open-support-center"]` → `openUserSupportCenter()`
 * The layout must reproduce those pairings.
 */

const router = { replace: vi.fn(), currentRoute: { value: { query: {} } } };

vi.mock('vue-router', () => ({ useRouter: () => router }));

vi.mock('@/services/api', () => {
    const mocks = { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
    return { default: mocks, api: mocks };
});

vi.mock('@/stores/auth', () => ({
    useAuthStore: () => ({
        user: { username: 'user1', role: 'کارشناس' },
        username: 'user1',
        isAuthenticated: true,
        ready: true,
        fetchMe: vi.fn(),
    }),
}));

vi.mock('@/composables/useTheme', () => ({
    useTheme: () => ({ isDark: ref(false), toggleTheme: vi.fn() }),
}));

const PYTHON_TEMPLATE = resolve(process.cwd(), '../app/templates/user-panel.html');

beforeEach(() => {
    setActivePinia(createPinia());
    api.get.mockReset();
    api.get.mockResolvedValue(undefined);
});

afterEach(() => {
    document.body.className = '';
    vi.clearAllMocks();
});

async function mountPanel() {
    const wrapper = mount(UserPanelLayout, { attachTo: document.body });
    await flushPromises();
    return wrapper;
}

function rendered(wrapper) {
    return new DOMParser().parseFromString(`<div>${wrapper.html()}</div>`, 'text/html');
}

describe('user panel entry points', () => {
    it('exposes the same sidebar actions as the reference sidebar', async () => {
        const wrapper = await mountPanel();
        const reference = new DOMParser().parseFromString(readFileSync(PYTHON_TEMPLATE, 'utf-8'), 'text/html');

        for (const action of [
            'open-leave',
            'open-overtime',
            'open-hourly-pass',
            'open-internal-automation',
            'open-notification-center',
            'toggle-settings-panel',
        ]) {
            expect(reference.querySelector(`[data-action="${action}"]`), `reference is missing ${action}`).toBeTruthy();
            expect(wrapper.element.querySelector(`[data-action="${action}"]`), `vue is missing ${action}`).toBeTruthy();
        }
    });

    it('opens the internal-automation centre from the sidebar', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('#internalAutomationNav').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#internalAutomationCenter')).toBeTruthy();
    });

    it('opens the notification centre from the sidebar', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('.sidebar-nav [data-action="open-notification-center"]').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#notificationCenter')).toBeTruthy();
        expect(api.get).toHaveBeenCalledWith(expect.stringContaining('/notifications'));
    });

    it('opens the new-ticket modal from the sidebar', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('#ticketListIcon').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#ticketModal')).toBeTruthy();
        expect(api.get).toHaveBeenCalledWith('/tickets/categories');
    });

    it('opens the profile panel from the profile dropdown', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('[data-action="open-profile-panel"]').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#profilePanel')).toBeTruthy();
    });

    it('opens the support centre from the profile dropdown', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('[data-action="open-support-center"]').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#userSupportCenter')).toBeTruthy();
        expect(api.get).toHaveBeenCalledWith(expect.stringContaining('/tickets?'));
    });

    it('opens the notification centre from the bell’s “view all”', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('.notification-view-all').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#notificationCenter')).toBeTruthy();
    });

    it('marks the document open and lets Escape close every overlay', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('#internalAutomationNav').trigger('click');
        await flushPromises();
        expect(document.body.classList.contains('leave-modal-open')).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(rendered(wrapper).querySelector('#internalAutomationCenter')).toBeNull();
        expect(document.body.classList.contains('leave-modal-open')).toBe(false);
    });

    it('closes an overlay from its backdrop', async () => {
        const wrapper = await mountPanel();

        await wrapper.find('.sidebar-nav [data-action="open-notification-center"]').trigger('click');
        await flushPromises();

        await wrapper.find('.notification-center-backdrop').trigger('click');
        await flushPromises();

        expect(rendered(wrapper).querySelector('#notificationCenter')).toBeNull();
    });
});
