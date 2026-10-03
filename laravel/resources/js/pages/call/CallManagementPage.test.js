import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '@/services/api';
import CallManagementPage from '@/pages/call/CallManagementPage.vue';

/**
 * Parity test against the Python reference page.
 *
 * The expected DOM is not written by hand — it is read straight out of
 * `app/templates/call-management.html` and compared with the mounted Vue
 * page.  Behavioural expectations come from
 * `app/static/js/call-system-standalone.js`, `toast.js` and `hastama-ux.js`
 * (the loading-state icon loss, the Latin/Persian digit choices per counter,
 * the two different confirm dialogs, the per-field patient copy buttons …).
 */

vi.mock('@/services/api', () => {
    const mocks = { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() };
    return { default: mocks, api: mocks };
});

/* vitest از ریشه‌ی laravel/ اجرا می‌شود؛ قالب پایتون یک سطح بالاتر است. */
const PYTHON_TEMPLATE = resolve(process.cwd(), '../app/templates/call-management.html');

function pythonDocument() {
    const html = readFileSync(PYTHON_TEMPLATE, 'utf-8');
    return new DOMParser().parseFromString(html, 'text/html');
}

/* ── WebSocket فیک (قرارداد connectWS پایتون) ─────────────────────────── */
const wsInstances = [];

class MockWebSocket {
    static OPEN = 1;

    constructor(url) {
        this.url = url;
        this.readyState = MockWebSocket.OPEN;
        this.sent = [];
        wsInstances.push(this);
    }

    send(payload) {
        this.sent.push(payload);
    }

    close() {
        if (this.onclose) this.onclose();
    }
}

/* ── پاسخ‌های پیش‌فرض API به شکل envelope پایتون ─────────────────────── */
const DEFAULT_ROUTES = {
    'GET /calls/display-queue': { success: true, queue: [] },
    'GET /calls/waiting-queue': { success: true, items: [] },
    'GET /queue/list?status=waiting': { success: true, tickets: [] },
    'GET /calls/recent?limit=30': { success: true, calls: [] },
    'GET /calls/status': { success: true, connected_displays: 0, real_displays: 0, preview_displays: 0 },
    'GET /calls/audio-status': { success: true, exists: false, total_files: 0, total_expected: 2000 },
    'GET /calls/slides': { success: true, slides: [] },
};

function installApi(overrides = {}) {
    api.get.mockImplementation((url) => {
        const key = 'GET ' + url;
        if (key in overrides) return Promise.resolve(overrides[key]);
        return Promise.resolve(DEFAULT_ROUTES[key] ?? { success: true });
    });
    api.post.mockImplementation((url) => {
        const key = 'POST ' + url;
        if (key in overrides) return Promise.resolve(overrides[key]);
        return Promise.resolve({ success: true });
    });
    api.delete.mockImplementation((url) => {
        const key = 'DELETE ' + url;
        if (key in overrides) return Promise.resolve(overrides[key]);
        return Promise.resolve({ success: true });
    });
    api.put.mockImplementation((url) => {
        const key = 'PUT ' + url;
        if (key in overrides) return Promise.resolve(overrides[key]);
        return Promise.resolve({ success: true });
    });
}

const rafTwice = () => new Promise((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(resolve, 0)));
});

/* ── مقایسه‌گر بازگشتی شکل DOM (تگ، مجموعه‌ی کلاس‌ها، متن، فرزندان) ───── */
function classSet(el) {
    return (el.getAttribute('class') || '').split(/\s+/).filter(Boolean).sort().join(' ');
}

function collapse(text) {
    return (text || '').replace(/\s+/g, ' ').trim();
}

function directText(el) {
    let text = '';
    for (const node of el.childNodes) {
        if (node.nodeType === 3) text += node.textContent;
    }
    return collapse(text);
}

