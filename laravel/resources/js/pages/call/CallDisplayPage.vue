<script setup>
/**
 * `/call-display` — نمایشگر تلویزیونی سامانه فراخوان.
 *
 * این کامپوننت بازآفرینیِ دقیقِ صفحه‌ی پایتون است:
 *
 *   - ساختار DOM عیناً مطابق `app/templates/call-display.html` — همان کلاس‌ها،
 *     همان idها، همان ترتیب فرزندان، همان متن‌های ثابت، همان `hidden` روی
 *     overlay و همان `style="display:none"` اولیه روی `#heroCall`.
 *   - رفتار عیناً مطابق `app/static/js/call-display.js`:
 *       * قفل‌گشایی صدا با پخش بی‌صدای `<audio id="callAudio">` (volume 0.01) و
 *         بازگشت overlay اگر autoplay مسدود بود؛
 *       * صف پخش mp3 با `getAudioUrl` (۰۰۰۱..۲۰۰۰) و پیام
 *         «فایل صوتی شمارهٔ X موجود نیست» روی `#heroMessage`؛
 *       * شیفت اسلات‌های «فراخوان‌های قبلی» و فشرده‌سازی آن‌ها پس از remove؛
 *       * اسلایدشو: کلاس `is-active` روی کانتینر، کلاس `is-paused` +
 *         `style.opacity` هنگام فراخوان، و مخفی/نمایش کروم صفحه
 *         (topbar / footer / statusCard / content) — همان hideChrome/showChrome؛
 *       * بازنشانی انیمیشن `cd-heroIn` برای هر فراخوان جدید؛
 *       * `pageshow` (bfcache)، رفرش از راه دور با کلید `cd-auto-resume`،
 *         fullscreen با دابل‌کلیک و reconnect با backoff ۱٫۵× تا ۳۰ ثانیه.
 *   - قرارداد تم: سند پایتون هیچ `theme.js` لود نمی‌کند، پس کلاس‌های
 *     `dark-mode` / `dark-theme` و `data-theme` هنگام ورود از سند برداشته و
 *     هنگام خروج بازگردانده می‌شوند تا `dark-theme.css` باندل (که در آبشار
 *     بعد از call-display.css import شده) پس‌زمینه‌ی ثابت این صفحه را بازنویسی
 *     نکند. بنداندهای زیر از `<style>` همین فایل همان کار را می‌کنند.
 *   - شماره/فونت سند: عنوان و فاوآیکون قالب پایتون روی document اعمال می‌شود
 *     (قرارداد هم‌خانواده‌ی این صفحه، `CallManagementPage.vue`).
 *
 * دو سازگاری مخصوص لاراول، که هیچ‌کدام ظاهر صفحه را تغییر نمی‌دهد:
 *
 *   ۱) `#app` بین بدنه و فرزندان صفحه فاصله می‌اندازد؛ بلوک CSS پایین همان
 *      زنجیره‌ی flex عمومی که در پایتون روی `html, body` است را ادامه می‌دهد.
 *   ۲) سوکت `/api/ws/call-display` در لاراول هنوز سرویس ندارد (Reverb خاموش،
 *      `BROADCAST_CONNECTION=log`). همان پروتکل پایتون اینجا هم اولین تلاش است؛
 *      اگر سوکت باز نشد، صفحه همان `display-queue` ای را که پایتون هنگام boot
 *      می‌خواند هر ۵ ثانیه هم می‌خواند تا دیوارِ نمایشگر زنده بماند، و به‌محض
 *      باز شدن سوکت این polling خاموش می‌شود.
 */
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

/* ── ثابت‌های همان فایل پایتون ───────────────────────────────────────── */
const MAX_PREV = 4;
const SLIDE_INTERVAL = 20000; /* هر اسلاید ۲۰ ثانیه */
const CALL_PAUSE_DURATION = 30000; /* ۳۰ ثانیه نمایش فراخوان */
const AUDIO_BASE = '/static/audio/sample_call/fa-IR-DilaraNeural/';
const PYTHON_TITLE = 'سامانه فراخوان نمونه‌گیری — نمایشگر';

/* لوگوی پایتون در public لاراول کپی شده است (قرارداد AppLayout و CallManagement). */
const logoUrl = '/images/lab-logo.png?v=20260928';

/* ── DOM refs (همان idهای قالب پایتون) ───────────────────────────────── */
const audioEl = ref(null);
const heroCallEl = ref(null);
const topbarEl = ref(null);
const footerEl = ref(null);
const statusCardEl = ref(null);
const contentEl = ref(null);

