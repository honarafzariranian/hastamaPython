<script setup>
/**
 * Attendance (ورود و خروج) management — verbatim port of `hozoorbox`.
 *
 * Tabs:
 *   hozoor-report-tab   — Jalali date-range attendance report
 *                         GET /get_hozoor/{username}?start_date&end_date
 *   hozoor-panels-tab   — summary cards + manual registration form
 *                         POST /sabt_hozoor
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const WEEK_DAYS = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];

const activeTab = ref('hozoor-report-tab');
const notice = ref('');
const error = ref('');

const users = ref([]);

/* Report tab */
const reportForm = reactive({ username: '', startDate: '', endDate: '' });
const reportLoading = ref(false);
const reportRows = ref([]);
const reportGenerated = ref(false);
const stats = reactive({ presence: '00:00', overtime: '00:00', delay: '00:00', earlyStart: '00:00', earlyExit: '00:00' });
const statsVisible = ref(false);

/* Manual entry tab */
const manualForm = reactive({ username: '', date: '', vorood: '', khorooj: '' });
const manualSaving = ref(false);

function switchTab(tabId) { activeTab.value = tabId; error.value = ''; notice.value = ''; }

async function loadUsers() {
    try {
        const r = await api.get('/get_users', { baseURL: '' });
        users.value = (r.users ?? []).filter((u) => !u.is_active || u.is_active === 'active');
    } catch { users.value = []; }
}

function todayPersian() {
    const parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year:'numeric', month:'2-digit', day:'2-digit' }).formatToParts(new Date());
    const y = parts.find(p => p.type === 'year')?.value, m = parts.find(p => p.type === 'month')?.value, d = parts.find(p => p.type === 'day')?.value;
    return `${y}/${m}/${d}`;
}

function formatHm(totalMinutes) {
    const sign = totalMinutes < 0 ? '-' : '';
    totalMinutes = Math.abs(Math.round(totalMinutes));
    const h = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    return sign + toPersianDigits(String(h).padStart(2, '0')) + ':' + toPersianDigits(String(m).padStart(2, '0'));
}

function parseHM(str) {
    if (!str) return 0;
    const [h, m] = String(str).split(':').map((n) => parseInt(toLatinDigits(n), 10) || 0);
    return h * 60 + m;
}

function dayOfWeekJalali(dateStr) {
    // dateStr like "1405/01/15" — convert to JS via a coarse approach: use gregorian approximation via Intl.
    // For display we just compute weekday from today if the string parses.
    const parts = String(dateStr).split(/[/-]/).map((n) => parseInt(toLatinDigits(n), 10) || 0);
    if (parts.length !== 3) return '';
    // Use a known gregorian-equivalent mapping (approximate): Jalali epoch 1925/03/21 = 1304/01/01; use the standard 226-year cycle offset of 226899 days between 1925-03-21 and Jalali 0.
    // Simpler: compute using the Intl relative by creating a date.
    const [jy, jm, jd] = parts;
    // Jalali to Gregorian via algorithm:
    const dMap = [0, 31, 62, 93, 124, 155, 186, 216, 246, 276, 306, 336];
    const j1 = jy - 979;
    const j2 = (j1 < 0 ? j1 - 32 : j1) / 33 | 0;
    let j3 = j1 - 33*j2 + 1;
    const j4 = ((j3 + 3) / 4 | 0) * ((j3 * -1 + 4) % 4 ? 0 : 1);
    const days = 11 + 29 * (j3 - 1) + j4 + dMap[jm-1] + (jm > 6 ? 1 : 0) * ((j1 % 33) % 4 === 0 ? 1 : 0) + jd - 1;
    const gy = 1979 + 33*j2 + (j3 / 4 | 0) * ((j3 % 4) ? 0 : 1) + (days / 365 | 0);
    const gd = new Date(gy, 0, days % 365 + 1, 12);
    return WEEK_DAYS[gd.getDay() % 7] || '';
}

