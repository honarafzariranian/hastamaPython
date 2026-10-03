<script setup>
/**
 * `/call-management` — پنل مدیریت سامانه فراخوان نمونه‌گیری.
 *
 * این کامپوننت بازآفرینیِ دقیقِ صفحه‌ی پایتون است:
 *   - ساختار DOM عیناً مطابق `app/templates/call-management.html` (همان کلاس‌ها،
 *     همان idها، همان ترتیب فرزندان، همان متن‌های ثابت).
 *   - رفتار عیناً مطابق `app/static/js/call-system-standalone.js` — حتی وضعیت
 *     لودینگ دکمه‌ها (`setLoading` متن دکمه را به «در حال ارسال...» تبدیل و
 *     آیکون را برای همیشه حذف می‌کند؛ همان‌طور که در پایتون اتفاق می‌افتد)،
 *     اعداد لاتین/فارسی هر شمارنده، دو سبک متفاوت مودال تأیید (`cs-modal` و
 *     `HastamaUX.confirm`) و دیالوگ کپی فیلدهای بیمار.
 *   - تم این صفحه (کلید `cs-theme` در localStorage و کلاس `dark-mode` روی
 *     <body>) از تم سراسری اپ (`hastama-theme`) جداست؛ دقیقاً همان قرارداد
 *     قالب پایتون. کلاس `cs-page` هنگام ورود به <body> افزوده و هنگام خروج
 *     برداشته می‌شود تا آبشارِ `body.cs-page` در استایل‌شیت مشترک فعال شود.
 */
import { ref, reactive, computed, onMounted, onUnmounted, nextTick } from 'vue';
import api from '@/services/api';
import { toPersianDigits, toLatinDigits } from '@/utils/numbers';

/*
 * همان آدرس قالب پایتون (/static/images/newlogo.png?v=20260928) با پیشوند
 * public لاراول. به‌صورت پویا bind می‌شود تا asset-rewriter باندلر آن را به
 * عنوان ماژول resolve نکند — قرارداد قبلی همین فایل و AppLayout.vue.
 */
const logoUrl = '/images/newlogo.png?v=20260928';

const QUEUE_MAX = 5;

/* ── زبانه‌ها: مقادیر همان idهای قالب پایتون‌اند ───────────────────────── */
const activeTab = ref('cs-tab-tickets');

function switchCsTab(tabId) {
    activeTab.value = tabId;
}

/* ── وضعیت لودینگ دکمه‌ها به سبک setLoading پایتون ─────────────────────── */
/*
 * setLoading(btn,true) در پایتون btn.textContent را بازنویسی می‌کند؛ یعنی
 * آیکون SVG از DOM حذف می‌شود و هنگام بازگشت فقط متنِ خامِ قبلی برمی‌گردد.
 * نتیجه: پس از اولین کلیک آیکون دکمه برای همیشه نمایش داده نمی‌شود و در
 * حین ارسال فقط «در حال ارسال...» دیده می‌شود. `spent` همان رفتار را
 * بازتولید می‌کند.
 */
function makeBtnState() {
    return reactive({ loading: false, spent: false });
}

function setLoading(state, loading) {
    if (loading) {
        state.loading = true;
    } else {
        state.loading = false;
        state.spent = true;
    }
}

const callBtn = makeBtnState();
const repeatBtn = makeBtnState();
const testDisplayBtn = makeBtnState();
const testVoiceBtn = makeBtnState();
const resetBtn = makeBtnState();
const waitingAddBtn = makeBtnState();

const LOADING_TEXT = 'در حال ارسال...';

/* ── فرم فراخوان ─────────────────────────────────────────────────────── */
const numberInput = ref('');
const department = ref('نمونه‌گیری');
const numberInputEl = ref(null);

/* ── صف نمایشگر ──────────────────────────────────────────────────────── */
const displayQueue = ref(new Array(QUEUE_MAX).fill(null));

/* ── صف پذیرش ────────────────────────────────────────────────────────── */
const waitingItems = ref([]);
const waitingInput = ref('');
const waitingDept = ref('نمونه‌گیری');
const waitingInputEl = ref(null);
const waitingLoading = ref(false);

/* ── نوبت‌ها ─────────────────────────────────────────────────────────── */
const tickets = ref([]);
const ticketStatusFilter = ref('waiting');
const ticketDept = ref('پذیرش');
const expandedTickets = ref(new Set());
const copiedField = ref(null);
let copiedTimer = null;

/* ── اسلایدها ────────────────────────────────────────────────────────── */
const slides = ref([]);
const slideUploading = ref(false);
const slideFileInput = ref(null);

/* ── تاریخچه ─────────────────────────────────────────────────────────── */
const history = ref([]);

/* ── وضعیت نمایشگر / صدا / اتصال ─────────────────────────────────────── */
const displayStatus = ref({ connected_displays: 0, real_displays: 0, preview_displays: 0 });
const audioStatus = ref(null); // null = هنوز پاسخی نیامده؛ مثل div خالی پایتون
const connState = ref(''); // '' = حالت اولیه‌ی بج پایتون (بدون کلاس وضعیت)
const connCount = ref(0);
const previewOverlayVisible = ref(true);

const connLabel = computed(() => {
    if (connState.value === 'connected') {
        let label = 'متصل';
        if (connCount.value > 0) label += ' (' + connCount.value + ')';
        return label;
    }
    if (connState.value === 'disconnected') return 'قطع';
    return 'در حال اتصال...';
});

const queueBadge = computed(() => displayQueue.value.filter(Boolean).length + ' / ' + QUEUE_MAX);
const waitingCount = computed(() => waitingItems.value.length);
const ticketCount = computed(() => tickets.value.length);
const slideCount = computed(() => slides.value.length);

/* ════════════════════════════════════════════════════════════════════
   Toast — بازآفرینی app/static/js/toast.js
   IC Dos: آیکون/عنوان/مدت‌زمان هر نوع دقیقاً همان‌های toast.js هستند؛
   ساختار DOM (head/icon/title/close/message/timeline) و کلاس‌های
   show / is-closing هم همان‌ها.
   ════════════════════════════════════════════════════════════════════ */
const TOAST_ICONS = { success: '✓', error: '×', warning: '!', info: 'i' };
const TOAST_TITLES = { success: 'عملیات موفق', error: 'خطای سامانه', warning: 'توجه سامانه', info: 'پیام سامانه' };
const TOAST_DURATIONS = { error: 6000, warning: 5000, success: 4500, info: 4000 };

const toasts = ref([]);
/* toast.js پشته را با اولین پیام می‌سازد و دیگر حذفش نمی‌کند؛ مثل getStack(). */
const toastStackMounted = ref(false);
let toastSeq = 0;

function showToast(message, type = 'success') {
    if (['success', 'error', 'warning', 'info'].indexOf(type) === -1) type = 'info';
    toastStackMounted.value = true;
    const id = ++toastSeq;
    const toast = {
        id,
        message,
        type,
        icon: TOAST_ICONS[type],
        title: TOAST_TITLES[type],
        duration: TOAST_DURATIONS[type] || 4500,
        show: false,
        closing: false,
    };
    toasts.value.push(toast);
    /* مانند دوقلوی requestAnimationFrame در toast.js */
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            const current = toasts.value.find((t) => t.id === id);
            if (current) current.show = true;
        });
    });
    toast.timer = setTimeout(() => dismissToast(id), toast.duration);
}