/* ── وضعیت ───────────────────────────────────────────────────────────── */
const heroData = ref(null);
const prevData = ref(new Array(MAX_PREV).fill(null));
const heroMessage = ref('');
const heroWaitingHidden = ref(false);
const heroCallVisible = ref(false);

const queueTickets = ref([]);
const queueCount = ref(0);

const slides = ref([]);
const currentSlideIndex = ref(-1);
const slidesActive = ref(false);
const slidesPaused = ref(false);

const connState = ref('connecting');
const overlayHidden = ref(false);
const autoResumedRemote = ref(false);

let audioReady = false;
let audioQueue = [];
let audioPlaying = false;

let slideTimer = null;
let callPauseTimer = null;
let messageClearTimer = null;
let ticketsInterval = null;
let fallbackInterval = null;
let reconnectTimer = null;
let pingInterval = null;
let watchdogTimer = null;
let reconnectDelay = 1000;
let ws = null;

/* اگر سوکت ۵ ثانیه باز نشد، صفحه از API همگام می‌ماند (پایتون به سوکت تکیه است). */
const CONNECT_WATCHDOG = 5000;

/* transport */
let wsOpen = false;
let fallbackHealthy = false;
let fallbackStarted = false;
let lastQueueSignature = null;

/* document identity (مختص این سند) */
let previousTitle = '';
let previousFavicon = null;
let previousDocumentState = null;

const heroNumber = computed(() => heroData.value?.persian_number || heroData.value?.number || '');
const heroDept = computed(() => heroData.value?.department || '');
const isPreview = () => window.location !== window.parent.location;

const slideshowStyle = computed(() => (slidesPaused.value ? { opacity: '0' } : null));

/* همان برچسب‌های setStatus در پایتون: متصل / قطع / در حال اتصال... */
const statusLabel = computed(() => {
    if (connState.value === 'connected') return 'متصل';
    if (connState.value === 'disconnected') return 'قطع';

    return 'در حال اتصال...';
});

/* ── Status ─────────────────────────────────────────────────────────── */
/* در پایتون setStatus(state) سه کلاس/متن را یک‌جا بازنویسی می‌کند؛ اینجا همان
   سه حالت: connecting (اولین تلاش)، connected (سوکت باز یا polling سالم) و
   disconnected. */
function setStatus(state) {
    connState.value = state;
}

function syncConnState() {
    if (wsOpen || fallbackHealthy) {
        setStatus('connected');
    } else {
        setStatus('disconnected');
    }
}

/* ── Audio activation (همان initAudio پایتون) ────────────────────────── */
function initAudio() {
    if (audioReady) return;

    try {
        if (audioEl.value) {
            audioEl.value.volume = 0.01;
            audioEl.value
                .play()
                .then(() => {
                    audioEl.value.pause();
                    audioEl.value.currentTime = 0;
                    audioEl.value.volume = 1;
                    audioReady = true;
                })
                .catch(() => {
                    audioReady = false;
                    /* autoplay مسدود بود — اگر این تلاش بعد از رفرش از راه دور
                       بوده، overlay فعال‌سازی برگردد تا با یک کلیک صدا بیاید */
                    if (autoResumedRemote.value) {
                        autoResumedRemote.value = false;
                        overlayHidden.value = false;
                    }
                });
        }
    } catch {
        /* ignore */
    }

    audioReady = true;
    overlayHidden.value = true;
    notifyAudioActivated();
}

function notifyAudioActivated() {
    /* اطلاع‌رسانی فعال‌سازی صدا به سرور (پیش‌نمایشِ پنل مدیریت هم overlay را ببندد) */
    if (ws && ws.readyState === WebSocket.OPEN) {
        try {
            ws.send(JSON.stringify({ type: 'audio_activated' }));
        } catch {
            /* ignore */
        }
    }
}

/* ── Audio URL / queue ───────────────────────────────────────────────── */
function getAudioUrl(number) {
    const num = parseInt(String(number).replace(/[^\d]/g, ''), 10);
    if (Number.isNaN(num) || num < 1 || num > 2000) return null;
    let padded = String(num);
    while (padded.length < 4) padded = '0' + padded;

    return AUDIO_BASE + padded + '.mp3';
}

function queueAudio(number) {
    const url = getAudioUrl(number);
    if (!url) return;
    audioQueue.push({ url, number });
    processAudioQueue();
}

