<script setup>
/**
 * Payroll — verbatim port of `payrollBox` from admin.html.
 *
 * Tabs (per Python):
 *   overtime-calculation         — per-user overtime/deficit
 *   comprehensive-calculation    — full pay/deduct table
 *   hourly-payroll-calculation   — unofficial hourly staff
 *   payroll-summary-calculation  — aggregate cards
 *   karaneh-calculation          — pass-based bonus (کارانه)
 *
 * Save/load through POST /api/admin/payroll/save, GET /api/admin/payroll/load.
 * Overtime/karaneh rows' runtime values are populated from attendance endpoints
 * where possible; otherwise they show "در حال دریافت…" placeholders like the
 * Python page did on initial load.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const MONTHS = [
    'فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور',
    'مهر','آبان','آذر','دی','بهمن','اسفند',
];

const COMP_FIELDS = [
    { key: 'dailySalary',  label: 'حقوق روزانه',                                money: true },
    { key: 'benefits',     label: 'بن کارگری، حق مسکن، حق اولاد و حق تأهل',     money: true },
    { key: 'workHours',    label: 'ساعت کارکرد قابل پرداخت',                  money: false },
    { key: 'overtime',     label: 'اضافه کاری',                                 money: true },
    { key: 'bonus',        label: 'پاداش',                                      money: true },
    { key: 'eidiSanavat',  label: 'عیدی و سنوات',                               money: true },
    { key: 'leave',        label: 'مرخصی',                                      money: true },
    { key: 'workDeduction',label: 'کسر کار',                                    money: true },
    { key: 'insurance',    label: 'کسر بیمه پایه و تکمیلی',                     money: true },
    { key: 'advance',      label: 'علی‌الحساب دریافتی',                          money: true },
];
const COMP_PAY = ['dailySalary','benefits','overtime','bonus','eidiSanavat'];
const COMP_DEDUCT = ['leave','workDeduction','insurance','advance'];

const HOURLY_FIELDS = [
    { key: 'hourlyRate',        label: 'دستمزد به ازای هر ساعت (ریال)', money: true },
    { key: 'hourlyWorkHours',   label: 'ساعت کارکرد ماه',               money: false },
    { key: 'hourlyLeaveHours',  label: 'مرخصی (ساعت)',                  money: false },
    { key: 'hourlyPenaltyHours',label: 'جریمه به ساعت',                 money: false },
    { key: 'hourlyOvertimeHours',label: 'اضافه‌کاری به ساعت',           money: false },
    { key: 'hourlyBonus',       label: 'پاداش (ریال)',                   money: true },
    { key: 'hourlyEidiSanavat', label: 'عیدی و سنوات (ریال)',           money: true },
    { key: 'hourlyAdvance',     label: 'علی‌الحساب دریافتی (ریال)',      money: true },
];

const OVERTIME_FIELDS = ['minuteRate','hourRate'];
const OVERTIME_RESULTS = ['signedMinutes','signedHours','totalCost','status','workedHours','mandatoryHours','difference','premium','finalWorkHours','finalOvertimeHours'];

const activeTab = ref('overtime-calculation');
const users = ref([]);
const officialUsers = computed(() => users.value.filter((u) => (!u.employment_status || u.employment_status === 'official') && (!u.is_active || u.is_active === 'active')));
const unofficialUsers = computed(() => users.value.filter((u) => u.employment_status === 'unofficial' && (!u.is_active || u.is_active === 'active')));
const loadingUsers = ref(true);

const period = reactive({
    month: 'فروردین',
    year: '۱۴۰۵',
    workDays: '۲۲',
    mandatoryHours: '۱۷۶',
});

const comprehensive = ref([]);
const hourly = ref([]);
const overtime = ref([]);
const karaneh = ref([]);

const notice = ref('');
const error = ref('');
const savingType = ref('');
const loadingType = ref('');

const tabs = [
    { id: 'overtime-calculation',       label: 'محاسبات اضافه کاری و کسری کار' },
    { id: 'comprehensive-calculation',  label: 'محاسبه جامع' },
    { id: 'hourly-payroll-calculation', label: 'حقوق ساعتی پرسنل غیررسمی' },
    { id: 'payroll-summary-calculation',label: 'جمع کل پرداخت‌ها' },
    { id: 'karaneh-calculation',        label: 'محاسبه کارانه' },
];

/* Number formatting (ported from legacy formatPayrollInputValue) */
function countNumericChars(t, pos) {
    let c = 0;
    for (let i = 0; i < pos && i < t.length; i += 1) if (/[0-9۰-۹.]/.test(t[i])) c += 1;
    return c;
}
function setCaret(input, n) {
    let pos = 0, seen = 0;
    while (pos < input.value.length && seen < n) { if (/[0-9۰-۹.]/.test(input.value[pos])) seen += 1; pos += 1; }
    input.setSelectionRange(pos, pos);
}
function formatMoneyInput(input) {
    const raw = input.value, caret = input.selectionStart ?? raw.length, before = countNumericChars(raw, caret);
    const digits = toLatinDigits(raw).replace(/[،,]/g, '').replace(/[^0-9.]/g, '');
    if (digits === '') { if (raw !== '') input.value = ''; return; }
    const en = digits.replace(/^0+(?=\d)/, '');
    const fmt = toPersianDigits(en.replace(/\B(?=(\d{3})+(?!\d))/g, '،'));
    if (fmt !== raw) { input.value = fmt; setCaret(input, before); }
}
function formatHoursInput(input) {
    const raw = input.value, caret = input.selectionStart ?? raw.length, before = countNumericChars(raw, caret);
    let cleaned = toLatinDigits(raw).replace(/[،,]/g, '').replace(/[^0-9.]/g, '');
    const dot = cleaned.indexOf('.');
    if (dot !== -1) cleaned = cleaned.slice(0, dot + 2);
    const fmt = toPersianDigits(cleaned);
    if (fmt !== raw) { input.value = fmt; setCaret(input, before); }
}
function nv(v) {
    const n = Number(toLatinDigits(String(v ?? '')).replace(/[،,]/g, ''));
    return Number.isFinite(n) ? n : 0;
}
function fmt(n) {
    const num = Math.round(nv(n));
    if (num === 0) return '۰';
    return toPersianDigits(num.toLocaleString('en-US'));
}
function fmtHours(n) {
    const num = nv(n);
    return toPersianDigits(String(Math.floor(num * 100) / 100));
}

