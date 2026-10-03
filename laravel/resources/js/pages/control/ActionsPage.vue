<script setup>
/**
 * Admin-action feed — the Vue equivalent of the legacy `loadAdminActions()`
 * and `maDeleteAdminAction()` in `master-admin.js`.
 *
 *   * `GET /master-admin/api/admin-actions` — the operator activity feed;
 *   * `DELETE /master-admin/api/admin-actions/{id}` — remove a record.  The
 *     feed records its own deletions (the backend writes a `delete_admin_action`
 *     row), so removing an entry keeps the fact that something was removed.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';
import PaginationBar from '@/pages/control/PaginationBar.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const PER_PAGE = 25;

const loading = ref(true);
const error = ref('');

const actions = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

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

async function loadActions() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/admin-actions', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
            },
        });

        actions.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? actions.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری عملیات';
        actions.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadActions();
}

async function deleteAction(entry) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف رکورد عملیات مدیریتی',
        msg: 'آیا از حذف دائمی این رکورد اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = entry.action_id;

    try {
        const response = await api.delete(
            `/master-admin/api/admin-actions/${encodeURIComponent(entry.action_id)}`,
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'رکورد عملیات مدیریتی پیدا نشد یا حذف نشد.';
            return;
        }

        await loadActions();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف رکورد انجام نشد';
    } finally {
        busy.value = '';
    }
}

onMounted(loadActions);
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <div class="ma-panel-card">
            <div class="ma-panel-card__body--flush">
                <div v-if="loading" class="ma-empty">
                    <div class="ma-empty__text">در حال بارگذاری عملیات…</div>
                </div>

                <div v-else-if="!actions.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">عملیات مدیریتی ثبت نشده است</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>زمان</th>
                                <th>مدیر</th>
                                <th>عملیات</th>
                                <th>هدف</th>
                                <th>توضیحات</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in actions" :key="entry.action_id">
                                <td><code style="font-size:.75rem">{{ entry.action_id }}</code></td>
                                <td>{{ formatDateTime(entry.created_at) }}</td>
                                <td>{{ entry.admin_username }}</td>
                                <td>{{ entry.action }}</td>
                                <td>{{ entry.target_username || '—' }}</td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ excerpt(entry.description) }}</td>
                                <td>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === entry.action_id"
                                        @click="deleteAction(entry)"
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
