<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import api from '@/services/api';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits, toLatinDigits } from '@/utils/numbers';

const { isDark, toggleTheme } = useTheme();

const logoUrl = '/images/lab-logo.png';

const QUEUE_MAX = 5;

const activeTab = ref('tickets');

const numberInput = ref('');
const department = ref('نمونه‌گیری');
const loadingCall = ref(false);
const loadingRepeat = ref(false);
const loadingTestDisplay = ref(false);
const loadingTestVoice = ref(false);
const loadingReset = ref(false);
const loadingRefresh = ref(false);

const displayQueue = ref([]);
const queueLoading = ref(false);

const waitingItems = ref([]);
const waitingInput = ref('');
const waitingDept = ref('نمونه‌گیری');
const waitingLoading = ref(false);

const tickets = ref([]);
const ticketStatus = ref('waiting');
const ticketDept = ref('پذیرش');
const ticketsLoading = ref(false);

const slides = ref([]);
const slideUploading = ref(false);
const slideFileInput = ref(null);

const history = ref([]);
const historyLoading = ref(false);

const displayStatus = ref({ connected_displays: 0, real_displays: 0, preview_displays: 0 });
const audioStatus = ref({ exists: false, total_files: 0, total_expected: 2000 });

const connState = ref('connecting');
const previewOverlayVisible = ref(true);

const toasts = ref([]);
let toastSeq = 0;

function showToast(message, type = 'success') {
    const id = ++toastSeq;
    toasts.value.push({ id, message, type });
    setTimeout(() => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }, 4000);
}

function switchTab(tabId) {
    activeTab.value = tabId;
}

function fmtTime(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    } catch {
        return '';
    }
}

async function makeCall() {
    const num = numberInput.value.trim();
    if (!num) {
        showToast('لطفاً شماره پذیرش را وارد کنید.', 'error');
        return;
    }
    loadingCall.value = true;
    try {
        const res = await api.post('/calls', { reception_number: num, department: department.value });
        addToQueue(res.data);
        addHistoryItem(res.data);
        showToast(res.message || 'فراخوان ارسال شد.');
        numberInput.value = '';
    } catch (err) {
        showToast(err.message || 'فراخوان انجام نشد.', 'error');
    } finally {
        loadingCall.value = false;
    }
}

async function repeatCall() {
    loadingRepeat.value = true;
    try {
        const res = await api.post('/calls/repeat');
        addToQueue(res.data);
        addHistoryItem(res.data);
        showToast(res.message || 'تکرار ارسال شد.');
    } catch (err) {
        showToast(err.message || 'تکرار انجام نشد.', 'error');
    } finally {
        loadingRepeat.value = false;
    }
}

async function testDisplay() {
    loadingTestDisplay.value = true;
    try {
        const res = await api.post('/calls/test-display');
        showToast(res.message || 'تست نمایشگر ارسال شد.');
    } catch (err) {
        showToast(err.message || 'تست نمایشگر انجام نشد.', 'error');
    } finally {
        loadingTestDisplay.value = false;
    }
}

async function testVoice() {
    loadingTestVoice.value = true;
    try {
        const res = await api.post('/calls/test-voice');
        showToast(res.message || 'تست صدا ارسال شد.');
    } catch (err) {
        showToast(err.message || 'تست صدا انجام نشد.', 'error');
    } finally {
        loadingTestVoice.value = false;
    }
}

function confirmReset() {
    showConfirmModal(
        'پاک کردن همه',
        'آیا از پاک کردن تمام شماره‌ها از نمایشگر اطمینان دارید؟',
        'بله، پاک کن',
        true,
        resetDisplay
    );
}

async function resetDisplay() {
    loadingReset.value = true;
    try {
        const res = await api.post('/calls/reset-display');
        displayQueue.value = [];
        showToast(res.message || 'نمایشگر پاک شد.');
    } catch (err) {
        showToast(err.message || 'پاک کردن انجام نشد.', 'error');
    } finally {
        loadingReset.value = false;
    }
}

async function refreshDisplays() {
    loadingRefresh.value = true;
    try {
        const res = await api.post('/calls/refresh-display');
        const realCount = res.real_displays || 0;
        if (realCount > 0) {
            showToast('دستور رفرش به ' + toPersianDigits(realCount) + ' نمایشگر ارسال شد.');
        } else {
            showToast('هیچ نمایشگر فعالی متصل نیست.', 'error');
        }
    } catch (err) {
        showToast(err.message || 'ارسال دستور رفرش انجام نشد.', 'error');
    } finally {
        loadingRefresh.value = false;
    }
}

