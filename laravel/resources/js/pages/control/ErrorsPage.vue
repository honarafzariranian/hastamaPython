<script setup>
/**
 * System-error administration — the Vue equivalent of the legacy
 * `loadErrors()`, `maDeleteError()` and the resolve action in
 * `master-admin.js`.
 *
 *   * `GET /master-admin/api/errors` — paginated, grouped by error id, with
 *     severity / status filters;
 *   * `POST /master-admin/api/errors/{id}/resolve` — marks the group resolved
 *     (same no-op-success contract as the security resolve);
 *   * `DELETE /master-admin/api/errors/{id}` — removes the record.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';
import StatusBadge from '@/pages/control/StatusBadge.vue';
import PaginationBar from '@/pages/control/PaginationBar.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const PER_PAGE = 25;

const loading = ref(true);
const error = ref('');

const errors = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const filters = reactive({
    severity: '',
    status: '',
});

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

const severityOptions = [
    { value: 'low', label: 'کم' },
    { value: 'medium', label: 'متوسط' },
    { value: 'high', label: 'زیاد' },
    { value: 'critical', label: 'بحرانی' },
];

const statusOptions = [
    { value: 'open', label: 'باز' },
    { value: 'investigating', label: 'در حال بررسی' },
    { value: 'resolved', label: 'حل‌شده' },
];

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
}

function excerpt(value) {
    const text = String(value ?? '');
    return text.length > 80 ? `${text.slice(0, 80)}…` : text;
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

async function loadErrors() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/errors', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                severity: filters.severity,
                status: filters.status,
            },
        });

        errors.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? errors.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری خطاها';
        errors.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function applyFilters() {
    page.value = 1;
    loadErrors();
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadErrors();
}

async function resolveError(entry) {
    if (busy.value) {
        return;
    }

    busy.value = entry.error_id;

    try {
        await api.post(
            `/master-admin/api/errors/${encodeURIComponent(entry.error_id)}/resolve`,
            { status: 'resolved' },
            { baseURL: '' },
        );
        await loadErrors();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'بررسی خطا انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function deleteError(entry) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف رکورد خطای سیستم',
        msg: 'آیا از حذف دائمی این رکورد اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = entry.error_id;

    try {
        const response = await api.delete(
            `/master-admin/api/errors/${encodeURIComponent(entry.error_id)}`,
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'رکورد خطا پیدا نشد یا حذف نشد.';
            return;
        }

        await loadErrors();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف رکورد انجام نشد';
    } finally {
        busy.value = '';
    }
}

onMounted(loadErrors);
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <form class="ma-filters" @submit.prevent="applyFilters">
            <select v-model="filters.severity" class="ma-filter" aria-label="اولویت">
                <option value="">همه اولویت‌ها</option>
                <option v-for="option in severityOptions" :key="option.value" :value="option.value">
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
                    <div class="ma-empty__text">در حال بارگذاری خطاها…</div>
                </div>

                <div v-else-if="!errors.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">خطایی ثبت نشده است</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>زمان</th>
                                <th>نوع</th>
                                <th>اولویت</th>
                                <th>پیام</th>
                                <th>کاربر</th>
                                <th>تعداد</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in errors" :key="entry.error_id">
                                <td><code style="font-size:.75rem">{{ entry.error_id }}</code></td>
                                <td>{{ formatDateTime(entry.first_seen) }}</td>
                                <td><StatusBadge :value="entry.error_type" /></td>
                                <td><StatusBadge :value="entry.severity" /></td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ excerpt(entry.message) }}</td>
                                <td>{{ entry.username || '—' }}</td>
                                <td>{{ toPersianDigits(entry.occurrences ?? 0) }}</td>
                                <td><StatusBadge :value="entry.status" /></td>
                                <td>
                                    <button
                                        v-if="entry.status !== 'resolved'"
                                        type="button"
                                        class="ma-btn ma-btn--primary ma-btn--sm"
                                        :disabled="busy === entry.error_id"
                                        @click="resolveError(entry)"
                                    >
                                        بررسی شد
                                    </button>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === entry.error_id"
                                        @click="deleteError(entry)"
                                    >
                                        حذف رکورد
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PaginationBar :page="page" :pages="pages" @change="goToPage" />
    </div>
</template>
