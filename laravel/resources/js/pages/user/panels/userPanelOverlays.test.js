import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '@/services/api';
import InternalAutomationCenter from '@/pages/user/panels/InternalAutomationCenter.vue';
import NotificationCenter from '@/pages/user/panels/NotificationCenter.vue';
import ProfilePanel from '@/pages/user/panels/ProfilePanel.vue';
import TicketCreateModal from '@/pages/user/panels/TicketCreateModal.vue';
import UserSupportCenter from '@/pages/user/panels/UserSupportCenter.vue';

/**
 * Parity test for `/user_panel` against the Python reference page.
 *
 * The expected markup is read straight out of `app/templates/user-panel.html`
 * and the behavioural expectations out of `user-panel-script.js`,
 * `ticketing.js`, `internal-automation.js` and `notification-system.js`.
 *
 * The Laravel port models these five legacy containers as overlays rather than
 * page sections, so each test asserts that the mounted overlay still owns the
 * exact ids, class names and entry points the reference document had — that is
 * what makes `user-panel-style.css` apply unchanged.
 */

vi.mock('@/services/api', () => {
    const mocks = { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
    return { default: mocks, api: mocks };
});

const PYTHON_TEMPLATE = resolve(process.cwd(), '../app/templates/user-panel.html');

function pythonDocument() {
    return new DOMParser().parseFromString(readFileSync(PYTHON_TEMPLATE, 'utf-8'), 'text/html');
}

/**
 * Assert that a mounted overlay still owns every id / class the reference owns.
 *
 * `wrapper.element` only points at the FIRST root node, and several of these
 * overlays are multi-root (the centre plus its detail / create dialog), so the
 * whole rendered markup is parsed instead.
 */
function expectSameChrome(wrapper, selectors) {
    const reference = pythonDocument();
    const rendered = new DOMParser()
        .parseFromString(`<div id="__rendered__">${wrapper.html()}</div>`, 'text/html')
        .getElementById('__rendered__');

    for (const selector of selectors) {
        expect(reference.querySelector(selector), `reference is missing ${selector}`).toBeTruthy();
        expect(rendered.querySelector(selector), `vue is missing ${selector}`).toBeTruthy();
    }
}

beforeEach(() => {
    setActivePinia(createPinia());
    api.get.mockReset();
    api.post.mockReset();
    api.patch.mockReset();
    api.delete.mockReset();
    api.get.mockResolvedValue(undefined);
});

afterEach(() => {
    vi.clearAllMocks();
});

describe('profile panel overlay', () => {
    it('owns the same chrome as #profilePanel', async () => {
        const wrapper = mount(ProfilePanel);
        await flushPromises();

        expectSameChrome(wrapper, [
            '#profilePanel.profile-panel',
            '.profile-panel-backdrop',
            '.profile-panel-dialog',
            '.profile-panel-header',
            '.profile-panel-close',
            '.profile-panel-body',
            '.profile-panel-avatar',
            '.profile-panel-grid',
            '.profile-panel-card',
        ]);

        expect(wrapper.find('.profile-panel-header h2').text()).toBe('ویرایش پروفایل');
        expect(wrapper.find('.profile-panel-close').attributes('aria-label')).toBe('بستن پنل پروفایل');
    });

    it('closes from the backdrop and the close button', async () => {
        const wrapper = mount(ProfilePanel);
        await flushPromises();

        await wrapper.find('.profile-panel-close').trigger('click');
        expect(wrapper.emitted('close')).toBeTruthy();

        await wrapper.find('.profile-panel-backdrop').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(2);
    });
});

describe('user support centre overlay', () => {
    it('owns the same chrome as #userSupportCenter', async () => {
        const wrapper = mount(UserSupportCenter);
        await flushPromises();

        expectSameChrome(wrapper, [
            '#userSupportCenter.user-support-center',
            '.user-support-backdrop',
            '.user-support-dialog',
            '.user-support-header',
            '.user-support-heading',
            '.user-support-back',
            '.user-support-header-actions',
            '.user-support-refresh',
            '.user-support-new',
            '.user-support-close',
            '.user-support-summary',
            '.user-support-layout',
            '.user-support-nav',
            '.user-support-nav-title',
            '.user-support-help',
            '.user-support-list-pane',
            '.user-support-list-head',
            '.user-support-eyebrow',
            '.user-support-search',
            '.user-support-ticket-list',
            '.user-support-pagination',
            '.user-support-detail',
            '.user-support-detail-empty',
            '.user-support-empty-mark',
            '.user-support-activity',
            '.matnepaeinticketing',
        ]);

        /* Four summary counters, seven nav filters — the reference's counts. */
        expect(wrapper.findAll('.user-support-summary button')).toHaveLength(4);
        expect(wrapper.findAll('.user-support-nav button')).toHaveLength(7);
    });

    it('reads the caller’s own ticket list from GET /api/tickets', async () => {
        api.get.mockResolvedValue({ items: [], page: 1, pages: 1, total: 0, counts: {} });

        const wrapper = mount(UserSupportCenter);
        await flushPromises();

        expect(api.get).toHaveBeenCalledWith(expect.stringContaining('/tickets?'));
        expect(wrapper.find('#userSupportAllCount').text()).toBe('۰');
    });

    it('opens the create modal instead of navigating away', async () => {
        const wrapper = mount(UserSupportCenter);
        await flushPromises();

        await wrapper.find('.user-support-new').trigger('click');
        expect(wrapper.emitted('new-ticket')).toBeTruthy();
    });

    it('keeps the list rendering an empty state when there are no tickets', async () => {
        const wrapper = mount(UserSupportCenter);
        await flushPromises();

        expect(wrapper.find('.user-support-empty-action').text()).toBe('ثبت درخواست جدید');
    });
});

describe('internal automation centre overlay', () => {
    it('owns the same chrome as #internalAutomationCenter and its create modal', async () => {
        const wrapper = mount(InternalAutomationCenter);
        await flushPromises();

        expectSameChrome(wrapper, [
            '#internalAutomationCenter.internal-automation-center',
            '.internal-automation-backdrop',
            '.internal-automation-dialog',
            '.internal-automation-header',
            '.internal-automation-actions',
            '.internal-automation-new',
            '.internal-automation-close',
            '.internal-automation-layout',
            '.internal-automation-list-pane',
            '.internal-automation-list-head',
            '.internal-automation-list',
            '#internalAutomationConversation.internal-automation-conversation',
        ]);

        expect(wrapper.find('#internalAutomationTitle').text()).toBe('اتوماسیون داخلی');
        expect(wrapper.find('.internal-automation-new').text()).toBe('+ گفت‌وگوی جدید');
    });

    it('opens the create modal with the reference markup', async () => {
        const wrapper = mount(InternalAutomationCenter);
        await flushPromises();

        await wrapper.find('.internal-automation-new').trigger('click');
        await flushPromises();

        expect(wrapper.find('#internalAutomationCreateModal').exists()).toBe(true);
        expectSameChrome(wrapper, [
            '.internal-automation-create-modal',
            '.internal-automation-create-backdrop',
            '.internal-automation-create-dialog',
            '.internal-automation-create-error',
            '.internal-automation-secondary',
            '.internal-automation-primary',
        ]);
        expect(wrapper.find('#internalAutomationCreateTitle').text()).toBe('شروع گفت‌وگوی جدید');
        expect(api.get).toHaveBeenCalledWith('/tickets/users');
    });

    it('loads the conversation list from GET /api/automation', async () => {
        api.get.mockResolvedValue({ items: [{ id: 7, subject: 'هماهنگی', participant_count: 2, message_count: 3, updated_at: null }] });

        const wrapper = mount(InternalAutomationCenter);
        await flushPromises();

        expect(api.get).toHaveBeenCalledWith('/automation');
        expect(wrapper.find('.internal-automation-item').text()).toContain('هماهنگی');
    });

    it('shows the reference empty state before any conversation exists', async () => {
        const wrapper = mount(InternalAutomationCenter);
        await flushPromises();

        expect(wrapper.find('.internal-automation-list-empty strong').text()).toBe('هنوز گفت‌وگویی ایجاد نکرده‌اید');
        expect(wrapper.find('.internal-automation-empty h3').text()).toBe('یک گفتگو را انتخاب کنید');
    });

    it('closes from the backdrop', async () => {
        const wrapper = mount(InternalAutomationCenter);
        await flushPromises();

        await wrapper.find('.internal-automation-backdrop').trigger('click');
        expect(wrapper.emitted('close')).toBeTruthy();
    });
});

describe('notification centre overlay', () => {
    it('owns the same chrome as #notificationCenter and #notificationDetail', async () => {
        const wrapper = mount(NotificationCenter);
        await flushPromises();

        expectSameChrome(wrapper, [
            '#notificationCenter.notification-center',
            '.notification-center-backdrop',
            '.notification-center-panel',
            '.notification-center-head',
            '.notification-center-toolbar',
            '.notification-search',
            '.notification-filter-tabs',
            '.notification-secondary-btn',
            '.notification-user-list',
            '.notification-pagination',
        ]);

        expect(wrapper.find('#notificationCenterTitle').text()).toBe('اعلان‌های من');
        expect(wrapper.findAll('.notification-filter-tabs button')).toHaveLength(3);
    });

    it('reads the inbox from GET /api/notifications and keeps the 12-per-page size', async () => {
        api.get.mockResolvedValue({ items: [], page: 1, pages: 1, total: 0, unread: 0 });

        const wrapper = mount(NotificationCenter);
        await flushPromises();

        expect(api.get).toHaveBeenCalledWith(expect.stringContaining('page_size=12'));
    });

    it('opens the detail dialog only after an item is chosen', async () => {
        api.get.mockResolvedValue({
            items: [{ id: 1, title: 'اعلان', content: 'متن', type: 'info', priority: 'normal', read_at: null, published_at: null }],
            page: 1, pages: 1, total: 1, unread: 1,
        });

        const wrapper = mount(NotificationCenter);
        await flushPromises();

        const rendered = new DOMParser().parseFromString(`<div>${wrapper.html()}</div>`, 'text/html');
        expect(rendered.querySelector('.notification-detail')).toBeNull();

        await wrapper.find('.notification-user-item').trigger('click');
        await flushPromises();

        const after = new DOMParser().parseFromString(`<div>${wrapper.html()}</div>`, 'text/html');
        expect(after.querySelector('.notification-detail')).toBeTruthy();
        expect(after.querySelector('.notification-detail-backdrop')).toBeTruthy();
        expect(after.querySelector('.notification-detail-close')).toBeTruthy();
        expect(after.querySelector('#notificationDetailBody h2').textContent).toBe('اعلان');
        /* Selecting an unread item marks it read, as `showDetail()` did. */
        expect(api.post).toHaveBeenCalledWith('/notifications/1/read');
    });
});

describe('new-ticket modal', () => {
    it('owns the same chrome as #ticketModal', async () => {
        const wrapper = mount(TicketCreateModal);
        await flushPromises();

        expectSameChrome(wrapper, [
            '#ticketModal.user-ticket-create',
            '.user-ticket-create-backdrop',
            '.user-ticket-create-dialog',
            '.user-ticket-create-head',
            '.user-ticket-steps',
            '.user-ticket-create-form',
            '.user-ticket-form-grid',
            '.user-attachment-picker',
            '.user-ticket-create-error',
            '.user-ticket-cancel',
            '.user-ticket-submit',
            '.user-ticket-close',
        ]);

        expect(wrapper.find('#ticketCreateHeading').text()).toBe('چطور می‌توانیم کمک کنیم؟');
        expect(wrapper.findAll('.user-ticket-steps span')).toHaveLength(3);
    });

    it('loads categories and recipients from the reference endpoints', async () => {
        const wrapper = mount(TicketCreateModal);
        await flushPromises();

        expect(api.get).toHaveBeenCalledWith('/tickets/categories');
        expect(api.get).toHaveBeenCalledWith('/tickets/users');
        /* Once the pickers resolve the form is submittable, as the reference was. */
        expect(wrapper.find('.user-ticket-submit').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('#ticketReceiver ~ small').text()).toBe('تمام درخواست‌های پشتیبانی مستقیماً برای مدیر اصلی سامانه ارسال می‌شوند.');
    });

    it('submits to POST /api/tickets addressed to the primary administrator', async () => {
        api.get.mockImplementation((url) => {
            if (url === '/tickets/users') {
                return Promise.resolve({ items: [{ username: 'master', name: 'مدیر' }] });
            }

            return Promise.resolve({ items: [] });
        });
        api.post.mockResolvedValue({ id: 12 });

        const wrapper = mount(TicketCreateModal);
        await flushPromises();

        await wrapper.find('#ticketCreateTitle').setValue('موضوع');
        await wrapper.find('#ticketDescription').setValue('شرح');
        await wrapper.find('.user-ticket-create-form').trigger('submit');
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith('/tickets', expect.objectContaining({
            recipient_username: 'master',
            subject: 'موضوع',
            body: 'شرح',
            priority: 'normal',
        }));
        expect(wrapper.emitted('created')).toBeTruthy();
    });

    it('closes from the cancel button and the backdrop', async () => {
        const wrapper = mount(TicketCreateModal);
        await flushPromises();

        await wrapper.find('.user-ticket-cancel').trigger('click');
        await wrapper.find('.user-ticket-create-backdrop').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(2);
    });
});