async function removeFromDisplay(item, index) {
    const num = String(item.reception_number || item.number || '').replace(/[^\d]/g, '');
    try {
        const res = await api.post('/calls/remove', { number: num });
        displayQueue.value.splice(index, 1);
        while (displayQueue.value.length < QUEUE_MAX) displayQueue.value.push(null);
        showToast(res.message || 'از نمایشگر حذف شد.');
    } catch (err) {
        showToast(err.message || 'حذف انجام نشد.', 'error');
    }
}

async function loadDisplayQueue() {
    queueLoading.value = true;
    try {
        const res = await api.get('/calls/display-queue');
        const q = res.queue || [];
        displayQueue.value = [];
        for (let i = 0; i < QUEUE_MAX; i++) {
            displayQueue.value.push(q[i] || null);
        }
    } catch {
    } finally {
        queueLoading.value = false;
    }
}

function addToQueue(data) {
    if (!data) return;
    const rawNum = data.reception_number || data.number;
    if (!rawNum) return;
    const num = String(rawNum).replace(/[^\d]/g, '');
    for (let i = 0; i < QUEUE_MAX; i++) {
        const existing = displayQueue.value[i];
        if (existing) {
            const existingNum = String(existing.reception_number || existing.number || '').replace(/[^\d]/g, '');
            if (existingNum === num) return;
        }
    }
    displayQueue.value.pop();
    displayQueue.value.unshift(data);
}

function addHistoryItem(data) {
    if (!data) return;
    history.value.unshift(data);
    if (history.value.length > 30) history.value.pop();
}

async function loadWaitingQueue() {
    waitingLoading.value = true;
    try {
        const res = await api.get('/calls/waiting-queue');
        waitingItems.value = res.items || [];
    } catch {
    } finally {
        waitingLoading.value = false;
    }
}

async function addToWaitingQueue() {
    const num = waitingInput.value.trim();
    if (!num) {
        showToast('لطفاً شماره پذیرش را وارد کنید.', 'error');
        return;
    }
    waitingLoading.value = true;
    try {
        const res = await api.post('/calls/waiting-queue', { number: num, department: waitingDept.value });
        showToast(res.message || 'به صف اضافه شد.');
        waitingInput.value = '';
        await loadWaitingQueue();
    } catch (err) {
        showToast(err.message || 'اضافه به صف انجام نشد.', 'error');
    } finally {
        waitingLoading.value = false;
    }
}

async function removeFromWaitingQueue(id) {
    try {
        const res = await api.delete('/calls/waiting-queue/' + id);
        waitingItems.value = waitingItems.value.filter((d) => d.id !== id);
        showToast(res.message || 'از صف حذف شد.');
    } catch (err) {
        showToast(err.message || 'حذف انجام نشد.', 'error');
    }
}

async function callFromWaitingQueue(id) {
    try {
        const res = await api.post('/calls/waiting-queue/' + id + '/call');
        waitingItems.value = waitingItems.value.filter((d) => d.id !== id);
        showToast(res.message || 'فراخوان ارسال شد.');
    } catch (err) {
        showToast(err.message || 'فراخوان انجام نشد.', 'error');
    }
}

async function loadTickets(status) {
    ticketStatus.value = status || ticketStatus.value;
    ticketsLoading.value = true;
    try {
        const res = await api.get('/queue/list?status=' + ticketStatus.value);
        tickets.value = res.tickets || [];
    } catch {
    } finally {
        ticketsLoading.value = false;
    }
}

async function callNextTicket() {
    try {
        const res = await api.post('/queue/call-next?department=' + encodeURIComponent(ticketDept.value));
        showToast(res.message || 'نوبت بعدی فراخوان شد.');
        await loadTickets();
    } catch (err) {
        showToast(err.message || 'خطا در اتصال به سرور', 'error');
    }
}

async function callTicket(id) {
    try {
        const res = await api.post('/queue/call/' + id + '?department=' + encodeURIComponent(ticketDept.value));
        showToast(res.message || 'نوبت فراخوان شد.');
        await loadTickets();
    } catch (err) {
        showToast(err.message || 'خطا در اتصال به سرور', 'error');
    }
}

function confirmDeleteTicket(id, number) {
    showConfirmModal(
        'حذف نوبت',
        'آیا از حذف نوبت ' + number + ' از صف مطمئن هستید؟',
        'حذف',
        true,
        () => deleteTicket(id)
    );
}

