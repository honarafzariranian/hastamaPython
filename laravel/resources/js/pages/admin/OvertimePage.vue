<script setup>
/**
 * Overtime approval — the legacy `overtimeBox`.
 *
 * Tab 1 (درخواست‌های اضافه‌کاری): GET /get_overtime_requests answers a bare
 * array; the legacy table keeps only «انتظار تایید» rows, each with a status
 * dropdown submitted through POST /update_overtime_status.  A decided row
 * disappears, as in the legacy `applyStatusChangeForApproval`.
 *
 * Tab 2 (گزارش کلی): GET /admin/overtime/reports — the totals rendered in the
 * Python admin template, sorted by duration.
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

function getStatusClass(status) {
    if (status === 'تایید شده') return 'approved-status';
    if (status === 'رد شده') return 'rejected-status';
    if (status === 'انصراف') return 'cancelled-status';
    return 'pending-status';
}

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
        const response = await api.get('/get_overtime_requests', { baseURL: '' });
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
        const response = await api.get('/get_users', { baseURL: '' });
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
        const response = await api.post(
            '/update_overtime_status',
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

async function loadAllReport() {
    allReportLoading.value = true;
    allReportError.value = '';
    allReport.value = [];

    try {
        const response = await api.get('/admin/overtime/reports', { baseURL: '' });
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
        const response = await api.post(
            '/get_overtime_report',
            {
                username: reportForm.username,
                start_date: toLatinDigits(reportForm.startDate),
                end_date: toLatinDigits(reportForm.endDate),
            },
            { baseURL: '' },
        );

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
        const response = await api.post(
            '/update_overtime_Indivisual_status',
            {
                id: row.id,
                status: row.decision,
            },
            { baseURL: '' },
        );

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
    <header
        class="section-hero"
        style="--hero-accent:#a855f7;--hero-accent-2:#c084fc;--hero-glow-1:rgba(168,85,247,.14);--hero-glow-2:rgba(192,132,252,.12);--hero-shadow:rgba(168,85,247,.55);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(168,85,247,.08);"
    >
        <div class="section-hero__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="13" r="8" fill="#fff" opacity=".14"/><circle cx="12" cy="13" r="8" stroke="#fff" stroke-width="1.9"/><path d="M12 8.5V13l3 1.9" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18.8" cy="5.5" r="3.8" fill="#fff"/><path d="M18.8 3.7v3.6M17 5.5h3.6" stroke="#A855F7" stroke-width="1.7" stroke-linecap="round"/></svg></div>
        <div class="section-hero__text"><h2>مدیریت اضافه‌کاری‌ها</h2><p>درخواست‌ها و گزارشات اضافه‌کاری پرسنل</p></div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="overtime-frame">
        <div class="overtime-tabs" role="tablist">
            <button
                type="button"
                class="overtime-tab-btn"
                :class="{ active: activeTab === 'requests' }"
                role="tab"
                :aria-selected="activeTab === 'requests'"
                @click="activeTab = 'requests'"
            >
                درخواست‌های اضافه‌کاری
            </button>
            <button
                type="button"
                class="overtime-tab-btn"
                :class="{ active: activeTab === 'all' }"
                role="tab"
                :aria-selected="activeTab === 'all'"
                @click="activeTab = 'all'; loadAllReport()"
            >
                گزارش کلی
            </button>
            <button
                type="button"
                class="overtime-tab-btn"
                :class="{ active: activeTab === 'report' }"
                role="tab"
                :aria-selected="activeTab === 'report'"
                @click="activeTab = 'report'"
            >
                گزارش انفرادی
            </button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div id="overtime-requests" v-show="activeTab === 'requests'" class="overtime-tab-content" :class="{ active: activeTab === 'requests' }" role="tabpanel">
            <table id="overTimeRequestTable" class="overTime-table">
                    <thead>
                        <tr>
                            <th class="krbr-ezafe-drkhst">نام کاربر</th>
                            <th class="trkh-ezafe-drkhst">تاریخ درخواست</th>
                            <th class="mdt-ezafe-drkhst">مدت زمان اضافه کاری</th>
                            <th class="tzht-ezafe-drkhst">توضیحات</th>
                            <th class="vaziat-ezafe-drkhst">وضعیت درخواست</th>
                            <th class="sbt-ezafe-drkhst">ثبت تغییرات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading"><td colspan="6">در حال دریافت درخواست‌ها…</td></tr>
                        <tr v-else v-for="row in requests" :key="row.id" :id="`request_${row.id}`">
                            <td class="krbr-ezafe-drkhst">{{ toPersianDigits(row.username) }}</td>
                            <td class="trkh-ezafe-drkhst">{{ toPersianDigits(row.overtime_date) }}</td>
                            <td class="mdt-ezafe-drkhst">{{ toPersianDigits(row.daily_overtime) }}</td>
                            <td class="tzht-ezafe-drkhst">{{ toPersianDigits(row.description || '') }}</td>
                            <td class="vaziat-ezafe-drkhst">
                                <div class="status-container">
                                    <button
                                        type="button"
                                        class="status-navbar pending-status"
                                        :aria-expanded="openRowId === row.id"
                                        @click="toggleDropdown(row.id)"
                                    >
                                        {{ toPersianDigits(row.decision) }}
                                    </button>
                                    <div class="status-dropdown" :style="{ display: openRowId === row.id ? 'flex' : 'none' }" role="menu">
                                        <button
                                            v-for="status in DECISIONS"
                                            :key="status"
                                            type="button"
                                            class="status-option"
                                            :class="leaveStatusClass(status)"
                                            role="menuitem"
                                            @click="chooseDecision(row, status)"
                                        >
                                            {{ status }}
                                        </button>
                                    </div>
                                </div>
                            </td>
                            <td class="sbt-ezafe-drkhst"><button type="button" class="update-button" :disabled="busyRowId === row.id" @click="submitDecision(row)">ثبت تغییرات</button></td>
                        </tr>
                    </tbody>
                </table>
        </div>

        <div id="overtime-all-report" v-show="activeTab === 'all'" class="overtime-tab-content" :class="{ active: activeTab === 'all' }" role="tabpanel">
            <p v-if="allReportError" class="h-alert" role="alert">{{ allReportError }}</p>
            <table class="overTime-allreport-table">
                    <thead>
                        <tr>
                            <th class="overtimeezafetime">کل مدت زمان اضافه کاری</th>
                            <th class="overtimekarbar">نام کاربر</th>
                            <th class="overtimeRadif">ردیف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="allReportLoading"><td colspan="3">در حال دریافت گزارش کلی…</td></tr>
                        <tr v-for="row in allReport" :key="row.username">
                            <td class="overtimeezafetime">{{ toPersianDigits(row.total_ezafe_time) }}</td>
                            <td class="overtimekarbar">{{ toPersianDigits(row.username) }}</td>
                            <td class="overtimeRadif">{{ toPersianDigits(row.row_number) }}</td>
                        </tr>
                        <tr v-if="allReport.length === 0">
                            <td colspan="3">{{ allReportLoading ? '' : 'داده‌ای برای نمایش نیست.' }}</td>
                        </tr>
                    </tbody>
                </table>
        </div>

        <div id="overtime-individual-report" v-show="activeTab === 'report'" class="overtime-tab-content" :class="{ active: activeTab === 'report' }" role="tabpanel">
            <div class="overtime-config">
                <div class="overtime-config-title">پارامترهای گزارش</div>
                <div class="overtime-config-grid">
                    <div class="overtime-config-item">
                        <span>انتخاب کاربر</span>
                        <select v-model="reportForm.username" id="usernameEzafeReport">
                            <option value="" disabled>انتخاب کنید</option>
                            <option value="all_users">همه کاربران</option>
                            <option v-for="user in users" :key="user.value" :value="user.value">
                                {{ user.label || user.value }}
                            </option>
                        </select>
                    </div>
                    <div class="overtime-config-item"><span>از تاریخ</span><input v-model="reportForm.startDate" type="text" id="start_date" name="start_date" placeholder="1404/01/01" autocomplete="off"></div>
                    <div class="overtime-config-item"><span>تا تاریخ</span><input v-model="reportForm.endDate" type="text" id="end_date" name="end_date" placeholder="1404/01/01" autocomplete="off"></div>
                    <div class="overtime-config-item overtime-config-actions"><button type="button" id="submitReport" :disabled="reportLoading" @click="generateReport">{{ reportLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}</button></div>
                </div>
            </div>

            <p v-if="reportError" class="h-alert" role="alert">{{ reportError }}</p>

            <div v-if="reportGenerated" id="overtimeReportResult" class="overtime-report-result">
                <div class="overtime-report-toolbar"><button type="button" id="downloadOvertimeReport" @click="downloadReport">دریافت گزارش</button></div>
                <table id="overTimeIndivisualReportTable" class="overTimeindivisualReport-table">
                        <thead>
                            <tr>
                                <th class="sbt-ezafe-gzrsh">ثبت تغییرات</th>
                                <th class="vaziat-ezafe-gzrsh">وضعیت درخواست</th>
                                <th class="tzht-ezafe-gzrsh">توضیحات</th>
                                <th class="mdt-ezafe-gzrsh">مدت زمان اضافه کاری</th>
                                <th class="trkh-ezafe-gzrsh">تاریخ درخواست</th>
                                <th class="krbr-ezafe-gzrsh">نام کاربر</th>
                                <th class="rdf-ezafe-gzrsh">ردیف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in reportRows" :key="row.id">
                                <td class="sbt-ezafe-gzrsh"><button type="button" class="confirm-changes-btn" :disabled="reportBusyId === row.id" @click="submitReportDecision(row)">تایید تغییرات</button></td>
                                <td class="vaziat-ezafe-gzrsh"><div class="status-container"><div class="status-navbar" :class="getStatusClass(row.decision)" @click="row.decisionOpen = !row.decisionOpen">{{ toPersianDigits(row.decision) }}</div><div class="status-dropdown" :style="{ display: row.decisionOpen ? 'flex' : 'none' }"><button v-for="status in DECISIONS" :key="status" type="button" class="status-option" :class="getStatusClass(status)" @click="chooseReportDecision(row, status); row.decisionOpen = false">{{ status }}</button></div></div></td>
                                <td class="tzht-ezafe-gzrsh">{{ toPersianDigits(row.description || '') }}</td>
                                <td class="mdt-ezafe-gzrsh">{{ toPersianDigits(row.daily_overtime) }}</td>
                                <td class="trkh-ezafe-gzrsh">{{ toPersianDigits(row.overtime_date) }}</td>
                                <td class="krbr-ezafe-gzrsh">{{ toPersianDigits(row.username) }}</td>
                                <td class="rdf-ezafe-gzrsh">{{ toPersianDigits(index + 1) }}</td>
                            </tr>
                            <tr v-if="reportRows.length === 0"><td colspan="7">داده‌ای برای این بازه یافت نشد.</td></tr>
                        </tbody>
                    </table>
            </div>
        </div>
    </div>
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
