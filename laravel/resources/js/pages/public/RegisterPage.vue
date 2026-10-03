<script setup>
/**
 * The self-registration page — the Vue equivalent of `app/templates/register.html`.
 *
 * The template is the legacy markup element for element: the same divs, the same
 * class names, the same order, the same SVG icons and the same Persian labels.
 * The stylesheet that styles it (`resources/css/legacy/login-style.css`, ported
 * verbatim from `app/static/css/login-style.css`) is already loaded globally,
 * so this component carries only the register page's own inline rules (the
 * `.register-*` / `.reg-*` block that lived in the `<style>` of register.html).
 *
 * The flow is the running application's, in the running application's order:
 *
 *   the visitor fills the personal / account / organisational sections
 *       ↓  username and national id are probed live against the backend
 *   POST /registration/submit
 *       ↓  the backend rate-limits, validates, hashes (bcrypt) and stores the
 *       ↓  request as `pending`; an administrator approves it later
 *   the success state shows the `HST-…` request id
 *
 * The three probes are the legacy client's, in the legacy client's order:
 * the username probe is debounced 400 ms and only runs once the value matches
 * `^[a-zA-Z0-9_]{3,30}$`; the national-id probe runs when ten digits are present
 * (the checksum itself is the backend's); the mobile probe is purely client
 * side.  Persian and Arabic digits are folded to Latin before submission
 * (`toLatinDigits`), because the backend stores and compares ASCII digits.
 */
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import api from '@/services/api';
import { useTheme } from '@/composables/useTheme';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const { toggleTheme } = useTheme();

const form = ref({
    first_name: '',
    last_name: '',
    father_name: '',
    national_id: '',
    mobile: '',
    username: '',
    password: '',
    password2: '',
    department: '',
    work_hours: '',
    substitute: '',
});

const departments = ref([]);
const workSchedules = ref([]);
const substitutes = ref([]);
const optionsLoading = ref(true);
const optionsError = ref('');

const usernameCheck = ref({ status: 'idle', message: '' });
const nationalIdCheck = ref({ status: 'idle', message: '' });
const mobileCheck = ref({ status: 'idle', message: '' });
const password2Check = ref({ status: 'idle', message: '' });

