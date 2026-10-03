<script setup>
/**
 * Admin dashboard — counts and recent activity.
 *
 * The legacy dashboard was server-rendered by the Python admin route.  The
 * Vue port cannot call the master-admin dashboard endpoints (they are
 * guarded by the master-admin flag, which an ordinary admin does not hold),
 * so every figure here is derived from the admin-specific reads the panel
 * already owns:
 *
 *   GET /get_users               → total staff
 *   GET /get_leave_requests      → leave request counts
 *   GET /get_overtime_requests   → overtime counts and per-user totals
 *   GET /get_hourly_pass_requests→ pending hourly-pass count
 *   GET /get_active_shifts       → today's active shifts
 *
 * "Recent activity" merges the newest leave / overtime / pass requests into
 * one feed, newest first — the same rows the approval queues show.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const loading = ref(true);
const error = ref('');

const totalUsers = ref(0);
const pendingLeave = ref(0);
const pendingOvertime = ref(0);
const pendingPass = ref(0);
const activeShifts = ref(0);
const overtimeUserCount = ref(0);
const totalOvertimeTime = ref('۰');

const topOvertime = ref([]);
const activity = ref([]);

const PENDING = 'انتظار تایید';

function toMinutes(value) {
    const text = String(value ?? '').trim();
    if (!text) {
        return 0;
    }

    const parts = text.split(':').map((part) => Number(part) || 0);
    if (parts.length >= 2) {
        return parts[0] * 60 + parts[1];
    }

    return parts[0] || 0;
}

function formatMinutes(totalMinutes) {
    const minutes = Math.max(0, Math.round(totalMinutes));
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return toPersianDigits(`${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`);
}

function sortByDateDesc(rows) {
    return [...rows].sort((a, b) => {
        const dateA = String(a.date ?? '').replace(/\//g, '');
        const dateB = String(b.date ?? '').replace(/\//g, '');

        return dateB.localeCompare(dateA);
    });
}

async function loadDashboard() {
    loading.value = true;
    error.value = '';

    try {
        const [usersRes, leaveRes, overtimeRes, passRes, shiftsRes] = await Promise.all([
            api.get('/get_users'),
            api.get('/get_leave_requests'),
            api.get('/get_overtime_requests'),
            api.get('/get_hourly_pass_requests'),
            api.get('/get_active_shifts'),
        ]);

        const users = usersRes.users ?? [];
        const leaveRequests = Array.isArray(leaveRes) ? leaveRes : [];
        const overtimeRequests = Array.isArray(overtimeRes) ? overtimeRes : [];
        const passRequests = Array.isArray(passRes) ? passRes : [];
        const shifts = shiftsRes.shifts ?? [];

        totalUsers.value = users.length;
        pendingLeave.value = leaveRequests.filter((row) => row.status === PENDING).length;
        pendingOvertime.value = overtimeRequests.filter((row) => row.status === PENDING).length;
        pendingPass.value = passRequests.filter((row) => row.status === PENDING).length;
        activeShifts.value = shifts.length;

        /* Per-user overtime totals, summed from the request rows (the
         * org-wide ezafe_total_table read is the master-admin /overtime_report
         * endpoint, which an ordinary admin cannot call). */
        const totalsByUser = new Map();

        for (const row of overtimeRequests) {
            const minutes = toMinutes(row.daily_overtime);
            totalsByUser.set(row.username, (totalsByUser.get(row.username) ?? 0) + minutes);
        }

        const totals = [...totalsByUser.entries()]
            .map(([username, total]) => ({ username, total }))
            .filter((row) => row.total > 0)
            .sort((a, b) => b.total - a.total);

        overtimeUserCount.value = totals.length;
        totalOvertimeTime.value = formatMinutes(totals.reduce((sum, row) => sum + row.total, 0));
        topOvertime.value = totals.slice(0, 5).map((row) => ({
            username: row.username,
            total: formatMinutes(row.total),
        }));

        const feed = [
            ...leaveRequests
                .filter((row) => row.status === PENDING)
                .map((row) => ({ type: 'مرخصی', username: row.username, date: row.start_date, status: row.status })),
            ...overtimeRequests
                .filter((row) => row.status === PENDING)
                .map((row) => ({ type: 'اضافه‌کاری', username: row.username, date: row.overtime_date, status: row.status })),
            ...passRequests
                .filter((row) => row.status === PENDING)
                .map((row) => ({ type: 'پاس ساعتی', username: row.username, date: row.request_date, status: row.status })),
        ];

        activity.value = sortByDateDesc(feed).slice(0, 8);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت اطلاعات داشبورد.';
        totalUsers.value = 0;
        pendingLeave.value = 0;
        pendingOvertime.value = 0;
        pendingPass.value = 0;
        activeShifts.value = 0;
        overtimeUserCount.value = 0;
        totalOvertimeTime.value = '۰';
        topOvertime.value = [];
        activity.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(loadDashboard);

