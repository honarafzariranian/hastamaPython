<script setup>
/**
 * Security-event administration — the Vue equivalent of the legacy
 * `loadSecurity()`, `maResolveSecurity()` and `maDeleteSecurity()` in
 * `master-admin.js`.
 *
 *   * `GET /master-admin/api/security` — paginated feed with severity / status
 *     filters;
 *   * `POST /master-admin/api/security/{id}/resolve` — marks the event
 *     resolved (the backend answers `{"success": true}` even for an unknown
 *     id, exactly as the Python did);
 *   * `DELETE /master-admin/api/security/{id}` — removes the record.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import StatusBadge from '@/pages/control/StatusBadge.vue';
import PaginationBar from '@/pages/control/PaginationBar.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const PER_PAGE = 25;

const loading = ref(true);
const error = ref('');

const events = ref([]);
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

async function loadEvents() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/security', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                severity: filters.severity,
                status: filters.status,
            },
        });

        events.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? events.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری رویدادها';
        events.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function applyFilters() {
    page.value = 1;
    loadEvents();
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadEvents();
}

async function resolveEvent(event) {
    if (busy.value) {
        return;
    }

    busy.value = event.event_id;

    try {
        await api.post(
            `/master-admin/api/security/${encodeURIComponent(event.event_id)}/resolve`,
            { status: 'resolved' },
            { baseURL: '' },
        );
        await loadEvents();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'بررسی رویداد انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function deleteEvent(event) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف رکورد رویداد امنیتی',
        msg: 'آیا از حذف دائمی این رکورد اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = event.event_id;

    try {
        const response = await api.delete(
            `/master-admin/api/security/${encodeURIComponent(event.event_id)}`,
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'رکورد رویداد امنیتی پیدا نشد یا حذف نشد.';
            return;
        }

        await loadEvents();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف رکورد انجام نشد';
    } finally {
        busy.value = '';
    }
}

onMounted(loadEvents);
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
                    <div class="ma-empty__text">در حال بارگذاری رویدادها…</div>
                </div>

                <div v-else-if="!events.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">رویداد امنیتی موجود نیست</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>زمان</th>
                                <th>نوع</th>
                                <th>اولویت</th>
                                <th>کاربر</th>
                                <th>توضیحات</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="event in events" :key="event.event_id">
                                <td><code style="font-size:.75rem">{{ event.event_id }}</code></td>
                                <td>{{ formatDateTime(event.created_at) }}</td>
                                <td>{{ event.event_type }}</td>
                                <td><StatusBadge :value="event.severity" /></td>
                                <td>{{ event.username || '—' }}</td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ excerpt(event.description) }}</td>
                                <td><StatusBadge :value="event.status" /></td>
                                <td>
                                    <button
                                        v-if="event.status === 'open'"
                                        type="button"
                                        class="ma-btn ma-btn--primary ma-btn--sm"
                                        :disabled="busy === event.event_id"
                                        @click="resolveEvent(event)"
                                    >
                                        بررسی شد
                                    </button>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === event.event_id"
                                        @click="deleteEvent(event)"
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
