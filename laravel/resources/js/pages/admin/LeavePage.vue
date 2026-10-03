<script setup>
/**
 * Leave approval — the legacy `vacationBox` requests tab.
 *
 * GET /get_leave_requests answers a bare array; the legacy table shows only
 * rows whose status is «انتظار تایید», lets the operator pick a new status
 * (تایید شده / رد شده / انصراف) and submits it with
 * POST /update_leave_status.  A row that was actually decided disappears
 * from the table, exactly as the legacy `removeRequestFromTable` did.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const PENDING = 'انتظار تایید';
const DECISIONS = ['تایید شده', 'رد شده', 'انصراف'];

const loading = ref(true);
const error = ref('');
const notice = ref('');
const activeTab = ref('requests');
const requests = ref([]);
const openRowId = ref(null);
const busyRowId = ref(null);
const reports = ref([]);
const reportsLoading = ref(true);
const reportUsers = ref([]);
const selectedUser = ref('all');
const fromDate = ref('');
const toDate = ref('');
const individualReports = ref([]);
const individualGenerated = ref(false);
const individualLoading = ref(false);
const individualError = ref('');

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return toPersianDigits(String(value).replace(/-/g, '/'));
}

async function loadRequests() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/get_leave_requests', { baseURL: '' });
        const rows = Array.isArray(response) ? response : [];

        requests.value = rows
            .filter((row) => row.status === PENDING)
            .map((row) => ({ ...row, decision: row.status }));
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت درخواست‌های مرخصی.';
        requests.value = [];
    } finally {
        loading.value = false;
    }
}

async function loadLeaveReports() {
    reportsLoading.value = true;

    try {
        const response = await api.get('/get_leave_reports', { baseURL: '' });
        reports.value = response.reports ?? [];
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت گزارشات مرخصی.';
        reports.value = [];
    } finally {
        reportsLoading.value = false;
    }
}

async function loadReportUsers() {
    try {
        const response = await api.get('/admin/coworkers/users', {
            params: { page: 1, per_page: 200 },
            baseURL: '',
        });
        reportUsers.value = (response.data ?? []).filter((user) => !user.is_active || user.is_active === 'active');
    } catch (failure) {
        individualError.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت فهرست کاربران.';
    }
}

function toLatinDigits(value) {
    return String(value ?? '').replace(/[۰-۹]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit));
}

function leaveStatusClass(status) {
    if (status === 'تایید شده') return 'approved';
    if (status === 'رد شده') return 'rejected';
    if (status === 'انصراف') return 'cancelled';
    return 'pending';
}

async function generateIndividualReport() {
    individualError.value = '';
    if (!fromDate.value || !toDate.value) {
        individualError.value = 'لطفاً همه فیلدها را پر کنید';
        return;
    }

    individualLoading.value = true;
    try {
        const response = await api.post('/generate_individual_report', {
            user: selectedUser.value === 'all' ? '' : selectedUser.value,
            fromDate: toLatinDigits(fromDate.value),
            toDate: toLatinDigits(toDate.value),
        }, { baseURL: '' });

        if (response.success !== true) {
            individualError.value = response.message || 'خطا در دریافت گزارش';
            return;
        }

        individualReports.value = (response.reports ?? []).map((row) => ({ ...row, decision: row.status || PENDING }));
        individualGenerated.value = true;
    } catch (failure) {
        individualError.value = failure.apiFailure?.message || failure.message || 'خطا در برقراری ارتباط';
    } finally {
        individualLoading.value = false;
    }
}

function downloadIndividualReport() {
    const approved = individualReports.value
        .filter((row) => row.decision === 'تایید شده')
        .map((row, index) => ({
            substitute: row.substitute || 'ندارد',
            days: toPersianDigits(row.days),
            end_date: toPersianDigits(row.end_date),
            start_date: toPersianDigits(row.start_date),
            row_number: toPersianDigits(index + 1),
        }));

    localStorage.setItem('reports', JSON.stringify(approved));
    localStorage.setItem('username', selectedUser.value);
    window.open('/leave_report_page', '_blank');
}

function toggleDropdown(rowId) {
    openRowId.value = openRowId.value === rowId ? null : rowId;
}

function chooseDecision(row, status) {
    row.decision = status;
    openRowId.value = null;
}

async function submitDecision(row) {
    if (row.decision === PENDING) {
        error.value = 'درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.';
        return;
    }

    busyRowId.value = row.id;
    error.value = '';
    notice.value = '';

    try {
        const response = await api.post(
            '/update_leave_status',
            {
                requestId: row.id,
                status: row.decision,
            },
            { baseURL: '' },
        );

        if (response.success === false) {
            error.value = response.message || 'خطا در به‌روزرسانی وضعیت!';
            return;
        }

        notice.value = response.message || 'وضعیت با موفقیت تغییر کرد!';
        requests.value = requests.value.filter((item) => item.id !== row.id);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در به‌روزرسانی وضعیت!';
    } finally {
        busyRowId.value = null;
    }
}

async function submitIndividualDecision(row) {
    if (row.decision === PENDING) {
        individualError.value = 'درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.';
        return;
    }

    busyRowId.value = row.id;
    individualError.value = '';
    notice.value = '';

    try {
        const response = await api.post('/update_leave_status', {
            requestId: row.id,
            status: row.decision,
        }, { baseURL: '' });

        if (response.success === false) {
            individualError.value = response.message || 'خطا در به‌روزرسانی وضعیت!';
            return;
        }

        notice.value = response.message || 'وضعیت با موفقیت تغییر کرد!';
    } catch (failure) {
        individualError.value = failure.apiFailure?.message || failure.message || 'خطا در به‌روزرسانی وضعیت!';
    } finally {
        busyRowId.value = null;
    }
}

onMounted(() => {
    loadRequests();
    loadLeaveReports();
    loadReportUsers();
});
</script>

<template>
        <header
            class="section-hero"
            style="--hero-accent:#f97316;--hero-accent-2:#fb923c;--hero-glow-1:rgba(249,115,22,.14);--hero-glow-2:rgba(251,146,60,.12);--hero-shadow:rgba(249,115,22,.55);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(249,115,22,.08);"
        >
            <div class="section-hero__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="5" width="14.5" height="16" rx="3.5" stroke="#fff" stroke-width="1.9"/><path d="M3 10h14.5" stroke="#fff" stroke-width="1.9"/><path d="M7 2.8V6M13.5 2.8V6" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><path d="M6.8 14.3h3.6" stroke="#fff" stroke-width="1.7" stroke-linecap="round" opacity=".7"/><circle cx="17" cy="17" r="5.2" fill="#fff"/><path d="m14.7 17.1 1.6 1.6 3.1-3.4" stroke="#EA580C" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div class="section-hero__text">
                <h2>مدیریت مرخصی پرسنل</h2>
                <p>درخواست‌ها و گزارشات مرخصی پرسنل را مدیریت کنید</p>
            </div>
            <div class="section-hero__glow" aria-hidden="true"></div>
        </header>

        <div class="vacation-frame">
            <div class="vacation-tabs" role="tablist">
                <button type="button" class="vacation-tab-btn" :class="{ active: activeTab === 'requests' }" role="tab" :aria-selected="activeTab === 'requests'" @click="activeTab = 'requests'">درخواست‌های مرخصی</button>
                <button type="button" class="vacation-tab-btn" :class="{ active: activeTab === 'reports' }" role="tab" :aria-selected="activeTab === 'reports'" @click="activeTab = 'reports'">گزارشات مرخصی</button>
                <button type="button" class="vacation-tab-btn" :class="{ active: activeTab === 'individual' }" role="tab" :aria-selected="activeTab === 'individual'" @click="activeTab = 'individual'">گزارش انفرادی</button>
            </div>

            <div id="leave-requests" class="vacation-tab-content" :class="{ active: activeTab === 'requests' }" role="tabpanel">
                <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
                <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>
                <table id="leaveRequestsTable" class="request-table">
                    <thead>
                        <tr>
                            <th class="sabt-morkhc-drkhst">نام کاربر</th>
                            <th class="vaziat-morkhc-drkhst">از تاریخ</th>
                            <th class="jnshn-morkhc-drkhst">تا تاریخ</th>
                            <th class="rooz-morkhc-drkhst">تعداد روزها</th>
                            <th class="tatrkh-morkhc-drkhst">جانشین</th>
                            <th class="aztrkh-morkhc-drkhst">وضعیت درخواست</th>
                            <th class="krbr-morkhc-drkhst">ثبت تغییرات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading"><td colspan="7">در حال دریافت درخواست‌ها…</td></tr>
                        <tr v-else v-for="row in requests" :key="row.id" :id="`row_${row.id}`">
                            <td class="sabt-morkhc-drkhst">{{ toPersianDigits(row.username) }}</td>
                            <td class="vaziat-morkhc-drkhst">{{ formatDate(row.start_date) }}</td>
                            <td class="jnshn-morkhc-drkhst">{{ formatDate(row.end_date) }}</td>
                            <td class="rooz-morkhc-drkhst">{{ toPersianDigits(row.days) }}</td>
                            <td class="tatrkh-morkhc-drkhst">{{ toPersianDigits(row.substitute || '') }}</td>
                            <td class="aztrkh-morkhc-drkhst">
                                <div class="status-container">
                                    <div class="status-navbar" :class="leaveStatusClass(row.decision)" :aria-expanded="openRowId === row.id" @click="toggleDropdown(row.id)">{{ toPersianDigits(row.decision) }}</div>
                                    <div class="status-dropdown" :style="{ display: openRowId === row.id ? 'flex' : 'none' }">
                                        <button v-for="status in DECISIONS" :key="status" type="button" class="status-option" :class="leaveStatusClass(status)" @click="chooseDecision(row, status)">{{ status }}</button>
                                    </div>
                                </div>
                            </td>
                            <td class="krbr-morkhc-drkhst"><button type="button" class="update-button" :disabled="busyRowId === row.id" @click="submitDecision(row)">تایید تغییرات</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="vacation-reports" class="vacation-tab-content" :class="{ active: activeTab === 'reports' }" role="tabpanel">
                <h2>گزارشات مرخصی‌ها</h2>
                <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
                <table class="vacation-table">
                    <thead><tr><th class="bghmnd-morkhc-gzrsh">کل مرخصی‌های باقی‌مانده</th><th class="stfdeh-morkhc-gzrsh">کل مرخصی‌های استفاده شده</th><th class="krbr-morkhc-gzrsh">نام کاربر</th></tr></thead>
                    <tbody>
                        <tr v-if="reportsLoading"><td colspan="3">در حال دریافت گزارش‌ها…</td></tr>
                        <tr v-else v-for="report in reports" :key="report.username">
                            <td class="bghmnd-morkhc-gzrsh">{{ toPersianDigits(report.remaining_days) }}</td>
                            <td class="stfdeh-morkhc-gzrsh">{{ toPersianDigits(report.total_days) }}</td>
                            <td class="krbr-morkhc-gzrsh">{{ toPersianDigits(report.username) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="individual-report" class="vacation-tab-content" :class="{ active: activeTab === 'individual' }" role="tabpanel">
                <div class="vacation-config">
                    <div class="vacation-config-title">پارامترهای گزارش</div>
                    <div class="vacation-config-grid">
                        <div class="vacation-config-item">
                            <span>انتخاب کاربر</span>
                            <select v-model="selectedUser" id="userSelect">
                                <option value="" disabled>انتخاب کنید</option>
                                <option value="all">همه کاربران</option>
                                <option v-for="user in reportUsers" :key="user.username" :value="user.username">{{ user.username }}</option>
                            </select>
                        </div>
                        <div class="vacation-config-item"><span>از تاریخ</span><input v-model="fromDate" type="text" id="fromDate" name="fromDate" placeholder="1404/01/01" autocomplete="off"></div>
                        <div class="vacation-config-item"><span>تا تاریخ</span><input v-model="toDate" type="text" id="toDate" name="toDate" placeholder="1404/01/01" autocomplete="off"></div>
                        <div class="vacation-config-item vacation-config-actions"><button type="button" id="generategozareshmrkReportBtn" :disabled="individualLoading" @click="generateIndividualReport">{{ individualLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}</button></div>
                    </div>
                    <p v-if="individualError" class="h-alert" role="alert">{{ individualError }}</p>
                </div>

                <div v-if="individualGenerated" id="vacationReportResult" class="vacation-report-result">
                    <div class="vacation-report-toolbar"><button id="downloadReportBtn" type="button" @click="downloadIndividualReport">دریافت گزارش</button></div>
                    <table id="individualReportTablemrkc" class="individual-report-table" data-rt="cards">
                        <thead><tr><th class="sbt-morkhc-nfrdi">ثبت تغییرات</th><th class="drkhst-morkhc-nfrdi">وضعیت درخواست</th><th class="jnshn-morkhc-nfrdi">جانشین</th><th class="rooz-morkhc-nfrdi">تعداد روز</th><th class="tatrkh-morkhc-nfrdi">تا تاریخ</th><th class="aztarkh-morkhc-nfrdi">از تاریخ</th><th class="krbr-morkhc-nfrdi">نام کاربر</th><th class="rdf-morkhc-nfrdi">ردیف</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, index) in individualReports" :key="row.id" :id="`individual-row-${row.id}`">
                                <td class="sbt-morkhc-nfrdi"><button type="button" class="update-button" :disabled="busyRowId === row.id" @click="submitIndividualDecision(row)">تایید تغییرات</button></td>
                                <td class="drkhst-morkhc-nfrdi"><div class="status-container"><div class="status-navbar" :class="leaveStatusClass(row.decision)" @click="toggleDropdown(row.id)">{{ toPersianDigits(row.decision) }}</div><div class="status-dropdown" :style="{ display: openRowId === row.id ? 'flex' : 'none' }"><button v-for="status in DECISIONS" :key="status" type="button" class="status-option" :class="leaveStatusClass(status)" @click="chooseDecision(row, status)">{{ status }}</button></div></div></td>
                                <td class="jnshn-morkhc-nfrdi">{{ toPersianDigits(row.substitute || 'ندارد') }}</td>
                                <td class="rooz-morkhc-nfrdi">{{ toPersianDigits(row.days) }}</td>
                                <td class="tatrkh-morkhc-nfrdi">{{ toPersianDigits(row.end_date) }}</td>
                                <td class="aztarkh-morkhc-nfrdi">{{ toPersianDigits(row.start_date) }}</td>
                                <td class="krbr-morkhc-nfrdi">{{ toPersianDigits(row.username || (selectedUser === 'all' ? 'همه کاربران' : selectedUser)) }}</td>
                                <td class="rdf-morkhc-nfrdi">{{ toPersianDigits(index + 1) }}</td>
                            </tr>
                            <tr v-if="individualReports.length === 0"><td colspan="8">داده‌ای برای این بازه یافت نشد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
</template>

<style scoped>
.leave {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.leave__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.leave__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.leave__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .leave__sub {
    color: var(--dk-text-2);
}

.leave__loading {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #64748b;
}

[data-theme='dark'] .leave__loading {
    color: var(--dk-text-2);
}

.leave__table-scroll {
    overflow-x: auto;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
}

[data-theme='dark'] .leave__table-scroll {
    border-color: var(--dk-border);
}

.leave__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    background: #fff;
}

[data-theme='dark'] .leave__table {
    background: var(--dk-surface);
}

.leave__table th,
.leave__table td {
    padding: 0.65rem 0.7rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .leave__table th,
[data-theme='dark'] .leave__table td {
    border-bottom-color: var(--dk-line);
}

.leave__table th {
    color: #64748b;
    font-size: 0.75rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .leave__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.leave__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .leave__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.leave__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .leave__empty {
    color: var(--dk-text-3);
}

.leave__status {
    position: relative;
}

.leave__status-btn {
    min-width: 7.5rem;
    padding: 0.35rem 0.8rem;
    border: 1px solid rgb(245 158 11 / 0.45);
    border-radius: 999px;
    background: rgb(245 158 11 / 0.12);
    color: #b45309;
    font: inherit;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
}

[data-theme='dark'] .leave__status-btn {
    color: #fcd34d;
}

.leave__status-menu {
    position: absolute;
    top: calc(100% + 4px);
    inset-inline-start: 0;
    z-index: 10;
    display: flex;
    flex-direction: column;
    min-width: 9rem;
    padding: 0.3rem;
    border: 1px solid rgb(15 23 42 / 0.12);
    border-radius: var(--radius-token-sm);
    background: #fff;
    box-shadow: 0 10px 30px rgb(15 23 42 / 0.16);
}

[data-theme='dark'] .leave__status-menu {
    border-color: var(--dk-border);
    background: var(--dk-surface-2);
}

.leave__status-option {
    padding: 0.45rem 0.7rem;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 0.8rem;
    text-align: start;
    cursor: pointer;
}

.leave__status-option:hover {
    background: rgb(14 165 233 / 0.1);
}

.leave__submit {
    padding: 0.4rem 0.9rem;
    font-size: 0.78rem;
}
</style>