function dismissToast(id) {
    const toast = toasts.value.find((t) => t.id === id);
    if (!toast || toast.closing) return;
    clearTimeout(toast.timer);
    toast.closing = true;
    toast.show = false;
    setTimeout(() => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }, 350);
}

/* ════════════════════════════════════════════════════════════════════
   مودال تأیید «cs-modal» — نسخه‌ی خودِ قالب پایتون
   آیکون و متن دکمه‌ی تأیید («بله، پاک کن») در قالب ثابت‌اند؛ فقط عنوان و
   توضیح با هر فراخوانی عوض می‌شوند (showModal در call-system-standalone.js).
   ════════════════════════════════════════════════════════════════════ */
const csModal = ref({ open: false, title: 'پاک کردن تمام شماره‌ها', desc: 'آیا از پاک کردن تمام شماره‌های در حال نمایش اطمینان دارید؟', onConfirm: null });

function showCsModal(title, desc, onConfirm) {
    csModal.value = { open: true, title, desc, onConfirm };
}

function closeCsModal() {
    csModal.value.open = false;
}

function confirmCsModal() {
    const fn = csModal.value.onConfirm;
    closeCsModal();
    if (fn) fn();
}

/* ════════════════════════════════════════════════════════════════════
   HastamaUX.confirm — بازآفرینی app/static/js/hastama-ux.js
   overlay با آیکون هشدار سه‌گوش؛ انصراف/تأیید، کلیک روی پس‌زمینه و Escape
   همان resolve(false)، تأیید resolve(true) است. بستن: حذف کلاس h-ux-active
   و حذف overlay بعد از ۳۰۰ms.
   ════════════════════════════════════════════════════════════════════ */
const huxDialog = ref({ open: false, active: false, title: '', message: '', confirmText: 'بله', cancelText: 'انصراف' });
const huxConfirmOk = ref(null);
let huxResolve = null;

function uxConfirm(opts) {
    return new Promise((resolve) => {
        huxResolve = resolve;
        huxDialog.value = {
            open: true,
            active: false,
            title: opts.title || 'تأیید عملیات',
            message: opts.message || 'آیا مطمئن هستید؟',
            confirmText: opts.confirmText || 'بله',
            cancelText: opts.cancelText || 'انصراف',
        };
        requestAnimationFrame(() => {
            huxDialog.value.active = true;
        });
        nextTick(() => {
            if (huxConfirmOk.value) huxConfirmOk.value.focus();
        });
    });
}

function closeHuxConfirm(result) {
    if (!huxDialog.value.open) return;
    huxDialog.value.active = false;
    const resolve = huxResolve;
    huxResolve = null;
    /* مثل پایتون: promise بلافاصله resolve می‌شود و overlay ۳۰۰ms بعد حذف */
    if (resolve) resolve(result);
    setTimeout(() => {
        huxDialog.value.open = false;
    }, 300);
}

function onHuxEscape(event) {
    if (event.key === 'Escape' && huxDialog.value.open) {
        closeHuxConfirm(false);
    }
}

/* ── کپی متن — نسخه‌ی سازگار با HTTP و HTTPS (همان دو روش پایتون) ────── */
function copyTextToClipboard(text) {
    return new Promise((resolve, reject) => {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(resolve).catch(reject);
            return;
        }
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            const ok = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (ok) resolve();
            else reject(new Error('copy failed'));
        } catch (err) {
            document.body.removeChild(textarea);
            reject(err);
        }
    });
}

/* ── fetch خام شبیه پایتون: فقط خطای شبکه/تحلیل JSON رد می‌کند ───────── */
/*
 * دکمه‌های «تست نمایشگر» و «تست صدا» در پایتون با fetch ساده خوانده می‌شوند
 * و بدون توجه به کد وضعیت HTTP هر پاسخ JSON قابل تحلیل مسیر موفقیت را طی
 * می‌کند؛ فقط خطای شبکه یا JSON نامعتبر به catch می‌افتد.
 */
function rawJson(url, options) {
    return fetch(url, options).then((r) => r.json());
}

/* ── متن خطای یکسان با پیام‌های فارسی پایتون ─────────────────────────── */
function toastCallError(err, detailFallback, offlineText) {
    if (err && err.offline) {
        showToast(offlineText || detailFallback, 'error');
    } else {
        showToast((err && err.message) || detailFallback, 'error');
    }
}

/* ── Format time (همان fmtTime پایتون) ───────────────────────────────── */
function fmtTime(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    } catch {
        return '';
    }
}

/* ── صف نمایشگر: افزودن محلی به سبک addToQueue پایتون ────────────────── */
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
    for (let j = QUEUE_MAX - 1; j > 0; j--) {
        displayQueue.value[j] = displayQueue.value[j - 1];
    }
    displayQueue.value[0] = data;
}

function addHistoryItem(data) {
    if (!data) return;
    history.value.unshift(data);
    while (history.value.length > 30) history.value.pop();
}

/* ── API: فراخوان ────────────────────────────────────────────────────── */
function makeCall() {
    const num = numberInput.value.trim();
    if (!num) {
        showToast('لطفاً شماره پذیرش را وارد کنید.', 'error');
        if (numberInputEl.value) numberInputEl.value.focus();
        return;
    }
    setLoading(callBtn, true);
    api.post('/calls', { reception_number: num, department: department.value })
        .then((res) => {
            addToQueue(res.data);
            addHistoryItem(res.data);
            showToast(res.message || 'فراخوان ارسال شد.', 'success');
            numberInput.value = '';
            if (numberInputEl.value) numberInputEl.value.focus();
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'فراخوان انجام نشد.');
        })
        .finally(() => {
            setLoading(callBtn, false);
        });
}

/* ── API: تکرار ──────────────────────────────────────────────────────── */
function repeatCall() {
    setLoading(repeatBtn, true);
    api.post('/calls/repeat')
        .then((res) => {
            addToQueue(res.data);
            addHistoryItem(res.data);
            showToast(res.message || 'تکرار ارسال شد.', 'success');
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'تکرار انجام نشد.');
        })
        .finally(() => {
            setLoading(repeatBtn, false);
        });
}

/* ── API: تست نمایشگر (همان معنای fetch پایتون) ──────────────────────── */
function testDisplay() {
    setLoading(testDisplayBtn, true);
    rawJson('/api/calls/test-display', { method: 'POST' })
        .then((res) => {
            setLoading(testDisplayBtn, false);
            showToast(res.message || 'تست نمایشگر ارسال شد.', 'success');
        })
        .catch(() => {
            setLoading(testDisplayBtn, false);
            showToast('تست نمایشگر انجام نشد.', 'error');
        });
}

/* ── API: تست صدا (در پایتون: test-audio?number=1) ───────────────────── */
function testVoice() {
    setLoading(testVoiceBtn, true);
    rawJson('/api/calls/test-audio?number=1', { method: 'POST' })
        .then((res) => {
            setLoading(testVoiceBtn, false);
            showToast(res.message || 'تست صدا ارسال شد.', 'success');
        })
        .catch(() => {
            setLoading(testVoiceBtn, false);
            showToast('تست صدا انجام نشد.', 'error');
        });
}

/* ── API: پاک کردن همه (با cs-modal) ─────────────────────────────────── */
function resetDisplay() {
    showCsModal(
        'پاک کردن همه',
        'آیا از پاک کردن تمام شماره‌ها از نمایشگر اطمینان دارید؟',
        () => {
            setLoading(resetBtn, true);
            api.post('/calls/reset-display')
                .then((res) => {
                    for (let i = 0; i < QUEUE_MAX; i++) displayQueue.value[i] = null;
                    showToast(res.message || 'نمایشگر پاک شد.', 'success');
                })
                .catch((err) => {
                    toastCallError(err, 'خطا', 'پاک کردن انجام نشد.');
                })
                .finally(() => {
                    setLoading(resetBtn, false);
                });
        }
    );
}

