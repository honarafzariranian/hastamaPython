<script setup>
/**
 * Ticket detail — the Vue equivalent of the `ticket-detail` branch of
 * `app/templates/master-admin.html` plus `loadTicketDetail()`,
 * `maSaveTicketChanges()`, `maSendTicketReply()` and
 * `maDeleteTicketFromDetail()` in `master-admin.js`.
 *
 *   * `GET    /master-admin/api/tickets/{id}` — the ticket, its messages and
 *     its event history;
 *   * `GET    /master-admin/api/tickets/users/all`      — the assignee picker;
 *   * `GET    /master-admin/api/tickets/categories/all` — the category picker;
 *   * `PATCH  /master-admin/api/tickets/{id}` — status / priority / assignee /
 *     category;
 *   * `POST   /master-admin/api/tickets/{id}/reply` — public reply or internal
 *     note;
 *   * `DELETE /master-admin/api/tickets/{id}` — drop the ticket and go back to
 *     the queue.
 *
 * The legacy page identified the ticket with `?t=<id>` on
 * `/master-admin/ticket-detail`, which is the route name and query key kept
 * here so a bookmarked or handed-over link still opens the same ticket.
 *
 * `maSaveTicketChanges()` always sent all four management fields together, so
 * the save button does too rather than patching only what the operator touched.
 */
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import TicketBadge from '@/pages/control/TicketBadge.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const emit = defineEmits(['navigate']);

const route = useRoute();

const loading = ref(true);
const error = ref('');

const ticket = ref(null);
const users = ref([]);
const categories = ref([]);

const form = reactive({
    status: '',
    priority: '',
    assigned_to: '',
    category_id: '',
});

const reply = reactive({
    body: '',
    internal: false,
});

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

const STATUS_OPTIONS = [
    { value: 'new', label: 'جدید' },
    { value: 'open', label: 'باز' },
    { value: 'in_progress', label: 'در حال بررسی' },
    { value: 'waiting_for_user', label: 'در انتظار کاربر' },
    { value: 'waiting_for_support', label: 'در انتظار پشتیبانی' },
    { value: 'resolved', label: 'حل‌شده' },
    { value: 'closed', label: 'بسته‌شده' },
];

const PRIORITY_OPTIONS = [
    { value: 'low', label: 'کم' },
    { value: 'normal', label: 'عادی' },
    { value: 'high', label: 'زیاد' },
    { value: 'urgent', label: 'فوری' },
];

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

function ticketNumber(value) {
    return value.ticket_number || `HT-${String(value.id).padStart(8, '0')}`;
}

function requestedId() {
    const raw = route.query.t;

    return typeof raw === 'string' ? raw.trim() : '';
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
}

