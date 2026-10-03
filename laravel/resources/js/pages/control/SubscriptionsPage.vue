<script setup>
/**
 * Customer subscriptions — the Vue equivalent of the legacy
 * `loadSubscriptions()` and `ma_viewSubscription()` in `master-admin.js`.
 *
 *   * `GET /master-admin/api/subscriptions/summary` — the four headline
 *     counters (answers `setup_needed: true` when the optional subscriptions
 *     migration has not run);
 *   * `GET /master-admin/api/subscriptions` — paginated list with search / status
 *     filters (the backend filters and paginates in PHP, exactly as the Python
 *     did);
 *   * `GET /master-admin/api/subscriptions/{id}` — the customer profile with
 *     its linked users;
 *   * `PATCH /master-admin/api/subscriptions/{id}` — the edit form.
 *
 * The list row opens the detail modal (the legacy row click), and the edit form
 * submits the same field set the legacy modal sent.  Dates use native
 * `type="date"` inputs — the backend validates `YYYY-MM-DD`, which is exactly
 * what the control produces, replacing the legacy hand-rolled Jalali picker.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';
import StatusBadge from '@/pages/control/StatusBadge.vue';
import PaginationBar from '@/pages/control/PaginationBar.vue';

const PER_PAGE = 25;

const loading = ref(true);
const error = ref('');

const summary = ref(null);
const setupNeeded = ref(false);

const subscriptions = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const filters = reactive({
    search: '',
    status: '',
});

/* Detail modal state. */
const detailOpen = ref(false);
const detailLoading = ref(false);
const detailError = ref('');
const detail = ref(null);
const detailSaving = ref(false);
const detailNotice = ref('');

const statusOptions = [
    { value: 'active', label: 'فعال' },
    { value: 'expiring', label: 'در آستانه پایان' },
    { value: 'expired', label: 'منقضی' },
    { value: 'suspended', label: 'متوقف' },
];

const editForm = reactive({
    customer_id: '',
    customer_code: '',
    customer_name: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    plan_name: '',
    subscription_status: 'active',
    starts_at: '',
    expires_at: '',
    max_users: 0,
    payment_method: 'cash',
    invoice_number: '',
    notes: '',
});

const paymentMethods = [
    { value: 'cash', label: 'نقدی' },
    { value: 'check', label: 'چکی' },
    { value: 'installment', label: 'اقساطی' },
];

const subscriptionStatuses = [
    { value: 'active', label: 'فعال' },
    { value: 'suspended', label: 'متوقف' },
    { value: 'expired', label: 'منقضی' },
];

const summaryCards = computed(() => {
    if (!summary.value) {
        return [];
    }

    return [
        { label: 'کل مشتریان', value: summary.value.total_customers, meta: 'ثبت‌شده در سامانه' },
        { label: 'اشتراک‌های فعال', value: summary.value.active_subscriptions, meta: 'در حال استفاده' },
        { label: 'در آستانه تمدید', value: summary.value.expiring_soon, meta: 'کمتر از ۳۰ روز' },
        {
            label: 'ظرفیت کاربران',
            value: `${toPersianDigits(summary.value.total_seats_used ?? 0)} / ${toPersianDigits(summary.value.total_seats ?? 0)}`,
            meta: 'کاربر فعال / ظرفیت',
        },
    ];
});

function formatDate(value) {
    if (!value || !String(value).trim()) {
        return '—';
    }

    const raw = String(value).trim();
    const parsed = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleDateString('fa-IR');
}

function remainingText(entry) {
    const days = Number(entry.remaining_days);

    if (entry.remaining_days === null || entry.remaining_days === undefined || Number.isNaN(days)) {
        return '—';
    }

    if (days < 0) {
        return 'منقضی شده';
    }

    return `${toPersianDigits(days)} روز`;
}

function remainingClass(entry) {
    const days = Number(entry.remaining_days);

    if (entry.remaining_days === null || entry.remaining_days === undefined || Number.isNaN(days)) {
        return '';
    }

    if (days < 0) {
        return 'ma-subscription-days--expired';
    }

    if (days <= 30) {
        return 'ma-subscription-days--urgent';
    }

    return '';
}

function seatsPercent(entry) {
    const used = Number(entry.seats_used) || 0;
    const capacity = Number(entry.max_users) || 0;

    return capacity > 0 ? Math.min(100, Math.round((used * 100) / capacity)) : 0;
}

async function loadSummary() {
    const response = await api.get('/master-admin/api/subscriptions/summary', { baseURL: '' });
    setupNeeded.value = response.setup_needed === true;
    summary.value = response.data ?? null;
}

async function loadSubscriptions() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/subscriptions', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                search: filters.search,
                status: filters.status,
            },
        });

        subscriptions.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? subscriptions.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری اشتراک‌ها';
        subscriptions.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function applyFilters() {
    page.value = 1;
    loadSubscriptions();
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadSubscriptions();
}

