<script setup>
/**
 * The “new ticket” modal — the Vue equivalent of `#ticketModal.user-ticket-create`
 * in `user-panel.html`.  Uses the normalized `/api/tickets` surface:
 *   * `GET  /api/tickets/categories` — the category picker;
 *   * `GET  /api/tickets/users`      — the master-admin recipients;
 *   * `POST /api/tickets`            — open the conversation.
 * Every request is routed to the primary system administrator, exactly as the
 * legacy copy under the recipient field said.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';

const emit = defineEmits(['close', 'created']);

const PRIORITIES = [
    { value: 'normal', label: 'عادی' },
    { value: 'low', label: 'کم' },
    { value: 'high', label: 'زیاد' },
    { value: 'urgent', label: 'فوری' },
];

const loading = ref(true);
const formError = ref('');
const submitting = ref(false);

const categories = ref([]);
const recipients = ref([]);

const form = ref({
    category_id: '',
    priority: 'normal',
    subject: '',
    body: '',
    attachments: null,
});

async function loadOptions() {
    try {
        const [categoriesResponse, recipientsResponse] = await Promise.all([
            api.get('/tickets/categories'),
            api.get('/tickets/users'),
        ]);

        categories.value = Array.isArray(categoriesResponse?.items) ? categoriesResponse.items : [];
        recipients.value = Array.isArray(recipientsResponse?.items) ? recipientsResponse.items : [];
    } catch (failure) {
        formError.value = failure?.message || 'دریافت اطلاعات فرم ناموفق بود.';
    } finally {
        loading.value = false;
    }
}

async function submit() {
    if (submitting.value) {
        return;
    }

    if (form.value.subject.trim() === '' || form.value.body.trim() === '') {
        formError.value = 'موضوع و شرح درخواست الزامی است.';
        return;
    }

    const recipient = recipients.value[0]?.username;

    if (!recipient) {
        formError.value = 'گیرنده‌ای برای ارسال درخواست یافت نشد.';
        return;
    }

    submitting.value = true;
    formError.value = '';

    const payload = {
        recipient_username: recipient,
        subject: form.value.subject.trim(),
        body: form.value.body.trim(),
        priority: form.value.priority,
    };

    if (form.value.category_id !== '') {
        payload.category_id = Number(form.value.category_id);
    }

    try {
        const created = await api.post('/tickets', payload);

        if (form.value.attachments && form.value.attachments.length > 0 && created?.id) {
            for (const file of Array.from(form.value.attachments)) {
                const upload = new FormData();
                upload.append('file', file);
                await api.post(`/tickets/${created.id}/attachments`, upload);
            }
        }

        emit('created');
    } catch (failure) {
        formError.value = failure?.message || 'ثبت درخواست ناموفق بود.';
    } finally {
        submitting.value = false;
    }
}

onMounted(loadOptions);
</script>

<template>
    <div id="ticketModal" class="user-ticket-create">
        <div class="user-ticket-create-backdrop" @click="emit('close')"></div>
        <section class="user-ticket-create-dialog" role="dialog" aria-modal="true" aria-labelledby="ticketCreateHeading">
            <header class="user-ticket-create-head">
                <div>
                    <span class="ticket-panel-kicker">درخواست جدید</span>
                    <h2 id="ticketCreateHeading">چطور می‌توانیم کمک کنیم؟</h2>
                    <p>موضوع را کوتاه و روشن بنویسید تا سریع‌تر پاسخ بگیرید.</p>
                </div>
                <button type="button" class="user-ticket-close" aria-label="بستن فرم" @click="emit('close')">×</button>
            </header>
            <div class="user-ticket-steps" aria-label="مراحل ثبت درخواست">
                <span class="is-active">۱ دسته‌بندی</span>
                <span>۲ توضیح مشکل</span>
                <span>۳ ارسال</span>
            </div>
            <form id="ticketForm" class="user-ticket-create-form" @submit.prevent="submit">
                <div class="user-ticket-form-grid">
                    <label for="ticketCategory">دسته‌بندی درخواست
                        <select v-model="form.category_id" id="ticketCategory" name="category_id">
                            <option value="">عمومی</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </label>
                    <label for="ticketPriority">اهمیت
                        <select v-model="form.priority" id="ticketPriority" name="priority">
                            <option v-for="priority in PRIORITIES" :key="priority.value" :value="priority.value">{{ priority.label }}</option>
                        </select>
                    </label>
                    <label for="ticketReceiver">ارسال برای
                        <select id="ticketReceiver" name="ticketReceiver" required>
                            <option value="" disabled selected>مدیر اصلی سامانه</option>
                        </select>
                        <small>تمام درخواست‌های پشتیبانی مستقیماً برای مدیر اصلی سامانه ارسال می‌شوند.</small>
                    </label>
                    <label for="ticketCreateTitle" class="span-2">موضوع درخواست
                        <input v-model="form.subject" type="text" id="ticketCreateTitle" name="ticketTitle" required maxlength="180" autocomplete="off" placeholder="مثلاً مشکل در ثبت ورود">
                    </label>
                    <label for="ticketDescription" class="span-2">شرح درخواست
                        <textarea v-model="form.body" id="ticketDescription" name="ticketDescription" rows="6" required maxlength="4000" placeholder="چه اتفاقی افتاده و چه کمکی نیاز دارید؟"></textarea>
                        <small>حداکثر ۴۰۰۰ نویسه</small>
                    </label>
                    <label for="ticketAttachments" class="span-2 user-attachment-picker">پیوست‌های اختیاری
                        <input type="file" id="ticketAttachments" name="attachments" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx,.xls,.xlsx" @change="form.attachments = $event.target.files">
                        <small>هر فایل حداکثر ۱۰ مگابایت</small>
                    </label>
                </div>
                <div class="user-ticket-create-error" id="ticketCreateError" role="alert">{{ formError }}</div>
                <footer>
                    <button type="button" class="user-ticket-cancel" @click="emit('close')">انصراف</button>
                    <button type="submit" class="user-ticket-submit" :disabled="submitting || loading">{{ submitting ? 'در حال ثبت…' : 'ثبت درخواست' }} <span aria-hidden="true">←</span></button>
                </footer>
            </form>
        </section>
    </div>
</template>

