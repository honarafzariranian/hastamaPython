<script setup>
/**
 * Shift management — verbatim port of `shiftBox` from admin.html.
 *
 * Tabs:
 *   shift-define  — per-user monthly shift ranges (GET /get_shifts/{u}/{y}/{m},
 *                   POST /add_shift, POST /update_shift, POST /delete_shift/{id})
 *   shift-active  — today's active shifts (GET /get_active_shifts)
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const MONTHS = [
    'فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور',
    'مهر','آبان','آذر','دی','بهمن','اسفند',
];
const MONTH_INDEX = Object.fromEntries(MONTHS.map((name, i) => [name, i + 1]));

const WEEK_DAYS = [
    { key: 'shanbeh',     label: 'شنبه' },
    { key: 'yekshanbeh',  label: 'یکشنبه' },
    { key: 'doshanbeh',   label: 'دوشنبه' },
    { key: 'seshanbeh',   label: 'سه‌شنبه' },
    { key: 'chaharshanbeh', label: 'چهارشنبه' },
    { key: 'panjshanbeh', label: 'پنج‌شنبه' },
    { key: 'jomeh',       label: 'جمعه' },
];

const activeTab = ref('shift-define');
const notice = ref('');
const error = ref('');

/* Selectors */
const users = ref([]);
const username = ref('');
const monthName = ref('فروردین');
const year = ref(1405);

/* Shifts list */
const loading = ref(false);
const shifts = ref([]);

/* Popup */
const popupOpen = ref(false);
const popupMode = ref('add');
const popupSaving = ref(false);
const popupForm = reactive({
    id: null,
    title: '',
    startDay: null,
    endDay: null,
    shanbeh: '', yekshanbeh: '', doshanbeh: '',
    seshanbeh: '', chaharshanbeh: '', panjshanbeh: '', jomeh: '',
});

/* Delete confirm */
const deleteConfirmOpen = ref(false);
const deleteTargetId = ref(null);
const deleteSaving = ref(false);

/* Active shifts */
const activeLoading = ref(false);
const activeShifts = ref([]);
const activeError = ref('');

const monthOptions = computed(() => MONTHS);
const yearOptions = computed(() => {
    const out = [];
    for (let y = year.value - 2; y <= year.value + 2; y += 1) out.push(y);
    return out;
});

function switchTab(tabId) {
    activeTab.value = tabId;
    notice.value = '';
    error.value = '';
}

async function loadUsers() {
    try {
        const response = await api.get('/get_users', { baseURL: '' });
        users.value = (response.users ?? []).filter((u) => !u.is_active || u.is_active === 'active');
    } catch { users.value = []; }
}

async function loadShifts() {
    if (!username.value) { error.value = 'لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید'; return; }
    loading.value = true; error.value = ''; notice.value = ''; shifts.value = [];
    const m = MONTH_INDEX[monthName.value] ?? 1;
    try {
        const response = await api.get(`/get_shifts/${encodeURIComponent(username.value)}/${year.value}/${m}`, { baseURL: '' });
        if (!response.success) { error.value = response.message || 'خطا در دریافت اطلاعات شیفت‌ها'; return; }
        shifts.value = response.shifts ?? [];
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت اطلاعات شیفت‌ها';
    } finally { loading.value = false; }
}

function resetPopup() {
    popupForm.id = null;
    popupForm.title = '';
    popupForm.startDay = null;
    popupForm.endDay = null;
    for (const d of WEEK_DAYS) popupForm[d.key] = '';
}

function openAddPopup() {
    if (!username.value) { error.value = 'لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید'; return; }
    resetPopup(); popupMode.value = 'add'; popupOpen.value = true;
}

function openEditPopup(shift) {
    popupForm.id = shift.id;
    popupForm.title = shift.title || '';
    popupForm.startDay = shift.start_day;
    popupForm.endDay = shift.end_day;
    for (const d of WEEK_DAYS) popupForm[d.key] = shift[d.key] || '';
    popupMode.value = 'edit';
    popupOpen.value = true;
}

