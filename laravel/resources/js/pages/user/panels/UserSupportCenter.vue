<script setup>
/**
 * The user-support centre — the Vue equivalent of `#userSupportCenter` in
 * `user-panel.html`, ported from the user half of `ticketing.js`:
 *   * `GET   /api/tickets?page&page_size&search&status` — list + counts;
 *   * `GET   /api/tickets/{id}`                          — one conversation;
 *   * `POST  /api/tickets/{id}/messages`                 — reply;
 *   * `POST  /api/tickets/{id}/attachments`              — reply attachment;
 *   * `PATCH /api/tickets/{id}`                          — resolve / reopen.
 * The “+ درخواست جدید” button asks the host to open the create modal.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';

const emit = defineEmits(['close', 'new-ticket']);

const USER_STATUS_LABELS = {
    new: 'ثبت‌شده',
    open: 'باز',
    in_progress: 'در حال بررسی',
    waiting_for_user: 'منتظر پاسخ شما',
    waiting_for_support: 'در صف پشتیبانی',
    resolved: 'حل‌شده',
    closed: 'بسته‌شده',
};

const USER_EVENT_LABELS = {
    status_changed: 'وضعیت درخواست تغییر کرد',
    priority_changed: 'اولویت درخواست تغییر کرد',
    assigned: 'درخواست به پشتیبانی ارجاع شد',
    reply_added: 'پاسخ جدید ثبت شد',
    attachment_added: 'پیوست جدید اضافه شد',
    reopened: 'درخواست دوباره باز شد',
};

const PRIORITY_LABELS = { low: 'کم', normal: 'عادی', high: 'زیاد', urgent: 'فوری' };

const FILTERS = [
    { value: '', label: 'همه درخواست‌ها' },
    { value: 'new', label: 'تازه ثبت‌شده' },
    { value: 'in_progress', label: 'در حال بررسی' },
    { value: 'waiting_for_user', label: 'نیازمند پاسخ من' },
    { value: 'waiting_for_support', label: 'در انتظار پشتیبانی' },
    { value: 'resolved', label: 'حل‌شده' },
    { value: 'closed', label: 'بسته‌شده' },
];

const ACCEPT = '.pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx,.xls,.xlsx';

const loading = ref(true);
const error = ref('');

const items = ref([]);
const page = ref(1);
const pages = ref(1);
const search = ref('');
const status = ref('');
const counts = ref({ total: 0, raw: {}, waiting: 0 });

const detail = ref(null);
const detailLoading = ref(false);
const reply = ref('');
const replyFiles = ref(null);
const replyStatus = ref('');
const sendingReply = ref(false);

const summary = computed(() => {
    const raw = counts.value.raw || {};

    return {
        all: counts.value.total || 0,
        open: Number(raw.open || 0) + Number(raw.new || 0) + Number(raw.in_progress || 0),
        waiting: Number(raw.waiting_for_user || 0),
        resolved: Number(raw.resolved || 0) + Number(raw.closed || 0),
    };
});

function fa(value) {
    return new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' })
            .format(new Date(value));
    } catch {
        return String(value);
    }
}

function statusLabel(value) {
    return USER_STATUS_LABELS[value] || 'در حال بررسی';
}

function priorityLabel(value) {
    return PRIORITY_LABELS[value] || value || 'عادی';
}

async function loadList(targetPage = 1) {
    loading.value = true;
    error.value = '';

    const params = new URLSearchParams({ page: String(targetPage), page_size: '12' });

    if (search.value.trim() !== '') {
        params.set('search', search.value.trim());
    }

    if (status.value !== '') {
        params.set('status', status.value);
    }

    try {
        const data = await api.get(`/tickets?${params.toString()}`);
        items.value = Array.isArray(data?.items) ? data.items : [];
        page.value = data?.page ?? 1;
        pages.value = data?.pages ?? 1;
        counts.value = {
            total: data?.total ?? 0,
            raw: data?.counts || {},
            waiting: data?.counts?.waiting_for_user ?? 0,
        };
    } catch (failure) {
        error.value = failure?.message || 'دریافت درخواست‌ها ناموفق بود. دوباره تلاش کنید.';
    } finally {
        loading.value = false;
    }
}

async function selectFilter(value) {
    status.value = value;
    await loadList(1);
}

async function openDetail(ticket) {
    detailLoading.value = true;
    detail.value = null;
    reply.value = '';
    replyStatus.value = '';

    try {
        const response = await api.get(`/tickets/${ticket.id}`);

        if (response?.ticket) {
            detail.value = {
                ...response.ticket,
                messages: response.messages || [],
                attachments: response.attachments || [],
                events: response.events || [],
            };
        } else {
            detail.value = response;
        }
    } catch (failure) {
        error.value = failure?.message || 'دریافت جزئیات درخواست ناموفق بود.';
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail() {
    detail.value = null;
}

function attachmentsFor(message) {
    return (detail.value?.attachments || []).filter(
        (file) => Number(file.message_id) === Number(message.id),
    );
}

const PROGRESS_STEPS = [
    { value: 'new', label: 'ثبت درخواست' },
    { value: 'in_progress', label: 'بررسی پشتیبانی' },
    { value: 'waiting_for_user', label: 'پاسخ شما' },
    { value: 'resolved', label: 'حل‌شده' },
];

const STAGE_INDEX = {
    new: 0, open: 1, in_progress: 1, waiting_for_support: 1, waiting_for_user: 2, resolved: 3, closed: 3,
};

function stepClass(index) {
    const current = STAGE_INDEX[detail.value?.status] ?? 0;

    if (index === current) {
        return 'is-current';
    }

    return index < current ? 'is-done' : '';
}

function messageClass(message) {
    if (message.visibility === 'internal') {
        return 'is-system';
    }

    return message.author_username === detail.value?.requester_username ? 'is-mine' : 'is-support';
}

function messageAuthor(message) {
    if (message.visibility === 'internal') {
        return 'رویداد سیستم';
    }

    return message.author_username === detail.value?.requester_username ? 'شما' : 'پشتیبانی';
}

function eventLabel(type) {
    return USER_EVENT_LABELS[type] || 'رویداد درخواست';
}

const relatedEvents = computed(() => (detail.value?.events || []).filter((event) => event.event_type !== 'created'));

const activityText = computed(() => (items.value.length > 0
    ? `آخرین تغییر در ${items.value[0].ticket_number || ''} · ${formatDate(items.value[0].updated_at)}`
    : 'هنوز فعالیتی برای نمایش وجود ندارد.'));

async function copyNumber() {
    if (!detail.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(detail.value.ticket_number);
    } catch {
        /* clipboard blocked — the number is visible anyway */
    }
}

