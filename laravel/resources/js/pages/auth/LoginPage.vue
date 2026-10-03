<script setup>
/**
 * The login page — the Vue equivalent of `app/templates/login.html`.
 *
 * The template is the legacy markup element for element: the same divs,
 * the same class names, the same order, the same SVG icons and the same
 * Persian placeholders and labels.  The stylesheet that styles it
 * (`resources/css/legacy/login-style.css`, ported verbatim from
 * `app/static/css/login-style.css`) is already loaded globally, so this
 * component carries no styles of its own.
 *
 * The flow is the running application's, in the running application's order:
 *
 *   username + password (+ CAPTCHA when the operator enabled it)
 *       ↓  POST /login_user
 *   the backend validates the CAPTCHA first (so the endpoint cannot be used
 *   as a password oracle), then the credentials, then sets the session
 *       ↓
 *   GET /api/me pulls the identity the router and the guards need
 *       ↓
 *   the browser is sent to the panel the role owns
 *
 * The CAPTCHA is fetched from `GET /captcha` (a PNG) and refreshed through
 * `POST /captcha/refresh`; `GET /captcha/status` drives the countdown hint
 * and the expired state.  The code itself never leaves the session.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useTheme } from '@/composables/useTheme';

const router = useRouter();
const auth = useAuthStore();
const { toggleTheme } = useTheme();

/*
 * The logo lives in public/ because it is a same-origin local asset, not a
 * bundled module.  It is bound rather than written as a literal src so that
 * Vue's template asset rewriting does not try to resolve it through the
 * bundler at build time.  Dark mode swaps it through the legacy rule
 * `body.dark-mode .login-logo { content: url('/images/login-mobile-logo-dark.png'); }`.
 */
const logoUrl = '/images/login-mobile-logo.png';

const username = ref('');
const password = ref('');
const captcha = ref('');
const remember = ref(false);
const passwordVisible = ref(false);
const captchaImage = ref('');
const captchaEnabled = ref(false);
const captchaNotice = ref(true);
const captchaRemaining = ref(0);
const captchaWarningLead = ref(60);
const captchaExpired = ref(false);
const captchaSpinning = ref(false);
const captchaShaking = ref(false);
const usernameError = ref('');
const passwordError = ref('');
const captchaError = ref('');
const error = ref('');
const cardShaking = ref(false);

const loginCard = ref(null);
const captchaGroupEl = ref(null);

/* ── CAPTCHA lifetime (ported from js/script.js) ─────────────────────
   The status poll is what warns the user while they are still typing
   instead of letting them submit a code that expired a minute ago. */

const EXPIRED_MESSAGE = 'کد امنیتی منقضی شده است؛ لطفاً روی «کد جدید» بزنید و دوباره وارد شوید.';

let captchaWatchTimer = null;
let captchaPolling = false;

const captchaStatusText = computed(() => {
    if (!captchaEnabled.value || !captchaNotice.value) return '';
    if (captchaRemaining.value <= 0 || captchaRemaining.value > captchaWarningLead.value) return '';
    return `اعتبار کد امنیتی: ${captchaRemaining.value} ثانیه`;
});

async function pollCaptchaStatus() {
    if (!captchaEnabled.value || captchaPolling) return;
    captchaPolling = true;
    try {
        const response = await api.get('/captcha/status', { baseURL: '' });
        if (!response || response.success !== true) return;
        captchaRemaining.value = response.remaining_seconds ?? 0;
        captchaWarningLead.value = response.warning_lead_seconds || 60;
        if (response.has_captcha && response.remaining_seconds <= 0) {
            if (!captchaExpired.value) {
                captchaExpired.value = true;
                captchaError.value = EXPIRED_MESSAGE;
            }
            return;
        }
        if (response.valid) {
            /* A refresh (here or in another tab) gives the code a new life. */
            captchaExpired.value = false;
        }
    } catch {
        /* offline: the server stays the authority */
    } finally {
        captchaPolling = false;
    }
}

function startCaptchaWatch() {
    stopCaptchaWatch();
    if (!captchaEnabled.value) return;
    pollCaptchaStatus();
    captchaWatchTimer = setInterval(pollCaptchaStatus, 5000);
}

function stopCaptchaWatch() {
    if (captchaWatchTimer) {
        clearInterval(captchaWatchTimer);
        captchaWatchTimer = null;
    }
}