function expectSameShape(pyEl, vueEl, path, ignoreClassPrefixes = []) {
    const here = `${path}>${pyEl.tagName.toLowerCase()}`;
    const classes = (el) =>
        classSet(el)
            .split(' ')
            .filter((c) => c && !ignoreClassPrefixes.some((p) => c.startsWith(p)))
            .join(' ');
    expect(vueEl, `missing element at ${here}`).toBeTruthy();
    expect(vueEl.tagName.toLowerCase(), `tag mismatch at ${here}`).toBe(pyEl.tagName.toLowerCase());
    expect(classes(vueEl), `class mismatch at ${here}`).toBe(classes(pyEl));

    const pyText = directText(pyEl);
    if (pyText !== '') {
        expect(collapse(vueEl.textContent).includes(pyText) || collapse(directText(vueEl)) === pyText,
            `text mismatch at ${here}: «${pyText}»`).toBe(true);
    }

    const pyChildren = Array.from(pyEl.children);
    const vueChildren = Array.from(vueEl.children).filter(
        (child) => child.tagName !== 'SCRIPT' && child.tagName.toLowerCase() !== 'script'
    );
    expect(vueChildren.length, `child count mismatch at ${here}`).toBe(pyChildren.length);
    pyChildren.forEach((child, index) => expectSameShape(child, vueChildren[index], here, ignoreClassPrefixes));
}

let wrapper;

function mountPage() {
    wrapper = mount(CallManagementPage, { attachTo: document.body });
    return wrapper;
}

beforeEach(() => {
    vi.clearAllMocks();
    wsInstances.length = 0;
    installApi();
    document.body.className = '';
    document.head.querySelectorAll('link[rel~="icon"]').forEach((node) => node.remove());
    window.localStorage.clear();
    vi.stubGlobal('WebSocket', MockWebSocket);
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('no network in tests')));
    vi.stubGlobal('matchMedia', undefined);
});

afterEach(() => {
    if (wrapper) {
        wrapper.unmount();
        wrapper = null;
    }
    document.body.className = '';
    vi.unstubAllGlobals();
});

describe('ساختار ایستای قالب پایتون', () => {
    it('همه‌ی idهای قالب پایتون در صفحه‌ی Vue هم هستند', async () => {
        mountPage();
        await flushPromises();

        const pyDoc = pythonDocument();
        const ids = Array.from(pyDoc.querySelectorAll('[id]')).map((el) => el.id);
        expect(ids.length).toBeGreaterThan(30);
        for (const id of ids) {
            expect(document.getElementById(id), `element #${id} should exist`).toBeTruthy();
        }
    });

    it('هدر دقیقاً همان ساختار قالب پایتون را دارد', async () => {
        mountPage();
        await flushPromises();

        const pyHeader = pythonDocument().querySelector('header.cs-header');
        expectSameShape(pyHeader, wrapper.find('header').element, 'header');

        const logo = wrapper.find('#csLabLogo');
        expect(logo.attributes('src')).toBe('/images/newlogo.png?v=20260928');
        expect(logo.attributes('alt')).toBe('لوگو');
        expect(wrapper.find('#csThemeToggle').attributes('title')).toBe('تغییر حالت نمایش');
        expect(wrapper.find('.cs-header-title').text()).toBe('سامانه فراخوان نمونه‌گیری');
        expect(wrapper.find('.cs-header-sub').text()).toBe('آزمایشگاه دکتر امینی');
    });

    it('نوار زبانه‌ها و ترتیب/فعال بودن آن‌ها مثل قالب پایتون است', async () => {
        mountPage();
        await flushPromises();

        const pyTabs = pythonDocument().querySelector('.cs-tabs');
        expectSameShape(pyTabs, wrapper.find('.cs-tabs').element, '.cs-tabs');

        /* زبانه‌ی فعال اولیه: نوبت‌ها */
        const tabIds = ['cs-tab-tickets', 'cs-tab-queue', 'cs-tab-slides', 'cs-tab-preview', 'cs-tab-history'];
        for (const id of tabIds) {
            const content = wrapper.find('#' + id);
            expect(content.exists(), `#${id} should exist`).toBe(true);
            const active = id === 'cs-tab-tickets';
            expect(content.classes('cs-tab-content--active'), `${id} active class`).toBe(active);
            expect(content.element.style.display === 'none', `${id} inline hidden`).toBe(!active);
        }
    });

    it('پانل چپ (فرم فراخوان و دکمه‌های عملیات) با قالب پایتون یکی است', async () => {
        mountPage();

        /* مقایسه قبل از رفتن داده‌ها: همان لحظه‌ای که قالب پایتون رندر شده */
        const pyLeft = pythonDocument().querySelector('.cs-col--left');
        expectSameShape(pyLeft, wrapper.find('.cs-col--left').element, '.cs-col--left');

        /* وضعیت صدا در ابتدا خالی است (همان div خالی پایتون) */
        expect(wrapper.find('#csAudioStatus').text()).toBe('');
        expect(wrapper.find('#csAudioStatus').attributes('class')).toBe('cs-audio-info');

        /* بعد از بارگذاری هم مثل JS پایتون کلاس وضعیت می‌گیرد */
        await flushPromises();
        expect(wrapper.find('#csAudioStatus').classes()).toContain('cs-audio-info--missing');
    });

    it('فوتر، بج اتصال و cs-modal با قالب پایتون یکی‌اند', async () => {
        /* loadStatus را معلق نگه می‌داریم تا بج همان‌طور که در init پایتون است
           در حالت «در حال اتصال» بماند (Promise.resolve روی promise معلق اثر ندارد) */
        installApi({ 'GET /calls/status': new Promise(() => {}) });
        mountPage();
        await flushPromises();

        const pyDoc = pythonDocument();
        /* بج اتصال: قالب پایتون بدون کلاس وضعیت است؛ Vue هم فقط همان کلاس پایه را دارد */
        expectSameShape(pyDoc.getElementById('csConnBadge'), wrapper.find('#csConnBadge').element, 'csConnBadge', ['cs-conn-float--']);

        /* مثل init() پایتون: اتصال WebSocket بلافاصله به حالت در حال اتصال می‌رود */
        expect(wrapper.find('#csConnBadge').classes()).toContain('cs-conn-float--connecting');
        expect(wrapper.find('#csStatusText').text()).toBe('در حال اتصال...');

        expectSameShape(pyDoc.querySelector('.cs-footer'), wrapper.find('.cs-footer').element, 'footer');
        expectSameShape(pyDoc.getElementById('csModal'), wrapper.find('#csModal').element, 'csModal');

        /* متن ثابت دکمه‌ی تأیید cs-modal در قالب پایتون */
        expect(wrapper.find('#csModalConfirm').text()).toBe('بله، پاک کن');
        expect(wrapper.find('#csModalCancel').text()).toBe('انصراف');
    });

    it('پشته‌ی toast مثل پایتون فقط با اولین پیام ساخته می‌شود', async () => {
        mountPage();
        await flushPromises();

        expect(document.getElementById('toastStack')).toBeNull();
        await wrapper.find('#csCallBtn').trigger('click'); // ورودی خالی → خطا
        await flushPromises();
        expect(document.getElementById('toastStack')).toBeTruthy();
    });
});

