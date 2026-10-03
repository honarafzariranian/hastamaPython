import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '@/services/api';
import CallDisplayPage from '@/pages/call/CallDisplayPage.vue';

/**
 * Parity test for `/call-display` against the Python reference page.
 *
 * The expected DOM is read straight out of `app/templates/call-display.html`
 * and the behavioural expectations out of `app/static/js/call-display.js`
 * (hero/previous-slot arithmetic, the `is-active` / `is-paused` slideshow
 * classes, the `hideChrome`/`showChrome` opacity dance, the WS frame protocol
 * with its `tag` and `audio_activated` frames, the Latin counter of the ticket
 * row, the `cd-auto-resume` reload flag and the page-scoped document identity).
 */

vi.mock('@/services/api', () => {
    const mocks = { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() };
    return { default: mocks, api: mocks };
});

/* vitest از ریشه‌ی laravel/ اجرا می‌شود؛ قالب پایتون یک سطح بالاتر است. */
const PYTHON_TEMPLATE = resolve(process.cwd(), '../app/templates/call-display.html');
const PYTHON_JS = resolve(process.cwd(), '../app/static/js/call-display.js');

function pythonDocument() {
    const html = readFileSync(PYTHON_TEMPLATE, 'utf-8');
    return new DOMParser().parseFromString(html, 'text/html');
}

/* ── WebSocket فیک (قرارداد connect() پایتون) ────────────────────────── */
const wsInstances = [];

class MockWebSocket {
    static CONNECTING = 0;

    static OPEN = 1;

    static CLOSED = 3;

    constructor(url) {
        this.url = url;
        this.readyState = MockWebSocket.CONNECTING;
        this.sent = [];
        wsInstances.push(this);
    }

    send(payload) {
        this.sent.push(payload);
    }

    close() {
        this.readyState = MockWebSocket.CLOSED;
        if (this.onclose) this.onclose();
    }

    /* کمک‌متد تست: رویدادهای واقعیِ مرورگر را بازسازی می‌کند */
    simulateOpen() {
        this.readyState = MockWebSocket.OPEN;
        if (this.onopen) this.onopen();
    }

    simulateMessage(payload) {
        if (this.onmessage) this.onmessage({ data: JSON.stringify(payload) });
    }
}

const lastWs = () => wsInstances[wsInstances.length - 1];

/* ── پاسخ‌های پیش‌فرض API به شکل envelope پایتون ─────────────────────── */
const EMPTY_ROUTES = {
    'GET /calls/display-queue': { success: true, queue: [] },
    'GET /queue/list?status=waiting': { success: true, tickets: [] },
    'GET /calls/slides/active': { success: true, slides: [] },
};

function installApi(overrides = {}) {
    api.get.mockImplementation((url) => {
        const key = 'GET ' + url;
        if (key in overrides) return Promise.resolve(overrides[key]);

        return Promise.resolve(EMPTY_ROUTES[key] ?? { success: true });
    });
}

/* ── مقایسه‌گر بازگشتی شکل DOM (تگ، کلاس‌ها، متن، فرزندان) ────────────── */
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
        expect(
            collapse(vueEl.textContent).includes(pyText) || collapse(directText(vueEl)) === pyText,
            `text mismatch at ${here}: «${pyText}»`,
        ).toBe(true);
    }

    const pyChildren = Array.from(pyEl.children);
    const vueChildren = Array.from(vueEl.children).filter((child) => child.tagName !== 'SCRIPT');
    expect(vueChildren.length, `child count mismatch at ${here}`).toBe(pyChildren.length);
    pyChildren.forEach((child, index) => expectSameShape(child, vueChildren[index], here, ignoreClassPrefixes));
}

let wrapper;

function mountPage() {
    wrapper = mount(CallDisplayPage, { attachTo: document.body });

    return wrapper;
}

async function mounted() {
    mountPage();
    await flushPromises();
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    vi.clearAllMocks();
    wsInstances.length = 0;
    installApi();
    document.body.className = '';
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
    document.head.querySelectorAll('link[rel~="icon"]').forEach((node) => node.remove());
    window.localStorage.clear();
    sessionStorage.clear();
    vi.stubGlobal('WebSocket', MockWebSocket);
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('no network in tests')));
});

