<script setup>
/**
 * User management — the legacy `coworkerBox`.
 *
 * Directory (GET /master-admin/api/users, paginated + searchable), the
 * new-user form (POST /add_user — the port validates FastAPI-style form
 * fields, so the payload is sent as application/x-www-form-urlencoded),
 * the edit popup (POST /update_user) and the two per-row actions
 * (toggle-status / change-role on the master-admin user endpoints).
 *
 * The legacy delete button posted to the server-rendered admin page; the
 * Laravel port has no delete-user route, so no delete action is rendered.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const DEPARTMENTS = [
    'بیوشیمی', 'هورمون', 'میکروب', 'مولکولی', 'مدیریت', 'پذیرش',
    'نمونه گیری', 'جوابدهی', 'حسابداری', 'ایمونولوژی', 'خدمات', 'فناوری', 'هماتولوژی',
];

const WORK_HOURS = [
    '16:00 - 09:00', '14:00 - 07:00', '15:00 - 08:00', '20:00 - 14:00',
    '18:00 - 13:00', '13:30 - 06:30', '20:00 - 13:00', '14:30 - 07:30',
    '20:30 - 13:30', '00:00 - 00:00',
];

const SUBSTITUTES = [
    'بدون جانشین', 'فاطمه سالاری فر', 'مریم براتی', 'زهره محمودی', 'محدثه گنجمه',
    'عالیه سقایی', 'فرشته ایزدی', 'مرتضی کمالی', 'سیدمرتضی موسوی پور', 'محمدمهدی صمدیان',
    'صبا حلاجی', 'سیاوش آراسته', 'حسین عرفانیان', 'علی شاکری', 'کیمیا حبیبی',
    'حوریه تاجیک', 'فرشته جعفری', 'رضا یوسفیان', 'عبدالله بهمدی',
];

const WEEK_DAYS = [
    { key: 'shanbeh', label: 'شنبه' },
    { key: 'yekshanbeh', label: 'یکشنبه' },
    { key: 'doshanbeh', label: 'دوشنبه' },
    { key: 'seshanbeh', label: 'سه‌شنبه' },
    { key: 'chrshanbeh', label: 'چهارشنبه' },
    { key: 'panjshanbeh', label: 'پنج‌شنبه' },
];

const activeTab = ref('users');

const loading = ref(true);
const error = ref('');
const notice = ref('');

const users = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);
const perPage = 15;
const search = ref('');

const editOpen = ref(false);
const editSaving = ref(false);
const editForm = reactive({
    currentUsername: '',
    username: '',
    password: '',
    department: '',
    workHours: '',
    employmentStatus: 'official',
    isActive: 'active',
    substitute: '',
});

const addForm = reactive({
    name: '',
    lastName: '',
    username: '',
    password: '',
    department: '',
    workHours: '',
    employmentStatus: 'official',
    role: '',
    substitute: '',
    hozoorNum: '',
    shanbeh: '',
    yekshanbeh: '',
    doshanbeh: '',
    seshanbeh: '',
    chrshanbeh: '',
    panjshanbeh: '',
});

const addSaving = ref(false);
const addError = ref('');

const busyUsername = ref('');

function displayField(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

function roleLabel(role) {
    return role === 'admin' ? 'مدیر' : 'کاربر';
}

function statusLabel(isActive) {
    return isActive === 'inactive' ? 'غیرفعال' : 'فعال';
}

async function loadUsers() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/users', {
            params: {
                page: page.value,
                per_page: perPage,
                search: search.value,
            },
        });

        users.value = response.data ?? [];
        total.value = response.total ?? users.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت فهرست کاربران.';
        users.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function applySearch() {
    page.value = 1;
    loadUsers();
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadUsers();
}

function openEdit(user) {
    editForm.currentUsername = user.username;
    editForm.username = user.username;
    editForm.password = '';
    editForm.department = user.department ?? '';
    editForm.workHours = user.work_hours ?? '';
    editForm.employmentStatus = user.employment_status ?? 'official';
    editForm.isActive = user.is_active === 'inactive' ? 'inactive' : 'active';
    editForm.substitute = user.substitute ?? '';
    editOpen.value = true;
}

function closeEdit() {
    editOpen.value = false;
}

async function submitEdit() {
    if (!editForm.username.trim()) {
        error.value = 'نام کاربری را وارد کنید.';
        return;
    }

    editSaving.value = true;
    error.value = '';
    notice.value = '';

    try {
        await api.post('/update_user', {
            current_username: editForm.currentUsername,
            username: editForm.username.trim(),
            password: editForm.password,
            substitute: editForm.substitute,
            work_hours: editForm.workHours,
            department: editForm.department,
            employment_status: editForm.employmentStatus,
            is_active: editForm.isActive,
        });

        notice.value = 'اطلاعات با موفقیت ثبت شد.';
        editOpen.value = false;
        await loadUsers();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ثبت اطلاعات.';
    } finally {
        editSaving.value = false;
    }
}

async function toggleStatus(user) {
    busyUsername.value = user.username;
    error.value = '';
    notice.value = '';

    try {
        const response = await api.post(`/master-admin/api/users/${encodeURIComponent(user.username)}/toggle-status`);
        const newStatus = response.new_status === 'inactive' ? 'inactive' : 'active';

        const row = users.value.find((item) => item.username === user.username);
        if (row) {
            row.is_active = newStatus;
        }

        notice.value = newStatus === 'active' ? 'کاربر فعال شد.' : 'کاربر غیرفعال شد.';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در تغییر وضعیت کاربر.';
    } finally {
        busyUsername.value = '';
    }
}

async function changeRole(user) {
    const nextRole = user.role === 'admin' ? 'user' : 'admin';

    busyUsername.value = user.username;
    error.value = '';
    notice.value = '';

    try {
        await api.post(`/master-admin/api/users/${encodeURIComponent(user.username)}/change-role`, {
            role: nextRole,
        });

        const row = users.value.find((item) => item.username === user.username);
        if (row) {
            row.role = nextRole;
        }

        notice.value = nextRole === 'admin' ? 'نقش کاربر به مدیر تغییر کرد.' : 'نقش کاربر به کاربر عادی تغییر کرد.';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در تغییر نقش کاربر.';
    } finally {
        busyUsername.value = '';
    }
}

function applyAllDays() {
    const value = addForm.shanbeh;
    if (!value) {
        return;
    }

    for (const day of WEEK_DAYS) {
        addForm[day.key] = value;
    }
}

function resetAddForm() {
    addForm.name = '';
    addForm.lastName = '';
    addForm.username = '';
    addForm.password = '';
    addForm.department = '';
    addForm.workHours = '';
    addForm.employmentStatus = 'official';
    addForm.role = '';
    addForm.substitute = '';
    addForm.hozoorNum = '';
    addForm.shanbeh = '';
    addForm.yekshanbeh = '';
    addForm.doshanbeh = '';
    addForm.seshanbeh = '';
    addForm.chrshanbeh = '';
    addForm.panjshanbeh = '';
}

async function submitAdd() {
    addError.value = '';

    if (!addForm.name.trim() || !addForm.lastName.trim() || !addForm.username.trim() || !addForm.password.trim()) {
        addError.value = 'نام، نام خانوادگی، نام کاربری و رمز عبور الزامی هستند.';
        return;
    }

    if (!addForm.department || !addForm.workHours || !addForm.role || !addForm.substitute || !addForm.hozoorNum.trim()) {
        addError.value = 'لطفاً همه فیلدهای اطلاعات شغلی و کد ساعت زن را پر کنید.';
        return;
    }

    addSaving.value = true;

    try {
        const payload = new URLSearchParams();
        payload.set('name', addForm.name.trim());
        payload.set('last_name', addForm.lastName.trim());
        payload.set('username', addForm.username.trim());
        payload.set('password', addForm.password);
        payload.set('department', addForm.department);
        payload.set('work_hours', addForm.workHours);
        payload.set('employment_status', addForm.employmentStatus);
        payload.set('role', addForm.role);
        payload.set('substitute', addForm.substitute);
        payload.set('hozoorNum', addForm.hozoorNum.trim());
        payload.set('shanbeh', addForm.shanbeh);
        payload.set('yekshanbeh', addForm.yekshanbeh);
        payload.set('doshanbeh', addForm.doshanbeh);
        payload.set('seshanbeh', addForm.seshanbeh);
        payload.set('chrshanbeh', addForm.chrshanbeh);
        payload.set('panjshanbeh', addForm.panjshanbeh);

        await api.post('/add_user', payload);

        notice.value = 'کاربر جدید با موفقیت ثبت شد.';
        resetAddForm();
        activeTab.value = 'users';
        page.value = 1;
        await loadUsers();
    } catch (failure) {
        addError.value = failure.apiFailure?.message || failure.message || 'خطا در ثبت کاربر جدید.';
    } finally {
        addSaving.value = false;
    }
}

onMounted(loadUsers);

const pageNumbers = computed(() => {
    const result = [];
    const max = Math.min(pages.value, 5);
    let start = Math.max(1, Math.min(page.value - 2, pages.value - 4));

    for (let i = 0; i < max; i += 1) {
        result.push(start + i);
    }

    return result;
});
</script>

<template>
    <section class="users">
        <header class="users__head">
            <div>
                <h1 class="users__title">مدیریت کارکنان</h1>
                <p class="users__sub">مدیریت اطلاعات و دسترسی کاربران سامانه</p>
            </div>
        </header>

        <div class="users__tabs" role="tablist">
            <button
                type="button"
                class="users__tab"
                :class="{ 'is-active': activeTab === 'users' }"
                role="tab"
                :aria-selected="activeTab === 'users'"
                @click="activeTab = 'users'"
            >
                اطلاعات کاربران
            </button>
            <button
                type="button"
                class="users__tab"
                :class="{ 'is-active': activeTab === 'new' }"
                role="tab"
                :aria-selected="activeTab === 'new'"
                @click="activeTab = 'new'"
            >
                تعریف کاربر جدید
            </button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <div v-show="activeTab === 'users'">
            <div class="users__toolbar">
                <label class="users__search">
                    <span class="sr-only">جستجوی کاربر</span>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="جستجو بر اساس نام کاربری…"
                        @keyup.enter="applySearch"
                    >
                </label>
                <button type="button" class="h-btn h-btn-ghost" @click="applySearch">جستجو</button>
            </div>

            <div v-if="loading" class="users__loading">در حال دریافت کاربران…</div>

            <template v-else>
                <div class="users__table-scroll">
                    <table class="users__table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>نام کاربر</th>
                                <th>بخش</th>
                                <th>ساعت کاری</th>
                                <th>جانشین</th>
                                <th>نقش</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(user, index) in users" :key="user.username">
                                <td>{{ toPersianDigits((page - 1) * perPage + index + 1) }}</td>
                                <td>{{ user.username }}</td>
                                <td>{{ displayField(user.department) }}</td>
                                <td>{{ displayField(user.work_hours) }}</td>
                                <td>{{ displayField(user.substitute) }}</td>
                                <td>
                                    <span class="users__role" :class="user.role === 'admin' ? 'is-admin' : ''">
                                        {{ roleLabel(user.role) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="users__status" :class="user.is_active === 'inactive' ? 'is-inactive' : 'is-active'">
                                        {{ statusLabel(user.is_active) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="users__actions">
                                        <button
                                            type="button"
                                            class="h-btn h-btn-ghost users__action"
                                            @click="openEdit(user)"
                                        >
                                            ویرایش
                                        </button>
                                        <button
                                            type="button"
                                            class="h-btn h-btn-ghost users__action"
                                            :disabled="busyUsername === user.username"
                                            @click="toggleStatus(user)"
                                        >
                                            {{ user.is_active === 'inactive' ? 'فعال‌سازی' : 'غیرفعال‌سازی' }}
                                        </button>
                                        <button
                                            type="button"
                                            class="h-btn h-btn-ghost users__action"
                                            :disabled="busyUsername === user.username"
                                            @click="changeRole(user)"
                                        >
                                            {{ user.role === 'admin' ? 'تغییر به کاربر' : 'تغییر به مدیر' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="users.length === 0">
                                <td colspan="8" class="users__empty">کاربری یافت نشد.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="pages > 1" class="users__pagination">
                    <button
                        type="button"
                        class="users__page"
                        :disabled="page <= 1"
                        @click="goToPage(page - 1)"
                    >
                        قبلی
                    </button>
                    <button
                        v-for="pageNumber in pageNumbers"
                        :key="pageNumber"
                        type="button"
                        class="users__page"
                        :class="{ 'is-active': pageNumber === page }"
                        @click="goToPage(pageNumber)"
                    >
                        {{ toPersianDigits(pageNumber) }}
                    </button>
                    <button
                        type="button"
                        class="users__page"
                        :disabled="page >= pages"
                        @click="goToPage(page + 1)"
                    >
                        بعدی
                    </button>
                </div>
            </template>
        </div>

        <div v-show="activeTab === 'new'" class="users__new">
            <form class="users__form" @submit.prevent="submitAdd">
                <fieldset class="users__fieldset">
                    <legend>اطلاعات هویتی</legend>
                    <div class="users__grid">
                        <label class="h-field">
                            <span class="h-field__label">نام</span>
                            <input v-model="addForm.name" type="text" class="h-input" required autocomplete="off">
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">نام خانوادگی</span>
                            <input v-model="addForm.lastName" type="text" class="h-input" required autocomplete="off">
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">نام کاربری</span>
                            <input v-model="addForm.username" type="text" class="h-input" required autocomplete="off">
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">رمز عبور</span>
                            <input v-model="addForm.password" type="password" class="h-input" required autocomplete="new-password">
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">بخش فعالیت</span>
                            <select v-model="addForm.department" class="h-input" required>
                                <option value="" disabled>انتخاب کنید</option>
                                <option v-for="department in DEPARTMENTS" :key="department" :value="department">
                                    {{ department }}
                                </option>
                            </select>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="users__fieldset">
                    <legend>اطلاعات شغلی</legend>
                    <div class="users__grid">
                        <label class="h-field">
                            <span class="h-field__label">ساعت کاری</span>
                            <select v-model="addForm.workHours" class="h-input" required>
                                <option value="" disabled>انتخاب کنید</option>
                                <option v-for="hours in WORK_HOURS" :key="hours" :value="hours">{{ hours }}</option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">وضعیت استخدام</span>
                            <select v-model="addForm.employmentStatus" class="h-input" required>
                                <option value="official">رسمی</option>
                                <option value="unofficial">غیر رسمی</option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">نوع کاربری</span>
                            <select v-model="addForm.role" class="h-input" required>
                                <option value="" disabled>انتخاب کنید</option>
                                <option value="admin">مدیر</option>
                                <option value="user">کاربر عادی</option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">جانشین</span>
                            <select v-model="addForm.substitute" class="h-input" required>
                                <option value="" disabled>انتخاب کنید</option>
                                <option v-for="substitute in SUBSTITUTES" :key="substitute" :value="substitute">
                                    {{ substitute }}
                                </option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">کد ساعت زن</span>
                            <input v-model="addForm.hozoorNum" type="text" class="h-input" placeholder="۸ رقمی" required autocomplete="off">
                        </label>
                    </div>
                </fieldset>

                <fieldset class="users__fieldset">
                    <legend>ساعات کاری هفتگی</legend>
                    <div class="users__apply-all">
                        <label class="h-field">
                            <span class="h-field__label">اعمال بر همه روزها</span>
                            <input type="text" class="h-input" placeholder="مثلاً ۰۸:۰۰ - ۱۶:۰۰">
                        </label>
                        <button type="button" class="h-btn h-btn-ghost" @click="applyAllDays">اعمال</button>
                    </div>
                    <div class="users__days">
                        <label v-for="day in WEEK_DAYS" :key="day.key" class="h-field">
                            <span class="h-field__label">{{ day.label }}</span>
                            <input v-model="addForm[day.key]" type="text" class="h-input" placeholder="۰۸:۰۰ - ۱۶:۰۰">
                        </label>
                    </div>
                </fieldset>

                <p v-if="addError" class="h-alert" role="alert">{{ addError }}</p>

                <div class="users__form-footer">
                    <span class="users__hint">فیلدهای ستاره‌دار الزامی هستند</span>
                    <button type="submit" class="h-btn h-btn-primary" :disabled="addSaving">
                        {{ addSaving ? 'در حال ثبت…' : 'ذخیره کاربر' }}
                    </button>
                </div>
            </form>
        </div>

        <div v-if="editOpen" class="users__modal-overlay" @click.self="closeEdit">
            <div class="users__modal" role="dialog" aria-modal="true" aria-labelledby="edit-user-title">
                <header class="users__modal-head">
                    <h2 id="edit-user-title">ویرایش اطلاعات کاربر</h2>
                    <button type="button" class="users__modal-close" aria-label="بستن" @click="closeEdit">×</button>
                </header>

                <form class="users__modal-form" @submit.prevent="submitEdit">
                    <div class="users__modal-grid">
                        <label class="h-field">
                            <span class="h-field__label">نام کاربری</span>
                            <input v-model="editForm.username" type="text" class="h-input" required autocomplete="off">
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">رمز عبور جدید</span>
                            <input
                                v-model="editForm.password"
                                type="password"
                                class="h-input"
                                placeholder="برای حفظ رمز قبلی خالی بگذارید"
                                autocomplete="new-password"
                            >
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">بخش فعالیت</span>
                            <select v-model="editForm.department" class="h-input" required>
                                <option v-for="department in DEPARTMENTS" :key="department" :value="department">
                                    {{ department }}
                                </option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">ساعت‌های کاری</span>
                            <select v-model="editForm.workHours" class="h-input" required>
                                <option value="" disabled>انتخاب کنید</option>
                                <option v-for="hours in WORK_HOURS" :key="hours" :value="hours">{{ hours }}</option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">وضعیت استخدام</span>
                            <select v-model="editForm.employmentStatus" class="h-input" required>
                                <option value="official">رسمی</option>
                                <option value="unofficial">غیر رسمی</option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">وضعیت فعالیت</span>
                            <select v-model="editForm.isActive" class="h-input" required>
                                <option value="active">فعال</option>
                                <option value="inactive">غیرفعال</option>
                            </select>
                        </label>
                        <label class="h-field">
                            <span class="h-field__label">جانشین</span>
                            <select v-model="editForm.substitute" class="h-input" required>
                                <option v-for="substitute in SUBSTITUTES" :key="substitute" :value="substitute">
                                    {{ substitute }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="users__modal-actions">
                        <button type="button" class="h-btn h-btn-ghost" @click="closeEdit">انصراف</button>
                        <button type="submit" class="h-btn h-btn-primary" :disabled="editSaving">
                            {{ editSaving ? 'در حال ثبت…' : 'ذخیره تغییرات' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>

<style scoped>
.users {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.users__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
}

.users__sub {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

[data-theme='dark'] .users__sub {
    color: var(--dk-text-2);
}

.users__tabs {
    display: flex;
    gap: 0.4rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.1);
}

[data-theme='dark'] .users__tabs {
    border-bottom-color: var(--dk-line);
}

.users__tab {
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

[data-theme='dark'] .users__tab {
    color: var(--dk-text-2);
}

.users__tab.is-active {
    border-bottom-color: var(--c-primary);
    color: var(--c-primary-dark);
}

[data-theme='dark'] .users__tab.is-active {
    color: var(--dk-accent);
}

.users__toolbar {
    display: flex;
    gap: 0.6rem;
    align-items: center;
}

.users__search {
    flex: 1;
    max-width: 320px;
}

.users__search input {
    width: 100%;
}

.users__loading {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #64748b;
}

[data-theme='dark'] .users__loading {
    color: var(--dk-text-2);
}

.users__table-scroll {
    overflow-x: auto;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
}

[data-theme='dark'] .users__table-scroll {
    border-color: var(--dk-border);
}

.users__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    background: #fff;
}

[data-theme='dark'] .users__table {
    background: var(--dk-surface);
}

.users__table th,
.users__table td {
    padding: 0.6rem 0.7rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.07);
    text-align: start;
    white-space: nowrap;
}

[data-theme='dark'] .users__table th,
[data-theme='dark'] .users__table td {
    border-bottom-color: var(--dk-line);
}

.users__table th {
    color: #64748b;
    font-size: 0.75rem;
    background: rgb(15 23 42 / 0.03);
}

[data-theme='dark'] .users__table th {
    color: var(--dk-text-2);
    background: var(--dk-surface-2);
}

.users__table tbody tr:hover {
    background: rgb(14 165 233 / 0.05);
}

[data-theme='dark'] .users__table tbody tr:hover {
    background: var(--dk-surface-2);
}

.users__empty {
    padding: 1.6rem !important;
    color: #94a3b8;
    text-align: center !important;
}

[data-theme='dark'] .users__empty {
    color: var(--dk-text-3);
}

.users__role,
.users__status {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}

.users__role {
    background: rgb(139 92 246 / 0.14);
    color: #6d28d9;
}

[data-theme='dark'] .users__role {
    color: #c4b5fd;
}

.users__role.is-admin {
    background: rgb(14 165 233 / 0.14);
    color: #0369a1;
}

[data-theme='dark'] .users__role.is-admin {
    color: #7dd3fc;
}

.users__status.is-active {
    background: rgb(34 197 94 / 0.14);
    color: #15803d;
}

[data-theme='dark'] .users__status.is-active {
    color: #86efac;
}

.users__status.is-inactive {
    background: rgb(239 68 68 / 0.14);
    color: #b91c1c;
}

[data-theme='dark'] .users__status.is-inactive {
    color: #fca5a5;
}

.users__actions {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.users__action {
    padding: 0.35rem 0.7rem;
    font-size: 0.78rem;
}

.users__pagination {
    display: flex;
    gap: 0.35rem;
    align-items: center;
    justify-content: center;
}

.users__page {
    min-width: 2.2rem;
    padding: 0.4rem 0.6rem;
    border: 1px solid rgb(15 23 42 / 0.12);
    border-radius: var(--radius-token-sm);
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 0.82rem;
    cursor: pointer;
}

[data-theme='dark'] .users__page {
    border-color: var(--dk-border);
}

.users__page.is-active {
    border-color: var(--c-primary);
    background: var(--c-primary);
    color: #fff;
}

.users__page:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.users__form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.2rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-md);
    background: #fff;
}

[data-theme='dark'] .users__form {
    border-color: var(--dk-border);
    background: var(--dk-surface);
}

.users__fieldset {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    margin: 0;
    padding: 0.9rem 1rem;
    border: 1px solid rgb(15 23 42 / 0.08);
    border-radius: var(--radius-token-sm);
}

[data-theme='dark'] .users__fieldset {
    border-color: var(--dk-line);
}

.users__fieldset legend {
    padding: 0 0.5rem;
    font-size: 0.82rem;
    font-weight: 800;
}

.users__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.8rem;
}

.users__apply-all {
    display: flex;
    align-items: flex-end;
    gap: 0.6rem;
}

.users__apply-all .h-field {
    flex: 1;
}

.users__days {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 0.8rem;
}

.users__form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.users__hint {
    font-size: 0.75rem;
    color: #94a3b8;
}

[data-theme='dark'] .users__hint {
    color: var(--dk-text-3);
}

.users__modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgb(15 23 42 / 0.5);
}

.users__modal {
    width: 100%;
    max-width: 720px;
    max-height: 90dvh;
    overflow-y: auto;
    border-radius: var(--radius-token-lg);
    background: #fff;
    box-shadow: var(--dk-shadow);
}

[data-theme='dark'] .users__modal {
    background: var(--dk-surface);
}

.users__modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.2rem;
    border-bottom: 1px solid rgb(15 23 42 / 0.08);
}

[data-theme='dark'] .users__modal-head {
    border-bottom-color: var(--dk-line);
}

.users__modal-head h2 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
}

.users__modal-close {
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
}

.users__modal-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.2rem;
}

.users__modal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.9rem;
}

.users__modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
}
</style>