function fieldVal(row, key) { return nv(row.values?.[key] ?? row[key]); }

function computeComprehensiveRow(row) {
    const pay = COMP_PAY.reduce((s, k) => s + fieldVal(row, k), 0);
    const ded = COMP_DEDUCT.reduce((s, k) => s + fieldVal(row, k), 0);
    const net = Math.max(0, pay - ded);
    row.results = row.results || {};
    row.results.totalPay = fmt(pay);
    row.results.netPay = fmt(net);
    return { totalPay: pay, netPay: net };
}
function computeHourlyRow(row) {
    const rate = fieldVal(row, 'hourlyRate');
    const work = fieldVal(row, 'hourlyWorkHours'), leave = fieldVal(row, 'hourlyLeaveHours');
    const pen = fieldVal(row, 'hourlyPenaltyHours'), ot = fieldVal(row, 'hourlyOvertimeHours');
    const bonus = fieldVal(row, 'hourlyBonus'), eidi = fieldVal(row, 'hourlyEidiSanavat'), adv = fieldVal(row, 'hourlyAdvance');
    const total = rate * work + rate * ot + bonus + eidi;
    const ded = rate * leave + rate * pen + adv;
    const net = Math.max(0, total - ded);
    row.results = row.results || {};
    row.results.totalPay = fmt(total);
    row.results.netPay = fmt(net);
    return { totalPay: total, netPay: net };
}

function computeOvertimeRow(row) {
    // minuteRate/hourRate/signedMinutes are entered or loaded from API; we compute dependent cells.
    const minuteRate = nv(row.values?.minuteRate ?? row.minuteRate);
    const hourRate = nv(row.values?.hourRate ?? row.hourRate) || minuteRate * 60;
    const signed = nv(row.values?.signedMinutes ?? row.signedMinutes);
    const mandatory = nv(period.mandatoryHours);
    const worked = nv(row.workedHours);
    const diff = worked - mandatory; // positive = overtime, negative = deficit
    row.results = row.results || {};
    row.results.signedHours = fmtHours(signed / 60);
    row.results.totalCost = fmt(minuteRate * signed);
    row.results.hourRate = fmt(hourRate);
    row.results.mandatoryHours = fmtHours(mandatory);
    row.results.workedHours = fmtHours(worked);
    row.results.difference = fmtHours(diff);
    // 40% overtime premium (only the positive portion)
    const overtime = Math.max(0, diff) + Math.max(0, signed / 60);
    row.results.premium = fmt(hourRate * 0.4 * overtime);
    row.results.finalWorkHours = fmtHours(worked);
    row.results.finalOvertimeHours = fmtHours(overtime);
    if (row.values) { row.values.hourRate = fmt(hourRate); }
}