describe('صف نمایشگر و شمارنده‌ها', () => {
    it('بج صف با اعداد لاتین «0 / 5» شروع و با داده به‌روز می‌شود', async () => {
        installApi({
            'GET /calls/display-queue': {
                success: true,
                queue: [{ reception_number: '45', persian_number: '۴۵', department: 'خون‌گیری' }],
            },
        });
        mountPage();
        await flushPromises();

        expect(wrapper.find('#csQueueCount').text()).toBe('1 / 5');

        const hero = wrapper.find('#csQueueHero');
        expect(hero.classes()).toContain('is-active');
        expect(hero.find('.cs-queue-slot-num').text()).toBe('۴۵');
        expect(hero.find('.cs-queue-slot-dept').text()).toBe('خون‌گیری');
        expect(hero.find('.cs-queue-slot-empty').text()).toBe('—');
        expect(hero.find('.cs-queue-remove').exists()).toBe(true);

        expect(wrapper.find('#csQueue1').classes()).toContain('is-empty');
    });

    it('حذف یک شماره از نمایشگر همان منطق فشرده‌سازی پایتون را دارد', async () => {
        installApi({
            'GET /calls/display-queue': {
                success: true,
                queue: [
                    { reception_number: '45', persian_number: '۴۵', department: 'الف' },
                    { reception_number: '46', persian_number: '۴۶', department: 'ب' },
                ],
            },
            'POST /calls/remove': { success: true, message: 'حذف شد.' },
        });
        mountPage();
        await flushPromises();

        await wrapper.find('#csQueueHero .cs-queue-remove').trigger('click');
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith('/calls/remove', { number: '45' });
        expect(wrapper.find('#csQueueHero .cs-queue-slot-num').text()).toBe('۴۶');
        expect(wrapper.find('#csQueue1').classes()).toContain('is-empty');
        expect(wrapper.find('#csQueueCount').text()).toBe('1 / 5');
    });
});

