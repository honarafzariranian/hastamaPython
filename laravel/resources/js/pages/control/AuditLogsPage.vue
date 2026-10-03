<script setup>
/**
 * Audit trail — the Vue equivalent of the `audit-logs` branch of
 * `app/templates/master-admin.html` plus `loadAuditLogs()` and
 * `window.maDeleteAuditLog()` in `master-admin.js`.
 *
 *   * `GET /master-admin/api/audit-logs` — the paginated, filtered trail;
 *   * `DELETE /master-admin/api/audit-logs/{event_id}` — drop one record.
 *
 * The legacy table is rendered by the shared `renderTable()` helper, so the
 * column set, the order and the cell renderers below are the ones
 * `loadAuditLogs()` passed to it.  `renderTable()` substituted `'—'` for a
 * missing value before rendering, which is what `cell()` reproduces.
 *
 * The legacy page shared one `state.filters` object between sections and
 * `maApplyFilters()` was the only thing that wrote to it, so the filters were
 * inert until «اعمال فیلتر» was pressed.  Submitting the form is that button.
 */
import { computed, onMounted, reactive, ref } from 'vue';
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
    search: '',
    eventType: '',
    severity: '',
});

const eventTypeOptions = [
    { value: 'AUTHENTICATION', label: 'احراز هویت' },
    { value: 'USER', label: 'کاربر' },
    { value: 'SECURITY', label: 'امنیت' },
    { value: 'ADMINISTRATION', label: 'مدیریتی' },
    { value: 'DATA', label: 'داده' },
    { value: 'SYSTEM', label: 'سیستم' },
];

const severityOptions = [
    { value: 'info', label: 'اطلاعات' },
    { value: 'low', label: 'کم' },
    { value: 'medium', label: 'متوسط' },
    { value: 'high', label: 'زیاد' },
    { value: 'critical', label: 'بحرانی' },
];

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

const columns = computed(() => [
    'شناسه',
    'زمان',
    'نوع',
    'عملیات',
    'کاربر',
    'ماژول',
    'اولویت',
    'وضعیت',
    'IP',
    'حذف',
]);

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

/**
 * `renderTable()`'s per-cell contract: a `null`/`undefined` value became `'—'`
 * before any renderer ran, and a renderer received that substituted value.
 */
function raw(event, key) {
    return event[key] ?? '—';
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const rawValue = String(value);
    const date = new Date(rawValue.includes(' ') && !rawValue.includes('T') ? rawValue.replace(' ', 'T') : rawValue);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
}

async function loadEvents() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/audit-logs', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                search: filters.search,
                event_type: filters.eventType,
                severity: filters.severity,
            },
        });

        events.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? events.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری لاگ‌ها';
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

async function deleteEvent(event) {
    if (busy.value) {
        return;
    }

    const eventId = event.event_id;
    const accepted = await confirmAction({
        title: 'حذف رکورد لاگ حسابرسی',
        msg: 'آیا از حذف دائمی این رکورد اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = eventId;

    try {
        const response = await api.delete(
            `/master-admin/api/audit-logs/${encodeURIComponent(eventId)}`,
            { baseURL: '' },
        );

        /* The handler answers `{"success": false}` when the row was already
         * gone, and the legacy handler toasted «پیدا نشد یا حذف نشد». */
        if (response?.success === false) {
            error.value = 'رکورد لاگ پیدا نشد یا حذف نشد';
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
            <input
                id="filterSearch"
                v-model="filters.search"
                type="text"
                class="ma-filter"
                placeholder="جستجو..."
                aria-label="جستجو"
            >
            <select id="filterEventType" v-model="filters.eventType" class="ma-filter" aria-label="نوع رویداد">
                <option value="">همه انواع</option>
                <option v-for="option in eventTypeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <select id="filterSeverity" v-model="filters.severity" class="ma-filter" aria-label="اولویت">
                <option value="">همه اولویت‌ها</option>
                <option v-for="option in severityOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <button type="submit" class="ma-btn ma-btn--primary">اعمال فیلتر</button>
        </form>

        <div class="ma-panel-card">
            <div class="ma-panel-card__body--flush">
                <div v-if="loading" class="ma-empty">
                    <div class="ma-empty__text">در حال بارگذاری لاگ‌ها…</div>
                </div>

                <div v-else-if="!events.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">لاگ حسابرسی موجود نیست</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th v-for="column in columns" :key="column">{{ column }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="event in events" :key="event.event_id">
                                <td><code style="font-size:.75rem">{{ raw(event, 'event_id') }}</code></td>
                                <td>{{ formatDateTime(event.created_at) }}</td>
                                <td><StatusBadge :value="event.event_type" /></td>
                                <td>{{ raw(event, 'action') }}</td>
                                <td>{{ raw(event, 'username') }}</td>
                                <td>{{ raw(event, 'module') }}</td>
                                <td><StatusBadge :value="event.severity" /></td>
                                <td><StatusBadge :value="event.status" /></td>
                                <td>{{ raw(event, 'ip_address') }}</td>
                                <td>
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