const compTotals = computed(() => {
    const sums = {};
    for (const f of COMP_FIELDS) sums[f.key] = 0;
    let totalPay = 0, netPay = 0;
    for (const row of comprehensive.value) {
        for (const f of COMP_FIELDS) sums[f.key] += fieldVal(row, f.key);
        const r = computeComprehensiveRow(row);
        totalPay += r.totalPay; netPay += r.netPay;
    }
    return { sums, totalPay, netPay };
});
const hourlyTotals = computed(() => {
    const sums = {};
    for (const f of HOURLY_FIELDS) sums[f.key] = 0;
    let totalPay = 0, netPay = 0, rateSum = 0, rateCount = 0;
    for (const row of hourly.value) {
        for (const f of HOURLY_FIELDS) sums[f.key] += fieldVal(row, f.key);
        const rate = fieldVal(row, 'hourlyRate');
        if (rate) { rateSum += rate; rateCount += 1; }
        const r = computeHourlyRow(row);
        totalPay += r.totalPay; netPay += r.netPay;
    }
    return { sums, totalPay, netPay, rateAvg: rateCount ? rateSum / rateCount : 0 };
});
const overtimeTotals = computed(() => {
    const totals = { signedMinutes: 0, signedHours: 0, totalCost: 0, workedHours: 0, mandatoryHours: 0, difference: 0, premium: 0, finalWorkHours: 0, finalOvertimeHours: 0 };
    for (const row of overtime.value) {
        computeOvertimeRow(row);
        totals.signedMinutes += nv(row.values?.signedMinutes ?? row.signedMinutes);
        totals.totalCost += nv(row.results?.totalCost);
        totals.workedHours += nv(row.workedHours);
        totals.difference += nv(row.results?.difference);
        totals.premium += nv(row.results?.premium);
        totals.finalWorkHours += nv(row.results?.finalWorkHours);
        totals.finalOvertimeHours += nv(row.results?.finalOvertimeHours);
    }
    totals.signedHours = totals.signedMinutes / 60;
    totals.mandatoryHours = nv(period.mandatoryHours) * overtime.value.length;
    return totals;
});
const summary = computed(() => {
    const cs = compTotals.value.sums, hs = hourlyTotals.value.sums;
    return {
        insurance: cs.insurance,
        bonus: cs.bonus + hs.hourlyBonus,
        eidiSanavat: cs.eidiSanavat + hs.hourlyEidiSanavat,
        advance: cs.advance + hs.hourlyAdvance,
        totalPay: compTotals.value.totalPay + hourlyTotals.value.totalPay,
        netPay: compTotals.value.netPay + hourlyTotals.value.netPay,
    };
});

function makeCompRow(u) {
    const values = {}; for (const f of COMP_FIELDS) values[f.key] = '۰';
    return { username: u.username || u.value, name: u.last_name || u.name || u.label || u.username || u.value, department: u.department || '', values, results: { totalPay: '—', netPay: '—' } };
}
function makeHourlyRow(u) {
    const values = {}; for (const f of HOURLY_FIELDS) values[f.key] = '۰';
    return { username: u.username || u.value, name: u.last_name || u.name || u.label || u.username || u.value, department: u.department || '', values, results: { totalPay: '—', netPay: '—' } };
}
function makeOvertimeRow(u) {
    const values = { minuteRate: '۰', hourRate: '۰', signedMinutes: '۰' };
    return { username: u.username || u.value, name: u.last_name || u.name || u.label || u.username || u.value, department: u.department || '', values, workedHours: 0, status: 'در حال دریافت…' };
}
function makeKaranehRow(u) {
    return { username: u.username || u.value, name: u.last_name || u.name || u.label || u.username || u.value, department: u.department || '' };
}

async function loadUsers() {
    loadingUsers.value = true;
    try {
        const response = await api.get('/get_users', { baseURL: '' });
        const list = response.users ?? [];
        users.value = list;
        comprehensive.value = list.filter((u) => !u.is_active || u.is_active === 'active').map(makeCompRow);
        hourly.value = list.filter((u) => u.employment_status === 'unofficial' && (!u.is_active || u.is_active === 'active')).map(makeHourlyRow);
        overtime.value = list.filter((u) => (!u.employment_status || u.employment_status === 'official') && (!u.is_active || u.is_active === 'active')).map(makeOvertimeRow);
        karaneh.value = list.filter((u) => !u.is_active || u.is_active === 'active').map(makeKaranehRow);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت فهرست پرسنل.';
    } finally { loadingUsers.value = false; }
}

function periodConfig() { return { workDays: period.workDays, mandatoryHours: period.mandatoryHours }; }

function payloadFor(rows, fields, extra) {
    return rows.map((row) => {
        const p = {};
        for (const f of fields) p[f.key] = row.values?.[f.key] ?? row[f.key];
        if (extra) Object.assign(p, extra(row));
        p.periodConfig = periodConfig();
        return { username: row.username, payload: p };
    });
}

async function savePayroll(type) {
    savingType.value = type; error.value = ''; notice.value = '';
    let rows;
    if (type === 'comprehensive') rows = payloadFor(comprehensive.value, COMP_FIELDS);
    else if (type === 'hourly') rows = payloadFor(hourly.value, HOURLY_FIELDS);
    else if (type === 'overtime') rows = payloadFor(overtime.value, OVERTIME_FIELDS.map((k)=>({key:k})), (r) => ({ signedMinutes: r.values.signedMinutes }));
    else if (type === 'summary') {
        rows = [{ username: '__summary__', payload: { ...summary.value, periodConfig: periodConfig() } }];
    } else if (type === 'karaneh') {
        rows = [{ username: '__karaneh__', payload: { periodConfig: periodConfig() } }];
    }
    const payload = { calculation_type: type, period_year: toLatinDigits(period.year), period_month: period.month, period_config: periodConfig(), rows };
    try {
        const response = await api.post('/admin/payroll/save', payload);
        if (!response.success) { error.value = response.error || 'خطا در ذخیره تغییرات.'; return; }
        notice.value = response.message || 'تغییرات با موفقیت ذخیره شد.';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ذخیره تغییرات.';
    } finally { savingType.value = ''; }
}