async function generateReport() {
    if (!reportForm.username || !reportForm.startDate || !reportForm.endDate) {
        error.value = 'لطفاً تمام فیلدها را پر کنید.'; return;
    }
    reportLoading.value = true; error.value = ''; notice.value = '';
    reportRows.value = []; reportGenerated.value = false; statsVisible.value = false;
    try {
        const r = await api.get(`/get_hozoor/${encodeURIComponent(reportForm.username)}`, {
            params: {
                start_date: toLatinDigits(reportForm.startDate),
                end_date: toLatinDigits(reportForm.endDate),
            },
            baseURL: '',
        });
        const rows = r.records || r.report || r.rows || r.data || [];
        reportRows.value = rows.map((row) => ({
            date: row.date || row.day,
            entry1: row.entry1 ?? row.entry ?? row.enter1 ?? row.checkin ?? row.entry_first ?? '',
            exit1:  row.exit1 ?? row.exit ?? row.leave1 ?? row.checkout ?? row.exit_first ?? '',
            entry2: row.entry2 ?? row.enter2 ?? row.checkin2 ?? row.entry_second ?? '',
            exit2:  row.exit2 ?? row.leave2 ?? row.checkout2 ?? row.exit_second ?? '',
            delay:      row.delay      ?? row.takhir    ?? '',
            earlyStart: row.earlyStart ?? row.early_start ?? row.shoroozd   ?? '',
            earlyExit:  row.earlyExit  ?? row.early_exit  ?? row.khorojzd   ?? '',
            overtime:   row.overtime   ?? row.ezafe       ?? '',
            total:      row.total      ?? row.majmoo     ?? row.presence ?? '',
        }));
        reportGenerated.value = true;
        // Compute totals
        let presence = 0, ot = 0, delay = 0, es = 0, ee = 0;
        for (const row of reportRows.value) {
            presence += parseHM(row.total);
            ot += parseHM(row.overtime);
            delay += parseHM(row.delay);
            es += parseHM(row.earlyStart);
            ee += parseHM(row.earlyExit);
        }
        stats.presence = formatHm(presence);
        stats.overtime = formatHm(ot);
        stats.delay = formatHm(delay);
        stats.earlyStart = formatHm(es);
        stats.earlyExit = formatHm(ee);
        statsVisible.value = reportRows.value.length > 0;
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت گزارش.';
    } finally { reportLoading.value = false; }
}

async function submitManual() {
    if (!manualForm.username || !manualForm.date) { error.value = 'لطفاً کاربر و تاریخ را انتخاب کنید.'; return; }
    manualSaving.value = true; error.value = ''; notice.value = '';
    try {
        const fd = new FormData();
        fd.append('usernamedast', manualForm.username);
        fd.append('tarikh', toLatinDigits(manualForm.date));
        fd.append('vorood', toLatinDigits(manualForm.vorood));
        fd.append('khorooj', toLatinDigits(manualForm.khorooj));
        const r = await api.post('/sabt_hozoor', fd, { baseURL: '' });
        if (r.success === false) { error.value = r.message || 'خطا در ثبت.'; return; }
        notice.value = 'اطلاعات با موفقیت ثبت شد.';
        manualForm.vorood = ''; manualForm.khorooj = '';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ثبت.';
    } finally { manualSaving.value = false; }
}

function goToFinalReport() {
    // Store report data for final report page (legacy flow)
    localStorage.setItem('finalReportData', JSON.stringify({
        username: reportForm.username,
        startDate: reportForm.startDate,
        endDate: reportForm.endDate,
        rows: reportRows.value,
        stats: { ...stats },
    }));
    window.open('/final_report_page', '_blank');
}

onMounted(() => { loadUsers(); manualForm.date = todayPersian(); });
</script>