/* ── API: رفرش نمایشگرها ─────────────────────────────────────────────── */
const loadingRefresh = ref(false);

function refreshDisplays() {
    loadingRefresh.value = true;
    api.post('/calls/refresh-display')
        .then((res) => {
            const realCount = res.real_displays || 0;
            if (realCount > 0) {
                showToast('دستور رفرش به ' + toPersianDigits(realCount) + ' نمایشگر ارسال شد.', 'success');
            } else {
                showToast('هیچ نمایشگر فعالی متصل نیست.', 'error');
            }
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'ارسال دستور رفرش انجام نشد.');
        })
        .finally(() => {
            loadingRefresh.value = false;
        });
}

/* ── API: حذف یک شماره از نمایشگر ────────────────────────────────────── */
function removeFromDisplay(item, slotIndex) {
    const raw = item.reception_number || item.number;
    const num = toLatinDigits(String(raw)).replace(/[^\d]/g, '');
    api.post('/calls/remove', { number: num })
        .then((res) => {
            displayQueue.value[slotIndex] = null;
            const compacted = [];
            for (let i = 0; i < QUEUE_MAX; i++) {
                if (displayQueue.value[i]) compacted.push(displayQueue.value[i]);
            }
            for (let k = 0; k < QUEUE_MAX; k++) {
                displayQueue.value[k] = k < compacted.length ? compacted[k] : null;
            }
            showToast(res.message || 'از نمایشگر حذف شد.', 'success');
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'حذف انجام نشد.');
        });
}

/* ── بارگذاری صف نمایشگر از سرور ─────────────────────────────────────── */
function loadDisplayQueue() {
    api.get('/calls/display-queue')
        .then((res) => {
            if (!res.queue) return;
            for (let i = 0; i < Math.min(res.queue.length, QUEUE_MAX); i++) {
                displayQueue.value[i] = res.queue[i];
            }
        })
        .catch(() => {});
}

/* ════════════════════════════════════════════════════════════════════
   صف پذیرش (در انتظار فراخوان)
   ════════════════════════════════════════════════════════════════════ */
function loadWaitingQueue() {
    return api.get('/calls/waiting-queue')
        .then((res) => {
            waitingItems.value = res.items || [];
        })
        .catch(() => {});
}

function addToWaitingQueue() {
    const num = waitingInput.value.trim();
    if (!num) {
        showToast('لطفاً شماره را وارد کنید.', 'error');
        if (waitingInputEl.value) waitingInputEl.value.focus();
        return;
    }
    setLoading(waitingAddBtn, true);
    api.post('/calls/waiting-queue', { number: num, department: waitingDept.value })
        .then((res) => {
            loadWaitingQueue();
            showToast(res.message || 'به صف اضافه شد.', 'success');
            waitingInput.value = '';
            if (waitingInputEl.value) waitingInputEl.value.focus();
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'اضافه به صف انجام نشد.');
        })
        .finally(() => {
            setLoading(waitingAddBtn, false);
        });
}

function removeFromWaitingQueue(id) {
    api.delete('/calls/waiting-queue/' + id)
        .then((res) => {
            waitingItems.value = waitingItems.value.filter((d) => d.id !== id);
            showToast(res.message || 'از صف حذف شد.', 'success');
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'حذف انجام نشد.');
        });
}

function callFromWaitingQueue(id) {
    api.post('/calls/waiting-queue/' + id + '/call')
        .then((res) => {
            waitingItems.value = waitingItems.value.filter((d) => d.id !== id);
            showToast(res.message || 'فراخوان ارسال شد.', 'success');
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'فراخوان انجام نشد.');
        });
}

/* ════════════════════════════════════════════════════════════════════
   نوبت‌های صف (سامانه نوبت‌دهی)
   ════════════════════════════════════════════════════════════════════ */
function loadQueueTickets(status) {
    ticketStatusFilter.value = status || ticketStatusFilter.value;
    api.get('/queue/list?status=' + ticketStatusFilter.value)
        .then((res) => {
            tickets.value = res.tickets || [];
        })
        .catch(() => {});
}

const TICKET_PATIENT_FIELDS = [
    ['نام', 'patient_name'],
    ['سن', 'patient_age'],
    ['کد ملی', 'patient_national_id'],
    ['تلفن', 'patient_phone'],
    ['بیمه پایه', 'insurance_base'],
    ['بیمه تکمیلی', 'insurance_extra'],
];

function ticketPatientFields(item) {
    return TICKET_PATIENT_FIELDS
        .map(([label, key]) => ({ label, value: item[key] }))
        .filter((field) => Boolean(field.value));
}

function ticketDeptText(item) {
    if (item.status === 'called') return (item.called_for || item.service || '') + ' — فراخوان شده';
    if (item.status === 'completed') return (item.called_for || item.service || '') + ' — انجام شده';
    return item.service || '';
}

function isTicketExpanded(id) {
    return expandedTickets.value.has(id);
}

function toggleTicketExpand(id) {
    const next = new Set(expandedTickets.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    expandedTickets.value = next;
}

function onTicketToggleKeydown(event, id) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    toggleTicketExpand(id);
}

function copyTicketField(ticket, field) {
    copyTextToClipboard(String(field.value))
        .then(() => {
            copiedField.value = ticket.id + ':' + field.label;
            showToast(field.label + ' کپی شد.', 'success');
            clearTimeout(copiedTimer);
            copiedTimer = setTimeout(() => {
                copiedField.value = null;
            }, 1500);
        })
        .catch(() => {
            showToast('خطا در کپی.', 'error');
        });
}

function callQueueTicket(id) {
    api.post('/queue/call/' + id + '?department=' + encodeURIComponent(ticketDept.value))
        .then((res) => {
            showToast(res.message || 'نوبت فراخوان شد.', 'success');
            loadQueueTickets();
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'خطا در اتصال به سرور');
        });
}

async function deleteQueueTicket(id, number) {
    if (!await uxConfirm({ title: 'حذف نوبت', message: 'آیا از حذف نوبت ' + number + ' از صف مطمئن هستید؟', confirmText: 'حذف', danger: true })) return;
    api.delete('/queue/' + encodeURIComponent(id))
        .then((res) => {
            showToast(res.message || 'نوبت حذف شد.', 'success');
            loadQueueTickets();
        })
        .catch((err) => {
            toastCallError(err, 'خطا در حذف نوبت.', 'خطا در حذف نوبت.');
        });
}

async function deleteAllWaitingTickets() {
    if (!await uxConfirm({ title: 'حذف همه نوبت‌ها', message: 'آیا از حذف همه نوبت‌های در انتظار مطمئن هستید؟', confirmText: 'حذف همه', danger: true })) return;
    api.delete('/queue')
        .then((res) => {
            showToast(res.message || 'نوبت‌ها حذف شدند.', 'success');
            loadQueueTickets();
        })
        .catch((err) => {
            toastCallError(err, 'خطا در حذف نوبت‌ها.', 'خطا در حذف نوبت‌ها.');
        });
}

