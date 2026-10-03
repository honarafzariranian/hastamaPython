<script setup>
/**
 * Hourly pass management — verbatim port of `hourlyPassBox` from admin.html.
 *
 * Three tabs:
 *   1. hp-requests     — pending requests, approval via POST /change_hourly_pass_status
 *   2. hp-all-report   — total per-user (server context in Python, fetched here)
 *   3. hp-individual   — date-range individual report, POST /get_hourly_pass_report,
 *                        status updates via POST /update_hourly_pass_status,
 *                        download opens /hourlypass_Report_page with localStorage data.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const DECISIONS = ['تایید شده', 'رد شده', 'انصراف'];

const activeTab = ref('hp-requests');
const notice = ref('');
const error = ref('');

/* ── Tab 1: pending requests ── */
const requestsLoading = ref(true);
const requests = ref([]);
const requestsOpenMenu = ref(null);
const requestsBusy = ref(null);

/* ── Tab 2: totals (rendered server-side in Jinja, fetched here) ── */
const totalsLoading = ref(false);
const passReports = ref([]);

/* ── Tab 3: individual report ── */
const users = ref([]);
const reportForm = reactive({
    username: '',
    startDate: '',
    endDate: '',
});
const reportLoading = ref(false);
const reportRows = ref([]);
const reportGenerated = ref(false);
const reportBusy = ref(null);
const reportOpenMenu = ref(null);

function formatDuration(value) {
    if (!value || value === 'None') return '—';
    const parts = String(value).split(':');
    return toPersianDigits(`${parts[0] ?? '00'}:${parts[1] ?? '00'}`);
}

function switchTab(tabId) {
    activeTab.value = tabId;
    notice.value = '';
    error.value = '';
}

async function loadRequests() {
    requestsLoading.value = true;
    error.value = '';
    try {
        const response = await api.get('/get_hourly_pass_requests', { baseURL: '' });
        requests.value = (Array.isArray(response) ? response : [])
            .map((row) => ({ ...row, decision: row.status }));
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت درخواست‌های پاس ساعتی.';
        requests.value = [];
    } finally {
        requestsLoading.value = false;
    }
}

async function submitRequestDecision(row) {
    if (row.decision === 'انتظار تایید') {
        error.value = 'درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.';
        return;
    }
    requestsBusy.value = row.id;
    error.value = '';
    notice.value = '';
    try {
        const response = await api.post('/change_hourly_pass_status', {
            id: row.id,
            status: row.decision,
        }, { baseURL: '' });
        if (response.success === false) {
            error.value = response.message || 'خطا در به‌روزرسانی وضعیت!';
            return;
        }
        notice.value = 'وضعیت با موفقیت تغییر کرد!';
        requests.value = requests.value.filter((item) => item.id !== row.id);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در به‌روزرسانی وضعیت!';
    } finally {
        requestsBusy.value = null;
        requestsOpenMenu.value = null;
    }
}

async function loadTotals() {
    totalsLoading.value = true;
    try {
        // GET /admin/overtime/reports already returns all-report style aggregates;
        // for hourly passes the totals come from a POST that supplies a date range,
        // but the legacy template renders pass_reports from page context.  To match
        // the appearance we fetch the individual list without filters and aggregate.
        const response = await api.post('/get_hourly_pass_report', {
            username: 'all_users',
            start_date: '',
            end_date: '',
        }, { baseURL: '' }).catch(() => []);
        const rows = Array.isArray(response) ? response.filter((r) => r.status === 'تایید شده') : [];
        const map = new Map();
        rows.forEach((r) => {
            if (!r.pass_duration) return;
            const [h, m] = String(r.pass_duration).split(':').map((n) => parseInt(n, 10) || 0);
            const total = (map.get(r.username) || 0) + (h * 60 + m);
            map.set(r.username, total);
        });
        const arr = Array.from(map.entries()).map(([username, mins]) => ({
            username,
            totalMinutes: mins,
            total_pass_time: `${toPersianDigits(String(Math.floor(mins / 60)).padStart(2, '0'))}:${toPersianDigits(String(mins % 60).padStart(2, '0'))}`,
        }));
        arr.sort((a, b) => b.totalMinutes - a.totalMinutes);
        arr.forEach((row, idx) => { row.row_number = toPersianDigits(String(idx + 1)); });
        passReports.value = arr;
    } catch {
        passReports.value = [];
    } finally {
        totalsLoading.value = false;
    }
}