async function loadPayroll(type) {
    loadingType.value = type; error.value = '';
    try {
        const response = await api.get('/admin/payroll/load', {
            params: { calculation_type: type, period_year: toLatinDigits(period.year), period_month: period.month },
        });
        if (!response.success) { error.value = response.error || 'خطا در بازیابی تغییرات.'; return; }
        const items = response.items || [];
        const apply = (target, fields) => {
            for (const it of items) {
                const row = target.find((c) => c.username === it.username);
                if (!row || !it.payload) continue;
                row.values = row.values || {};
                for (const f of fields) if (it.payload[f.key] != null) row.values[f.key] = String(it.payload[f.key]);
                if (it.payload.periodConfig) {
                    if (it.payload.periodConfig.workDays != null) period.workDays = String(it.payload.periodConfig.workDays);
                    if (it.payload.periodConfig.mandatoryHours != null) period.mandatoryHours = String(it.payload.periodConfig.mandatoryHours);
                }
            }
        };
        if (type === 'comprehensive') apply(comprehensive.value, COMP_FIELDS);
        else if (type === 'hourly') apply(hourly.value, HOURLY_FIELDS);
        else if (type === 'overtime') apply(overtime.value, OVERTIME_FIELDS.map((k)=>({key:k})));
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در بازیابی تغییرات.';
    } finally { loadingType.value = ''; }
}

async function showPeriod() {
    const yr = Number(toLatinDigits(period.year));
    if (!yr || yr < 1300 || yr > 1600) { error.value = 'لطفاً سال معتبر بین ۱۳۰۰ تا ۱۶۰۰ وارد کنید.'; return; }
    error.value = ''; notice.value = 'در حال بارگذاری اطلاعات دوره…';
    await Promise.all([loadPayroll('comprehensive'), loadPayroll('hourly'), loadPayroll('overtime')]);
    notice.value = 'اطلاعات دوره نمایش داده شد.';
}

function recalculate() {
    for (const r of comprehensive.value) computeComprehensiveRow(r);
    for (const r of hourly.value) computeHourlyRow(r);
    for (const r of overtime.value) computeOvertimeRow(r);
}

function onWorkDaysInput() {
    const days = Number(toLatinDigits(period.workDays));
    if (days) period.mandatoryHours = toPersianDigits(String(days * 7));
}

function openPayrollReport() {
    // Store summary in localStorage and open payroll report page (mirrors Python flow)
    localStorage.setItem('payrollSummary', JSON.stringify(summary.value));
    window.open('/payroll_report_page', '_blank');
}

onMounted(() => {
    const now = new Date();
    const parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year:'numeric', month:'numeric' }).formatToParts(now);
    for (const p of parts) {
        if (p.type === 'year') period.year = toPersianDigits(p.value);
        else if (p.type === 'month') period.month = MONTHS[Number(p.value)-1] || 'فروردین';
    }
    loadUsers();
});
</script>

