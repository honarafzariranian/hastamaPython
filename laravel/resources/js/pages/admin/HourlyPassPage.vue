<script setup>
/**
 * Hourly pass approval — the legacy `hourlyPassBox`.
 *
 * Tab 1 (درخواست‌های پاس ساعتی): GET /get_hourly_pass_requests answers a bare
 * array of pending rows; the operator picks a status and submits with
 * POST /change_hourly_pass_status.  A decided row disappears, as in the
 * legacy `applyStatusChangeForHourlyPass`.
 *
 * Tab 2 (گزارش انفرادی): POST /get_hourly_pass_report returns every decided
 * pass in a Jalali range; each row's status can be changed with
 * POST /update_hourly_pass_status, and «دریافت گزارش» stores the approved
 * rows in localStorage and opens the server-rendered
 * /hourlypass_Report_page — the legacy download flow.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const DECISIONS = ['تایید شده', 'رد شده', 'انصراف'];

const activeTab = ref('requests');

const loading = ref(true);
const error = ref('');
const notice = ref('');
const requests = ref([]);
const openRowId = ref(null);
const busyRowId = ref(null);

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

function formatDuration(value) {
    if (!value || value === 'None') {
        return '—';
    }

    const parts = String(value).split(':');

    return toPersianDigits(`${parts[0] ?? '00'}:${parts[1] ?? '00'}`);
}

async function loadRequests() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/get_hourly_pass_requests');
        const rows = Array.isArray(response) ? response : [];

        requests.value = rows.map((row) => ({ ...row, decision: row.status }));
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت درخواست‌های پاس ساعتی.';
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
    if (row.decision === 'انتظار تایید') {
        error.value = 'درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.';
        return;
    }

    busyRowId.value = row.id;
    error.value = '';
    notice.value = '';

    try {
        const response = await api.post('/change_hourly_pass_status', {
            id: row.id,
            status: row.decision,
        });

        if (response.success === false) {
            error.value = response.message || 'خطا در به‌روزرسانی وضعیت!';
            return;
        }

        notice.value = 'وضعیت با موفقیت تغییر کرد!';
        requests.value = requests.value.filter((item) => item.id !== row.id);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در به‌روزرسانی وضعیت!';
    } finally {
        busyRowId.value = null;
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
        const response = await api.post('/get_hourly_pass_report', {
            username: reportForm.username,
            start_date: toLatinDigits(reportForm.startDate),
            end_date: toLatinDigits(reportForm.endDate),
        });

        const rows = Array.isArray(response) ? response : [];
        reportRows.value = rows.map((row) => ({ ...row, decision: row.status }));
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
        const response = await api.post('/update_hourly_pass_status', {
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
        .map((row, index) => ({
            index: index + 1,
            requestDate: row.request_date,
            passTitle: row.pass_title,
            passDuration: row.pass_duration,
        }));

    localStorage.setItem('hourlyPassReportData', JSON.stringify(approved));
    localStorage.setItem('hourlyPassUsername', reportForm.username);
    window.open('/hourlypass_Report_page', '_blank');
}

onMounted(() => {
    loadRequests();
    loadUsers();
});
</script>

<template>
    <section class="pass">
        <header class="pass__head">
            <div>
                <h1 class="pass__title">مدیریت پاس‌های ساعتی</h1>
                <p class="pass__sub">مدیریت پاس‌های ساعتی و گزارشات مرتبط</p>
            </div>
        </header>

        <div class="pass__tabs" role="tablist">
            <button
                type="button"
                class="pass__tab"
                :class="{ 'is-active': activeTab === 'requests' }"
                role="tab"
                :aria-selected="activeTab === 'requests'"
                @click="activeTab = 'requests'"
            >
                درخواست‌های پاس ساعتی
            </button>
            <button
                type="button"
                class="pass__tab"
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
            <div class="pass__toolbar">
                <button type="button" class="h-btn h-btn-ghost" :disabled="loading" @click="loadRequests">
                    {{ loading ? 'در حال دریافت…' : 'بروزرسانی' }}
                </button>
            </div>

            <div v-if="loading" class="pass__loading">در حال دریافت درخواست‌ها…</div>

            <div v-else class="pass__table-scroll">
                <table class="pass__table">
                    <thead>
                        <tr>
                            <th>نام کاربر</th>
                            <th>تاریخ درخواست</th>
                            <th>عنوان پاس</th>
                            <th>مدت زمان پاس</th>
                            <th>وضعیت درخواست</th>
                            <th>ثبت تغییرات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in requests" :key="row.id">
                            <td>{{ row.username }}</td>
                            <td>{{ toPersianDigits(row.request_date) }}</td>
                            <td>{{ row.pass_title || '—' }}</td>
                            <td>{{ formatDuration(row.pass_duration) }}</td>
                            <td>
                                <div class="pass__status">
                                    <button
                                        type="button"
                                        class="pass__status-btn"
                                        :aria-expanded="openRowId === row.id"
                                        @click="toggleDropdown(row.id)"
                                    >
                                        {{ row.decision }}
                                    </button>
                                    <div v-if="openRowId === row.id" class="pass__status-menu" role="menu">
                                        <button
                                            v-for="status in DECISIONS"
                                            :key="status"
                                            type="button"
                                            class="pass__status-option"
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
                                    class="h-btn h-btn-primary pass__submit"
                                    :disabled="busyRowId === row.id"
                                    @click="submitDecision(row)"
                                >
                                    ثبت تغییرات
                                </button>
                            </td>
                        </tr>
                        <tr v-if="requests.length === 0">
                            <td colspan="6" class="pass__empty">درخواست پاس ساعتی در انتظاری وجود ندارد.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-show="activeTab === 'report'" class="pass__report">
            <div class="pass__config">
                <span class="pass__config-title">تنظیمات گزارش</span>
                <div class="pass__config-grid">
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
                    <div class="pass__config-actions">
                        <button type="button" class="h-btn h-btn-primary" :disabled="reportLoading" @click="generateReport">
                            {{ reportLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="reportError" class="h-alert" role="alert">{{ reportError }}</p>

            <div v-if="reportGenerated" class="pass__result">
                <div class="pass__result-toolbar">
                    <button type="button" class="h-btn h-btn-primary" @click="downloadReport">
                        دریافت گزارش
                    </button>
                </div>

                <div class="pass__table-scroll">
                    <table class="pass__table">
                        <thead>
                            <tr>
                                <th>ثبت تغییرات</th>
                                <th>وضعیت درخواست</th>
                                <th>مدت زمان پاس</th>
                                <th>عنوان پاس</th>
                                <th>تاریخ درخواست</th>
                                <th>نام کاربر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in reportRows" :key="row.id">
                                <td>
                                    <button
                                        type="button"
                                        class="h-btn h-btn-primary pass__submit"
                                        :disabled="reportBusyId === row.id"
                                        @click="submitReportDecision(row)"
                                    >
                                        تأیید تغییرات
                                    </button>
                                </td>
                                <td>
                                    <div class="pass__status">
                                        <button type="button" class="pass__status-btn" @click="row.decisionOpen = !row.decisionOpen">
                                            {{ row.decision }}
                                        </button>
                                        <div v-if="row.decisionOpen" class="pass__status-menu" role="menu">
                                            <button
                                                v-for="status in DECISIONS"
                                                :key="status"
                                                type="button"
                                                class="pass__status-option"
                                                role="menuitem"
                                                @click="chooseReportDecision(row, status); row.decisionOpen = false"
                                            >
                                                {{ status }}
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ formatDuration(row.pass_duration) }}</td>
                                <td>{{ row.pass_title || '—' }}</td>
                                <td>{{ toPersianDigits(row.request_date) }}</td>
                                <td>{{ row.username }}</td>
                            </tr>
                            <tr v-if="reportRows.length === 0">
                                <td colspan="6" class="pass__empty">داده‌ای برای این بازه یافت نشد.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.pass {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.pass__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.pass__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .pass__sub {
    color: var(--dk-text-2);
}

.pass__tabs {
    display: flex;
    gap: 0.4rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.1);
}

[data-theme='dark'] .pass__tabs {
    border-bottom-color: var(--dk-line);
}

.pass__tab {
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

[data-theme='dark'] .pass__tab {
    color: var(--dk-text-2);
}

.pass__tab.is-active {
    border-bottom-color: var(--c-primary);
    color: var(--c-primary-dark);
}

[data-theme='dark'] .pass__tab.is-active {
    color: var(--dk-accent);
}

.pass__toolbar {
    display: flex;
    justify-content: flex-start;
}

.pass__loading {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #64748b;
}

[data-theme='dark'] .pass__loading {
    color: var(--dk-text-2);
}

.pass__table-scroll {
    overflow-x: auto;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
}

[data-theme='dark'] .pass__table-scroll {
    border-color: var(--dk-border);
}

.pass__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    background: #fff;
}

[data-theme='dark'] .pass__table {
    background: var(--dk-surface);
}

.pass__table th,
.pass__table td {
    padding: 0.65rem 0.7rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .pass__table th,
[data-theme='dark'] .pass__table td {
    border-bottom-color: var(--dk-line);
}

.pass__table th {
    color: #64748b;
    font-size: 0.75rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .pass__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.pass__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .pass__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.pass__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .pass__empty {
    color: var(--dk-text-3);
}

.pass__status {
    position: relative;
}

.pass__status-btn {
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

[data-theme='dark'] .pass__status-btn {
    color: #fcd34d;
}

.pass__status-menu {
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

[data-theme='dark'] .pass__status-menu {
    border-color: var(--dk-border);
    background: var(--dk-surface-2);
}

.pass__status-option {
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

.pass__status-option:hover {
    background: rgb(14 165 233 / 0.1);
}

.pass__submit {
    padding: 0.4rem 0.9rem;
    font-size: 0.78rem;
}

.pass__config {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    padding: 1rem 1.1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .pass__config {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.pass__config-title {
    font-size: 0.85rem;
    font-weight: 800;
}

.pass__config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.8rem;
    align-items: end;
}

.pass__result {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}

.pass__result-toolbar {
    display: flex;
    justify-content: flex-start;
}
</style>
