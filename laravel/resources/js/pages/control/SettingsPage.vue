<script setup>
/**
 * System settings — the Vue equivalent of the legacy `loadSystemSettings()`,
 * `lanAccessCard()`, `outageCard()`, `iranAccessCard()` and
 * `loginExperienceCard()` in `master-admin.js`.
 *
 * Six independent surfaces, each with its own GET/POST pair under
 * `/master-admin/api/...`:
 *   * config — the CAPTCHA switch and the idle-timeout switch + seconds;
 *   * lan-access — the emergency LAN listener, its live status and self-test;
 *   * outage — the internet-outage page settings, probe and manual declare;
 *   * iran-access — the Iran-only filter, its range list, IP probe, refresh
 *     and counter reset;
 *   * login-experience — the login loader and the CAPTCHA lifetime;
 *   * printers — the server-side printer discovery behind the label studio.
 *
 * Every card re-reads its own endpoint after a successful write (the legacy
 * `loadSystemSettings()` full re-render), so the live state the backend now
 * holds — addresses, errors, counters — is what the operator sees next.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const loading = ref(true);
const error = ref('');
const notice = ref('');

const busy = ref('');

/* ── system_config ─────────────────────────────────────────── */
const configRows = ref([]);

const captchaEnabled = computed(() => getConfigValue('captcha_enabled') === '1');
const idleEnabled = computed(() => getConfigValue('idle_timeout_enabled') === '1');
const idleSeconds = ref(getConfigValue('idle_timeout_seconds') || '300');

function getConfigValue(key) {
    const row = configRows.value.find((item) => item.config_key === key);
    return row ? row.config_value : null;
}

function configMeta(key) {
    const row = configRows.value.find((item) => item.config_key === key);
    return row?.updated_by
        ? `آخرین تغییر: ${row.updated_by} — ${formatDateTime(row.updated_at)}`
        : '';
}

