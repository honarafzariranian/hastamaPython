<script setup>
/**
 * Admin ticket inbox — verbatim port of `ticketBox` (helpdesk-layout) from admin.html.
 *
 * Three panes:
 *   helpdesk-views     — status filter list (all/new/in_progress/...), counts per view
 *   helpdesk-list-pane — search, priority, sort; paginated ticket list
 *   helpdesk-context   — conversation thread with reply composer
 *
 * Data source: /api/tickets (normalized ticketing API already ported in Laravel).
 * Legacy /get_ticket_requests_admin is a 410 stub, so we use the modern endpoint.
 */
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const VIEWS = [
    { id: 'all',                label: 'همه تیکت‌ها' },
    { id: 'new',                label: 'جدید' },
    { id: 'in_progress',        label: 'در حال بررسی' },
    { id: 'waiting_for_user',   label: 'در انتظار کاربر' },
    { id: 'waiting_for_support',label: 'در انتظار پشتیبانی' },
    { id: 'resolved',           label: 'حل‌شده' },
    { id: 'closed',             label: 'بسته‌شده' },
];
const PRIORITIES = [
    { v: '',        label: 'همه اولویت‌ها' },
    { v: 'urgent',  label: 'فوری' },
    { v: 'high',    label: 'زیاد' },
    { v: 'normal',  label: 'عادی' },
    { v: 'low',     label: 'کم' },
];
const SORTS = [
    { v: 'newest',   label: 'جدیدترین فعالیت' },
    { v: 'oldest',   label: 'قدیمی‌ترین' },
    { v: 'priority', label: 'اولویت' },
];

const view = ref('all');
const search = ref('');
const priority = ref('');
const sort = ref('newest');
const page = ref(1);
const perPage = ref(25);

const loading = ref(true);
const error = ref('');
const tickets = ref([]);
const totalPages = ref(1);
const counts = reactive({ all: 0, new: 0, in_progress: 0, waiting_for_user: 0, waiting_for_support: 0, resolved: 0, closed: 0 });
const selected = ref(null);
const loadingSelected = ref(false);

/* New ticket modal */
const modalOpen = ref(false);
const receivers = ref([]);
const categories = ref([]);
const newTicket = reactive({ receiver: '', category_id: '', priority: 'normal', title: '', body: '' });
const submitting = ref(false);

/* Reply */
const reply = ref('');
const sendingReply = ref(false);

/* Delete confirmation */
const deleteConfirm = reactive({ open: false, id: null });

function b64id(id) {
    // Python likely uses same id format; just pass through
    return id;
}

async function loadCounts() {
    try {
        const params = { per_page: 1 };
        for (const v of VIEWS) {
            if (v.id === 'all') {
                const r = await api.get('/api/tickets', { params });
                counts.all = r.meta?.total ?? r.pagination?.total ?? 0;
            } else {
                const r = await api.get('/api/tickets', { params: { ...params, status: v.id } });
                counts[v.id] = r.meta?.total ?? r.pagination?.total ?? 0;
            }
        }
    } catch { /* stay zero */ }
}

async function loadList() {
    loading.value = true; error.value = '';
    try {
        const params = { page: page.value, per_page: perPage.value };
        if (view.value !== 'all') params.status = view.value;
        if (priority.value) params.priority = priority.value;
        if (search.value) params.search = search.value;
        params.sort = sort.value;
        const r = await api.get('/api/tickets', { params });
        tickets.value = r.data ?? r.tickets ?? [];
        totalPages.value = r.meta?.last_page ?? r.pagination?.total_pages ?? 1;
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت تیکت‌ها.';
        tickets.value = [];
    } finally { loading.value = false; }
}

function selectView(vid) { view.value = vid; page.value = 1; selected.value = null; loadList(); }

function doSearch() { page.value = 1; loadList(); }
function refresh() { page.value = 1; loadList(); loadCounts(); }