function openDetail(entry) {
    detailOpen.value = true;
    detail.value = entry;
    detailError.value = '';
    detailNotice.value = '';

    editForm.customer_id = entry.customer_id ?? '';
    editForm.customer_code = entry.customer_code ?? '';
    editForm.customer_name = entry.customer_name ?? '';
    editForm.contact_name = entry.contact_name ?? '';
    editForm.contact_email = entry.contact_email ?? '';
    editForm.contact_phone = entry.contact_phone ?? '';
    editForm.plan_name = entry.plan_name ?? '';
    editForm.subscription_status = entry.subscription_status ?? 'active';
    editForm.starts_at = entry.starts_at ? String(entry.starts_at).slice(0, 10) : '';
    editForm.expires_at = entry.expires_at ? String(entry.expires_at).slice(0, 10) : '';
    editForm.max_users = Number(entry.max_users) || 0;
    editForm.payment_method = entry.payment_method ?? 'cash';
    editForm.invoice_number = entry.invoice_number ?? '';
    editForm.notes = entry.notes ?? '';
}

function closeDetail() {
    detailOpen.value = false;
    detail.value = null;
    detailError.value = '';
    detailNotice.value = '';
}

async function saveDetail() {
    if (detailSaving.value || !detail.value) {
        return;
    }

    detailSaving.value = true;
    detailError.value = '';
    detailNotice.value = '';

    try {
        const payload = {
            ...editForm,
            max_users: editForm.max_users === '' ? 0 : Number(editForm.max_users),
        };

        const response = await api.patch(
            `/master-admin/api/subscriptions/${encodeURIComponent(detail.value.id)}`,
            payload,
            { baseURL: '' },
        );

        detailNotice.value = response.data?.message || 'اطلاعات مشتری ذخیره شد.';
        await Promise.all([loadSummary(), loadSubscriptions()]);
        closeDetail();
    } catch (failure) {
        detailError.value = failure?.apiFailure?.message || failure?.message || 'ذخیره اطلاعات مشتری ناموفق بود.';
    } finally {
        detailSaving.value = false;
    }
}