const cards = computed(() => [
    { label: 'کل پرسنل', value: toPersianDigits(totalUsers.value), hint: 'کاربران سامانه' },
    { label: 'مرخصی در انتظار', value: toPersianDigits(pendingLeave.value), hint: 'درخواست تأیید نشده' },
    { label: 'اضافه‌کاری در انتظار', value: toPersianDigits(pendingOvertime.value), hint: 'درخواست تأیید نشده' },
    { label: 'پاس ساعتی در انتظار', value: toPersianDigits(pendingPass.value), hint: 'درخواست تأیید نشده' },
    { label: 'شیفت فعال امروز', value: toPersianDigits(activeShifts.value), hint: 'پرسنل در شیفت' },
    { label: 'کاربران با اضافه‌کاری', value: toPersianDigits(overtimeUserCount.value), hint: 'دارای ثبت اضافه‌کاری' },
]);
</script>

<template>
    <section class="dash">
        <header class="dash__head">
            <div>
                <span class="dash__badge">نمای کلی سازمان</span>
                <h1 class="dash__title">داشبورد مدیریت</h1>
                <p class="dash__sub">تصویر زندهٔ کارکرد، اضافه‌کاری و درخواست‌های در انتظار</p>
            </div>
            <button type="button" class="h-btn h-btn-ghost dash__refresh" :disabled="loading" @click="loadDashboard">
                {{ loading ? 'در حال دریافت…' : 'بروزرسانی' }}
            </button>
        </header>

        <p v-if="error" class="h-alert dash__alert" role="alert">{{ error }}</p>

        <div v-if="loading" class="dash__loading">در حال دریافت اطلاعات…</div>

        <template v-else>
            <div class="dash__cards">
                <article v-for="card in cards" :key="card.label" class="h-card dash-card">
                    <span class="dash-card__label">{{ card.label }}</span>
                    <span class="dash-card__value">{{ card.value }}</span>
                    <span class="dash-card__hint">{{ card.hint }}</span>
                </article>

                <article class="h-card dash-card dash-card--wide">
                    <span class="dash-card__label">کل اضافه‌کاری ثبت‌شده</span>
                    <span class="dash-card__value">{{ totalOvertimeTime }}</span>
                    <span class="dash-card__hint">مجموع ساعت اضافه‌کاری پرسنل</span>
                </article>
            </div>

            <div class="dash__panels">
                <article class="h-card dash-panel">
                    <header class="dash-panel__head">
                        <h2>کاربران برتر اضافه‌کاری</h2>
                        <span class="dash-panel__tag">۵ نفر برتر</span>
                    </header>

                    <div class="dash-table-scroll">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>کاربر</th>
                                    <th>کل اضافه‌کاری</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in topOvertime" :key="row.username">
                                    <td>{{ toPersianDigits(index + 1) }}</td>
                                    <td>{{ row.username }}</td>
                                    <td>{{ row.total }}</td>
                                </tr>
                                <tr v-if="topOvertime.length === 0">
                                    <td colspan="3" class="dash-empty">هنوز اضافه‌کاری‌ای ثبت نشده است.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="h-card dash-panel">
                    <header class="dash-panel__head">
                        <h2>فعالیت‌های اخیر</h2>
                        <span class="dash-panel__tag">درخواست‌های در انتظار</span>
                    </header>

                    <ul v-if="activity.length" class="dash-activity">
                        <li v-for="(item, index) in activity" :key="`${item.type}-${item.username}-${index}`" class="dash-activity__item">
                            <span class="dash-activity__type" :class="`dash-activity__type--${item.type === 'مرخصی' ? 'leave' : item.type === 'اضافه‌کاری' ? 'overtime' : 'pass'}`">
                                {{ item.type }}
                            </span>
                            <span class="dash-activity__user">{{ item.username }}</span>
                            <span class="dash-activity__date">{{ toPersianDigits(item.date) }}</span>
                        </li>
                    </ul>
                    <p v-else class="dash-empty">درخواست در انتظاری وجود ندارد.</p>
                </article>
            </div>
        </template>
    </section>