afterEach(() => {
    if (wrapper) {
        wrapper.unmount();
        wrapper = null;
    }
    document.body.className = '';
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('ساختار ایستای قالب پایتون', () => {
    it('همه‌ی idهای قالب پایتون در صفحه‌ی Vue هم هستند', async () => {
        await mounted();

        const ids = Array.from(pythonDocument().querySelectorAll('[id]')).map((el) => el.id);
        expect(ids.length).toBeGreaterThan(15);
        for (const id of ids) {
            expect(document.getElementById(id), `element #${id} should exist`).toBeTruthy();
        }
    });

    it('هر چهار اسلات فراخوان‌های قبلی با همان id و ساختار وجود دارد', async () => {
        await mounted();

        for (const id of ['slot1', 'slot2', 'slot3', 'slot4']) {
            const slot = document.getElementById(id);
            expect(slot, `#${id} should exist`).toBeTruthy();
            expect(slot.className).toContain('cd-prev-slot');
            expect(slot.querySelector('.cd-prev-num')).toBeTruthy();
            expect(slot.querySelector('.cd-prev-dept')).toBeTruthy();
        }
    });

    it('کل درخت بدنه (تگ، کلاس، متن، ترتیب) با قالب پایتون یکی است', async () => {
        await mounted();

        const pyBody = pythonDocument().body;
        /* بدنه‌ی پایتون دو <script> هم دارد؛ آن‌ها بخشی از markup صفحه‌ی Vue نیستند */
        const pyChildren = Array.from(pyBody.children).filter((el) => el.tagName !== 'SCRIPT');
        const vueChildren = Array.from(wrapper.element.children);
        expect(vueChildren.length, 'same number of top-level blocks').toBe(pyChildren.length);
        pyChildren.forEach((child, index) => {
            /*
             * `is-empty`/`is-active` روی اسلات‌ها و کلاس حالتِ ردیف اتصال را
             * خودِ JS پایتون بعد از init می‌نویسد (در markup ایستا نیستند)؛
             * برای همین از مقایسه بیرون‌اند و پایین‌تر جدا/assert می‌شوند.
             */
            expectSameShape(child, vueChildren[index], 'body', ['is-', 'cd-status-row--']);
        });

        /* همان چیزی که setStatus('connecting') در اولین connect() می‌نویسد */
        expect(document.getElementById('displayStatus').className)
            .toBe('cd-status-row cd-status-row--conn cd-status-row--connecting');
        expect(document.getElementById('connIconWrap').className)
            .toBe('cd-status-icon-wrap cd-status-icon-wrap--connecting');
    });

    it('متن‌های ثابت هدر، فوتر و کارت وضعیت عیناً پایتون است', async () => {
        await mounted();

        expect(wrapper.find('.cd-title').text()).toBe('آزمایشگاه تشخیص طبی دکتر امینی');
        expect(wrapper.find('.cd-subtitle').text()).toBe('همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز');
        expect(wrapper.find('.cd-hero-waiting-text').text()).toBe('در انتظار فراخوان');
        expect(wrapper.find('.cd-queue-label').text()).toBe('نوبت‌های صادر شده');
        expect(wrapper.find('.cd-previous-label').text()).toBe('فراخوان‌های قبلی');
        expect(wrapper.find('.cd-footer-brand').text()).toBe('سامانه فراخوان هستما');
        expect(wrapper.find('.cd-footer-company').text()).toBe('محصولی از شرکت هنر افزار ایرانیان');
        expect(wrapper.find('.cd-overlay-title').text()).toBe('فعال‌سازی صدای فراخوان');
        expect(wrapper.find('.cd-overlay-desc').text())
            .toBe('برای شنیدن اعلان‌های صوتی، روی دکمه زیر کلیک کنید');
    });

    it('لوگو همان فایل پایتون و جای‌نما با همان کلاس‌هاست', async () => {
        await mounted();

        const img = wrapper.find('#labLogoImg');
        expect(img.attributes('src')).toBe('/images/lab-logo.png?v=20260928');
        expect(img.attributes('alt')).toBe('لوگوی آزمایشگاه');
        expect(wrapper.find('#labLogoPlaceholder').classes()).toContain('cd-lab-logo-placeholder');
    });

    it('عناصر لازم (audio، preload، idهای فعال‌سازی) مثل قالب‌اند', async () => {
        await mounted();

        const audio = wrapper.find('#callAudio');
        expect(audio.exists()).toBe(true);
        expect(audio.attributes('preload')).toBe('auto');
        expect(wrapper.find('#activateBtn').classes()).toContain('cd-overlay-btn');
        expect(wrapper.find('#heroCall').attributes('style')).toContain('display: none');
    });

    it('کلاس‌های لازمِ صفحه در استایل‌شیت پایتون تعریف شده‌اند', async () => {
        await mounted();

        const css = readFileSync(resolve(process.cwd(), 'resources/css/legacy/call-display.css'), 'utf-8');
        const used = Array.from(new Set(
            wrapper.findAll('*')
                .filter((node) => node.classes().length > 0)
                .flatMap((node) => node.classes())
                .filter((name) => name.startsWith('cd-')),
        ));
        expect(used.length).toBeGreaterThan(20);
        for (const name of used) {
            expect(css.includes('.' + name), `.${name} has no rule in call-display.css`).toBe(true);
        }
    });
});

describe('هویت سند (عنوان، فاوآیکون، تم)', () => {
    it('عنوان و فاوآیکون قالب پایتون اعمال و هنگام خروج بازگردانده می‌شود', async () => {
        document.title = 'عنوان قبلی';
        await mounted();

        expect(document.title).toBe('سامانه فراخوان نمونه‌گیری — نمایشگر');
        const icon = document.querySelector('link[rel~="icon"]');
        expect(icon.getAttribute('href')).toBe('/favicon.ico');
        expect(icon.getAttribute('type')).toBe('image/x-icon');

        wrapper.unmount();
        wrapper = null;
        expect(document.title).toBe('عنوان قبلی');
    });

    it('کلاس call-display-page روی بدنه می‌نشیند (قرارداد <body class> پایتون)', async () => {
        await mounted();
        expect(document.body.classList.contains('call-display-page')).toBe(true);
    });

    it('کلاس‌های تم سراسری حذف و در unmount بازگردانده می‌شوند', async () => {
        document.documentElement.classList.add('dark-mode', 'dark-theme');
        document.body.classList.add('dark-mode', 'dark-theme');
        document.documentElement.setAttribute('data-theme', 'dark');

        await mounted();

        expect(document.body.classList.contains('dark-mode')).toBe(false);
        expect(document.body.classList.contains('dark-theme')).toBe(false);
        expect(document.documentElement.classList.contains('dark-mode')).toBe(false);
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');

        wrapper.unmount();
        wrapper = null;

        expect(document.body.classList.contains('dark-mode')).toBe(true);
        expect(document.documentElement.classList.contains('dark-theme')).toBe(true);
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    });

    it('صفحه‌ی زنجیره‌ی flex بدنه را در SPA حفظ می‌کند (#app:has(.cd-slideshow))', () => {
        const sfc = readFileSync(resolve(process.cwd(), 'resources/js/pages/call/CallDisplayPage.vue'), 'utf-8');
        expect(sfc).toMatch(/#app:has\(\.cd-slideshow\)/);
    });
});

describe('فعال‌سازی صدا (initAudio پایتون)', () => {
    it('overlay در ابتدا نمایان است و با کلیک بسته می‌شود', async () => {
        await mounted();

        const overlay = document.getElementById('activateOverlay');
        expect(overlay.hasAttribute('hidden')).toBe(false);

        await wrapper.find('#activateBtn').trigger('click');
        expect(overlay.hasAttribute('hidden')).toBe(true);
    });

    it('کلیک روی فعال‌سازی، فریم audio_activated را به سرور می‌فرستد', async () => {
        await mounted();

        lastWs().simulateOpen();
        await wrapper.find('#activateBtn').trigger('click');

        expect(lastWs().sent.some((frame) => JSON.parse(frame).type === 'audio_activated')).toBe(true);
    });

    it('پس از باز شدن سوکت، تگ display/preview مثل پایتون ارسال می‌شود', async () => {
        await mounted();

        lastWs().simulateOpen();
        const tag = JSON.parse(lastWs().sent[0]);
        expect(tag.tag).toBe('display');
    });

    it('وقتی تلویزیون صدا را فعال کرد، پیش‌نمایش overlay را می‌بندد', async () => {
        await mounted();

        lastWs().simulateOpen();
        lastWs().simulateMessage({ type: 'audio_activated' });
        await flushPromises();

        expect(document.getElementById('activateOverlay').hasAttribute('hidden')).toBe(true);
    });
});

describe('فراخوان‌ها (displayCall / removeCall / resetDisplay)', () => {
    it('reception_call شماره، بخش و پیام را روی hero می‌گذارد', async () => {
        await mounted();
        lastWs().simulateOpen();

        lastWs().simulateMessage({
            type: 'reception_call',
            data: {
                number: '12',
                persian_number: '۱۲',
                department: 'نمونه‌گیری',
                message: 'لطفاً به بخش نمونه‌گیری مراجعه کنید.',
            },
        });
        await flushPromises();

        expect(document.getElementById('heroNumber').textContent).toBe('۱۲');
        expect(document.getElementById('heroDept').textContent).toBe('نمونه‌گیری');
        expect(document.getElementById('heroMessage').textContent).toBe('لطفاً به بخش نمونه‌گیری مراجعه کنید.');
        expect(document.getElementById('heroWaiting').hasAttribute('hidden')).toBe(true);
        expect(document.getElementById('heroCall').style.display).not.toBe('none');
    });

    it('فراخوان بعدی، قبلی را به اسلات ۱ منتقل می‌کند (همان شیفت پایتون)', async () => {
        await mounted();
        lastWs().simulateOpen();

        const call = (num) => lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: String(num), persian_number: String(num), department: 'پذیرش', message: '' },
        });

        call(10);
        await flushPromises();
        call(11);
        await flushPromises();

        expect(document.querySelector('#slot1 .cd-prev-num').textContent).toBe('10');
        expect(document.getElementById('heroNumber').textContent).toBe('11');
        expect(document.querySelector('#slot1').className).toContain('is-active');
        expect(document.querySelector('#slot2').className).toContain('is-empty');
    });

    it('remove_call شماره را پاک و اسلات‌ها را فشرده می‌کند', async () => {
        await mounted();
        lastWs().simulateOpen();

        const call = (num) => lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: String(num), persian_number: String(num), department: 'پذیرش', message: '' },
        });

        call(1);
        call(2);
        call(3);
        await flushPromises();

        lastWs().simulateMessage({ type: 'remove_call', data: { number: 2 } });
        await flushPromises();

        /* پایتون: hero (۳) دست‌نخورده می‌ماند، ۲ از اسلات‌ها حذف و ردیف فشرده می‌شود */
        expect(document.getElementById('heroNumber').textContent).toBe('3');
        expect(document.querySelector('#slot1 .cd-prev-num').textContent).toBe('1');
        expect(document.querySelector('#slot2 .cd-prev-num').textContent).toBe('');
        expect(document.querySelector('#slot2').className).toContain('is-empty');
    });

    it('reset_display همه‌چیز را به حالت «در انتظار فراخوان» برمی‌گرداند', async () => {
        await mounted();
        lastWs().simulateOpen();

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '7', persian_number: '۷', department: 'نمونه‌گیری', message: '' },
        });
        await flushPromises();

        lastWs().simulateMessage({ type: 'reset_display', data: {} });
        await flushPromises();

        expect(document.getElementById('heroWaiting').hasAttribute('hidden')).toBe(false);
        expect(document.getElementById('heroCall').style.display).toBe('none');
        expect(document.querySelector('#slot1').className).toContain('is-empty');
    });

    it('refresh_display کلید cd-auto-resume را می‌گذارد و صفحه را reload می‌کند', async () => {
        vi.useFakeTimers();
        await mounted();
        lastWs().simulateOpen();

        lastWs().simulateMessage({ type: 'refresh_display', data: {} });

        expect(sessionStorage.getItem('cd-auto-resume')).toBe('1');
        /* reload در setTimeout(400) است؛ تایمر اجرا نمی‌شود تا jsdom ناوبری نکند */
    });

    it('سرریز فراخوان‌های قبلی: پنج شماره، چهار اسلات و قدیمی‌ترین حذف', async () => {
        await mounted();
        lastWs().simulateOpen();

        for (const num of [1, 2, 3, 4, 5]) {
            lastWs().simulateMessage({
                type: 'reception_call',
                data: { number: String(num), persian_number: String(num), department: 'پذیرش', message: '' },
            });
        }
        await flushPromises();

        expect(document.getElementById('heroNumber').textContent).toBe('5');
        expect(document.querySelector('#slot1 .cd-prev-num').textContent).toBe('4');
        expect(document.querySelector('#slot4 .cd-prev-num').textContent).toBe('1');
    });
});

