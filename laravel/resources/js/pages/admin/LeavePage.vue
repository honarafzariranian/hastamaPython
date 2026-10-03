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
const requests = ref([]);
const openRowId = ref(null);
const busyRowId = ref(null);

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
        const response = await api.get('/get_leave_requests');
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
        const response = await api.post('/update_leave_status', {
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

onMounted(loadRequests);
</script>

<template>
    <section class="leave">
        <header class="leave__head">
            <div>
                <h1 class="leave__title">مدیریت مرخصی پرسنل</h1>
                <p class="leave__sub">درخواست‌های مرخصی در انتظار را بررسی و تأیید یا رد کنید</p>
            </div>
            <button type="button" class="h-btn h-btn-ghost" :disabled="loading" @click="loadRequests">
                {{ loading ? 'در حال دریافت…' : 'بروزرسانی' }}
            </button>
        </header>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div v-if="loading" class="leave__loading">در حال دریافت درخواست‌ها…</div>

        <div v-else class="leave__table-scroll">
            <table class="leave__table">
                <thead>
                    <tr>
                        <th>نام کاربر</th>
                        <th>از تاریخ</th>
                        <th>تا تاریخ</th>
                        <th>تعداد روزها</th>
                        <th>جانشین</th>
                        <th>وضعیت درخواست</th>
                        <th>ثبت تغییرات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in requests" :key="row.id">
                        <td>{{ row.username }}</td>
                        <td>{{ formatDate(row.start_date) }}</td>
                        <td>{{ formatDate(row.end_date) }}</td>
                        <td>{{ toPersianDigits(row.days) }}</td>
                        <td>{{ row.substitute || 'ندارد' }}</td>
                        <td>
                            <div class="leave__status">
                                <button
                                    type="button"
                                    class="leave__status-btn"
                                    :aria-expanded="openRowId === row.id"
                                    @click="toggleDropdown(row.id)"
                                >
                                    {{ row.decision }}
                                </button>
                                <div v-if="openRowId === row.id" class="leave__status-menu" role="menu">
                                    <button
                                        v-for="status in DECISIONS"
                                        :key="status"
                                        type="button"
                                        class="leave__status-option"
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
                                class="h-btn h-btn-primary leave__submit"
                                :disabled="busyRowId === row.id"
                                @click="submitDecision(row)"
                            >
                                تایید تغییرات
                            </button>
                        </td>
                    </tr>
                    <tr v-if="requests.length === 0">
                        <td colspan="7" class="leave__empty">درخواست مرخصی در انتظاری وجود ندارد.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
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
