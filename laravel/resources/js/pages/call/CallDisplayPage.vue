<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '@/services/api';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

const { isDark, toggleTheme } = useTheme();

const logoUrl = '/images/lab-logo.png';

const MAX_PREV = 4;

const heroData = ref(null);
const prevData = ref(new Array(MAX_PREV).fill(null));
const queueTickets = ref([]);
const queueCount = ref(0);

const slides = ref([]);
const currentSlideIndex = ref(-1);
const slideTimer = ref(null);
const callPauseTimer = ref(null);

const connState = ref('connecting');
const audioReady = ref(false);
const audioQueue = ref([]);
const audioPlaying = ref(false);
const autoResumedRemote = ref(false);

const SLIDE_INTERVAL = 20000;
const CALL_PAUSE_DURATION = 30000;
const AUDIO_BASE = '/static/audio/sample_call/fa-IR-DilaraNeural/';

const heroNumber = computed(() => heroData.value?.persian_number || heroData.value?.number || '');
const heroDept = computed(() => heroData.value?.department || '');
const heroMessage = computed(() => heroData.value?.message || '');

function setStatus(state) {
    connState.value = state;
}

function getAudioUrl(number) {
    const num = parseInt(String(number).replace(/[^\d]/g, ''), 10);
    if (isNaN(num) || num < 1 || num > 2000) return null;
    let padded = String(num);
    while (padded.length < 4) padded = '0' + padded;
    return AUDIO_BASE + padded + '.mp3';
}

function queueAudio(number) {
    const url = getAudioUrl(number);
    if (!url) return;
    audioQueue.value.push({ url, number });
    processAudioQueue();
}

function processAudioQueue() {
    if (audioPlaying.value || audioQueue.value.length === 0 || !audioReady.value) return;
    audioPlaying.value = true;
    const item = audioQueue.value.shift();

    const audio = new Audio();
    audio.preload = 'auto';
    audio.src = item.url;

    audio.oncanplaythrough = () => {
        audio.play().catch(() => {
            audioPlaying.value = false;
            setTimeout(processAudioQueue, 500);
        });
    };
    audio.onended = () => {
        audioPlaying.value = false;
        setTimeout(processAudioQueue, 300);
    };
    audio.onerror = () => {
        audioPlaying.value = false;
        setTimeout(processAudioQueue, 500);
    };
    audio.load();
}

function displayCall(data) {
    if (!data || !data.number) return;

    for (let j = MAX_PREV - 1; j > 0; j--) {
        prevData.value[j] = prevData.value[j - 1];
    }
    if (heroData.value && heroData.value.number) {
        prevData.value[0] = heroData.value;
    }
    heroData.value = data;

    if (typeof onCallInterrupt === 'function') onCallInterrupt();

    if (data.number && audioReady.value) queueAudio(data.number);
}

function removeCall(number) {
    const num = String(number).replace(/[^\d]/g, '');
    if (heroData.value && String(heroData.value.number).replace(/[^\d]/g, '') === num) {
        heroData.value = null;
    }
    for (let i = 0; i < MAX_PREV; i++) {
        if (prevData.value[i] && String(prevData.value[i].number).replace(/[^\d]/g, '') === num) {
            prevData.value[i] = null;
        }
    }
    const compacted = prevData.value.filter(Boolean);
    for (let k = 0; k < MAX_PREV; k++) {
        prevData.value[k] = k < compacted.length ? compacted[k] : null;
    }
}

function resetDisplay() {
    heroData.value = null;
    prevData.value = new Array(MAX_PREV).fill(null);
    clearTimeout(callPauseTimer.value);
    resumeSlideshow();
}

function handleRemoteRefresh() {
    try { sessionStorage.setItem('cd-auto-resume', '1'); } catch { /* ignore */ }
    setTimeout(() => { window.location.reload(); }, 400);
}

function showSlide(index) {
    slides.value.forEach((img, i) => {
        if (i === index) img.classList.add('is-visible');
        else img.classList.remove('is-visible');
    });
}

function nextSlide() {
    if (slides.value.length === 0) return;
    currentSlideIndex.value = (currentSlideIndex.value + 1) % slides.value.length;
    showSlide(currentSlideIndex.value);
}

function startSlideshow() {
    stopSlideshow();
    slideTimer.value = setInterval(nextSlide, SLIDE_INTERVAL);
}

function stopSlideshow() {
    clearInterval(slideTimer.value);
    slideTimer.value = null;
}

function pauseSlideshow() {
    stopSlideshow();
}

function resumeSlideshow() {
    if (slides.value.length > 0) startSlideshow();
}

function onCallInterrupt() {
    pauseSlideshow();
    clearTimeout(callPauseTimer.value);
    callPauseTimer.value = setTimeout(resumeSlideshow, CALL_PAUSE_DURATION);
}

