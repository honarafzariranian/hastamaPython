<script setup>
/**
 * User administration — the Vue equivalent of the legacy `loadUsers()` and
 * `loadUserDetail()` in `master-admin.js`.
 *
 *   * `GET /master-admin/api/users` — paginated directory with search / role /
 *     status filters;
 *   * `GET /master-admin/api/users/{username}` — the 360° detail (account,
 *     sessions, recent audit trail, password-reset history);
 *   * `POST /master-admin/api/users/{username}/toggle-status` — enable/disable;
 *   * `POST /master-admin/api/users/{username}/change-role` — user ↔ admin.
 *
 * The detail opens from the «مشاهده» row action or from `?u=<username>` in the
 * query string (the control-centre search links here), and the two write
 * actions are confirmed through the shared dialog, exactly as the legacy
 * `maConfirm()` flow did.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';
import StatusBadge from '@/pages/control/StatusBadge.vue';
import PaginationBar from '@/pages/control/PaginationBar.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const router = useRouter();

const PER_PAGE = 25;

const loading = ref(true);
const error = ref('');

const users = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const filters = reactive({
    search: '',
    role: '',
    status: '',
});

/* Detail state.  `username` non-null means the 360° panel is open. */
const detailUsername = ref('');
const detailLoading = ref(false);
const detailError = ref('');
const detail = ref(null);

/* Pending confirmation: { title, msg, confirmText, cancelText, danger, action }. */
const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

const roleOptions = [
    { value: 'user', label: 'کاربر' },
    { value: 'admin', label: 'مدیر' },
];

const statusOptions = [
    { value: 'active', label: 'فعال' },
    { value: 'disabled', label: 'غیرفعال' },
];

const roleForm = reactive({
    role: 'user',
});

const isDetailOpen = computed(() => detailUsername.value !== '');

const detailTitle = computed(() => {
    if (!detail.value) {
        return '';
    }

    const fullName = `${detail.value.name || ''} ${detail.value.last_name || ''}`.trim();

    return `${detail.value.username} — ${fullName}`;
});

function displayValue(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
}

function fullName(user) {
    return `${user.name || ''} ${user.last_name || ''}`.trim() || '—';
}

function confirmAction({ title, msg, confirmText = 'تأیید', cancelText = 'انصراف', danger = true }) {
    confirmState.value = { title, msg, confirmText, cancelText, danger };

    return new Promise((resolve) => {
        confirmResolver = resolve;
    });
}

function resolveConfirm(result) {
    confirmState.value = null;

    if (confirmResolver) {
        confirmResolver(result);
        confirmResolver = null;
    }
}

