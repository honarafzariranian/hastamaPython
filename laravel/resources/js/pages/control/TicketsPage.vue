<script setup>
/**
 * Ticket queue — the Vue equivalent of the `tickets` branch of
 * `app/templates/master-admin.html` plus `loadTickets()`, `loadTicketStats()`,
 * `window.maApplyTicketFilters()` and `window.ma_deleteTicket()` in
 * `master-admin.js`.
 *
 *   * `GET  /master-admin/api/tickets/stats` — the four headline counters;
 *   * `GET  /master-admin/api/tickets`       — the paginated queue;
 *   * `DELETE /master-admin/api/tickets/{id}` — drop a ticket.
 *
 * The legacy list wrote its own table markup instead of going through
 * `renderTable()` — it has a fixed `<thead>`, two action buttons per row and a
 * `ticket_number` fallback — so the columns below are the legacy ones and the
 * subject cell keeps its `title` tooltip and truncation.
 *
 * **The stat cards are left in ASCII digits.**  `loadTicketStats()` interpolated
 * `s.total` and friends straight into the template while every *other* counter
 * on this page runs through `toFa()`.  Persisting the inconsistency is
 * deliberate: these four numbers are the ones a support lead scans for
 * parity, and they must read the same as the Python screen.
 *
 * `state.perPage` is 25 for the shared tables but the ticket list pins
 * `per_page: 20` explicitly, so `PER_PAGE` is 20 here.
 */
import { onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import TicketBadge from '@/pages/control/TicketBadge.vue';
import PaginationBar from '@/pages/control/PaginationBar.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const emit = defineEmits(['navigate']);

const PER_PAGE = 20;

const loading = ref(true);
const error = ref('');

const stats = ref(null);
const tickets = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const filters = reactive({
    search: '',
    status: '',
    priority: '',
    sort: 'newest',
});

const statusOptions = [
    { value: 'new', label: 'جدید' },
    { value: 'open', label: 'باز' },
    { value: 'in_progress', label: 'در حال بررسی' },
    { value: 'waiting_for_user', label: 'در انتظار کاربر' },
    { value: 'waiting_for_support', label: 'در انتظار پشتیبانی' },
    { value: 'resolved', label: 'حل‌شده' },
    { value: 'closed', label: 'بسته‌شده' },
];

const priorityOptions = [
    { value: 'urgent', label: 'فوری' },
    { value: 'high', label: 'زیاد' },
    { value: 'normal', label: 'عادی' },
    { value: 'low', label: 'کم' },
];

const sortOptions = [
    { value: 'newest', label: 'جدیدترین' },
    { value: 'oldest', label: 'قدیمی‌ترین' },
    { value: 'priority', label: 'اولویت' },
];

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

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

/** `t.ticket_number || 'HT-' + String(t.id).padStart(8,'0')` */
function ticketNumber(ticket) {
    return ticket.ticket_number || `HT-${String(ticket.id).padStart(8, '0')}`;
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fa-IR');
}

async function loadStats() {
    const response = await api.get('/master-admin/api/tickets/stats', { baseURL: '' });
    stats.value = response.data ?? {};
}

async function loadTickets() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/tickets', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                search: filters.search,
                status: filters.status,
                priority: filters.priority,
                sort: filters.sort,
            },
        });

        const result = response.data ?? {};
        tickets.value = Array.isArray(result.items) ? result.items : [];
        total.value = result.total ?? tickets.value.length;
        pages.value = result.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری تیکت‌ها';
        tickets.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

async function refresh() {
    /* The legacy loader awaited its stats read before the list, so a stats
     * failure must not blank the table — and vice versa. */
    await Promise.all([
        loadStats().catch(() => {}),
        loadTickets(),
    ]);
}

function applyFilters() {
    page.value = 1;
    refresh();
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    refresh();
}

function viewTicket(ticket) {
    emit('navigate', 'ticket-detail', { t: String(ticket.id) });
}