const passwordRequirements = [
    { key: 'length', label: 'حداقل ۸ کاراکتر', test: (p) => p.length >= 8 },
    { key: 'upper', label: 'حروف بزرگ', test: (p) => /[A-Z]/.test(p) },
    { key: 'lower', label: 'حروف کوچک', test: (p) => /[a-z]/.test(p) },
    { key: 'digit', label: 'عدد', test: (p) => /[0-9]/.test(p) },
    { key: 'symbol', label: 'کاراکتر خاص', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

const passwordScore = computed(() => {
    const password = form.value.password;

    if (!password) {
        return 0;
    }

    return passwordRequirements.filter((requirement) => requirement.test(password)).length;
});

const passwordStrength = computed(() => {
    const score = passwordScore.value;

    if (score <= 1) {
        return { label: 'خیلی ضعیف', width: '15%', color: '#dc2626' };
    }

    if (score === 2) {
        return { label: 'ضعیف', width: '35%', color: '#f97316' };
    }

    if (score === 3) {
        return { label: 'متوسط', width: '55%', color: '#eab308' };
    }

    if (score === 4) {
        return { label: 'خوب', width: '75%', color: '#22c55e' };
    }

    return { label: 'قوی ✓', width: '100%', color: '#10b981' };
});

const submitting = ref(false);
const submitted = ref(false);
const requestId = ref('');

let usernameTimer = null;

async function loadOptions() {
    optionsLoading.value = true;
    optionsError.value = '';

    const [departmentsResult, schedulesResult, usersResult] = await Promise.allSettled([
        api.get('/registration/departments', { baseURL: '' }),
        api.get('/registration/work-schedules', { baseURL: '' }),
        // `active-users` answers 403 for anonymous callers; the form simply
        // renders without a substitute picker then, exactly as the legacy
        // client's empty `.catch()` did.
        api.get('/registration/active-users', { baseURL: '' }),
    ]);

    if (departmentsResult.status === 'fulfilled') {
        departments.value = departmentsResult.value.data ?? [];
    }

    if (schedulesResult.status === 'fulfilled') {
        workSchedules.value = schedulesResult.value.data ?? [];
    }

    if (usersResult.status === 'fulfilled') {
        substitutes.value = usersResult.value.data ?? [];
    }

    if (departmentsResult.status === 'rejected' && schedulesResult.status === 'rejected') {
        optionsError.value = 'گزینه‌های فرم بارگذاری نشدند. لطفاً صفحه را دوباره بارگذاری کنید.';
    }

    optionsLoading.value = false;
}

function hintClass(check) {
    return {
        'reg-field__hint--ok': check.status === 'available',
        'reg-field__hint--err': check.status === 'invalid',
        'reg-field__hint--info': check.status === 'info' || check.status === 'checking',
    };
}

function inputClass(check) {
    return {
        'is-valid': check.status === 'available',
        'is-invalid': check.status === 'invalid',
    };
}

function onUsernameInput() {
    clearTimeout(usernameTimer);

    const value = form.value.username.trim();

    if (value.length < 3) {
        usernameCheck.value = { status: 'idle', message: '' };
        return;
    }

    if (!/^[a-zA-Z0-9_]{3,30}$/.test(value)) {
        usernameCheck.value = { status: 'invalid', message: 'فقط حروف انگلیسی، اعداد و زیرخط' };
        return;
    }

    usernameCheck.value = { status: 'checking', message: 'در حال بررسی...' };
    usernameTimer = setTimeout(checkUsername, 400);
}

async function checkUsername() {
    try {
        const response = await api.get('/registration/check-username', {
            baseURL: '',
            params: { q: form.value.username.trim() },
        });

        usernameCheck.value = {
            status: response.available ? 'available' : 'invalid',
            message: response.message,
        };
    } catch {
        usernameCheck.value = { status: 'invalid', message: 'خطا در بررسی.' };
    }
}

function onNationalIdInput() {
    const value = form.value.national_id.replace(/\s/g, '');
    form.value.national_id = value;

    if (value.length === 0) {
        nationalIdCheck.value = { status: 'idle', message: '' };
        return;
    }

    if (value.length < 10) {
        nationalIdCheck.value = {
            status: 'info',
            message: `${toPersianDigits(value.length)} / ۱۰ رقم`,
        };
        return;
    }

    checkNationalId();
}

async function checkNationalId() {
    nationalIdCheck.value = { status: 'checking', message: 'در حال بررسی...' };

    try {
        const response = await api.get('/registration/check-national-id', {
            baseURL: '',
            params: { q: form.value.national_id },
        });

        nationalIdCheck.value = {
            status: response.available ? 'available' : 'invalid',
            message: response.message || (response.available ? 'شماره ملی معتبر است.' : ''),
        };
    } catch {
        nationalIdCheck.value = { status: 'invalid', message: 'خطا در بررسی.' };
    }
}

function onMobileInput() {
    const value = form.value.mobile.replace(/[\s-]/g, '');
    form.value.mobile = value;

    if (value.length === 0) {
        mobileCheck.value = { status: 'idle', message: '' };
        return;
    }

    const valid = /^09\d{9}$/.test(value) || /^\+?989\d{9}$/.test(value);

    if (valid) {
        mobileCheck.value = { status: 'available', message: 'شماره همراه معتبر است.' };
    } else if (value.length >= 11) {
        mobileCheck.value = { status: 'invalid', message: 'شماره همراه معتبر نیست.' };
    } else {
        mobileCheck.value = { status: 'info', message: '' };
    }
}

function onPassword2Input() {
    const password = form.value.password;
    const password2 = form.value.password2;

    if (!password2) {
        password2Check.value = { status: 'idle', message: '' };
        return;
    }

    const match = password === password2;

    password2Check.value = {
        status: match ? 'available' : 'invalid',
        message: match ? 'رمز عبور مطابقت دارد.' : 'رمز عبور مطابقت ندارد.',
    };
}

const TOAST_TITLES = {
    success: 'عملیات موفق',
    error: 'خطای سامانه',
    warning: 'توجه سامانه',
    info: 'پیام سامانه',
};
const TOAST_ICONS = { success: '✓', error: '×', warning: '!', info: 'i' };
const TOAST_DURATIONS = { error: 6000, warning: 5000, success: 4500, info: 4000 };

function showToast(message, type = 'success') {
    let stack = document.getElementById('toastStack');

    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'toastStack';
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
    }

    const duration = TOAST_DURATIONS[type] || 4500;

    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    toast.style.setProperty('--toast-duration', `${duration}ms`);

    const head = document.createElement('div');
    head.className = 'toast__head';

    const icon = document.createElement('span');
    icon.className = 'toast__icon';
    icon.textContent = TOAST_ICONS[type] || 'i';
    icon.setAttribute('aria-hidden', 'true');

    const title = document.createElement('strong');
    title.className = 'toast__title';
    title.textContent = TOAST_TITLES[type] || '';

    const close = document.createElement('button');
    close.className = 'toast__close';
    close.type = 'button';
    close.textContent = '×';
    close.setAttribute('aria-label', 'بستن پیام');

    head.appendChild(icon);
    head.appendChild(title);
    head.appendChild(close);

    const msg = document.createElement('span');
    msg.className = 'toast__message';
    msg.textContent = message;

    const timeline = document.createElement('div');
    timeline.className = 'toast__timeline';
    const bar = document.createElement('span');
    timeline.appendChild(bar);

    toast.appendChild(head);
    toast.appendChild(msg);
    toast.appendChild(timeline);
    stack.appendChild(toast);

    const dismiss = () => {
        toast.classList.add('is-closing');
        setTimeout(() => toast.remove(), 300);
    };

    close.addEventListener('click', dismiss);
    setTimeout(dismiss, duration);

    requestAnimationFrame(() => toast.classList.add('show'));
}

async function submit() {
    const data = {
        first_name: form.value.first_name.trim(),
        last_name: form.value.last_name.trim(),
        father_name: form.value.father_name.trim(),
        national_id: toLatinDigits(form.value.national_id.trim()),
        mobile: toLatinDigits(form.value.mobile.trim()),
        username: form.value.username.trim(),
        password: form.value.password,
        department: form.value.department,
        work_hours: form.value.work_hours,
        substitute: form.value.substitute,
    };

    const errors = [];

    if (!data.first_name) {
        errors.push('نام الزامی است.');
    }

    if (!data.last_name) {
        errors.push('نام خانوادگی الزامی است.');
    }

    if (!data.username) {
        errors.push('نام کاربری الزامی است.');
    }

    if (!data.password || data.password.length < 8) {
        errors.push('رمز عبور باید حداقل ۸ کاراکتر باشد.');
    }

    if (data.password !== form.value.password2) {
        errors.push('رمز عبور و تکرار آن مطابقت ندارند.');
    }

    if (!data.department) {
        errors.push('بخش فعالیت الزامی است.');
    }

    if (errors.length) {
        showToast(errors.join(' | '), 'error');
        return;
    }

    submitting.value = true;

    try {
        const response = await api.post('/registration/submit', data, { baseURL: '' });
        requestId.value = response.request_id ?? '';
        submitted.value = true;
    } catch (failure) {
        const fieldErrors = failure.apiFailure?.errors;
        showToast(
            (fieldErrors && fieldErrors.length ? fieldErrors.join(' | ') : '') ||
                failure.apiFailure?.message ||
                failure.message ||
                'خطا در ثبت درخواست.',
            'error',
        );
    } finally {
        submitting.value = false;
    }
}

onMounted(loadOptions);
</script>

<template>
    <div class="reg-page">
        <!-- لایه‌های پس‌زمینه -->
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
                <RouterLink to="/training?from_login=true" class="support-toggle" aria-label="آموزش سامانه">
                    <span>🎓</span>
                </RouterLink>
                <span class="tooltip">آموزش سامانه</span>
            </div>
            <div class="tooltip-wrap">
                <a href="#" class="support-toggle" aria-label="پشتیبانی">
                    <span>🎧</span>
                </a>
                <span class="tooltip">پشتیبانی</span>
            </div>
        </div>

        <main class="register-shell">
            <div class="register-card" id="regCard">
                <!-- Registration Form -->
                <div id="regForm" v-show="!submitted">
                    <div class="reg-header">
                        <div class="reg-header__icon">👤</div>
                        <h2>ثبت نام در سامانه هستما</h2>
                        <p>اطلاعات خود را تکمیل کنید تا درخواست شما برای مدیر مجموعه ارسال شود</p>
                    </div>

                    <form id="registrationForm" @submit.prevent="submit">
                        <!-- اطلاعات شخصی -->
                        <div class="reg-section">
                            <div class="reg-section__title">📋 اطلاعات شخصی</div>
                            <div class="two-col">
                                <div class="reg-field">
                                    <label for="regFirstName">نام *</label>
                                    <input type="text" id="regFirstName" v-model="form.first_name" required placeholder="نام">
                                </div>
                                <div class="reg-field">
                                    <label for="regLastName">نام خانوادگی *</label>
                                    <input type="text" id="regLastName" v-model="form.last_name" required placeholder="نام خانوادگی">
                                </div>
                            </div>
                            <div class="reg-field">
                                <label for="regFatherName">نام پدر</label>
                                <input type="text" id="regFatherName" v-model="form.father_name" placeholder="اختیاری">
                            </div>
                            <div class="two-col">
                                <div class="reg-field">
                                    <label for="regNationalId">شماره ملی</label>
                                    <input
                                        type="text"
                                        id="regNationalId"
                                        v-model="form.national_id"
                                        :class="inputClass(nationalIdCheck)"
                                        placeholder="۱۰ رقم"
                                        maxlength="10"
                                        inputmode="numeric"
                                        dir="ltr"
                                        style="text-align:center"
                                        @input="onNationalIdInput"
                                    >
                                    <div class="reg-field__hint" id="regNationalIdHint" :class="hintClass(nationalIdCheck)">{{ nationalIdCheck.message }}</div>
                                </div>
                                <div class="reg-field">
                                    <label for="regMobile">شماره همراه</label>
                                    <input
                                        type="text"
                                        id="regMobile"
                                        v-model="form.mobile"
                                        :class="inputClass(mobileCheck)"
                                        placeholder="09xxxxxxxxx"
                                        maxlength="11"
                                        inputmode="numeric"
                                        dir="ltr"
                                        style="text-align:center"
                                        @input="onMobileInput"
                                    >
                                    <div class="reg-field__hint" id="regMobileHint" :class="hintClass(mobileCheck)">{{ mobileCheck.message }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- اطلاعات حساب -->
                        <div class="reg-section">
                            <div class="reg-section__title">🔐 اطلاعات حساب کاربری</div>
                            <div class="reg-field">
                                <label for="regUsername">نام کاربری *</label>
                                <input
                                    type="text"
                                    id="regUsername"
                                    v-model="form.username"
                                    :class="inputClass(usernameCheck)"
                                    required
                                    placeholder="فقط حروف انگلیسی، اعداد و زیرخط"
                                    autocomplete="off"
                                    dir="ltr"
                                    style="text-align:center"
                                    @input="onUsernameInput"
                                >
                                <div class="reg-field__hint" id="regUsernameHint" :class="hintClass(usernameCheck)">{{ usernameCheck.message }}</div>
                            </div>
                            <div class="reg-field">
                                <label for="regPassword">رمز عبور *</label>
                                <input
                                    type="password"
                                    id="regPassword"
                                    v-model="form.password"
                                    required
                                    placeholder="حداقل ۸ کاراکتر"
                                    autocomplete="new-password"
                                >
                                <div class="pw-strength" id="pwStrength" v-show="form.password">
                                    <div class="pw-strength__bar">
                                        <div
                                            class="pw-strength__fill"
                                            id="pwFill"
                                            :style="{ width: passwordStrength.width, background: passwordStrength.color }"
                                        ></div>
                                    </div>
                                    <div
                                        class="pw-strength__label"
                                        id="pwLabel"
                                        :style="{ color: passwordStrength.color }"
                                    >{{ passwordStrength.label }}</div>
                                </div>
                                <div v-show="form.password" class="pw-requirements" id="pwReqs">
                                    <span
                                        v-for="requirement in passwordRequirements"
                                        :key="requirement.key"
                                        class="pw-req"
                                        :class="{ met: requirement.test(form.password) }"
                                    >{{ requirement.test(form.password) ? '✓' : '✕' }} {{ requirement.label }}</span>
                                </div>
                            </div>
                            <div class="reg-field">
                                <label for="regPassword2">تکرار رمز عبور *</label>
                                <input
                                    type="password"
                                    id="regPassword2"
                                    v-model="form.password2"
                                    :class="inputClass(password2Check)"
                                    required
                                    placeholder="تکرار رمز عبور"
                                    autocomplete="new-password"
                                    @input="onPassword2Input"
                                >
                                <div class="reg-field__hint" id="regPassword2Hint" :class="hintClass(password2Check)">{{ password2Check.message }}</div>
                            </div>
                        </div>

                        <!-- اطلاعات سازمانی -->
                        <div class="reg-section">
                            <div class="reg-section__title">🏢 اطلاعات سازمانی</div>
                            <div class="reg-field">
                                <label for="regDepartment">بخش فعالیت *</label>
                                <select id="regDepartment" v-model="form.department" required>
                                    <option value="" disabled selected>انتخاب بخش</option>
                                    <option v-for="department in departments" :key="department" :value="department">
                                        {{ department }}
                                    </option>
                                </select>
                            </div>
                            <div class="reg-field">
                                <label for="regWorkHours">ساعت کاری</label>
                                <select id="regWorkHours" v-model="form.work_hours">
                                    <option value="" disabled selected>انتخاب ساعت کاری</option>
                                    <option v-for="schedule in workSchedules" :key="schedule.value" :value="schedule.value">
                                        {{ schedule.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="reg-field">
                                <label for="regSubstitute">جانشین</label>
                                <select id="regSubstitute" v-model="form.substitute">
                                    <option value="" selected>بدون جانشین</option>
                                    <option v-for="user in substitutes" :key="user.username" :value="user.username">
                                        {{ user.name }} — {{ user.department }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <p v-if="optionsError" class="reg-options-error" role="alert">{{ optionsError }}</p>

                        <button type="submit" class="reg-submit" id="regSubmit" :disabled="submitting">
                            {{ submitting ? 'در حال ارسال...' : 'ثبت درخواست' }}
                        </button>
                    </form>

                    <div class="reg-footer">
                        <p>حساب کاربری دارید؟ <RouterLink to="/login">ورود کنید</RouterLink></p>
                    </div>
                </div>

                <!-- Success State -->
                <div class="reg-success" id="regSuccess" v-show="submitted">
                    <div class="reg-success__icon">✅</div>
                    <h3>درخواست شما ثبت شد!</h3>
                    <p>پس از بررسی مدیر مجموعه، حساب شما فعال خواهد شد.</p>
                    <p>شناسه درخواست شما:</p>
                    <div class="request-id" id="regRequestId">{{ requestId }}</div>
                    <p style="font-size:0.75rem;color:#94a3b8;margin-top:8px">این شناسه را ذخیره کنید تا وضعیت درخواست خود را پیگیری کنید.</p>
                    <div class="reg-success__actions">
                        <RouterLink to="/login" class="reg-success__btn reg-success__btn--primary">بازگشت به ورود</RouterLink>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
.register-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(1rem, 3vw, 2rem);
    position: relative;
    z-index: 1;
}

.register-card {
    position: relative;
    width: 100%;
    max-width: 520px;
    background: var(--panel);
    border: 1px solid var(--border-strong);
    border-radius: 28px;
    box-shadow: var(--shadow-card);
    padding: clamp(1.6rem, 3.5vw, 2.4rem) clamp(1.4rem, 3vw, 2.2rem);
    overflow: hidden;
    backdrop-filter: blur(22px) saturate(1.35);
    -webkit-backdrop-filter: blur(22px) saturate(1.35);
    animation: regCardIn 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes regCardIn {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.register-card::before {
    content: '';
    position: absolute;
    top: -40%; left: -20%;
    width: 140%; height: 80%;
    background: radial-gradient(ellipse at center, rgba(14,165,233,0.08) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}

.reg-header {
    text-align: center;
    margin-bottom: 28px;
    position: relative;
    z-index: 1;
}

.reg-header__icon {
    width: 64px; height: 64px;
    border-radius: 20px;
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.8rem;
    box-shadow: 0 12px 32px rgba(14,165,233,0.3);
    margin-bottom: 14px;
    animation: iconFloat 3s ease-in-out infinite;
}

@keyframes iconFloat {
    0%,100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
}

.reg-header h2 {
    margin: 0 0 6px;
    font-size: 1.3rem;
    font-weight: 900;
    background: linear-gradient(120deg, var(--text) 20%, var(--brand-1) 55%, var(--brand-3) 90%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}

.reg-header p {
    margin: 0;
    font-size: 0.82rem;
    color: var(--muted);
    line-height: 1.7;
}

.reg-section {
    margin-bottom: 22px;
    padding: 16px;
    border-radius: 18px;
    background: var(--chip-bg);
    border: 1px solid var(--border);
    position: relative;
    z-index: 1;
    animation: sectionIn 0.5s ease both;
}

.reg-section:nth-child(2) { animation-delay: 0.1s; }
.reg-section:nth-child(3) { animation-delay: 0.2s; }
.reg-section:nth-child(4) { animation-delay: 0.3s; }

@keyframes sectionIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

.reg-section__title {
    font-size: 0.82rem;
    font-weight: 800;
    color: var(--brand-1);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.reg-section__title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to left, transparent, var(--border));
}

.reg-field { margin-bottom: 14px; }
.reg-field:last-child { margin-bottom: 0; }

.reg-field label {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
}

.reg-field input,
.reg-field select {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--border);
    border-radius: 12px;
    font-size: 0.88rem;
    font-family: 'Vazir', sans-serif;
    background: var(--panel);
    color: var(--text);
    outline: none;
    transition: all 0.25s ease;
    direction: rtl;
}

.reg-field input:focus,
.reg-field select:focus {
    border-color: var(--brand-1);
    box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
}

.reg-field input.is-valid {
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,0.1);
}

.reg-field input.is-invalid {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
}

.reg-field__hint {
    font-size: 0.72rem;
    margin-top: 5px;
    min-height: 1.1em;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 4px;
}

.reg-field__hint--ok { color: #10b981; }
.reg-field__hint--err { color: #ef4444; }
.reg-field__hint--info { color: var(--muted); }

.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.pw-strength { margin-top: 8px; }

.pw-strength__bar {
    height: 5px;
    border-radius: 3px;
    background: var(--border);
    overflow: hidden;
    margin-bottom: 6px;
}

.pw-strength__fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.4s cubic-bezier(0.22, 1, 0.36, 1), background 0.3s;
    width: 0;
}

.pw-strength__label {
    font-size: 0.75rem;
    font-weight: 700;
    transition: color 0.3s;
}

.pw-requirements {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.pw-req {
    font-size: 0.7rem;
    padding: 4px 10px;
    border-radius: 8px;
    background: var(--chip-bg);
    color: var(--muted);
    border: 1px solid var(--border);
    transition: all 0.25s;
}

.pw-req.met {
    background: rgba(16,185,129,0.1);
    color: #059669;
    border-color: rgba(16,185,129,0.3);
}

.reg-submit {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    color: #fff;
    font-size: 0.95rem;
    font-weight: 800;
    font-family: 'Vazir', sans-serif;
    cursor: pointer;
    transition: all 0.25s;
    position: relative;
    z-index: 1;
    overflow: hidden;
    margin-top: 8px;
}

.reg-submit::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #0284c7, #0369a1);
    opacity: 0;
    transition: opacity 0.25s;
    z-index: -1;
}

.reg-submit:hover::before { opacity: 1; }
.reg-submit:hover { box-shadow: 0 8px 24px rgba(14,165,233,0.35); transform: translateY(-1px); }
.reg-submit:active { transform: translateY(0) scale(0.98); }
.reg-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.reg-footer {
    text-align: center;
    margin-top: 20px;
    font-size: 0.82rem;
    color: var(--muted);
    position: relative;
    z-index: 1;
}

.reg-footer a {
    color: var(--brand-1);
    font-weight: 700;
    text-decoration: none;
    transition: opacity 0.2s;
}

.reg-footer a:hover { opacity: 0.7; }

.reg-success {
    text-align: center;
    padding: 40px 20px;
    position: relative;
    z-index: 1;
}

.reg-success__icon {
    font-size: 3.5rem;
    margin-bottom: 16px;
    animation: successPop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes successPop {
    0% { transform: scale(0) rotate(-20deg); }
    100% { transform: scale(1) rotate(0); }
}

.reg-success h3 {
    margin: 0 0 10px;
    font-size: 1.2rem;
    font-weight: 900;
    color: #059669;
}

.reg-success p {
    margin: 0 0 8px;
    font-size: 0.85rem;
    color: var(--muted);
    line-height: 1.7;
}

.reg-success .request-id {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 12px;
    background: rgba(16,185,129,0.08);
    border: 1px solid rgba(16,185,129,0.2);
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    font-weight: 700;
    color: #065f46;
    margin: 10px 0;
    direction: ltr;
    letter-spacing: 1px;
}

.reg-success__actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    justify-content: center;
}

.reg-success__btn {
    padding: 10px 24px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 700;
    font-family: 'Vazir', sans-serif;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.reg-success__btn--primary {
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    color: #fff;
}

.reg-success__btn--primary:hover { box-shadow: 0 6px 20px rgba(14,165,233,0.3); }

.reg-options-error {
    position: relative;
    z-index: 1;
    margin: 4px 0 0;
    font-size: 0.8rem;
    color: #ef4444;
    text-align: center;
}

@media (max-width: 600px) {
    .register-card {
        border-radius: 22px;
        padding: 20px 16px;
    }

    .reg-section { padding: 14px; }
}

@media (max-width: 480px) {
    .two-col { grid-template-columns: 1fr; }
}
</style>