async function deleteTicket(id) {
    try {
        const res = await api.delete('/queue/' + id);
        showToast(res.message || 'نوبت حذف شد.');
        await loadTickets();
    } catch (err) {
        showToast(err.message || 'خطا در حذف نوبت.', 'error');
    }
}

function confirmDeleteAllTickets() {
    showConfirmModal(
        'حذف همه نوبت‌ها',
        'آیا از حذف همه نوبت‌های در انتظار مطمئن هستید؟',
        'حذف همه',
        true,
        deleteAllTickets
    );
}

async function deleteAllTickets() {
    try {
        const res = await api.delete('/queue');
        showToast(res.message || 'نوبت‌ها حذف شدند.');
        await loadTickets();
    } catch (err) {
        showToast(err.message || 'خطا در حذف نوبت‌ها.', 'error');
    }
}

async function loadSlides() {
    try {
        const res = await api.get('/calls/slides');
        slides.value = res.slides || [];
    } catch {
    }
}

async function uploadSlide() {
    const files = slideFileInput.value ? slideFileInput.value.files : null;
    if (!files || files.length === 0) {
        showToast('لطفاً فایل اطلاعاتی انتخاب کنید.', 'error');
        return;
    }
    const file = files[0];
    const formData = new FormData();
    formData.append('file', file);
    slideUploading.value = true;
    try {
        await api.post('/calls/slides/upload', formData);
        showToast('اسلاید آپلود شد.');
        if (slideFileInput.value) slideFileInput.value.value = '';
        await loadSlides();
    } catch (err) {
        showToast(err.message || 'آپلود انجام نشد.', 'error');
    } finally {
        slideUploading.value = false;
    }
}

async function toggleSlide(id) {
    try {
        const res = await api.put('/calls/slides/' + id + '/toggle');
        showToast(res.message || 'تغییر صورت افتاد.');
        await loadSlides();
    } catch (err) {
        showToast(err.message || 'خطا در تغییر.', 'error');
    }
}

function confirmDeleteSlide(id, name) {
    showConfirmModal(
        'حذف اسلاید',
        'آیا از حذف اسلاید «' + name + '» اطمینان دارید؟',
        'حذف',
        true,
        () => deleteSlide(id)
    );
}

async function deleteSlide(id) {
    try {
        const res = await api.delete('/calls/slides/' + id);
        showToast(res.message || 'اسلاید حذف شد.');
        await loadSlides();
    } catch (err) {
        showToast(err.message || 'حذف انجام نشد.', 'error');
    }
}

async function loadHistory() {
    historyLoading.value = true;
    try {
        const res = await api.get('/calls/recent?limit=30');
        history.value = res.calls || [];
    } catch {
    } finally {
        historyLoading.value = false;
    }
}

function confirmClearHistory() {
    showConfirmModal(
        'پاک کردن تاریخچه',
        'آیا از پاک کردن کامل تاریخچه فراخوان‌ها مطمئن هستید؟',
        'پاک کردن',
        true,
        clearHistory
    );
}

async function clearHistory() {
    try {
        const res = await api.delete('/calls/recent');
        history.value = [];
        showToast(res.message || 'تاریخچه فراخوان‌ها پاک شد.');
    } catch (err) {
        showToast(err.message || 'خطا در پاک کردن تاریخچه.', 'error');
    }
}

async function loadStatus() {
    try {
        const res = await api.get('/calls/status');
        displayStatus.value = res;
        const realCount = res.real_displays || 0;
        connState.value = realCount > 0 ? 'connected' : 'disconnected';
        previewOverlayVisible.value = realCount === 0;
    } catch {
        connState.value = 'disconnected';
    }
}

async function loadAudioStatus() {
    try {
        const res = await api.get('/calls/audio-status');
        audioStatus.value = res;
    } catch {
    }
}

let ws = null;
let reconnectTimer = null;
let reconnectDelay = 1000;
let pingInterval = null;
let statusInterval = null;
let ticketsInterval = null;

function connectWs() {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = proto + '//' + location.host + '/api/ws/call-display';
    try {
        ws = new WebSocket(url);
    } catch {
        scheduleReconnect();
        return;
    }

    connState.value = 'connecting';

    ws.onopen = () => {
        reconnectDelay = 1000;
        try {
            ws.send(JSON.stringify({ tag: 'preview' }));
        } catch { /* ignore */ }
        pingInterval = setInterval(() => {
            if (ws && ws.readyState === WebSocket.OPEN) {
                try { ws.send('ping'); } catch { /* ignore */ }
            }
        }, 30000);
    };

    ws.onmessage = (evt) => {
        try {
            const msg = JSON.parse(evt.data);
            if (msg.type === 'pong') return;
            if (msg.type === 'reception_call' && msg.data) {
                addToQueue(msg.data);
                if (!msg.data.is_test) addHistoryItem(msg.data);
            }
            if (msg.type === 'reset_display') {
                displayQueue.value = [];
            }
        } catch { /* ignore */ }
    };

    ws.onclose = () => {
        clearInterval(pingInterval);
        connState.value = 'connecting';
        scheduleReconnect();
    };

    ws.onerror = () => { /* ignore */ };
}

