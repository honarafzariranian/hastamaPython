<script setup>
/**
 * Payroll — the legacy `payrollBox`, reduced to the parts the ported API can
 * feed.  The brief scopes this page to the save/load cycle
 * (POST /api/admin/payroll/save, GET /api/admin/payroll/load), so the tables
 * are the three that need no extra server data:
 *
 *   محاسبه جامع            — comprehensive payroll (all pay/deduct fields)
 *   حقوق ساعتی            — hourly payroll for unofficial staff
 *   جمع کل پرداخت‌ها       — the summary cards
 *
 * The legacy overtime-payroll and karaneh tabs read per-user attendance and
 * pass records through endpoints outside this page's API list
 * (/get_hozoor/{username}, /get_hourly_pass_report), so they are not
 * reproduced here.
 *
 * The calculations are the legacy ones verbatim: comprehensive
 * netPay = max(0, pay − deductions); hourly netPay = max(0, rate·(work +
 * overtime) + bonus + eidi − rate·(leave + penalty) − advance); the summary
 * aggregates both tables.  Inputs are formatted live with Persian digits and
 * thousands separators, as the legacy formatPayrollInputValue did.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const MONTHS = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
];

const COMPREHENSIVE_FIELDS = [
    { key: 'dailySalary', label: 'حقوق روزانه', money: true },
    { key: 'benefits', label: 'بن کارگری، حق مسکن، حق اولاد و حق تأهل', money: true },
    { key: 'workHours', label: 'ساعت کارکرد قابل پرداخت', money: false },
    { key: 'overtime', label: 'اضافه کاری', money: true },
    { key: 'bonus', label: 'پاداش', money: true },
    { key: 'eidiSanavat', label: 'عیدی و سنوات', money: true },
    { key: 'leave', label: 'مرخصی', money: true },
    { key: 'workDeduction', label: 'کسر کار', money: true },
    { key: 'insurance', label: 'کسر بیمه پایه و تکمیلی', money: true },
    { key: 'advance', label: 'علی‌الحساب دریافتی', money: true },
];

const COMPREHENSIVE_PAY_FIELDS = ['dailySalary', 'benefits', 'overtime', 'bonus', 'eidiSanavat'];
const COMPREHENSIVE_DEDUCT_FIELDS = ['leave', 'workDeduction', 'insurance', 'advance'];

const HOURLY_FIELDS = [
    { key: 'hourlyRate', label: 'دستمزد به ازای هر ساعت (ریال)', money: true },
    { key: 'hourlyWorkHours', label: 'ساعت کارکرد ماه', money: false },
    { key: 'hourlyLeaveHours', label: 'مرخصی (ساعت)', money: false },
    { key: 'hourlyPenaltyHours', label: 'جریمه به ساعت', money: false },
    { key: 'hourlyOvertimeHours', label: 'اضافه‌کاری به ساعت', money: false },
    { key: 'hourlyBonus', label: 'پاداش (ریال)', money: true },
    { key: 'hourlyEidiSanavat', label: 'عیدی و سنوات (ریال)', money: true },
    { key: 'hourlyAdvance', label: 'علی‌الحساب دریافتی (ریال)', money: true },
];

const activeTab = ref('overtime');

const users = ref([]);
const loadingUsers = ref(true);

const period = reactive({
    month: 'فروردین',
    year: '۱۴۰۵',
    workDays: '۲۲',
    mandatoryHours: '۱۷۶',
});

const comprehensive = ref([]);
const hourly = ref([]);

const notice = ref('');
const error = ref('');
const savingType = ref('');
const loadingType = ref('');

const tabs = [
    { id: 'comprehensive', label: 'محاسبه جامع' },
    { id: 'hourly', label: 'حقوق ساعتی پرسنل غیررسمی' },
    { id: 'summary', label: 'جمع کل پرداخت‌ها' },
];

/* ── number formatting (legacy formatPayrollInputValue) ──────────────── */

function countNumericChars(text, position) {
    let count = 0;

    for (let index = 0; index < position && index < text.length; index += 1) {
        if (/[0-9۰-۹.]/.test(text[index])) {
            count += 1;
        }
    }

    return count;
}