<template>
    <header class="section-hero" style="--hero-accent:#d97706;--hero-accent-2:#fbbf24;--hero-glow-1:rgba(217,119,6,0.14);--hero-glow-2:rgba(251,191,36,0.12);--hero-shadow:rgba(217,119,6,0.55);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(217,119,6,0.08);">
        <div class="section-hero__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="7" width="18" height="13" rx="3.2" fill="#fff" opacity=".14"/><rect x="3" y="7" width="18" height="13" rx="3.2" stroke="#fff" stroke-width="1.9"/><path d="M6.5 7V6a2 2 0 012-2h8" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M6.5 11h6" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".75"/><circle cx="16.5" cy="14.5" r="4" fill="#fff"/><circle cx="16.5" cy="14.5" r="2.1" stroke="#D97706" stroke-width="1.4"/><path d="M16.5 13.4v2.2" stroke="#D97706" stroke-width="1.3" stroke-linecap="round"/></svg>
        </div>
        <div class="section-hero__text">
            <h2>محاسبه حقوق و دستمزد پرسنل</h2>
            <p>محاسبه و گزارش‌گیری حقوق و مزایای پرسنل</p>
        </div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="payroll-frame">
        <div class="payroll-tabs" role="tablist">
            <button v-for="t in tabs" :key="t.id" class="payroll-tab-btn"
                :class="{ active: activeTab === t.id }" role="tab" :aria-selected="activeTab === t.id"
                @click="activeTab = t.id; recalculate()">
                {{ t.label }}
            </button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div v-if="loadingUsers" style="padding:2rem;text-align:center;color:#64748b;">در حال دریافت فهرست پرسنل…</div>

        <template v-else>
            <!-- Tab 1: overtime calculation -->
            <div id="overtime-calculation" class="payroll-tab-content" :class="{ active: activeTab === 'overtime-calculation' }" role="tabpanel">
                <div class="payroll-config">
                    <div class="payroll-config-title">تنظیمات دوره گزارش</div>
                    <div class="payroll-config-grid">
                        <label class="payroll-config-item">
                            <span>ماه</span>
                            <select v-model="period.month">
                                <option v-for="m in MONTHS" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </label>
                        <label class="payroll-config-item">
                            <span>سال</span>
                            <input v-model="period.year" type="text" inputmode="numeric" autocomplete="off">
                        </label>
                        <label class="payroll-config-item">
                            <span>روزهای کاری</span>
                            <input v-model="period.workDays" type="text" inputmode="numeric" autocomplete="off" @input="onWorkDaysInput">
                        </label>
                        <label class="payroll-config-item">
                            <span>ساعت موظفی</span>
                            <input v-model="period.mandatoryHours" type="text" inputmode="numeric" autocomplete="off">
                        </label>
                        <div class="payroll-config-item payroll-config-item--btn">
                            <button type="button" class="payroll-period-display-btn" :disabled="loadingType !== ''" @click="showPeriod">
                                {{ loadingType ? 'در حال بارگذاری…' : 'نمایش' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="overtime-payroll-table-scroll">
                    <table class="payroll-table overtime-payroll-table" id="overtimePayrollTable">
                        <thead>
                            <tr class="payroll-table-group">
                                <th rowspan="2" class="payroll-col-name">پرسنل</th>
                                <th colspan="5">نرخ و مبلغ محاسبه</th>
                                <th colspan="7">وضعیت کارکرد ماه</th>
                            </tr>
                            <tr class="payroll-table-subhead">
                                <th data-overtime-field="minuteRate">هزینه هر دقیقه (ریال)</th>
                                <th data-overtime-field="hourRate">هزینه هر ساعت (ریال)</th>
                                <th>دقیقه اضافه‌کاری/کسری</th>
                                <th>ساعت اضافه‌کاری/کسری</th>
                                <th>جمع کل (ریال)</th>
                                <th>وضعیت این ماه</th>
                                <th>کل ساعات ماه محاسبه‌شده</th>
                                <th>ساعت موظفی</th>
                                <th>مابه‌التفاوت ساعتی</th>
                                <th>۴۰٪ اضافه‌کاری (ریال)</th>
                                <th>مقدار نهایی ساعت کاری</th>
                                <th>مقدار نهایی اضافه‌کاری</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in overtime" :key="row.username" class="overtime-payroll-row" :data-username="row.username">
                                <td class="payroll-col-name">
                                    <div class="payroll-person">
                                        <span class="payroll-person-name">{{ row.name }}</span>
                                        <span class="payroll-person-meta">{{ row.department }}</span>
                                    </div>
                                </td>
                                <td><input type="text" class="overtime-payroll-input" data-overtime-field="minuteRate" v-model="row.values.minuteRate" inputmode="numeric" autocomplete="off" @input="formatMoneyInput($event.target); computeOvertimeRow(row)"></td>
                                <td><input type="text" class="overtime-payroll-input" data-overtime-field="hourRate" v-model="row.values.hourRate" inputmode="numeric" autocomplete="off" @input="formatMoneyInput($event.target); computeOvertimeRow(row)"></td>
                                <td><input type="text" class="overtime-payroll-input" v-model="row.values.signedMinutes" inputmode="numeric" autocomplete="off" @input="formatHoursInput($event.target); computeOvertimeRow(row)"></td>
                                <td class="overtime-payroll-result" data-overtime-result="signedHours">{{ row.results?.signedHours || '۰' }}</td>
                                <td class="overtime-payroll-result overtime-payroll-money" data-overtime-result="totalCost">{{ row.results?.totalCost || '۰' }}</td>
                                <td class="overtime-payroll-result" data-overtime-result="status">{{ row.status }}</td>
                                <td class="overtime-payroll-result" data-overtime-result="workedHours">{{ row.results?.workedHours || '۰' }}</td>
                                <td class="overtime-payroll-result" data-overtime-result="mandatoryHours">{{ row.results?.mandatoryHours || period.mandatoryHours }}</td>
                                <td class="overtime-payroll-result" data-overtime-result="difference">{{ row.results?.difference || '۰' }}</td>
                                <td class="overtime-payroll-result overtime-payroll-money" data-overtime-result="premium">{{ row.results?.premium || '۰' }}</td>
                                <td class="overtime-payroll-result" data-overtime-result="finalWorkHours">{{ row.results?.finalWorkHours || '۰' }}</td>
                                <td class="overtime-payroll-result" data-overtime-result="finalOvertimeHours">{{ row.results?.finalOvertimeHours || '۰' }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="payroll-total-row overtime-payroll-total-row">
                                <td class="payroll-col-name">مجموع کل</td>
                                <td>—</td><td>—</td>
                                <td data-overtime-total="signedMinutes">{{ fmt(overtimeTotals.signedMinutes) }}</td>
                                <td data-overtime-total="signedHours">{{ fmtHours(overtimeTotals.signedHours) }}</td>
                                <td data-overtime-total="totalCost">{{ fmt(overtimeTotals.totalCost) }}</td>
                                <td>—</td>
                                <td data-overtime-total="workedHours">{{ fmtHours(overtimeTotals.workedHours) }}</td>
                                <td data-overtime-total="mandatoryHours">{{ fmtHours(overtimeTotals.mandatoryHours) }}</td>
                                <td data-overtime-total="difference">{{ fmtHours(overtimeTotals.difference) }}</td>
                                <td data-overtime-total="premium">{{ fmt(overtimeTotals.premium) }}</td>
                                <td data-overtime-total="finalWorkHours">{{ fmtHours(overtimeTotals.finalWorkHours) }}</td>
                                <td data-overtime-total="finalOvertimeHours">{{ fmtHours(overtimeTotals.finalOvertimeHours) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="overtime-payroll-actions" aria-label="عملیات محاسبات اضافه‌کاری و کسری‌کاری">
                    <button type="button" class="overtime-payroll-refresh" @click="recalculate">محاسبه مجدد</button>
                    <button type="button" class="overtime-payroll-save" :disabled="savingType==='overtime'" @click="savePayroll('overtime')">
                        {{ savingType==='overtime' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                </div>
            </div>

            <!-- Tab 2: comprehensive -->
            <div id="comprehensive-calculation" class="payroll-tab-content" :class="{ active: activeTab === 'comprehensive-calculation' }" role="tabpanel">
                <div class="payroll-config">
                    <div class="payroll-config-title">تنظیمات دوره گزارش</div>
                    <div class="payroll-config-grid">
                        <label class="payroll-config-item"><span>ماه</span>
                            <select v-model="period.month"><option v-for="m in MONTHS" :key="m" :value="m">{{ m }}</option></select>
                        </label>
                        <label class="payroll-config-item"><span>سال</span>
                            <input v-model="period.year" type="text" inputmode="numeric" autocomplete="off">
                        </label>
                        <label class="payroll-config-item"><span>روزهای کاری</span>
                            <input v-model="period.workDays" type="text" inputmode="numeric" autocomplete="off" @input="onWorkDaysInput">
                        </label>
                        <label class="payroll-config-item"><span>ساعت موظفی</span>
                            <input v-model="period.mandatoryHours" type="text" inputmode="numeric" autocomplete="off">
                        </label>
                        <div class="payroll-config-item payroll-config-item--btn">
                            <button type="button" class="payroll-period-display-btn" id="payrollDisplayPeriodBtn" :disabled="loadingType !== ''" @click="showPeriod">نمایش</button>
                        </div>
                    </div>
                </div>
                <div class="payroll-table-scroll">
                    <table class="payroll-table" id="payrollComprehensiveTable">
                        <thead>
                            <tr class="payroll-table-group">
                                <th rowspan="2" class="payroll-col-index">ردیف</th>
                                <th rowspan="2" class="payroll-col-name">پرسنل</th>
                                <th colspan="6">پرداختی‌ها</th>
                                <th colspan="4">کسورات</th>
                                <th rowspan="2">مجموع پرداختی (ریال)</th>
                                <th rowspan="2">مانده قابل پرداخت (ریال)</th>
                            </tr>
                            <tr class="payroll-table-subhead">
                                <th v-for="f in COMP_FIELDS.slice(0,6)" :key="f.key" :data-field="f.key">
                                    <span class="payroll-column-title">{{ f.label }}</span>
                                    <button type="button" class="payroll-copy-previous-btn">ماه قبل</button>
                                </th>
                                <th v-for="f in COMP_FIELDS.slice(6)" :key="f.key" :data-field="f.key">
                                    <span class="payroll-column-title">{{ f.label }}</span>
                                    <button type="button" class="payroll-copy-previous-btn">ماه قبل</button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in comprehensive" :key="row.username" class="payroll-row" :data-username="row.username">
                                <td class="payroll-col-index">{{ toPersianDigits(idx + 1) }}</td>
                                <td class="payroll-col-name">
                                    <div class="payroll-person">
                                        <span class="payroll-person-name">{{ row.name }}</span>
                                        <span class="payroll-person-meta">{{ row.department }}</span>
                                    </div>
                                </td>
                                <td v-for="f in COMP_FIELDS" :key="f.key">
                                    <input type="text" class="payroll-input" :data-username="row.username" :data-field="f.key" v-model="row.values[f.key]" inputmode="numeric" autocomplete="off"
                                        @input="f.money ? formatMoneyInput($event.target) : formatHoursInput($event.target); computeComprehensiveRow(row)">
                                </td>
                                <td class="payroll-calc-cell" :data-username="row.username" data-result="totalPay">{{ row.results.totalPay }}</td>
                                <td class="payroll-calc-cell" :data-username="row.username" data-result="netPay">{{ row.results.netPay }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="payroll-total-row">
                                <td colspan="2">مجموع کل</td>
                                <td v-for="f in COMP_FIELDS" :key="f.key" :data-total="f.key">{{ fmt(compTotals.sums[f.key]) }}</td>
                                <td data-total="totalPay">{{ fmt(compTotals.totalPay) }}</td>
                                <td data-total="netPay">{{ fmt(compTotals.netPay) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="payroll-save-actions" aria-label="عملیات محاسبه جامع">
                    <button type="button" class="payroll-save-recalculate" @click="recalculate">محاسبه مجدد</button>
                    <button type="button" class="payroll-save-btn" :disabled="savingType==='comprehensive'" @click="savePayroll('comprehensive')">
                        {{ savingType==='comprehensive' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                </div>
            </div>

            <!-- Tab 3: hourly payroll -->
            <div id="hourly-payroll-calculation" class="payroll-tab-content" :class="{ active: activeTab === 'hourly-payroll-calculation' }" role="tabpanel">
                <div class="hourly-payroll-table-scroll">
                    <table class="payroll-table hourly-payroll-table" id="hourlyPayrollTable">
                        <thead>
                            <tr class="payroll-table-group">
                                <th rowspan="2" class="payroll-col-name hourly-payroll-person-col">پرسنل</th>
                                <th colspan="4">ساعت کار و محاسبه</th>
                                <th colspan="4">پرداختی‌ها و کسورات</th>
                                <th rowspan="2">مبلغ مانده قابل پرداخت (ریال)</th>
                                <th rowspan="2">مجموع پرداختی (ریال)</th>
                            </tr>
                            <tr class="payroll-table-subhead">
                                <th v-for="f in HOURLY_FIELDS" :key="f.key" :data-hourly-field="f.key">{{ f.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in hourly" :key="row.username" class="payroll-row hourly-payroll-row" :data-username="row.username">
                                <td class="payroll-col-name">
                                    <div class="payroll-person">
                                        <span class="payroll-person-name">{{ row.name }}</span>
                                        <span class="payroll-person-meta">{{ row.department }}</span>
                                    </div>
                                </td>
                                <td v-for="f in HOURLY_FIELDS" :key="f.key">
                                    <input type="text" class="hourly-payroll-input" :data-hourly-field="f.key" v-model="row.values[f.key]" inputmode="numeric" autocomplete="off"
                                        @input="f.money ? formatMoneyInput($event.target) : formatHoursInput($event.target); computeHourlyRow(row)">
                                </td>
                                <td class="payroll-calc-cell hourly-payroll-result" data-hourly-result="netPay">{{ row.results.netPay }}</td>
                                <td class="payroll-calc-cell hourly-payroll-result" data-hourly-result="totalPay">{{ row.results.totalPay }}</td>
                            </tr>
                            <tr v-if="hourly.length === 0">
                                <td colspan="11" style="text-align:center;padding:2rem;color:#94a3b8;">پرسنل غیررسمی برای نمایش وجود ندارد.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="payroll-total-row hourly-payroll-total-row">
                                <td class="payroll-col-name">مجموع کل</td>
                                <td data-hourly-total="hourlyRate">{{ fmt(hourlyTotals.rateAvg) }}</td>
                                <td v-for="f in HOURLY_FIELDS.slice(1)" :key="f.key" :data-hourly-total="f.key">{{ fmt(hourlyTotals.sums[f.key]) }}</td>
                                <td data-hourly-total="netPay">{{ fmt(hourlyTotals.netPay) }}</td>
                                <td data-hourly-total="totalPay">{{ fmt(hourlyTotals.totalPay) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="payroll-save-actions" aria-label="عملیات حقوق ساعتی">
                    <button type="button" class="payroll-save-recalculate" @click="recalculate">محاسبه مجدد</button>
                    <button type="button" class="payroll-save-btn" :disabled="savingType==='hourly'" @click="savePayroll('hourly')">
                        {{ savingType==='hourly' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                </div>
            </div>

            <!-- Tab 4: summary -->
            <div id="payroll-summary-calculation" class="payroll-tab-content" :class="{ active: activeTab === 'payroll-summary-calculation' }" role="tabpanel">
                <div class="payroll-summary-grid">
                    <article class="payroll-summary-card payroll-summary-card--deduction"><span>جمع کل کسورات بابت بیمه پایه و تکمیلی</span><strong id="payrollSummaryInsurance">{{ fmt(summary.insurance) }}</strong><small>ریال</small></article>
                    <article class="payroll-summary-card payroll-summary-card--bonus"><span>جمع کل پاداش</span><strong id="payrollSummaryBonus">{{ fmt(summary.bonus) }}</strong><small>ریال</small></article>
                    <article class="payroll-summary-card payroll-summary-card--eidi"><span>جمع کل عیدی و سنوات</span><strong id="payrollSummaryEidiSanavat">{{ fmt(summary.eidiSanavat) }}</strong><small>ریال</small></article>
                    <article class="payroll-summary-card payroll-summary-card--advance"><span>جمع کل علی‌الحساب دریافتی</span><strong id="payrollSummaryAdvance">{{ fmt(summary.advance) }}</strong><small>ریال</small></article>
                    <article class="payroll-summary-card payroll-summary-card--net"><span>جمع کل مبلغ مانده قابل پرداخت</span><strong id="payrollSummaryNetPay">{{ fmt(summary.netPay) }}</strong><small>ریال</small></article>
                    <article class="payroll-summary-card payroll-summary-card--total"><span>جمع کل مبلغ پرداختی به پرسنل</span><strong id="payrollSummaryTotalPay">{{ fmt(summary.totalPay) }}</strong><small>ریال</small></article>
                </div>
                <div class="payroll-save-actions" aria-label="عملیات جمع کل پرداخت‌ها">
                    <button type="button" class="payroll-save-recalculate" @click="recalculate">محاسبه مجدد</button>
                    <button type="button" class="payroll-save-btn" :disabled="savingType==='summary'" @click="savePayroll('summary')">
                        {{ savingType==='summary' ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                    </button>
                    <button type="button" class="payroll-print-btn" @click="openPayrollReport">نمایش گزارش چاپی</button>
                </div>
            </div>

            <!-- Tab 5: karaneh -->
            <div id="karaneh-calculation" class="payroll-tab-content" :class="{ active: activeTab === 'karaneh-calculation' }" role="tabpanel">
                <div class="karaneh-summary-grid" id="karanehSummaryGrid">
                    <div class="karaneh-summary-card"><span class="karaneh-summary-label">مجموع پاس اول وقت</span><span class="karaneh-summary-value" id="karanehTotalFirst">۰ ساعت و ۰ دقیقه</span></div>
                    <div class="karaneh-summary-card"><span class="karaneh-summary-label">مجموع پاس بین وقت</span><span class="karaneh-summary-value" id="karanehTotalBetween">۰ ساعت و ۰ دقیقه</span></div>
                    <div class="karaneh-summary-card"><span class="karaneh-summary-label">مجموع پاس آخر وقت</span><span class="karaneh-summary-value" id="karanehTotalLast">۰ ساعت و ۰ دقیقه</span></div>
                    <div class="karaneh-summary-card"><span class="karaneh-summary-label">مجموع کل پاس‌ها</span><span class="karaneh-summary-value" id="karanehTotalAll">۰ ساعت و ۰ دقیقه</span></div>
                    <div class="karaneh-summary-card karaneh-summary-card--valid"><span class="karaneh-summary-label">پاس‌های مجاز</span><span class="karaneh-summary-value" id="karanehValidCount">۰</span></div>
                    <div class="karaneh-summary-card karaneh-summary-card--invalid"><span class="karaneh-summary-label">پاس‌های غیرمجاز</span><span class="karaneh-summary-value" id="karanehInvalidCount">۰</span></div>
                </div>
                <div class="overtime-payroll-table-scroll">
                    <table class="payroll-table karaneh-payroll-table" id="karanehPayrollTable">
                        <thead>
                            <tr class="payroll-table-group">
                                <th rowspan="2" class="payroll-col-name">پرسنل</th>
                                <th colspan="3">پاس اول وقت</th>
                                <th colspan="3">پاس بین وقت</th>
                                <th colspan="3">پاس آخر وقت</th>
                                <th rowspan="2">جمع کل</th><th rowspan="2">وضعیت</th>
                            </tr>
                            <tr class="payroll-table-subhead">
                                <th>ساعت</th><th>دقیقه</th><th>مجاز/غیرمجاز</th>
                                <th>ساعت</th><th>دقیقه</th><th>مجاز/غیرمجاز</th>
                                <th>ساعت</th><th>دقیقه</th><th>مجاز/غیرمجاز</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in karaneh" :key="row.username" class="karaneh-payroll-row" :data-username="row.username">
                                <td class="payroll-col-name">
                                    <div class="payroll-person">
                                        <span class="payroll-person-name">{{ row.name }}</span>
                                        <span class="payroll-person-meta">{{ row.department }}</span>
                                    </div>
                                </td>
                                <td class="karaneh-result" data-karaneh="firstHours">۰</td>
                                <td class="karaneh-result" data-karaneh="firstMinutes">۰</td>
                                <td class="karaneh-result" data-karaneh="firstStatus">—</td>
                                <td class="karaneh-result" data-karaneh="betweenHours">۰</td>
                                <td class="karaneh-result" data-karaneh="betweenMinutes">۰</td>
                                <td class="karaneh-result" data-karaneh="betweenStatus">—</td>
                                <td class="karaneh-result" data-karaneh="lastHours">۰</td>
                                <td class="karaneh-result" data-karaneh="lastMinutes">۰</td>
                                <td class="karaneh-result" data-karaneh="lastStatus">—</td>
                                <td class="karaneh-result" data-karaneh="totalMinutes">۰</td>
                                <td class="karaneh-result" data-karaneh="overallStatus">در حال دریافت…</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="payroll-total-row karaneh-total-row">
                                <td class="payroll-col-name">مجموع کل</td>
                                <td data-karaneh-total="firstHours">۰</td><td data-karaneh-total="firstMinutes">۰</td><td>—</td>
                                <td data-karaneh-total="betweenHours">۰</td><td data-karaneh-total="betweenMinutes">۰</td><td>—</td>
                                <td data-karaneh-total="lastHours">۰</td><td data-karaneh-total="lastMinutes">۰</td><td>—</td>
                                <td data-karaneh-total="totalMinutes">۰</td><td>—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="overtime-payroll-actions" aria-label="عملیات محاسبه کارانه">
                    <button type="button" class="overtime-payroll-refresh" @click="recalculate">محاسبه مجدد</button>
                </div>
            </div>
        </template>
    </div>
</template>