describe('صفِ نوبت‌دهی و بازیابی حالت از سرور', () => {
    it('نوبت‌های صادرشده با شمارهٔ فارسی و شمارندهٔ لاتین رندر می‌شوند', async () => {
        installApi({
            'GET /queue/list?status=waiting': {
                success: true,
                tickets: [
                    { id: 1, ticket_number: 7, persian_number: '۷', service: 'پذیرش' },
                    { id: 2, ticket_number: 8, persian_number: '۸', service: 'آزمایش' },
                ],
            },
        });

        await mounted();

        expect(document.getElementById('cdQueueCount').textContent).toBe('2 نفر در صف');
        const items = wrapper.findAll('.cd-queue-item');
        expect(items).toHaveLength(2);
        expect(items[0].find('.cd-queue-item-num').text()).toBe('۷');
        expect(items[0].find('.cd-queue-item-service').text()).toBe('پذیرش');
    });

    it('بدون نوبت، همان پیام خالیِ قالب پایتون می‌ماند', async () => {
        await mounted();

        expect(document.querySelector('.cd-queue-empty').textContent).toBe('هنوز نوبتی صادر نشده است');
        expect(document.getElementById('cdQueueCount').textContent).toBe('0 نفر در صف');
    });

    it('display-queue هنگام boot، hero و اسلات‌ها را پر می‌کند (بدون message)', async () => {
        installApi({
            'GET /calls/display-queue': {
                success: true,
                queue: [
                    { reception_number: '21', persian_number: '۲۱', department: 'نمونه‌گیری' },
                    { reception_number: '20', persian_number: '۲۰', department: 'پذیرش' },
                ],
            },
        });

        await mounted();

        expect(document.getElementById('heroNumber').textContent).toBe('۲۱');
        expect(document.getElementById('heroDept').textContent).toBe('نمونه‌گیری');
        expect(document.getElementById('heroMessage').textContent).toBe('');
        expect(document.querySelector('#slot1 .cd-prev-num').textContent).toBe('۲۰');
    });

    it('صف نوبت‌ها هر ۵ ثانیه بازخوانی می‌شود (همان setInterval پایتون)', async () => {
        vi.useFakeTimers();
        await mounted();
        const before = api.get.mock.calls.length;

        await vi.advanceTimersByTimeAsync(5000);
        expect(api.get.mock.calls.length).toBeGreaterThan(before);
        expect(api.get.mock.calls.some((call) => call[0] === '/queue/list?status=waiting')).toBe(true);
    });
});