async function sendReply() {
    if (!detail.value || sendingReply.value) {
        return;
    }

    const body = reply.value.trim();

    if (body === '') {
        return;
    }

    sendingReply.value = true;
    replyStatus.value = '';

    try {
        await api.post(`/tickets/${detail.value.id}/messages`, { body, visibility: 'public' });

        if (replyFiles.value && replyFiles.value.length > 0) {
            const refreshed = await api.get(`/tickets/${detail.value.id}`);
            const last = (refreshed?.messages || []).at(-1);

            if (last) {
                for (const file of Array.from(replyFiles.value)) {
                    const form = new FormData();
                    form.append('file', file);
                    form.append('message_id', last.id);
                    await api.post(`/tickets/${detail.value.id}/attachments`, form);
                }
            }
        }

        reply.value = '';
        replyFiles.value = null;
        await openDetail(detail.value);
        await loadList(page.value);
    } catch (failure) {
        replyStatus.value = failure?.message || 'ارسال پاسخ ناموفق بود.';
    } finally {
        sendingReply.value = false;
    }
}

async function patchStatus(value) {
    if (!detail.value) {
        return;
    }

    try {
        await api.patch(`/tickets/${detail.value.id}`, { status: value });
        await openDetail(detail.value);
        await loadList(page.value);
    } catch (failure) {
        error.value = failure?.message || 'به‌روزرسانی وضعیت ناموفق بود.';
    }
}

onMounted(() => loadList(1));
</script>