function processAudioQueue() {
    if (audioPlaying || audioQueue.length === 0 || !audioReady) return;
    audioPlaying = true;
    const item = audioQueue.shift();

    const audio = new Audio();
    audio.preload = 'auto';
    audio.src = item.url;

    audio.oncanplaythrough = () => {
        audio.play().catch(() => {
            audioPlaying = false;
            setTimeout(processAudioQueue, 500);
        });
    };
    audio.onended = () => {
        audioPlaying = false;
        setTimeout(processAudioQueue, 300);
    };
    audio.onerror = () => {
        audioPlaying = false;
        showAudioError(item.number);
        setTimeout(processAudioQueue, 500);
    };
    audio.load();
}

function showAudioError(number) {
    heroMessage.value = 'فایل صوتی شماره ' + toPersianDigits(String(number)) + ' موجود نیست.';
    clearTimeout(messageClearTimer);
    messageClearTimer = setTimeout(() => {
        heroMessage.value = '';
    }, 5000);
}

/* ── Display a call ─────────────────────────────────────────────────── */
function displayCall(data) {
    if (!data || !data.number) return;

    /* شیفت اسلات‌های قبلی و هل دادن hero فعلی به اسلات ۱ — دقیقاً پایتون */
    for (let j = MAX_PREV - 1; j > 0; j--) {
        prevData.value[j] = prevData.value[j - 1];
    }
    if (heroData.value && heroData.value.number) {
        prevData.value[0] = heroData.value;
    }
    heroData.value = data;

    renderHero();

    /* قطع اسلایدشو و نمایان کردن کروم هنگام فراخوان */
    onCallInterrupt();

    if (data.number && audioReady) queueAudio(data.number);
}

/* ── Render hero ────────────────────────────────────────────────────── */
function renderHero() {
    if (!heroData.value) {
        heroCallVisible.value = false;
        heroWaitingHidden.value = false;
        heroMessage.value = '';

        return;
    }

    heroWaitingHidden.value = true;
    heroCallVisible.value = true;
    heroMessage.value = heroData.value.message || '';

    /* Re-trigger animation — سه خط پایتون (none → reflow → '') */
    nextTick(() => {
        const el = heroCallEl.value;
        if (!el) return;
        el.style.animation = 'none';
        void el.offsetHeight;
        el.style.animation = '';
    });
}

/* ── Remote refresh (دستور «بازخوانی نمایشگر» از پنل مدیریت) ─────────── */
function handleRemoteRefresh() {
    try {
        sessionStorage.setItem('cd-auto-resume', '1');
    } catch {
        /* ignore */
    }
    setTimeout(() => window.location.reload(), 400);
}

/* ── Reset / Remove ─────────────────────────────────────────────────── */
function resetDisplay() {
    heroData.value = null;
    prevData.value = new Array(MAX_PREV).fill(null);
    renderHero();
    clearTimeout(callPauseTimer);
    resumeSlideshow();
}

function removeCall(number) {
    const num = String(number).replace(/[^\d]/g, '');

    if (heroData.value && String(heroData.value.number).replace(/[^\d]/g, '') === num) {
        heroData.value = null;
        renderHero();
    }

    for (let i = 0; i < MAX_PREV; i++) {
        if (prevData.value[i] && String(prevData.value[i].number).replace(/[^\d]/g, '') === num) {
            prevData.value[i] = null;
        }
    }

    /* فشرده‌سازی: پر کردن شکاف‌ها */
    const compacted = prevData.value.filter(Boolean);
    for (let k = 0; k < MAX_PREV; k++) {
        prevData.value[k] = k < compacted.length ? compacted[k] : null;
    }
}

/* ── Slideshow ──────────────────────────────────────────────────────── */
async function loadActiveSlides() {
    try {
        const res = await api.get('/calls/slides/active');

        if (!res?.success || !Array.isArray(res.slides) || res.slides.length === 0) {
            slidesActive.value = false;

            return;
        }

        slides.value = res.slides;
        slidesActive.value = true;
        currentSlideIndex.value = 0;
        startSlideshow();
        hideChrome();
    } catch {
        /* ignore — همان .catch(function () {}) پایتون */
    }
}

function nextSlide() {
    if (slides.value.length === 0) return;
    currentSlideIndex.value = (currentSlideIndex.value + 1) % slides.value.length;
}

function startSlideshow() {
    stopSlideshow();
    slideTimer = setInterval(nextSlide, SLIDE_INTERVAL);
}