function setCaretAfterNumericChars(input, count) {
    const value = input.value;
    let position = 0;
    let seen = 0;

    while (position < value.length && seen < count) {
        if (/[0-9۰-۹.]/.test(value[position])) {
            seen += 1;
        }

        position += 1;
    }

    input.setSelectionRange(position, position);
}

function formatMoneyInput(input) {
    const raw = input.value;
    const caret = input.selectionStart ?? raw.length;
    const before = countNumericChars(raw, caret);
    const digits = toLatinDigits(raw).replace(/[،,]/g, '').replace(/[^0-9.]/g, '');

    if (digits === '') {
        if (raw !== '') {
            input.value = '';
        }

        return;
    }

    const english = digits.replace(/^0+(?=\d)/, '');
    const grouped = english.replace(/\B(?=(\d{3})+(?!\d))/g, '،');
    const formatted = toPersianDigits(grouped);

    if (formatted !== raw) {
        input.value = formatted;
        setCaretAfterNumericChars(input, before);
    }
}

function formatHoursInput(input) {
    const raw = input.value;
    const caret = input.selectionStart ?? raw.length;
    const before = countNumericChars(raw, caret);
    const cleaned = toLatinDigits(raw).replace(/[،,]/g, '');
    let hours = cleaned.replace(/[^0-9.]/g, '');
    const dot = hours.indexOf('.');

    if (dot !== -1) {
        hours = hours.slice(0, dot + 2);
    }

    const formatted = toPersianDigits(hours);

    if (formatted !== raw) {
        input.value = formatted;
        setCaretAfterNumericChars(input, before);
    }
}

function numericValue(value) {
    const parsed = Number(toLatinDigits(String(value ?? '')).replace(/[،,]/g, ''));

    return Number.isFinite(parsed) ? parsed : 0;
}

function formatPayrollNumber(value) {
    const numeric = Math.round(numericValue(value));

    if (numeric === 0) {
        return '۰';
    }

    return toPersianDigits(numeric.toLocaleString('en-US'));
}

/* ── table helpers ────────────────────────────────────────────────────── */

function fieldValue(row, field) {
    return numericValue(row.values[field] ?? '۰');
}

function setResult(row, key, value) {
    row.results[key] = value;
}

function computeComprehensiveRow(row) {
    const totalPay = COMPREHENSIVE_PAY_FIELDS.reduce((sum, field) => sum + fieldValue(row, field), 0);
    const totalDeduct = COMPREHENSIVE_DEDUCT_FIELDS.reduce((sum, field) => sum + fieldValue(row, field), 0);

    setResult(row, 'totalPay', formatPayrollNumber(totalPay));
    setResult(row, 'netPay', formatPayrollNumber(Math.max(0, totalPay - totalDeduct)));

    return { totalPay, netPay: Math.max(0, totalPay - totalDeduct) };
}

function computeHourlyRow(row) {
    const rate = fieldValue(row, 'hourlyRate');
    const work = fieldValue(row, 'hourlyWorkHours');
    const leave = fieldValue(row, 'hourlyLeaveHours');
    const penalty = fieldValue(row, 'hourlyPenaltyHours');
    const overtime = fieldValue(row, 'hourlyOvertimeHours');
    const bonus = fieldValue(row, 'hourlyBonus');
    const eidi = fieldValue(row, 'hourlyEidiSanavat');
    const advance = fieldValue(row, 'hourlyAdvance');

    const totalPay = rate * work + rate * overtime + bonus + eidi;
    const deductions = rate * leave + rate * penalty + advance;

    setResult(row, 'totalPay', formatPayrollNumber(totalPay));
    setResult(row, 'netPay', formatPayrollNumber(Math.max(0, totalPay - deductions)));

    return { totalPay, netPay: Math.max(0, totalPay - deductions) };
}

const comprehensiveTotals = computed(() => {
    const sums = {};

    for (const field of COMPREHENSIVE_FIELDS) {
        sums[field.key] = 0;
    }

    let totalPay = 0;
    let netPay = 0;

    for (const row of comprehensive.value) {
        for (const field of COMPREHENSIVE_FIELDS) {
            sums[field.key] += fieldValue(row, field.key);
        }

        const result = computeComprehensiveRow(row);

        totalPay += result.totalPay;
        netPay += result.netPay;
    }

    return { sums, totalPay, netPay };
});