<template>
    <div id="userSupportCenter" class="user-support-center" aria-hidden="false">
        <div class="user-support-backdrop" @click="emit('close')"></div>
        <section class="user-support-dialog" role="dialog" aria-modal="true" aria-labelledby="userSupportTitle">
            <header class="user-support-header">
                <div class="user-support-heading">
                    <button type="button" class="user-support-back" aria-label="بازگشت" @click="emit('close')">→</button>
                    <div>
                        <span class="ticket-panel-kicker">فضای شخصی پشتیبانی</span>
                        <h2 id="userSupportTitle">مرکز پشتیبانی من</h2>
                        <p>درخواست‌ها، پاسخ‌ها و پیگیری‌های شما</p>
                    </div>
                </div>
                <div class="user-support-header-actions">
                    <button type="button" class="user-support-refresh" aria-label="تازه‌سازی" @click="loadList(page)">↻</button>
                    <button type="button" class="user-support-new" @click="emit('new-ticket')">+ درخواست جدید</button>
                    <button type="button" class="user-support-close" aria-label="بستن مرکز پشتیبانی" @click="emit('close')">×</button>
                </div>
            </header>
            <div class="user-support-summary" aria-label="خلاصه وضعیت تیکت‌ها">
                <button type="button" :class="{ 'is-active': status === '' }" @click="selectFilter('')"><strong id="userSupportAllCount">{{ fa(summary.all) }}</strong><span>همه درخواست‌ها</span></button>
                <button type="button" :class="{ 'is-active': status === 'open' }" @click="selectFilter('open')"><strong id="userSupportOpenCount">{{ fa(summary.open) }}</strong><span>در حال پیگیری</span></button>
                <button type="button" :class="{ 'is-active': status === 'waiting_for_user' }" @click="selectFilter('waiting_for_user')"><strong id="userSupportWaitingCount">{{ fa(summary.waiting) }}</strong><span>منتظر پاسخ من</span></button>
                <button type="button" :class="{ 'is-active': status === 'resolved' }" @click="selectFilter('resolved')"><strong id="userSupportResolvedCount">{{ fa(summary.resolved) }}</strong><span>حل‌شده</span></button>
            </div>
            <div class="user-support-layout">
                <aside class="user-support-nav" aria-label="فیلتر درخواست‌ها">
                    <div class="user-support-nav-title">درخواست‌های من</div>
                    <button
                        v-for="filter in FILTERS"
                        :key="filter.value"
                        type="button"
                        :class="{ 'is-active': status === filter.value }"
                        @click="selectFilter(filter.value)"
                    >{{ filter.label }} <span>›</span></button>
                    <div class="user-support-help"><strong>راهنما</strong><span>پاسخ‌های جدید در همین مرکز نمایش داده می‌شوند.</span></div>
                </aside>
                <section class="user-support-list-pane" aria-label="فهرست درخواست‌های پشتیبانی">
                    <div class="user-support-list-head">
                        <div><span class="user-support-eyebrow">فهرست درخواست‌ها</span><h3>پیگیری‌های اخیر</h3></div>
                        <label class="user-support-search">
                            <span class="sr-only">جست‌وجوی درخواست</span>
                            <input v-model="search" type="search" id="userSupportSearch" placeholder="شماره یا موضوع را جست‌وجو کنید…" autocomplete="off" @keyup.enter="loadList(1)">
                        </label>
                    </div>
                    <div id="userSupportTicketList" class="user-support-ticket-list" aria-live="polite">
                        <div v-if="loading" class="user-support-skeleton"></div>
                        <div v-else-if="error" class="user-support-empty-list">{{ error }}</div>
                        <div v-else-if="items.length === 0" class="user-support-empty-list">
                            <div class="user-support-empty-icon">✦</div>
                            <h3>{{ search || status ? 'درخواستی مطابق جست‌وجو پیدا نشد' : 'هنوز درخواستی ثبت نکرده‌اید' }}</h3>
                            <p>{{ search || status ? 'فیلترها را تغییر دهید یا یک درخواست جدید ثبت کنید.' : 'هر زمان به کمک نیاز داشتید، درخواست خود را از همین‌جا ارسال کنید.' }}</p>
                            <button type="button" class="user-support-empty-action" @click="emit('new-ticket')">ثبت درخواست جدید</button>
                        </div>
                        <template v-else>
                            <article
                                v-for="ticket in items"
                                :key="ticket.id"
                                class="user-support-ticket"
                                :class="{ 'is-waiting': ticket.status === 'waiting_for_user' }"
                                :data-ticket-id="ticket.id"
                                tabindex="0"
                                @click="openDetail(ticket)"
                            >
                                <div class="user-support-ticket-top">
                                    <span class="user-support-ticket-number">{{ ticket.ticket_number || `HT-${ticket.id}` }}</span>
                                    <span class="ticket-workspace-badge" :class="`ticket-workspace-badge--${ticket.status}`">{{ statusLabel(ticket.status) }}</span>
                                </div>
                                <h4 class="user-support-ticket-subject">{{ ticket.subject || 'بدون موضوع' }}</h4>
                                <div class="user-support-ticket-activity">
                                    <span>{{ ticket.category_name || 'عمومی' }}</span>
                                    <span>آخرین پاسخ: {{ ticket.last_responder || '—' }}</span>
                                    <time>ثبت {{ formatDate(ticket.created_at) }}</time>
                                </div>
                                <div class="user-support-ticket-foot">
                                    <span class="ticket-workspace-badge" :class="`ticket-workspace-badge--priority-${ticket.priority || 'normal'}`">{{ priorityLabel(ticket.priority) }}</span>
                                    <span>{{ ticket.last_message_preview || 'برای مشاهده جزئیات انتخاب کنید.' }}</span>
                                </div>
                            </article>
                        </template>
                    </div>
                    <div id="userSupportPagination" class="user-support-pagination">
                        <template v-if="pages > 1">
                            <button type="button" class="ticket-page-btn" :disabled="page <= 1" @click="loadList(page - 1)">قبلی</button>
                            <span class="ticket-page-info">صفحه {{ fa(page) }} از {{ fa(pages) }}</span>
                            <button type="button" class="ticket-page-btn" :disabled="page >= pages" @click="loadList(page + 1)">بعدی</button>
                        </template>
                    </div>
                </section>
                <section id="userSupportDetail" class="user-support-detail" aria-label="جزئیات درخواست">
                    <div v-if="detailLoading" class="user-support-detail-empty">در حال دریافت…</div>
                    <div v-else-if="!detail" class="user-support-detail-empty">
                        <div class="user-support-empty-mark">✦</div>
                        <h3>یک درخواست را انتخاب کنید</h3>
                        <p>آخرین پاسخ و جزئیات درخواست انتخاب‌شده اینجا نمایش داده می‌شود.</p>
                    </div>
                    <template v-else>
                        <header class="user-support-detail-head">
                            <div>
                                <span class="user-support-ticket-number">{{ detail.ticket_number || `HT-${detail.id}` }}</span>
                                <h3>{{ detail.subject || 'بدون موضوع' }}</h3>
                                <p>آخرین به‌روزرسانی {{ formatDate(detail.updated_at) }}</p>
                            </div>
                            <button type="button" class="user-support-detail-close" aria-label="بستن جزئیات" @click="closeDetail">×</button>
                        </header>
                        <div class="user-support-detail-meta">
                            <span class="ticket-workspace-badge" :class="`ticket-workspace-badge--${detail.status}`">{{ statusLabel(detail.status) }}</span>
                            <span class="ticket-workspace-badge" :class="`ticket-workspace-badge--priority-${detail.priority || 'normal'}`">{{ priorityLabel(detail.priority) }}</span>
                            <span>دسته‌بندی: {{ detail.category_name || 'عمومی' }}</span>
                            <span>گیرنده: {{ detail.recipient_username || '—' }}</span>
                        </div>
                        <div class="user-support-progress">
                            <span v-for="(step, index) in PROGRESS_STEPS" :key="step.value" :class="stepClass(index)">{{ step.label }}</span>
                        </div>
                        <div class="user-support-timeline">
                            <div v-if="(detail.messages || []).length === 0" class="user-support-empty-list">هنوز پیامی در این درخواست وجود ندارد.</div>
                            <article
                                v-for="message in detail.messages"
                                :key="message.id"
                                class="user-support-message"
                                :class="messageClass(message)"
                            >
                                <header class="user-support-message-head">
                                    <strong>{{ messageAuthor(message) }}</strong>
                                    <time>{{ formatDate(message.created_at) }}</time>
                                </header>
                                <p class="user-support-message-body">{{ message.body }}</p>
                                <div v-if="attachmentsFor(message).length" class="user-support-attachments">
                                    <a v-for="file in attachmentsFor(message)" :key="file.id" :href="file.download_url" target="_blank" rel="noopener">{{ file.original_name || 'پیوست' }}</a>
                                </div>
                            </article>
                            <div v-for="(event, index) in relatedEvents" :key="`event-${index}`" class="user-support-event">{{ eventLabel(event.event_type) }} · {{ formatDate(event.created_at) }}</div>
                        </div>
                        <div class="user-support-detail-actions">
                            <button v-if="detail.status === 'resolved'" type="button" class="user-support-secondary-action" @click="patchStatus('open')">بازگشایی درخواست</button>
                            <button v-if="!['resolved', 'closed'].includes(detail.status)" type="button" class="user-support-secondary-action" @click="patchStatus('resolved')">اعلام حل‌شدن مشکل</button>
                            <button type="button" class="user-support-secondary-action" @click="copyNumber">کپی شماره درخواست</button>
                        </div>
                        <form v-if="detail.status !== 'closed'" class="user-support-composer" @submit.prevent="sendReply">
                            <textarea v-model="reply" id="userSupportReply" required maxlength="4000" placeholder="پاسخ خود را بنویسید…" aria-label="متن پاسخ"></textarea>
                            <label class="user-support-file-button">افزودن پیوست<input type="file" id="userSupportReplyFiles" multiple :accept="ACCEPT" @change="replyFiles = $event.target.files"></label>
                            <small class="user-support-upload-status">{{ replyStatus }}</small>
                            <button type="submit" class="user-support-send" :disabled="sendingReply">ارسال پاسخ</button>
                        </form>
                    </template>
                </section>
            </div>
            <section class="user-support-activity">
                <div class="matnepaeinticketing"><span class="user-support-eyebrow">آخرین فعالیت</span><h3>در جریان درخواست‌ها بمانید</h3></div>
                <p id="userSupportActivityText">{{ activityText }}</p>
            </section>
        </section>
    </div>
</template>