function stopSlideshow() {
    clearInterval(slideTimer);
    slideTimer = null;
}

function hideChrome() {
    for (const el of [topbarEl.value, footerEl.value, statusCardEl.value, contentEl.value]) {
        if (el) el.style.opacity = '0';
    }
}

function showChrome() {
    for (const el of [topbarEl.value, footerEl.value, statusCardEl.value, contentEl.value]) {
        if (el) el.style.opacity = '';
    }
}

function pauseSlideshow() {
    slidesPaused.value = true;
    stopSlideshow();
}

function resumeSlideshow() {
    slidesPaused.value = false;
    if (slides.value.length > 0) {
        startSlideshow();
        /* بعد از تمام شدن نمایش فراخوان، کروم دوباره مخفی می‌شود */
        setTimeout(hideChrome, 1500);
    }
}

function onCallInterrupt() {
    showChrome();
    pauseSlideshow();
    clearTimeout(callPauseTimer);
    callPauseTimer = setTimeout(resumeSlideshow, CALL_PAUSE_DURATION);
}

/* ── Queue (نوبت‌دهی) ────────────────────────────────────────────────── */
async function loadQueueTickets() {
    try {
        const res = await api.get('/queue/list?status=waiting');
        queueTickets.value = res?.tickets || [];
        queueCount.value = queueTickets.value.length;
    } catch {
        /* ignore */
    }
}

/* ── Display queue از سرور (حالت اولیه‌ی صفحه) ───────────────────────── */
async function loadDisplayQueue() {
    try {
        const res = await api.get('/calls/display-queue');
        const queue = res?.queue || [];
        if (queue.length === 0) return;

        /* امضای صف را نگه می‌داریم تا همگام‌سازیِ fallback همان فراخوان را
           تازه (و بنابراین بی‌صدا/با انیمیشن) نمایش ندهد. */
        lastQueueSignature = queueSignature(queue);
        applyQueue(queue);
    } catch {
        /* ignore */
    }
}

function queueSignature(queue) {
    return queue
        .map((entry) => String(entry.reception_number ?? entry.number ?? ''))
        .join(',');
}

function applyQueue(queue) {
    /* ترتیب همان position است: ۰ = hero، ۱..۴ = فراخوان‌های قبلی */
    heroData.value = queue[0] || null;
    for (let i = 1; i <= MAX_PREV; i++) {
        prevData.value[i - 1] = queue[i] || null;
    }
    renderHero();
}

/* ── WebSocket ──────────────────────────────────────────────────────── */
function connect() {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = proto + '//' + location.host + '/api/ws/call-display';

    /* `connecting` فقط وقتی که هنوز هیچ منبع داده‌ای سالم نیست؛ اگر fallback
       سالم است، بج همان «متصل» می‌ماند تا صفحه در حالت reconnect قرمز چشمک نزند. */
    if (!fallbackHealthy) setStatus('connecting');

    /* Watchdog: سوکتی که باز نمی‌شود (Laravel هنوز /api/ws ندارد) نباید صفحه را
       بی‌خبر بگذارد؛ پس پس از چند ثانیه همگام‌سازی از API آغاز می‌شود. */
    clearTimeout(watchdogTimer);
    watchdogTimer = setTimeout(() => {
        if (!wsOpen) startFallback();
    }, CONNECT_WATCHDOG);

    try {
        ws = new WebSocket(url);
    } catch {
        wsOpen = false;
        startFallback();
        scheduleReconnect();

        return;
    }

    ws.onopen = () => {
        reconnectDelay = 1000;
        wsOpen = true;
        setStatus('connected');
        clearTimeout(watchdogTimer);
        stopFallback();

        try {
            ws.send(JSON.stringify({ tag: isPreview() ? 'preview' : 'display' }));
        } catch {
            /* ignore */
        }

        if (autoResumedRemote.value) {
            try {
                ws.send(JSON.stringify({ type: 'audio_activated' }));
            } catch {
                /* ignore */
            }
        }

        pingInterval = setInterval(() => {
            if (ws && ws.readyState === WebSocket.OPEN) {
                try {
                    ws.send('ping');
                } catch {
                    /* ignore */
                }
            }
        }, 25000);
    };

    ws.onmessage = (evt) => {
        try {
            const msg = JSON.parse(evt.data);
            if (msg.type === 'pong') return;
            if (msg.type === 'reception_call' && msg.data) displayCall(msg.data);
            if (msg.type === 'remove_call' && msg.data) removeCall(msg.data.number);
            if (msg.type === 'reset_display') resetDisplay();
            if (msg.type === 'refresh_display') {
                handleRemoteRefresh();

                return;
            }
            /* تلویزیون صدا را فعال کرد → overlay در پیش‌نمایش بسته شود */
            if (msg.type === 'audio_activated') {
                overlayHidden.value = true;
                audioReady = true;
            }
        } catch {
            /* ignore */
        }
    };

    ws.onclose = () => {
        clearInterval(pingInterval);
        wsOpen = false;
        syncConnState();
        startFallback();
        scheduleReconnect();
    };

    ws.onerror = () => {
        /* ignore — onclose بلافاصله reconnect را زمان‌بندی می‌کند */
    };
}