/* ── Public settings (master-admin → system settings) ──────────────── */

const loaderEnabled = ref(true);
const loaderSeconds = ref(3);
const loaderTitle = ref('در حال آماده‌سازی میزکار شما…');
const loaderMessage = ref('لطفاً چند لحظه صبر کنید؛ در حال ورود به سامانه هستما.');

/**
 * The public settings the login screen needs.  `captcha_enabled` is a
 * string ('1'/'0') because the legacy endpoint returns every value as a
 * string and the old client compared it that way.
 */
async function loadConfig() {
    try {
        const response = await api.get('/system-config');
        const data = response.data ?? {};
        captchaEnabled.value = data.captcha_enabled === '1' || data.captcha_enabled === 'true';
        captchaNotice.value = data.login_captcha_notice !== '0';
        loaderEnabled.value = data.login_loader_enabled === '1' || data.login_loader_enabled === 'true';
        loaderSeconds.value = Math.min(15, Math.max(1, Number(data.login_loader_seconds) || 3));
        loaderTitle.value = data.login_loader_title || 'در حال آماده‌سازی میزکار شما…';
        loaderMessage.value = data.login_loader_message || 'لطفاً چند لحظه صبر کنید؛ در حال ورود به سامانه هستما.';
    } catch {
        // A settings outage must not block the login form; the backend fails
        // the CAPTCHA closed if it cannot read the setting.
        captchaEnabled.value = false;
    }
}

    /*
     * The CAPTCHA image is loaded the way the Python page loads it: a direct
     * `src` on `/captcha` with a cache-busting timestamp.  The blob/object-URL
     * approach was tried first and the image did not render reliably; the
     * direct src is what `js/script.js` does (`img.src = '/captcha?t=' + …`),
     * and it also means the browser sends the session cookie on the image
     * request, which is what the CAPTCHA is bound to.
     */
    function loadCaptcha() {
        if (!captchaEnabled.value) return;
        captchaImage.value = '/captcha?t=' + Date.now();
    }

    async function refreshCaptcha() {
        captchaSpinning.value = true;
        try {
            await api.post('/captcha/refresh', {}, { baseURL: '' });
        } catch {
            // A refresh failure must not blank the image: the old code is still
            // there until the timestamp changes it.
        } finally {
            loadCaptcha();
            setTimeout(() => { captchaSpinning.value = false; }, 500);
            captchaExpired.value = false;
            captchaShaking.value = false;
            captchaError.value = '';
            pollCaptchaStatus();
        }
    }

/* ── The login loader (ported from js/script.js) ──────────────────────
   The button never becomes «در حال ورود…» and never turns green: the
   feedback is this full-screen loader, shown for a minimum of
   `login_loader_seconds` (a floor, never a delay). */

const LOADER_STEPS = [
    'در حال بررسی اطلاعات ورود…',
    'در حال آماده‌سازی میزکار شما…',
    'در حال دریافت دسترسی‌ها…',
    'لطفاً کمی بیشتر صبر کنید…',
];

const loaderVisible = ref(false);
const loaderDone = ref(false);
const loaderStep = ref('');
let loaderTicker = null;

const loaderTitleText = computed(() => (username.value ? `خوش آمدید، ${username.value}` : loaderTitle.value));

function showLoader() {
    loaderVisible.value = true;
    loaderDone.value = false;
    loaderStep.value = LOADER_STEPS[0];
    clearInterval(loaderTicker);
    let index = 0;
    loaderTicker = setInterval(() => {
        index = (index + 1) % LOADER_STEPS.length;
        loaderStep.value = LOADER_STEPS[index];
    }, 1200);
}

function loaderSuccess() {
    clearInterval(loaderTicker);
    loaderTicker = null;
    loaderDone.value = true;
    loaderStep.value = 'ورود موفق؛ در حال انتقال به میزکار…';
}

function hideLoader() {
    clearInterval(loaderTicker);
    loaderTicker = null;
    loaderVisible.value = false;
}

function waitLoaderMinimum(started) {
    const minimum = loaderEnabled.value ? loaderSeconds.value * 1000 : 0;
    const left = minimum - (Date.now() - started);
    return left > 0 ? new Promise((resolve) => setTimeout(resolve, left)) : Promise.resolve();
}