async function setConfig(key, value, successMessage) {
    if (busy.value) {
        return;
    }

    busy.value = key;

    try {
        await api.post('/master-admin/api/config', { key, value }, { baseURL: '' });
        notice.value = successMessage;
        const response = await api.get('/master-admin/api/config', { baseURL: '' });
        configRows.value = Array.isArray(response.data) ? response.data : [];
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'ذخیره تنظیم انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function toggleCaptcha() {
    const next = !captchaEnabled.value;
    await setConfig('captcha_enabled', next ? '1' : '0', next ? 'کپچا فعال شد' : 'کپچا غیرفعال شد');
}

async function toggleIdle() {
    const next = !idleEnabled.value;
    await setConfig('idle_timeout_enabled', next ? '1' : '0', next ? 'خروج خودکار فعال شد' : 'خروج خودکار غیرفعال شد');
}

async function saveIdleSeconds() {
    const value = Number(idleSeconds.value);

    if (!Number.isFinite(value) || value < 10 || value > 86400) {
        error.value = 'زمان باید بین ۱۰ تا ۸۶۴۰۰ ثانیه باشد';
        return;
    }

    await setConfig('idle_timeout_seconds', String(value), 'زمان بیکاری ذخیره شد');
}

/* ── LAN access ────────────────────────────────────────────── */
const lan = ref(null);
const lanBusy = ref(false);

const lanState = computed(() => {
    if (!lan.value) {
        return '';
    }

    if (lan.value.running) {
        return { text: 'فعال و در حال سرویس‌دهی', cls: 'on' };
    }

    if (lan.value.enabled) {
        return { text: 'فعال است ولی شنونده باز نشده', cls: 'warn' };
    }

    return { text: 'غیرفعال', cls: 'off' };
});

async function loadLan() {
    const response = await api.get('/master-admin/api/lan-access', { baseURL: '' });
    lan.value = response.data ?? null;
}

async function toggleLan() {
    if (lanBusy.value || !lan.value) {
        return;
    }

    const want = !lan.value.enabled;
    lanBusy.value = true;

    try {
        await api.post('/master-admin/api/lan-access', { enabled: want }, { baseURL: '' });
        notice.value = want ? 'دسترسی از شبکه داخلی فعال شد' : 'دسترسی از شبکه داخلی غیرفعال شد';
        await loadLan();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'تغییر وضعیت شبکه داخلی انجام نشد';
        await loadLan();
    } finally {
        lanBusy.value = false;
    }
}

async function lanSelftest() {
    if (lanBusy.value) {
        return;
    }

    lanBusy.value = true;

    try {
        const response = await api.post('/master-admin/api/lan-access/selftest', {}, { baseURL: '' });
        notice.value = response.data?.message || 'تست انجام شد';
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'تست شبکه داخلی ناموفق بود';
    } finally {
        lanBusy.value = false;
    }
}

/* ── Internet outage ───────────────────────────────────────── */
const outage = ref(null);
const outageBusy = ref(false);
const outageForm = reactive({
    interval_seconds: 30,
    failures: 3,
    targets: '',
    title: '',
    message: '',
    terminate_sessions: true,
    show_lan_address: true,
});

const outageState = computed(() => {
    if (!outage.value) {
        return { text: '—', cls: 'off' };
    }

    if (!outage.value.enabled) {
        return { text: 'غیرفعال', cls: 'off' };
    }

    if (outage.value.active) {
        return { text: `حالت قطعی ${outage.value.manual ? '(دستی)' : '(خودکار)'} فعال است`, cls: 'warn' };
    }

    return { text: outage.value.probe_online ? 'اینترنت سرور وصل است' : 'در حال پایش…', cls: 'on' };
});

function applyOutageForm() {
    if (!outage.value) {
        return;
    }

    outageForm.interval_seconds = Number(outage.value.interval_seconds) || 30;
    outageForm.failures = Number(outage.value.threshold) || 3;
    outageForm.targets = outage.value.targets ?? '';
    outageForm.title = outage.value.title ?? '';
    outageForm.message = outage.value.message ?? '';
    outageForm.terminate_sessions = Boolean(outage.value.terminate_sessions);
    outageForm.show_lan_address = Boolean(outage.value.show_lan_address);
}

async function loadOutage() {
    const response = await api.get('/master-admin/api/outage', { baseURL: '' });
    outage.value = response.data ?? null;
    applyOutageForm();
}

async function saveOutage() {
    if (outageBusy.value) {
        return;
    }

    outageBusy.value = true;

    try {
        await api.post('/master-admin/api/outage', {
            enabled: outage.value?.enabled ?? false,
            interval_seconds: Number(outageForm.interval_seconds),
            failures: Number(outageForm.failures),
            targets: outageForm.targets,
            title: outageForm.title,
            message: outageForm.message,
            terminate_sessions: outageForm.terminate_sessions,
            show_lan_address: outageForm.show_lan_address,
        }, { baseURL: '' });
        notice.value = 'تنظیمات صفحهٔ قطعی اینترنت ذخیره شد';
        await loadOutage();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'ذخیره تنظیمات قطعی انجام نشد';
    } finally {
        outageBusy.value = false;
    }
}

async function checkOutage() {
    if (outageBusy.value) {
        return;
    }

    outageBusy.value = true;

    try {
        const response = await api.post('/master-admin/api/outage/check', {}, { baseURL: '' });
        const online = response.data?.probe_online;
        notice.value = online ? 'اینترنت سرور در دسترس است' : 'اینترنت سرور در دسترس نیست';
        await loadOutage();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'پایش اینترنت ناموفق بود';
    } finally {
        outageBusy.value = false;
    }
}

async function toggleOutageManual() {
    if (outageBusy.value || !outage.value) {
        return;
    }

    const want = !outage.value.manual;
    outageBusy.value = true;

    try {
        await api.post('/master-admin/api/outage/manual', { active: want }, { baseURL: '' });
        notice.value = want ? 'حالت قطعی به‌صورت دستی اعلام شد' : 'حالت قطعی دستی لغو شد';
        await loadOutage();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'تغییر حالت دستی انجام نشد';
    } finally {
        outageBusy.value = false;
    }
}

/* ── Iran-only access ──────────────────────────────────────── */
const iran = ref(null);
const iranBusy = ref(false);
const iranForm = reactive({
    title: '',
    message: '',
    help_text: '',
    log_blocked: true,
});
const iranProbeIp = ref('');
const iranProbeResult = ref('');
const iranProbeBusy = ref(false);

const iranState = computed(() => {
    if (!iran.value) {
        return { text: '—', cls: 'off' };
    }

    if (!iran.value.enabled) {
        return { text: 'غیرفعال', cls: 'off' };
    }

    if (iran.value.enforcing) {
        return { text: 'فعال — فقط آی‌پی ایران پذیرفته می‌شود', cls: 'on' };
    }

    return { text: 'فعال، اما فهرست آی‌پی در دسترس نیست (چیزی مسدود نمی‌شود)', cls: 'warn' };
});

function applyIranForm() {
    if (!iran.value) {
        return;
    }

    iranForm.title = iran.value.title ?? '';
    iranForm.message = iran.value.message ?? '';
    iranForm.help_text = iran.value.help_text ?? '';
    iranForm.log_blocked = Boolean(iran.value.log_blocked);
}

async function loadIran() {
    const response = await api.get('/master-admin/api/iran-access', { baseURL: '' });
    iran.value = response.data ?? null;
    applyIranForm();
}

async function saveIran() {
    if (iranBusy.value) {
        return;
    }

    iranBusy.value = true;

    try {
        await api.post('/master-admin/api/iran-access', {
            enabled: iran.value?.enabled ?? false,
            title: iranForm.title,
            message: iranForm.message,
            help_text: iranForm.help_text,
            log_blocked: iranForm.log_blocked,
        }, { baseURL: '' });
        notice.value = 'تنظیمات «فقط آی‌پی ایران» ذخیره شد';
        await loadIran();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'ذخیره تنظیمات ایران انجام نشد';
    } finally {
        iranBusy.value = false;
    }
}

async function checkIranIp() {
    if (iranProbeBusy.value) {
        return;
    }

    iranProbeBusy.value = true;
    iranProbeResult.value = '';

    try {
        const response = await api.post(
            '/master-admin/api/iran-access/check',
            { ip: iranProbeIp.value.trim() },
            { baseURL: '' },
        );
        const data = response.data ?? {};
        const decision = data.blocked ? 'ورود مسدود می‌شود' : 'ورود مجاز است';

        iranProbeResult.value = `${data.ip || '—'} — ${data.label || ''} — ${decision}`
            + (!data.enforcing ? ' (فیلتر غیرفعال است، پس همین حالا هیچ‌کس مسدود نمی‌شود)' : '')
            + (data.range ? ` — بازهٔ منطبق: ${data.range}` : '');
    } catch (failure) {
        iranProbeResult.value = failure?.apiFailure?.message || failure?.message || 'بررسی ناموفق بود';
    } finally {
        iranProbeBusy.value = false;
    }
}

async function refreshIranList() {
    if (iranBusy.value) {
        return;
    }

    const accepted = window.confirm('فهرست آی‌پی ایران از سرورهای RIPE و APNIC دوباره دانلود شود؟ (حدود ۳۰ مگابایت و چند دقیقه)');
    if (!accepted) {
        return;
    }

    iranBusy.value = true;
    notice.value = 'در حال به‌روزرسانی فهرست آی‌پی ایران…';

    try {
        const response = await api.post('/master-admin/api/iran-access/refresh', {}, { baseURL: '' });
        const data = response.data ?? {};
        notice.value = `فهرست به‌روز شد: ${toPersianDigits(data.ranges_ipv4 ?? 0)} بازهٔ IPv4 و ${toPersianDigits(data.ranges_ipv6 ?? 0)} بازهٔ IPv6`;
        await loadIran();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'به‌روزرسانی فهرست انجام نشد';
    } finally {
        iranBusy.value = false;
    }
}

async function resetIranCounters() {
    if (iranBusy.value) {
        return;
    }

    iranBusy.value = true;

    try {
        await api.post('/master-admin/api/iran-access/counters/reset', {}, { baseURL: '' });
        notice.value = 'آمار ورودهای مسدودشده صفر شد';
        await loadIran();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'صفر کردن آمار انجام نشد';
    } finally {
        iranBusy.value = false;
    }
}

/* ── Login experience ──────────────────────────────────────── */
const loginUx = ref(null);
const loginUxBusy = ref(false);
const loginUxForm = reactive({
    loader_enabled: true,
    loader_seconds: 3,
    loader_title: '',
    loader_message: '',
    captcha_notice: true,
    captcha_ttl: 180,
});

const loginUxState = computed(() => {
    if (!loginUx.value) {
        return { text: '—', cls: 'off' };
    }

    if (loginUx.value.loader_enabled) {
        return { text: `لودر فعال — ${toPersianDigits(loginUx.value.loader_seconds ?? 3)} ثانیه`, cls: 'on' };
    }

    return { text: 'لودر غیرفعال (ورود بدون وقفه)', cls: 'off' };
});

function applyLoginUxForm() {
    if (!loginUx.value) {
        return;
    }

    loginUxForm.loader_enabled = Boolean(loginUx.value.loader_enabled);
    loginUxForm.loader_seconds = Number(loginUx.value.loader_seconds) || 3;
    loginUxForm.loader_title = loginUx.value.loader_title ?? '';
    loginUxForm.loader_message = loginUx.value.loader_message ?? '';
    loginUxForm.captcha_notice = Boolean(loginUx.value.captcha_notice);
    loginUxForm.captcha_ttl = Number(loginUx.value.captcha_ttl_seconds) || 180;
}

async function loadLoginUx() {
    const response = await api.get('/master-admin/api/login-experience', { baseURL: '' });
    loginUx.value = response.data ?? null;
    applyLoginUxForm();
}

async function saveLoginUx() {
    if (loginUxBusy.value) {
        return;
    }

    loginUxBusy.value = true;

    try {
        await api.post('/master-admin/api/login-experience', {
            loader_enabled: loginUxForm.loader_enabled,
            loader_seconds: Number(loginUxForm.loader_seconds),
            loader_title: loginUxForm.loader_title,
            loader_message: loginUxForm.loader_message,
            captcha_notice: loginUxForm.captcha_notice,
            captcha_ttl_seconds: Number(loginUxForm.captcha_ttl),
        }, { baseURL: '' });
        notice.value = 'تنظیمات صفحهٔ ورود ذخیره شد';
        await loadLoginUx();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'ذخیره تنظیمات ورود انجام نشد';
    } finally {
        loginUxBusy.value = false;
    }
}

/* ── Printers ──────────────────────────────────────────────── */
const printers = ref(null);
const printersBusy = ref(false);

async function loadPrinters() {
    const response = await api.get('/master-admin/api/printers', { baseURL: '' });
    printers.value = response.data ?? null;
}

const printerStateLabel = {
    ready: 'آماده',
    busy: 'در حال چاپ',
    paused: 'متوقف',
    offline: 'آفلاین',
    unknown: 'نامشخص',
};

function printerMeta(printer) {
    const state = printerStateLabel[printer.status] || printer.status || '—';
    return printer.port ? `${state} · ${printer.port}` : state;
}

/* ── shared helpers ────────────────────────────────────────── */
function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
}

function formatTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('fa-IR');
}

onMounted(async () => {
    try {
        const [configResponse] = await Promise.all([
            api.get('/master-admin/api/config', { baseURL: '' }),
            loadLan(),
            loadOutage(),
            loadIran(),
            loadLoginUx(),
            loadPrinters(),
        ]);

        configRows.value = Array.isArray(configResponse.data) ? configResponse.data : [];
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری تنظیمات';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div v-if="loading" class="ma-empty">
            <div class="ma-empty__text">در حال بارگذاری تنظیمات…</div>
        </div>

        <div v-else class="ma-settings-grid">
            <div class="ma-settings-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">🔐</div>
                    <div>
                        <div class="ma-settings-card__title">کپچای صفحه ورود</div>
                        <div class="ma-settings-card__desc">فعال یا غیرفعال کردن کد امنیتی در صفحه ورود</div>
                    </div>
                </div>
                <div class="ma-settings-card__body">
                    <label class="ma-toggle">
                        <input type="checkbox" :checked="captchaEnabled" :disabled="busy === 'captcha_enabled'" @change="toggleCaptcha">
                        <span class="ma-toggle__slider"></span>
                    </label>
                    <span class="ma-toggle-label">{{ captchaEnabled ? 'فعال' : 'غیرفعال' }}</span>
                </div>
                <div v-if="configMeta('captcha_enabled')" class="ma-settings-card__footer">
                    <span class="ma-settings-card__meta">{{ configMeta('captcha_enabled') }}</span>
                </div>
            </div>

            <div class="ma-settings-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">⏱️</div>
                    <div>
                        <div class="ma-settings-card__title">خروج خودکار (بیکاری)</div>
                        <div class="ma-settings-card__desc">خروج خودکار کاربران پس از مدتی بیکاری</div>
                    </div>
                </div>
                <div class="ma-settings-card__body">
                    <label class="ma-toggle">
                        <input type="checkbox" :checked="idleEnabled" :disabled="busy === 'idle_timeout_enabled'" @change="toggleIdle">
                        <span class="ma-toggle__slider"></span>
                    </label>
                    <span class="ma-toggle-label">{{ idleEnabled ? 'فعال' : 'غیرفعال' }}</span>
                </div>
                <div class="ma-settings-card__body" style="margin-top:12px">
                    <label style="font-size:.85rem;color:#64748b;display:block;margin-bottom:6px" for="ma-cfg-idle-seconds">زمان بیکاری (ثانیه)</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input id="ma-cfg-idle-seconds" v-model="idleSeconds" type="number" class="ma-filter" style="width:120px" min="10" max="86400">
                        <button type="button" class="ma-btn ma-btn--primary ma-btn--sm" :disabled="busy === 'idle_timeout_seconds'" @click="saveIdleSeconds">ذخیره زمان</button>
                    </div>
                    <div style="font-size:.78rem;color:#94a3b8;margin-top:4px">پیش‌فرض: ۳۰۰ ثانیه (۵ دقیقه)</div>
                </div>
                <div v-if="configMeta('idle_timeout_enabled')" class="ma-settings-card__footer">
                    <span class="ma-settings-card__meta">{{ configMeta('idle_timeout_enabled') }}</span>
                </div>
            </div>

            <div v-if="lan" class="ma-settings-card ma-lan-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">🏠</div>
                    <div>
                        <div class="ma-settings-card__title">دسترسی از شبکه داخلی (حالت اضطراری)</div>
                        <div class="ma-settings-card__desc">اگر اینترنت قطع شود، رایانه‌های شبکه داخلی می‌توانند سامانه را از آدرس شبکه محلی همین سرور باز کنند. آدرس اینترنتی سامانه دست‌نخورده می‌ماند.</div>
                    </div>
                </div>
                <div class="ma-settings-card__body">
                    <label class="ma-toggle">
                        <input type="checkbox" :checked="lan.enabled" :disabled="lanBusy" @change="toggleLan">
                        <span class="ma-toggle__slider"></span>
                    </label>
                    <span class="ma-lan-state" :class="`ma-lan-state--${lanState.cls}`">
                        <i class="ma-lan-state__dot"></i>{{ lanState.text }}
                    </span>
                </div>
                <div class="ma-lan-status">
                    <div class="ma-lan-status__row">
                        <span>آدرسی که رایانه‌های شبکه باید باز کنند:</span>
                        <code class="ma-lan-status__value">{{ lan.expected_url || '—' }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>آدرس فعال (در حال سرویس‌دهی):</span>
                        <code class="ma-lan-status__value">{{ lan.running ? lan.url || '—' : '—' }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>اتصال‌های باز:</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(lan.connections ?? 0) }}</code>
                    </div>
                </div>
                <p v-if="lan.last_error" class="ma-lan-warning ma-lan-warning--error">آخرین خطا: {{ lan.last_error }}</p>
                <div class="ma-lan-warning">
                    <b>توجه:</b> این مسیر روی «اچ‌تی‌تی‌پی ساده» (بدون گواهی) کار می‌کند و فقط باید در شبکه داخلی آزمایشگاه استفاده شود. برای امنیت پورت ۵۰۰۰، ویندوز به‌صورت پیش‌فرض جلوی این اتصال را می‌گیرد؛ <b>یک بار</b> با دسترسی مدیر فایل <code>scripts\lan_access_firewall.ps1</code> را با گزینهٔ <code>enable</code> اجرا کنید تا فقط رایانه‌های همین شبکه اجازهٔ اتصال بگیرند. برای بستن کامل این راه، همان فایل را با گزینهٔ <code>disable</code> اجرا کنید.
                </div>
                <div class="ma-settings-card__footer">
                    <button type="button" class="ma-btn ma-btn--ghost ma-btn--sm" :disabled="lanBusy" @click="lanSelftest">تست مسیر (از همین سرور)</button>
                    <div class="ma-settings-card__meta" style="margin-top:8px">تست داخلی فقط مسیر سامانه را بررسی می‌کند و فایروال ویندوز را نمی‌سنجد؛ برای آزمایش واقعی از یک رایانهٔ دیگر شبکه با آدرس بالا وارد شوید.</div>
                </div>
            </div>

            <div v-if="outage" class="ma-settings-card ma-lan-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">📡</div>
                    <div>
                        <div class="ma-settings-card__title">صفحهٔ قطعی اینترنت</div>
                        <div class="ma-settings-card__desc">به محض قطع شدن اینترنت (چه روی سرور و چه روی رایانهٔ کاربر)، سامانه به‌جای خطای مرورگر یک صفحهٔ راهنما نشان می‌دهد، نشست کاربران را می‌بندد و آدرس شبکهٔ داخلی را اعلام می‌کند. با برگشت اینترنت، همه‌چیز خودکار به حالت عادی برمی‌گردد.</div>
                    </div>
                </div>
                <div class="ma-settings-card__body">
                    <label class="ma-toggle">
                        <input type="checkbox" :checked="outage.enabled" :disabled="outageBusy" @change="outage.enabled = !outage.enabled; saveOutage()">
                        <span class="ma-toggle__slider"></span>
                    </label>
                    <span class="ma-lan-state" :class="`ma-lan-state--${outageState.cls}`">
                        <i class="ma-lan-state__dot"></i>{{ outageState.text }}
                    </span>
                </div>
                <div class="ma-lan-status">
                    <div class="ma-lan-status__row">
                        <span>وضعیت پایش:</span>
                        <code class="ma-lan-status__value">{{ outage.monitoring ? 'در حال اجرا' : 'متوقف' }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>آخرین بررسی:</span>
                        <code class="ma-lan-status__value">{{ formatTime(outage.checked_at) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>شکست پیاپی:</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(outage.failures ?? 0) }} از {{ toPersianDigits(outage.threshold ?? 0) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>شروع قطعی:</span>
                        <code class="ma-lan-status__value">{{ formatDateTime(outage.since) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>نشست‌های قطع‌شده:</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(outage.terminated_sessions ?? 0) }}</code>
                    </div>
                </div>
                <p v-if="outage.detail" class="ma-settings-card__meta" style="margin-top:8px">آخرین نتیجهٔ پایش: <code>{{ outage.detail }}</code></p>

                <div class="ma-outage-form">
                    <div><label for="ma-outage-interval">فاصلهٔ پایش (ثانیه)</label><input id="ma-outage-interval" v-model="outageForm.interval_seconds" type="number" min="5" max="3600"></div>
                    <div><label for="ma-outage-failures">شکست پیاپی برای اعلام قطعی</label><input id="ma-outage-failures" v-model="outageForm.failures" type="number" min="1" max="20"></div>
                    <div class="wide"><label for="ma-outage-targets">آدرس‌های پایش اینترنت (host:port، با کاما)</label><input id="ma-outage-targets" v-model="outageForm.targets" type="text" dir="ltr"></div>
                    <div class="wide"><label for="ma-outage-title">عنوان پیام</label><input id="ma-outage-title" v-model="outageForm.title" type="text"></div>
                    <div class="wide"><label for="ma-outage-message">متن پیام</label><textarea id="ma-outage-message" v-model="outageForm.message"></textarea></div>
                    <div class="wide ma-outage-toggles">
                        <label class="ma-toggle-row" style="font-size:.8rem"><span>خروج خودکار نشست کاربران هنگام قطعی</span><input v-model="outageForm.terminate_sessions" type="checkbox"><i></i></label>
                        <label class="ma-toggle-row" style="font-size:.8rem"><span>نمایش آدرس شبکهٔ داخلی روی صفحه</span><input v-model="outageForm.show_lan_address" type="checkbox"><i></i></label>
                    </div>
                </div>

                <div class="ma-lan-warning">
                    <b>نکته:</b> با فعال بودن این بخش، در صورت قطع اینترنت همهٔ کاربران <b>خارج از شبکهٔ داخلی</b> خروج زده می‌شوند و صفحهٔ قطعی را می‌بینند. مدیر اصلی، درخواست‌های شبکهٔ داخلی و درخواست‌های محلی سرور (از جمله پایش سلامت) هیچ‌وقت مسدود نمی‌شوند تا سامانه قابل مدیریت بماند. صفحهٔ راهنما در مرورگر کاربران ذخیره می‌شود تا وقتی اینترنت خودشان قطع است هم نمایش داده شود.
                </div>

                <div class="ma-settings-card__footer" style="display:flex;flex-wrap:wrap;gap:8px">
                    <button type="button" class="ma-btn ma-btn--primary ma-btn--sm" :disabled="outageBusy" @click="saveOutage">ذخیرهٔ تنظیمات</button>
                    <button type="button" class="ma-btn ma-btn--ghost ma-btn--sm" :disabled="outageBusy" @click="checkOutage">پایش همین حالا</button>
                    <button type="button" class="ma-btn ma-btn--ghost ma-btn--sm" :disabled="outageBusy" @click="toggleOutageManual">{{ outage.manual ? 'لغو حالت دستی' : 'اعلام دستی قطعی' }}</button>
                </div>
            </div>

            <div v-if="iran" class="ma-settings-card ma-lan-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">🛡️</div>
                    <div>
                        <div class="ma-settings-card__title">فقط آی‌پی ایران (مسدودسازی VPN)</div>
                        <div class="ma-settings-card__desc">اگر کسی با VPN یا فیلترشکن (یا از خارج از کشور) وارد شود، سامانه به‌جای فرم ورود یک صفحهٔ راهنما نشان می‌دهد که از او می‌خواهد اول VPN را قطع کند. تشخیص «ایرانی بودن» کاملاً روی سرور و بدون اینترنت انجام می‌شود.</div>
                    </div>
                </div>
                <div class="ma-settings-card__body">
                    <label class="ma-toggle">
                        <input type="checkbox" :checked="iran.enabled" :disabled="iranBusy" @change="iran.enabled = !iran.enabled; saveIran()">
                        <span class="ma-toggle__slider"></span>
                    </label>
                    <span class="ma-lan-state" :class="`ma-lan-state--${iranState.cls}`">
                        <i class="ma-lan-state__dot"></i>{{ iranState.text }}
                    </span>
                </div>
                <div class="ma-lan-status">
                    <div class="ma-lan-status__row">
                        <span>بازه‌های ایران (IPv4 / IPv6):</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(iran.ranges_ipv4 ?? 0) }} / {{ toPersianDigits(iran.ranges_ipv6 ?? 0) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>بازه‌های استثنای دستی:</span>
                        <code class="ma-lan-status__value">{{ iran.extra_ranges ? `${toPersianDigits(iran.extra_ranges)} بازه از ${iran.extra_file || 'فایل استثنا'}` : 'ندارد' }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>نسخهٔ فهرست:</span>
                        <code class="ma-lan-status__value">{{ formatDateTime(iran.list_generated) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>آخرین به‌روزرسانی از منبع:</span>
                        <code class="ma-lan-status__value">{{ formatDateTime(iran.last_refresh) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>ورودهای مسدودشده:</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(iran.blocked_count ?? 0) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>ورودهای مجاز (از فعال‌سازی):</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(iran.allowed_count ?? 0) }}</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>آخرین آی‌پی مسدودشده:</span>
                        <code class="ma-lan-status__value">{{ iran.last_blocked_ip ? `${iran.last_blocked_ip}${iran.last_blocked_at ? ` — ${formatTime(iran.last_blocked_at)}` : ''}` : '—' }}</code>
                    </div>
                </div>
                <p v-if="!iran.list_loaded" class="ma-lan-warning ma-lan-warning--error">
                    <b>هشدار:</b> فایل فهرست آی‌پی ایران خوانده نشد ({{ iran.list_error || 'نامشخص' }}).
                    تا زمانی که این فایل در دسترس نباشد، هیچ ورودی مسدود <b>نمی‌شود</b>؛ برای جلوگیری از قفل شدن کاربران ایرانی، فیلتر در این حالت بی‌اثر می‌ماند.
                    با دکمهٔ «به‌روزرسانی فهرست» (یا اسکریپت بازسازی فهرست روی سرور) آن را بازسازی کنید.
                </p>
                <p v-if="iran.last_refresh_error" class="ma-settings-card__meta" style="margin-top:8px">
                    آخرین به‌روزرسانی با هشدار انجام شد: <code>{{ iran.last_refresh_error }}</code>
                </p>

                <div class="ma-outage-form">
                    <div class="wide"><label for="ma-iran-title">عنوان پیام مسدودی</label><input id="ma-iran-title" v-model="iranForm.title" type="text"></div>
                    <div class="wide"><label for="ma-iran-message">متن پیام مسدودی</label><textarea id="ma-iran-message" v-model="iranForm.message"></textarea></div>
                    <div class="wide"><label for="ma-iran-help">راهنمای قطع VPN (هر مورد در یک خط، روی صفحهٔ هشدار شماره‌گذاری می‌شود)</label><textarea id="ma-iran-help" v-model="iranForm.help_text"></textarea></div>
                    <div class="wide ma-outage-toggles">
                        <label class="ma-toggle-row" style="font-size:.8rem"><span>ثبت هر تلاش مسدودشده در گزارش رویداد</span><input v-model="iranForm.log_blocked" type="checkbox"><i></i></label>
                    </div>
                    <div class="wide">
                        <label for="ma-iran-ip">تست یک آی‌پی (خالی بگذارید تا آی‌پی خودتان بررسی شود)</label>
                        <div class="ma-iran-probe">
                            <input id="ma-iran-ip" v-model="iranProbeIp" type="text" dir="ltr" placeholder="8.8.8.8 یا 2.144.0.1">
                            <button type="button" class="ma-btn ma-btn--ghost ma-btn--sm" :disabled="iranProbeBusy" @click="checkIranIp">بررسی</button>
                        </div>
                        <div class="ma-settings-card__meta" style="margin-top:8px">{{ iranProbeResult }}</div>
                    </div>
                </div>

                <div class="ma-lan-warning">
                    <b>نکته:</b> شبکهٔ داخلی آزمایشگاه، خود سرور (پایش سلامت)، نشست مدیر اصلی و مسیرهای زیرساختی مثل <code>/health</code> و <code>/static/</code>
                    هیچ‌وقت مسدود نمی‌شوند؛ پس این گزینه حتی وقتی روی یک رایانهٔ داخل شبکه هستید هم قابل مدیریت است.
                    کاربرانی که در حال حاضر وصل هستند با قطع شدن VPN بلافاصله مجاز می‌شوند و نیازی به ورود دوباره ندارند.
                </div>

                <div class="ma-settings-card__footer" style="display:flex;flex-wrap:wrap;gap:8px">
                    <button type="button" class="ma-btn ma-btn--primary ma-btn--sm" :disabled="iranBusy" @click="saveIran">ذخیرهٔ تنظیمات</button>
                    <button type="button" class="ma-btn ma-btn--ghost ma-btn--sm" :disabled="iranBusy" @click="refreshIranList">به‌روزرسانی فهرست آی‌پی</button>
                    <button type="button" class="ma-btn ma-btn--ghost ma-btn--sm" :disabled="iranBusy" @click="resetIranCounters">صفر کردن آمار</button>
                </div>
                <div class="ma-settings-card__meta" style="margin-top:8px">به‌روزرسانی فهرست از سرورهای RIPE و APNIC انجام می‌شود (حدود ۳۰ مگابایت دانلود) و فقط با همین دکمه اجرا می‌شود؛ تشخیص آی‌پی هیچ‌وقت به اینترنت نیاز ندارد.</div>
            </div>

            <div v-if="loginUx" class="ma-settings-card ma-lan-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">🚀</div>
                    <div>
                        <div class="ma-settings-card__title">تجربهٔ ورود (لودر و کد امنیتی)</div>
                        <div class="ma-settings-card__desc">با فشردن دکمهٔ ورود، یک لودر تمام‌صفحه با مدت مشخص نمایش داده می‌شود و بعد کاربر به مقصد خودش می‌رود (دیگر خبری از «در حال ورود…» روی دکمه و حالت سبز رنگ نیست). همچنین اگر کد امنیتی منقضی شود، کاربر پیام می‌گیرد که باید کد جدید بگیرد و پیام تا رفرش کردن روی صفحه می‌ماند.</div>
                    </div>
                </div>
                <div class="ma-settings-card__body">
                    <label class="ma-toggle">
                        <input type="checkbox" :checked="loginUxForm.loader_enabled" :disabled="loginUxBusy" @change="loginUxForm.loader_enabled = !loginUxForm.loader_enabled; saveLoginUx()">
                        <span class="ma-toggle__slider"></span>
                    </label>
                    <span class="ma-lan-state" :class="`ma-lan-state--${loginUxState.cls}`">
                        <i class="ma-lan-state__dot"></i>{{ loginUxState.text }}
                    </span>
                </div>
                <div class="ma-lan-status">
                    <div class="ma-lan-status__row">
                        <span>مدت نمایش لودر:</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(loginUx.loader_seconds ?? 0) }} ثانیه</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>اعتبار کد امنیتی:</span>
                        <code class="ma-lan-status__value">{{ toPersianDigits(loginUx.captcha_ttl_seconds ?? 0) }} ثانیه</code>
                    </div>
                    <div class="ma-lan-status__row">
                        <span>هشدار زودهنگام انقضا:</span>
                        <code class="ma-lan-status__value">{{ loginUx.captcha_notice ? `فعال (از ${toPersianDigits(loginUx.captcha_warning_lead_seconds ?? 60)} ثانیهٔ آخر)` : 'غیرفعال' }}</code>
                    </div>
                </div>

                <div class="ma-outage-form">
                    <div><label for="ma-login-loader-seconds">مدت نمایش لودر (ثانیه) — {{ toPersianDigits(loginUx.min_seconds ?? 1) }} تا {{ toPersianDigits(loginUx.max_seconds ?? 15) }}</label><input id="ma-login-loader-seconds" v-model="loginUxForm.loader_seconds" type="number" :min="loginUx.min_seconds ?? 1" :max="loginUx.max_seconds ?? 15"></div>
                    <div><label for="ma-login-captcha-ttl">اعتبار کد امنیتی (ثانیه) — {{ toPersianDigits(loginUx.min_captcha_ttl_seconds ?? 30) }} تا {{ toPersianDigits(loginUx.max_captcha_ttl_seconds ?? 1800) }}</label><input id="ma-login-captcha-ttl" v-model="loginUxForm.captcha_ttl" type="number" :min="loginUx.min_captcha_ttl_seconds ?? 30" :max="loginUx.max_captcha_ttl_seconds ?? 1800"></div>
                    <div class="wide"><label for="ma-login-loader-title">عنوان لودر</label><input id="ma-login-loader-title" v-model="loginUxForm.loader_title" type="text"></div>
                    <div class="wide"><label for="ma-login-loader-message">متن زیر عنوان لودر</label><textarea id="ma-login-loader-message" v-model="loginUxForm.loader_message"></textarea></div>
                    <div class="wide ma-outage-toggles">
                        <label class="ma-toggle-row" style="font-size:.8rem"><span>هشدار زودهنگام و پیام انقضای کد امنیتی</span><input v-model="loginUxForm.captcha_notice" type="checkbox"><i></i></label>
                    </div>
                </div>

                <div class="ma-lan-warning">
                    <b>نکته:</b> اگر کد امنیتی منقضی شود، دیگر با کد مرده درخواستی به سرور فرستاده نمی‌شود؛ پیام «کد امنیتی منقضی شده است»
                    نمایش داده می‌شود و دکمهٔ «کد جدید» می‌درخشد. با کم کردن مدت اعتبار، امنیت افزایش می‌یابد و با زیاد کردن آن، فرصت بیشتری
                    برای وارد کردن کد (مثلاً روی موبایل) فراهم می‌شود. اگر لودر را خاموش کنید، ورود بی‌درنگ انجام می‌شود.
                </div>

                <div class="ma-settings-card__footer" style="display:flex;flex-wrap:wrap;gap:8px">
                    <button type="button" class="ma-btn ma-btn--primary ma-btn--sm" :disabled="loginUxBusy" @click="saveLoginUx">ذخیرهٔ تنظیمات</button>
                </div>
                <div class="ma-settings-card__meta" style="margin-top:8px">تغییرات بی‌درنگ روی صفحهٔ ورود اعمال می‌شوند (بدون ری‌استارت). اگر مرورگر نسخهٔ قدیمی صفحه را کش کرده باشد، یک رفرش کافی است.</div>
            </div>

            <div v-if="printers" class="ma-settings-card">
                <div class="ma-settings-card__header">
                    <div class="ma-settings-card__icon">🖨️</div>
                    <div>
                        <div class="ma-settings-card__title">چاپگرهای سرور</div>
                        <div class="ma-settings-card__desc">چاپگرهای نصب‌شده روی سرور؛ صفحهٔ طراحی لیبل هم همین فهرست را می‌خواند. وب‌یوز/وب‌سریال نمی‌توانند چاپگر شبکه را ببینند، پس فهرست از اسپولر همین سرور خوانده می‌شود.</div>
                    </div>
                </div>

                <div v-if="!printers.enumerated" class="ma-empty">
                    <div class="ma-empty__text">چاپگری روی سرور شناسایی نشد.</div>
                </div>

                <div v-else class="ma-printer-list" role="list" aria-live="polite">
                    <button
                        v-for="printer in printers.printers"
                        :key="printer.name"
                        type="button"
                        role="listitem"
                        class="ma-printer-row"
                        :title="printer.driver || printer.name"
                    >
                        <span class="ma-printer-row__dot" :data-state="printer.status"></span>
                        <span class="ma-printer-row__body">
                            <span class="ma-printer-row__name">{{ printer.name }}</span>
                            <span class="ma-printer-row__meta">{{ printerMeta(printer) }}</span>
                        </span>
                        <span class="ma-printer-row__badges">
                            <span v-if="printer.is_label" class="ma-printer-badge ma-printer-badge--label">لیبل</span>
                            <span v-if="printer.is_default" class="ma-printer-badge ma-printer-badge--default">پیش‌فرض</span>
                            <span v-if="printer.is_virtual" class="ma-printer-badge ma-printer-badge--virtual">مجازی</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <div style="margin-top:24px;padding:16px;background:rgba(99,102,241,.06);border-radius:12px;border:1px solid rgba(99,102,241,.12)">
            <div style="font-size:.85rem;color:#6366f1;font-weight:600;margin-bottom:6px">💡 راهنما</div>
            <ul style="font-size:.82rem;color:#64748b;margin:0;padding-inline-start:18px;line-height:1.8">
                <li>غیرفعال کردن کپچا: کاربران فقط با نام کاربری و رمز عبور وارد می‌شوند (امنیت کمتر).</li>
                <li>غیرفعال کردن خروج خودکار: کاربران تا زمانی که خودشان خارج شوند، در سامانه می‌مانند.</li>
                <li>زمان بیکاری: حداقل ۱۰ ثانیه، حداکثر ۸۶۴۰۰ ثانیه (۲۴ ساعت).</li>
            </ul>
        </div>
    </div>
</template>