async function loadActiveSlides() {
    try {
        const res = await api.get('/calls/slides/active');
        const activeSlides = res.slides || [];
        if (activeSlides.length === 0) return;

        const container = document.getElementById('cdSlideshow');
        if (!container) return;
        container.innerHTML = '';
        slides.value = [];
        currentSlideIndex.value = -1;

        activeSlides.forEach((slide) => {
            const img = document.createElement('img');
            img.className = 'cd-slideshow-img';
            img.src = slide.url;
            img.alt = '';
            img.loading = 'lazy';
            container.appendChild(img);
            slides.value.push(img);
        });

        if (slides.value.length > 0) {
            currentSlideIndex.value = 0;
            showSlide(0);
            startSlideshow();
        }
    } catch {
    }
}

async function loadDisplayQueue() {
    try {
        const res = await api.get('/calls/display-queue');
        const q = res.queue || [];
        if (q.length === 0) return;
        heroData.value = q[0] || null;
        for (let i = 1; i <= MAX_PREV; i++) {
            prevData.value[i - 1] = q[i] || null;
        }
    } catch {
    }
}

async function loadQueueTickets() {
    try {
        const res = await api.get('/queue/list?status=waiting');
        const tickets = res.tickets || [];
        queueTickets.value = tickets;
        queueCount.value = tickets.length;
    } catch {
    }
}

function initAudio() {
    if (audioReady.value) return;
    audioReady.value = true;
    if (ws && ws.readyState === WebSocket.OPEN) {
        try { ws.send(JSON.stringify({ type: 'audio_activated' })); } catch { /* ignore */ }
    }
}

let ws = null;
let reconnectTimer = null;
let reconnectDelay = 1000;
let pingInterval = null;
let ticketsInterval = null;

function connect() {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = proto + '//' + location.host + '/api/ws/call-display';
    try {
        ws = new WebSocket(url);
    } catch {
        scheduleReconnect();
        return;
    }

    setStatus('connecting');

    ws.onopen = () => {
        reconnectDelay = 1000;
        setStatus('connected');
        const isPreview = (window.location !== window.parent.location);
        try {
            ws.send(JSON.stringify({ tag: isPreview ? 'preview' : 'display' }));
        } catch { /* ignore */ }
        if (autoResumedRemote.value) {
            try { ws.send(JSON.stringify({ type: 'audio_activated' })); } catch { /* ignore */ }
        }
        pingInterval = setInterval(() => {
            if (ws && ws.readyState === WebSocket.OPEN) {
                try { ws.send('ping'); } catch { /* ignore */ }
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
            if (msg.type === 'refresh_display') { handleRemoteRefresh(); return; }
            if (msg.type === 'audio_activated') {
                audioReady.value = true;
            }
        } catch { /* ignore */ }
    };

    ws.onclose = () => {
        clearInterval(pingInterval);
        setStatus('disconnected');
        scheduleReconnect();
    };

    ws.onerror = () => { /* ignore */ };
}

function scheduleReconnect() {
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => {
        reconnectDelay = Math.min(reconnectDelay * 1.5, 30000);
        connect();
    }, reconnectDelay);
}

function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else {
        document.exitFullscreen().catch(() => {});
    }
}

onMounted(async () => {
    const isPreview = (window.location !== window.parent.location);
    let autoResume = false;
    try {
        autoResume = sessionStorage.getItem('cd-auto-resume') === '1';
        sessionStorage.removeItem('cd-auto-resume');
    } catch { /* ignore */ }

    if (autoResume && !isPreview) {
        autoResumedRemote.value = true;
        initAudio();
    } else if (isPreview) {
    } else {
        initAudio();
    }

    await Promise.all([
        loadDisplayQueue(),
        loadQueueTickets(),
        loadActiveSlides(),
    ]);
    connect();
    ticketsInterval = setInterval(loadQueueTickets, 5000);

    document.addEventListener('dblclick', toggleFullscreen);
});

onUnmounted(() => {
    clearTimeout(reconnectTimer);
    clearInterval(pingInterval);
    clearInterval(ticketsInterval);
    clearInterval(slideTimer.value);
    clearTimeout(callPauseTimer.value);
    document.removeEventListener('dblclick', toggleFullscreen);
    if (ws) {
        try { ws.close(); } catch { /* ignore */ }
    }
});
</script>