function callNextTicket() {
    api.post('/queue/call-next?department=' + encodeURIComponent(ticketDept.value))
        .then((res) => {
            showToast(res.message || 'نوبت بعدی فراخوان شد.', 'success');
            loadQueueTickets();
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'خطا در اتصال به سرور');
        });
}

/* ════════════════════════════════════════════════════════════════════
   اسلایدشو
   ════════════════════════════════════════════════════════════════════ */
function loadSlides() {
    api.get('/calls/slides')
        .then((res) => {
            slides.value = res.slides || [];
        })
        .catch(() => {});
}

function uploadSlide() {
    const files = slideFileInput.value ? slideFileInput.value.files : null;
    if (!files || files.length === 0) {
        showToast('لطفاً فایل اطلاعاتی انتخاب کنید.', 'error');
        return;
    }
    const file = files[0];
    const formData = new FormData();
    formData.append('file', file);
    slideUploading.value = true;
    api.post('/calls/slides/upload', formData)
        .then((res) => {
            showToast(res.message || 'اسلاید آپلود شد.', 'success');
            if (slideFileInput.value) slideFileInput.value.value = '';
            loadSlides();
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'آپلود انجام نشد.');
        })
        .finally(() => {
            slideUploading.value = false;
        });
}

function toggleSlide(id) {
    api.put('/calls/slides/' + id + '/toggle')
        .then((res) => {
            loadSlides();
            showToast(res.message || 'تغییر صورت.', 'success');
        })
        .catch((err) => {
            toastCallError(err, 'خطا', 'خطا در تغییر.');
        });
}

function deleteSlide(id, name) {
    showCsModal(
        'حذف اسلاید',
        'آیا از حذف اسلاید «' + name + '» اطمینان دارید؟',
        () => {
            api.delete('/calls/slides/' + id)
                .then((res) => {
                    loadSlides();
                    showToast(res.message || 'اسلاید حذف شد.', 'success');
                })
                .catch((err) => {
                    toastCallError(err, 'خطا', 'حذف انجام نشد.');
                });
        }
    );
}

/* ════════════════════════════════════════════════════════════════════
   تاریخچه فراخوان‌ها
   ════════════════════════════════════════════════════════════════════ */
function loadRecent() {
    api.get('/calls/recent?limit=30')
        .then((res) => {
            if (!res.calls) return;
            res.calls.forEach((c) => { addHistoryItem(c); });
        })
        .catch(() => {});
}

async function clearCallHistory() {
    if (!await uxConfirm({ title: 'پاک کردن تاریخچه', message: 'آیا از پاک کردن کامل تاریخچه فراخوان‌ها مطمئن هستید؟', confirmText: 'پاک کردن', danger: true })) return;
    api.delete('/calls/recent')
        .then((res) => {
            history.value = [];
            showToast(res.message || 'تاریخچه فراخوان‌ها پاک شد.', 'success');
        })
        .catch((err) => {
            toastCallError(err, 'پاک کردن تاریخچه انجام نشد.', 'خطا در پاک کردن تاریخچه.');
        });
}

/* ════════════════════════════════════════════════════════════════════
   وضعیت نمایشگر و فایل‌های صوتی
   ════════════════════════════════════════════════════════════════════ */
function loadStatus() {
    api.get('/calls/status')
        .then((res) => {
            /* فقط دستگاه‌های واقعی نمایشگر (نه iframe پیش‌نمایش) */
            const realCount = res.real_displays || 0;
            displayStatus.value = res;
            connCount.value = realCount;
            connState.value = realCount > 0 ? 'connected' : 'disconnected';
            previewOverlayVisible.value = realCount === 0;
        })
        .catch(() => {
            connState.value = 'disconnected';
        });
}

function loadAudioStatus() {
    api.get('/calls/audio-status')
        .then((res) => {
            audioStatus.value = res;
        })
        .catch(() => {});
}

/* ════════════════════════════════════════════════════════════════════
   WebSocket — همان connectWS/scheduleReconnect/pageshow پایتون
   ════════════════════════════════════════════════════════════════════ */
let ws = null;
let reconnectDelay = 1000;
const MAX_RECONNECT_DELAY = 30000;
let reconnectTimer = null;
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
        /* وضعیت اتصال توسط loadStatus بر اساس real_displays تعیین می‌شود */
        /* Management page identifies as preview, not a real TV display */
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
            /* remove_call handled locally by removeFromDisplay — skip WS echo */
            if (msg.type === 'reset_display') {
                for (let m = 0; m < QUEUE_MAX; m++) displayQueue.value[m] = null;
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
        reconnectDelay = Math.min(reconnectDelay * 1.5, MAX_RECONNECT_DELAY);
        connectWs();
    }, reconnectDelay);
}

function onPageShow(event) {
    if (event.persisted) {
        if (ws) {
            try { ws.close(); } catch { /* ignore */ }
            ws = null;
        }
        connectWs();
    }
}

/* ════════════════════════════════════════════════════════════════════
   تم مخصوص این صفحه (کلید cs-theme) — جدا از تم سراسری سامانه.
   قرارداد قالب پایتون: فقط کلاس dark-mode روی <body>.
   ════════════════════════════════════════════════════════════════════ */
const CS_THEME_KEY = 'cs-theme';
let previousBodyDarkMode = false;
let previousBodyDarkTheme = false;

function applyCsTheme() {
    let saved = null;
    try {
        saved = window.localStorage.getItem(CS_THEME_KEY);
    } catch { /* private browsing */ }
    document.body.classList.toggle('dark-mode', saved === 'dark');
}

function toggleTheme() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    try {
        window.localStorage.setItem(CS_THEME_KEY, isDark ? 'dark' : 'light');
    } catch { /* ignore */ }
}

/* ── عنوان سند و فاوآیکون مخصوص قالب پایتون ──────────────────────────── */
const PYTHON_TITLE = 'سامانه فراخوان نمونه‌گیری — پنل مدیریت';
let previousFavicon = null;

function applyPythonFavicon() {
    const icon = document.querySelector('link[rel~="icon"]');
    if (!icon) return;
    previousFavicon = { href: icon.getAttribute('href'), type: icon.getAttribute('type') };
    icon.setAttribute('href', '/favicon.ico');
    icon.setAttribute('type', 'image/x-icon');
}

function restoreFavicon() {
    if (!previousFavicon) return;
    const icon = document.querySelector('link[rel~="icon"]');
    if (icon) {
        icon.setAttribute('href', previousFavicon.href);
        if (previousFavicon.type) icon.setAttribute('type', previousFavicon.type);
        else icon.removeAttribute('type');
    }
    previousFavicon = null;
}

/* ── نورافکن کارت‌ها (pointer-tracking --cs-mx/--cs-my — همان JS پایتون) ─ */
const cardCleanups = [];

function attachCardSpotlights(rootEl) {
    const canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!canHover || reduceMotion || !rootEl) return;
    rootEl.querySelectorAll('.cs-card').forEach((card) => {
        const handler = (event) => {
            const rect = card.getBoundingClientRect();
            if (!rect.width || !rect.height) return;
            card.style.setProperty('--cs-mx', ((event.clientX - rect.left) / rect.width * 100).toFixed(1) + '%');
            card.style.setProperty('--cs-my', ((event.clientY - rect.top) / rect.height * 100).toFixed(1) + '%');
        };
        card.addEventListener('pointermove', handler);
        cardCleanups.push(() => card.removeEventListener('pointermove', handler));
    });
}

const rootEl = ref(null);