const hourlyTotals = computed(() => {
    const sums = {};

    for (const field of HOURLY_FIELDS) {
        sums[field.key] = 0;
    }

    let rateSum = 0;
    let rateCount = 0;
    let totalPay = 0;
    let netPay = 0;

    for (const row of hourly.value) {
        for (const field of HOURLY_FIELDS) {
            sums[field.key] += fieldValue(row, field.key);
        }

        const rate = fieldValue(row, 'hourlyRate');

        if (rate) {
            rateSum += rate;
            rateCount += 1;
        }

        const result = computeHourlyRow(row);

        totalPay += result.totalPay;
        netPay += result.netPay;
    }

    return { sums, rateAverage: rateCount ? rateSum / rateCount : 0, totalPay, netPay };
});

const summary = computed(() => {
    const comprehensiveSums = comprehensiveTotals.value.sums;
    const hourlySums = hourlyTotals.value.sums;

    const insurance = comprehensiveSums.insurance;
    const bonus = comprehensiveSums.bonus + hourlySums.hourlyBonus;
    const eidiSanavat = comprehensiveSums.eidiSanavat + hourlySums.hourlyEidiSanavat;
    const advance = comprehensiveSums.advance + hourlySums.hourlyAdvance;
    const totalPay = comprehensiveTotals.value.totalPay + hourlyTotals.value.totalPay;
    const netPay = comprehensiveTotals.value.netPay + hourlyTotals.value.netPay;

    return { insurance, bonus, eidiSanavat, advance, totalPay, netPay };
});

/* ── data ─────────────────────────────────────────────────────────────── */

function makeComprehensiveRow(user) {
    const values = {};

    for (const field of COMPREHENSIVE_FIELDS) {
        values[field.key] = '۰';
    }

    return {
        username: user.value,
        name: user.label || user.value,
        values,
        results: { totalPay: '۰', netPay: '۰' },
    };
}

function makeHourlyRow(user) {
    const values = {};

    for (const field of HOURLY_FIELDS) {
        values[field.key] = '۰';
    }

    return {
        username: user.value,
        name: user.label || user.value,
        values,
        results: { totalPay: '۰', netPay: '۰' },
    };
}

async function loadUsers() {
    loadingUsers.value = true;

    try {
        const response = await api.get('/get_users');
        users.value = response.users ?? [];
        comprehensive.value = users.value.map(makeComprehensiveRow);
        hourly.value = users.value.map(makeHourlyRow);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت فهرست پرسنل.';
    } finally {
        loadingUsers.value = false;
    }
}

/* ── save / load ──────────────────────────────────────────────────────── */

function periodConfig() {
    return {
        workDays: period.workDays,
        mandatoryHours: period.mandatoryHours,
    };
}

function comprehensivePayload() {
    return comprehensive.value.map((row) => {
        const payload = { ...row.values };

        payload.periodConfig = periodConfig();

        return { username: row.username, payload };
    });
}

function hourlyPayload() {
    return hourly.value.map((row) => {
        const payload = { ...row.values };

        payload.periodConfig = periodConfig();

        return { username: row.username, payload };
    });
}

function summaryPayload() {
    return [
        {
            username: '__summary__',
            payload: {
                insurance: summary.value.insurance,
                bonus: summary.value.bonus,
                eidiSanavat: summary.value.eidiSanavat,
                advance: summary.value.advance,
                netPay: summary.value.netPay,
                totalPay: summary.value.totalPay,
                periodConfig: periodConfig(),
            },
        },
    ];
}