function scheduleReconnect() {
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => {
        reconnectDelay = Math.min(reconnectDelay * 1.5, 30000);
        connect();
    }, reconnectDelay);
}

/* ── همگام‌سازی زمانی که سوکت در دسترس نیست (نگهدارنده‌ی صفحه در لاراول) ──
   هم از onclose و هم از watchdog صدا زده می‌شود؛ وقتی سوکت باز شود
   stopFallback() همان interval را خاموش می‌کند تا صفحه فقط رویداد زنده بخواند. */
function startFallback() {
    if (fallbackStarted) return;
    fallbackStarted = true;
    syncDisplayQueue();
    fallbackInterval = setInterval(syncDisplayQueue, 5000);
}

function stopFallback() {
    fallbackStarted = false;
    fallbackHealthy = false;
    clearInterval(fallbackInterval);
    fallbackInterval = null;
}

async function syncDisplayQueue() {
    try {
        const res = await api.get('/calls/display-queue');
        /* سوکت در این فاصله وصل شده → این پاسخ دیگر حرفی برای گفتن ندارد */
        if (!fallbackStarted) return;

        const queue = res?.queue || [];
        const signature = queueSignature(queue);

        fallbackHealthy = true;
        syncConnState();

        if (signature === lastQueueSignature) return;

        const newest = queue[0] ? String(queue[0].reception_number ?? queue[0].number ?? '') : '';
        const wasShowing = heroData.value
            ? String(heroData.value.number ?? heroData.value.reception_number ?? '')
            : '';
        const isNewCall = newest !== '' && newest !== wasShowing;

        lastQueueSignature = signature;
        applyQueue(queue);

        /* فراخوان تازه‌ای که روی سیم ندیده‌ایم: مثل مسیر سوکت، صدا و وقفه‌ی
           اسلایدشو هم باید داشته باشد. */
        if (isNewCall) {
            onCallInterrupt();
            if (audioReady && queue[0]) queueAudio(queue[0].reception_number ?? queue[0].number);
        }
    } catch {
        fallbackHealthy = false;
        syncConnState();
    }
}

/* ── Fullscreen (دابل‌کلیک — همان پایتون) ────────────────────────────── */
function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else {
        document.exitFullscreen().catch(() => {});
    }
}

/* ── bfcache: برگشت از تاریخچه باید سوکت را تازه کند ────────────────── */
/* هندلرهای سوکتِ بازنشسته جدا می‌شوند: در غیر این صورت `onclose` قدیمی پس از
   باز شدن سوکت جدید، پرچم اتصال را پایین می‌آورد و ping سوکت تازه را می‌کشد. */
function teardownSocket() {
    if (!ws) return;
    const dead = ws;
    ws = null;
    wsOpen = false;
    dead.onopen = null;
    dead.onmessage = null;
    dead.onerror = null;
    dead.onclose = null;
    try {
        dead.close();
    } catch {
        /* ignore */
    }
}

function onPageShow(event) {
    if (!event.persisted) return;
    teardownSocket();
    connect();
}

/* ── سند: کلاس بدنه، عنوان، فاوآیکون و خنثی‌سازی تم سراسری ────────────── */
function applyPythonDocument() {
    previousTitle = document.title;
    document.title = PYTHON_TITLE;

    previousFavicon = captureFavicon();
    applyFavicon('/favicon.ico', 'image/x-icon');

    previousDocumentState = {
        htmlDarkMode: document.documentElement.classList.contains('dark-mode'),
        htmlDarkTheme: document.documentElement.classList.contains('dark-theme'),
        htmlTheme: document.documentElement.getAttribute('data-theme'),
        htmlColorScheme: document.documentElement.style.colorScheme,
        bodyDarkMode: document.body.classList.contains('dark-mode'),
        bodyDarkTheme: document.body.classList.contains('dark-theme'),
    };

    document.body.classList.add('call-display-page');

    /* سند پایتون هیچ تم تاریکی ندارد؛ کلاس‌های سراسری را بردار تا
       `body.dark-mode { background: var(--dk-bg) }` برنده‌ی آبشار نشود. */
    document.documentElement.classList.remove('dark-mode', 'dark-theme');
    document.body.classList.remove('dark-mode', 'dark-theme');
    document.documentElement.setAttribute('data-theme', 'light');
    document.documentElement.style.colorScheme = 'light';
}

