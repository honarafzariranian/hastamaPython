<script setup>
/**
 * Shift management — the legacy `shiftBox` define tab.
 *
 * GET /get_shifts/{username}/{year}/{month} lists one user's shift ranges
 * for a Jalali month; the popup adds (POST /add_shift) or edits
 * (POST /update_shift) a range, and delete asks for confirmation before
 * POST /delete_shift/{shift_id}.  The legacy validation messages are
 * reproduced verbatim.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const MONTHS = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
];

const WEEK_DAYS = [
    { key: 'shanbeh', label: 'شنبه' },
    { key: 'yekshanbeh', label: 'یکشنبه' },
    { key: 'doshanbeh', label: 'دوشنبه' },
    { key: 'seshanbeh', label: 'سه‌شنبه' },
    { key: 'chaharshanbeh', label: 'چهارشنبه' },
    { key: 'panjshanbeh', label: 'پنج‌شنبه' },
    { key: 'jomeh', label: 'جمعه' },
];

const users = ref([]);
const username = ref('');
const month = ref(1);
const year = ref(1405);

const loading = ref(false);
const error = ref('');
const notice = ref('');
const shifts = ref([]);

const popupOpen = ref(false);
const popupSaving = ref(false);
const popupMode = ref('add');
const popupForm = reactive({
    id: null,
    title: '',
    startDay: null,
    endDay: null,
    shanbeh: '',
    yekshanbeh: '',
    doshanbeh: '',
    seshanbeh: '',
    chaharshanbeh: '',
    panjshanbeh: '',
    jomeh: '',
});

const deleteId = ref(null);
const deleteSaving = ref(false);

const yearOptions = computed(() => {
    const options = [];

    for (let value = year.value - 2; value <= year.value + 2; value += 1) {
        options.push(value);
    }

    return options;
});

async function loadUsers() {
    try {
        const response = await api.get('/get_users', { baseURL: '' });
        users.value = response.users ?? [];
    } catch {
        users.value = [];
    }
}

async function loadShifts() {
    if (!username.value) {
        error.value = 'لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید';
        return;
    }

    loading.value = true;
    error.value = '';
    notice.value = '';
    shifts.value = [];

    try {
        const response = await api.get(
            `/get_shifts/${encodeURIComponent(username.value)}/${year.value}/${month.value}`,
            { baseURL: '' },
        );

        if (!response.success) {
            error.value = response.message || 'خطا در دریافت اطلاعات شیفت‌ها';
            return;
        }

        shifts.value = response.shifts ?? [];
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت اطلاعات شیفت‌ها';
    } finally {
        loading.value = false;
    }
}

function resetPopup() {
    popupForm.id = null;
    popupForm.title = '';
    popupForm.startDay = null;
    popupForm.endDay = null;

    for (const day of WEEK_DAYS) {
        popupForm[day.key] = '';
    }
}

function openAddPopup() {
    if (!username.value) {
        error.value = 'لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید';
        return;
    }

    resetPopup();
    popupMode.value = 'add';
    popupOpen.value = true;
}

function openEditPopup(shift) {
    popupForm.id = shift.id;
    popupForm.title = shift.title || '';
    popupForm.startDay = shift.start_day;
    popupForm.endDay = shift.end_day;

    for (const day of WEEK_DAYS) {
        popupForm[day.key] = shift[day.key] || '';
    }

    popupMode.value = 'edit';
    popupOpen.value = true;
}

function closePopup() {
    popupOpen.value = false;
}

async function saveShift() {
    const startDay = Number(popupForm.startDay);
    const endDay = Number(popupForm.endDay);

    if (!startDay || !endDay) {
        error.value = 'لطفاً بازه‌ی روز را وارد کنید';
        return;
    }

    if (startDay < 1 || startDay > 31 || endDay < 1 || endDay > 31 || startDay > endDay) {
        error.value = 'بازه‌ی روز نامعتبر است (باید بین ۱ تا ۳۱ باشد و روز شروع نباید بعد از روز پایان باشد)';
        return;
    }

    popupSaving.value = true;
    error.value = '';
    notice.value = '';

    const payload = {
        username: username.value,
        jalali_year: year.value,
        jalali_month: month.value,
        start_day: startDay,
        end_day: endDay,
        title: popupForm.title,
    };

    for (const day of WEEK_DAYS) {
        payload[day.key] = popupForm[day.key];
    }

    if (popupMode.value === 'edit') {
        payload.id = popupForm.id;
    }

    try {
        const response = await api.post(popupMode.value === 'edit' ? '/update_shift' : '/add_shift', payload);

        if (!response.success) {
            error.value = response.message || 'خطا در ذخیره‌ی شیفت';
            return;
        }

        notice.value = response.message || (popupMode.value === 'edit' ? 'شیفت با موفقیت ویرایش شد' : 'شیفت با موفقیت ذخیره شد');
        popupOpen.value = false;
        await loadShifts();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ذخیره‌ی شیفت';
    } finally {
        popupSaving.value = false;
    }
}

function askDelete(shiftId) {
    deleteId.value = shiftId;
}

function cancelDelete() {
    deleteId.value = null;
}

async function confirmDelete() {
    if (!deleteId.value) {
        return;
    }

    deleteSaving.value = true;
    error.value = '';
    notice.value = '';

    try {
        const response = await api.post(`/delete_shift/${deleteId.value}`, {}, { baseURL: '' });

        if (!response.success) {
            error.value = response.message || 'خطا در حذف شیفت';
            return;
        }

        notice.value = response.message || 'شیفت با موفقیت حذف شد';
        deleteId.value = null;
        await loadShifts();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در حذف شیفت';
    } finally {
        deleteSaving.value = false;
    }
}

onMounted(() => {
    const now = new Date();
    const persian = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
        year: 'numeric',
        month: 'numeric',
    }).formatToParts(now);

    for (const part of persian) {
        if (part.type === 'year') {
            year.value = Number(part.value);
        } else if (part.type === 'month') {
            month.value = Number(part.value);
        }
    }

    loadUsers();
});
</script>

<template>
    <section class="shifts">
        <header class="shifts__head">
            <div>
                <h1 class="shifts__title">مدیریت شیفت‌های ماهانه پرسنل</h1>
                <p class="shifts__sub">بازه‌های شیفتی هر پرسنل را ماه‌به‌ماه تعریف، ویرایش و مرور کنید</p>
            </div>
        </header>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div class="shifts__config">
            <div class="shifts__config-grid">
                <label class="h-field">
                    <span class="h-field__label">پرسنل</span>
                    <select v-model="username" class="h-input" required>
                        <option value="" disabled>انتخاب کنید</option>
                        <option v-for="user in users" :key="user.value" :value="user.value">
                            {{ user.label || user.value }}
                        </option>
                    </select>
                </label>
                <label class="h-field">
                    <span class="h-field__label">ماه شمسی</span>
                    <select v-model="month" class="h-input" required>
                        <option v-for="(name, index) in MONTHS" :key="name" :value="index + 1">
                            {{ name }}
                        </option>
                    </select>
                </label>
                <label class="h-field">
                    <span class="h-field__label">سال شمسی</span>
                    <select v-model="year" class="h-input" required>
                        <option v-for="yearOption in yearOptions" :key="yearOption" :value="yearOption">
                            {{ toPersianDigits(yearOption) }}
                        </option>
                    </select>
                </label>
                <div class="shifts__config-actions">
                    <button type="button" class="h-btn h-btn-primary" :disabled="loading" @click="loadShifts">
                        {{ loading ? 'در حال دریافت…' : 'نمایش شیفت‌های این ماه' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="shifts__table-card">
            <div class="shifts__table-head">
                <h2>بازه‌های شیفت ثبت‌شده</h2>
                <span v-if="shifts.length" class="shifts__count">{{ toPersianDigits(shifts.length) }}</span>
            </div>

            <div class="shifts__table-scroll">
                <table class="shifts__table">
                    <thead>
                        <tr>
                            <th>تغییرات</th>
                            <th>عنوان</th>
                            <th>جمعه</th>
                            <th>پنج‌شنبه</th>
                            <th>چهارشنبه</th>
                            <th>سه‌شنبه</th>
                            <th>دوشنبه</th>
                            <th>یکشنبه</th>
                            <th>شنبه</th>
                            <th>تا روز</th>
                            <th>از روز</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in shifts" :key="shift.id">
                            <td>
                                <div class="shifts__row-actions">
                                    <button
                                        type="button"
                                        class="h-btn h-btn-ghost shifts__icon-btn"
                                        title="ویرایش"
                                        aria-label="ویرایش شیفت"
                                        @click="openEditPopup(shift)"
                                    >
                                        ✎
                                    </button>
                                    <button
                                        type="button"
                                        class="h-btn h-btn-ghost shifts__icon-btn shifts__icon-btn--danger"
                                        title="حذف"
                                        aria-label="حذف شیفت"
                                        @click="askDelete(shift.id)"
                                    >
                                        🗑
                                    </button>
                                </div>
                            </td>
                            <td>{{ shift.title || '—' }}</td>
                            <td>{{ shift.jomeh || 'تعطیل/پیش‌فرض' }}</td>
                            <td>{{ shift.panjshanbeh || 'پیش‌فرض' }}</td>
                            <td>{{ shift.chaharshanbeh || 'پیش‌فرض' }}</td>
                            <td>{{ shift.seshanbeh || 'پیش‌فرض' }}</td>
                            <td>{{ shift.doshanbeh || 'پیش‌فرض' }}</td>
                            <td>{{ shift.yekshanbeh || 'پیش‌فرض' }}</td>
                            <td>{{ shift.shanbeh || 'پیش‌فرض' }}</td>
                            <td>{{ toPersianDigits(shift.end_day) }}</td>
                            <td>{{ toPersianDigits(shift.start_day) }}</td>
                        </tr>
                        <tr v-if="!loading && shifts.length === 0">
                            <td colspan="11" class="shifts__empty">
                                برای مشاهده، ابتدا پرسنل و ماه را انتخاب و «نمایش شیفت‌های این ماه» را بزنید
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="shifts__footer">
                <button type="button" class="h-btn h-btn-primary" @click="openAddPopup">
                    افزودن بازه‌ی شیفت جدید
                </button>
            </div>
        </div>

        <div v-if="popupOpen" class="shifts__modal-overlay" @click.self="closePopup">
            <div class="shifts__modal" role="dialog" aria-modal="true" aria-labelledby="shift-form-title">
                <header class="shifts__modal-head">
                    <div>
                        <h2 id="shift-form-title">
                            {{ popupMode === 'edit' ? 'ویرایش بازه‌ی شیفت' : 'افزودن بازه‌ی شیفت جدید' }}
                        </h2>
                        <p class="shifts__modal-sub">بازه‌ی روزها را مشخص و ساعت شیفت هر روز هفته را وارد کنید</p>
                    </div>
                    <button type="button" class="shifts__modal-close" aria-label="بستن" @click="closePopup">×</button>
                </header>

                <form class="shifts__modal-form" @submit.prevent="saveShift">
                    <label class="h-field">
                        <span class="h-field__label">عنوان شیفت (اختیاری)</span>
                        <input v-model="popupForm.title" type="text" class="h-input" placeholder="مثلا شیفت صبح" autocomplete="off">
                    </label>

                    <div class="shifts__range">
                        <label class="h-field">
                            <span class="h-field__label">از روز (۱ تا ۳۱ این ماه)</span>
                            <input v-model="popupForm.startDay" type="number" class="h-input" min="1" max="31" required>
                        </label>
                        <span class="shifts__range-dash" aria-hidden="true">—</span>
                        <label class="h-field">
                            <span class="h-field__label">تا روز (۱ تا ۳۱ این ماه)</span>
                            <input v-model="popupForm.endDay" type="number" class="h-input" min="1" max="31" required>
                        </label>
                    </div>

                    <div class="shifts__days">
                        <label v-for="day in WEEK_DAYS" :key="day.key" class="h-field">
                            <span class="h-field__label">{{ day.label }}</span>
                            <input v-model="popupForm[day.key]" type="text" class="h-input" placeholder="12:00 - 24:00">
                        </label>
                    </div>

                    <div class="shifts__modal-actions">
                        <button type="button" class="h-btn h-btn-ghost" @click="closePopup">انصراف</button>
                        <button
                            v-if="popupMode === 'edit'"
                            type="button"
                            class="h-btn h-btn-danger"
                            :disabled="popupSaving || deleteSaving"
                            @click="askDelete(popupForm.id)"
                        >
                            حذف این شیفت
                        </button>
                        <button type="submit" class="h-btn h-btn-primary" :disabled="popupSaving">
                            {{ popupSaving ? 'در حال ذخیره…' : (popupMode === 'edit' ? 'اعمال تغییرات شیفت' : 'ذخیره بازه‌ی شیفت') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="deleteId" class="shifts__modal-overlay" @click.self="cancelDelete">
            <div class="shifts__confirm" role="alertdialog" aria-modal="true" aria-labelledby="shift-delete-title">
                <h2 id="shift-delete-title">حذف شیفت</h2>
                <p>آیا از حذف این بازه‌ی شیفت مطمئن هستید؟</p>
                <div class="shifts__confirm-actions">
                    <button type="button" class="h-btn h-btn-ghost" @click="cancelDelete">انصراف</button>
                    <button type="button" class="h-btn h-btn-danger" :disabled="deleteSaving" @click="confirmDelete">
                        {{ deleteSaving ? 'در حال حذف…' : 'حذف' }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.shifts {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.shifts__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.shifts__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .shifts__sub {
    color: var(--dk-text-2);
}

.shifts__config {
    padding: 1rem 1.1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .shifts__config {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.shifts__config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.8rem;
    align-items: end;
}

.shifts__table-card {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    padding: 1.1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .shifts__table-card {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.shifts__table-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
}

.shifts__table-head h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
}

.shifts__count {
    padding: 0.15rem 0.7rem;
    border-radius: 999px;
    background: var(--c-primary-ghost);
    color: var(--c-primary-dark);
    font-size: 0.75rem;
    font-weight: 800;
}

[data-theme='dark'] .shifts__count {
    background: var(--dk-surface-2);
    color: var(--dk-accent);
}

.shifts__table-scroll {
    overflow-x: auto;
}

.shifts__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}

.shifts__table th,
.shifts__table td {
    padding: 0.6rem 0.65rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .shifts__table th,
[data-theme='dark'] .shifts__table td {
    border-bottom-color: var(--dk-line);
}

.shifts__table th {
    color: #64748b;
    font-size: 0.74rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .shifts__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.shifts__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .shifts__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.shifts__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .shifts__empty {
    color: var(--dk-text-3);
}

.shifts__row-actions {
    display: flex;
    gap: 0.3rem;
}

.shifts__icon-btn {
    padding: 0.3rem 0.55rem;
    font-size: 0.85rem;
}

.shifts__icon-btn--danger {
    color: #b91c1c;
}

[data-theme='dark'] .shifts__icon-btn--danger {
    color: #fca5a5;
}

.shifts__footer {
    display: flex;
    justify-content: flex-start;
}

.shifts__modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgb(15 23 42 / 0.5);
}

.shifts__modal {
    width: 100%;
    max-width: 760px;
    max-height: 90dvh;
    overflow-y: auto;
    border-radius: var(--radius-token-lg);
    background: #fff;
    box-shadow: var(--dk-shadow);
}

[data-theme='dark'] .shifts__modal {
    background: var(--dk-surface);
}

.shifts__modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.2rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.08);
}

[data-theme='dark'] .shifts__modal-head {
    border-bottom-color: var(--dk-line);
}

.shifts__modal-head h2 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
}

.shifts__modal-sub {
    margin: 0.25rem 0 0;
    color: #64748b;
    font-size: 0.78rem;
}

[data-theme='dark'] .shifts__modal-sub {
    color: var(--dk-text-2);
}

.shifts__modal-close {
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
}

.shifts__modal-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.2rem;
}

.shifts__range {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 0.7rem;
    align-items: end;
}

.shifts__range-dash {
    padding-bottom: 0.7rem;
    color: #94a3b8;
}

.shifts__days {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 0.8rem;
}

.shifts__modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
    flex-wrap: wrap;
}

.shifts__confirm {
    width: 100%;
    max-width: 24rem;
    padding: 1.4rem;
    border-radius: var(--radius-token-lg);
    background: #fff;
    box-shadow: var(--dk-shadow);
}

[data-theme='dark'] .shifts__confirm {
    background: var(--dk-surface);
}

.shifts__confirm h2 {
    margin: 0 0 0.5rem;
    font-size: 1.05rem;
    font-weight: 800;
}

.shifts__confirm p {
    margin: 0 0 1rem;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .shifts__confirm p {
    color: var(--dk-text-2);
}

.shifts__confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
}
</style>