/* ── Init (همان توالی init() پایتون) ─────────────────────────────────── */
onMounted(() => {
    /* بدنه باید کلاس cs-page بگیرد تا قواعد `body.cs-page` اعمال شوند و
       کلاس سراسری dark-theme برداشته می‌شود (قالب پایتون فقط dark-mode دارد). */
    previousBodyDarkMode = document.body.classList.contains('dark-mode');
    previousBodyDarkTheme = document.body.classList.contains('dark-theme');
    document.body.classList.add('cs-page');
    document.body.classList.remove('dark-theme');
    applyCsTheme();

    document.title = PYTHON_TITLE;
    applyPythonFavicon();

    loadDisplayQueue();
    loadWaitingQueue();
    loadQueueTickets('waiting');
    loadRecent();
    loadStatus();
    loadAudioStatus();
    loadSlides();
    connectWs();

    /* Poll display status every 8 seconds */
    statusInterval = setInterval(loadStatus, 8000);
    /* Poll queue tickets every 5 seconds */
    ticketsInterval = setInterval(() => loadQueueTickets(), 5000);

    attachCardSpotlights(rootEl.value);
    window.addEventListener('pageshow', onPageShow);
    document.addEventListener('keydown', onHuxEscape);
});

onUnmounted(() => {
    clearTimeout(reconnectTimer);
    clearInterval(pingInterval);
    clearInterval(statusInterval);
    clearInterval(ticketsInterval);
    clearTimeout(copiedTimer);
    if (ws) {
        try { ws.close(); } catch { /* ignore */ }
        ws = null;
    }
    cardCleanups.forEach((cleanup) => cleanup());
    window.removeEventListener('pageshow', onPageShow);
    document.removeEventListener('keydown', onHuxEscape);

    /* بازگرداندن وضعیت بدنه و فاوآیکون به حالت سراسری سامانه */
    document.body.classList.remove('cs-page');
    document.body.classList.toggle('dark-mode', previousBodyDarkMode);
    document.body.classList.toggle('dark-theme', previousBodyDarkTheme);
    restoreFavicon();
});
</script>