async function openTicket(t) {
    loadingSelected.value = true; error.value = '';
    try {
        const r = await api.get(`/api/tickets/${b64id(t.id)}`);
        selected.value = r.data ?? r.ticket ?? r;
        reply.value = '';
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در بارگذاری مکالمه.';
    } finally { loadingSelected.value = false; }
}

async function sendReply() {
    if (!selected.value || !reply.value.trim()) return;
    sendingReply.value = true;
    try {
        await api.post(`/api/tickets/${b64id(selected.value.id)}/messages`, { body: reply.value });
        reply.value = '';
        await openTicket(selected.value);
        await loadList(); await loadCounts();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ارسال پاسخ.';
    } finally { sendingReply.value = false; }
}

function askDelete(id) { deleteConfirm.open = true; deleteConfirm.id = id; }
function cancelDelete() { deleteConfirm.open = false; deleteConfirm.id = null; }
async function confirmDelete() {
    try {
        await api.delete(`/api/tickets/${b64id(deleteConfirm.id)}`);
        selected.value = null; cancelDelete(); await loadList(); await loadCounts();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در حذف تیکت.';
    }
}

async function openModal() {
    modalOpen.value = true;
    newTicket.receiver = ''; newTicket.category_id = ''; newTicket.priority = 'normal'; newTicket.title = ''; newTicket.body = '';
    try {
        const rec = await api.get('/get_receivers', { baseURL: '' });
        receivers.value = rec.users ?? rec.receivers ?? [];
    } catch { receivers.value = []; }
    try {
        const cat = await api.get('/api/tickets/categories');
        categories.value = cat.data ?? cat.categories ?? [];
    } catch { categories.value = []; }
}
function closeModal() { modalOpen.value = false; }
async function submitTicket() {
    if (!newTicket.title.trim() || !newTicket.body.trim()) { error.value = 'عنوان و توضیحات الزامی است.'; return; }
    submitting.value = true; error.value = '';
    try {
        await api.post('/api/tickets', {
            receiver: newTicket.receiver,
            category_id: newTicket.category_id || null,
            priority: newTicket.priority,
            subject: newTicket.title,
            title: newTicket.title,
            body: newTicket.body,
        });
        closeModal(); await refresh();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ثبت تیکت.';
    } finally { submitting.value = false; }
}

onMounted(() => { loadCounts(); loadList(); });
onBeforeUnmount(() => {});
</script>