describe('فراخوان، وضعیت لودینگ و toast (قرارداد toast.js)', () => {
    it('ورودی خالی همان toast خطای پایتون را با ساختار toast.js نشان می‌دهد', async () => {
        mountPage();
        await flushPromises();

        await wrapper.find('#csCallBtn').trigger('click');
        await flushPromises();
        await rafTwice();

        const toast = wrapper.find('.toast-stack .toast');
        expect(toast.classes()).toContain('toast--error');
        expect(toast.classes()).toContain('show');
        expect(toast.find('.toast__icon').text()).toBe('×');
        expect(toast.find('.toast__title').text()).toBe('خطای سامانه');
        expect(toast.find('.toast__message').text()).toBe('لطفاً شماره پذیرش را وارد کنید.');
        expect(toast.find('.toast__close').exists()).toBe(true);
        expect(toast.find('.toast__timeline span').exists()).toBe(true);
        expect(toast.attributes('role')).toBe('alert');
    });

    it('حین ارسال دکمه فقط «در حال ارسال...» است و پس از آن آیکون برنمی‌گردد', async () => {
        let resolveCall;
        api.post.mockImplementation((url) => {
            if (url === '/calls') return new Promise((resolve) => { resolveCall = resolve; });
            return Promise.resolve({ success: true });
        });
        mountPage();
        await flushPromises();

        await wrapper.find('#csNumberInput').setValue('101');
        await wrapper.find('#csCallBtn').trigger('click');

        /* setLoading(btn,true) در پایتون: کل محتوای دکمه «در حال ارسال...» می‌شود */
        const btn = wrapper.find('#csCallBtn');
        expect(btn.text()).toBe('در حال ارسال...');
        expect(btn.find('svg').exists()).toBe(false);
        expect(btn.attributes('disabled')).toBeDefined();

        resolveCall({
            success: true,
            message: 'فراخوان با موفقیت ارسال شد.',
            data: { number: '101', persian_number: '۱۰۱', department: 'نمونه‌گیری', timestamp: '2026-10-03T09:00:00' },
        });
        await flushPromises();

        /* بازگشت: متن برمی‌گردد اما آیکون نه — همان رفتار textContent پایتون */
        expect(btn.text()).toBe('فراخوان');
        expect(btn.find('svg').exists()).toBe(false);

        /* صف و تاریخچه به‌روز شده‌اند و ورودی پاک شده است */
        expect(wrapper.find('#csQueueHero .cs-queue-slot-num').text()).toBe('۱۰۱');
        expect(wrapper.find('#csRecentList .cs-history-item .cs-history-num').text()).toBe('۱۰۱');
        expect(wrapper.find('#csNumberInput').element.value).toBe('');

        /* toast موفقیت با ساختار toast.js */
        await rafTwice();
        const toast = wrapper.find('.toast-stack .toast');
        expect(toast.classes()).toContain('toast--success');
        expect(toast.find('.toast__title').text()).toBe('عملیات موفق');
        expect(toast.find('.toast__icon').text()).toBe('✓');
        expect(toast.find('.toast__message').text()).toBe('فراخوان با موفقیت ارسال شد.');
        expect(toast.attributes('role')).toBe('status');
    });

    it('کلید Enter روی ورودی شماره همان فراخوان را می‌فرستد', async () => {
        mountPage();
        await flushPromises();

        await wrapper.find('#csNumberInput').setValue('9');
        await wrapper.find('#csNumberInput').trigger('keydown', { key: 'Enter' });
        expect(api.post).toHaveBeenCalledWith('/calls', { reception_number: '9', department: 'نمونه‌گیری' });
    });

    it('تست صدا همان endpoint پایتون (test-audio?number=1) را صدا می‌زند', async () => {
        window.fetch.mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, message: 'تست صدا ارسال شد.' }) });
        mountPage();
        await flushPromises();

        await wrapper.find('#csTestVoiceBtn').trigger('click');
        await flushPromises();
        await rafTwice();

        expect(window.fetch).toHaveBeenCalledWith('/api/calls/test-audio?number=1', { method: 'POST' });
        expect(wrapper.find('.toast__message').text()).toBe('تست صدا ارسال شد.');
    });

    it('تست نمایشگر همان endpoint پایتون را صدا می‌زند', async () => {
        window.fetch.mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, message: 'پیام آزمایشی ارسال شد.' }) });
        mountPage();
        await flushPromises();

        await wrapper.find('#csTestDisplayBtn').trigger('click');
        await flushPromises();

        expect(window.fetch).toHaveBeenCalledWith('/api/calls/test-display', { method: 'POST' });
    });
});