async function savePayroll(type) {
    savingType.value = type;
    error.value = '';
    notice.value = '';

    const payload = {
        calculation_type: type,
        period_year: toLatinDigits(period.year),
        period_month: period.month,
        period_config: periodConfig(),
        rows: type === 'comprehensive' ? comprehensivePayload() : type === 'hourly' ? hourlyPayload() : summaryPayload(),
    };

    try {
        const response = await api.post('/admin/payroll/save', payload);

        if (!response.success) {
            error.value = response.error || 'خطا در ذخیره تغییرات.';
            return;
        }

        notice.value = response.message || 'تغییرات با موفقیت ذخیره شد.';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ذخیره تغییرات.';
    } finally {
        savingType.value = '';
    }
}

function applyComprehensivePayload(items) {
    for (const item of items ?? []) {
        const row = comprehensive.value.find((candidate) => candidate.username === item.username);

        if (!row || !item.payload) {
            continue;
        }

        for (const field of COMPREHENSIVE_FIELDS) {
            if (item.payload[field.key] !== undefined && item.payload[field.key] !== null) {
                row.values[field.key] = String(item.payload[field.key]);
            }
        }

        if (item.payload.periodConfig) {
            applyPeriodConfig(item.payload.periodConfig);
        }
    }
}

function applyHourlyPayload(items) {
    for (const item of items ?? []) {
        const row = hourly.value.find((candidate) => candidate.username === item.username);

        if (!row || !item.payload) {
            continue;
        }

        for (const field of HOURLY_FIELDS) {
            if (item.payload[field.key] !== undefined && item.payload[field.key] !== null) {
                row.values[field.key] = String(item.payload[field.key]);
            }
        }

        if (item.payload.periodConfig) {
            applyPeriodConfig(item.payload.periodConfig);
        }
    }
}

function applySummaryPayload(items) {
    const item = (items ?? [])[0];

    if (!item?.payload?.periodConfig) {
        return;
    }

    applyPeriodConfig(item.payload.periodConfig);
}

function applyPeriodConfig(config) {
    if (config.workDays !== undefined) {
        period.workDays = String(config.workDays);
    }

    if (config.mandatoryHours !== undefined) {
        period.mandatoryHours = String(config.mandatoryHours);
    }
}

async function loadPayroll(type) {
    loadingType.value = type;
    error.value = '';

    try {
        const response = await api.get('/admin/payroll/load', {
            params: {
                calculation_type: type,
                period_year: toLatinDigits(period.year),
                period_month: period.month,
            },
        });

        if (!response.success) {
            error.value = response.error || 'خطا در بازیابی تغییرات.';
            return;
        }

        if (type === 'comprehensive') {
            applyComprehensivePayload(response.items);
        } else if (type === 'hourly') {
            applyHourlyPayload(response.items);
        } else {
            applySummaryPayload(response.items);
        }
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در بازیابی تغییرات.';
    } finally {
        loadingType.value = '';
    }
}

async function showPeriod() {
    const year = Number(toLatinDigits(period.year));

    if (!year || year < 1300 || year > 1600) {
        error.value = 'لطفاً سال معتبر بین ۱۳۰۰ تا ۱۶۰۰ وارد کنید.';
        return;
    }

    error.value = '';
    notice.value = 'در حال بارگذاری اطلاعات دوره…';

    await Promise.all([
        loadPayroll('comprehensive'),
        loadPayroll('hourly'),
        loadPayroll('summary'),
    ]);

    notice.value = 'اطلاعات دوره نمایش داده شد.';
}

function recalculate() {
    for (const row of comprehensive.value) {
        computeComprehensiveRow(row);
    }

    for (const row of hourly.value) {
        computeHourlyRow(row);
    }
}

function onWorkDaysInput() {
    const days = Number(toLatinDigits(period.workDays));

    if (days) {
        period.mandatoryHours = toPersianDigits(String(days * 7));
    }
}

onMounted(() => {
    const now = new Date();
    const persian = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
        year: 'numeric',
        month: 'numeric',
    }).formatToParts(now);

    for (const part of persian) {
        if (part.type === 'year') {
            period.year = toPersianDigits(part.value);
        } else if (part.type === 'month') {
            period.month = MONTHS[Number(part.value) - 1];
        }
    }

    loadUsers();
});
</script>