<template>
    <header class="section-hero" style="--hero-accent:#14b8a6;--hero-accent-2:#2dd4bf;--hero-glow-1:rgba(20,184,166,0.14);--hero-glow-2:rgba(45,212,191,0.12);--hero-shadow:rgba(20,184,166,0.55);--hero-ink:#16233a;--hero-muted:#5a6b80;--hero-glow-sheen:rgba(20,184,166,0.08);">
        <div class="section-hero__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 7.8A2.8 2.8 0 016.8 5h10.4A2.8 2.8 0 0120 7.8v1.5a2.9 2.9 0 000 5.4v1.5a2.8 2.8 0 01-2.8 2.8H6.8A2.8 2.8 0 014 16.2v-1.5a2.9 2.9 0 000-5.4V7.8z" fill="#fff" opacity=".14"/><path d="M4 7.8A2.8 2.8 0 016.8 5h10.4A2.8 2.8 0 0120 7.8v1.5a2.9 2.9 0 000 5.4v1.5a2.8 2.8 0 01-2.8 2.8H6.8A2.8 2.8 0 014 16.2v-1.5a2.9 2.9 0 000-5.4V7.8z" stroke="#fff" stroke-width="1.9"/><path d="M14.2 7.5v9" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="2 2.4"/><path d="M7.2 10.2h3.4M7.2 13.8h3.4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" opacity=".8"/></svg>
        </div>
        <div class="section-hero__text">
            <h2>مدیریت تیکت‌ها</h2>
            <p>مدیریت تیکت‌های پشتیبانی و پیگیری درخواست‌ها</p>
        </div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="helpdesk-layout" dir="rtl">
        <p v-if="error" class="h-alert" role="alert" style="grid-column: 1 / -1;">{{ error }}</p>

        <aside class="helpdesk-views" aria-label="نماهای تیکت">
            <div class="helpdesk-views-head"><span class="ticket-panel-kicker">فضای کاری پشتیبانی</span><strong>صندوق تیکت</strong></div>
            <button v-for="v in VIEWS" :key="v.id" type="button" class="helpdesk-view" :class="{ 'is-active': view === v.id }" :data-ticket-view="v.id" @click="selectView(v.id)">
                {{ v.label }} <b :id="`adminViewCount${v.id === 'in_progress' ? 'Progress' : v.id === 'waiting_for_user' ? 'User' : v.id === 'waiting_for_support' ? 'Support' : v.id.charAt(0).toUpperCase() + v.id.slice(1)}`">{{ toPersianDigits(counts[v.id]) }}</b>
            </button>
        </aside>

        <section class="helpdesk-list-pane" aria-label="فهرست تیکت‌ها">
            <header class="helpdesk-pane-head">
                <div><span class="ticket-panel-kicker">مرکز پشتیبانی</span><h2>تیکت‌ها</h2><p id="adminTicketSummary">تیکت‌های قابل پیگیری را مدیریت کنید.</p></div>
                <div class="helpdesk-head-actions">
                    <button type="button" class="ticket-refresh-btn" id="refreshTicketRequests" aria-label="تازه‌سازی فهرست تیکت‌ها" @click="refresh">↻ تازه‌سازی</button>
                    <button type="button" class="ticket-new-btn" @click="openModal">+ تیکت جدید</button>
                </div>
            </header>
            <div class="ticket-toolbar" role="search">
                <label class="ticket-search" for="adminTicketSearch"><span class="sr-only">جستجو در تیکت‌ها</span>
                    <input type="search" id="adminTicketSearch" v-model="search" placeholder="جستجو بر اساس شماره، موضوع یا کاربر…" autocomplete="off" @keyup.enter="doSearch">
                </label>
                <select id="adminTicketPriority" class="ticket-filter" aria-label="فیلتر اولویت" v-model="priority" @change="doSearch">
                    <option v-for="p in PRIORITIES" :key="p.v" :value="p.v">{{ p.label }}</option>
                </select>
                <select id="adminTicketSort" class="ticket-filter" aria-label="مرتب‌سازی" v-model="sort" @change="doSearch">
                    <option v-for="s in SORTS" :key="s.v" :value="s.v">{{ s.label }}</option>
                </select>
            </div>
            <div id="adminTicketList" class="helpdesk-ticket-list">
                <div v-if="loading" class="ticket-loading-state">در حال دریافت تیکت‌ها…</div>
                <template v-else>
                    <article v-for="t in tickets" :key="t.id" class="helpdesk-ticket-item" :class="{ 'is-selected': selected?.id === t.id }" @click="openTicket(t)">
                        <header class="helpdesk-ticket-item__head">
                            <strong class="helpdesk-ticket-item__subject">{{ t.subject || t.title }}</strong>
                            <span class="helpdesk-ticket-item__id">#{{ toPersianDigits(String(t.id).slice(-5)) }}</span>
                        </header>
                        <div class="helpdesk-ticket-item__meta">
                            <span>{{ t.creator?.username || t.user?.username || t.username || 'کاربر' }}</span>
                            <span>{{ toPersianDigits(t.updated_at || t.created_at || '') }}</span>
                        </div>
                        <span class="helpdesk-ticket-item__status" :data-status="t.status">{{ t.status }}</span>
                    </article>
                    <div v-if="tickets.length === 0" class="ticket-loading-state">تیکتی در این نما یافت نشد.</div>
                </template>
            </div>
            <div id="adminTicketPagination" class="ticket-pagination" aria-label="صفحه‌بندی تیکت‌ها">
                <button v-if="page > 1" type="button" @click="page -= 1; loadList()">قبلی</button>
                <span v-for="p in totalPages" :key="p">
                    <button v-if="Math.abs(p - page) <= 2 || p === 1 || p === totalPages" type="button" :class="{ active: p === page }" @click="page = p; loadList()">{{ toPersianDigits(p) }}</button>
                    <span v-else-if="Math.abs(p - page) === 3">…</span>
                </span>
                <button v-if="page < totalPages" type="button" @click="page += 1; loadList()">بعدی</button>
            </div>
        </section>

        <aside id="adminTicketContext" class="helpdesk-context" aria-label="جزئیات تیکت">
            <div v-if="!selected" class="ticket-context-empty">
                <span>←</span><strong>یک تیکت را انتخاب کنید</strong><p>مکالمه و مشخصات آن در این بخش نمایش داده می‌شود.</p>
            </div>
            <template v-else>
                <header class="ticket-context-head">
                    <div>
                        <h3>{{ selected.subject || selected.title }}</h3>
                        <small>#{{ toPersianDigits(String(selected.id).slice(-5)) }} · {{ selected.creator?.username || selected.user?.username }} · {{ selected.status }}</small>
                    </div>
                    <button type="button" class="ticket-context-delete" @click="askDelete(selected.id)" title="حذف">🗑</button>
                </header>
                <div class="ticket-context-body">
                    <div v-if="loadingSelected" class="ticket-loading-state">در حال بارگذاری…</div>
                    <template v-else>
                        <article v-for="m in (selected.messages || selected.thread || [])" :key="m.id" class="ticket-message" :data-author="m.author?.role || m.role || 'user'">
                            <strong>{{ m.author?.name || m.author?.username || 'کاربر' }}</strong>
                            <time>{{ toPersianDigits(m.created_at || '') }}</time>
                            <p>{{ m.body || m.content }}</p>
                        </article>
                        <article v-if="!selected.messages?.length && selected.body" class="ticket-message" data-author="user">
                            <strong>{{ selected.creator?.username || 'کاربر' }}</strong>
                            <p>{{ selected.body }}</p>
                        </article>
                    </template>
                </div>
                <div class="ticket-reply-bar">
                    <input type="text" v-model="reply" placeholder="پاسخ خود را بنویسید…" aria-label="متن پاسخ" maxlength="4000" autocomplete="off" @keyup.enter="sendReply">
                    <button type="button" class="ticket-reply-send ersal-icon" aria-label="ارسال پاسخ" :disabled="sendingReply || !reply.trim()" @click="sendReply">↑</button>
                </div>
            </template>
        </aside>
    </div>

    <!-- New ticket modal -->
    <div v-if="modalOpen" id="ticketModal" class="morakhaci-sabt" :class="{ 'is-open': modalOpen }" style="display:block;">
        <div class="modaleSabteTicket sabt-ticket-wrap">
            <div class="modal-content-sabtTicket sabt-ticket-dialog" role="dialog" aria-modal="true" aria-labelledby="sabtTicketHeading">
                <header class="sabt-ticket-head">
                    <div>
                        <span class="ticket-panel-kicker">تیکت جدید</span>
                        <h2 id="sabtTicketHeading">ثبت تیکت</h2>
                        <p>موضوع را کوتاه و روشن بنویسید تا سریع‌تر پاسخ بگیرید.</p>
                    </div>
                    <button type="button" class="sabt-ticket-close" @click="closeModal" aria-label="بستن فرم">×</button>
                </header>
                <form class="tikcet-popup-Req sabt-ticket-form" @submit.prevent="submitTicket">
                    <div class="sabt-ticket-grid">
                        <label for="ticketReceiver">دریافت‌کننده
                            <select id="ticketReceiver" v-model="newTicket.receiver">
                                <option value="" disabled selected>انتخاب همکار…</option>
                                <option v-for="u in receivers" :key="u.username || u.value" :value="u.username || u.value">{{ u.name || u.label || u.username || u.value }}</option>
                            </select>
                        </label>
                        <label for="ticketCategory">دسته‌بندی
                            <select id="ticketCategory" v-model="newTicket.category_id"><option value="">عمومی</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name || c.title }}</option>
                            </select>
                        </label>
                        <label for="ticketPriority">اولویت
                            <select id="ticketPriority" v-model="newTicket.priority">
                                <option value="normal">عادی</option><option value="low">کم</option><option value="high">زیاد</option><option value="urgent">فوری</option>
                            </select>
                        </label>
                        <label for="ticketTitleAdmin" class="sabt-ticket-span-2">عنوان تیکت
                            <input type="text" id="ticketTitleAdmin" v-model="newTicket.title" required maxlength="180" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="مثلاً مشکل در ثبت ورود">
                        </label>
                        <label for="ticketDescription" class="sabt-ticket-span-2">توضیحات
                            <textarea id="ticketDescription" v-model="newTicket.body" rows="4" required maxlength="4000" placeholder="چه اتفاقی افتاده و چه کمکی نیاز دارید؟"></textarea>
                            <small>حداکثر ۴۰۰۰ نویسه</small>
                        </label>
                    </div>
                    <div class="modal-buttons sabt-ticket-actions">
                        <button type="button" class="btn-cancel" @click="closeModal">انصراف</button>
                        <button type="submit" class="btn-confirm" :disabled="submitting">{{ submitting ? 'در حال ثبت…' : 'ثبت تیکت' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <div v-if="deleteConfirm.open" id="confirmDeleteModalHazf" class="modal-hazf">
        <div class="modal-content-hazfPopup">
            <h2>آیا مطمئن به حذف تیکت هستید؟</h2>
            <button id="confirmDeleteBtnTicket" class="ok-btn" @click="confirmDelete">تایید</button>
            <button class="cancel-btn-ticket-karbar" @click="cancelDelete">انصراف</button>
        </div>
    </div>
</template>

<style scoped>
.helpdesk-ticket-item { padding: .75rem .9rem; border-bottom: 1px solid rgb(15 23 42 / .07); cursor: pointer; transition: background .15s; }
.helpdesk-ticket-item:hover { background: rgb(14 165 233 / .05); }
.helpdesk-ticket-item.is-selected { background: rgb(20 184 166 / .08); }
.helpdesk-ticket-item__head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.helpdesk-ticket-item__subject { font-weight: 700; font-size: .85rem; }
.helpdesk-ticket-item__id { color: #94a3b8; font-size: .72rem; }
.helpdesk-ticket-item__meta { display: flex; justify-content: space-between; color: #64748b; font-size: .72rem; margin-top: .25rem; }
.helpdesk-ticket-item__status { display: inline-block; margin-top: .35rem; padding: .15rem .55rem; border-radius: 999px; font-size: .7rem; background: rgb(100 116 139 / .12); color: #475569; }
.ticket-context-head { display: flex; justify-content: space-between; align-items: flex-start; padding: 1rem; border-bottom: 1px solid rgb(15 23 42 / .08); }
.ticket-context-head h3 { margin: 0; font-size: 1rem; font-weight: 800; }
.ticket-context-head small { color: #64748b; font-size: .72rem; }
.ticket-context-delete { border: 0; background: transparent; cursor: pointer; font-size: 1rem; }
.ticket-context-body { padding: 1rem; max-height: calc(100vh - 420px); min-height: 200px; overflow-y: auto; }
.ticket-message { padding: .75rem; border-radius: 10px; background: rgb(14 165 233 / .06); margin-bottom: .6rem; }
.ticket-message strong { display: block; font-size: .78rem; color: #0f172a; }
.ticket-message time { font-size: .68rem; color: #94a3b8; }
.ticket-message p { margin: .4rem 0 0; font-size: .83rem; line-height: 1.7; }
.ticket-message[data-author="support"] { background: rgb(16 185 129 / .08); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
.ticket-pagination { display: flex; gap: .25rem; justify-content: center; padding: .75rem; }
.ticket-pagination button { padding: .35rem .7rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; font: inherit; font-size: .75rem; cursor: pointer; }
.ticket-pagination button.active { background: #14b8a6; color: #fff; border-color: #14b8a6; }
</style>