describe('اسلایدشو و کروم صفحه', () => {
    const slidesResponse = {
        success: true,
        slides: [
            { id: 1, url: '/static/slides/a.png' },
            { id: 2, url: '/static/slides/b.png' },
        ],
    };

    it('با اسلاید فعال: کانتینر is-active، اسلاید اول is-visible و کروم مخفی', async () => {
        installApi({ 'GET /calls/slides/active': slidesResponse });
        await mounted();

        const slideshow = document.getElementById('cdSlideshow');
        expect(slideshow.className).toContain('is-active');
        const images = slideshow.querySelectorAll('.cd-slideshow-img');
        expect(images).toHaveLength(2);
        expect(images[0].className).toContain('is-visible');
        expect(images[1].className).not.toContain('is-visible');
        expect(wrapper.find('.cd-topbar').element.style.opacity).toBe('0');
        expect(wrapper.find('.cd-footer').element.style.opacity).toBe('0');
        expect(wrapper.find('.cd-content').element.style.opacity).toBe('0');
        expect(document.getElementById('statusCard').style.opacity).toBe('0');
    });

    it('بدون اسلاید، is-active گذاشته نمی‌شود و کروم نمایان می‌ماند', async () => {
        await mounted();

        expect(document.getElementById('cdSlideshow').className).not.toContain('is-active');
        expect(wrapper.find('.cd-topbar').element.style.opacity).toBe('');
    });

    it('هر ۲۰ ثانیه اسلاید بعدی نمایش داده می‌شود', async () => {
        vi.useFakeTimers();
        installApi({ 'GET /calls/slides/active': slidesResponse });
        await mounted();

        await vi.advanceTimersByTimeAsync(20000);

        const images = document.querySelectorAll('.cd-slideshow-img');
        expect(images[0].className).not.toContain('is-visible');
        expect(images[1].className).toContain('is-visible');
    });

    it('فراخوان: اسلایدشو متوقف (is-paused + opacity 0) و کروم نمایش داده می‌شود', async () => {
        installApi({ 'GET /calls/slides/active': slidesResponse });
        await mounted();
        lastWs().simulateOpen();

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '5', persian_number: '۵', department: 'پذیرش', message: '' },
        });
        await flushPromises();

        const slideshow = document.getElementById('cdSlideshow');
        expect(slideshow.className).toContain('is-paused');
        expect(slideshow.style.opacity).toBe('0');
        expect(wrapper.find('.cd-topbar').element.style.opacity).toBe('');
    });

    it('پس از ۳۰ ثانیه اسلایدشو از سر گرفته و کروم دوباره مخفی می‌شود', async () => {
        vi.useFakeTimers();
        installApi({ 'GET /calls/slides/active': slidesResponse });
        await mounted();
        lastWs().simulateOpen();

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '5', persian_number: '۵', department: 'پذیرش', message: '' },
        });

        await vi.advanceTimersByTimeAsync(30000 + 1500);

        const slideshow = document.getElementById('cdSlideshow');
        expect(slideshow.className).not.toContain('is-paused');
        expect(slideshow.style.opacity).toBe('');
        expect(wrapper.find('.cd-topbar').element.style.opacity).toBe('0');
    });

    it('انیمیشن hero برای هر فراخوان از نو اجرا می‌شود (none → reflow → بازنشانی)', async () => {
        await mounted();
        lastWs().simulateOpen();

        const hero = document.getElementById('heroCall');
        hero.style.animation = 'paused-by-hand';

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '9', persian_number: '۹', department: 'پذیرش', message: '' },
        });
        await flushPromises();
        await flushPromises();

        expect(hero.style.animation).toBe('');
    });
});