function closePopup() { popupOpen.value = false; }

function submitShift(event) {
    if (event) event.preventDefault();
    saveShift();
}

async function saveShift() {
    popupSaving.value = true; error.value = ''; notice.value = '';
    const m = MONTH_INDEX[monthName.value] ?? 1;
    const payload = {
        username: username.value,
        year: year.value,
        month: m,
        title: popupForm.title || '',
        start_day: Number(popupForm.startDay),
        end_day: Number(popupForm.endDay),
    };
    for (const d of WEEK_DAYS) payload[d.key] = popupForm[d.key] || '';

    try {
        const url = popupMode.value === 'edit' ? '/update_shift' : '/add_shift';
        if (popupMode.value === 'edit') payload.id = popupForm.id;
        const response = await api.post(url, payload, { baseURL: '' });
        if (response.success === false) { error.value = response.message || 'خطا در ذخیره شیفت'; return; }
        notice.value = popupMode.value === 'edit' ? 'شیفت با موفقیت ویرایش شد.' : 'شیفت با موفقیت اضافه شد.';
        closePopup();
        await loadShifts();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ذخیره شیفت';
    } finally { popupSaving.value = false; }
}

function askDelete(id) { deleteTargetId.value = id; deleteConfirmOpen.value = true; }
function cancelDelete() { deleteConfirmOpen.value = false; deleteTargetId.value = null; }
async function confirmDelete() {
    if (!deleteTargetId.value) return;
    deleteSaving.value = true;
    try {
        const response = await api.post(`/delete_shift/${deleteTargetId.value}`, {}, { baseURL: '' });
        if (response.success === false) { error.value = response.message || 'خطا در حذف شیفت'; return; }
        notice.value = 'شیفت با موفقیت حذف شد.';
        shifts.value = shifts.value.filter((s) => s.id !== deleteTargetId.value);
        cancelDelete();
        if (popupOpen.value) closePopup();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در حذف شیفت';
    } finally { deleteSaving.value = false; }
}

async function loadActiveShifts() {
    activeLoading.value = true; activeError.value = '';
    try {
        const response = await api.get('/get_active_shifts', { baseURL: '' });
        activeShifts.value = Array.isArray(response) ? response : (response.shifts ?? []);
    } catch (failure) {
        activeError.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت شیفت‌های فعال';
        activeShifts.value = [];
    } finally { activeLoading.value = false; }
}

onMounted(() => {
    loadUsers();
    const now = new Date();
    const parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: 'numeric' }).formatToParts(now);
    for (const p of parts) {
        if (p.type === 'year') year.value = Number(toLatinDigits(p.value));
        else if (p.type === 'month') monthName.value = MONTHS[Number(p.value) - 1] || 'فروردین';
    }
});
</script>