describe('وضعیت نمایشگر، بج اتصال و overlay پیش‌نمایش', () => {
    it('با نمایشگر متصل: بج «متصل (2)» با عدد لاتین و کارت وضعیت به‌روز', async () => {
        installApi({
            'GET /calls/status': { success: true, connected_displays: 3, real_displays: 2, preview_displays: 1 },
        });
        mountPage();
        await flushPromises();

        expect(wrapper.find('#csConnBadge').classes()).toContain('cs-conn-float--connected');
        expect(wrapper.find('#csStatusText').text()).toBe('متصل (2)');

        expect(wrapper.find('#csDisplayStatusLabel').text()).toBe('نمایشگر متصل است');
        expect(wrapper.find('#csDisplayStatusSub').text()).toBe('2 دستگاه نمایشگر فعال در شبکه');
        expect(wrapper.find('#csDisplayDot').classes()).toContain('cs-display-status-dot--on');
        expect(wrapper.find('#csDisplayStatusBody').classes()).toContain('cs-display-status-body--connected');
        expect(wrapper.find('#csPreviewOverlay').classes()).toContain('is-hidden');
    });

    it('وضعیت صدا با اعداد لاتین مثل پایتون رندر می‌شود', async () => {
        installApi({
            'GET /calls/audio-status': { success: true, exists: true, total_files: 1530, total_expected: 2000 },
        });
        mountPage();
        await flushPromises();

        expect(wrapper.find('#csAudioStatus').text()).toBe('فایل‌های صوتی: 1530 / 2000');
        expect(wrapper.find('#csAudioStatus').classes()).toContain('cs-audio-info--ok');
    });

    it('بدون فایل صوتی همان متن/کلاس پایتون', async () => {
        mountPage();
        await flushPromises();

        expect(wrapper.find('#csAudioStatus').text()).toBe('فایل صوتی موجود نیست');
        expect(wrapper.find('#csAudioStatus').classes()).toContain('cs-audio-info--missing');
    });

    it('WebSocket: reception_call به صف و تاریخچه اضافه می‌کند و is_test نمی‌کند', async () => {
        mountPage();
        await flushPromises();

        const ws = wsInstances[0];
        const expectedWsUrl = `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}/api/ws/call-display`;
        expect(ws.url).toBe(expectedWsUrl);
        ws.onopen();
        expect(ws.sent).toContain(JSON.stringify({ tag: 'preview' }));

        ws.onmessage({ data: JSON.stringify({ type: 'reception_call', data: { number: '12', persian_number: '۱۲', department: 'رادیولوژی', timestamp: '2026-10-03T08:30:00', is_test: false } }) });
        await flushPromises();

        expect(wrapper.find('#csQueueHero .cs-queue-slot-num').text()).toBe('۱۲');
        expect(wrapper.find('#csRecentList .cs-history-item').exists()).toBe(true);
    });
});

describe('صف پذیرش', () => {
    it('آیتم‌ها با ساختار پایتون رندر و شمارنده فارسی می‌شود', async () => {
        installApi({
            'GET /calls/waiting-queue': {
                success: true,
                items: [
                    { id: 5, reception_number: '21', persian_number: '۲۱', department: 'خون‌گیری', created_at: '2026-10-03T08:00:00' },
                    { id: 6, reception_number: '22', persian_number: '۲۲', department: 'نمونه‌گیری', created_at: '2026-10-03T08:10:00' },
                ],
            },
        });
        mountPage();
        await flushPromises();

        expect(wrapper.find('#csWaitingCount').text()).toBe('۲');
        const items = wrapper.findAll('#csWaitingList .cs-waiting-item');
        expect(items).toHaveLength(2);
        expect(items[0].find('.cs-waiting-num').text()).toBe('۲۱');
        expect(items[0].find('.cs-waiting-dept').text()).toBe('خون‌گیری');
        expect(items[0].find('.cs-waiting-btn--call').attributes('title')).toBe('فراخوان');
        expect(items[0].find('.cs-waiting-btn--remove').attributes('title')).toBe('حذف');
        expect(wrapper.find('#csWaitingEmpty').element.style.display).toBe('none');
    });

    it('افزودن به صف: بازخوانی صف، پاک شدن ورودی و toast پایتون', async () => {
        installApi({ 'POST /calls/waiting-queue': { success: true, message: 'شماره ۴ به صف اضافه شد.', id: 9 } });
        mountPage();
        await flushPromises();

        await wrapper.find('#csWaitingInput').setValue('4');
        await wrapper.find('#csWaitingAddBtn').trigger('click');
        await flushPromises();
        await rafTwice();

        expect(api.post).toHaveBeenCalledWith('/calls/waiting-queue', { number: '4', department: 'نمونه‌گیری' });
        expect(wrapper.find('#csWaitingInput').element.value).toBe('');
        expect(wrapper.find('.toast__message').text()).toBe('شماره ۴ به صف اضافه شد.');
    });

    it('ورودی صف خالی همان پیام پایتون را می‌دهد (متفاوت از فرم فراخوان)', async () => {
        mountPage();
        await flushPromises();

        await wrapper.find('#csWaitingAddBtn').trigger('click');
        await flushPromises();
        await rafTwice();

        expect(wrapper.find('.toast__message').text()).toBe('لطفاً شماره را وارد کنید.');
    });
});