describe('وضعیت اتصال و همگام‌سازی بدون سوکت', () => {
    it('اتصال باز: بج «متصل» با کلاس connected روی ردیف و آیکون', async () => {
        await mounted();
        lastWs().simulateOpen();
        await flushPromises();

        expect(document.getElementById('statusText').textContent.trim()).toBe('متصل');
        expect(document.getElementById('displayStatus').className).toContain('cd-status-row--connected');
        expect(document.getElementById('connIconWrap').className).toContain('cd-status-icon-wrap--connected');
    });

    it('قطع سوکت: reconnect با backoff و صفحه تا بازگشت سوکت از API همگام می‌ماند', async () => {
        vi.useFakeTimers();
        await mounted();
        lastWs().simulateOpen();
        await flushPromises();

        expect(document.getElementById('statusText').textContent.trim()).toBe('متصل');

        lastWs().close();
        await flushPromises();

        /* backoff پایتون: ۱ ثانیه پس از اولین قطع */
        await vi.advanceTimersByTimeAsync(1000);
        expect(wsInstances.length).toBe(2);

        const queueCalls = () => api.get.mock.calls.filter((call) => call[0] === '/calls/display-queue').length;
        const before = queueCalls();
        await vi.advanceTimersByTimeAsync(5000);
        expect(queueCalls()).toBeGreaterThan(before);

        /* تا fallback داده می‌آورد، بج معنای «نمایشگر زنده است» را نگه می‌دارد */
        expect(document.getElementById('statusText').textContent.trim()).toBe('متصل');

        /* و با باز شدن سوکت، polling خاموش می‌شود (صفحه فقط رویداد زنده می‌خواند) */
        lastWs().simulateOpen();
        await flushPromises();
        const settled = queueCalls();
        await vi.advanceTimersByTimeAsync(20000);
        expect(queueCalls()).toBe(settled);
        expect(document.getElementById('statusText').textContent.trim()).toBe('متصل');
    });

    it('سوکتِ بدون پاسخ: صفحه با display-queue همگام می‌ماند (fallback لاراول)', async () => {
        vi.useFakeTimers();
        const queue = {
            success: true,
            queue: [{ reception_number: '33', persian_number: '۳۳', department: 'نمونه‌گیری' }],
        };
        installApi({ 'GET /calls/display-queue': queue });

        await mounted();

        const before = api.get.mock.calls.filter((call) => call[0] === '/calls/display-queue').length;
        await vi.advanceTimersByTimeAsync(5000);
        const after = api.get.mock.calls.filter((call) => call[0] === '/calls/display-queue').length;

        expect(after).toBeGreaterThan(before);
        expect(document.getElementById('statusText').textContent.trim()).toBe('متصل');
        expect(document.getElementById('heroNumber').textContent).toBe('۳۳');
    });

    it('قطع کامل (سوکت و API): بج «قطع»', async () => {
        vi.useFakeTimers();
        api.get.mockRejectedValue(new Error('down'));

        await mounted();
        await vi.advanceTimersByTimeAsync(5000);

        expect(document.getElementById('statusText').textContent.trim()).toBe('قطع');
    });

    it('بازگشت از bfcache سوکت را از نو وصل می‌کند (pageshow پایتون)', async () => {
        await mounted();
        const first = lastWs();
        first.simulateOpen();
        await flushPromises();

        window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
        await flushPromises();

        expect(wsInstances.length).toBe(2);
        /* هندلرهای سوکت بازنشسته باید جدا شوند، وگرنه onclose قدیمی وضعیت
           سوکت تازه را خراب می‌کند */
        expect(first.onclose).toBeNull();
        expect(first.onopen).toBeNull();
        expect(document.getElementById('statusText').textContent.trim()).toBe('در حال اتصال...');

        lastWs().simulateOpen();
        await flushPromises();
        expect(document.getElementById('statusText').textContent.trim()).toBe('متصل');
    });

    it('قرارداد سوکت همان پایتون است: ping هر ۲۵ ثانیه روی همان مسیر', async () => {
        vi.useFakeTimers();
        await mounted();

        const socket = lastWs();
        const expectedProto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        expect(socket.url).toBe(`${expectedProto}//${location.host}/api/ws/call-display`);

        socket.simulateOpen();
        await vi.advanceTimersByTimeAsync(25000);
        expect(socket.sent).toContain('ping');
    });
});