function captureFavicon() {
    const icon = document.querySelector('link[rel~="icon"]');
    if (!icon) return null;

    return { href: icon.getAttribute('href'), type: icon.getAttribute('type') };
}

function applyFavicon(href, type) {
    let icon = document.querySelector('link[rel~="icon"]');
    if (!icon) {
        icon = document.createElement('link');
        icon.setAttribute('rel', 'icon');
        document.head.appendChild(icon);
    }
    icon.setAttribute('href', href);
    icon.setAttribute('type', type);
}

function restorePythonDocument() {
    document.title = previousTitle;
    document.body.classList.remove('call-display-page');

    if (previousDocumentState) {
        const state = previousDocumentState;
        document.documentElement.classList.toggle('dark-mode', state.htmlDarkMode);
        document.documentElement.classList.toggle('dark-theme', state.htmlDarkTheme);
        document.body.classList.toggle('dark-mode', state.bodyDarkMode);
        document.body.classList.toggle('dark-theme', state.bodyDarkTheme);

        if (state.htmlTheme === null) document.documentElement.removeAttribute('data-theme');
        else document.documentElement.setAttribute('data-theme', state.htmlTheme);

        document.documentElement.style.colorScheme = state.htmlColorScheme;
        previousDocumentState = null;
    }

    if (previousFavicon) {
        const icon = document.querySelector('link[rel~="icon"]');
        if (icon) {
            icon.setAttribute('href', previousFavicon.href);
            if (previousFavicon.type) icon.setAttribute('type', previousFavicon.type);
            else icon.removeAttribute('type');
        }
        previousFavicon = null;
    }
}

/* ── Init (همان توالی init() پایتون) ────────────────────────────────── */
onMounted(() => {
    applyPythonDocument();

    let autoResume = false;
    try {
        autoResume = sessionStorage.getItem('cd-auto-resume') === '1';
        sessionStorage.removeItem('cd-auto-resume');
    } catch {
        /* ignore */
    }

    const preview = isPreview();

    if (autoResume && !preview) {
        /* رفرش از راه دور: بدون کلیک روی تلویزیون صدا خودکار فعال شود
           (در کیوسکِ بدون محدودیت autoplay صدا باز می‌شود؛ اگر مسدود بود،
           خودِ initAudio overlay را برمی‌گرداند) */
        autoResumedRemote.value = true;
        overlayHidden.value = true;
        initAudio();
    } else {
        /* هم تلویزیون و هم پیش‌نمایشِ پنل: overlay نمایان می‌ماند — تلویزیون با
           کلیک روی «فعال‌سازی صدا» قفل autoplay را باز می‌کند و پیش‌نمایش همان
           کلیک را از فریم audio_activated روی سیم می‌گیرد. */
        overlayHidden.value = false;
    }

    loadDisplayQueue();
    loadQueueTickets();
    loadActiveSlides();
    connect();
    ticketsInterval = setInterval(loadQueueTickets, 5000);

    document.addEventListener('dblclick', toggleFullscreen);
    window.addEventListener('pageshow', onPageShow);
});

onUnmounted(() => {
    clearTimeout(reconnectTimer);
    clearTimeout(watchdogTimer);
    clearTimeout(callPauseTimer);
    clearTimeout(messageClearTimer);
    clearInterval(pingInterval);
    clearInterval(ticketsInterval);
    clearInterval(slideTimer);
    stopFallback();

    document.removeEventListener('dblclick', toggleFullscreen);
    window.removeEventListener('pageshow', onPageShow);

    teardownSocket();

    restorePythonDocument();
});
</script>

