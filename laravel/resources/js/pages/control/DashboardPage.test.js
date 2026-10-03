import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '@/services/api';
import DashboardPage from '@/pages/control/DashboardPage.vue';

/**
 * The dashboard is a *visual* port, so the assertions here are about what the
 * page renders rather than what it computes.  Every expected value is the one
 * in `app/static/js/master-admin.js` / `app/templates/master-admin.html`; the
 * test exists because the previous port drifted (eleven cards instead of ten,
 * one shared gradient instead of ten, and a «دسترسی سریع» grid derived from the
 * counters) and nothing failed.
 */

const STATS = {
    total_users: 120,
    active_users: 118,
    online_sessions: 7,
    logins_today: 42,
    failed_logins_today: 3,
    pending_password_resets: 2,
    open_security_events: 0,
    open_errors: 5,
    open_tickets: 9,
    admin_count: 4,
    events_today: 61,
};

const ACTIVITY = [
    {
        event_id: 'EV-1',
        event_type: 'AUTHENTICATION',
        action: 'login',
        username: 'admin',
        module: 'auth',
        status: 'failure',
        severity: 'high',
        created_at: '2026-10-03 08:15:00',
        ip_address: '10.0.0.5',
    },
];

const LEGACY_CARDS = [
    ['کل کاربران', 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', 0, '/master-admin/users'],
    ['کاربران فعال', 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)', 50, null],
    ['نشست‌های فعال', 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)', 100, '/master-admin/sessions'],
    ['ورودهای امروز', 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)', 150, '/master-admin/audit-logs'],
    ['ورود ناموفق', 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)', 200, '/master-admin/security'],
    ['بازیابی رمز', 'linear-gradient(135deg, #fccb90 0%, #d57eeb 100%)', 250, '/master-admin/password-resets'],
    ['رویداد امنیتی', 'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)', 300, '/master-admin/security'],
    ['خطاهای باز', 'linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%)', 350, '/master-admin/errors'],
    ['تیکت‌های باز', 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)', 400, '/master-admin/tickets'],
    ['رویدادهای امروز', 'linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%)', 450, '/master-admin/audit-logs'],
];

const LEGACY_QUICK_LINKS = [
    ['مدیریت تیکت‌ها', '/master-admin/tickets', '#0ea5e9'],
    ['بازیابی رمز', '/master-admin/password-resets', '#f59e0b'],
    ['رویدادهای امنیتی', '/master-admin/security', '#10b981'],
    ['خطاهای سیستم', '/master-admin/errors', '#ef4444'],
    ['نشست‌های فعال', '/master-admin/sessions', '#8b5cf6'],
    ['لاگ حسابرسی', '/master-admin/audit-logs', '#06b6d4'],
    ['مدیریت کاربران', '/master-admin/users', '#ec4899'],
];

function renderedCards(wrapper) {
    return wrapper.findAll('.ma-top-cards-row .ma-glow-card');
}

function renderedQuickLinks(wrapper) {
    return wrapper.findAll('.ma-quick-grid .ma-qcard');
}

beforeEach(() => {
    vi.spyOn(api, 'get').mockImplementation((url) => {
        if (url.includes('/dashboard/stats')) {
            return Promise.resolve({ success: true, data: STATS });
        }

        if (url.includes('/dashboard/activity')) {
            return Promise.resolve({ success: true, data: ACTIVITY });
        }

        return Promise.reject(new Error(`unexpected request: ${url}`));
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('master-admin dashboard', () => {
    it('renders the ten legacy counters, not eleven — admin_count is never shown', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const labels = renderedCards(wrapper).map((card) => card.find('.ma-glow-card__label').text());

        expect(labels).toEqual(LEGACY_CARDS.map(([label]) => label));
        expect(labels).toHaveLength(10);
        expect(labels).not.toContain('مدیران');
    });

    it('gives every card its own legacy gradient and animation delay', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const cards = renderedCards(wrapper);

        cards.forEach((card, index) => {
            const [, gradient, delay] = LEGACY_CARDS[index];

            expect(card.attributes('style')).toContain(gradient);
            expect(card.attributes('style')).toContain(`animation-delay: ${delay}ms`);
        });

        /* A single shared gradient is the bug this test exists to prevent. */
        expect(new Set(cards.map((card) => card.attributes('style'))).size).toBe(10);
    });

    it('anchors the cards the legacy page linked and leaves the rest a div', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const contents = renderedCards(wrapper).map((card) => card.find('.ma-glow-card__content'));

        contents.forEach((content, index) => {
            const href = LEGACY_CARDS[index][3];

            if (href) {
                expect(content.element.tagName).toBe('A');
                expect(content.attributes('href')).toBe(href);
            } else {
                expect(content.element.tagName).toBe('DIV');
            }
        });
    });

    it('asks the layout to navigate to the linked section instead of reloading', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        await wrapper.findAll('.ma-top-cards-row .ma-glow-card__content')[0].trigger('click');

        expect(wrapper.emitted('navigate')).toEqual([['users']]);

        await wrapper.findAll('.ma-quick-grid .ma-qcard')[5].trigger('click');

        expect(wrapper.emitted('navigate')[1]).toEqual(['audit-logs']);
    });

    it('paints the final count first, then steps it up 30ms at a time', async () => {
        vi.useFakeTimers();

        try {
            const wrapper = mount(DashboardPage);
            await flushPromises();

            const values = () => renderedCards(wrapper).map((card) => card.find('.ma-glow-card__value').text());

            /* `loadDashboard()` interpolated the final value into the markup
               before the interval fired, so the first paint is the target. */
            expect(values()[0]).toBe('۱۲۰');

            /* step = max(1, floor(120 / 20)) = 6 */
            await vi.advanceTimersByTimeAsync(30);
            expect(values()[0]).toBe('۶');

            await vi.advanceTimersByTimeAsync(1500);
            expect(values()).toEqual(['۱۲۰', '۱۱۸', '۷', '۴۲', '۳', '۲', '۰', '۵', '۹', '۶۱']);
        } finally {
            vi.useRealTimers();
        }
    });

    it('leaves a zero counter at ۰ with no alert dot, and flags only the non-zero alerts', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const cards = renderedCards(wrapper);

        expect(cards[6].find('.ma-glow-card__value').text()).toBe('۰');
        expect(cards[6].find('.ma-glow-card__alert').exists()).toBe(false);

        /* «رویداد امنیتی» is the only alert-flagged card at zero. */
        const dots = cards.map((card) => card.find('.ma-glow-card__alert').exists());

        expect(dots).toEqual([false, false, false, false, true, true, false, true, false, false]);
    });

    it('keeps the data-count attribute the legacy count-up read its target from', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const targets = renderedCards(wrapper).map((card) => card.find('.ma-glow-card__value').attributes('data-count'));

        expect(targets).toEqual(['120', '118', '7', '42', '3', '2', '0', '5', '9', '61']);
    });

    it('renders the seven legacy quick-access links with their own accents', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const links = renderedQuickLinks(wrapper);

        expect(links).toHaveLength(7);

        links.forEach((link, index) => {
            const [label, href, accent] = LEGACY_QUICK_LINKS[index];

            expect(link.find('.ma-qcard__label').text()).toBe(label);
            expect(link.attributes('href')).toBe(href);
            expect(link.attributes('style')).toContain(`--accent: ${accent}`);
        });
    });

    it('draws an icon inside every quick-access tile', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        renderedQuickLinks(wrapper).forEach((link) => {
            const svg = link.find('.ma-qcard__icon svg');

            expect(svg.exists()).toBe(true);
            expect(svg.element.children.length).toBeGreaterThan(0);
        });
    });

    it('renders the activity feed as the legacy timeline', async () => {
        const wrapper = mount(DashboardPage);
        await flushPromises();

        const item = wrapper.find('.ma-timeline__item');

        expect(item.exists()).toBe(true);
        expect(item.find('.ma-timeline__dot').classes()).toContain('ma-timeline__dot--danger');
        expect(item.find('.ma-timeline__meta').text()).toBe('EV-1 · 10.0.0.5');
        expect(item.find('.ma-timeline__text').text()).toContain('admin');
        expect(item.find('.ma-timeline__text').text()).toContain('در auth');
    });

    it('shows the legacy empty state when the feed has no events', async () => {
        api.get.mockImplementation((url) => (url.includes('/dashboard/stats')
            ? Promise.resolve({ success: true, data: STATS })
            : Promise.resolve({ success: true, data: [] })));

        const wrapper = mount(DashboardPage);
        await flushPromises();

        expect(wrapper.find('.ma-empty__text').text()).toBe('هنوز رویدادی ثبت نشده است');
    });

    it('serves the quick-access grid before the counters arrive, as the server-rendered page did', async () => {
        let releaseStats;
        api.get.mockImplementation((url) => {
            if (url.includes('/dashboard/stats')) {
                return new Promise((resolve) => {
                    releaseStats = () => resolve({ success: true, data: STATS });
                });
            }

            return Promise.resolve({ success: true, data: ACTIVITY });
        });

        const wrapper = mount(DashboardPage);
        await flushPromises();

        expect(renderedQuickLinks(wrapper)).toHaveLength(7);
        expect(wrapper.find('.ma-skeleton').exists()).toBe(true);

        releaseStats();
        await flushPromises();
    });

    it('surfaces a failure instead of rendering a half-populated dashboard', async () => {
        api.get.mockImplementation(() => Promise.reject(new Error('boom')));

        const wrapper = mount(DashboardPage);
        await flushPromises();

        expect(wrapper.find('.h-alert').text()).toBe('boom');
    });
});
