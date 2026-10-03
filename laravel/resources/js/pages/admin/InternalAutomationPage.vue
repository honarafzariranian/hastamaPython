<script setup>
/**
 * Internal automation supervision — verbatim port of `internalAutomationAdminBox`.
 *
 * Tabs:
 *   internalAutomationMonitorTab  — list of conversations; admin sees members/message
 *                                    count/last activity (content is hidden per policy)
 *   internalAutomationCreateTab   — start a new conversation with a user + first message;
 *                                    sidebar shows open conversations.
 *
 * Source: /api/automation (already ported — see routes/automation.php).
 */
import { onMounted, onBeforeUnmount, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const activeTab = ref('internalAutomationMonitorTab');
const error = ref('');
const notice = ref('');

/* Monitor tab */
const monitorLoading = ref(true);
const conversations = ref([]);

/* Create tab */
const users = ref([]);
const form = reactive({ subject: '', participant: '', body: '' });
const submitting = ref(false);
const formStatus = ref('');
const openConversations = ref([]);
const openLoading = ref(false);
const createdConversation = ref(null);
const reply = reactive({ body: '', sending: false });

function switchTab(tabId) { activeTab.value = tabId; }

async function loadMonitor() {
    monitorLoading.value = true; error.value = '';
    try {
        const r = await api.get('/api/automation');
        conversations.value = Array.isArray(r) ? r : (r.data || r.conversations || []);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت گفتگوها.';
        conversations.value = [];
    } finally { monitorLoading.value = false; }
}

async function loadUsers() {
    try {
        const r = await api.get('/get_users', { baseURL: '' });
        users.value = r.users || [];
    } catch { users.value = []; }
}
async function loadOpenConversations() {
    openLoading.value = true;
    try {
        const r = await api.get('/api/automation');
        openConversations.value = (Array.isArray(r) ? r : (r.data || []))
            .filter((c) => c.status !== 'completed' && c.status !== 'closed');
    } catch { openConversations.value = []; }
    finally { openLoading.value = false; }
}

async function submitForm(event) {
    event.preventDefault();
    if (!form.subject || !form.participant || !form.body) {
        formStatus.value = 'لطفاً تمام فیلدها را پر کنید.'; return;
    }
    submitting.value = true; formStatus.value = ''; error.value = '';
    try {
        const r = await api.post('/api/automation', {
            subject: form.subject,
            participant: form.participant,
            participant_username: form.participant,
            body: form.body,
        });
        createdConversation.value = r.conversation || r.data || r;
        form.subject = ''; form.participant = ''; form.body = '';
        formStatus.value = 'گفتگو با موفقیت ایجاد شد.';
        await Promise.all([loadMonitor(), loadOpenConversations()]);
    } catch (failure) {
        formStatus.value = failure.apiFailure?.message || failure.message || 'خطا در ایجاد گفتگو.';
    } finally { submitting.value = false; }
}

async function openConversation(c) {
    try {
        const r = await api.get(`/api/automation/${c.id}`);
        createdConversation.value = r.conversation || r.data || r;
    } catch { /* ignore */ }
}

async function sendAdminMessage(cid) {
    if (!reply.body.trim() || !cid) return;
    reply.sending = true;
    try {
        const r = await api.post(`/api/automation/${cid}/messages`, { body: reply.body });
        await openConversation({ id: cid });
        if (r?.conversation || r?.data) createdConversation.value = r.conversation || r.data;
        reply.body = '';
        await loadMonitor();
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در ارسال پیام.';
    } finally { reply.sending = false; }
}

async function completeConversation(cid) {
    try { await api.post(`/api/automation/${cid}/complete`);
        createdConversation.value = null; await Promise.all([loadMonitor(), loadOpenConversations()]);
    } catch (failure) { error.value = failure.apiFailure?.message || failure.message || 'خطا در بستن گفتگو.'; }
}
async function deleteConversation(cid) {
    if (!confirm('آیا از حذف این گفتگو مطمئن هستید؟')) return;
    try { await api.delete(`/api/automation/${cid}`);
        createdConversation.value = null; await Promise.all([loadMonitor(), loadOpenConversations()]);
    } catch (failure) { error.value = failure.apiFailure?.message || failure.message || 'خطا در حذف گفتگو.'; }
}

onMounted(() => { loadMonitor(); loadUsers(); loadOpenConversations(); });
</script>

<template>
    <header class="section-hero" style="--hero-accent:#087f72;--hero-accent-2:#1686b5;">
        <div class="section-hero__icon" aria-hidden="true">✦</div>
        <div class="section-hero__text">
            <h2>نظارت بر اتوماسیون داخلی</h2>
            <p>فهرست ارتباطات سازمانی بدون نمایش محتوای پیام‌ها</p>
        </div>
        <div class="section-hero__glow" aria-hidden="true"></div>
    </header>

    <div class="internal-automation-admin-content">
        <div class="internal-automation-admin-tabs" role="tablist" aria-label="بخش‌های اتوماسیون داخلی">
            <button type="button" class="internal-automation-admin-tab"
                :class="{ active: activeTab === 'internalAutomationMonitorTab' }" role="tab"
                :aria-selected="activeTab === 'internalAutomationMonitorTab'"
                aria-controls="internalAutomationMonitorTab"
                @click="switchTab('internalAutomationMonitorTab')">نظارت بر گفتگوها</button>
            <button type="button" class="internal-automation-admin-tab"
                :class="{ active: activeTab === 'internalAutomationCreateTab' }" role="tab"
                :aria-selected="activeTab === 'internalAutomationCreateTab'"
                aria-controls="internalAutomationCreateTab"
                @click="switchTab('internalAutomationCreateTab')">ایجاد گفت‌وگو</button>
        </div>

        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <section id="internalAutomationMonitorTab" class="internal-automation-admin-tab-content" :class="{ active: activeTab === 'internalAutomationMonitorTab' }" role="tabpanel">
            <div class="internal-automation-admin-note">برای حفظ حریم خصوصی، مدیر مجموعه فقط اعضا، تعداد پیام‌ها و زمان فعالیت را می‌بیند. محتوای پیام و فایل‌ها فقط برای اعضای گفتگو و مدیر اصلی قابل مشاهده است.</div>
            <div id="internalAutomationAdminList" class="internal-automation-admin-list">
                <div v-if="monitorLoading" class="ticket-loading-state">در حال دریافت گفتگوها…</div>
                <template v-else>
                    <article v-for="c in conversations" :key="c.id" class="internal-automation-admin-card" @click="openConversation(c); activeTab = 'internalAutomationCreateTab'">
                        <strong>{{ c.subject || 'گفتگو' }}</strong>
                        <span>اعضا: {{ Array.isArray(c.participants) ? c.participants.map(p => p.username || p.name).join('، ') : (c.participants || '—') }}</span>
                        <small>تعداد پیام: {{ toPersianDigits(c.message_count ?? c.messages_count ?? 0) }} · آخرین فعالیت: {{ toPersianDigits(c.last_activity_at || c.updated_at || c.created_at || '—') }}</small>
                        <em class="internal-automation-admin-status-badge" :data-status="c.status">{{ c.status || 'باز' }}</em>
                    </article>
                    <div v-if="conversations.length === 0" class="internal-automation-admin-detail-empty">گفتگویی یافت نشد.</div>
                </template>
            </div>
        </section>

        <section id="internalAutomationCreateTab" class="internal-automation-admin-tab-content" :class="{ active: activeTab === 'internalAutomationCreateTab' }" role="tabpanel" :hidden="activeTab !== 'internalAutomationCreateTab'">
            <form id="internalAutomationAdminCreateForm" class="internal-automation-admin-create-form" @submit="submitForm">
                <div class="internal-automation-admin-form-heading">
                    <span class="ticket-panel-kicker">ارتباط بین سازمانی</span>
                    <h3>شروع گفت‌وگوی جدید</h3>
                    <p>یک کاربر را انتخاب کنید و اولین پیام را برای او ارسال کنید.</p>
                </div>
                <label>موضوع گفتگو<input name="subject" type="text" maxlength="180" required v-model="form.subject" placeholder="موضوع گفتگو را وارد کنید"></label>
                <label>کاربر مقابل
                    <select name="participant" required v-model="form.participant">
                        <option value="">در حال دریافت کاربران…</option>
                        <option v-for="u in users" :key="u.username" :value="u.username">{{ u.name || u.username }}</option>
                    </select>
                </label>
                <label>پیام آغازین<textarea name="body" maxlength="4000" rows="5" required v-model="form.body" placeholder="پیام خود را بنویسید…"></textarea></label>
                <div class="internal-automation-admin-form-footer">
                    <button type="submit" class="internal-automation-admin-primary" :disabled="submitting">{{ submitting ? 'در حال ارسال…' : 'ایجاد گفتگو و ارسال پیام' }}</button>
                    <span class="internal-automation-admin-form-status" role="status">{{ formStatus }}</span>
                </div>

                <div v-if="createdConversation" class="internal-automation-admin-created-conversation" aria-live="polite">
                    <header>
                        <div>
                            <span class="ticket-panel-kicker">گفت‌وگوی فعال</span>
                            <h3>{{ createdConversation.subject || 'گفتگو' }}</h3>
                            <small>{{ Array.isArray(createdConversation.participants) ? createdConversation.participants.map(p => p.username || p.name).join('، ') : '' }}</small>
                        </div>
                        <div class="internal-automation-admin-created-tools">
                            <button type="button" class="internal-automation-admin-complete" @click="completeConversation(createdConversation.id)">بستن گفتگو</button>
                            <button type="button" class="internal-automation-admin-delete" @click="deleteConversation(createdConversation.id)">حذف</button>
                        </div>
                    </header>
                    <div class="internal-automation-admin-messages">
                        <article v-for="m in (createdConversation.messages || [])" :key="m.id" class="internal-automation-admin-message">
                            <div>
                                <strong>{{ m.author?.username || m.author?.name || 'کاربر' }}</strong>
                                <time>{{ toPersianDigits(m.created_at || '') }}</time>
                            </div>
                            <p>{{ m.body || m.content }}</p>
                        </article>
                    </div>
                    <div class="internal-automation-admin-composer">
                        <textarea v-model="reply.body" placeholder="پاسخ خود را بنویسید…" maxlength="4000"></textarea>
                        <div>
                            <button type="button" :disabled="reply.sending || !reply.body.trim()" @click="sendAdminMessage(createdConversation.id)">
                                {{ reply.sending ? 'در حال ارسال…' : 'ارسال پیام' }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <aside class="internal-automation-admin-open-pane">
                <header class="internal-automation-admin-open-heading">
                    <div><span class="ticket-panel-kicker">گفت‌وگوها</span><h3>ادامهٔ گفتگوها</h3></div>
                    <span id="internalAutomationAdminOpenCount">{{ toPersianDigits(openConversations.length) }}</span>
                </header>
                <div id="internalAutomationAdminOpenList" class="internal-automation-admin-open-list">
                    <div v-if="openLoading" class="internal-automation-admin-detail-empty">در حال دریافت گفتگوهای باز…</div>
                    <template v-else>
                        <article v-for="c in openConversations" :key="c.id" class="internal-automation-admin-open-card"
                            :class="{ 'is-completed': c.status === 'completed' }" @click="openConversation(c)">
                            <strong>{{ c.subject || 'گفتگو' }}</strong>
                            <span>{{ Array.isArray(c.participants) ? c.participants.map(p => p.username || p.name).join('، ') : '' }}</span>
                            <small>{{ toPersianDigits(c.updated_at || c.created_at || '') }}</small>
                            <em class="internal-automation-admin-status-badge">{{ c.status || 'باز' }}</em>
                        </article>
                        <div v-if="openConversations.length === 0" class="internal-automation-admin-detail-empty">گفتگوی بازی یافت نشد.</div>
                    </template>
                </div>
            </aside>
        </section>
    </div>
</template>