onMounted(async () => {
    try {
        await Promise.all([loadSummary(), loadSubscriptions()]);
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در دریافت اطلاعات اشتراک‌ها.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="ma-subscriptions-page" aria-labelledby="maSubscriptionsTitle">
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <div v-if="setupNeeded" class="h-alert" role="alert">
            اطلاعات اشتراک‌ها در دسترس نیست؛ ابتدا مهاجرت پایگاه‌داده را اجرا کنید.
        </div>

        <template v-else>
            <div class="ma-section-header">
                <div>
                    <span class="ma-subscriptions-eyebrow">CUSTOMER SUCCESS</span>
                    <h1 class="ma-section-title" id="maSubscriptionsTitle" style="font-size:1.1rem;font-weight:800;color:#0f172a">اشتراک مشتریان</h1>
                    <p class="ma-subscriptions-intro">وضعیت قرارداد، ظرفیت کاربران و فرصت‌های تمدید را یکجا پیگیری کنید.</p>
                </div>
                <button type="button" class="ma-btn ma-btn--ghost" @click="loadSubscriptions">به‌روزرسانی</button>
            </div>

            <div class="ma-subscription-stats">
                <div v-for="card in summaryCards" :key="card.label" class="ma-subscription-stat">
                    <span class="ma-subscription-stat__label">{{ card.label }}</span>
                    <strong class="ma-subscription-stat__value">{{ card.value ?? '—' }}</strong>
                    <span class="ma-subscription-stat__meta">{{ card.meta }}</span>
                </div>
            </div>

            <form class="ma-filters ma-subscription-filters" @submit.prevent="applyFilters">
                <input
                    v-model="filters.search"
                    type="text"
                    class="ma-filter"
                    placeholder="جستجوی مشتری، شناسه یا طرح..."
                    aria-label="جستجو"
                >
                <select v-model="filters.status" class="ma-filter" aria-label="وضعیت">
                    <option value="">همه وضعیت‌ها</option>
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <button type="submit" class="ma-btn ma-btn--primary">اعمال فیلتر</button>
            </form>

            <div class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">فهرست اشتراک‌ها</div>
                    <span class="ma-subscription-table-note">تاریخ‌ها بر اساس تقویم محلی نمایش داده می‌شوند</span>
                </div>
                <div class="ma-panel-card__body--flush">
                    <div v-if="loading" class="ma-empty">
                        <div class="ma-empty__text">در حال بارگذاری اشتراک‌ها…</div>
                    </div>

                    <div v-else-if="!subscriptions.length" class="ma-empty">
                        <div class="ma-empty__icon">📭</div>
                        <div class="ma-empty__text">اشتراک ثبت‌شده‌ای یافت نشد</div>
                    </div>

                    <div v-else class="ma-table__scroll">
                        <table class="ma-table">
                            <thead>
                                <tr>
                                    <th>مشتری</th>
                                    <th>طرح</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ شروع</th>
                                    <th>تاریخ پایان</th>
                                    <th>زمان باقی‌مانده</th>
                                    <th>کاربران</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="entry in subscriptions"
                                    :key="entry.id"
                                    class="ma-subscription-row"
                                    @click="openDetail(entry)"
                                >
                                    <td>
                                        <div class="ma-subscription-customer">
                                            <strong>{{ entry.customer_name || 'بدون نام' }}</strong>
                                            <small>{{ entry.customer_code || entry.contact || '—' }}</small>
                                        </div>
                                    </td>
                                    <td>{{ entry.plan_name || '—' }}</td>
                                    <td><StatusBadge :value="entry.status || entry.computed_status || 'unknown'" /></td>
                                    <td>{{ formatDate(entry.starts_at) }}</td>
                                    <td>{{ formatDate(entry.expires_at) }}</td>
                                    <td>
                                        <span class="ma-subscription-days" :class="remainingClass(entry)">
                                            {{ remainingText(entry) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="ma-subscription-progress">
                                            <div class="ma-subscription-progress__track">
                                                <div class="ma-subscription-progress__fill" :style="{ width: `${seatsPercent(entry)}%` }"></div>
                                            </div>
                                            <div class="ma-subscription-progress__label">
                                                <span>{{ toPersianDigits(entry.seats_used ?? 0) }} فعال</span>
                                                <span>{{ toPersianDigits(entry.max_users ?? 0) }} ظرفیت</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <PaginationBar :page="page" :pages="pages" @change="goToPage" />

            <div v-if="detailOpen" class="ma-detail-overlay" role="dialog" aria-modal="true" aria-labelledby="ma-subs-detail-title">
                <section class="ma-detail-modal">
                    <button type="button" class="ma-detail-modal__close" aria-label="بستن" @click="closeDetail">×</button>
                    <div class="ma-detail-modal__body">
                        <div v-if="detailLoading" class="ma-empty">
                            <div class="ma-empty__text">در حال بارگذاری اطلاعات مشتری…</div>
                        </div>

                        <template v-else-if="detail">
                            <div class="ma-detail-modal__eyebrow">CUSTOMER PROFILE</div>
                            <h2 id="ma-subs-detail-title">ویرایش اطلاعات مشتری</h2>

                            <p v-if="detailError" class="h-alert" role="alert">{{ detailError }}</p>
                            <p v-if="detailNotice" class="h-alert h-alert--ok" role="status">{{ detailNotice }}</p>

                            <form class="ma-detail-form" @submit.prevent="saveDetail">
                                <label>شناسه مشتری<input v-model="editForm.customer_id" type="text" required></label>
                                <label>کد مشتری<input v-model="editForm.customer_code" type="text"></label>
                                <label>نام مشتری<input v-model="editForm.customer_name" type="text" required></label>
                                <label>نام رابط<input v-model="editForm.contact_name" type="text"></label>
                                <label>ایمیل<input v-model="editForm.contact_email" type="email" dir="ltr"></label>
                                <label>تلفن<input v-model="editForm.contact_phone" type="text" dir="ltr"></label>
                                <label>طرح<input v-model="editForm.plan_name" type="text" required></label>
                                <label>وضعیت
                                    <select v-model="editForm.subscription_status">
                                        <option v-for="option in subscriptionStatuses" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </label>
                                <label>تاریخ شروع<input v-model="editForm.starts_at" type="date" required></label>
                                <label>تاریخ پایان<input v-model="editForm.expires_at" type="date" required></label>
                                <label>ظرفیت کاربران<input v-model="editForm.max_users" type="number" min="0"></label>
                                <label>روش پرداخت
                                    <select v-model="editForm.payment_method">
                                        <option v-for="option in paymentMethods" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </label>
                                <label>شماره فاکتور<input v-model="editForm.invoice_number" type="text"></label>
                                <label class="ma-detail-form__wide">یادداشت<textarea v-model="editForm.notes" rows="3"></textarea></label>

                                <div class="ma-detail-form__actions">
                                    <button type="submit" class="ma-btn ma-btn--primary" :disabled="detailSaving">
                                        {{ detailSaving ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
                                    </button>
                                    <span class="ma-detail-users-count">
                                        کاربران زیرمجموعه: {{ toPersianDigits((detail.users || []).length) }}
                                    </span>
                                </div>
                            </form>

                            <div class="ma-detail-users">
                                <h3>کاربران زیرمجموعه</h3>
                                <p v-if="!detail.users || !detail.users.length" class="ma-empty__text">
                                    کاربر ثبت‌شده‌ای برای این مشتری پیدا نشد.
                                </p>
                                <ul v-else>
                                    <li v-for="user in (detail.users || [])" :key="user.id">
                                        <strong>{{ `${user.name || ''} ${user.last_name || ''}`.trim() || user.username }}</strong>
                                        <span>{{ user.department || user.role || '—' }}</span>
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </div>
                </section>
            </div>
        </template>
    </section>
</template>