async function deleteTicket(ticket) {
    if (busy.value) {
        return;
    }

    const ticketId = ticket.id;
    const accepted = await confirmAction({
        title: 'حذف تیکت',
        msg: 'آیا از حذف این تیکت اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = ticketId;

    try {
        await api.delete(`/master-admin/api/tickets/${encodeURIComponent(ticketId)}`, { baseURL: '' });
        await refresh();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف تیکت انجام نشد';
    } finally {
        busy.value = '';
    }
}

onMounted(refresh);
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <div class="ma-ticket-stats" id="maTicketStats">
            <div v-if="stats" class="ma-top-cards-row">
                <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#a1c4fd,#c2e9fb)">
                    <div class="ma-glow-card__content">
                        <div class="ma-glow-card__top"><div class="ma-glow-card__icon">🎫</div></div>
                        <div class="ma-glow-card__label">کل تیکت‌ها</div>
                        <div class="ma-glow-card__value">{{ stats.total || 0 }}</div>
                    </div>
                </div>
                <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#f093fb,#f5576c)">
                    <div class="ma-glow-card__content">
                        <div class="ma-glow-card__top"><div class="ma-glow-card__icon">📬</div></div>
                        <div class="ma-glow-card__label">تیکت‌های باز</div>
                        <div class="ma-glow-card__value">{{ stats.open || 0 }}</div>
                    </div>
                </div>
                <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#11998e,#38ef7d)">
                    <div class="ma-glow-card__content">
                        <div class="ma-glow-card__top"><div class="ma-glow-card__icon">✅</div></div>
                        <div class="ma-glow-card__label">حل‌شده</div>
                        <div class="ma-glow-card__value">{{ (stats.by_status && stats.by_status.resolved) || 0 }}</div>
                    </div>
                </div>
                <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#ff9a9e,#fad0c4)">
                    <div class="ma-glow-card__content">
                        <div class="ma-glow-card__top"><div class="ma-glow-card__icon">🔴</div></div>
                        <div class="ma-glow-card__label">فوری</div>
                        <div class="ma-glow-card__value">{{ (stats.by_priority && stats.by_priority.urgent) || 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <form class="ma-filters" id="maTicketFilters" @submit.prevent="applyFilters">
            <input
                id="ticketFilterSearch"
                v-model="filters.search"
                type="text"
                class="ma-filter"
                placeholder="جستجو در موضوع، درخواست‌کننده..."
                aria-label="جستجوی تیکت"
            >
            <select id="ticketFilterStatus" v-model="filters.status" class="ma-filter" aria-label="وضعیت">
                <option value="">همه وضعیت‌ها</option>
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <select id="ticketFilterPriority" v-model="filters.priority" class="ma-filter" aria-label="اولویت">
                <option value="">همه اولویت‌ها</option>
                <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <select id="ticketFilterSort" v-model="filters.sort" class="ma-filter" aria-label="ترتیب">
                <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <button type="submit" class="ma-btn ma-btn--primary">اعمال فیلتر</button>
        </form>

        <div class="ma-panel-card">
            <div class="ma-panel-card__body--flush">
                <div v-if="loading" class="ma-empty">
                    <div class="ma-empty__text">در حال بارگذاری تیکت‌ها…</div>
                </div>

                <div v-else-if="!tickets.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">تیکتی موجود نیست</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th>شماره</th>
                                <th>موضوع</th>
                                <th>درخواست‌کننده</th>
                                <th>گیرنده</th>
                                <th>وضعیت</th>
                                <th>اولویت</th>
                                <th>تاریخ</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ticket in tickets" :key="ticket.id">
                                <td><code style="font-size:.75rem">{{ ticketNumber(ticket) }}</code></td>
                                <td
                                    style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                    :title="ticket.subject || ''"
                                >
                                    {{ ticket.subject || '—' }}
                                </td>
                                <td>{{ ticket.requester_username || '—' }}</td>
                                <td>{{ ticket.recipient_username || '—' }}</td>
                                <td><TicketBadge :value="ticket.status" map="status" /></td>
                                <td><TicketBadge :value="ticket.priority" map="priority" /></td>
                                <td style="font-size:.75rem">{{ formatDate(ticket.created_at) }}</td>
                                <td>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--ghost ma-btn--sm"
                                        @click="viewTicket(ticket)"
                                    >
                                        مشاهده
                                    </button>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === ticket.id"
                                        @click="deleteTicket(ticket)"
                                    >
                                        حذف
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