/* ── Form ──────────────────────────────────────────────────────────── */

function shakeCard() {
    cardShaking.value = false;
    if (loginCard.value) void loginCard.value.offsetWidth;
    cardShaking.value = true;
}

function captchaShake() {
    captchaShaking.value = false;
    if (captchaGroupEl.value) void captchaGroupEl.value.offsetWidth;
    captchaShaking.value = true;
}

function togglePassword() {
    passwordVisible.value = !passwordVisible.value;
}

/* Button ripple effect (visual only) */
function onLoginRipple(event) {
    const button = event.currentTarget;
    const rect = button.getBoundingClientRect();
    const ink = document.createElement('span');
    const size = Math.max(rect.width, rect.height);
    ink.className = 'ripple-ink';
    ink.style.width = ink.style.height = `${size}px`;
    ink.style.left = `${event.clientX - rect.left - size / 2}px`;
    ink.style.top = `${event.clientY - rect.top - size / 2}px`;
    button.appendChild(ink);
    setTimeout(() => {
        if (ink.parentNode) ink.parentNode.removeChild(ink);
    }, 650);
}

async function submit() {
    usernameError.value = '';
    passwordError.value = '';
    captchaError.value = '';
    error.value = '';

    if (!username.value.trim() || !password.value) {
        if (!username.value.trim()) usernameError.value = 'لطفاً نام کاربری را وارد کنید';
        if (!password.value) passwordError.value = 'لطفاً رمز عبور را وارد کنید';
        error.value = 'لطفاً نام کاربری و رمز عبور را وارد کنید.';
        shakeCard();
        return;
    }

    if (captchaEnabled.value) {
        if (!captcha.value.trim()) {
            captchaError.value = 'لطفاً کد امنیتی را وارد کنید.';
            captchaShake();
            return;
        }
        if (captchaExpired.value) {
            /* The code is already known to be dead: say so at once instead of
               spending a request (and the loader) on it. */
            captchaError.value = EXPIRED_MESSAGE;
            captchaShake();
            return;
        }
    }

    const started = Date.now();
    if (loaderEnabled.value) showLoader();

    try {
        const user = await auth.login({
            username: username.value,
            password: password.value,
            captcha: captcha.value,
        });

        if (loaderEnabled.value) loaderSuccess();
        await waitLoaderMinimum(started);
        if (loaderEnabled.value) hideLoader();

        /* The panel the role owns.  A master admin also satisfies the admin
           guard, so the admin panel is reachable — but the control centre is
           where a master admin is expected. */
        if (user?.is_master_admin) {
            router.push('/master-admin');
        } else if (user?.is_admin) {
            router.push('/admin/dashboard');
        } else {
            router.push('/user_panel');
        }
    } catch (failure) {
        await waitLoaderMinimum(started);
        if (loaderEnabled.value) hideLoader();

        error.value = failure.apiFailure?.message || failure.message || 'ورود ناموفق بود.';
        captcha.value = '';
        shakeCard();
        await loadCaptcha();
    }
}

/* ── Forgot password modal ──────────────────────────────────────────── */

const fpOpen = ref(false);
const fpUsername = ref('');
const fpHint = ref('');
const fpHintType = ref('');
const fpLoading = ref(false);
const fpUsernameInput = ref(null);

function openForgotModal() {
    fpOpen.value = true;
    fpUsername.value = '';
    fpHint.value = '';
    fpHintType.value = '';
    setTimeout(() => fpUsernameInput.value?.focus(), 200);
}

async function submitForgot() {
    const usernameValue = fpUsername.value.trim();
    if (!usernameValue) {
        fpHint.value = 'لطفاً نام کاربری را وارد کنید.';
        fpHintType.value = 'error';
        return;
    }
    fpLoading.value = true;
    fpHint.value = '';
    try {
        const response = await api.post('/forgot_password', { username: usernameValue }, { baseURL: '' });
        let message = response.message || 'درخواست شما ثبت شد.';
        if (response.request_id) {
            message += `\n\nشناسه درخواست شما:\n${response.request_id}\n\nاین شناسه را برای مرحله بعدی ذخیره کنید.`;
        }
        fpHint.value = message;
        fpHintType.value = 'success';
        fpUsername.value = '';
        setTimeout(() => { fpOpen.value = false; }, 8000);
    } catch (failure) {
        fpHint.value = failure.apiFailure?.message || failure.message || 'خطا در اتصال به سرور.';
        fpHintType.value = 'error';
    } finally {
        fpLoading.value = false;
    }
}

