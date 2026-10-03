import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRouter, createWebHistory } from 'vue-router';
import api from '@/services/api';
import AuditLogsPage from '@/pages/control/AuditLogsPage.vue';
import TicketsPage from '@/pages/control/TicketsPage.vue';
import TicketDetailPage from '@/pages/control/TicketDetailPage.vue';

/**
 * Smoke coverage for the three control-centre sections the dashboard links to
 * but this port did not have: the audit trail, the ticket queue and the ticket
 * detail.  The assertions are on the legacy contract — the filter vocabulary,
 * the column set, the ASCII digits on the ticket counters and the query the
 * queue hands the detail page.
 */

const AUDIT_ROWS = [
    {
        event_id: 'EV-9',
        created_at: '2026-10-03 08:15:00',
        event_type: 'AUTHENTICATION',
        action: 'login',
        username: 'admin',
        module: 'auth',
        severity: 'high',
        status: 'failure',
        ip_address: '10.0.0.5',
    },
];

const TICKET_STATS = {
    total: 12,
    open: 9,
    by_status: { resolved: 3 },
    by_priority: { urgent: 2 },
};

const TICKET_ROWS = [
    {
        id: 7,
        ticket_number: null,
        subject: 'مشکل ورود',
        requester_username: 'ali',
        recipient_username: 'support',
        status: 'waiting_for_user',
        priority: 'urgent',
        created_at: '2026-10-03 08:15:00',
    },
];

const TICKET_DETAIL = {
    id: 7,
    ticket_number: 'HT-00000007',
    subject: 'مشکل ورود',
    requester_username: 'ali',
    recipient_username: 'support',
    assigned_to: 'admin',
    category_id: 4,
    category_name: 'دسترسی',
    status: 'open',
    priority: 'high',
    created_at: '2026-10-03T08:15:00Z',
    updated_at: '2026-10-04T09:00:00Z',
    last_message_at: '2026-10-05T10:00:00Z',
    sla_due_at: '2026-10-06T10:00:00Z',
    sla_state: 'overdue',
    messages: [
        { id: 1, author_username: 'ali', body: 'سلام', visibility: 'public', created_at: '2026-10-03T08:15:00Z' },
        { id: 2, author_username: 'admin', body: 'یادداشت', visibility: 'internal', created_at: '2026-10-03T09:00:00Z' },
    ],
    events: [
        { id: 1, actor_username: 'ali', event_type: 'created', metadata: { source: 'kiosk' }, created_at: '2026-10-03T08:15:00Z' },
    ],
};

let calls;

function respond(url) {
    if (url.includes('/audit-logs')) {
        return Promise.resolve({ success: true, data: AUDIT_ROWS, total: 1, page: 1, per_page: 25, pages: 1 });
    }

    if (url.includes('/tickets/stats')) {
        return Promise.resolve({ success: true, data: TICKET_STATS });
    }

    if (/\/tickets\/\d+\/reply$/.test(url)) {
        return Promise.resolve({ success: true, data: {} });
    }

    if (/\/tickets\/categories\/all$/.test(url)) {
        return Promise.resolve({ success: true, data: [{ id: 4, name: 'دسترسی' }] });
    }

    if (/\/tickets\/users\/all$/.test(url)) {
        return Promise.resolve({ success: true, data: [{ username: 'admin', name: 'مدیر', department: 'IT' }] });
    }

    if (/\/tickets\/\d+$/.test(url)) {
        return Promise.resolve({ success: true, data: TICKET_DETAIL });
    }

    if (url.includes('/tickets')) {
        return Promise.resolve({ success: true, data: { items: TICKET_ROWS, total: 1, page: 1, pages: 1 } });
    }

    return Promise.reject(new Error(`unexpected request: ${url}`));
}

