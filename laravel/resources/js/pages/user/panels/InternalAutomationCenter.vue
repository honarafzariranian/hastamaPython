<script setup>
/**
 * The internal-automation centre — the Vue equivalent of
 * `#internalAutomationCenter` and `#internalAutomationCreateModal` in
 * `user-panel.html`.  Behaviour is ported from `internal-automation.js`:
 *   * `GET    /api/automation`                     — the caller's conversations;
 *   * `GET    /api/automation/{id}`                — one conversation + messages;
 *   * `POST   /api/automation`                     — create a conversation;
 *   * `POST   /api/automation/{id}/messages`        — send a message;
 *   * `POST   /api/automation/{id}/attachments`     — attach a file to a message;
 *   * `POST   /api/automation/{id}/complete`        — close the conversation;
 *   * `POST   /api/automation/{id}/reopen-request`  — ask to reopen;
 *   * `POST   /api/automation/{id}/reopen-request/{requestId}/approve`;
 *   * `DELETE /api/automation/{id}`                — delete the conversation.
 * The participant picker is `GET /api/tickets/users` (master admins), the same
 * list the legacy create form loaded.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const emit = defineEmits(['close']);

const auth = useAuthStore();

const ACCEPT = '.pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx,.xls,.xlsx';

const listLoading = ref(true);
const listError = ref('');
const conversations = ref([]);

const conversation = ref(null);
const conversationLoading = ref(false);
const conversationError = ref('');

const messagesBox = ref(null);

const composer = ref({ body: '' });
const composerFile = ref(null);
const composerStatus = ref('');
const sending = ref(false);

const createOpen = ref(false);
const createError = ref('');
const createLoading = ref(false);
const createUsers = ref([]);
const createForm = ref({ subject: '', participant: '', body: '' });
const creating = ref(false);

const currentUsername = () => String(auth.user?.username || '').trim();

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
        return '—';
    }
}

function localizedError(error) {
    const message = String(error?.message || '').trim();

    if (/not found|پیدا نشد|گفتگو پیدا نشد/i.test(message)) {
        return 'این گفتگو دیگر در دسترس نیست یا حذف شده است.';
    }

    if (/unauthorized|forbidden|وارد سامانه/i.test(message)) {
        return 'برای مشاهدهٔ این بخش باید دوباره وارد سامانه شوید.';
    }

    return message || 'دریافت اطلاعات گفتگوها ناموفق بود.';
}

async function loadList() {
    listLoading.value = true;
    listError.value = '';

    try {
        const data = await api.get('/automation');
        conversations.value = Array.isArray(data?.items) ? data.items : [];
    } catch (error) {
        if (error?.status === 404) {
            conversations.value = [];
        } else {
            listError.value = localizedError(error);
        }
    } finally {
        listLoading.value = false;
    }
}

async function scrollMessages() {
    await Promise.resolve();

    if (messagesBox.value) {
        messagesBox.value.scrollTop = messagesBox.value.scrollHeight;
    }
}

async function openConversation(id) {
    conversationLoading.value = true;
    conversationError.value = '';

    try {
        conversation.value = await api.get(`/automation/${id}`);
        await scrollMessages();
    } catch (error) {
        conversationError.value = localizedError(error);
        conversation.value = null;
    } finally {
        conversationLoading.value = false;
    }
}

async function sendMessage() {
    if (!conversation.value || sending.value) {
        return;
    }

    const body = composer.value.body.trim();

    if (body === '') {
        return;
    }

    sending.value = true;
    composerStatus.value = 'در حال ارسال…';

    try {
        const result = await api.post(`/automation/${conversation.value.id}/messages`, { body });

        if (composerFile.value) {
            const upload = new FormData();
            upload.append('file', composerFile.value);
            upload.append('message_id', result?.messages?.at(-1)?.id ?? '');
            await api.post(`/automation/${conversation.value.id}/attachments`, upload);
        }

        composer.value.body = '';
        composerFile.value = null;
        composerStatus.value = '';
        await openConversation(conversation.value.id);
        await loadList();
    } catch (error) {
        composerStatus.value = localizedError(error);
    } finally {
        sending.value = false;
    }
}

async function conversationAction(name) {
    if (!conversation.value || sending.value) {
        return;
    }

    try {
        await api.post(`/automation/${conversation.value.id}/${name}`);
        await openConversation(conversation.value.id);
        await loadList();
    } catch (error) {
        conversationError.value = localizedError(error);
    }
}

async function approveReopen(requestId) {
    if (!conversation.value) {
        return;
    }

    try {
        await api.post(`/automation/${conversation.value.id}/reopen-request/${requestId}/approve`);
        await openConversation(conversation.value.id);
    } catch (error) {
        conversationError.value = localizedError(error);
    }
}

async function deleteConversation() {
    if (!conversation.value) {
        return;
    }

    if (!window.confirm('آیا از حذف کامل این گفتگو و پیام‌های آن مطمئن هستید؟ این عملیات قابل بازگشت نیست.')) {
        return;
    }

    try {
        await api.delete(`/automation/${conversation.value.id}`);
        conversation.value = null;
        await loadList();
    } catch (error) {
        conversationError.value = localizedError(error);
    }
}

async function openCreate() {
    createOpen.value = true;
    createError.value = '';
    createForm.value = { subject: '', participant: '', body: '' };
    createLoading.value = true;

    try {
        const data = await api.get('/tickets/users');
        createUsers.value = Array.isArray(data?.items) ? data.items : [];
    } catch (error) {
        createError.value = localizedError(error);
    } finally {
        createLoading.value = false;
    }
}

function closeCreate() {
    createOpen.value = false;
    createError.value = '';
    createForm.value = { subject: '', participant: '', body: '' };
}

async function submitCreate() {
    if (creating.value) {
        return;
    }

    if (createForm.value.subject.trim() === ''
        || createForm.value.participant === ''
        || createForm.value.body.trim() === '') {
        createError.value = 'همه‌ی فیلدها الزامی است.';
        return;
    }

    creating.value = true;
    createError.value = '';

    try {
        const result = await api.post('/automation', {
            subject: createForm.value.subject.trim(),
            participants: [createForm.value.participant],
            body: createForm.value.body.trim(),
        });

        closeCreate();
        await loadList();

        if (result?.id || result?.conversation_id) {
            await openConversation(result.id || result.conversation_id);
        }
    } catch (error) {
        createError.value = localizedError(error);
    } finally {
        creating.value = false;
    }
}

function isOwn(message) {
    return String(message?.author_username || '').trim() === currentUsername();
}

function attachmentsFor(message) {
    return (conversation.value?.attachments || []).filter(
        (file) => Number(file.message_id) === Number(message.id),
    );
}

function ownPendingReopen() {
    return (conversation.value?.reopen_requests || []).find(
        (item) => item.status === 'pending' && String(item.requester).trim() === currentUsername(),
    );
}

function pendingReopen() {
    return (conversation.value?.reopen_requests || []).find(
        (item) => item.status === 'pending' && String(item.requester).trim() !== currentUsername(),
    );
}

onMounted(loadList);
</script>

<template>
    <div class="internal-automation-center" id="internalAutomationCenter" aria-hidden="false">
        <div class="internal-automation-backdrop" @click="emit('close')"></div>
        <section class="internal-automation-dialog" role="dialog" aria-modal="true" aria-labelledby="internalAutomationTitle">
            <header class="internal-automation-header">
                <div>
                    <span class="ticket-panel-kicker">ارتباطات سازمانی</span>
                    <h2 id="internalAutomationTitle">اتوماسیون داخلی</h2>
                    <p>گفت‌وگوهای کاری شما با همکاران، جدا از تیکتینگ فنی سامانه</p>
                </div>
                <div class="internal-automation-actions">
                    <button type="button" class="internal-automation-new" data-action="new-internal-conversation" @click="openCreate">+ گفت‌وگوی جدید</button>
                    <button type="button" class="internal-automation-close" data-action="close-internal-automation" aria-label="بستن" @click="emit('close')">×</button>
                </div>
            </header>
            <div class="internal-automation-layout">
                <aside class="internal-automation-list-pane">
                    <div class="internal-automation-list-head">
                        <strong>گفت‌وگوهای من</strong>
                        <button type="button" data-action="refresh-internal-automation" aria-label="تازه‌سازی" @click="loadList">↻</button>
                    </div>
                    <div id="internalAutomationList" class="internal-automation-list" aria-live="polite">
                        <div v-if="listLoading" class="internal-automation-loading">در حال دریافت گفتگوها…</div>
                        <div v-else-if="listError" class="internal-automation-error">{{ listError }}</div>
                        <div v-else-if="conversations.length === 0" class="internal-automation-list-empty">
                            <span class="internal-automation-list-empty-icon">✦</span>
                            <strong>هنوز گفت‌وگویی ایجاد نکرده‌اید</strong>
                            <p>برای شروع ارتباط با همکاران، روی «گفت‌وگوی جدید» بزنید.</p>
                            <button type="button" data-action="new-internal-conversation" @click="openCreate">+ شروع گفت‌وگو</button>
                        </div>
                        <template v-else>
                        <button
                            v-for="item in conversations"
                            :key="item.id"
                            type="button"
                            class="internal-automation-item"
                            :class="{ 'is-active': conversation && Number(conversation.id) === Number(item.id) }"
                            :data-conversation-id="item.id"
                            @click="openConversation(item.id)"
                        >
                            <strong>{{ item.subject }}</strong>
                            <span>{{ fa(item.participant_count) }} همکار · {{ fa(item.message_count) }} پیام</span>
                            <time>{{ formatDate(item.updated_at) }}</time>
                        </button>
                        </template>
                    </div>
                </aside>
                <section id="internalAutomationConversation" class="internal-automation-conversation">
                    <div v-if="conversationLoading" class="internal-automation-empty"><span>✦</span><h3>در حال دریافت…</h3></div>
                    <div v-else-if="conversationError" class="internal-automation-error">{{ conversationError }}</div>
                    <div v-else-if="!conversation" class="internal-automation-empty">
                        <span>✦</span>
                        <h3>یک گفتگو را انتخاب کنید</h3>
                        <p>برای شروع، گفت‌وگوی جدیدی با همکاران خود بسازید.</p>
                    </div>
                    <template v-else>
                        <header class="internal-automation-conversation-head">
                            <div>
                                <span>{{ conversation.status === 'completed' ? 'گفت‌وگو پایان یافته' : 'گفت‌وگوی سازمانی' }}</span>
                                <h3>{{ conversation.subject }}</h3>
                            </div>
                            <div class="internal-automation-conversation-tools">
                                <small>{{ fa((conversation.messages || []).length) }} پیام</small>
                                <template v-if="conversation.status === 'completed'">
                                    <button
                                        v-if="pendingReopen()"
                                        type="button"
                                        class="internal-automation-reopen"
                                        data-action="approve-internal-reopen"
                                        @click="approveReopen(pendingReopen().id)"
                                    >تأیید شروع مجدد</button>
                                    <button
                                        v-else
                                        type="button"
                                        class="internal-automation-reopen"
                                        data-action="request-internal-reopen"
                                        :disabled="!!ownPendingReopen()"
                                        @click="conversationAction('reopen-request')"
                                    >{{ ownPendingReopen() ? 'در انتظار تأیید طرف مقابل' : 'درخواست شروع مجدد' }}</button>
                                </template>
                                <button
                                    v-else
                                    type="button"
                                    class="internal-automation-complete"
                                    data-action="complete-internal-conversation"
                                    @click="conversationAction('complete')"
                                >اتمام گفتگو</button>
                                <button type="button" class="internal-automation-delete" data-action="delete-internal-conversation" title="حذف گفتگو" @click="deleteConversation">حذف گفتگو</button>
                            </div>
                        </header>
                        <div class="internal-automation-messages" ref="messagesBox">
                            <div v-if="(conversation.messages || []).length === 0" class="internal-automation-list-empty">هنوز پیامی ثبت نشده است.</div>
                            <article
                                v-for="message in conversation.messages"
                                :key="message.id"
                                class="internal-automation-message"
                                :class="isOwn(message) ? 'is-own' : 'is-other'"
                            >
                                <div>
                                    <strong>{{ message.author_username }}</strong>
                                    <time>{{ formatDate(message.created_at) }}</time>
                                </div>
                                <p>{{ message.body }}</p>
                                <a
                                    v-for="file in attachmentsFor(message)"
                                    :key="file.id"
                                    class="internal-automation-file"
                                    target="_blank"
                                    rel="noopener"
                                    :href="`/api/automation/${conversation.id}/attachments/${file.id}`"
                                >📎 {{ file.original_name }}</a>
                            </article>
                        </div>
                        <div v-if="conversation.status === 'completed'" class="internal-automation-completed-note">این گفتگو به پایان رسیده و برای ارسال پیام باید دوباره فعال شود.</div>
                        <form v-else class="internal-automation-composer" @submit.prevent="sendMessage">
                            <textarea v-model="composer.body" name="body" maxlength="4000" required placeholder="پیام خود را بنویسید…"></textarea>
                            <div>
                                <label class="internal-automation-file-picker">
                                    <span class="internal-automation-file-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="m16.5 6.5-7.8 7.8a3.2 3.2 0 0 0 4.5 4.5l7-7a4.7 4.7 0 0 0-6.7-6.7l-7.4 7.4a6.2 6.2 0 0 0 8.8 8.8l6.1-6.1"></path></svg></span>
                                    <span>فایل</span>
                                    <input type="file" name="file" :accept="ACCEPT" @change="composerFile = $event.target.files[0] || null">
                                </label>
                                <button type="submit" :disabled="sending">ارسال پیام</button>
                                <span class="internal-automation-status">{{ composerStatus }}</span>
                            </div>
                        </form>
                    </template>
                </section>
            </div>
        </section>
    </div>

    <div v-if="createOpen" id="internalAutomationCreateModal" class="internal-automation-create-modal" aria-hidden="false">
        <div class="internal-automation-create-backdrop" data-action="close-internal-automation-create" @click="closeCreate"></div>
        <section class="internal-automation-create-dialog" role="dialog" aria-modal="true" aria-labelledby="internalAutomationCreateTitle">
            <header>
                <div>
                    <span class="ticket-panel-kicker">ارتباطات سازمانی</span>
                    <h2 id="internalAutomationCreateTitle">شروع گفت‌وگوی جدید</h2>
                    <p>همکاران و پیام آغازین گفتگو را مشخص کنید.</p>
                </div>
                <button type="button" class="internal-automation-close" data-action="close-internal-automation-create" aria-label="بستن" @click="closeCreate">×</button>
            </header>
            <form id="internalAutomationCreateForm" @submit.prevent="submitCreate">
                <label>موضوع گفتگو<input v-model="createForm.subject" type="text" name="subject" maxlength="180" required placeholder="مثلاً هماهنگی برنامه کاری"></label>
                <label>انتخاب همکار<select v-model="createForm.participant" name="participant" required>
                    <option value="">{{ createLoading ? 'در حال دریافت کاربران…' : 'انتخاب همکار' }}</option>
                    <option v-for="user in createUsers" :key="user.username" :value="user.username">{{ user.name ? `${user.name} (${user.username})` : user.username }}</option>
                </select></label>
                <label>پیام آغازین<textarea v-model="createForm.body" name="body" maxlength="4000" rows="5" required placeholder="پیام خود را بنویسید…"></textarea></label>
                <p class="internal-automation-create-error" role="alert">{{ createError }}</p>
                <footer>
                    <button type="button" class="internal-automation-secondary" data-action="close-internal-automation-create" @click="closeCreate">انصراف</button>
                    <button type="submit" class="internal-automation-primary" :disabled="creating">ایجاد گفتگو</button>
                </footer>
            </form>
        </section>
    </div>
</template>