</template>

<style scoped>
.dash {
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
}

.dash__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.dash__badge {
    display: inline-block;
    margin-bottom: 0.35rem;
    padding: 0.2rem 0.7rem;
    border-radius: 999px;
    background: var(--c-primary-ghost);
    color: var(--c-primary-dark);
    font-size: 0.72rem;
    font-weight: 700;
}

[data-theme='dark'] .dash__badge {
    background: var(--dk-surface-2);
    color: var(--dk-accent);
}

.dash__title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 800;
}

.dash__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .dash__sub {
    color: var(--dk-text-2);
}

.dash__refresh {
    flex-shrink: 0;
}

.dash__alert {
    margin: 0;
}

.dash__loading {
    padding: 3rem 1rem;
    text-align: center;
    color: #64748b;
}

[data-theme='dark'] .dash__loading {
    color: var(--dk-text-2);
}

.dash__cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.9rem;
}

.dash-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    padding: 1.1rem 1.2rem;
    overflow: hidden;
}

.dash-card::before {
    content: '';
    position: absolute;
    inset-inline-start: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--c-primary);
}

.dash-card--wide::before {
    background: var(--c-gold);
}

.dash-card__label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #64748b;
}

[data-theme='dark'] .dash-card__label {
    color: var(--dk-text-2);
}

.dash-card__value {
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}

.dash-card__hint {
    font-size: 0.72rem;
    color: #94a3b8;
}

[data-theme='dark'] .dash-card__hint {
    color: var(--dk-text-3);
}

.dash__panels {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 0.9rem;
}

.dash-panel {
    padding: 1.1rem 1.2rem;
}

.dash-panel__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    margin-bottom: 0.8rem;
}

.dash-panel__head h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
}

.dash-panel__tag {
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    background: rgb(15 23 42 / 0.05);
    color: #64748b;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
}

[data-theme='dark'] .dash-panel__tag {
    background: var(--dk-surface-2);
    color: var(--dk-text-2);
}

.dash-table-scroll {
    overflow-x: auto;
}

.dash-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.dash-table th,
.dash-table td {
    padding: 0.55rem 0.6rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .dash-table th,
[data-theme='dark'] .dash-table td {
    border-bottom-color: var(--dk-line);
}

.dash-table th {
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 700;
}

[data-theme='dark'] .dash-table th {
    color: var(--dk-text-2);
}

.dash-table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .dash-table tbody tr:hover {
    background: var(--dk-surface-2);
}

.dash-empty {
    padding: 1.4rem 0.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .dash-empty {
    color: var(--dk-text-3);
}

.dash-activity {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.dash-activity__item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.6rem;
    border-radius: var(--radius-token-sm);
    background: rgb(15 23 42 / 0.03);
    font-size: 0.82rem;
}

[data-theme='dark'] .dash-activity__item {
    background: var(--dk-surface-2);
}

.dash-activity__type {
    flex-shrink: 0;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
}

.dash-activity__type--leave {
    background: rgb(249 115 22 / 0.14);
    color: #c2410c;
}

[data-theme='dark'] .dash-activity__type--leave {
    color: #fdba74;
}

.dash-activity__type--overtime {
    background: rgb(168 85 247 / 0.14);
    color: #7e22ce;
}

[data-theme='dark'] .dash-activity__type--overtime {
    color: #d8b4fe;
}

.dash-activity__type--pass {
    background: rgb(14 165 233 / 0.14);
    color: #0369a1;
}

[data-theme='dark'] .dash-activity__type--pass {
    color: #7dd3fc;
}

.dash-activity__user {
    font-weight: 700;
}

.dash-activity__date {
    margin-inline-start: auto;
    color: #94a3b8;
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
}

[data-theme='dark'] .dash-activity__date {
    color: var(--dk-text-3);
}
</style>