describe('نوبت‌ها (ساختار کامل آیتمِ بیمار)', () => {
    const TICKET = {
        id: 3,
        ticket_number: '101',
        persian_number: '۱۰۱',
        status: 'waiting',
        service: 'آزمایشگاه',
        patient_name: 'علی رضایی',
        patient_age: '۳۴',
        patient_national_id: '0011223344',
        patient_phone: null,
        insurance_base: 'تأمین اجتماعی',
        insurance_extra: null,
        created_at: '2026-10-03T07:45:00',
    };

    function mountWithTickets(extraOverrides = {}) {
        installApi({
            'GET /queue/list?status=waiting': { success: true, tickets: [TICKET] },
            ...extraOverrides,
        });
        mountPage();
    }

    it('شمارنده‌ی نوبت‌ها لاتین است و آیتم با جزئیات بیمار ساخته می‌شود', async () => {
        mountWithTickets();
        await flushPromises();

        expect(wrapper.find('#csTicketCount').text()).toBe('1');

        const item = wrapper.find('#csTicketList .cs-waiting-item');
        expect(item.attributes('data-id')).toBe('3');
        expect(item.attributes('aria-expanded')).toBe('false');
        expect(item.find('.cs-waiting-num').text()).toBe('۱۰۱');
        expect(item.find('.cs-waiting-dept').text()).toBe('آزمایشگاه');

        /* فیلدهای خالی (تلفن، بیمه تکمیلی) رندر نمی‌شوند — شرط `if (!field[1]) return` */
        const fields = item.findAll('.cs-ticket-patient__field');
        expect(fields).toHaveLength(4);
        expect(fields[0].find('small').text()).toBe('نام');
        expect(fields[0].find('strong').text()).toBe('علی رضایی');
        expect(fields[3].find('small').text()).toBe('بیمه پایه');
        expect(fields[3].find('.cs-ticket-copy-btn').attributes('title')).toBe('کپی بیمه پایه');

        expect(item.find('.cs-ticket-details-toggle').text()).toBe('مشاهده جزئیات');
        expect(item.find('.cs-ticket-side .cs-waiting-time').exists()).toBe(true);
        expect(item.find('.cs-waiting-btn--remove').attributes('aria-label')).toBe('حذف نوبت');
    });

    it('باز و بسته کردن جزئیات نوبت همان رفتار details toggle پایتون است', async () => {
        mountWithTickets();
        await flushPromises();

        const item = wrapper.find('#csTicketList .cs-waiting-item');
        await item.find('.cs-ticket-details-toggle').trigger('click');

        expect(item.classes()).toContain('is-expanded');
        expect(item.attributes('aria-expanded')).toBe('true');
        expect(item.find('.cs-ticket-details-toggle').text()).toBe('بستن جزئیات');

        await item.find('.cs-ticket-details-toggle').trigger('click');
        expect(item.classes()).not.toContain('is-expanded');
        expect(item.find('.cs-ticket-details-toggle').text()).toBe('مشاهده جزئیات');

        /* Enter/Space هم همان کار را می‌کند */
        await item.find('.cs-ticket-details-toggle').trigger('keydown', { key: 'Enter' });
        expect(item.classes()).toContain('is-expanded');
    });

    it('کپی فیلد بیمار: کلاس copied آیکون تعویض و toast «نام کپی شد.»', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        mountWithTickets();
        await flushPromises();

        const copyBtn = wrapper.find('.cs-ticket-patient__field .cs-ticket-copy-btn');
        await copyBtn.trigger('click');
        await flushPromises();
        await rafTwice();

        expect(writeText).toHaveBeenCalledWith('علی رضایی');
        expect(copyBtn.classes()).toContain('copied');
        expect(wrapper.find('.toast__message').text()).toBe('نام کپی شد.');
    });

    it('وضعیت called/complete متن dept را مثل پایتون می‌سازد', async () => {
        installApi({
            'GET /queue/list?status=waiting': {
                success: true,
                tickets: [
                    { ...TICKET, id: 7, status: 'called', called_for: 'نمونه‌گیری' },
                    { ...TICKET, id: 8, status: 'completed', called_for: null },
                ],
            },
        });
        mountPage();
        await flushPromises();

        const items = wrapper.findAll('#csTicketList .cs-waiting-item');
        expect(items[0].find('.cs-waiting-dept').text()).toBe('نمونه‌گیری — فراخوان شده');
        expect(items[1].find('.cs-waiting-dept').text()).toBe('آزمایشگاه — انجام شده');
        /* دکمه‌های اکشن فقط برای نوبت‌های در انتظارند */
        expect(items[0].find('.cs-waiting-actions').exists()).toBe(false);
    });

    it('حذف نوبت با HastamaUX.confirm (هشدار سه‌گوش) انجام می‌شود', async () => {
        installApi({
            'GET /queue/list?status=waiting': { success: true, tickets: [TICKET] },
            'DELETE /queue/3': { success: true, message: 'نوبت حذف شد.' },
        });
        mountPage();
        await flushPromises();

        await wrapper.find('.cs-waiting-btn--remove').trigger('click');
        await flushPromises();
        await rafTwice();

        const overlay = wrapper.find('.h-ux-confirm-overlay');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('h-ux-active');
        expect(overlay.find('.h-ux-confirm-icon').exists()).toBe(true);
        expect(overlay.find('.h-ux-confirm-title').text()).toBe('حذف نوبت');
        expect(overlay.find('.h-ux-confirm-message').text()).toBe('آیا از حذف نوبت ۱۰۱ از صف مطمئن هستید؟');
        expect(overlay.find('#h-ux-confirm-ok').text()).toBe('حذف');
        expect(overlay.find('#h-ux-confirm-cancel').text()).toBe('انصراف');

        await overlay.find('#h-ux-confirm-ok').trigger('click');
        expect(api.delete).toHaveBeenCalledWith('/queue/3');
    });
});