beforeEach(() => {
    calls = [];
    setActivePinia(createPinia());

    vi.spyOn(api, 'get').mockImplementation((url, config) => {
        calls.push({ verb: 'GET', url, config });

        return respond(url);
    });
    vi.spyOn(api, 'post').mockImplementation((url, payload, config) => {
        calls.push({ verb: 'POST', url, payload, config });

        return respond(url);
    });
    vi.spyOn(api, 'patch').mockImplementation((url, payload, config) => {
        calls.push({ verb: 'PATCH', url, payload, config });

        return respond(url);
    });
    vi.spyOn(api, 'delete').mockImplementation((url, config) => {
        calls.push({ verb: 'DELETE', url, config });

        return Promise.resolve({ success: true });
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('audit trail', () => {
    it('asks for the legacy page size and the legacy filter vocabulary', async () => {
        const wrapper = mount(AuditLogsPage);
        await flushPromises();

        const request = calls.find((call) => call.url.includes('/audit-logs'));

        expect(request.config.baseURL).toBe('');
        expect(request.config.params).toMatchObject({ page: 1, per_page: 25, event_type: '', severity: '', search: '' });

        const typeOptions = wrapper.find('#filterEventType').findAll('option').map((option) => option.text());
        expect(typeOptions).toEqual(['همه انواع', 'احراز هویت', 'کاربر', 'امنیت', 'مدیریتی', 'داده', 'سیستم']);

        const severityOptions = wrapper.find('#filterSeverity').findAll('option').map((option) => option.text());
        expect(severityOptions).toEqual(['همه اولویت‌ها', 'اطلاعات', 'کم', 'متوسط', 'زیاد', 'بحرانی']);
    });

    it('renders the legacy ten columns and one row per event', async () => {
        const wrapper = mount(AuditLogsPage);
        await flushPromises();

        const headers = wrapper.findAll('thead th').map((cell) => cell.text());

        expect(headers).toEqual([
            'شناسه', 'زمان', 'نوع', 'عملیات', 'کاربر', 'ماژول', 'اولویت', 'وضعیت', 'IP', 'حذف',
        ]);
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
        expect(wrapper.find('tbody tr code').text()).toBe('EV-9');
    });

    it('confirms before deleting a record', async () => {
        const wrapper = mount(AuditLogsPage);
        await flushPromises();

        await wrapper.find('tbody tr button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('حذف رکورد لاگ حسابرسی');
        expect(calls.some((call) => call.verb === 'DELETE')).toBe(false);

        /* The dialog's accept button resolves the confirmation. */
        await wrapper.find('.ma-modal__btn--danger, .ma-btn--danger').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.verb === 'DELETE' && call.url.endsWith('/audit-logs/EV-9'))).toBe(true);
    });
});

describe('ticket queue', () => {
    it('renders the four legacy stat cards in ASCII digits', async () => {
        const wrapper = mount(TicketsPage);
        await flushPromises();

        const cards = wrapper.findAll('.ma-ticket-stats .ma-glow-card');

        expect(cards.map((card) => card.find('.ma-glow-card__label').text()))
            .toEqual(['کل تیکت‌ها', 'تیکت‌های باز', 'حل‌شده', 'فوری']);
        expect(cards.map((card) => card.find('.ma-glow-card__value').text()))
            .toEqual(['12', '9', '3', '2']);
    });

    it('renders the legacy filters and the legacy table', async () => {
        const wrapper = mount(TicketsPage);
        await flushPromises();

        const statusOptions = wrapper.find('#ticketFilterStatus').findAll('option').map((option) => option.text());
        expect(statusOptions).toEqual([
            'همه وضعیت‌ها', 'جدید', 'باز', 'در حال بررسی', 'در انتظار کاربر', 'در انتظار پشتیبانی', 'حل‌شده', 'بسته‌شده',
        ]);

        const sortOptions = wrapper.find('#ticketFilterSort').findAll('option').map((option) => option.text());
        expect(sortOptions).toEqual(['جدیدترین', 'قدیمی‌ترین', 'اولویت']);

        expect(wrapper.findAll('thead th').map((cell) => cell.text())).toEqual([
            'شماره', 'موضوع', 'درخواست‌کننده', 'گیرنده', 'وضعیت', 'اولویت', 'تاریخ', 'عملیات',
        ]);

        /* `ticket_number` is null, so the legacy `HT-00000007` fallback applies. */
        expect(wrapper.find('tbody tr code').text()).toBe('HT-00000007');
        expect(wrapper.text()).toContain('در انتظار کاربر');
        expect(wrapper.text()).toContain('فوری');
    });

    it('pages the queue with 20 rows at a time', async () => {
        const wrapper = mount(TicketsPage);
        await flushPromises();

        const request = calls.find((call) => call.url.includes('/tickets') && !call.url.includes('/stats'));

        expect(request.config.params).toMatchObject({ page: 1, per_page: 20, sort: 'newest' });
    });

    it('opens the detail page at the legacy ?t= address', async () => {
        const wrapper = mount(TicketsPage);
        await flushPromises();

        await wrapper.find('tbody tr button').trigger('click');

        expect(wrapper.emitted('navigate')).toEqual([['ticket-detail', { t: '7' }]]);
    });
});

describe('ticket detail', () => {
    function mountWithQuery(query) {
        const router = createRouter({
            history: createWebHistory(),
            routes: [{ path: '/master-admin/:section', name: 'master-admin-section', component: { template: '<div />' } }],
        });

        return router.push({ name: 'master-admin-section', params: { section: 'ticket-detail' }, query })
            .then(() => mount(TicketDetailPage, { global: { plugins: [router] } }));
    }

    it('says the ticket id is missing when ?t= is absent', async () => {
        const wrapper = await mountWithQuery({});
        await flushPromises();

        expect(wrapper.find('.ma-empty__text').text()).toBe('شناسه تیکت مشخص نشده');
    });

    it('renders the ticket, its messages, its events and the overdue SLA note', async () => {
        const wrapper = await mountWithQuery({ t: '7' });
        await flushPromises();

        expect(wrapper.text()).toContain('HT-00000007 — مشکل ورود');
        expect(wrapper.text()).toContain('سررسید گذشته');
        expect(wrapper.text()).toContain('💬 پیام‌ها (2)');
        expect(wrapper.text()).toContain('یادداشت داخلی');
        expect(wrapper.text()).toContain('source: kiosk');

        /* The internal note is the amber-bordered bubble, the public one is not. */
        const bubbles = wrapper.findAll('.ma-panel-card__body > div')
            .filter((node) => node.text().includes('سلام') || node.text().includes('یادداشت'));

        const internal = bubbles.find((node) => node.text().includes('یادداشت'));

        expect(internal.element.style.borderRightWidth).toBe('3px');
        expect(internal.element.style.borderRightColor).toBe('rgb(245, 158, 11)');
        expect(bubbles.find((node) => node.text().includes('سلام')).element.style.borderRightWidth).toBe('1px');
    });

    it('pre-fills the management selects from the ticket', async () => {
        const wrapper = await mountWithQuery({ t: '7' });
        await flushPromises();

        expect(wrapper.find('#maTicketStatus').element.value).toBe('open');
        expect(wrapper.find('#maTicketPriority').element.value).toBe('high');
        expect(wrapper.find('#maTicketAssignee').element.value).toBe('admin');
        expect(wrapper.find('#maTicketCategory').element.value).toBe('4');
    });

    it('sends all four management fields together, as the legacy save did', async () => {
        const wrapper = await mountWithQuery({ t: '7' });
        await flushPromises();

        await wrapper.findAll('.ma-btn--primary').at(0).trigger('click');
        await flushPromises();

        const saved = calls.find((call) => call.verb === 'PATCH');

        expect(saved.url).toBe('/master-admin/api/tickets/7');
        expect(saved.payload).toEqual({
            status: 'open',
            priority: 'high',
            assigned_to: 'admin',
            category_id: 4,
        });
    });

    it('refuses to post an empty reply', async () => {
        const wrapper = await mountWithQuery({ t: '7' });
        await flushPromises();

        await wrapper.findAll('.ma-btn--primary').at(1).trigger('click');
        await flushPromises();

        expect(wrapper.find('.h-alert').text()).toBe('لطفاً متن پاسخ را وارد کنید');
        expect(calls.some((call) => call.verb === 'POST')).toBe(false);
    });

    it('posts an internal note when the checkbox is ticked', async () => {
        const wrapper = await mountWithQuery({ t: '7' });
        await flushPromises();

        await wrapper.find('#maTicketReplyBody').setValue('  پاسخ  ');
        await wrapper.find('#maTicketReplyInternal').setValue(true);
        await wrapper.findAll('.ma-btn--primary').at(1).trigger('click');
        await flushPromises();

        const posted = calls.find((call) => call.verb === 'POST');

        expect(posted.url).toBe('/master-admin/api/tickets/7/reply');
        expect(posted.payload).toEqual({ body: 'پاسخ', visibility: 'internal' });
    });
});