<template>
    <header class="section-hero" style="--hero-accent:#6366F1;--hero-accent-2:#0EA5E9;--hero-glow-1:rgba(99,102,241,0.14);--hero-glow-2:rgba(14,165,233,0.12);--hero-shadow:rgba(99,102,241,0.45);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(99,102,241,0.08);">
        <div class="section-hero__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="4.5" width="18" height="16" rx="3.5" stroke="#fff" stroke-width="1.9"/>
                <path d="M3 9.5h18" stroke="#fff" stroke-width="1.9"/>
                <path d="M7.5 2.8v3.4M16.5 2.8v3.4" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/>
                <circle cx="12" cy="15" r="4.6" fill="#fff"/>
                <path d="M12 12.7v2.5l1.8 1.1" stroke="#4F46E5" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="section-hero__text">
            <h2>مدیریت شیفت‌های ماهانه پرسنل</h2>
            <p>بازه‌های شیفتی هر پرسنل را ماه‌به‌ماه تعریف، ویرایش و مرور کنید</p>
        </div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="shift-frame">
        <div class="shift-tabs" role="tablist">
            <button
                class="shift-tab-btn" :class="{ active: activeTab === 'shift-define' }" role="tab"
                :aria-selected="activeTab === 'shift-define'"
                @click="switchTab('shift-define')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                تعریف شیفت
            </button>
            <button
                class="shift-tab-btn" :class="{ active: activeTab === 'shift-active' }" role="tab"
                :aria-selected="activeTab === 'shift-active'"
                @click="switchTab('shift-active')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                شیفت‌های فعال امروز
            </button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <!-- Tab 1: define -->
        <div id="shift-define" class="shift-tab-content" :class="{ active: activeTab === 'shift-define' }" role="tabpanel">
            <div class="form-container-shift">
                <form id="shiftSelectForm" class="form-shift-krbr">
                    <div class="form-row-krbrjadid">
                        <label for="shiftUsername">پرسنل</label>
                        <select id="shiftUsername" v-model="username" required>
                            <option value="" disabled selected>انتخاب کنید</option>
                            <option v-for="user in users" :key="user.username" :value="user.username">{{ user.username }}</option>
                        </select>
                    </div>
                    <div class="form-row-krbrjadid">
                        <label for="shiftMonth">ماه شمسی</label>
                        <select id="shiftMonth" v-model="monthName" required>
                            <option v-for="m in monthOptions" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                    <div class="form-row-krbrjadid">
                        <label for="shiftYear">سال شمسی</label>
                        <select id="shiftYear" v-model.number="year" required>
                            <option v-for="y in yearOptions" :key="y" :value="y">{{ toPersianDigits(y) }}</option>
                        </select>
                    </div>
                </form>
                <button type="button" class="show-shift-krbrinmah" id="loadShiftsBtn" :disabled="loading" @click="loadShifts">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                    <span>{{ loading ? 'در حال بارگذاری…' : 'نمایش شیفت‌های این ماه' }}</span>
                </button>
            </div>

            <div class="shift-table-card">
                <div class="shift-table-card__head">
                    <div class="shift-table-card__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                        <span>بازه‌های شیفت ثبت‌شده</span>
                    </div>
                    <span v-if="shifts.length" class="shift-count-badge">{{ toPersianDigits(shifts.length) }}</span>
                </div>
                <div class="shift-table-scroll">
                    <table class="request-table" id="shiftsTable">
                        <thead>
                            <tr>
                                <th>تغییرات</th>
                                <th>عنوان</th>
                                <th>جمعه</th><th>پنج‌شنبه</th><th>چهارشنبه</th><th>سه‌شنبه</th>
                                <th>دوشنبه</th><th>یکشنبه</th><th>شنبه</th>
                                <th>تا روز</th><th>از روز</th>
                            </tr>
                        </thead>
                        <tbody id="shiftsTableBody">
                            <tr v-if="loading">
                                <td colspan="11" style="text-align:center;padding:2rem;color:#64748b;">در حال بارگذاری…</td>
                            </tr>
                            <template v-else>
                                <tr v-for="shift in shifts" :key="shift.id">
                                    <td>
                                        <button type="button" class="shift-row-btn" title="ویرایش" @click="openEditPopup(shift)">ویرایش</button>
                                        <button type="button" class="shift-row-btn shift-row-btn--danger" title="حذف" @click="askDelete(shift.id)">حذف</button>
                                    </td>
                                    <td>{{ shift.title || '—' }}</td>
                                    <td>{{ shift.jomeh || 'تعطیل' }}</td>
                                    <td>{{ shift.panjshanbeh || 'پیش‌فرض' }}</td>
                                    <td>{{ shift.chaharshanbeh || 'پیش‌فرض' }}</td>
                                    <td>{{ shift.seshanbeh || 'پیش‌فرض' }}</td>
                                    <td>{{ shift.doshanbeh || 'پیش‌فرض' }}</td>
                                    <td>{{ shift.yekshanbeh || 'پیش‌فرض' }}</td>
                                    <td>{{ shift.shanbeh || 'پیش‌فرض' }}</td>
                                    <td>{{ toPersianDigits(shift.end_day) }}</td>
                                    <td>{{ toPersianDigits(shift.start_day) }}</td>
                                </tr>
                                <tr v-if="shifts.length === 0" class="shifts-empty-row" data-shift-hint>
                                    <td colspan="11">برای مشاهده، ابتدا پرسنل و ماه را انتخاب و «نمایش شیفت‌های این ماه» را بزنید</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="shift-footer-row">
                <button type="button" class="add-shift-krbrinmah" id="openShiftPopupBtn" @click="openAddPopup">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    <span>افزودن بازه‌ی شیفت جدید</span>
                </button>
            </div>
        </div>

        <!-- Tab 2: active shifts -->
        <div id="shift-active" class="shift-tab-content" :class="{ active: activeTab === 'shift-active' }" role="tabpanel">
            <div class="shift-active-header">
                <div class="shift-active-header__info">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    <span>شیفت‌هایی که امروز فعال هستند</span>
                </div>
                <button type="button" class="show-shift-krbrinmah" :disabled="activeLoading" @click="loadActiveShifts">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <span>{{ activeLoading ? 'در حال بارگذاری…' : 'بروزرسانی' }}</span>
                </button>
            </div>
            <div class="shift-active-cards" id="shiftActiveCards">
                <p v-if="!activeLoading && activeShifts.length === 0 && !activeError" class="shifts-empty-row">
                    برای مشاهده شیفت‌های فعال، دکمه بروزرسانی را بزنید
                </p>
                <p v-if="activeError" style="text-align:center;padding:2rem;color:#dc2626;">{{ activeError }}</p>
                <article v-for="s in activeShifts" :key="s.id || s.username" class="shift-active-card">
                    <header class="shift-active-card__header">
                        <div class="shift-active-card__user">{{ s.username || s.user }}</div>
                        <button v-if="s.edit_url" type="button" class="shift-active-card__edit-btn">ویرایش</button>
                    </header>
                    <div class="shift-active-card__title">{{ s.title || 'شیفت' }}</div>
                    <div class="shift-active-card__range">{{ s.range || '' }}</div>
                    <div class="shift-active-card__schedule">
                        <div v-for="d in WEEK_DAYS" :key="d.key" class="shift-active-card__schedule-row">
                            <span class="shift-active-card__day">{{ d.label }}</span>
                            <span class="shift-active-card__time">{{ s[d.key] || '—' }}</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </div>

    <!-- Add / Edit popup -->
    <div v-if="popupOpen" id="shiftPopupOverlay" class="shift-popup-overlay" @click.self="closePopup">
        <div id="shiftPopup" class="shift-popup" role="dialog" aria-modal="true" aria-labelledby="shiftFormTitle">
            <div class="shift-popup-content">
                <header class="shift-popup-head">
                    <div class="shift-popup-head__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="17" rx="3.5"/><path d="M8 2.5v4M16 2.5v4M3.5 9.5h17M12 13v6M9 16h6"/></svg>
                    </div>
                    <div class="shift-popup-head__text">
                        <h2 id="shiftFormTitle">{{ popupMode === 'edit' ? 'ویرایش بازه‌ی شیفت' : 'افزودن بازه‌ی شیفت جدید' }}</h2>
                        <p id="shiftFormSubtitle">بازه‌ی روزها را مشخص و ساعت شیفت هر روز هفته را وارد کنید</p>
                    </div>
                    <button type="button" class="close-shift-popup" aria-label="بستن" @click="closePopup">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </header>

                <div v-if="popupMode === 'edit'" id="shiftPopupMeta" class="shift-popup-meta">
                    <div class="shift-popup-meta__item">
                        <span class="shift-popup-meta__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <span class="shift-popup-meta__label">پرسنل:</span>
                        <strong class="shift-popup-meta__value" id="shiftPopupMetaUser">{{ username }}</strong>
                    </div>
                    <div class="shift-popup-meta__item">
                        <span class="shift-popup-meta__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </span>
                        <span class="shift-popup-meta__label">ماه و سال:</span>
                        <strong class="shift-popup-meta__value" id="shiftPopupMetaDate">{{ monthName }} {{ toPersianDigits(year) }}</strong>
                    </div>
                </div>

                <form id="shiftForm" class="form-krbrjadid shift-details-form" @submit.prevent="submitShift">
                    <div class="form-row-krbrjadid shift-details-form__title-row">
                        <label for="shiftTitle">عنوان شیفت (اختیاری)</label>
                        <input type="text" id="shiftTitle" v-model="popupForm.title" placeholder="مثلا شیفت صبح" autocomplete="off">
                    </div>
                    <div class="shift-days-range">
                        <div class="form-row-krbrjadid">
                            <label for="shiftStartDay">از روز (۱ تا ۳۱ این ماه)</label>
                            <input type="number" id="shiftStartDay" v-model.number="popupForm.startDay" min="1" max="31" required>
                        </div>
                        <div class="shift-days-range__dash" aria-hidden="true"></div>
                        <div class="form-row-krbrjadid">
                            <label for="shiftEndDay">تا روز (۱ تا ۳۱ این ماه)</label>
                            <input type="number" id="shiftEndDay" v-model.number="popupForm.endDay" min="1" max="31" required>
                        </div>
                    </div>
                </form>

                <div class="schedule-container shift-schedule-grid">
                    <div class="days-column">
                        <div v-for="d in WEEK_DAYS" :key="d.key" class="day-label">
                            <span class="day-dot" :class="`day-dot--${d.key}`" aria-hidden="true"></span>{{ d.label }}
                        </div>
                    </div>
                    <div class="fields-column">
                        <input v-for="d in WEEK_DAYS" :key="d.key" :id="`shift_${d.key}`" type="text" v-model="popupForm[d.key]" placeholder="12:00 - 24:00 (خالی = تعطیل/شیفت پیش‌فرض)">
                    </div>
                </div>

                <div class="shift-popup-actions">
                    <button type="button" class="save-btn-krbrjad" id="saveShiftBtn" :disabled="popupSaving" @click="saveShift">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5 10 18 19.5 7"/></svg>
                        <span>{{ popupSaving ? 'در حال ذخیره…' : 'ذخیره بازه‌ی شیفت' }}</span>
                    </button>
                    <button v-if="popupMode === 'edit'" type="button" class="save-btn-krbrjad shift-delete-btn" id="deleteShiftModalBtn" @click="askDelete(popupForm.id)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <span>حذف این شیفت</span>
                    </button>
                    <button type="button" class="save-btn-krbrjad shift-cancel-btn" id="cancelShiftEditBtn" @click="closePopup">انصراف</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <div v-if="deleteConfirmOpen" class="modal-hazf" @click.self="cancelDelete">
        <div class="modal-content-hazfPopup">
            <h2>آیا از حذف این شیفت مطمئن هستید؟</h2>
            <button id="confirmDeleteBtnTicket" class="ok-btn" :disabled="deleteSaving" @click="confirmDelete">
                {{ deleteSaving ? 'در حال حذف…' : 'تایید' }}
            </button>
            <button class="cancel-btn-ticket-karbar" @click="cancelDelete">انصراف</button>
        </div>
    </div>
</template>

<style scoped>
.shift-row-btn {
    padding: 0.3rem 0.6rem;
    margin-inline-start: 0.25rem;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
    color: #0f172a;
    font: inherit;
    font-size: 0.75rem;
    cursor: pointer;
}
.shift-row-btn:hover { background: #f1f5f9; }
.shift-row-btn--danger { color: #dc2626; border-color: #fca5a5; }
.shift-row-btn--danger:hover { background: #fef2f2; }
</style>