describe('مودال‌های تأیید', () => {
    it('«پاک کردن همه» از cs-modal خودِ قالب (نه تایید UX) استفاده می‌کند', async () => {
        installApi({ 'POST /calls/reset-display': { success: true, message: 'نمایشگر پاک شد.' } });
        mountPage();
        await flushPromises();

        await wrapper.find('#csResetBtn').trigger('click');
        await flushPromises();

        const modal = wrapper.find('#csModal');
        expect(modal.classes()).toContain('is-open');
        expect(wrapper.find('#csModalTitle').text()).toBe('پاک کردن همه');
        expect(wrapper.find('#csModalDesc').text()).toBe('آیا از پاک کردن تمام شماره‌ها از نمایشگر اطمینان دارید؟');
        expect(wrapper.find('#csModalConfirm').text()).toBe('بله، پاک کن');

        await wrapper.find('#csModalConfirm').trigger('click');
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith('/calls/reset-display');
        expect(wrapper.find('#csModal').classes()).not.toContain('is-open');
        expect(wrapper.find('#csQueueHero').classes()).toContain('is-empty');
    });

    it('پاک کردن تاریخچه از HastamaUX.confirm استفاده می‌کند', async () => {
        installApi({ 'DELETE /calls/recent': { success: true, message: 'تاریخچه فراخوان‌ها پاک شد.' } });
        mountPage();
        await flushPromises();

        const clearBtn = wrapper.findAll('.cs-card--history .cs-reset-btn--danger')[0];
        await clearBtn.trigger('click');
        await flushPromises();
        await rafTwice();

        const overlay = wrapper.find('.h-ux-confirm-overlay');
        expect(overlay.find('.h-ux-confirm-title').text()).toBe('پاک کردن تاریخچه');
        expect(overlay.find('.h-ux-confirm-message').text()).toBe('آیا از پاک کردن کامل تاریخچه فراخوان‌ها مطمئن هستید؟');
        expect(overlay.find('#h-ux-confirm-ok').text()).toBe('پاک کردن');

        await overlay.find('#h-ux-confirm-ok').trigger('click');
        await flushPromises();
        expect(api.delete).toHaveBeenCalledWith('/calls/recent');
    });
});