async function loadUsers() {
    try {
        const response = await api.get('/get_users', { baseURL: '' });
        users.value = (response.users ?? []).filter((u) => !u.is_active || u.is_active === 'active');
    } catch {
        users.value = [];
    }
}

async function generateReport() {
    if (!reportForm.username || !reportForm.startDate || !reportForm.endDate) {
        error.value = 'لطفاً تمام فیلدها را پر کنید.';
        return;
    }
    reportLoading.value = true;
    error.value = '';
    reportGenerated.value = false;
    try {
        const response = await api.post('/get_hourly_pass_report', {
            username: reportForm.username,
            start_date: toLatinDigits(reportForm.startDate),
            end_date: toLatinDigits(reportForm.endDate),
        }, { baseURL: '' });
        reportRows.value = (Array.isArray(response) ? response : []).map((row) => ({ ...row, decision: row.status }));
        reportGenerated.value = true;
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت داده‌ها.';
        reportRows.value = [];
    } finally {
        reportLoading.value = false;
    }
}

async function submitReportDecision(row) {
    reportBusy.value = row.id;
    error.value = '';
    try {
        const response = await api.post('/update_hourly_pass_status', {
            id: row.id,
            status: row.decision,
        }, { baseURL: '' });
        if (response.success === false) {
            error.value = response.message || 'خطا در تغییر وضعیت.';
            return;
        }
        notice.value = 'وضعیت با موفقیت تغییر کرد.';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ارسال درخواست.';
    } finally {
        reportBusy.value = null;
        reportOpenMenu.value = null;
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

function selectRequestDecision(row, status) {
    row.decision = status;
    requestsOpenMenu.value = null;
}
function selectReportDecision(row, status) {
    row.decision = status;
    reportOpenMenu.value = null;
}

onMounted(() => {
    loadRequests();
    loadUsers();
    loadTotals();
});
</script>

<template>
    <header class="section-hero" style="--hero-accent:#0ea5e9;--hero-accent-2:#38bdf8;--hero-glow-1:rgba(14,165,233,0.14);--hero-glow-2:rgba(56,189,248,0.12);--hero-shadow:rgba(14,165,233,0.55);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(14,165,233,0.08);">
        <div class="section-hero__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="13" r="8" fill="#fff" opacity=".14"/><circle cx="11" cy="13" r="8" stroke="#fff" stroke-width="1.9"/><path d="M11 8.5V13l3 1.9" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="19.2" cy="6" r="3.5" fill="#fff"/><path d="M17.8 6H20.6M19.2 4.6V7.4" stroke="#0EA5E9" stroke-width="1.5" stroke-linecap="round"/></svg>
        </div>
        <div class="section-hero__text">
            <h2>مدیریت پاس‌های ساعتی</h2>
            <p>مدیریت پاس‌های ساعتی و گزارشات مرتبط</p>
        </div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="hourlyPass-frame">
        <div class="hourlyPass-tabs" role="tablist">
            <button
                class="hourlyPass-tab-btn"
                :class="{ active: activeTab === 'hp-requests' }"
                role="tab"
                :aria-selected="activeTab === 'hp-requests'"
                @click="switchTab('hp-requests')"
            >درخواست‌های پاس ساعتی</button>
            <button
                class="hourlyPass-tab-btn"
                :class="{ active: activeTab === 'hp-all-report' }"
                role="tab"
                :aria-selected="activeTab === 'hp-all-report'"
                @click="switchTab('hp-all-report')"
            >گزارش کلی</button>
            <button
                class="hourlyPass-tab-btn"
                :class="{ active: activeTab === 'hp-individual' }"
                role="tab"
                :aria-selected="activeTab === 'hp-individual'"
                @click="switchTab('hp-individual')"
            >گزارش انفرادی</button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <!-- Tab 1: pending requests -->
        <div
            id="hp-requests"
            class="hourlyPass-tab-content"
            :class="{ active: activeTab === 'hp-requests' }"
            role="tabpanel"
        >
            <table class="hourlyPassReport-table" id="hourlyPassReportTable">
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
                    <tr v-if="requestsLoading">
                        <td colspan="6" style="text-align:center;padding:2rem;color:#64748b;">در حال دریافت درخواست‌ها…</td>
                    </tr>
                    <template v-else>
                        <tr v-for="row in requests" :key="row.id">
                            <td>{{ row.username }}</td>
                            <td>{{ toPersianDigits(row.request_date) }}</td>
                            <td>{{ row.pass_title || '—' }}</td>
                            <td>{{ formatDuration(row.pass_duration) }}</td>
                            <td>
                                <div class="status-dropdown" style="position:relative;display:inline-block;">
                                    <button
                                        type="button"
                                        class="status-select-btn"
                                        @click.stop="requestsOpenMenu = requestsOpenMenu === row.id ? null : row.id"
                                    >{{ row.decision }}</button>
                                    <div v-if="requestsOpenMenu === row.id" class="status-select-menu" @click.stop>
                                        <button
                                            v-for="status in DECISIONS"
                                            :key="status"
                                            type="button"
                                            class="status-select-option"
                                            @click="selectRequestDecision(row, status)"
                                        >{{ status }}</button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="table-action-btn table-action-btn--primary"
                                    :disabled="requestsBusy === row.id"
                                    @click="submitRequestDecision(row)"
                                >{{ requestsBusy === row.id ? 'در حال ثبت…' : 'ثبت تغییرات' }}</button>
                            </td>
                        </tr>
                        <tr v-if="requests.length === 0">
                            <td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8;">درخواست پاس ساعتی در انتظاری وجود ندارد.</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Tab 2: totals report -->
        <div
            id="hp-all-report"
            class="hourlyPass-tab-content"
            :class="{ active: activeTab === 'hp-all-report' }"
            role="tabpanel"
        >
            <h2>گزارش کلی پاس‌های ساعتی</h2>
            <table class="hourlyPassTotaluserReport-table" id="hourlyPassTotaluserReportTable">
                <thead>
                    <tr>
                        <th class="hourlyPassezafetime">کل مدت زمان پاس</th>
                        <th class="hourlyPasskarbar">نام کاربر</th>
                        <th class="hourlyPassRadif">ردیف</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="totalsLoading">
                        <td colspan="3" style="text-align:center;padding:2rem;color:#64748b;">در حال بارگذاری…</td>
                    </tr>
                    <template v-else>
                        <tr v-for="row in passReports" :key="row.username">
                            <td>{{ row.total_pass_time }}</td>
                            <td>{{ row.username }}</td>
                            <td>{{ row.row_number }}</td>
                        </tr>
                        <tr v-if="passReports.length === 0">
                            <td colspan="3" style="text-align:center;padding:2rem;color:#94a3b8;">داده‌ای برای نمایش وجود ندارد.</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Tab 3: individual report -->
        <div
            id="hp-individual"
            class="hourlyPass-tab-content"
            :class="{ active: activeTab === 'hp-individual' }"
            role="tabpanel"
        >
            <div class="hourlyPass-config">
                <div class="hourlyPass-config-title">تنظیمات گزارش</div>
                <div class="hourlyPass-config-grid">
                    <div class="hourlyPass-config-item">
                        <span>انتخاب کاربر</span>
                        <select v-model="reportForm.username" id="usernameHourlypass" name="usernameHourlypass" required>
                            <option value="" disabled selected>انتخاب کنید</option>
                            <option value="all_users">همه کاربران</option>
                            <option v-for="user in users" :key="user.username" :value="user.username">{{ user.username }}</option>
                        </select>
                    </div>
                    <div class="hourlyPass-config-item">
                        <span>از تاریخ</span>
                        <input v-model="reportForm.startDate" type="text" id="start_date_hourlypass" name="start_date" placeholder="۱۴۰۵/۰۱/۰۱" required>
                    </div>
                    <div class="hourlyPass-config-item">
                        <span>تا تاریخ</span>
                        <input v-model="reportForm.endDate" type="text" id="end_date_hourlypass" name="end_date" placeholder="۱۴۰۵/۰۱/۳۱" required>
                    </div>
                    <div class="hourlyPass-config-actions">
                        <button type="button" id="submitHourlyPassReport" :disabled="reportLoading" @click="generateReport">
                            {{ reportLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="reportGenerated" id="hourlyPassReportResult">
                <div class="hourlyPass-report-toolbar">
                    <button id="downloadHourlyPassReport" type="button" @click="downloadReport">دریافت گزارش</button>
                </div>
                <table class="hourlyPassIndivisualuserReport-table" id="hourlyPassIndivisualuserReportTable">
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
                                    class="table-action-btn table-action-btn--primary"
                                    :disabled="reportBusy === row.id"
                                    @click="submitReportDecision(row)"
                                >{{ reportBusy === row.id ? 'در حال ثبت…' : 'ثبت تغییرات' }}</button>
                            </td>
                            <td>
                                <div class="status-dropdown" style="position:relative;display:inline-block;">
                                    <button
                                        type="button"
                                        class="status-select-btn"
                                        @click.stop="reportOpenMenu = reportOpenMenu === row.id ? null : row.id"
                                    >{{ row.decision }}</button>
                                    <div v-if="reportOpenMenu === row.id" class="status-select-menu" @click.stop>
                                        <button
                                            v-for="status in DECISIONS"
                                            :key="status"
                                            type="button"
                                            class="status-select-option"
                                            @click="selectReportDecision(row, status)"
                                        >{{ status }}</button>
                                    </div>
                                </div>
                            </td>
                            <td>{{ formatDuration(row.pass_duration) }}</td>
                            <td>{{ row.pass_title || '—' }}</td>
                            <td>{{ toPersianDigits(row.request_date) }}</td>
                            <td>{{ row.username }}</td>
                        </tr>
                        <tr v-if="reportRows.length === 0">
                            <td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8;">داده‌ای برای این بازه یافت نشد.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* The legacy admin.css rules already style `.hourlyPass-frame`, `.hourlyPass-tabs`,
   `.hourlyPass-tab-btn`, `.hourlyPassReport-table`, etc.  We only add a few
   complementary styles for the Vue-driven status dropdown/buttons so the
   controls visually match the legacy table language. */

/* The legacy sheet hard-codes `#downloadHourlyPassReport { display: none; }`
   and relied on JS to unhide it; Vue controls visibility through v-if on the
   parent container, so make sure the button isn't hidden when its container
   is visible. */
:deep(#downloadHourlyPassReport) { display: inline-block !important; }

.status-select-btn {
    min-width: 7rem;
    padding: 0.35rem 0.7rem;
    border: 1px solid rgb(245 158 11 / 0.5);
    border-radius: 8px;
    background: rgb(245 158 11 / 0.12);
    color: #b45309;
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
}
.status-select-menu {
    position: absolute;
    top: calc(100% + 4px);
    inset-inline-start: 0;
    z-index: 30;
    min-width: 9rem;
    padding: 0.25rem;
    border: 1px solid rgb(15 23 42 / 0.1);
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 30px rgb(15 23 42 / 0.15);
    display: flex;
    flex-direction: column;
}
.status-select-option {
    padding: 0.45rem 0.7rem;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 0.82rem;
    text-align: start;
    cursor: pointer;
}
.status-select-option:hover {
    background: rgb(14 165 233 / 0.1);
}

.table-action-btn {
    padding: 0.4rem 0.8rem;
    border: 0;
    border-radius: 8px;
    font: inherit;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
}
.table-action-btn--primary {
    background: #0ea5e9;
    color: #fff;
}
.table-action-btn--primary:hover {
    background: #0284c7;
}
.table-action-btn--primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>