<template>
    <div class="call-display-page">
        <!-- Slideshow background -->
        <div
            id="cdSlideshow"
            class="cd-slideshow"
            :class="{ 'is-active': slidesActive, 'is-paused': slidesPaused }"
            :style="slideshowStyle"
        >
            <img
                v-for="(slide, index) in slides"
                :key="slide.id ?? slide.url"
                class="cd-slideshow-img"
                :class="{ 'is-visible': index === currentSlideIndex }"
                :src="slide.url"
                alt=""
                loading="lazy"
            >
        </div>

        <!-- Animated background particles -->
        <div class="cd-bg-particles">
            <div class="cd-particle cd-particle--1"></div>
            <div class="cd-particle cd-particle--2"></div>
            <div class="cd-particle cd-particle--3"></div>
            <div class="cd-particle cd-particle--4"></div>
            <div class="cd-particle cd-particle--5"></div>
        </div>

        <!-- Audio activation overlay -->
        <div id="activateOverlay" class="cd-overlay" :hidden="overlayHidden">
            <div class="cd-overlay-card">
                <div class="cd-overlay-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                        <path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                        <path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path>
                    </svg>
                </div>
                <h2 class="cd-overlay-title">فعال‌سازی صدای فراخوان</h2>
                <p class="cd-overlay-desc">برای شنیدن اعلان‌های صوتی، روی دکمه زیر کلیک کنید</p>
                <button id="activateBtn" class="cd-overlay-btn" type="button" @click="initAudio">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;margin-left:8px">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                        <path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                    </svg>
                    فعال‌سازی صدا
                </button>
            </div>
        </div>

        <!-- Top bar: Logo + Title -->
        <header ref="topbarEl" class="cd-topbar">
            <div class="cd-topbar-right">
                <div id="labLogo" class="cd-lab-logo">
                    <img id="labLogoImg" class="cd-lab-logo-img" :src="logoUrl" alt="لوگوی آزمایشگاه">
                    <span id="labLogoPlaceholder" class="cd-lab-logo-placeholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px">
                            <rect x="3" y="3" width="18" height="18" rx="3"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>
                        </svg>
                        لوگوی آزمایشگاه
                    </span>
                </div>
                <div class="cd-topbar-text">
                    <h1 class="cd-title">آزمایشگاه تشخیص طبی دکتر امینی</h1>
                    <div class="cd-subtitle">همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز</div>
                </div>
            </div>
        </header>

        <!-- Main content area -->
        <main ref="contentEl" class="cd-content">
            <!-- Hero: newest call -->
            <div id="cdHero" class="cd-hero">
                <div id="heroInner" class="cd-hero-inner">
                    <div id="heroWaiting" class="cd-hero-waiting" :hidden="heroWaitingHidden">
                        <div class="cd-hero-waiting-ring">
                            <svg viewBox="0 0 120 120" class="cd-hero-waiting-svg">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(56,189,248,0.08)" stroke-width="4" />
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(56,189,248,0.3)" stroke-width="4" stroke-dasharray="327" stroke-dashoffset="80" stroke-linecap="round" class="cd-hero-waiting-arc" />
                            </svg>
                            <div class="cd-hero-waiting-icon">⏳</div>
                        </div>
                        <div class="cd-hero-waiting-text">در انتظار فراخوان</div>
                    </div>
                    <div id="heroCall" ref="heroCallEl" class="cd-hero-call" v-show="heroCallVisible">
                        <div class="cd-hero-glow"></div>
                        <div id="heroNumber" class="cd-hero-number">{{ heroNumber }}</div>
                        <div id="heroDept" class="cd-hero-dept">{{ heroDept }}</div>
                        <div id="heroMessage" class="cd-hero-message">{{ heroMessage }}</div>
                    </div>
                </div>
            </div>

            <!-- Queue tickets row -->
            <div id="cdQueueTickets" class="cd-queue-tickets">
                <div class="cd-queue-header">
                    <span class="cd-queue-label">نوبت‌های صادر شده</span>
                    <span id="cdQueueCount" class="cd-queue-count">{{ queueCount }} نفر در صف</span>
                </div>
                <div id="cdQueueGrid" class="cd-queue-grid">
                    <div v-if="queueTickets.length === 0" class="cd-queue-empty">هنوز نوبتی صادر نشده است</div>
                    <div v-for="t in queueTickets" :key="t.id" class="cd-queue-item">
                        <span class="cd-queue-item-num">{{ t.persian_number || t.ticket_number || '' }}</span>
                        <span class="cd-queue-item-service">{{ t.service || '' }}</span>
                    </div>
                </div>
            </div>

            <!-- Previous calls row -->
            <div id="cdPrevious" class="cd-previous">
                <div class="cd-previous-label">فراخوان‌های قبلی</div>
                <div id="previousGrid" class="cd-previous-grid">
                    <div
                        v-for="i in MAX_PREV"
                        :id="'slot' + i"
                        :key="i"
                        class="cd-prev-slot"
                        :class="{ 'is-active': !!prevData[i - 1], 'is-empty': !prevData[i - 1] }"
                    >
                        <div class="cd-prev-num">{{ prevData[i - 1]?.persian_number || prevData[i - 1]?.number || '' }}</div>
                        <div class="cd-prev-dept">{{ prevData[i - 1]?.department || '' }}</div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Connection status card (bottom-left) -->
        <div id="statusCard" ref="statusCardEl" class="cd-status-card">
            <div id="displayStatus" class="cd-status-row cd-status-row--conn" :class="'cd-status-row--' + connState">
                <div id="connIconWrap" class="cd-status-icon-wrap" :class="'cd-status-icon-wrap--' + connState">
                    <svg class="cd-status-svg cd-status-svg--connecting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <svg class="cd-status-svg cd-status-svg--connected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                        <circle cx="12" cy="12" r="3" />
                        <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
                    </svg>
                    <svg class="cd-status-svg cd-status-svg--disconnected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="2" y1="2" x2="22" y2="22" />
                        <path d="M6.7 6.7 2 12s3 7 10 7a20.5 20.5 0 0 0 5.3-1.3" />
                        <path d="M17.2 17.2 22 12s-3-7-10-7a19.5 19.5 0 0 0-4 1.3" />
                    </svg>
                </div>
                <span id="statusText" class="cd-status-text">{{ statusLabel }}</span>
            </div>
        </div>

        <!-- Footer -->
        <footer ref="footerEl" class="cd-footer">
            <span class="cd-footer-brand">سامانه فراخوان هستما</span>
            <span class="cd-footer-sep">|</span>
            <span class="cd-footer-company">محصولی از شرکت هنر افزار ایرانیان</span>
            <span class="cd-footer-tm">&#x00AE;</span>
        </footer>

        <!-- Audio element -->
        <audio id="callAudio" ref="audioEl" preload="auto"></audio>
    </div>