function formatEventTime(value) {
    if (!value) {
        return '';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('fa-IR');
}

/** `Object.entries(e.metadata).map(([k,v]) => `${k}: ${v}`).join(', ')` */
function eventMeta(metadata) {
    if (!metadata || typeof metadata !== 'object') {
        return '';
    }

    return Object.entries(metadata)
        .map(([key, value]) => `${key}: ${value}`)
        .join(', ');
}

function backToQueue() {
    emit('navigate', 'tickets');
}

function syncForm(value) {
    form.status = value.status ?? '';
    form.priority = value.priority ?? '';
    form.assigned_to = value.assigned_to ?? '';
    form.category_id = value.category_id ?? '';
}

async function loadTicket() {
    const id = requestedId();
    loading.value = true;
    error.value = '';
    ticket.value = null;

    if (!id) {
        loading.value = false;

        return;
    }

    try {
        const [ticketResponse, usersResponse, categoriesResponse] = await Promise.all([
            api.get(`/master-admin/api/tickets/${encodeURIComponent(id)}`, { baseURL: '' }),
            api.get('/master-admin/api/tickets/users/all', { baseURL: '' }),
            api.get('/master-admin/api/tickets/categories/all', { baseURL: '' }),
        ]);

        ticket.value = ticketResponse.data ?? null;
        users.value = Array.isArray(usersResponse.data) ? usersResponse.data : [];
        categories.value = Array.isArray(categoriesResponse.data) ? categoriesResponse.data : [];
        syncForm(ticket.value);
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری تیکت';
    } finally {
        loading.value = false;
    }
}

async function saveChanges() {
    const id = requestedId();

    if (busy.value || !id) {
        return;
    }

    busy.value = 'save';

    try {
        await api.patch(
            `/master-admin/api/tickets/${encodeURIComponent(id)}`,
            {
                status: form.status,
                priority: form.priority,
                assigned_to: form.assigned_to || null,
                category_id: form.category_id ? Number(form.category_id) : null,
            },
            { baseURL: '' },
        );

        await loadTicket();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'تیکت به‌روزرسانی نشد';
    } finally {
        busy.value = '';
    }
}

async function sendReply() {
    const id = requestedId();
    const body = reply.body.trim();

    if (busy.value || !id) {
        return;
    }

    if (!body) {
        error.value = 'لطفاً متن پاسخ را وارد کنید';

        return;
    }

    busy.value = 'reply';

    try {
        await api.post(
            `/master-admin/api/tickets/${encodeURIComponent(id)}/reply`,
            { body, visibility: reply.internal ? 'internal' : 'public' },
            { baseURL: '' },
        );

        reply.body = '';
        reply.internal = false;
        await loadTicket();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'پاسخ ارسال نشد';
    } finally {
        busy.value = '';
    }
}

async function deleteTicket() {
    const id = requestedId();

    if (busy.value || !id) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف تیکت',
        msg: 'آیا از حذف این تیکت اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = 'delete';

    try {
        await api.delete(`/master-admin/api/tickets/${encodeURIComponent(id)}`, { baseURL: '' });
        backToQueue();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف تیکت انجام نشد';
    } finally {
        busy.value = '';
    }
}

/* The queue opens a ticket by changing the query, so the same component stays
   mounted and has to react to `?t=` rather than only read it once. */
watch(requestedId, loadTicket);

onMounted(loadTicket);
</script>

<template>
    <div id="maTicketDetail">
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <div v-if="loading" class="ma-skeleton" style="height:400px;margin:20px"></div>

        <div v-else-if="!requestedId()" class="ma-empty">
            <div class="ma-empty__icon">🎫</div>
            <div class="ma-empty__text">شناسه تیکت مشخص نشده</div>
        </div>

        <template v-else-if="ticket">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
                <button type="button" class="ma-btn ma-btn--ghost" style="font-size:0.82rem" @click="backToQueue">
                    ← بازگشت به تیکت‌ها
                </button>
                <h2 style="margin:0;font-size:1.1rem;font-weight:800;color:#0f172a">
                    {{ ticketNumber(ticket) }} — {{ ticket.subject }}
                </h2>
                <TicketBadge :value="ticket.status" map="status" />
                <TicketBadge :value="ticket.priority" map="priority" />
            </div>

            <div class="ma-grid-2" style="margin-bottom:20px">
                <div class="ma-panel-card">
                    <div class="ma-panel-card__header">
                        <div class="ma-panel-card__title">📋 اطلاعات تیکت</div>
                    </div>
                    <div class="ma-panel-card__body">
                        <table style="width:100%;font-size:0.82rem;border-collapse:collapse">
                            <tbody>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b;width:140px">شماره</td>
                                    <td style="padding:6px 0;font-weight:600">{{ ticketNumber(ticket) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">موضوع</td>
                                    <td style="padding:6px 0">{{ ticket.subject }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">درخواست‌کننده</td>
                                    <td style="padding:6px 0">{{ ticket.requester_username }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">گیرنده</td>
                                    <td style="padding:6px 0">{{ ticket.recipient_username }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">واگذار شده به</td>
                                    <td style="padding:6px 0">{{ ticket.assigned_to || '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">دسته‌بندی</td>
                                    <td style="padding:6px 0">{{ ticket.category_name || '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">تاریخ ایجاد</td>
                                    <td style="padding:6px 0">{{ formatDateTime(ticket.created_at) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">آخرین به‌روزرسانی</td>
                                    <td style="padding:6px 0">{{ formatDateTime(ticket.updated_at) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">آخرین پیام</td>
                                    <td style="padding:6px 0">{{ formatDateTime(ticket.last_message_at) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#64748b">SLA</td>
                                    <td style="padding:6px 0">
                                        {{ formatDateTime(ticket.sla_due_at) }}
                                        <span v-if="ticket.sla_state === 'overdue'" style="color:#dc2626;font-weight:700">
                                            — سررسید گذشته
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="ma-panel-card">
                    <div class="ma-panel-card__header">
                        <div class="ma-panel-card__title">⚙️ مدیریت تیکت</div>
                    </div>
                    <div class="ma-panel-card__body">
                        <div style="display:flex;flex-direction:column;gap:12px">
                            <label for="maTicketStatus" style="font-size:.82rem;font-weight:600;color:#415466">
                                وضعیت
                            </label>
                            <select id="maTicketStatus" v-model="form.status" class="ma-filter" style="width:100%">
                                <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>

                            <label for="maTicketPriority" style="font-size:.82rem;font-weight:600;color:#415466">
                                اولویت
                            </label>
                            <select id="maTicketPriority" v-model="form.priority" class="ma-filter" style="width:100%">
                                <option v-for="option in PRIORITY_OPTIONS" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>

                            <label for="maTicketAssignee" style="font-size:.82rem;font-weight:600;color:#415466">
                                واگذاری به
                            </label>
                            <select id="maTicketAssignee" v-model="form.assigned_to" class="ma-filter" style="width:100%">
                                <option value="">بدون واگذاری</option>
                                <option v-for="user in users" :key="user.username" :value="user.username">
                                    {{ user.name || user.username }} — {{ user.department || '' }}
                                </option>
                            </select>

                            <label for="maTicketCategory" style="font-size:.82rem;font-weight:600;color:#415466">
                                دسته‌بندی
                            </label>
                            <select id="maTicketCategory" v-model="form.category_id" class="ma-filter" style="width:100%">
                                <option value="">بدون دسته</option>
                                <option v-for="category in categories" :key="category.id" :value="category.id">
                                    {{ category.name }}
                                </option>
                            </select>

                            <div style="display:flex;gap:8px;margin-top:8px">
                                <button
                                    type="button"
                                    class="ma-btn ma-btn--primary"
                                    :disabled="busy === 'save'"
                                    @click="saveChanges"
                                >
                                    ذخیره تغییرات
                                </button>
                                <button
                                    type="button"
                                    class="ma-btn ma-btn--danger"
                                    :disabled="busy === 'delete'"
                                    @click="deleteTicket"
                                >
                                    حذف تیکت
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ma-panel-card" style="margin-bottom:20px">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">💬 پیام‌ها ({{ (ticket.messages || []).length }})</div>
                </div>
                <div class="ma-panel-card__body" style="max-height:400px;overflow-y:auto">
                    <div v-if="!ticket.messages || !ticket.messages.length" class="ma-empty" style="padding:20px">
                        <div class="ma-empty__text">هنوز پیامی ارسال نشده</div>
                    </div>

                    <template v-else>
                        <div
                            v-for="message in ticket.messages || []"
                            :key="message.id"
                            :style="message.visibility === 'internal'
                                ? 'padding:12px;margin-bottom:10px;border-radius:10px;border:1px solid #e4e7ec;border-right:3px solid #f59e0b;background:#fffbeb'
                                : 'padding:12px;margin-bottom:10px;border-radius:10px;border:1px solid #e4e7ec'"
                        >
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                <div>
                                    <strong style="font-size:.85rem">{{ message.author_username }}</strong>
                                    <span
                                        v-if="message.visibility === 'internal'"
                                        class="ma-badge ma-badge--warning"
                                        style="margin-right:6px;font-size:.65rem"
                                    >
                                        یادداشت داخلی
                                    </span>
                                </div>
                                <span style="font-size:.72rem;color:#94a3b8">{{ formatEventTime(message.created_at) }}</span>
                            </div>
                            <div style="font-size:.85rem;color:#334155;line-height:1.7;white-space:pre-wrap">
                                {{ message.body }}
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="ma-panel-card" style="margin-bottom:20px">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">✏️ ارسال پاسخ</div>
                </div>
                <div class="ma-panel-card__body">
                    <textarea
                        id="maTicketReplyBody"
                        v-model="reply.body"
                        class="ma-filter"
                        style="width:100%;min-height:100px;resize:vertical"
                        placeholder="متن پاسخ..."
                    ></textarea>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                        <label style="font-size:.82rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input id="maTicketReplyInternal" v-model="reply.internal" type="checkbox">
                            یادداشت داخلی (فقط مدیران)
                        </label>
                        <button
                            type="button"
                            class="ma-btn ma-btn--primary"
                            :disabled="busy === 'reply'"
                            @click="sendReply"
                        >
                            ارسال پاسخ
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="ticket.events && ticket.events.length" class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">📋 تاریخچه رویدادها</div>
                </div>
                <div class="ma-panel-card__body" style="max-height:250px;overflow-y:auto">
                    <div class="ma-timeline">
                        <div v-for="event in ticket.events" :key="event.id" class="ma-timeline__item">
                            <div class="ma-timeline__dot ma-timeline__dot--success"></div>
                            <div class="ma-timeline__time">{{ formatEventTime(event.created_at) }}</div>
                            <div class="ma-timeline__text">
                                <strong>{{ event.actor_username }}</strong> {{ event.event_type }}
                            </div>
                            <div class="ma-timeline__meta">{{ eventMeta(event.metadata) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
