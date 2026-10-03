<script setup>
/**
 * Overtime approval — the legacy `overtimeBox`.
 *
 * Tab 1 (درخواست‌های اضافه‌کاری): GET /get_overtime_requests answers a bare
 * array; the legacy table keeps only «انتظار تایید» rows, each with a status
 * dropdown submitted through POST /update_overtime_status.  A decided row
 * disappears, as in the legacy `applyStatusChangeForApproval`.
 *
 * Tab 2 (گزارش کلی): GET /overtime_report — the org-wide totals from
 * ezafe_total_table.  NOTE: the ported endpoint reproduces the legacy 500
 * (the `overtime_report` template is missing from this installation), so
 * this tab renders the server's error rather than a table.
 *
 * Tab 3 (گزارش انفرادی): POST /get_overtime_report returns every overtime
 * row in a Jalali range; decided rows can be re-decided with
 * POST /update_overtime_Indivisual_status, and «دریافت گزارش» stores the
 * approved rows in localStorage and opens /overtime_report_page.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const PENDING = 'انتظار تایید';
const DECIDED = ['تایید شده', 'رد شده', 'انصراف'];
const DECISIONS = ['تایید شده', 'رد شده', 'انصراف'];

const activeTab = ref('requests');

const loading = ref(true);
const error = ref('');
const notice = ref('');
const requests = ref([]);
const openRowId = ref(null);
const busyRowId = ref(null);

const allReport = ref([]);
const allReportLoading = ref(false);
const allReportError = ref('');

const reportForm = reactive({
    username: '',
    startDate: '',
    endDate: '',
});

const users = ref([]);
const reportRows = ref([]);
const reportLoading = ref(false);
const reportError = ref('');
const reportBusyId = ref(null);
const reportGenerated = ref(false);

async function loadRequests() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/get_overtime_requests');
        const rows = Array.isArray(response) ? response : [];

        requests.value = rows
            .filter((row) => row.status === PENDING)
            .map((row) => ({ ...row, decision: row.status }));
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت درخواست‌های اضافه‌کاری.';
        requests.value = [];
    } finally {
        loading.value = false;
    }
}

async function loadUsers() {
    try {
        const response = await api.get('/get_users');
        users.value = response.users ?? [];
    } catch {
        users.value = [];
    }
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
        const response = await api.post('/update_overtime_status', {
            requestId: row.id,
            status: row.decision,
        });

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

async function loadAllReport() {
    allReportLoading.value = true;
    allReportError.value = '';
    allReport.value = [];

    try {
        const response = await api.get('/overtime_report');
        allReport.value = Array.isArray(response) ? response : [];
    } catch (failure) {
        allReportError.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت گزارش کلی.';
    } finally {
        allReportLoading.value = false;
    }
}

async function generateReport() {
    if (!reportForm.username || !reportForm.startDate || !reportForm.endDate) {
        reportError.value = 'لطفاً تمام فیلدها را پر کنید.';
        return;
    }

    reportLoading.value = true;
    reportError.value = '';
    reportGenerated.value = false;

    try {
        const response = await api.post('/get_overtime_report', {
            username: reportForm.username,
            start_date: toLatinDigits(reportForm.startDate),
            end_date: toLatinDigits(reportForm.endDate),
        });

        const rows = Array.isArray(response) ? response : [];
        reportRows.value = rows
            .filter((row) => DECIDED.includes(row.status))
            .map((row) => ({ ...row, decision: row.status }));
        reportGenerated.value = true;
    } catch (failure) {
        reportError.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت داده‌ها.';
        reportRows.value = [];
    } finally {
        reportLoading.value = false;
    }
}

function chooseReportDecision(row, status) {
    row.decision = status;
}

async function submitReportDecision(row) {
    reportBusyId.value = row.id;
    reportError.value = '';

    try {
        const response = await api.post('/update_overtime_Indivisual_status', {
            id: row.id,
            status: row.decision,
        });

        if (response.success === false) {
            reportError.value = response.message || 'خطا در تغییر وضعیت.';
            return;
        }

        notice.value = 'وضعیت با موفقیت تغییر کرد.';
    } catch (failure) {
        reportError.value = failure.apiFailure?.message || failure.message || 'خطا در ارسال درخواست.';
    } finally {
        reportBusyId.value = null;
    }
}

function downloadReport() {
    const approved = reportRows.value
        .filter((row) => row.decision === 'تایید شده')
        .map((row) => ({
            description: row.description,
            daily_overtime: row.daily_overtime,
            overtime_date: row.overtime_date,
            username: row.username,
        }));

    localStorage.setItem('overtimeReports', JSON.stringify(approved));
    localStorage.setItem('username', reportForm.username);
    window.open('/overtime_report_page', '_blank');
}

onMounted(() => {
    loadRequests();
    loadUsers();
});
</script>

<template>
    <section class="overtime">
        <header class="overtime__head">
            <div>
                <h1 class="overtime__title">مدیریت اضافه‌کاری‌ها</h1>
                <p class="overtime__sub">درخواست‌ها و گزارشات اضافه‌کاری پرسنل</p>
            </div>
        </header>

        <div class="overtime__tabs" role="tablist">
            <button
                type="button"
                class="overtime__tab"
                :class="{ 'is-active': activeTab === 'requests' }"
                role="tab"
                :aria-selected="activeTab === 'requests'"
                @click="activeTab = 'requests'"
            >
                درخواست‌های اضافه‌کاری
            </button>
            <button
                type="button"
                class="overtime__tab"
                :class="{ 'is-active': activeTab === 'all' }"
                role="tab"
                :aria-selected="activeTab === 'all'"
                @click="activeTab = 'all'; loadAllReport()"
            >
                گزارش کلی
            </button>
            <button
                type="button"
                class="overtime__tab"
                :class="{ 'is-active': activeTab === 'report' }"
                role="tab"
                :aria-selected="activeTab === 'report'"
                @click="activeTab = 'report'"
            >
                گزارش انفرادی
            </button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div v-show="activeTab === 'requests'">
            <div class="overtime__toolbar">
                <button type="button" class="h-btn h-btn-ghost" :disabled="loading" @click="loadRequests">
                    {{ loading ? 'در حال دریافت…' : 'بروزرسانی' }}
                </button>
            </div>

            <div v-if="loading" class="overtime__loading">در حال دریافت درخواست‌ها…</div>

            <div v-else class="overtime__table-scroll">
                <table class="overtime__table">
                    <thead>
                        <tr>
                            <th>نام کاربر</th>
                            <th>تاریخ درخواست</th>
                            <th>مدت زمان اضافه کاری</th>
                            <th>توضیحات</th>
                            <th>وضعیت درخواست</th>
                            <th>ثبت تغییرات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in requests" :key="row.id">
                            <td>{{ row.username }}</td>
                            <td>{{ toPersianDigits(row.overtime_date) }}</td>
                            <td>{{ toPersianDigits(row.daily_overtime) }}</td>
                            <td class="overtime__desc">{{ row.description || '—' }}</td>
                            <td>
                                <div class="overtime__status">
                                    <button
                                        type="button"
                                        class="overtime__status-btn"
                                        :aria-expanded="openRowId === row.id"
                                        @click="toggleDropdown(row.id)"
                                    >
                                        {{ row.decision }}
                                    </button>
                                    <div v-if="openRowId === row.id" class="overtime__status-menu" role="menu">
                                        <button
                                            v-for="status in DECISIONS"
                                            :key="status"
                                            type="button"
                                            class="overtime__status-option"
                                            role="menuitem"
                                            @click="chooseDecision(row, status)"
                                        >
                                            {{ status }}
                                        </button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="h-btn h-btn-primary overtime__submit"
                                    :disabled="busyRowId === row.id"
                                    @click="submitDecision(row)"
                                >
                                    ثبت تغییرات
                                </button>
                            </td>
                        </tr>
                        <tr v-if="requests.length === 0">
                            <td colspan="6" class="overtime__empty">درخواست اضافه‌کاری در انتظاری وجود ندارد.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-show="activeTab === 'all'">
            <div v-if="allReportLoading" class="overtime__loading">در حال دریافت گزارش کلی…</div>

            <div v-else-if="allReportError" class="h-alert" role="alert">{{ allReportError }}</div>

            <div v-else class="overtime__table-scroll">
                <table class="overtime__table">
                    <thead>
                        <tr>
                            <th>ردیف</th>
                            <th>نام کاربر</th>
                            <th>کل مدت زمان اضافه کاری</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in allReport" :key="row.username">
                            <td>{{ toPersianDigits(row.row_number) }}</td>
                            <td>{{ row.username }}</td>
                            <td>{{ toPersianDigits(row.total_ezafe_time) }}</td>
                        </tr>
                        <tr v-if="allReport.length === 0">
                            <td colspan="3" class="overtime__empty">داده‌ای برای نمایش نیست.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-show="activeTab === 'report'" class="overtime__report">
            <div class="overtime__config">
                <span class="overtime__config-title">پارامترهای گزارش</span>
                <div class="overtime__config-grid">
                    <label class="h-field">
                        <span class="h-field__label">انتخاب کاربر</span>
                        <select v-model="reportForm.username" class="h-input" required>
                            <option value="" disabled>انتخاب کنید</option>
                            <option value="all_users">همه کاربران</option>
                            <option v-for="user in users" :key="user.value" :value="user.value">
                                {{ user.label || user.value }}
                            </option>
                        </select>
                    </label>
                    <label class="h-field">
                        <span class="h-field__label">از تاریخ</span>
                        <input v-model="reportForm.startDate" type="text" class="h-input" placeholder="۱۴۰۵/۰۱/۰۱" required>
                    </label>
                    <label class="h-field">
                        <span class="h-field__label">تا تاریخ</span>
                        <input v-model="reportForm.endDate" type="text" class="h-input" placeholder="۱۴۰۵/۰۱/۰۱" required>
                    </label>
                    <div class="overtime__config-actions">
                        <button type="button" class="h-btn h-btn-primary" :disabled="reportLoading" @click="generateReport">
                            {{ reportLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="reportError" class="h-alert" role="alert">{{ reportError }}</p>

            <div v-if="reportGenerated" class="overtime__result">
                <div class="overtime__result-toolbar">
                    <button type="button" class="h-btn h-btn-primary" @click="downloadReport">
                        دریافت گزارش
                    </button>
                </div>

                <div class="overtime__table-scroll">
                    <table class="overtime__table">
                        <thead>
                            <tr>
                                <th>ثبت تغییرات</th>
                                <th>وضعیت درخواست</th>
                                <th>توضیحات</th>
                                <th>مدت زمان اضافه کاری</th>
                                <th>تاریخ درخواست</th>
                                <th>نام کاربر</th>
                                <th>ردیف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in reportRows" :key="row.id">
                                <td>
                                    <button
                                        type="button"
                                        class="h-btn h-btn-primary overtime__submit"
                                        :disabled="reportBusyId === row.id"
                                        @click="submitReportDecision(row)"
                                    >
                                        تایید تغییرات
                                    </button>
                                </td>
                                <td>
                                    <div class="overtime__status">
                                        <button
                                            type="button"
                                            class="overtime__status-btn"
                                            @click="row.decisionOpen = !row.decisionOpen"
                                        >
                                            {{ row.decision }}
                                        </button>
                                        <div v-if="row.decisionOpen" class="overtime__status-menu" role="menu">
                                            <button
                                                v-for="status in DECISIONS"
                                                :key="status"
                                                type="button"
                                                class="overtime__status-option"
                                                role="menuitem"
                                                @click="chooseReportDecision(row, status); row.decisionOpen = false"
                                            >
                                                {{ status }}
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td class="overtime__desc">{{ row.description || '—' }}</td>
                                <td>{{ toPersianDigits(row.daily_overtime) }}</td>
                                <td>{{ toPersianDigits(row.overtime_date) }}</td>
                                <td>{{ row.username }}</td>
                                <td>{{ toPersianDigits(index + 1) }}</td>
                            </tr>
                            <tr v-if="reportRows.length === 0">
                                <td colspan="7" class="overtime__empty">داده‌ای برای این بازه یافت نشد.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.overtime {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.overtime__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.overtime__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .overtime__sub {
    color: var(--dk-text-2);
}

.overtime__tabs {
    display: flex;
    gap: 0.4rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.1);
    flex-wrap: wrap;
}

[data-theme='dark'] .overtime__tabs {
    border-bottom-color: var(--dk-line);
}

.overtime__tab {
    padding: 0.55rem 1rem;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: #64748b;
    font: inherit;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
}

[data-theme='dark'] .overtime__tab {
    color: var(--dk-text-2);
}

.overtime__tab.is-active {
    border-bottom-color: var(--c-primary);
    color: var(--c-primary-dark);
}

[data-theme='dark'] .overtime__tab.is-active {
    color: var(--dk-accent);
}

.overtime__toolbar {
    display: flex;
    justify-content: flex-start;
}

.overtime__loading {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #64748b;
}

[data-theme='dark'] .overtime__loading {
    color: var(--dk-text-2);
}

.overtime__table-scroll {
    overflow-x: auto;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
}

[data-theme='dark'] .overtime__table-scroll {
    border-color: var(--dk-border);
}

.overtime__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    background: #fff;
}

[data-theme='dark'] .overtime__table {
    background: var(--dk-surface);
}

.overtime__table th,
.overtime__table td {
    padding: 0.65rem 0.7rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .overtime__table th,
[data-theme='dark'] .overtime__table td {
    border-bottom-color: var(--dk-line);
}

.overtime__table th {
    color: #64748b;
    font-size: 0.75rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .overtime__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.overtime__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .overtime__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.overtime__desc {
    max-width: 22rem;
    overflow: hidden;
    text-overflow: ellipsis;
}

.overtime__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .overtime__empty {
    color: var(--dk-text-3);
}

.overtime__status {
    position: relative;
}

.overtime__status-btn {
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

[data-theme='dark'] .overtime__status-btn {
    color: #fcd34d;
}

.overtime__status-menu {
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

[data-theme='dark'] .overtime__status-menu {
    border-color: var(--dk-border);
    background: var(--dk-surface-2);
}

.overtime__status-option {
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

.overtime__status-option:hover {
    background: rgb(14 165 233 / 0.1);
}

.overtime__submit {
    padding: 0.4rem 0.9rem;
    font-size: 0.78rem;
}

.overtime__config {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    padding: 1rem 1.1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .overtime__config {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.overtime__config-title {
    font-size: 0.85rem;
    font-weight: 800;
}

.overtime__config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.8rem;
    align-items: end;
}

.overtime__result {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}

.overtime__result-toolbar {
    display: flex;
    justify-content: flex-start;
}
</style>