</template>

<style>
/*
 * ── ادامه‌ی زنجیره‌ی چیدمان روی سند SPA ──────────────────────────────────
 * در پایتون فرزندان مستقیم `body` همان `.cd-*` ها هستند و `call-display.css`
 * روی `html, body` مقدار `height:100% / overflow:hidden / display:flex /
 * flex-direction:column` را می‌گذارد؛ `.cd-content { flex: 1 }` با همین
 * زنجیره ارتفاع می‌گیرد. در لاراول یک `<div id="app">` بین بدنه و صفحه وسط
 * است، پس همان زنجیره باید به `#app` و ریشه‌ی صفحه هم منتقل شود — وگرنه
 * middle/پایین صفحه (footer و وسط‌چین بودن hero) جابه‌جا می‌شود.
 *
 * نشانگر `.cd-slideshow` مثل `#app:has(.login-shell)` در login-style.css عمل
 * می‌کند: قاعده فقط روی همین صفحه اعمال می‌شود.
 */
#app:has(.cd-slideshow),
#app:has(.cd-slideshow) .call-display-page {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/*
 * ── خنثی‌سازی نشت تم سراسری روی این سند ────────────────────────────────
 * سند پایتون فقط vazir.css و call-display.css را لود می‌کند. باندل لاراول
 * همه‌ی استایل‌ها را سراسری می‌کند و dark-theme.css بعد از call-display.css
 * import شده، پس هر قاعده‌ای که روی `body.dark-mode` یا `[data-theme='dark']`
 * نشسته باشد می‌تواند پس‌زمینه/متن این نمایشگر را عوض کند. کلاس‌ها هنگام ورود
 * از سند برداشته می‌شوند (بالا) و این بلوک‌ها همان آبشار پایتون را ثابت
 * نگه می‌دارند؛ فقط همین صفحه (marker: `.cd-slideshow`) هدف است.
 */
html:has(.cd-slideshow),
body:has(.cd-slideshow) {
    background: var(--cd-bg-deep);
    color: var(--cd-text);
    background-image: none;
    color-scheme: light;
}

/*
 * کروم صفحه با `opacity` مخفی می‌شود (همان hideChrome پایتون)؛ transition
 * ۰٫۸ ثانیه‌ای در call-display.css تعریف شده و دست‌نخورده می‌ماند.
 */
</style>