<template>
    <section class="payroll">
        <header class="payroll__head">
            <div>
                <h1 class="payroll__title">محاسبه حقوق و دستمزد پرسنل</h1>
                <p class="payroll__sub">محاسبه و گزارش‌گیری حقوق و مزایای پرسنل</p>
            </div>
        </header>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div class="payroll__config">
            <span class="payroll__config-title">تنظیمات دوره گزارش</span>
            <div class="payroll__config-grid">
                <label class="h-field">
                    <span class="h-field__label">ماه</span>
                    <select v-model="period.month" class="h-input">
                        <option v-for="name in MONTHS" :key="name" :value="name">{{ name }}</option>
                    </select>
                </label>
                <label class="h-field">
                    <span class="h-field__label">سال</span>
                    <input v-model="period.year" type="text" class="h-input" inputmode="numeric" autocomplete="off">
                </label>
                <label class="h-field">
                    <span class="h-field__label">روزهای کاری</span>
                    <input
                        v-model="period.workDays"
                        type="text"
                        class="h-input"
                        inputmode="numeric"
                        autocomplete="off"
                        @input="onWorkDaysInput"
                    >
                </label>
                <label class="h-field">
                    <span class="h-field__label">ساعت موظفی</span>
                    <input v-model="period.mandatoryHours" type="text" class="h-input" inputmode="numeric" autocomplete="off">
                </label>
                <div class="payroll__config-actions">
                    <button type="button" class="h-btn h-btn-primary" :disabled="loadingType !== ''" @click="showPeriod">
                        {{ loadingType ? 'در حال دریافت…' : 'نمایش' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="payroll__tabs" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                class="payroll__tab"
                :class="{ 'is-active': activeTab === tab.id }"
                role="tab"
                :aria-selected="activeTab === tab.id"
                @click="activeTab = tab.id; recalculate()"
            >
                {{ tab.label }}
            </button>
        </div>

        <div v-if="loadingUsers" class="payroll__loading">در حال دریافت فهرست پرسنل…</div>

        <template v-else>
            <div v-show="activeTab === 'comprehensive'" class="payroll__panel">
                <div class="payroll__table-scroll">
                    <table class="payroll__table">
                        <thead>
                            <tr>
                                <th>ردیف</th>
                                <th>پرسنل</th>
                                <th v-for="field in COMPREHENSIVE_FIELDS" :key="field.key">{{ field.label }}</th>
                                <th>مجموع پرداختی (ریال)</th>
                                <th>مانده قابل پرداخت (ریال)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in comprehensive" :key="row.username">
                                <td>{{ toPersianDigits(index + 1) }}</td>
                                <td class="payroll__person">
                                    <strong>{{ row.name }}</strong>
                                    <span>{{ row.username }}</span>
                                </td>
                                <td v-for="field in COMPREHENSIVE_FIELDS" :key="field.key">
                                    <input
                                        v-model="row.values[field.key]"
                                        type="text"
                                        class="payroll__input"
                                        :class="field.money ? 'is-money' : 'is-hours'"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        @input="field.money ? formatMoneyInput($event.target) : formatHoursInput($event.target); computeComprehensiveRow(row)"
                                    >
                                </td>
                                <td class="payroll__result">{{ row.results.totalPay }}</td>
                                <td class="payroll__result">{{ row.results.netPay }}</td>
                            </tr>
                            <tr v-if="comprehensive.length === 0">
                                <td colspan="13" class="payroll__empty">پرسنلی برای نمایش وجود ندارد.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">مجموع کل</td>
                                <td v-for="field in COMPREHENSIVE_FIELDS" :key="field.key">
                                    {{ formatPayrollNumber(comprehensiveTotals.sums[field.key]) }}
                                </td>
                                <td>{{ formatPayrollNumber(comprehensiveTotals.totalPay) }}</td>
                                <td>{{ formatPayrollNumber(comprehensiveTotals.netPay) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="payroll__actions">
                    <button type="button" class="h-btn h-btn-ghost" @click="recalculate">محاسبه مجدد</button>
                    <button
                        type="button"
                        class="h-btn h-btn-primary"
                        :disabled="savingType === 'comprehensive'"
                        @click="savePayroll('comprehensive')"
                    >
                        {{ savingType === 'comprehensive' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                </div>
            </div>

            <div v-show="activeTab === 'hourly'" class="payroll__panel">
                <div class="payroll__table-scroll">
                    <table class="payroll__table">
                        <thead>
                            <tr>
                                <th>پرسنل</th>
                                <th v-for="field in HOURLY_FIELDS" :key="field.key">{{ field.label }}</th>
                                <th>مبلغ مانده قابل پرداخت (ریال)</th>
                                <th>مجموع پرداختی (ریال)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in hourly" :key="row.username">
                                <td class="payroll__person">
                                    <strong>{{ row.name }}</strong>
                                    <span>{{ row.username }}</span>
                                </td>
                                <td v-for="field in HOURLY_FIELDS" :key="field.key">
                                    <input
                                        v-model="row.values[field.key]"
                                        type="text"
                                        class="payroll__input"
                                        :class="field.money ? 'is-money' : 'is-hours'"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        @input="field.money ? formatMoneyInput($event.target) : formatHoursInput($event.target); computeHourlyRow(row)"
                                    >
                                </td>
                                <td class="payroll__result">{{ row.results.netPay }}</td>
                                <td class="payroll__result">{{ row.results.totalPay }}</td>
                            </tr>
                            <tr v-if="hourly.length === 0">
                                <td colspan="10" class="payroll__empty">پرسنلی برای نمایش وجود ندارد.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>مجموع کل</td>
                                <td v-for="field in HOURLY_FIELDS" :key="field.key">
                                    {{ field.key === 'hourlyRate' ? formatPayrollNumber(hourlyTotals.rateAverage) : formatPayrollNumber(hourlyTotals.sums[field.key]) }}
                                </td>
                                <td>{{ formatPayrollNumber(hourlyTotals.netPay) }}</td>
                                <td>{{ formatPayrollNumber(hourlyTotals.totalPay) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="payroll__actions">
                    <button type="button" class="h-btn h-btn-ghost" @click="recalculate">محاسبه مجدد</button>
                    <button
                        type="button"
                        class="h-btn h-btn-primary"
                        :disabled="savingType === 'hourly'"
                        @click="savePayroll('hourly')"
                    >
                        {{ savingType === 'hourly' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                </div>
            </div>

            <div v-show="activeTab === 'summary'" class="payroll__panel">
                <div class="payroll__summary">
                    <article class="h-card payroll__summary-card">
                        <span>جمع کل کسورات بابت بیمه پایه و تکمیلی</span>
                        <strong>{{ formatPayrollNumber(summary.insurance) }}</strong>
                        <small>ریال</small>
                    </article>
                    <article class="h-card payroll__summary-card">
                        <span>جمع کل پاداش</span>
                        <strong>{{ formatPayrollNumber(summary.bonus) }}</strong>
                        <small>ریال</small>
                    </article>
                    <article class="h-card payroll__summary-card">
                        <span>جمع کل عیدی و سنوات</span>
                        <strong>{{ formatPayrollNumber(summary.eidiSanavat) }}</strong>
                        <small>ریال</small>
                    </article>
                    <article class="h-card payroll__summary-card">
                        <span>جمع کل علی‌الحساب دریافتی</span>
                        <strong>{{ formatPayrollNumber(summary.advance) }}</strong>
                        <small>ریال</small>
                    </article>
                    <article class="h-card payroll__summary-card payroll__summary-card--net">
                        <span>جمع کل مبلغ مانده قابل پرداخت</span>
                        <strong>{{ formatPayrollNumber(summary.netPay) }}</strong>
                        <small>ریال</small>
                    </article>
                    <article class="h-card payroll__summary-card payroll__summary-card--total">
                        <span>جمع کل مبلغ پرداختی به پرسنل</span>
                        <strong>{{ formatPayrollNumber(summary.totalPay) }}</strong>
                        <small>ریال</small>
                    </article>
                </div>

                <div class="payroll__actions">
                    <button type="button" class="h-btn h-btn-ghost" @click="recalculate">محاسبه مجدد</button>
                    <button
                        type="button"
                        class="h-btn h-btn-primary"
                        :disabled="savingType === 'summary'"
                        @click="savePayroll('summary')"
                    >
                        {{ savingType === 'summary' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                </div>
            </div>
        </template>
    </section>
</template>

<style scoped>
.payroll {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.payroll__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.payroll__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .payroll__sub {
    color: var(--dk-text-2);
}

.payroll__config {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    padding: 1rem 1.1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .payroll__config {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.payroll__config-title {
    font-size: 0.85rem;
    font-weight: 800;
}

.payroll__config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 0.8rem;
    align-items: end;
}

.payroll__tabs {
    display: flex;
    gap: 0.4rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.1);
    flex-wrap: wrap;
}

[data-theme='dark'] .payroll__tabs {
    border-bottom-color: var(--dk-line);
}

.payroll__tab {
    padding: 0.55rem 1rem;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: #64748b;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
}

[data-theme='dark'] .payroll__tab {
    color: var(--dk-text-2);
}

.payroll__tab.is-active {
    border-bottom-color: var(--c-primary);
    color: var(--c-primary-dark);
}

[data-theme='dark'] .payroll__tab.is-active {
    color: var(--dk-accent);
}

.payroll__loading {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #64748b;
}

[data-theme='dark'] .payroll__loading {
    color: var(--dk-text-2);
}

.payroll__panel {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.payroll__table-scroll {
    overflow-x: auto;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
}

[data-theme='dark'] .payroll__table-scroll {
    border-color: var(--dk-border);
}

.payroll__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    background: #fff;
}

[data-theme='dark'] .payroll__table {
    background: var(--dk-surface);
}

.payroll__table th,
.payroll__table td {
    padding: 0.5rem 0.55rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .payroll__table th,
[data-theme='dark'] .payroll__table td {
    border-bottom-color: var(--dk-line);
}

.payroll__table th {
    color: #64748b;
    font-size: 0.72rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .payroll__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.payroll__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .payroll__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.payroll__person {
    display: flex;
    flex-direction: column;
    line-height: 1.3;
}

.payroll__person span {
    color: #94a3b8;
    font-size: 0.7rem;
}

[data-theme='dark'] .payroll__person span {
    color: var(--dk-text-3);
}

.payroll__input {
    width: 100%;
    min-width: 6.5rem;
    padding: 0.35rem 0.5rem;
    border: 1px solid rgb(15 23 42 / 0.15);
    border-radius: var(--radius-token-sm);
    background: #fff;
    color: inherit;
    font: inherit;
    font-size: 0.78rem;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

[data-theme='dark'] .payroll__input {
    border-color: var(--dk-border);
    background: var(--dk-surface-2);
}

.payroll__input:focus {
    outline: 2px solid var(--c-primary);
    outline-offset: 1px;
}

.payroll__result {
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.payroll__table tfoot td {
    background: rgb(14 165 233 / 0.07);
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

[data-theme='dark'] .payroll__table tfoot td {
    background: var(--dk-surface-2);
}

.payroll__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .payroll__empty {
    color: var(--dk-text-3);
}

.payroll__actions {
    display: flex;
    justify-content: flex-start;
    gap: 0.6rem;
}

.payroll__summary {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.9rem;
}

.payroll__summary-card {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 1.1rem 1.2rem;
}

.payroll__summary-card span {
    color: #64748b;
    font-size: 0.78rem;
}

[data-theme='dark'] .payroll__summary-card span {
    color: var(--dk-text-2);
}

.payroll__summary-card strong {
    font-size: 1.35rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.payroll__summary-card small {
    color: #94a3b8;
    font-size: 0.7rem;
}

[data-theme='dark'] .payroll__summary-card small {
    color: var(--dk-text-3);
}

.payroll__summary-card--net {
    border-color: rgb(34 197 94 / 0.45);
}

.payroll__summary-card--net strong {
    color: #15803d;
}

[data-theme='dark'] .payroll__summary-card--net strong {
    color: #86efac;
}

.payroll__summary-card--total {
    border-color: rgb(217 119 6 / 0.45);
}

.payroll__summary-card--total strong {
    color: #b45309;
}

[data-theme='dark'] .payroll__summary-card--total strong {
    color: #fcd34d;
}
</style>