<template>
    <div ref="rootEl">
        <!-- ═══ Header ═══ -->
        <header class="cs-header">
            <div class="cs-header-inner">
                <div class="cs-header-brand">
                    <div class="cs-header-logo-wrap">
                        <img :src="logoUrl" alt="لوگو" class="cs-header-logo" id="csLabLogo">
                        <span class="cs-header-logo-ph" id="csLabLogoPh">
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

                <!-- Theme Toggle -->
                <button type="button" class="cs-theme-toggle" id="csThemeToggle" title="تغییر حالت نمایش" @click="toggleTheme">
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

        <!-- ═══ Main ═══ -->
        <main class="cs-main">
            <div class="cs-grid">

                <!-- ─── Left Column: Call Controls ─── -->
                <div class="cs-col cs-col--left">

                    <!-- Call Input Card -->
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
                                <input v-model="numberInput" ref="numberInputEl" type="text" id="csNumberInput" class="cs-call-input" placeholder="شماره پذیرش" autocomplete="off" inputmode="numeric" @keydown.enter.prevent="makeCall">
                                <select v-model="department" id="csDeptSelect" class="cs-call-select">
                                    <option value="نمونه‌گیری">نمونه‌گیری</option>
                                    <option value="خون‌گیری">خون‌گیری</option>
                                    <option value="رادیولوژی">رادیولوژی</option>
                                    <option value="سونوگرافی">سونوگرافی</option>
                                    <option value="آزمایشگاه">آزمایشگاه</option>
                                </select>
                            </div>
                            <button type="button" id="csCallBtn" class="cs-btn cs-btn--call" :disabled="callBtn.loading" @click="makeCall">
                                <svg v-if="!callBtn.spent && !callBtn.loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;margin-left:8px">
                                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                </svg>
                                {{ callBtn.loading ? LOADING_TEXT : 'فراخوان' }}
                            </button>
                        </div>
                    </div>

                    <!-- Action Buttons Card -->
                    <div class="cs-card cs-card--actions">
                        <div class="cs-action-grid">
                            <button type="button" id="csRepeatBtn" class="cs-action-btn cs-action-btn--repeat" :disabled="repeatBtn.loading" @click="repeatCall">
                                <svg v-if="!repeatBtn.spent && !repeatBtn.loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                    <polyline points="17 1 21 5 17 9"/>
                                    <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                                    <polyline points="7 23 3 19 7 15"/>
                                    <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                                </svg>
                                {{ repeatBtn.loading ? LOADING_TEXT : 'تکرار فراخوان' }}
                            </button>
                            <button type="button" id="csTestDisplayBtn" class="cs-action-btn cs-action-btn--test" :disabled="testDisplayBtn.loading" @click="testDisplay">
                                <svg v-if="!testDisplayBtn.spent && !testDisplayBtn.loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                    <line x1="8" y1="21" x2="16" y2="21"/>
                                    <line x1="12" y1="17" x2="12" y2="21"/>
                                </svg>
                                {{ testDisplayBtn.loading ? LOADING_TEXT : 'تست نمایشگر' }}
                            </button>
                            <button type="button" id="csTestVoiceBtn" class="cs-action-btn cs-action-btn--voice" :disabled="testVoiceBtn.loading" @click="testVoice">
                                <svg v-if="!testVoiceBtn.spent && !testVoiceBtn.loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                                </svg>
                                {{ testVoiceBtn.loading ? LOADING_TEXT : 'تست صدا' }}
                            </button>
                        </div>
                        <div class="cs-audio-info" id="csAudioStatus" :class="audioStatus ? (audioStatus.exists ? 'cs-audio-info--ok' : 'cs-audio-info--missing') : ''"><template v-if="audioStatus">{{ audioStatus.exists ? 'فایل‌های صوتی: ' + (audioStatus.total_files ?? 0) + ' / ' + (audioStatus.total_expected ?? 0) : 'فایل صوتی موجود نیست' }}</template></div>
                    </div>

                </div>

                <!-- ─── Right Column: Tabs ─── -->
                <div class="cs-col cs-col--right">

                    <!-- Tabs -->
                    <div class="cs-tabs">
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'cs-tab-tickets' }" @click="switchCsTab('cs-tab-tickets')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                                <rect x="9" y="3" width="6" height="4" rx="1"/>
                            </svg>
                            نوبت‌ها
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'cs-tab-queue' }" @click="switchCsTab('cs-tab-queue')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            صف نمایش
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'cs-tab-slides' }" @click="switchCsTab('cs-tab-slides')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/>
                                <line x1="7" y1="2" x2="7" y2="22"/>
                                <line x1="17" y1="2" x2="17" y2="22"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                            </svg>
                            اسلایدشو
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'cs-tab-preview' }" @click="switchCsTab('cs-tab-preview')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                <line x1="8" y1="21" x2="16" y2="21"/>
                                <line x1="12" y1="17" x2="12" y2="21"/>
                            </svg>
                            پیش‌نمایش زنده
                        </button>
                        <button type="button" class="cs-tab-btn" :class="{ 'cs-tab-btn--active': activeTab === 'cs-tab-history' }" @click="switchCsTab('cs-tab-history')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            تاریخچه فراخوان‌ها
                        </button>
                    </div>

                    <!-- ── Tab 1: Queue ── -->
                    <div v-show="activeTab === 'cs-tab-queue'" id="cs-tab-queue" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'cs-tab-queue' }">

                        <!-- Display Queue (what's on TV now) -->
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
                                <span class="cs-queue-badge" id="csQueueCount">{{ queueBadge }}</span>
                                <button type="button" class="cs-reset-btn" id="csResetBtn" :disabled="resetBtn.loading" title="پاک کردن تمام شماره‌ها از نمایشگر" @click="resetDisplay">
                                    <svg v-if="!resetBtn.spent && !resetBtn.loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                        <line x1="10" y1="11" x2="10" y2="17"/>
                                        <line x1="14" y1="11" x2="14" y2="17"/>
                                    </svg>
                                    {{ resetBtn.loading ? LOADING_TEXT : 'پاک کردن همه' }}
                                </button>
                            </div>
                            <div class="cs-queue-grid" id="csQueueGrid">
                                <div class="cs-queue-slot cs-queue-slot--hero" id="csQueueHero" :class="{ 'is-active': !!displayQueue[0], 'is-empty': !displayQueue[0] }">
                                    <div class="cs-queue-slot-num">{{ displayQueue[0] ? (displayQueue[0].persian_number || displayQueue[0].reception_number || '') : '' }}</div>
                                    <div class="cs-queue-slot-dept">{{ displayQueue[0] ? (displayQueue[0].department || '') : '' }}</div>
                                    <div class="cs-queue-slot-empty">—</div>
                                    <button v-if="displayQueue[0]" type="button" class="cs-queue-remove" title="حذف از نمایش" @click.stop="removeFromDisplay(displayQueue[0], 0)">×</button>
                                </div>
                                <div v-for="i in 4" :key="i" class="cs-queue-slot" :id="'csQueue' + i" :class="{ 'is-active': !!displayQueue[i], 'is-empty': !displayQueue[i] }">
                                    <div class="cs-queue-slot-num">{{ displayQueue[i] ? (displayQueue[i].persian_number || displayQueue[i].reception_number || '') : '' }}</div>
                                    <div class="cs-queue-slot-dept">{{ displayQueue[i] ? (displayQueue[i].department || '') : '' }}</div>
                                    <div class="cs-queue-slot-empty">—</div>
                                    <button v-if="displayQueue[i]" type="button" class="cs-queue-remove" title="حذف از نمایش" @click.stop="removeFromDisplay(displayQueue[i], i)">×</button>
                                </div>
                            </div>
                        </div>

                        <!-- Waiting Queue (reception → sample collection) -->
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
                                <span class="cs-queue-badge cs-queue-badge--waiting" id="csWaitingCount">{{ toPersianDigits(waitingCount) }}</span>
                            </div>
                            <div class="cs-waiting-add">
                                <input v-model="waitingInput" ref="waitingInputEl" type="text" id="csWaitingInput" class="cs-waiting-input" placeholder="شماره پذیرش" autocomplete="off" inputmode="numeric" @keydown.enter.prevent="addToWaitingQueue">
                                <select v-model="waitingDept" id="csWaitingDept" class="cs-waiting-select">
                                    <option value="نمونه‌گیری">نمونه‌گیری</option>
                                    <option value="خون‌گیری">خون‌گیری</option>
                                </select>
                                <button type="button" class="cs-waiting-add-btn" id="csWaitingAddBtn" :disabled="waitingAddBtn.loading" @click="addToWaitingQueue">
                                    <svg v-if="!waitingAddBtn.spent && !waitingAddBtn.loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px">
                                        <line x1="12" y1="5" x2="12" y2="19"/>
                                        <line x1="5" y1="12" x2="19" y2="12"/>
                                    </svg>
                                    {{ waitingAddBtn.loading ? LOADING_TEXT : 'افزودن به صف' }}
                                </button>
                            </div>
                            <div class="cs-waiting-list" id="csWaitingList">
                                <div v-show="waitingItems.length === 0" class="cs-waiting-empty" id="csWaitingEmpty">هنوز شماره‌ای در صف نیست</div>
                                <div v-for="item in waitingItems" :key="item.id" class="cs-waiting-item" :data-id="item.id">
                                    <span class="cs-waiting-num">{{ item.persian_number || item.reception_number }}</span>
                                    <span class="cs-waiting-dept">{{ item.department || '' }}</span>
                                    <span class="cs-waiting-time">{{ fmtTime(item.created_at) }}</span>
                                    <div class="cs-waiting-actions">
                                        <button type="button" class="cs-waiting-btn cs-waiting-btn--call" title="فراخوان" @click.stop="callFromWaitingQueue(item.id)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        </button>
                                        <button type="button" class="cs-waiting-btn cs-waiting-btn--remove" title="حذف" @click.stop="removeFromWaitingQueue(item.id)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /cs-tab-queue -->

                    <!-- ── Tab: Queue Tickets (نوبت‌دهی) ── -->
                    <div v-show="activeTab === 'cs-tab-tickets'" id="cs-tab-tickets" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'cs-tab-tickets' }">
                        <div class="cs-card cs-card--tickets">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--display">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                                        <rect x="9" y="3" width="6" height="4" rx="1"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">نوبت‌های صادر شده</h2>
                                <span class="cs-queue-badge" id="csTicketCount">{{ ticketCount }}</span>
                                <button type="button" class="cs-reset-btn" title="بروزرسانی" @click="loadQueueTickets()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <path d="M23 4v6h-6M1 20v-6h6"/>
                                        <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                                    </svg>
                                    بروزرسانی
                                </button>
                                <button type="button" class="cs-reset-btn cs-reset-btn--danger" title="حذف همه نوبت‌های در انتظار" @click="deleteAllWaitingTickets">
                                    حذف همه
                                </button>
                            </div>

                            <!-- Call controls -->
                            <div class="cs-waiting-add" style="margin-bottom: 12px;">
                                <select v-model="ticketDept" id="csTicketDept" class="cs-waiting-select">
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

                            <!-- Ticket status filter -->
                            <div class="cs-ticket-filters" role="group" aria-label="فیلتر وضعیت نوبت‌ها">
                                <span class="cs-ticket-filters__label">نمایش</span>
                                <button type="button" class="cs-ticket-filter" :class="{ 'cs-ticket-filter--active': ticketStatusFilter === 'waiting' }" data-ticket-status="waiting" @click="loadQueueTickets('waiting')">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2"/></svg>
                                    در انتظار
                                </button>
                                <button type="button" class="cs-ticket-filter" :class="{ 'cs-ticket-filter--active': ticketStatusFilter === 'all' }" data-ticket-status="all" @click="loadQueueTickets('all')">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>
                                    همه
                                </button>
                            </div>

                            <div class="cs-waiting-list" id="csTicketList">
                                <div v-show="tickets.length === 0" class="cs-waiting-empty" id="csTicketEmpty">هنوز نوبتی صادر نشده است</div>
                                <div v-for="item in tickets" :key="item.id" class="cs-waiting-item" :data-id="item.id" :aria-expanded="isTicketExpanded(item.id) ? 'true' : 'false'" :class="{ 'is-expanded': isTicketExpanded(item.id) }">
                                    <span class="cs-waiting-num">{{ item.persian_number || item.ticket_number }}</span>
                                    <div class="cs-ticket-info">
                                        <span class="cs-waiting-dept">{{ ticketDeptText(item) }}</span>
                                        <div v-if="ticketPatientFields(item).length" class="cs-ticket-patient">
                                            <span v-for="field in ticketPatientFields(item)" :key="field.label" class="cs-ticket-patient__field">
                                                <small>{{ field.label }}</small>
                                                <strong>{{ field.value }}</strong>
                                                <button type="button" class="cs-ticket-copy-btn" :class="{ copied: copiedField === item.id + ':' + field.label }" :title="'کپی ' + field.label" :aria-label="'کپی ' + field.label" @click.stop="copyTicketField(item, field)">
                                                    <svg v-if="copiedField !== item.id + ':' + field.label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                </button>
                                            </span>
                                        </div>
                                        <span v-if="ticketPatientFields(item).length" class="cs-ticket-details-toggle" role="button" tabindex="0" @click.stop="toggleTicketExpand(item.id)" @keydown="onTicketToggleKeydown($event, item.id)">{{ isTicketExpanded(item.id) ? 'بستن جزئیات' : 'مشاهده جزئیات' }}</span>
                                    </div>
                                    <div class="cs-ticket-side">
                                        <span class="cs-waiting-time">{{ fmtTime(item.created_at) }}</span>
                                        <div v-if="item.status === 'waiting'" class="cs-waiting-actions">
                                            <button type="button" class="cs-waiting-btn cs-waiting-btn--call" title="فراخوان" @click.stop="callQueueTicket(item.id)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                            </button>
                                            <button type="button" class="cs-waiting-btn cs-waiting-btn--remove" title="حذف نوبت" aria-label="حذف نوبت" @click.stop="deleteQueueTicket(item.id, item.persian_number || item.ticket_number)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- /cs-tab-tickets -->

                    <!-- ── Tab 2: Live Preview ── -->
                    <div v-show="activeTab === 'cs-tab-preview'" id="cs-tab-preview" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'cs-tab-preview' }">

                        <!-- Live Preview of TV Display -->
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
                                <button type="button" class="cs-refresh-btn" id="csRefreshDisplayBtn" :class="{ 'is-loading': loadingRefresh }" :disabled="loadingRefresh" title="بارگذاری مجدد صفحه نمایشگر (تلویزیون) از راه دور" @click="refreshDisplays">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                    رفرش نمایشگر
                                </button>
                                <span class="cs-live-dot" id="csPreviewLive"></span>
                            </div>
                            <div class="cs-preview-wrap">
                                <iframe id="csPreviewFrame" src="/call-display" class="cs-preview-iframe" allow="autoplay" loading="lazy"></iframe>
                                <div class="cs-preview-overlay" id="csPreviewOverlay" :class="{ 'is-hidden': !previewOverlayVisible }">
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

                        <!-- Display Connection Status -->
                        <div class="cs-card cs-card--display-status" id="csDisplayStatusCard">
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
                            <div class="cs-display-status-body" id="csDisplayStatusBody" :class="{ 'cs-display-status-body--connected': displayStatus.real_displays > 0 }">
                                <div class="cs-display-status-icon" :class="displayStatus.real_displays > 0 ? 'cs-display-status-icon--connected' : 'cs-display-status-icon--waiting'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <div class="cs-display-status-text">
                                    <span class="cs-display-status-label" id="csDisplayStatusLabel">{{ displayStatus.real_displays > 0 ? 'نمایشگر متصل است' : 'در انتظار اتصال نمایشگر' }}</span>
                                    <span class="cs-display-status-sub" id="csDisplayStatusSub">{{ displayStatus.real_displays > 0 ? (displayStatus.real_displays) + ' دستگاه نمایشگر فعال در شبکه' : 'هیچ دستگاهی صفحه نمایش را باز نکرده است' }}</span>
                                </div>
                                <div class="cs-display-status-dot" id="csDisplayDot" :class="displayStatus.real_displays > 0 ? 'cs-display-status-dot--on' : 'cs-display-status-dot--off'"></div>
                            </div>
                        </div>

                    </div><!-- /cs-tab-preview -->

                    <!-- ── Tab 3: Slideshow ── -->
                    <div v-show="activeTab === 'cs-tab-slides'" id="cs-tab-slides" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'cs-tab-slides' }">
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
                                <span class="cs-queue-badge cs-queue-badge--slides" id="csSlideCount">{{ toPersianDigits(slideCount) }}</span>
                            </div>
                            <div class="cs-slides-upload" id="csSlideUploadArea">
                                <input ref="slideFileInput" type="file" id="csSlideFileInput" accept="image/*" multiple hidden @change="uploadSlide">
                                <button type="button" class="cs-slides-upload-btn" id="csSlideUploadBtn" :disabled="slideUploading" @click="slideFileInput && slideFileInput.click()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    آپلود تصویر اسلاید
                                </button>
                                <div class="cs-slides-upload-hint">JPG، PNG، WebP — حداکثر ۱۰ مگابایت</div>
                            </div>
                            <div class="cs-slides-list" id="csSlidesList">
                                <div v-show="slides.length === 0" class="cs-slides-empty" id="csSlidesEmpty">هنوز اسلایدی آپلود نشده است</div>
                                <div v-for="slide in slides" :key="slide.id" class="cs-slide-item" :class="{ 'is-inactive': !slide.is_active }" :data-id="slide.id">
                                    <img :src="slide.url" :alt="slide.original_name" class="cs-slide-thumb" loading="lazy">
                                    <div class="cs-slide-info">
                                        <div class="cs-slide-name">{{ slide.original_name }}</div>
                                        <div class="cs-slide-status" :class="slide.is_active ? 'cs-slide-status--active' : 'cs-slide-status--inactive'">{{ slide.is_active ? 'فعال' : 'غیرفعال' }}</div>
                                    </div>
                                    <div class="cs-slide-actions">
                                        <button type="button" class="cs-slide-btn cs-slide-btn--toggle" :title="slide.is_active ? 'غیرفعال کردن' : 'فعال کردن'" @click.stop="toggleSlide(slide.id)">
                                            <svg v-if="slide.is_active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                        <button type="button" class="cs-slide-btn cs-slide-btn--delete" title="حذف اسلاید" @click.stop="deleteSlide(slide.id, slide.original_name)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Tab 4: History ── -->
                    <div v-show="activeTab === 'cs-tab-history'" id="cs-tab-history" class="cs-tab-content" :class="{ 'cs-tab-content--active': activeTab === 'cs-tab-history' }">
                        <div class="cs-card cs-card--history">
                            <div class="cs-card-header">
                                <div class="cs-card-icon cs-card-icon--history">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <h2 class="cs-card-title">تاریخچه فراخوان‌ها</h2>
                                <button type="button" class="cs-reset-btn cs-reset-btn--danger" title="پاک کردن تاریخچه فراخوان‌ها" @click="clearCallHistory">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                                        <path d="M3 6h18"/>
                                        <path d="M8 6V4h8v2"/>
                                        <path d="M19 6l-1 14H6L5 6"/>
                                    </svg>
                                    پاک کردن تاریخچه
                                </button>
                            </div>
                            <div class="cs-history-list" id="csRecentList">
                                <div v-show="history.length === 0" class="cs-history-empty" id="csHistoryEmpty">هنوز فراخوانی ثبت نشده است</div>
                                <div v-for="(item, idx) in history" :key="idx" class="cs-history-item">
                                    <span class="cs-history-num">{{ item.persian_number || item.reception_number }}</span>
                                    <span class="cs-history-dept">{{ item.department || '' }}</span>
                                    <span class="cs-history-time">{{ fmtTime(item.timestamp) }}</span>
                                </div>
                            </div>
                        </div>
                    </div><!-- /cs-tab-history -->

                </div>
            </div>
        </main>

        <!-- ═══ Connection Status Badge ═══ -->
        <div class="cs-conn-float" id="csConnBadge" :class="connState ? 'cs-conn-float--' + connState : ''">
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
            <span class="cs-conn-text" id="csStatusText">{{ connLabel }}</span>
        </div>

        <!-- ═══ Footer ═══ -->
        <footer class="cs-footer">
            <span class="cs-footer-brand">سامانه فراخوان نمونه‌گیری</span>
            <span class="cs-footer-sep">|</span>
            <span class="cs-footer-company">محصول شرکت هنر افزار ایرانیان</span>
            <span class="cs-footer-tm">&#x00AE;</span>
        </footer>

        <!-- ═══ Toast Stack (همان ساختار toast.js پایتون؛ با اولین پیام ساخته می‌شود) ═══ -->
        <div v-if="toastStackMounted" class="toast-stack" id="toastStack">
            <div v-for="toast in toasts" :key="toast.id" class="toast" :class="['toast--' + toast.type, { show: toast.show, 'is-closing': toast.closing }]" :role="toast.type === 'error' ? 'alert' : 'status'" :aria-live="toast.type === 'error' ? 'assertive' : 'polite'" :style="{ '--toast-duration': toast.duration + 'ms' }">
                <div class="toast__head">
                    <span class="toast__icon" aria-hidden="true">{{ toast.icon }}</span>
                    <strong class="toast__title">{{ toast.title }}</strong>
                    <button class="toast__close" type="button" aria-label="بستن پیام" @click="dismissToast(toast.id)">×</button>
                </div>
                <span class="toast__message">{{ toast.message }}</span>
                <div class="toast__timeline"><span></span></div>
            </div>
        </div>

        <!-- ═══ Confirm Modal (cs-modal — نسخه‌ی قالب پایتون) ═══ -->
        <div class="cs-modal" id="csModal" :class="{ 'is-open': csModal.open }">
            <div class="cs-modal-backdrop" @click="closeCsModal"></div>
            <div class="cs-modal-card">
                <div class="cs-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </div>
                <h3 class="cs-modal-title" id="csModalTitle">{{ csModal.title }}</h3>
                <p class="cs-modal-desc" id="csModalDesc">{{ csModal.desc }}</p>
                <div class="cs-modal-actions">
                    <button type="button" class="cs-modal-btn cs-modal-btn--cancel" id="csModalCancel" @click="closeCsModal">انصراف</button>
                    <button type="button" class="cs-modal-btn cs-modal-btn--confirm" id="csModalConfirm" @click="confirmCsModal">بله، پاک کن</button>
                </div>
            </div>
        </div>

        <!-- ═══ HastamaUX.confirm (همان overlay پایتون) ═══ -->
        <div v-if="huxDialog.open" class="h-ux-confirm-overlay" :class="{ 'h-ux-active': huxDialog.active }" role="dialog" aria-modal="true" :aria-label="huxDialog.title" @click.self="closeHuxConfirm(false)">
            <div class="h-ux-confirm-card">
                <svg class="h-ux-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <h3 class="h-ux-confirm-title">{{ huxDialog.title }}</h3>
                <p class="h-ux-confirm-message">{{ huxDialog.message }}</p>
                <div class="h-ux-confirm-actions">
                    <button type="button" class="h-ux-confirm-cancel" id="h-ux-confirm-cancel" @click="closeHuxConfirm(false)">{{ huxDialog.cancelText }}</button>
                    <button type="button" class="h-ux-confirm-ok" id="h-ux-confirm-ok" ref="huxConfirmOk" @click="closeHuxConfirm(true)">{{ huxDialog.confirmText }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/*
 * ── خنثی‌سازیِ نشت استایل سراسری روی این صفحه ─────────────────────────────
 * صفحه‌ی پایتون فقط سه استایل‌شیت لود می‌کند: call-system-standalone.css،
 * toast.css و hastama-ux.css. در باندل لاراول همه‌ی استایل‌ها سراسری‌اند و
 * چند قاعده‌ی dark-theme.css (که دیرتر import شده و بر آبشار غلبه می‌کند) روی
 * این صفحه اثر می‌گذارد — قاعده‌هایی که در محیط پایتون اصلاً وجود ندارند.
 * بلوک‌های زیر فقط همین نشت‌ها را، فقط همین صفحه (body.cs-page.dark-mode)، به
 * مقادیری که آبشار پایتون محاسبه می‌کند برمی‌گردانند. مختص این صفحه‌اند چون
 * CSS کامپوننتِ lazy-loaded فقط وقتی تزریق می‌شود که صفحه باز باشد.
 *
 * توجه: `body.cs-page.dark-mode` سطح specificity بالاتری از `body.dark-mode`
 * دارد پس این قواعد همیشه برنده‌اند و استایل‌شیت‌های مشترک دست‌نخورده‌اند.
 */
body.cs-page.dark-mode {
    background-color: var(--cs-bg);
    color: var(--cs-text);
    background-image: none;
    color-scheme: normal;
}

body.cs-page.dark-mode .cs-call-input,
body.cs-page.dark-mode .cs-call-select,
body.cs-page.dark-mode .cs-waiting-input,
body.cs-page.dark-mode .cs-waiting-select {
    background-color: var(--cs-surface-2);
    border-color: var(--cs-border);
    color: var(--cs-text);
}

body.cs-page.dark-mode .cs-call-input:focus,
body.cs-page.dark-mode .cs-call-select:focus,
body.cs-page.dark-mode .cs-waiting-input:focus,
body.cs-page.dark-mode .cs-waiting-select:focus {
    border-color: var(--cs-accent);
    background-color: var(--cs-surface-solid);
}

body.cs-page.dark-mode option {
    background-color: revert;
    color: revert;
}

body.cs-page.dark-mode ::placeholder {
    color: var(--cs-text-muted);
    opacity: 1;
}

body.cs-page.dark-mode ::selection {
    background-color: revert;
    color: revert;
}

body.cs-page.dark-mode button:focus-visible,
body.cs-page.dark-mode a:focus-visible,
body.cs-page.dark-mode [role="button"]:focus-visible,
body.cs-page.dark-mode [tabindex]:focus-visible,
body.cs-page.dark-mode input:focus-visible,
body.cs-page.dark-mode select:focus-visible,
body.cs-page.dark-mode textarea:focus-visible {
    outline: none;
    outline-offset: 0;
}

body.cs-page.dark-mode ::-webkit-scrollbar-thumb {
    background: revert;
    border-radius: revert;
}

body.cs-page.dark-mode ::-webkit-scrollbar-track {
    background: revert;
}

body.cs-page.dark-mode .cs-col::-webkit-scrollbar-thumb,
body.cs-page.dark-mode .cs-waiting-list::-webkit-scrollbar-thumb,
body.cs-page.dark-mode .cs-slides-list::-webkit-scrollbar-thumb,
body.cs-page.dark-mode .cs-history-list::-webkit-scrollbar-thumb {
    border-radius: var(--cs-radius-pill);
    background: rgba(var(--cs-accent-rgb), .28);
}

body.cs-page.dark-mode .cs-col::-webkit-scrollbar-thumb:hover,
body.cs-page.dark-mode .cs-waiting-list::-webkit-scrollbar-thumb:hover,
body.cs-page.dark-mode .cs-slides-list::-webkit-scrollbar-thumb:hover,
body.cs-page.dark-mode .cs-history-list::-webkit-scrollbar-thumb:hover {
    background: rgba(var(--cs-accent-rgb), .45);
}
</style>
