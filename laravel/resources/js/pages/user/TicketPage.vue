<script setup>
/**
 * Tickets — the Vue equivalent of the legacy ticket modal (`#ticketModal`),
 * the ticket status table (`#ticket-status-table`) and the conversation popup
 * (`#MoshahedePopupbox`).
 *
 * This page uses the normalized `/api/tickets` surface (not the legacy
 * `ticket_table` generation, whose handlers are 410 stubs):
 *   * `GET  /api/tickets` — the paginated list for the signed-in user;
 *   * `POST /api/tickets` — open a conversation (`recipient_username`,
 *     `subject`, `body`, `priority`, `category_id`);
 *   * `GET  /api/tickets/categories` and `GET /api/tickets/users` — the
 *     category and recipient pickers;
 *   * `GET  /api/tickets/{id}` — one conversation with its messages;
 *   * `POST /api/tickets/{id}/messages` — reply (`body`, `visibility`).
 *
 * The recipient picker is the master-admin list the endpoint returns; the
 * priority allow-list is the one the server validates against.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toPersianDigits } from '@/utils/numbers';

const auth = useAuthStore();

const VIEW_ICON_URL = '/images/view.png';
const SEND_ICON_URL = '/images/send.png';

const PRIORITIES = [
    { value: 'low', label: 'کم' },
    { value: 'normal', label: 'عادی' },
    { value: 'high', label: 'زیاد' },
    { value: 'urgent', label: 'فوری' },
];

const loading = ref(true);
const error = ref('');
const notice = ref('');

const tickets = ref([]);
const categories = ref([]);
const recipients = ref([]);

const showForm = ref(false);
const formLoading = ref(false);
const formError = ref('');

const formRecipient = ref('');
const formSubject = ref('');
const formBody = ref('');
const formPriority = ref('normal');
const formCategory = ref('');

const activeTicket = ref(null);
const activeLoading = ref(false);
const replyText = ref('');
const replyLoading = ref(false);

const canSubmit = computed(() => (
    formRecipient.value !== ''
    && formSubject.value.trim() !== ''
    && formBody.value.trim() !== ''
));

function showNotice(message) {
    notice.value = message;

    window.setTimeout(() => {
        notice.value = '';
    }, 4000);
}

function priorityLabel(value) {
    return PRIORITIES.find((item) => item.value === value)?.label || value;
}

async function loadTickets() {
    const response = await api.get('/tickets');
    tickets.value = Array.isArray(response?.items) ? response.items : [];
}

async function loadCategories() {
    const response = await api.get('/tickets/categories');
    categories.value = Array.isArray(response?.items) ? response.items : [];
}

async function loadRecipients() {
    const response = await api.get('/tickets/users');
    recipients.value = Array.isArray(response?.items) ? response.items : [];
}

function openForm() {
    showForm.value = true;
    formError.value = '';
}

function closeForm() {
    showForm.value = false;
    formError.value = '';
}

async function submitTicket() {
    if (!canSubmit.value || formLoading.value) {
        return;
    }

    formLoading.value = true;
    formError.value = '';

    const payload = {
        recipient_username: formRecipient.value,
        subject: formSubject.value,
        body: formBody.value,
        priority: formPriority.value,
    };

    if (formCategory.value !== '') {
        payload.category_id = Number(formCategory.value);
    }

    try {
        await api.post('/tickets', payload);
        showNotice('تیکت شما با موفقیت ثبت شد.');
        formRecipient.value = '';
        formSubject.value = '';
        formBody.value = '';
        formPriority.value = 'normal';
        formCategory.value = '';
        showForm.value = false;
        await loadTickets();
    } catch (failure) {
        formError.value = failure?.message || 'خطا در ثبت تیکت.';
    } finally {
        formLoading.value = false;
    }
}

async function openTicket(ticket) {
    activeLoading.value = true;
    activeTicket.value = null;
    replyText.value = '';

    try {
        activeTicket.value = await api.get(`/tickets/${ticket.id}`);
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت جزئیات تیکت.';
    } finally {
        activeLoading.value = false;
    }
}

function closeTicket() {
    activeTicket.value = null;
    replyText.value = '';
}

async function sendReply() {
    if (!activeTicket.value || replyText.value.trim() === '' || replyLoading.value) {
        return;
    }

    replyLoading.value = true;

    try {
        activeTicket.value = await api.post(
            `/tickets/${activeTicket.value.id}/messages`,
            { body: replyText.value, visibility: 'public' },
        );
        replyText.value = '';
    } catch (failure) {
        error.value = failure?.message || 'خطا در ارسال پاسخ.';
    } finally {
        replyLoading.value = false;
    }
}

onMounted(async () => {
    try {
        await Promise.all([loadTickets(), loadCategories(), loadRecipients()]);
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اطلاعات تیکت‌ها.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="tickets-page" aria-label="تیکت‌ها">
        <!-- فرم ثبت تیکت -->
        <div v-if="showForm" class="user-ticket-create-dialog-wrap">
            <div class="user-ticket-create-backdrop" @click="closeForm"></div>
            <section class="user-ticket-create-dialog" role="dialog" aria-modal="true" aria-labelledby="ticketCreateHeading">
                <header class="user-ticket-create-head">
                    <div>
                        <span class="ticket-panel-kicker">درخواست جدید</span>
                        <h2 id="ticketCreateHeading">چطور می‌توانیم کمک کنیم؟</h2>
                        <p>موضوع را کوتاه و روشن بنویسید تا سریع‌تر پاسخ بگیرید.</p>
                    </div>
                    <button type="button" class="user-ticket-close" aria-label="بستن فرم" @click="closeForm">×</button>
                </header>
                <div class="user-ticket-steps" aria-label="مراحل ثبت درخواست">
                    <span class="is-active">۱ دسته‌بندی</span>
                    <span>۲ توضیح مشکل</span>
                    <span>۳ ارسال</span>
                </div>
                <form id="ticketForm" class="user-ticket-create-form" @submit.prevent="submitTicket">
                    <div class="user-ticket-form-grid">
                        <label for="ticketCategory">دسته‌بندی درخواست
                            <select id="ticketCategory" v-model="formCategory" name="category_id">
                                <option value="">عمومی</option>
                                <option v-for="category in categories" :key="category.id" :value="category.id">
                                    {{ category.name }}
                                </option>
                            </select>
                        </label>
                        <label for="ticketPriority">اهمیت
                            <select id="ticketPriority" v-model="formPriority" name="priority">
                                <option v-for="priority in PRIORITIES" :key="priority.value" :value="priority.value">
                                    {{ priority.label }}
                                </option>
                            </select>
                        </label>
                        <label for="ticketReceiver">ارسال برای
                            <select id="ticketReceiver" v-model="formRecipient" name="ticketReceiver" required>
                                <option value="" disabled selected>مدیر اصلی سامانه</option>
                                <option v-for="recipient in recipients" :key="recipient.username" :value="recipient.username">
                                    {{ recipient.name || recipient.username }}
                                </option>
                            </select>
                            <small>تمام درخواست‌های پشتیبانی مستقیماً برای مدیر اصلی سامانه ارسال می‌شوند.</small>
                        </label>
                        <label for="ticketCreateTitle" class="span-2">موضوع درخواست
                            <input v-model="formSubject" type="text" id="ticketCreateTitle" name="ticketTitle" required maxlength="180" autocomplete="off" placeholder="مثلاً مشکل در ثبت ورود">
                        </label>
                        <label for="ticketDescription" class="span-2">شرح درخواست
                            <textarea v-model="formBody" id="ticketDescription" name="ticketDescription" rows="6" required maxlength="4000" placeholder="چه اتفاقی افتاده و چه کمکی نیاز دارید؟"></textarea>
                            <small>حداکثر ۴۰۰۰ نویسه</small>
                        </label>
                    </div>
                    <div class="user-ticket-create-error" id="ticketCreateError" role="alert">
                        <span v-if="formError">{{ formError }}</span>
                    </div>
                    <footer>
                        <button type="button" class="user-ticket-cancel" @click="closeForm">انصراف</button>
                        <button type="submit" class="user-ticket-submit" :disabled="!canSubmit || formLoading">
                            {{ formLoading ? 'در حال ثبت…' : 'ثبت درخواست' }} <span aria-hidden="true">←</span>
                        </button>
                    </footer>
                </form>
            </section>
        </div>

        <!-- لیست تیکت‌ها -->
        <div class="ticket-status-box ticket-popup-box">
            <h3>وضعیت تیکت‌ها</h3>
            <div class="ticket-container">
                <div v-if="loading" class="user-support-skeleton"></div>
                <div v-else-if="tickets.length === 0" class="no-ticket-message">تیکتی موجود نیست</div>
                <div
                    v-for="ticket in tickets"
                    :key="ticket.id"
                    class="mobile-box"
                >
                    <div class="onvaneticket">
                        <div class="subjectTicket">عنوان تیکت</div>
                        <div class="contentTicket">{{ ticket.subject }}</div>
                    </div>
                    <div class="tatikhticket">
                        <div class="subjectTarikh">تاریخ تیکت</div>
                        <div class="contentTarikh">{{ toPersianDigits(ticket.created_at || '') }}</div>
                    </div>
                    <div class="tatikhticket">
                        <div class="subjectTarikh">دریافت کننده</div>
                        <div class="contentTarikh">{{ ticket.recipient_username || 'مدیر اصلی' }}</div>
                    </div>
                    <div class="tatikhticket">
                        <div class="subjectTarikh">وضعیت</div>
                        <div class="contentTarikh">{{ ticket.status }}</div>
                    </div>
                    <div class="tatikhticket">
                        <div class="subjectTarikh">توضیحات</div>
                        <div class="contentTarikh">{{ ticket.body || ticket.last_message_preview || '—' }}</div>
                    </div>
                    <div class="virayeshVAhazf">
                        <button type="button" class="view-btn" @click="openTicket(ticket)">
                            <img :src="VIEW_ICON_URL" alt="مشاهده">
                            <span class="tooltip-text-table-view">مشاهده</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="Jadvale-ticket">
                <table id="ticket-status-table-popup">
                    <thead>
                        <tr>
                            <th class="virayesh-ticket">ویرایش</th>
                            <th class="tozihat-ticket">توضیحات</th>
                            <th class="vazeiyat-ticket">وضعیت</th>
                            <th class="daryaft-ticket">دریافت کننده</th>
                            <th class="tarikh-ticket">تاریخ درخواست</th>
                            <th class="noe-ticket">عنوان درخواست</th>
                            <th class="radif-ticket">ردیف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(ticket, index) in tickets" :key="ticket.id">
                            <td class="virayesh-ticket">
                                <button type="button" class="view-btn" @click="openTicket(ticket)">
                                    <img :src="VIEW_ICON_URL" alt="مشاهده">
                                    <span class="tooltip-text-table-view">مشاهده</span>
                                </button>
                            </td>
                            <td class="tozihat-ticket">{{ ticket.body || ticket.last_message_preview || '—' }}</td>
                            <td class="vazeiyat-ticket">
                                <span class="ticket-status">{{ ticket.status }}</span>
                            </td>
                            <td class="daryaft-ticket">{{ ticket.recipient_username || 'مدیر اصلی' }}</td>
                            <td class="tarikh-ticket">{{ toPersianDigits(ticket.created_at || '') }}</td>
                            <td class="noe-ticket">{{ ticket.subject }}</td>
                            <td class="radif-ticket">{{ toPersianDigits(String(index + 1)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- گفت‌وگوی تیکت -->
        <div v-if="activeTicket" class="MoshahedeOverlay ticket-conversation-overlay user-ticketing-legacy" @click="closeTicket"></div>
        <div v-if="activeTicket" class="MoshahdePopup ticket-conversation-dialog user-ticketing-legacy" role="dialog" aria-modal="true" aria-labelledby="ticketConversationTitle">
            <div class="ticket-conversation-head">
                <div>
                    <p id="ticketConversationTitle">گفت‌وگوی تیکت</p>
                    <span>تاریخچه مکالمه</span>
                </div>
                <button type="button" class="ticket-conversation-close" aria-label="بستن" @click="closeTicket">×</button>
            </div>
            <div class="bishtar">
                <div class="kadr-matn">
                    <div
                        v-for="message in activeTicket.messages"
                        :key="message.id"
                        :class="message.author_username === auth.username ? 'matn1' : 'matn2'"
                    >
                        <p>{{ message.body }}</p>
                        <div class="zaman">{{ toPersianDigits(message.created_at || '') }}</div>
                    </div>
                </div>
                <hr class="line-separator">
                <div class="ticket-reply-bar">
                    <input
                        v-model="replyText"
                        type="text"
                        id="matnErsali"
                        name="matnErsali"
                        placeholder="پاسخ خود را بنویسید…"
                        aria-label="متن پاسخ"
                        maxlength="4000"
                        autocomplete="off"
                    >
                    <button type="button" class="ticket-reply-send ersal-icon" aria-label="ارسال پاسخ" :disabled="replyText.trim() === '' || replyLoading" @click="sendReply">
                        <img :src="SEND_ICON_URL" alt="">
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