/* ── Reset password modal ──────────────────────────────────────────── */

const rpOpen = ref(false);
const rpRequestId = ref('');
const rpCode = ref('');
const rpNewPassword = ref('');
const rpNewPassword2 = ref('');
const rpHint = ref('');
const rpHintType = ref('');
const rpLoading = ref(false);

const rpReqs = [
    { test: (p) => p.length >= 8, label: 'حداقل ۸ کاراکتر' },
    { test: (p) => /[A-Z]/.test(p), label: 'حروف بزرگ' },
    { test: (p) => /[a-z]/.test(p), label: 'حروف کوچک' },
    { test: (p) => /[0-9]/.test(p), label: 'عدد' },
    { test: (p) => /[^A-Za-z0-9]/.test(p), label: 'کاراکتر خاص' },
];

const rpScore = computed(() => rpReqs.filter((req) => req.test(rpNewPassword.value)).length);

const rpStrength = computed(() => {
    const score = rpScore.value;
    if (score <= 1) return { label: 'خیلی ضعیف', color: '#dc2626', width: '15%' };
    if (score === 2) return { label: 'ضعیف', color: '#f97316', width: '35%' };
    if (score === 3) return { label: 'متوسط', color: '#eab308', width: '55%' };
    if (score === 4) return { label: 'خوب', color: '#22c55e', width: '75%' };
    return { label: 'قوی ✓', color: '#10b981', width: '100%' };
});

const rpMatchText = computed(() => {
    if (!rpNewPassword2.value) return '';
    return rpNewPassword.value === rpNewPassword2.value ? '✓ مطابقت دارد' : '✕ مطابقت ندارد';
});

const rpMatchColor = computed(() => {
    if (!rpNewPassword2.value) return '';
    return rpNewPassword.value === rpNewPassword2.value ? '#059669' : '#dc2626';
});

function openResetModal() {
    fpOpen.value = false;
    rpOpen.value = true;
    rpHint.value = '';
    rpHintType.value = '';
}

async function submitReset() {
    const requestId = rpRequestId.value.trim();
    const code = rpCode.value.trim();
    const pw = rpNewPassword.value;
    const pw2 = rpNewPassword2.value;

    if (!requestId || !code || !pw || !pw2) {
        rpHint.value = 'لطفاً تمام فیلدها را پر کنید.';
        rpHintType.value = 'error';
        return;
    }
    if (pw !== pw2) {
        rpHint.value = 'رمز عبور و تکرار آن یکسان نیستند.';
        rpHintType.value = 'error';
        return;
    }
    if (pw.length < 8) {
        rpHint.value = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        rpHintType.value = 'error';
        return;
    }
    if (!/[A-Z]/.test(pw) || !/[a-z]/.test(pw) || !/[0-9]/.test(pw) || !/[^A-Za-z0-9]/.test(pw)) {
        rpHint.value = 'رمز عبور باید شامل حروف بزرگ، کوچک، عدد و کاراکتر خاص باشد.';
        rpHintType.value = 'error';
        return;
    }

    rpLoading.value = true;
    rpHint.value = '';
    try {
        const response = await api.post('/reset_password', { request_id: requestId, code, new_password: pw }, { baseURL: '' });
        rpHint.value = response.message || 'رمز عبور تغییر کرد.';
        rpHintType.value = 'success';
        rpRequestId.value = '';
        rpCode.value = '';
        rpNewPassword.value = '';
        rpNewPassword2.value = '';
        setTimeout(() => { rpOpen.value = false; }, 2500);
    } catch (failure) {
        rpHint.value = failure.apiFailure?.message || failure.message || 'خطا در تغییر رمز.';
        rpHintType.value = 'error';
    } finally {
        rpLoading.value = false;
    }
}

/* ── Support ticket modal ──────────────────────────────────────────── */

const stOpen = ref(false);
const stFullName = ref('');
const stPhone = ref('');
const stDesc = ref('');
const stHint = ref('');
const stHintType = ref('');
const stLoading = ref(false);
const stFullNameInput = ref(null);