async function loadUsers() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/users', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                search: filters.search,
                role: filters.role,
                status: filters.status,
            },
        });

        users.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? users.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری کاربران';
        users.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function applyFilters() {
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

async function openDetail(username) {
    if (!username) {
        return;
    }

    detailUsername.value = username;
    detail.value = null;
    detailError.value = '';
    detailLoading.value = true;

    try {
        const response = await api.get(`/master-admin/api/users/${encodeURIComponent(username)}`, { baseURL: '' });
        detail.value = response.data ?? null;
        roleForm.role = detail.value?.role === 'admin' ? 'admin' : 'user';
    } catch (failure) {
        detailError.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری اطلاعات کاربر';
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail() {
    detailUsername.value = '';
    detail.value = null;
    detailError.value = '';
}

async function toggleStatus(user) {
    if (busy.value) {
        return;
    }

    const nextStatus = (user.is_active || 'active') === 'active' ? 'غیرفعال' : 'فعال';
    const accepted = await confirmAction({
        title: 'تغییر وضعیت کاربر',
        msg: `آیا از ${nextStatus === 'غیرفعال' ? 'غیرفعال' : 'فعال'} کردن حساب «${user.username}» اطمینان دارید؟${nextStatus === 'غیرفعال' ? ' نشست‌های فعال این کاربر هم خاتمه می‌یابند.' : ''}`,
        confirmText: 'تأیید تغییر',
    });

    if (!accepted) {
        return;
    }

    busy.value = user.username;

    try {
        await api.post(`/master-admin/api/users/${encodeURIComponent(user.username)}/toggle-status`, {}, { baseURL: '' });
        await Promise.all([loadUsers(), detailUsername.value === user.username ? openDetail(user.username) : Promise.resolve()]);
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'تغییر وضعیت انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function changeRole() {
    const username = detailUsername.value;

    if (busy.value || !username) {
        return;
    }

    const accepted = await confirmAction({
        title: 'تغییر نقش کاربر',
        msg: `آیا از تغییر نقش «${username}» به «${roleForm.role === 'admin' ? 'مدیر' : 'کاربر'}» اطمینان دارید؟ نشست‌های فعال این کاربر خاتمه می‌یابند.`,
        confirmText: 'تغییر نقش',
    });

    if (!accepted) {
        return;
    }

    busy.value = username;

    try {
        await api.post(
            `/master-admin/api/users/${encodeURIComponent(username)}/change-role`,
            { role: roleForm.role },
            { baseURL: '' },
        );
        await openDetail(username);
    } catch (failure) {
        detailError.value = failure?.apiFailure?.message || failure?.message || 'تغییر نقش انجام نشد';
    } finally {
        busy.value = '';
    }
}

function backToList() {
    closeDetail();
    router.replace({ query: { page: 'users' } }).catch(() => {});
}

function openRequestedUser() {
    const requested = router.currentRoute.value.query.u;

    if (typeof requested === 'string' && requested.trim() !== '') {
        openDetail(requested.trim());
    }
}

/* The control-centre search links straight to `?page=users&u=<username>`; the
   layout is already mounted when that happens, so the query change has to be
   watched rather than read once. */
watch(() => router.currentRoute.value.query.u, openRequestedUser);

onMounted(() => {
    openRequestedUser();
    loadUsers();
});
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <div v-if="isDetailOpen">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
                <a href="#" class="ma-btn ma-btn--ghost" style="font-size:0.82rem" @click.prevent="backToList">← بازگشت به کاربران</a>

                <template v-if="detail">
                    <h2 style="margin:0;font-size:1.1rem;font-weight:800;color:#0f172a">{{ detailTitle }}</h2>
                    <StatusBadge :value="detail.role" />
                    <StatusBadge :value="detail.is_active || 'active'" />
                </template>
            </div>

            <div v-if="detailLoading" class="ma-empty">
                <div class="ma-empty__text">در حال بارگذاری اطلاعات کاربر…</div>
            </div>

            <p v-else-if="detailError" class="h-alert" role="alert">{{ detailError }}</p>

            <template v-else-if="detail">
                <div class="ma-grid-2" style="margin-bottom:20px">
                    <div class="ma-panel-card">
                        <div class="ma-panel-card__header">
                            <div class="ma-panel-card__title">📋 اطلاعات حساب</div>
                        </div>
                        <div class="ma-panel-card__body">
                            <table style="width:100%;font-size:0.82rem;border-collapse:collapse">
                                <tr><td style="padding:6px 0;color:#64748b;width:140px">نام کاربری</td><td style="padding:6px 0;font-weight:600">{{ detail.username }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">نام</td><td style="padding:6px 0">{{ displayValue(detail.name) }} {{ displayValue(detail.last_name) }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">بخش</td><td style="padding:6px 0">{{ displayValue(detail.department) }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">ساعت کاری</td><td style="padding:6px 0">{{ displayValue(detail.work_hours) }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">جانشین</td><td style="padding:6px 0">{{ displayValue(detail.substitute) }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">آخرین ورود</td><td style="padding:6px 0">{{ formatDateTime(detail.last_login) }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">تلاش ناموفق ورود</td><td style="padding:6px 0">{{ toPersianDigits(detail.failed_login_count || 0) }}</td></tr>
                                <tr><td style="padding:6px 0;color:#64748b">آخرین تغییر رمز</td><td style="padding:6px 0">{{ formatDateTime(detail.password_changed_at) }}</td></tr>
                            </table>

                            <div style="display:flex;align-items:center;gap:12px;margin-top:16px;flex-wrap:wrap">
                                <button
                                    type="button"
                                    class="ma-btn"
                                    :class="(detail.is_active || 'active') === 'active' ? 'ma-btn--danger' : 'ma-btn--primary'"
                                    :disabled="busy === detail.username"
                                    @click="toggleStatus(detail)"
                                >
                                    {{ (detail.is_active || 'active') === 'active' ? 'غیرفعال کردن حساب' : 'فعال کردن حساب' }}
                                </button>

                                <div style="display:flex;align-items:center;gap:8px">
                                    <label style="font-size:0.82rem;font-weight:600" for="ma-user-role">نقش</label>
                                    <select id="ma-user-role" v-model="roleForm.role" class="ma-filter">
                                        <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--primary"
                                        :disabled="busy === detail.username || detail.role === roleForm.role"
                                        @click="changeRole"
                                    >
                                        تغییر نقش
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ma-panel-card">
                        <div class="ma-panel-card__header">
                            <div class="ma-panel-card__title">🔐 نشست‌ها</div>
                        </div>
                        <div class="ma-panel-card__body">
                            <div v-if="!detail.sessions || !detail.sessions.length" class="ma-empty" style="padding:20px">
                                <div class="ma-empty__text">نشستی ثبت نشده</div>
                            </div>

                            <div v-else>
                                <div v-for="session in detail.sessions" :key="session.id" style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:0.82rem">
                                    <div style="display:flex;justify-content:space-between;align-items:center">
                                        <span>{{ session.ip_address || '—' }}</span>
                                        <StatusBadge :value="session.is_active ? 'active' : 'disabled'" />
                                    </div>
                                    <div style="color:#64748b;font-size:0.75rem;margin-top:2px">{{ formatDateTime(session.login_at) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ma-panel-card" style="margin-bottom:20px">
                    <div class="ma-panel-card__header">
                        <div class="ma-panel-card__title">📋 آخرین رویدادها</div>
                    </div>
                    <div class="ma-panel-card__body--flush">
                        <div class="ma-table__scroll">
                            <table class="ma-table">
                                <thead>
                                    <tr>
                                        <th>زمان</th>
                                        <th>عملیات</th>
                                        <th>ماژول</th>
                                        <th>وضعیت</th>
                                        <th>اولویت</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!detail.recent_audit || !detail.recent_audit.length">
                                        <td colspan="6" style="text-align:center;padding:20px;color:#94a3b8">رویدادی ثبت نشده</td>
                                    </tr>
                                    <tr v-for="event in (detail.recent_audit || [])" :key="event.event_id">
                                        <td>{{ formatDateTime(event.created_at) }}</td>
                                        <td>{{ event.action }}</td>
                                        <td>{{ event.module || '—' }}</td>
                                        <td><StatusBadge :value="event.status" /></td>
                                        <td><StatusBadge :value="event.severity" /></td>
                                        <td>{{ event.ip_address || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div v-if="detail.password_resets && detail.password_resets.length" class="ma-panel-card">
                    <div class="ma-panel-card__header">
                        <div class="ma-panel-card__title">🔑 تاریخچه بازیابی رمز</div>
                    </div>
                    <div class="ma-panel-card__body--flush">
                        <div class="ma-table__scroll">
                            <table class="ma-table">
                                <thead>
                                    <tr>
                                        <th>شناسه</th>
                                        <th>زمان</th>
                                        <th>وضعیت</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="reset in detail.password_resets" :key="reset.request_id">
                                        <td><code style="font-size:.75rem">{{ reset.request_id }}</code></td>
                                        <td>{{ formatDateTime(reset.created_at) }}</td>
                                        <td><StatusBadge :value="reset.status" /></td>
                                        <td>{{ reset.ip_address || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <template v-else>
            <form class="ma-filters" @submit.prevent="applyFilters">
                <input
                    v-model="filters.search"
                    type="text"
                    class="ma-filter"
                    placeholder="جستجو..."
                    aria-label="جستجو"
                >
                <select v-model="filters.role" class="ma-filter" aria-label="نقش">
                    <option value="">همه نقش‌ها</option>
                    <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <select v-model="filters.status" class="ma-filter" aria-label="وضعیت">
                    <option value="">همه وضعیت‌ها</option>
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <button type="submit" class="ma-btn ma-btn--primary">اعمال فیلتر</button>
            </form>

            <div class="ma-panel-card">
                <div class="ma-panel-card__body--flush">
                    <div v-if="loading" class="ma-empty">
                        <div class="ma-empty__text">در حال بارگذاری کاربران…</div>
                    </div>

                    <div v-else-if="!users.length" class="ma-empty">
                        <div class="ma-empty__icon">📭</div>
                        <div class="ma-empty__text">کاربری یافت نشد</div>
                    </div>

                    <div v-else class="ma-table__scroll">
                        <table class="ma-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>نام کاربری</th>
                                    <th>نام</th>
                                    <th>بخش</th>
                                    <th>نقش</th>
                                    <th>وضعیت</th>
                                    <th>آخرین ورود</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in users" :key="user.id">
                                    <td>{{ toPersianDigits(user.id) }}</td>
                                    <td>{{ user.username }}</td>
                                    <td>{{ fullName(user) }}</td>
                                    <td>{{ user.department || '—' }}</td>
                                    <td><StatusBadge :value="user.role" /></td>
                                    <td><StatusBadge :value="user.is_active || 'active'" /></td>
                                    <td>{{ formatDateTime(user.last_login) }}</td>
                                    <td>
                                        <button
                                            type="button"
                                            class="ma-btn ma-btn--ghost ma-btn--sm"
                                            @click="openDetail(user.username)"
                                        >
                                            مشاهده
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <PaginationBar :page="page" :pages="pages" @change="goToPage" />
        </template>
    </div>
</template>