function scheduleReconnect() {
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => {
        reconnectDelay = Math.min(reconnectDelay * 1.5, 30000);
        connectWs();
    }, reconnectDelay);
}

const confirmModal = ref({ open: false, title: '', desc: '', confirmText: '', danger: false, onConfirm: null });

function showConfirmModal(title, desc, confirmText, danger, onConfirm) {
    confirmModal.value = { open: true, title, desc, confirmText, danger, onConfirm };
}

function closeConfirmModal() {
    confirmModal.value.open = false;
}

function handleConfirm() {
    const fn = confirmModal.value.onConfirm;
    closeConfirmModal();
    if (fn) fn();
}

const queueCount = computed(() => displayQueue.value.filter(Boolean).length);
const waitingCount = computed(() => waitingItems.value.length);
const ticketCount = computed(() => tickets.value.length);
const slideCount = computed(() => slides.value.length);
const historyCount = computed(() => history.value.length);

onMounted(async () => {
    await Promise.all([
        loadDisplayQueue(),
        loadWaitingQueue(),
        loadTickets('waiting'),
        loadSlides(),
        loadHistory(),
        loadStatus(),
        loadAudioStatus(),
    ]);
    connectWs();
    statusInterval = setInterval(loadStatus, 8000);
    ticketsInterval = setInterval(() => loadTickets(), 5000);
});

onUnmounted(() => {
    clearTimeout(reconnectTimer);
    clearInterval(pingInterval);
    clearInterval(statusInterval);
    clearInterval(ticketsInterval);
    if (ws) {
        try { ws.close(); } catch { /* ignore */ }
    }
});
</script>