<template>
    <header class="section-hero" style="--hero-accent:#22c55e;--hero-accent-2:#4ade80;--hero-glow-1:rgba(34,197,94,0.14);--hero-glow-2:rgba(74,222,128,0.12);--hero-shadow:rgba(34,197,94,0.55);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(34,197,94,0.08);">
        <div class="section-hero__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 8.5V6.5A2.5 2.5 0 016.5 4h2M15.5 4h2A2.5 2.5 0 0120 6.5v2M20 15.5v2a2.5 2.5 0 01-2.5 2.5h-2M8.5 20h-2A2.5 2.5 0 014 17.5v-2" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><circle cx="9.3" cy="10.8" r="1.25" fill="#fff"/><circle cx="14.7" cy="10.8" r="1.25" fill="#fff"/><path d="M9 14.8c.9.9 1.9 1.3 3 1.3s2.1-.4 3-1.3" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
        </div>
        <div class="section-hero__text">
            <h2>مدیریت ورود و خروج همکاران</h2>
            <p>ثبت و گزارش‌گیری ساعت کاری همکاران</p>
        </div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="attendance-frame">
        <div class="attendance-tabs" role="tablist">
            <button type="button" class="attendance-tab-btn" :class="{ active: activeTab === 'hozoor-report-tab' }" role="tab"
                :aria-selected="activeTab === 'hozoor-report-tab'" data-tab="hozoor-report-tab"
                @click="switchTab('hozoor-report-tab')">گزارش ورود و خروج</button>
            <button type="button" class="attendance-tab-btn" :class="{ active: activeTab === 'hozoor-panels-tab' }" role="tab"
                :aria-selected="activeTab === 'hozoor-panels-tab'" data-tab="hozoor-panels-tab"
                @click="switchTab('hozoor-panels-tab')">خلاصه و ثبت دستی</button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <!-- Tab 1: report -->
        <div id="hozoor-report-tab" class="attendance-tab-content" :class="{ active: activeTab === 'hozoor-report-tab' }" role="tabpanel">
            <div class="hozoor-report-card">
                <div class="hozoor-config-title">پارامترهای گزارش</div>
                <div class="hozoor-config-grid">
                    <div class="hozoor-config-item">
                        <span>انتخاب کاربر</span>
                        <select id="usernameGozareshHozoor" v-model="reportForm.username" required>
                            <option value="" disabled selected>انتخاب کنید</option>
                            <option value="all_users">همه کاربران</option>
                            <option v-for="u in users" :key="u.username" :value="u.username">{{ u.username }}</option>
                        </select>
                    </div>
                    <div class="hozoor-config-item">
                        <span>از تاریخ</span>
                        <input type="text" id="start_date_hozoor" v-model="reportForm.startDate" required placeholder="۱۴۰۵/۰۱/۰۱" autocomplete="off">
                    </div>
                    <div class="hozoor-config-item">
                        <span>تا تاریخ</span>
                        <input type="text" id="end_date_hozoor" v-model="reportForm.endDate" required placeholder="۱۴۰۵/۰۱/۳۱" autocomplete="off">
                    </div>
                    <div class="hozoor-config-item hozoor-config-actions">
                        <button type="button" id="extractButton" :disabled="reportLoading" @click="generateReport">
                            {{ reportLoading ? 'در حال تهیه…' : 'تهیه گزارش' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="hozoor-table-wrap">
                <table class="hozoorUsersReport-table" id="hozoorUsersReportTable">
                    <thead>
                        <tr>
                            <th class="sbt-hozoor-gzrsh">تاریخ ثبت</th>
                            <th class="hfte-hozoor-gzrsh">روز هفته</th>
                            <th class="zmnvrd-hozoor-gzrsh">ورود</th>
                            <th class="zmnkhrj-hozoor-gzrsh">خروج</th>
                            <th class="zmnvrd2-hozoor-gzrsh">ورود ۲</th>
                            <th class="zmnkhrj2-hozoor-gzrsh">خروج ۲</th>
                            <th class="takhir-hozoor-gzrsh">تاخیر</th>
                            <th class="shorozd-hozoor-gzrsh">شروع زود</th>
                            <th class="khorojzd-hozoor-gzrsh">خروج زود</th>
                            <th class="ezafe-hozoor-gzrsh">اضافه کاری</th>
                            <th class="mjmoo-hozoor-gzrsh">مجموع حضور</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="reportGenerated && reportRows.length > 0">
                            <tr v-for="(row, idx) in reportRows" :key="idx">
                                <td>{{ toPersianDigits(row.date) }}</td>
                                <td>{{ dayOfWeekJalali(row.date) }}</td>
                                <td>{{ toPersianDigits(row.entry1) || '—' }}</td>
                                <td>{{ toPersianDigits(row.exit1) || '—' }}</td>
                                <td>{{ toPersianDigits(row.entry2) || '—' }}</td>
                                <td>{{ toPersianDigits(row.exit2) || '—' }}</td>
                                <td>{{ toPersianDigits(row.delay) || '—' }}</td>
                                <td>{{ toPersianDigits(row.earlyStart) || '—' }}</td>
                                <td>{{ toPersianDigits(row.earlyExit) || '—' }}</td>
                                <td>{{ toPersianDigits(row.overtime) || '—' }}</td>
                                <td>{{ toPersianDigits(row.total) || '—' }}</td>
                            </tr>
                        </template>
                        <tr v-else-if="!reportLoading" class="hozoor-table-empty">
                            <td colspan="11">
                                <div class="hozoor-table-empty__state">
                                    <div class="hozoor-table-empty__icon">📊</div>
                                    <p>پس از تهیه گزارش، نتایج اینجا نمایش داده می‌شود</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="reportLoading">
                            <td colspan="11" style="text-align:center;padding:2rem;color:#64748b;">در حال تهیه گزارش…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="statsVisible" id="natigehHozoor" class="management-box attendance-panel">
                <div class="attendance-panel__head">
                    <div class="attendance-panel__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <h2 class="attendance-panel__title">مجموع ساعت کاری</h2>
                        <p class="attendance-panel__subtitle">خلاصه حضور و غیاب دوره انتخاب‌شده</p>
                    </div>
                </div>
                <div class="boxha attendance-stats">
                    <div class="attendance-stat attendance-stat--presence box1-hozoor">
                        <span class="attendance-stat__icon" aria-hidden="true">⏱</span>
                        <div class="attendance-stat__info"><span class="attendance-stat__label">مجموع حضور</span><span class="attendance-stat__value">{{ stats.presence }}</span></div>
                    </div>
                    <div class="attendance-stat attendance-stat--overtime box2-hozoor">
                        <span class="attendance-stat__icon" aria-hidden="true">⚡</span>
                        <div class="attendance-stat__info"><span class="attendance-stat__label">اضافه کاری</span><span class="attendance-stat__value">{{ stats.overtime }}</span></div>
                    </div>
                    <div class="attendance-stat attendance-stat--delay box3-hozoor">
                        <span class="attendance-stat__icon" aria-hidden="true">⏱</span>
                        <div class="attendance-stat__info"><span class="attendance-stat__label">تاخیر</span><span class="attendance-stat__value">{{ stats.delay }}</span></div>
                    </div>
                    <div class="attendance-stat attendance-stat--early-start box4-hozoor">
                        <span class="attendance-stat__icon" aria-hidden="true">↗</span>
                        <div class="attendance-stat__info"><span class="attendance-stat__label">شروع زودهنگام</span><span class="attendance-stat__value">{{ stats.earlyStart }}</span></div>
                    </div>
                    <div class="attendance-stat attendance-stat--early-exit box5-hozoor">
                        <span class="attendance-stat__icon" aria-hidden="true">↙</span>
                        <div class="attendance-stat__info"><span class="attendance-stat__label">خروج زودهنگام</span><span class="attendance-stat__value">{{ stats.earlyExit }}</span></div>
                    </div>
                </div>
                <div class="attendance-panel__footer">
                    <button class="gozareshNahaei attendance-panel__btn" @click="goToFinalReport">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        گزارش نهایی
                    </button>
                </div>
            </div>
        </div>

        <!-- Tab 2: panels + manual entry -->
        <div id="hozoor-panels-tab" class="attendance-tab-content" :class="{ active: activeTab === 'hozoor-panels-tab' }" role="tabpanel">
            <div id="attendancePanelsRow" class="management-box attendance-panels-row">
                <div id="sabtdst" class="management-box attendance-panel">
                    <div class="vorodiha attendance-form-wrap">
                        <form id="sabtdastHozoor" class="formsabtdast attendance-form" @submit.prevent="submitManual">
                            <div class="attendance-form__row">
                                <label for="usernamedast" class="attendance-form__label">انتخاب کاربر</label>
                                <select id="usernamedast" v-model="manualForm.username" required class="attendance-form__select">
                                    <option value="" disabled selected>انتخاب کنید</option>
                                    <option value="all_users">همه کاربران</option>
                                    <option v-for="u in users" :key="u.username" :value="u.username">{{ u.username }}</option>
                                </select>
                            </div>
                            <div class="attendance-form__row">
                                <label for="tarikh" class="attendance-form__label">تاریخ</label>
                                <input type="text" id="tarikh" v-model="manualForm.date" readonly autocomplete="off" class="attendance-form__input">
                            </div>
                            <div class="attendance-form__times">
                                <div class="attendance-form__row attendance-form__row--time">
                                    <label for="vorood" class="attendance-form__label">ساعت ورود</label>
                                    <div class="time-container">
                                        <input type="text" id="vorood" v-model="manualForm.vorood" placeholder="--:--" class="attendance-form__input attendance-form__input--time">
                                    </div>
                                </div>
                                <div class="attendance-form__row attendance-form__row--time">
                                    <label for="khorooj" class="attendance-form__label">ساعت خروج</label>
                                    <div class="time-container">
                                        <input type="text" id="khorooj" v-model="manualForm.khorooj" placeholder="--:--" class="attendance-form__input attendance-form__input--time">
                                    </div>
                                </div>
                            </div>
                        </form>
                        <button type="button" id="sabt-btn" class="attendance-form__submit" :disabled="manualSaving" @click="submitManual">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            {{ manualSaving ? 'در حال ثبت…' : 'ثبت اطلاعات' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
