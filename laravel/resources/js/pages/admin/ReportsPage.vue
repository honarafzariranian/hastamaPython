<script setup>
/**
 * Reports hub — the report pages and the individual leave report.
 *
 * The individual report reproduces the legacy `generategozareshmrkReportBtn`
 * flow: POST /generate_individual_report returns the decided leave rows for a
 * user and Jalali range, each row can be re-decided with
 * POST /update_leave_status, and «دریافت گزارش» stores the approved rows in
 * localStorage and opens the server-rendered /leave_report_page.
 *
 * The hub cards open the remaining server-rendered report pages
 * (/hourlypass_Report_page, /overtime_report_page, /payroll_report_page,
 * /final_report_page) in a new tab, and the PDF button calls
 * GET /download_pdf — which answers 503 in this installation because the
 * final-report PDF template is missing (a legacy behaviour the port
 * reproduces), so the Persian server message is surfaced.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const DECISIONS = ['تایید شده', 'رد شده', 'انصراف'];

const users = ref([]);

const reportForm = reactive({
    user: '',
    fromDate: '',
    toDate: '',
});

const reportRows = ref([]);
const reportLoading = ref(false);
const reportError = ref('');
const reportBusyId = ref(null);
const reportGenerated = ref(false);

const reportUser = ref(null);

const pdfLoading = ref(false);
const pdfError = ref('');

const reportPages = [
    {
        title: 'گزارش مرخصی فردی',
        description: 'گزارش مرخصی تأییدشده یک کاربر در بازه تاریخی',
        url: '/leave_report_page',
    },
    {
        title: 'گزارش پاس ساعتی فردی',
        description: 'گزارش پاس‌های ساعتی تأییدشده یک کاربر',
        url: '/hourlypass_Report_page',
    },
    {
        title: 'گزارش اضافه‌کاری فردی',
        description: 'گزارش اضافه‌کاری تأییدشده یک کاربر',
        url: '/overtime_report_page',
    },
    {
        title: 'گزارش حقوق و دستمزد',
        description: 'پیش‌نمایش چاپی محاسبات حقوق پرسنل',
        url: '/payroll_report_page',
    },
    {
        title: 'گزارش نهایی حضور و غیاب',
        description: 'گزارش جامع حضور، اضافه‌کاری و مرخصی پرسنل',
        url: '/final_report_page',
    },
];

async function loadUsers() {
    try {
        const response = await api.get('/get_users');
        users.value = response.users ?? [];
    } catch {
        users.value = [];
    }
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return toPersianDigits(String(value).replace(/-/g, '/'));
}

async function loadReportUser() {
    if (!reportForm.user || reportForm.user === 'all') {
        reportUser.value = null;
        return;
    }

    try {
        const response = await api.get('/fetch_user_data', {
            params: { username: reportForm.user },
        });

        reportUser.value = response;
    } catch {
        reportUser.value = null;
    }
}

async function generateReport() {
    if (!reportForm.fromDate || !reportForm.toDate) {
        reportError.value = 'لطفاً همه فیلدها را پر کنید';
        return;
    }

    reportLoading.value = true;
    reportError.value = '';
    reportGenerated.value = false;

    try {
        const response = await api.post('/generate_individual_report', {
            user: reportForm.user,
            fromDate: toLatinDigits(reportForm.fromDate),
            toDate: toLatinDigits(reportForm.toDate),
        });

        if (!response.success) {
            reportError.value = response.message || 'خطا در دریافت گزارش';
            reportRows.value = [];
            return;
        }

        reportRows.value = (response.reports ?? []).map((row) => ({ ...row, decision: row.status || 'انتظار تایید' }));
        reportGenerated.value = true;
    } catch (failure) {
        reportError.value = failure.apiFailure?.message || failure.message || 'خطا در برقراری ارتباط';
        reportRows.value = [];
    } finally {
        reportLoading.value = false;
    }
}

function chooseDecision(row, status) {
    row.decision = status;
}

async function submitDecision(row) {
    if (row.decision === 'انتظار تایید') {
        reportError.value = 'درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.';
        return;
    }

    reportBusyId.value = row.id;
    reportError.value = '';

    try {
        const response = await api.post('/update_leave_status', {
            requestId: row.id,
            status: row.decision,
        });

        if (response.success === false) {
            reportError.value = response.message || 'خطا در به‌روزرسانی وضعیت!';
            return;
        }

        reportRows.value = reportRows.value.filter((item) => item.id !== row.id);
    } catch (failure) {
        reportError.value = failure.apiFailure?.message || failure.message || 'خطا در به‌روزرسانی وضعیت!';
    } finally {
        reportBusyId.value = null;
    }
}

function downloadReport() {
    const approved = reportRows.value
        .filter((row) => row.decision === 'تایید شده')
        .map((row) => ({
            substitute: row.substitute,
            days: row.days,
            end_date: row.end_date,
            start_date: row.start_date,
            row_number: row.id,
        }));

    localStorage.setItem('reports', JSON.stringify(approved));
    localStorage.setItem('username', reportForm.user);
    window.open('/leave_report_page', '_blank');
}

function openReportPage(url) {
    window.open(url, '_blank');
}

async function downloadPdf() {
    pdfLoading.value = true;
    pdfError.value = '';

    try {
        await api.get('/download_pdf');
    } catch (failure) {
        pdfError.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت گزارش PDF.';
    } finally {
        pdfLoading.value = false;
    }
}

onMounted(loadUsers);
</script>

<template>
    <section class="reports">
        <header class="reports__head">
            <div>
                <h1 class="reports__title">گزارش‌ها</h1>
                <p class="reports__sub">تهیه و دریافت گزارش‌های مرخصی، پاس ساعتی، اضافه‌کاری و حقوق</p>
            </div>
        </header>

        <div class="reports__grid">
            <article v-for="page in reportPages" :key="page.url" class="h-card reports__card">
                <h2 class="reports__card-title">{{ page.title }}</h2>
                <p class="reports__card-desc">{{ page.description }}</p>
                <button type="button" class="h-btn h-btn-ghost" @click="openReportPage(page.url)">
                    باز کردن صفحه گزارش
                </button>
            </article>

            <article class="h-card reports__card reports__card--pdf">
                <h2 class="reports__card-title">گزارش نهایی PDF</h2>
                <p class="reports__card-desc">دریافت گزارش نهایی حضور و غیاب به صورت PDF</p>
                <button type="button" class="h-btn h-btn-primary" :disabled="pdfLoading" @click="downloadPdf">
                    {{ pdfLoading ? 'در حال دریافت…' : 'دریافت PDF' }}
                </button>
                <p v-if="pdfError" class="reports__pdf-error">{{ pdfError }}</p>
            </article>
        </div>

        <div class="reports__individual">
            <h2 class="reports__individual-title">گزارش انفرادی مرخصی</h2>

            <div class="reports__config">
                <div class="reports__config-grid">
                    <label class="h-field">
                        <span class="h-field__label">انتخاب کاربر</span>
                        <select v-model="reportForm.user" class="h-input" @change="loadReportUser">
                            <option value="" disabled>انتخاب کنید</option>
                            <option value="all">همه کاربران</option>
                            <option v-for="user in users" :key="user.value" :value="user.value">
                                {{ user.label || user.value }}
                            </option>
                        </select>
                    </label>
                    <label class="h-field">
                        <span class="h-field__label">از تاریخ</span>
                        <input v-model="reportForm.fromDate" type="text" class="h-input" placeholder="۱۴۰۵/۰۱/۰۱">
                    </label>
                    <label class="h-field">
                        <span class="h-field__label">تا تاریخ</span>
                        <input v-model="reportForm.toDate" type="text" class="h-input" placeholder="۱۴۰۵/۰۱/۰۱">
                    </label>
                    <div class="reports__config-actions">
                        <button type="button" class="h-btn h-btn-primary" :disabled="reportLoading" @click="generateReport">
                            {{ reportLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="reportUser" class="reports__user">
                <span>نام: <strong>{{ reportUser.name }} {{ reportUser.last_name }}</strong></span>
                <span>بخش: <strong>{{ reportUser.department || '—' }}</strong></span>
            </div>

            <p v-if="reportError" class="h-alert" role="alert">{{ reportError }}</p>

            <div v-if="reportGenerated" class="reports__result">
                <div class="reports__result-toolbar">
                    <button type="button" class="h-btn h-btn-primary" @click="downloadReport">
                        دریافت گزارش
                    </button>
                </div>

                <div class="reports__table-scroll">
                    <table class="reports__table">
                        <thead>
                            <tr>
                                <th>ثبت تغییرات</th>
                                <th>وضعیت درخواست</th>
                                <th>جانشین</th>
                                <th>تعداد روز</th>
                                <th>تا تاریخ</th>
                                <th>از تاریخ</th>
                                <th>نام کاربر</th>
                                <th>ردیف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in reportRows" :key="row.id">
                                <td>
                                    <button
                                        type="button"
                                        class="h-btn h-btn-primary reports__submit"
                                        :disabled="reportBusyId === row.id"
                                        @click="submitDecision(row)"
                                    >
                                        تایید تغییرات
                                    </button>
                                </td>
                                <td>
                                    <div class="reports__status">
                                        <button
                                            type="button"
                                            class="reports__status-btn"
                                            @click="row.decisionOpen = !row.decisionOpen"
                                        >
                                            {{ row.decision }}
                                        </button>
                                        <div v-if="row.decisionOpen" class="reports__status-menu" role="menu">
                                            <button
                                                v-for="status in DECISIONS"
                                                :key="status"
                                                type="button"
                                                class="reports__status-option"
                                                role="menuitem"
                                                @click="chooseDecision(row, status); row.decisionOpen = false"
                                            >
                                                {{ status }}
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ row.substitute || 'ندارد' }}</td>
                                <td>{{ toPersianDigits(row.days) }}</td>
                                <td>{{ formatDate(row.end_date) }}</td>
                                <td>{{ formatDate(row.start_date) }}</td>
                                <td>{{ row.username }}</td>
                                <td>{{ toPersianDigits(index + 1) }}</td>
                            </tr>
                            <tr v-if="reportRows.length === 0">
                                <td colspan="8" class="reports__empty">داده‌ای برای این بازه یافت نشد.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.reports {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
}

.reports__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.reports__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .reports__sub {
    color: var(--dk-text-2);
}

.reports__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 0.9rem;
}

.reports__card {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    padding: 1.1rem 1.2rem;
}

.reports__card-title {
    margin: 0;
    font-size: 0.98rem;
    font-weight: 800;
}

.reports__card-desc {
    margin: 0;
    flex: 1;
    color: #64748b;
    font-size: 0.78rem;
}

[data-theme='dark'] .reports__card-desc {
    color: var(--dk-text-2);
}

.reports__card--pdf {
    border-color: rgb(217 119 6 / 0.4);
}

.reports__pdf-error {
    margin: 0.4rem 0 0;
    color: #b91c1c;
    font-size: 0.78rem;
}

[data-theme='dark'] .reports__pdf-error {
    color: #fca5a5;
}

.reports__individual {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.reports__individual-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
}

.reports__config {
    padding: 1rem 1.1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .reports__config {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.reports__config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.8rem;
    align-items: end;
}

.reports__user {
    display: flex;
    gap: 1.4rem;
    flex-wrap: wrap;
    padding: 0.7rem 1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-sm);
    background: rgb(14 165 233 / 0.06);
    font-size: 0.82rem;
}

[data-theme='dark'] .reports__user {
    border-color: var(--dk-line);
    background: var(--dk-surface-2);
}

.reports__result {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}

.reports__result-toolbar {
    display: flex;
    justify-content: flex-start;
}

.reports__table-scroll {
    overflow-x: auto;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
}

[data-theme='dark'] .reports__table-scroll {
    border-color: var(--dk-border);
}

.reports__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    background: #fff;
}

[data-theme='dark'] .reports__table {
    background: var(--dk-surface);
}

.reports__table th,
.reports__table td {
    padding: 0.65rem 0.7rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .reports__table th,
[data-theme='dark'] .reports__table td {
    border-bottom-color: var(--dk-line);
}

.reports__table th {
    color: #64748b;
    font-size: 0.75rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .reports__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.reports__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .reports__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.reports__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .reports__empty {
    color: var(--dk-text-3);
}

.reports__status {
    position: relative;
}

.reports__status-btn {
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

[data-theme='dark'] .reports__status-btn {
    color: #fcd34d;
}

.reports__status-menu {
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

[data-theme='dark'] .reports__status-menu {
    border-color: var(--dk-border);
    background: var(--dk-surface-2);
}

.reports__status-option {
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

.reports__status-option:hover {
    background: rgb(14 165 233 / 0.1);
}

.reports__submit {
    padding: 0.4rem 0.9rem;
    font-size: 0.78rem;
}
</style>