describe('پروتکل فایل صوتی', () => {
    class MockAudio {
        static instances = [];

        constructor() {
            this._src = '';
            this.volume = 1;
            MockAudio.instances.push(this);
        }

        set src(value) {
            this._src = value;
        }

        get src() {
            return this._src;
        }

        load() {}

        play() {
            return Promise.resolve();
        }

        pause() {}
    }

    beforeEach(() => {
        MockAudio.instances = [];
        vi.stubGlobal('Audio', MockAudio);
    });

    it('مسیر mp3 از همان AUDIO_BASE و شمارهٔ چهاررقمی ساخته می‌شود', async () => {
        const pythonSource = readFileSync(PYTHON_JS, 'utf-8');
        expect(pythonSource).toMatch(/AUDIO_BASE = '\/static\/audio\/sample_call\/fa-IR-DilaraNeural\/'/);

        await mounted();
        lastWs().simulateOpen();
        await wrapper.find('#activateBtn').trigger('click');

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '7', persian_number: '۷', department: 'پذیرش', message: '' },
        });
        await flushPromises();

        expect(MockAudio.instances).toHaveLength(1);
        expect(MockAudio.instances[0].src).toBe('/static/audio/sample_call/fa-IR-DilaraNeural/0007.mp3');
    });

    it('شمارهٔ خارج از بازهٔ ۱..۲۰۰۰ پخش نمی‌شود (همان getAudioUrl پایتون)', async () => {
        await mounted();
        lastWs().simulateOpen();
        await wrapper.find('#activateBtn').trigger('click');

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '2001', persian_number: '۲۰۰۱', department: 'پذیرش', message: '' },
        });
        await flushPromises();

        expect(MockAudio.instances).toHaveLength(0);
    });

    it('فایل نبودن، همان پیام پایتون را روی hero می‌نویسد (اعداد فارسی)', async () => {
        await mounted();
        lastWs().simulateOpen();
        await wrapper.find('#activateBtn').trigger('click');

        lastWs().simulateMessage({
            type: 'reception_call',
            data: { number: '7', persian_number: '۷', department: 'پذیرش', message: '' },
        });
        await flushPromises();

        MockAudio.instances[0].onerror();
        await flushPromises();

        expect(document.getElementById('heroMessage').textContent).toBe('فایل صوتی شماره ۷ موجود نیست.');
    });
});