<template>
    <div class="call-display-page">
        <div class="cd-slideshow" id="cdSlideshow"></div>

        <div class="cd-bg-particles">
            <div class="cd-particle cd-particle--1"></div>
            <div class="cd-particle cd-particle--2"></div>
            <div class="cd-particle cd-particle--3"></div>
            <div class="cd-particle cd-particle--4"></div>
            <div class="cd-particle cd-particle--5"></div>
        </div>

        <div class="cd-overlay" v-if="!audioReady && !autoResumedRemote" id="activateOverlay">
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
                <button class="cd-overlay-btn" @click="initAudio" id="activateBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;margin-left:8px">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                        <path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                    </svg>
                    فعال‌سازی صدا
                </button>
            </div>
        </div>

        <header class="cd-topbar">
            <div class="cd-topbar-right">
                <div class="cd-lab-logo">
                    <img :src="logoUrl" alt="لوگوی آزمایشگاه" class="cd-lab-logo-img">
                    <span class="cd-lab-logo-placeholder">
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

        <main class="cd-content">
            <div class="cd-hero" id="cdHero">
                <div class="cd-hero-inner" id="heroInner">
                    <div class="cd-hero-waiting" id="heroWaiting" v-if="!heroData">
                        <div class="cd-hero-waiting-ring">
                            <svg viewBox="0 0 120 120" class="cd-hero-waiting-svg">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(56,189,248,0.08)" stroke-width="4"/>
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(56,189,248,0.3)" stroke-width="4" stroke-dasharray="327" stroke-dashoffset="80" stroke-linecap="round" class="cd-hero-waiting-arc"/>
                            </svg>
                            <div class="cd-hero-waiting-icon">⏳</div>
                        </div>
                        <div class="cd-hero-waiting-text">در انتظار فراخوان</div>
                    </div>
                    <div class="cd-hero-call" id="heroCall" v-else>
                        <div class="cd-hero-glow"></div>
                        <div class="cd-hero-number" id="heroNumber">{{ heroNumber }}</div>
                        <div class="cd-hero-dept" id="heroDept">{{ heroDept }}</div>
                        <div class="cd-hero-message" id="heroMessage">{{ heroMessage }}</div>
                    </div>
                </div>
            </div>

            <div class="cd-queue-tickets" id="cdQueueTickets">
                <div class="cd-queue-header">
                    <span class="cd-queue-label">نوبت‌های صادر شده</span>
                    <span class="cd-queue-count" id="cdQueueCount">{{ toPersianDigits(queueCount) }} نفر در صف</span>
                </div>
                <div class="cd-queue-grid" id="cdQueueGrid">
                    <div class="cd-queue-empty" v-if="queueTickets.length === 0">هنوز نوبتی صادر نشده است</div>
                    <div v-for="t in queueTickets" :key="t.id" class="cd-queue-item">
                        <span class="cd-queue-item-num">{{ t.persian_number || t.ticket_number }}</span>
                        <span class="cd-queue-item-service">{{ t.service }}</span>
                    </div>
                </div>
            </div>

            <div class="cd-previous" id="cdPrevious">
                <div class="cd-previous-label">فراخوان‌های قبلی</div>
                <div class="cd-previous-grid" id="previousGrid">
                    <div v-for="i in MAX_PREV" :key="i" class="cd-prev-slot" :class="{ 'is-active': !!prevData[i - 1], 'is-empty': !prevData[i - 1] }">
                        <div class="cd-prev-num">{{ prevData[i - 1]?.persian_number || prevData[i - 1]?.number || '' }}</div>
                        <div class="cd-prev-dept">{{ prevData[i - 1]?.department || '' }}</div>
                    </div>
                </div>
            </div>
        </main>

        <div class="cd-status-card" id="statusCard">
            <div class="cd-status-row cd-status-row--conn" :class="'cd-status-row--' + connState" id="displayStatus">
                <div class="cd-status-icon-wrap" :class="'cd-status-icon-wrap--' + connState" id="connIconWrap">
                    <svg class="cd-status-svg cd-status-svg--connecting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg class="cd-status-svg cd-status-svg--connected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                        <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/>
                    </svg>
                    <svg class="cd-status-svg cd-status-svg--disconnected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="2" y1="2" x2="22" y2="22"/>
                        <path d="M6.7 6.7 2 12s3 7 10 7a20.5 20.5 0 0 0 5.3-1.3"/>
                        <path d="M17.2 17.2 22 12s-3-7-10-7a19.5 19.5 0 0 0-4 1.3"/>
                    </svg>
                </div>
                <span class="cd-status-text" id="statusText">
                    {{ connState === 'connected' ? 'متصل' : connState === 'disconnected' ? 'قطع' : 'در حال اتصال...' }}
                </span>
            </div>
        </div>

        <footer class="cd-footer">
            <span class="cd-footer-brand">سامانه فراخوان هستما</span>
            <span class="cd-footer-sep">|</span>
            <span class="cd-footer-company">محصولی از شرکت هنر افزار ایرانیان</span>
            <span class="cd-footer-tm">&#x00AE;</span>
        </footer>

        <audio id="callAudio" preload="auto"></audio>
    </div>
</template>