describe('تاریخچه (فیلد زمان دقیقاً مثل پایتون: timestamp)', () => {
    it('ردیف‌های دیتابیس (بدون timestamp) زمان خالی دارند؛ رخداد زنده زمان دارد', async () => {
        installApi({
            'GET /calls/recent?limit=30': {
                success: true,
                calls: [{ reception_number: '77', persian_number: '۷۷', department: 'رادیولوژی', called_by: 'admin', called_at: '2026-10-03T06:00:00' }],
            },
        });
        mountPage();
        await flushPromises();

        /* پایتون fmtTime(data.timestamp) را می‌خواند و ردیف‌های DB فقط called_at دارند */
        const row = wrapper.find('#csRecentList .cs-history-item');
        expect(row.find('.cs-history-num').text()).toBe('۷۷');
        expect(row.find('.cs-history-dept').text()).toBe('رادیولوژی');
        expect(row.find('.cs-history-time').text()).toBe('');

        wsInstances[0].onopen();
        wsInstances[0].onmessage({ data: JSON.stringify({ type: 'reception_call', data: { number: '78', persian_number: '۷۸', department: 'آزمایشگاه', timestamp: '2026-10-03T09:15:00' } }) });
        await flushPromises();

        const liveRow = wrapper.findAll('#csRecentList .cs-history-item')[0];
        expect(liveRow.find('.cs-history-time').text()).not.toBe('');
    });
});

describe('تم مخصوص صفحه، عنوان سند و فاوآیکون', () => {
    it('کلاس cs-page به body افزوده و dark-theme حذف می‌شود؛ عنوان و فاوآیکون پایتون اعمال می‌شود', async () => {
        document.head.insertAdjacentHTML('beforeend', '<link rel="icon" href="/images/newlogo.png" type="image/png">');
        window.localStorage.setItem('hastama-theme', 'dark');
        document.body.classList.add('dark-mode', 'dark-theme');

        mountPage();
        await flushPromises();

        /* تم سراسری dark است اما تمِ خود صفحه (cs-theme) تنظیم نیست → روشن، مثل پایتون */
        expect(document.body.classList.contains('cs-page')).toBe(true);
        expect(document.body.classList.contains('dark-mode')).toBe(false);
        expect(document.body.classList.contains('dark-theme')).toBe(false);

        expect(document.title).toBe('سامانه فراخوان نمونه‌گیری — پنل مدیریت');
        const icon = document.querySelector('link[rel~="icon"]');
        expect(icon.getAttribute('href')).toBe('/favicon.ico');
        expect(icon.getAttribute('type')).toBe('image/x-icon');

        wrapper.unmount();
        wrapper = null;

        /* بازگشت به وضعیت سراسری قبل از ورود */
        expect(document.body.classList.contains('dark-mode')).toBe(true);
        expect(document.body.classList.contains('dark-theme')).toBe(true);
        expect(document.body.classList.contains('cs-page')).toBe(false);
        expect(document.querySelector('link[rel~="icon"]').getAttribute('href')).toBe('/images/newlogo.png');
    });

    it('کلید cs-theme و نبودِ آن → روشن؛ toggle همان قرارداد پایتون را دارد', async () => {
        window.localStorage.setItem('cs-theme', 'dark');
        mountPage();
        await flushPromises();

        expect(document.body.classList.contains('dark-mode')).toBe(true);

        await wrapper.find('#csThemeToggle').trigger('click');
        expect(document.body.classList.contains('dark-mode')).toBe(false);
        expect(window.localStorage.getItem('cs-theme')).toBe('light');

        await wrapper.find('#csThemeToggle').trigger('click');
        expect(document.body.classList.contains('dark-mode')).toBe(true);
        expect(window.localStorage.getItem('cs-theme')).toBe('dark');
    });
});