function openSupportModal() {
    stOpen.value = true;
    stFullName.value = '';
    stPhone.value = '';
    stDesc.value = '';
    stHint.value = '';
    stHintType.value = '';
    setTimeout(() => stFullNameInput.value?.focus(), 200);
}

async function submitSupport() {
    const fullName = stFullName.value.trim();
    const phone = stPhone.value.trim();
    const desc = stDesc.value.trim();

    if (!fullName) {
        stHint.value = 'لطفاً نام و نام خانوادگی را وارد کنید.';
        stHintType.value = 'error';
        stFullNameInput.value?.focus();
        return;
    }
    if (!phone) {
        stHint.value = 'لطفاً شماره تماس را وارد کنید.';
        stHintType.value = 'error';
        return;
    }
    if (!/^0[0-9]{9,10}$/.test(phone.replace(/[\s\-]/g, ''))) {
        stHint.value = 'شماره تماس نامعتبر است. مثال: 09121234567';
        stHintType.value = 'error';
        return;
    }

    stLoading.value = true;
    stHint.value = '';
    try {
        const response = await api.post('/public/support-ticket', { full_name: fullName, phone, description: desc }, { baseURL: '' });
        stHint.value = response.message || 'درخواست شما ثبت شد.';
        stHintType.value = 'success';
        stFullName.value = '';
        stPhone.value = '';
        stDesc.value = '';
        setTimeout(() => { stOpen.value = false; }, 5000);
    } catch (failure) {
        stHint.value = failure.apiFailure?.message || failure.message || 'خطا در ثبت درخواست.';
        stHintType.value = 'error';
    } finally {
        stLoading.value = false;
    }
}

/* ── Lifecycle ─────────────────────────────────────────────────────── */

function onKeydown(event) {
    if (event.key !== 'Escape') return;
    if (fpOpen.value) fpOpen.value = false;
    if (rpOpen.value) rpOpen.value = false;
    if (stOpen.value) stOpen.value = false;
}

onMounted(async () => {
    await loadConfig();
    await loadCaptcha();
    startCaptchaWatch();
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    stopCaptchaWatch();
    clearInterval(loaderTicker);
    if (captchaImage.value?.startsWith('blob:')) {
        URL.revokeObjectURL(captchaImage.value);
    }
});
</script>