<template>
    <div class="cs-page">
        <header class="cs-header">
            <div class="cs-header-inner">
                <div class="cs-header-brand">
                    <div class="cs-header-logo-wrap">
                        <img :src="logoUrl" alt="لوگو" class="cs-header-logo">
                        <span class="cs-header-logo-ph">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:28px;height:28px">
                                <rect x="3" y="3" width="18" height="18" rx="3"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>
                            </svg>
                        </span>
                    </div>
                    <div class="cs-header-text">
                        <span class="cs-header-title">سامانه فراخوان نمونه‌گیری</span>
                        <span class="cs-header-sub">آزمایشگاه دکتر امینی</span>
                    </div>
                </div>
                <button type="button" class="cs-theme-toggle" @click="toggleTheme" title="تغییر حالت نمایش">
                    <svg class="cs-theme-icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                    <svg class="cs-theme-icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                </button>
            </div>
        </header>

        <main class="cs-main">
            <div class="cs-grid">
                <div class="cs-col cs-col--left">
                    <div class="cs-card cs-card--call">
                        <div class="cs-card-header">
                            <div class="cs-card-icon cs-card-icon--call">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                            </div>
                            <h2 class="cs-card-title">فراخوان شماره</h2>
                        </div>
                        <div class="cs-call-form">
                            <div class="cs-call-input-group">
                                <input v-model="numberInput" type="text" class="cs-call-input" placeholder="شماره پذیرش" autocomplete="off" inputmode="numeric" @keydown.enter.prevent="makeCall">
                                <select v-model="department" class="cs-call-select">
                                    <option value="نمونه‌گیری">نمونه‌گیری</option>
                                    <option value="خون‌گیری">خون‌گیری</option>
                                    <option value="رادیولوژی">رادیولوژی</option>
                                    <option value="سونوگرافی">سونوگرافی</option>
                                    <option value="آزمایشگاه">آزمایشگاه</option>
                                </select>
                            </div>
                            <button type="button" class="cs-btn cs-btn--call" :disabled="loadingCall" @click="makeCall">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;margin-left:8px">
                                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                </svg>
                                {{ loadingCall ? 'در حال ارسال...' : 'فراخوان' }}
                            </button>
                        </div>
                    </div>

                    <div class="cs-card cs-card--actions">
                        <div class="cs-action-grid">
                            <button type="button" class="cs-action-btn cs-action-btn--repeat" :disabled="loadingRepeat" @click="repeatCall">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                    <polyline points="17 1 21 5 17 9"/>
                                    <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                                    <polyline points="7 23 3 19 7 15"/>
                                    <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                                </svg>
                                تکرار فراخوان
                            </button>
                            <button type="button" class="cs-action-btn cs-action-btn--test" :disabled="loadingTestDisplay" @click="testDisplay">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                    <line x1="8" y1="21" x2="16" y2="21"/>
                                    <line x1="12" y1="17" x2="12" y2="21"/>
                                </svg>
                                تست نمایشگر
                            </button>
                            <button type="button" class="cs-action-btn cs-action-btn--voice" :disabled="loadingTestVoice" @click="testVoice">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                                </svg>
                                تست صدا
                            </button>
                        </div>
                        <div class="cs-audio-info" :class="audioStatus.exists ? 'cs-audio-info--ok' : 'cs-audio-info--missing'">
                            <template v-if="audioStatus.exists">
                                فایل‌های صوتی: {{ toPersianDigits(audioStatus.total_files) }} / {{ toPersianDigits(audioStatus.total_expected) }}
                            </template>
                            <template v-else>
                                فایل صوتی موجود نیست
                            </template>
                        </div>
                    </div>
                </div>

                <div class="cs-col cs-col--right">
                    <div class="cs-tabs">
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'tickets' }" @click="switchTab('tickets')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                                <rect x="9" y="3" width="6" height="4" rx="1"/>
                            </svg>
                            نوبت‌ها
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'queue' }" @click="switchTab('queue')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            صف نمایش
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'slides' }" @click="switchTab('slides')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/>
                                <line x1="7" y1="2" x2="7" y2="22"/>
                                <line x1="17" y1="2" x2="17" y2="22"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                            </svg>
                            اسلایدشو
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'preview' }" @click="switchTab('preview')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                <line x1="8" y1="21" x2="16" y2="21"/>
                                <line x1="12" y1="17" x2="12" y2="21"/>
                            </svg>
                            پیش‌نمایش زنده
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'history' }" @click="switchTab('history')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            تاریخچه فراخوان‌ها
                        </button>
                    </div>

                    <div v-show="activeTab === 'queue'" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'queue' }">
                        <div class="cs-card cs-card--queue">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--display">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                        <line x1="8" y1="21" x2="16" y2="21"/>
                                        <line x1="12" y1="17" x2="12" y2="21"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">در حال نمایش روی تلویزیون</h2>
                                <span class="cs-queue-badge">{{ toPersianDigits(queueCount) }} / {{ toPersianDigits(QUEUE_MAX) }}</span>
                                <button type="button" class="cs-reset-btn" :disabled="loadingReset" @click="confirmReset" title="پاک کردن تمام شماره‌ها از نمایشگر">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                        <line x1="10" y1="11" x2="10" y2="17"/>
                                        <line x1="14" y1="11" x2="14" y2="17"/>
                                    </svg>
                                    پاک کردن همه
                                </button>
                            </div>
                            <div class="cs-queue-grid">
                                <div class="cs-queue-slot cs-queue-slot--hero" :class="{ 'is-active': !!displayQueue[0], 'is-empty': !displayQueue[0] }">
                                    <div class="cs-queue-slot-num">{{ displayQueue[0]?.persian_number || displayQueue[0]?.reception_number || '' }}</div>
                                    <div class="cs-queue-slot-dept">{{ displayQueue[0]?.department || '' }}</div>
                                    <div class="cs-queue-slot-empty">{{ displayQueue[0] ? '' : '—' }}</div>
                                    <button v-if="displayQueue[0]" type="button" class="cs-queue-remove" title="حذف از نمایش" @click="removeFromDisplay(displayQueue[0], 0)">×</button>
                                </div>
                                <div v-for="i in 4" :key="i" class="cs-queue-slot" :class="{ 'is-active': !!displayQueue[i], 'is-empty': !displayQueue[i] }">
                                    <div class="cs-queue-slot-num">{{ displayQueue[i]?.persian_number || displayQueue[i]?.reception_number || '' }}</div>
                                    <div class="cs-queue-slot-dept">{{ displayQueue[i]?.department || '' }}</div>
                                    <div class="cs-queue-slot-empty">{{ displayQueue[i] ? '' : '—' }}</div>
                                    <button v-if="displayQueue[i]" type="button" class="cs-queue-remove" title="حذف از نمایش" @click="removeFromDisplay(displayQueue[i], i)">×</button>
                                </div>
                            </div>
                        </div>

                        <div class="cs-card cs-card--waiting">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--waiting">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                        <circle cx="9" cy="7" r="4"/>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">صف پذیرش (در انتظار فراخوان)</h2>
                                <span class="cs-queue-badge cs-queue-badge--waiting">{{ toPersianDigits(waitingCount) }}</span>
                            </div>
                            <div class="cs-waiting-add">
                                <input v-model="waitingInput" type="text" class="cs-waiting-input" placeholder="شماره پذیرش" autocomplete="off" inputmode="numeric" @keydown.enter.prevent="addToWaitingQueue">
                                <select v-model="waitingDept" class="cs-waiting-select">
                                    <option value="نمونه‌گیری">نمونه‌گیری</option>
                                    <option value="خون‌گیری">خون‌گیری</option>
                                </select>
                                <button type="button" class="cs-waiting-add-btn" :disabled="waitingLoading" @click="addToWaitingQueue">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
                                        <line x1="12" y1="5" x2="12" y2="19"/>
                                        <line x1="5" y1="12" x2="19" y2="12"/>
                                    </svg>
                                    افزودن به صف
                                </button>
                            </div>
                            <div class="cs-waiting-list">
                                <div v-if="waitingItems.length === 0" class="cs-waiting-empty">هنوز شماره‌ای در صف نیست</div>
                                <div v-for="item in waitingItems" :key="item.id" class="cs-waiting-item">
                                    <span class="cs-waiting-num">{{ item.persian_number || item.reception_number }}</span>
                                    <span class="cs-waiting-dept">{{ item.department }}</span>
                                    <span class="cs-waiting-time">{{ fmtTime(item.created_at) }}</span>
                                    <div class="cs-waiting-actions">
                                        <button type="button" class="cs-waiting-btn cs-waiting-btn--call" title="فراخوان" @click="callFromWaitingQueue(item.id)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        </button>
                                        <button type="button" class="cs-waiting-btn cs-waiting-btn--remove" title="حذف" @click="removeFromWaitingQueue(item.id)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-show="activeTab === 'tickets'" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'tickets' }">
                        <div class="cs-card cs-card--tickets">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--display">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                                        <rect x="9" y="3" width="6" height="4" rx="1"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">نوبت‌های صادر شده</h2>
                                <span class="cs-queue-badge">{{ toPersianDigits(ticketCount) }}</span>
                                <button type="button" class="cs-reset-btn" @click="loadTickets()" title="بروزرسانی">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <path d="M23 4v6h-6M1 20v-6h6"/>
                                        <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                                    </svg>
                                    بروزرسانی
                                </button>
                                <button type="button" class="cs-reset-btn cs-reset-btn--danger" @click="confirmDeleteAllTickets" title="حذف همه نوبت‌های در انتظار">
                                    حذف همه
                                </button>
                            </div>
                            <div class="cs-waiting-add" style="margin-bottom: 12px;">
                                <select v-model="ticketDept" class="cs-waiting-select">
                                    <option value="پذیرش">پذیرش</option>
                                    <option value="نمونه‌گیری">نمونه‌گیری</option>
                                </select>
                                <button type="button" class="cs-waiting-add-btn" style="background: var(--cs-accent); color: #fff;" @click="callNextTicket">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
                                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                    </svg>
                                    فراخوان نفر بعدی
                                </button>
                            </div>
                            <div class="cs-ticket-filters" role="group" aria-label="فیلتر وضعیت نوبت‌ها">
                                <span class="cs-ticket-filters__label">نمایش</span>
                                <button type="button" class="cs-ticket-filter" :class="{ 'cs-ticket-filter--active': ticketStatus === 'waiting' }" @click="loadTickets('waiting')">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2"/></svg>
                                    در انتظار
                                </button>
                                <button type="button" class="cs-ticket-filter" :class="{ 'cs-ticket-filter--active': ticketStatus === 'all' }" @click="loadTickets('all')">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>
                                    همه
                                </button>
                            </div>
                            <div class="cs-waiting-list">
                                <div v-if="tickets.length === 0" class="cs-waiting-empty">هنوز نوبتی صادر نشده است</div>
                                <div v-for="item in tickets" :key="item.id" class="cs-waiting-item">
                                    <span class="cs-waiting-num">{{ item.persian_number || item.ticket_number }}</span>
                                    <span class="cs-waiting-dept">
                                        <template v-if="item.status === 'waiting'">{{ item.service }}</template>
                                        <template v-else-if="item.status === 'called'">{{ item.called_for || item.service }} — فراخوان شده</template>
                                        <template v-else-if="item.status === 'completed'">{{ item.called_for || item.service }} — انجام شده</template>
                                    </span>
                                    <span class="cs-waiting-time">{{ fmtTime(item.created_at) }}</span>
                                    <div class="cs-waiting-actions" v-if="item.status === 'waiting'">
                                        <button type="button" class="cs-waiting-btn cs-waiting-btn--call" title="فراخوان" @click="callTicket(item.id)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        </button>
                                        <button type="button" class="cs-waiting-btn cs-waiting-btn--remove" title="حذف نوبت" @click="confirmDeleteTicket(item.id, item.persian_number || item.ticket_number)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-show="activeTab === 'preview'" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'preview' }">
                        <div class="cs-card cs-card--preview">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--display">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                        <line x1="8" y1="21" x2="16" y2="21"/>
                                        <line x1="12" y1="17" x2="12" y2="21"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">پیش‌نمایش زنده نمایشگر</h2>
                                <button type="button" class="cs-refresh-btn" :class="{ 'is-loading': loadingRefresh }" :disabled="loadingRefresh" @click="refreshDisplays" title="بارگذاری مجدد صفحه نمایشگر (تلویزیون) از راه دور">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                    رفرش نمایشگر
                                </button>
                                <span class="cs-live-dot"></span>
                            </div>
                            <div class="cs-preview-wrap">
                                <iframe src="/call-display" class="cs-preview-iframe" allow="autoplay" loading="lazy"></iframe>
                                <div v-if="previewOverlayVisible" class="cs-preview-overlay" :class="{ 'is-hidden': !previewOverlayVisible }">
                                    <div class="cs-preview-overlay-content">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:44px;height:44px;margin-bottom:14px;opacity:0.4">
                                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                            <line x1="8" y1="21" x2="16" y2="21"/>
                                            <line x1="12" y1="17" x2="12" y2="21"/>
                                        </svg>
                                        <span>در انتظار اتصال نمایشگر</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="cs-card cs-card--display-status">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--display">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                        <line x1="8" y1="21" x2="16" y2="21"/>
                                        <line x1="12" y1="17" x2="12" y2="21"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">وضعیت نمایشگر</h2>
                            </div>
                            <div class="cs-display-status-body" :class="displayStatus.real_displays > 0 ? 'cs-display-status-body--connected' : ''">
                                <div class="cs-display-status-icon" :class="displayStatus.real_displays > 0 ? 'cs-display-status-icon--connected' : 'cs-display-status-icon--waiting'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <div class="cs-display-status-text">
                                    <span class="cs-display-status-label">
                                        {{ displayStatus.real_displays > 0 ? 'نمایشگر متصل است' : 'در انتظار اتصال نمایشگر' }}
                                    </span>
                                    <span class="cs-display-status-sub">
                                        {{ displayStatus.real_displays > 0 ? toPersianDigits(displayStatus.real_displays) + ' دستگاه نمایشگر فعال در شبکه' : 'هیچ دستگاهی صفحه نمایش را باز نکرده است' }}
                                    </span>
                                </div>
                                <div class="cs-display-status-dot" :class="displayStatus.real_displays > 0 ? 'cs-display-status-dot--on' : 'cs-display-status-dot--off'"></div>
                            </div>
                        </div>
                    </div>

                    <div v-show="activeTab === 'slides'" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'slides' }">
                        <div class="cs-card cs-card--slides">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--slides">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/>
                                        <line x1="7" y1="2" x2="7" y2="22"/>
                                        <line x1="17" y1="2" x2="17" y2="22"/>
                                        <line x1="2" y1="12" x2="22" y2="12"/>
                                        <line x1="2" y1="7" x2="7" y2="7"/>
                                        <line x1="2" y1="17" x2="7" y2="17"/>
                                        <line x1="17" y1="7" x2="22" y2="7"/>
                                        <line x1="17" y1="17" x2="22" y2="17"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">اسلایدشوی نمایشگر</h2>
                                <span class="cs-queue-badge cs-queue-badge--slides">{{ toPersianDigits(slideCount) }}</span>
                            </div>
                            <div class="cs-slides-upload">
                                <input ref="slideFileInput" type="file" accept="image/*" multiple hidden @change="uploadSlide">
                                <button type="button" class="cs-slides-upload-btn" :disabled="slideUploading" @click="slideFileInput?.click()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    آپلود تصویر اسلاید
                                </button>
                                <div class="cs-slides-upload-hint">JPG، PNG، WebP — حداکثر ۱۰ مگابایت</div>
                            </div>
                            <div class="cs-slides-list">
                                <div v-if="slides.length === 0" class="cs-slides-empty">هنوز اسلایدی آپلود نشده است</div>
                                <div v-for="slide in slides" :key="slide.id" class="cs-slide-item" :class="{ 'is-inactive': !slide.is_active }">
                                    <img :src="slide.url" :alt="slide.original_name" class="cs-slide-thumb" loading="lazy">
                                    <div class="cs-slide-info">
                                        <div class="cs-slide-name">{{ slide.original_name }}</div>
                                        <div class="cs-slide-status" :class="slide.is_active ? 'cs-slide-status--active' : 'cs-slide-status--inactive'">
                                            {{ slide.is_active ? 'فعال' : 'غیرفعال' }}
                                        </div>
                                    </div>
                                    <div class="cs-slide-actions">
                                        <button type="button" class="cs-slide-btn cs-slide-btn--toggle" :title="slide.is_active ? 'غیرفعال کردن' : 'فعال کردن'" @click="toggleSlide(slide.id)">
                                            <svg v-if="slide.is_active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                        <button type="button" class="cs-slide-btn cs-slide-btn--delete" title="حذف اسلاید" @click="confirmDeleteSlide(slide.id, slide.original_name)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-show="activeTab === 'history'" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'history' }">
                        <div class="cs-card cs-card--history">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--history">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">تاریخچه فراخوان‌ها</h2>
                                <button type="button" class="cs-reset-btn cs-reset-btn--danger" @click="confirmClearHistory" title="پاک کردن تاریخچه فراخوان‌ها">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <path d="M3 6h18"/>
                                        <path d="M8 6V4h8v2"/>
                                        <path d="M19 6l-1 14H6L5 6"/>
                                    </svg>
                                    پاک کردن تاریخچه
                                </button>
                            </div>
                            <div class="cs-history-list">
                                <div v-if="history.length === 0" class="cs-history-empty">هنوز فراخوانی ثبت نشده است</div>
                                <div v-for="(item, idx) in history" :key="idx" class="cs-history-item">
                                    <span class="cs-history-num">{{ item.persian_number || item.reception_number }}</span>
                                    <span class="cs-history-dept">{{ item.department }}</span>
                                    <span class="cs-history-time">{{ fmtTime(item.called_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <div class="cs-conn-float" :class="'cs-conn-float--' + connState">
            <svg class="cs-conn-icon cs-conn-icon--connecting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
            <svg class="cs-conn-icon cs-conn-icon--connected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                <circle cx="12" cy="12" r="3"/>
                <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/>
            </svg>
            <svg class="cs-conn-icon cs-conn-icon--disconnected" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="2" y1="2" x2="22" y2="22"/>
                <path d="M6.7 6.7 2 12s3 7 10 7a20.5 20.5 0 0 0 5.3-1.3"/>
                <path d="M17.2 17.2 22 12s-3-7-10-7a19.5 19.5 0 0 0-4 1.3"/>
            </svg>
            <span class="cs-conn-text">
                {{ connState === 'connected' ? 'متصل' : connState === 'disconnected' ? 'قطع' : 'در حال اتصال...' }}
            </span>
        </div>

        <footer class="cs-footer">
            <span class="cs-footer-brand">سامانه فراخوان نمونه‌گیری</span>
            <span class="cs-footer-sep">|</span>
            <span class="cs-footer-company">محصول شرکت هنر افزار ایرانیان</span>
            <span class="cs-footer-tm">&#x00AE;</span>
        </footer>

        <div class="toast-stack">
            <div v-for="toast in toasts" :key="toast.id" class="toast" :class="'toast--' + toast.type">
                <div class="toast__head">
                    <span class="toast__icon">{{ toast.type === 'error' ? '✕' : '✓' }}</span>
                    <span class="toast__title">{{ toast.type === 'error' ? 'خطا' : 'موفق' }}</span>
                </div>
                <div class="toast__body">{{ toast.message }}</div>
            </div>
        </div>

        <div class="cs-modal" :class="{ 'is-open': confirmModal.open }">
            <div class="cs-modal-backdrop" @click="closeConfirmModal"></div>
            <div class="cs-modal-card">
                <div class="cs-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </div>
                <h3 class="cs-modal-title">{{ confirmModal.title }}</h3>
                <p class="cs-modal-desc">{{ confirmModal.desc }}</p>
                <div class="cs-modal-actions">
                    <button type="button" class="cs-modal-btn cs-modal-btn--cancel" @click="closeConfirmModal">انصراف</button>
                    <button type="button" class="cs-modal-btn cs-modal-btn--confirm" @click="handleConfirm">{{ confirmModal.confirmText }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
