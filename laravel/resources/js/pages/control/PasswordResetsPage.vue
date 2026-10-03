<script setup>
/**
 * Password-reset administration — the Vue equivalent of the legacy
 * `loadPasswordResets()`, `maApproveReset()`, `maRejectReset()` and
 * `maDeleteReset()` in `master-admin.js`.
 *
 *   * `GET /master-admin/api/password-resets` — paginated list with a status
 *     filter;
 *   * `POST /master-admin/api/password-resets/{id}/approve` — issues the
 *     one-time code, which the backend returns ONCE in the response (only its
 *     HMAC digest is stored), so the code is shown to the operator and never
 *     re-readable;
 *   * `POST /master-admin/api/password-resets/{id}/reject` — marks the request
 *     rejected;
 *   * `DELETE /master-admin/api/password-resets/{id}` — removes the row
 *     including the stored digest.
 *
 * Approval needs no confirmation (the legacy flow had none — the code is the
 * point of the action); reject and delete are confirmed.
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
const notice = ref('');

const resets = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const filters = reactive({
    status: '',
});

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

const statusOptions = [
    { value: 'pending', label: 'انتظار' },
    { value: 'approved', label: 'تأیید شده' },
    { value: 'rejected', label: 'رد شده' },
    { value: 'completed', label: 'تکمیل شده' },
];

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
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

async function loadResets() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/password-resets', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                status: filters.status,
            },
        });

        resets.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? resets.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری درخواست‌ها';
        resets.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function applyFilters() {
    page.value = 1;
    loadResets();
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadResets();
}

async function approveReset(reset) {
    if (busy.value) {
        return;
    }

    busy.value = reset.request_id;
    notice.value = '';

    try {
        const response = await api.post(
            `/master-admin/api/password-resets/${encodeURIComponent(reset.request_id)}/approve`,
            {},
            { baseURL: '' },
        );
        const data = response.data ?? {};

        if (data.success === false) {
            error.value = data.message || 'تأیید درخواست انجام نشد.';
            return;
        }

        notice.value = `کد بازیابی: ${data.code ?? '—'}`;
        await loadResets();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'تأیید درخواست انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function rejectReset(reset) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'رد درخواست بازیابی',
        msg: 'آیا از رد این درخواست اطمینان دارید؟',
        confirmText: 'رد شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = reset.request_id;

    try {
        const response = await api.post(
            `/master-admin/api/password-resets/${encodeURIComponent(reset.request_id)}/reject`,
            {},
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'درخواست پیدا نشد یا قبلاً پردازش شده است.';
            return;
        }

        notice.value = '';
        await loadResets();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'رد درخواست انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function deleteReset(reset) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف رکورد درخواست بازیابی',
        msg: 'آیا از حذف دائمی این رکورد اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = reset.request_id;

    try {
        const response = await api.delete(
            `/master-admin/api/password-resets/${encodeURIComponent(reset.request_id)}`,
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'رکورد درخواست بازیابی پیدا نشد یا حذف نشد.';
            return;
        }

        notice.value = '';
        await loadResets();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف رکورد انجام نشد';
    } finally {
        busy.value = '';
    }
}

onMounted(loadResets);
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>
        <p v-if="notice" class="h-alert h-alert--ok" role="status">{{ notice }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <form class="ma-filters" @submit.prevent="applyFilters">
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
                    <div class="ma-empty__text">در حال بارگذاری درخواست‌ها…</div>
                </div>

                <div v-else-if="!resets.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">درخواست بازیابی موجود نیست</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>کاربر</th>
                                <th>زمان</th>
                                <th>IP</th>
                                <th>وضعیت</th>
                                <th>تلاش‌ها</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="reset in resets" :key="reset.request_id">
                                <td><code style="font-size:.75rem">{{ reset.request_id }}</code></td>
                                <td>{{ reset.username }}</td>
                                <td>{{ formatDateTime(reset.created_at) }}</td>
                                <td>{{ reset.ip_address || '—' }}</td>
                                <td><StatusBadge :value="reset.status" /></td>
                                <td>{{ toPersianDigits(reset.code_attempts ?? 0) }}</td>
                                <td>
                                    <template v-if="reset.status === 'pending'">
                                        <button
                                            type="button"
                                            class="ma-btn ma-btn--primary ma-btn--sm"
                                            :disabled="busy === reset.request_id"
                                            @click="approveReset(reset)"
                                        >
                                            تأیید
                                        </button>
                                        <button
                                            type="button"
                                            class="ma-btn ma-btn--danger ma-btn--sm"
                                            :disabled="busy === reset.request_id"
                                            @click="rejectReset(reset)"
                                        >
                                            رد
                                        </button>
                                    </template>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === reset.request_id"
                                        @click="deleteReset(reset)"
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