<template>
    <!-- لایه‌های پس‌زمینه: گرادیان، هاله‌ها، شبکه، ذرات -->
    <div class="bg-scene" aria-hidden="true">
        <div class="bg-gradient"></div>
        <div class="bg-orb bg-orb--a"></div>
        <div class="bg-orb bg-orb--b"></div>
        <div class="bg-orb bg-orb--c"></div>
        <div class="bg-grid"></div>
        <div class="bg-noise"></div>
        <div class="bg-beam"></div>
        <div class="bg-particles">
            <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
            <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
        </div>
        <div class="bg-rings">
            <span></span><span></span><span></span>
        </div>
    </div>

    <!-- دکمه‌های شناور بالا -->
    <div class="top-actions">
        <div class="tooltip-wrap">
            <button type="button" class="theme-toggle" data-action="toggle-theme" aria-label="تغییر تم" @click="toggleTheme">
                <span class="theme-toggle-moon">🌙</span>
                <span class="theme-toggle-sun">☀️</span>
            </button>
            <span class="tooltip">تغییر تم</span>
        </div>
        <div class="tooltip-wrap">
            <a href="/training?from_login=true" class="support-toggle" aria-label="آموزش سامانه">
                <span>🎓</span>
            </a>
            <span class="tooltip">آموزش سامانه</span>
        </div>
        <div class="tooltip-wrap">
            <a href="#" class="support-toggle" aria-label="پشتیبانی" id="supportToggle" @click.prevent="openSupportModal">
                <span>🎧</span>
            </a>
            <span class="tooltip">پشتیبانی</span>
        </div>
    </div>

    <main class="login-shell">
        <!-- سمت برند: معرفی محصول -->
        <section class="brand-side" aria-hidden="true">
            <div class="brand-side__glow"></div>

            <div class="brand-side__logo">
                <img :src="logoUrl" alt="" class="brand-logo-img">
            </div>

            <h1 class="brand-side__title">مدیریت هوشمند<br>ساده، سریع، مطمئن</h1>
            <p class="brand-side__desc">سامانه یکپارچه حضور و غیاب، حقوق و مدیریت تیم؛ همه‌چیز در یک تجربهٔ مدرن.</p>

            <ul class="brand-side__features">
                <li>
                    <span class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <span class="feature-text"><strong>ثبت لحظه‌ای تردد</strong><small>گزارش دقیق ورود و خروج تیم</small></span>
                </li>
                <li>
                    <span class="feature-icon feature-icon--teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </span>
                    <span class="feature-text"><strong>داشبورد زندهٔ مدیریت</strong><small>تصمیم‌گیری با داده‌های لحظه‌ای</small></span>
                </li>
                <li>
                    <span class="feature-icon feature-icon--violet">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <span class="feature-text"><strong>امنیت سازمانی</strong><small>اطلاعات شما، همیشه محفوظ</small></span>
                </li>
            </ul>

            <div class="brand-side__chips" aria-hidden="true">
                <span class="chip chip--sky">✦ سریع</span>
                <span class="chip chip--teal">⚡ هوشمند</span>
                <span class="chip chip--violet">🔒 امن</span>
            </div>
        </section>

        <!-- سمت فرم: کارت ورود شیشه‌ای -->
        <section class="form-side">
            <div class="login-card" id="loginCard" ref="loginCard" :class="{ shake: cardShaking }">
                <div class="card-sheen" aria-hidden="true"></div>

                <div class="brand-row">
                    <div class="brand">
                        <img class="login-logo" :src="logoUrl" alt="لوگوی هستما">
                    </div>
                </div>

                <div class="login-header">
                    <h2>خوش آمدید</h2>
                    <p>برای دسترسی به داشبورد اطلاعات خود را وارد کنید.</p>
                </div>

                <form class="login-form" novalidate @submit.prevent="submit">
                    <div class="form-group">
                        <div class="input-wrapper" :class="{ 'has-error': usernameError }">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            <input type="text" id="username" name="username" required autocomplete="username" placeholder="نام کاربری" v-model="username" @input="usernameError = ''">
                            <label for="username">نام کاربری</label>
                            <span class="focus-border"></span>
                        </div>
                        <p class="field-error" id="usernameError" role="alert">{{ usernameError }}</p>
                    </div>

                    <div class="form-group">
                        <div class="input-wrapper" :class="{ 'has-error': passwordError }">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input :type="passwordVisible ? 'text' : 'password'" id="password" name="password" required autocomplete="current-password" placeholder="رمز عبور" v-model="password" @input="passwordError = ''">
                            <label for="password">رمز عبور</label>
                            <button type="button" class="pw-toggle" id="pwToggle" :aria-label="passwordVisible ? 'پنهان کردن رمز عبور' : 'نمایش رمز عبور'" :aria-pressed="passwordVisible" :class="{ 'is-active': passwordVisible }" @click="togglePassword">
                                <svg class="pw-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="pw-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                            <span class="focus-border"></span>
                        </div>
                        <p class="field-error" id="passwordError" role="alert">{{ passwordError }}</p>
                    </div>

                    <!-- CAPTCHA Section (hidden by default, shown if captcha_enabled -->
                    <div class="form-group captcha-group" id="captchaGroup" ref="captchaGroupEl" v-show="captchaEnabled" :class="{ 'captcha-expired': captchaExpired, 'captcha-shake': captchaShaking }">
                        <div class="captcha-wrapper">
                            <div class="captcha-display">
                                <img v-if="captchaImage" id="captchaImage" :src="captchaImage" alt="کد امنیتی" class="captcha-img" @click="refreshCaptcha">
                                <button type="button" class="captcha-refresh" id="captchaRefresh" aria-label="دریافت کد جدید" title="کد جدید" :class="{ spinning: captchaSpinning }" @click="refreshCaptcha">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                </button>
                            </div>
                            <div class="captcha-input-wrapper">
                                <span class="input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <input type="text" id="captcha" name="captcha" class="captcha-input" placeholder="کد امنیتی را وارد کنید" autocomplete="off" maxlength="8" dir="ltr" style="text-align:center;font-family:monospace;font-size:1rem;letter-spacing:3px" v-model="captcha">
                                <span class="focus-border"></span>
                            </div>
                        </div>
                        <p class="field-error" id="captchaError" role="alert">{{ captchaError }}</p>
                        <p v-if="captchaStatusText" id="captchaHint" class="captcha-hint" role="status" aria-live="polite">{{ captchaStatusText }}</p>
                    </div>

                    <div class="form-footer">
                        <label class="remember-wrapper">
                            <input type="checkbox" id="remember" name="remember" v-model="remember">
                            <span class="checkbox-label">
                                <span class="checkmark"></span>
                                مرا به خاطر بسپار
                            </span>
                        </label>
                        <a href="#" class="forgot-password" id="forgotPasswordBtn" @click.prevent="openForgotModal">فراموشی رمز عبور؟</a>
                    </div>

                    <!-- The button carries one label and nothing else: no busy caption,
                         no green success state.  The feedback is the full-screen
                         loader built by js/script.js (see login_experience.py). -->
                    <button type="button" class="btn h-ux-btn" id="loginBtn" @click="submit" @pointerdown="onLoginRipple">
                        <span class="h-ux-btn-text">ورود</span>
                    </button>

                    <p class="login-hint" id="loginHint" role="status" aria-live="polite">{{ error }}</p>
                </form>
            </div>

            <p class="form-side__note">با ورود، <a href="/rules">قوانین و مقررات</a> سامانه را می‌پذیرید.</p>
            <p class="form-side__note form-side__note--register">حساب کاربری ندارید؟ <a href="/register">ثبت نام کنید</a></p>
        </section>
    </main>

    <footer class="login-footer">
        محصولی از شرکت هنر افزار ایرانیان - نسخه آزمایشی
    </footer>

    <!-- فراموشی رمز عبور - مودال -->
    <div class="fp-overlay" id="fpOverlay" :class="{ 'is-visible': fpOpen }" @click.self="fpOpen = false">
        <div class="fp-modal">
            <button type="button" class="fp-close" id="fpClose" aria-label="بستن" @click="fpOpen = false">&times;</button>
            <div class="fp-icon">🔑</div>
            <h3 class="fp-title">بازیابی رمز عبور</h3>
            <p class="fp-desc">نام کاربری خود را وارد کنید. درخواست شما برای مدیر سامانه ارسال می‌شود و پس از تأیید، رمز جدید دریافت خواهید کرد.</p>
            <form id="fpForm" class="fp-form" @submit.prevent="submitForgot">
                <input ref="fpUsernameInput" type="text" id="fpUsername" class="fp-input" placeholder="نام کاربری" autocomplete="username" required v-model="fpUsername">
                <button type="submit" class="fp-submit" id="fpSubmit" :disabled="fpLoading">{{ fpLoading ? 'در حال ارسال...' : 'ارسال درخواست' }}</button>
            </form>
            <p class="fp-hint" id="fpHint" :class="fpHintType ? 'fp-hint--' + fpHintType : ''" style="white-space:pre-line">{{ fpHint }}</p>
            <div class="fp-divider"><span>یا</span></div>
            <button type="button" class="fp-link-btn" id="fpShowReset" @click="openResetModal">کد بازیابی دارم؟ وارد کنم</button>
        </div>
    </div>

    <!-- ورود کد بازیابی و رمز جدید - مودال -->
    <div class="fp-overlay" id="rpOverlay" :class="{ 'is-visible': rpOpen }" @click.self="rpOpen = false">
        <div class="fp-modal">
            <button type="button" class="fp-close" id="rpClose" aria-label="بستن" @click="rpOpen = false">&times;</button>
            <div class="fp-icon">✅</div>
            <h3 class="fp-title">تغییر رمز عبور</h3>
            <p class="fp-desc">کد بازیابی که از مدیر سامانه دریافت کرده‌اید و رمز عبور جدید خود را وارد کنید.</p>
            <form id="rpForm" class="fp-form" @submit.prevent="submitReset">
                <input type="text" id="rpRequestId" class="fp-input" placeholder="شناسه درخواست (request_id)" required maxlength="32" v-model="rpRequestId">
                <input type="text" id="rpCode" class="fp-input" placeholder="کد بازیابی ۸ کاراکتری" maxlength="8" dir="ltr" style="text-align:center;font-family:monospace;font-size:1.1rem;letter-spacing:2px" required v-model="rpCode">
                <input type="password" id="rpNewPassword" class="fp-input" placeholder="رمز عبور جدید (حداقل ۸ کاراکتر)" autocomplete="new-password" required maxlength="128" v-model="rpNewPassword">
                <div class="pw-strength" id="rpPwStrength" v-if="rpNewPassword">
                    <div class="pw-strength__bar"><div class="pw-strength__fill" id="rpPwFill" :style="{ width: rpStrength.width, background: rpStrength.color }"></div></div>
                    <div class="pw-strength__label" id="rpPwLabel" :style="{ color: rpStrength.color }">{{ rpStrength.label }}</div>
                </div>
                <div class="pw-requirements" id="rpPwReqs" v-if="rpNewPassword">
                    <span v-for="(req, index) in rpReqs" :key="index" class="pw-req" :class="{ met: req.test(rpNewPassword) }">{{ req.test(rpNewPassword) ? '✓' : '✕' }} {{ req.label }}</span>
                </div>
                <input type="password" id="rpNewPassword2" class="fp-input" placeholder="تکرار رمز عبور جدید" autocomplete="new-password" required maxlength="128" v-model="rpNewPassword2">
                <div class="fp-hint" id="rpPwMatch" :style="{ fontSize: '0.75rem', marginTop: '4px', color: rpMatchColor }">{{ rpMatchText }}</div>
                <button type="submit" class="fp-submit" id="rpSubmit" :disabled="rpLoading">{{ rpLoading ? 'در حال تغییر...' : 'تغییر رمز عبور' }}</button>
            </form>
            <p class="fp-hint" id="rpHint" :class="rpHintType ? 'fp-hint--' + rpHintType : ''">{{ rpHint }}</p>
        </div>
    </div>

    <!-- درخواست پشتیبانی (فراموشی نام کاربری و رمز) - مودال -->
    <div class="fp-overlay" id="stOverlay" :class="{ 'is-visible': stOpen }" @click.self="stOpen = false">
        <div class="fp-modal">
            <button type="button" class="fp-close" id="stClose" aria-label="بستن" @click="stOpen = false">&times;</button>
            <div class="fp-icon">🎧</div>
            <h3 class="fp-title">درخواست پشتیبانی</h3>
            <p class="fp-desc">نام کاربری و رمز عبور خود را فراموش کرده‌اید؟ فرم زیر را پر کنید تا مدیر سامانه اطلاعات ورود جدیدی برای شما ایجاد کند.</p>
            <form id="stForm" class="fp-form" @submit.prevent="submitSupport">
                <input ref="stFullNameInput" type="text" id="stFullName" class="fp-input" placeholder="نام و نام خانوادگی" required maxlength="200" v-model="stFullName">
                <input type="tel" id="stPhone" class="fp-input" placeholder="شماره تماس (مثال: 09121234567)" required maxlength="20" dir="ltr" style="text-align:center" v-model="stPhone">
                <textarea id="stDesc" class="fp-input" placeholder="توضیحات (اختیاری) — مثلاً نام دپارتمان یا سمت شما" rows="3" maxlength="2000" style="resize:vertical;min-height:70px" v-model="stDesc"></textarea>
                <button type="submit" class="fp-submit" id="stSubmit" :disabled="stLoading">{{ stLoading ? 'در حال ارسال...' : 'ارسال درخواست به مدیر سامانه' }}</button>
            </form>
            <p class="fp-hint" id="stHint" :class="stHintType ? 'fp-hint--' + stHintType : ''">{{ stHint }}</p>
        </div>
    </div>

    <!-- لودر ورود تمام‌صفحه -->
    <div v-if="loaderVisible" class="login-loader" id="loginLoader" :class="{ 'login-loader--done': loaderDone }" role="status" aria-live="polite" :aria-label="loaderTitle">
        <div class="login-loader__card">
            <div class="login-loader__ring">
                <span class="login-loader__ring-track"></span>
                <span class="login-loader__ring-spin"></span>
                <span class="login-loader__mark"></span>
            </div>
            <div class="login-loader__title">{{ loaderTitleText }}</div>
            <div class="login-loader__message">{{ loaderMessage }}</div>
            <div class="login-loader__bar"><i :style="{ animationDuration: loaderSeconds * 1000 + 'ms' }"></i></div>
            <div class="login-loader__step">{{ loaderStep }}</div>
        </div>
    </div>
</template>
