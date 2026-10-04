<script setup>
/**
 * The notification centre — the Vue equivalent of `#notificationCenter` and the
 * `#notificationDetail` dialog in `user-panel.html`, ported from
 * `notification-system.js`:
 *   * `GET    /api/notifications?page&page_size&state&search`;
 *   * `POST   /api/notifications/read-all`;
 *   * `POST   /api/notifications/{id}/read` / `/unread`;
 *   * `DELETE /api/notifications/{id}`.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';

const emit = defineEmits(['close']);

const TYPE_LABELS = {
    info: 'اطلاعیه', success: 'موفقیت', warning: 'هشدار', error: 'خطا', task: 'کار', announcement: 'اطلاعیه',
};
const PRIORITY_LABELS = { low: 'کم', normal: 'عادی', high: 'زیاد', urgent: 'فوری' };

const STATE_TABS = [
    { value: 'all', label: 'همه' },
    { value: 'unread', label: 'خوانده‌نشده' },
    { value: 'read', label: 'خوانده‌شده' },
];

const loading = ref(true);
const error = ref('');

const items = ref([]);
const total = ref(0);
const unread = ref(0);
const page = ref(1);
const pages = ref(1);

const state = ref('all');
const search = ref('');

const detail = ref(null);

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

function typeLabel(value) {
    return TYPE_LABELS[value] || value || 'اطلاعیه';
}

function priorityLabel(value) {
    return PRIORITY_LABELS[value] || value || 'عادی';
}

async function load(targetPage = 1) {
    loading.value = true;
    error.value = '';

    const params = new URLSearchParams({
        page: String(targetPage),
        page_size: '12',
        state: state.value,
    });

    if (search.value.trim() !== '') {
        params.set('search', search.value.trim());
    }

    try {
        const data = await api.get(`/notifications?${params.toString()}`);
        items.value = Array.isArray(data?.items) ? data.items : [];
        total.value = data?.total ?? 0;
        unread.value = data?.unread ?? 0;
        page.value = data?.page ?? 1;
        pages.value = data?.pages ?? 1;
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اعلان‌ها.';
    } finally {
        loading.value = false;
    }
}

function selectState(value) {
    state.value = value;
    load(1);
}

async function markRead(item) {
    if (item.read_at) {
        return;
    }

    try {
        await api.post(`/notifications/${item.id}/read`);
        item.read_at = new Date().toISOString();
        unread.value = Math.max(0, unread.value - 1);
    } catch (failure) {
        error.value = failure?.message || 'خطا در علامت‌گذاری اعلان.';
    }
}

async function mutate(action, item) {
    try {
        if (action === 'dismiss') {
            await api.delete(`/notifications/${item.id}`);
        } else {
            await api.post(`/notifications/${item.id}/${action}`);
        }

        await load(page.value);
    } catch (failure) {
        error.value = failure?.message || 'عملیات اعلان ناموفق بود.';
    }
}

async function markAllRead() {
    try {
        await api.post('/notifications/read-all');
        unread.value = 0;
        await load(page.value);
    } catch (failure) {
        error.value = failure?.message || 'خطا در خواندن همه اعلان‌ها.';
    }
}

function showDetail(item) {
    detail.value = item;

    if (!item.read_at) {
        markRead(item);
    }
}

function closeDetail() {
    detail.value = null;
}

const filtered = computed(() => {
    const query = search.value.trim();

    if (query === '') {
        return items.value;
    }

    return items.value.filter((item) => (item.title || '').includes(query) || (item.content || '').includes(query));
});

onMounted(() => load(1));
</script>

<template>
    <div class="notification-center" id="notificationCenter" aria-hidden="false">
        <div class="notification-center-backdrop" data-notification-center-close @click="emit('close')"></div>
        <section class="notification-center-panel" role="dialog" aria-modal="true" aria-labelledby="notificationCenterTitle">
            <header class="notification-center-head">
                <div>
                    <span class="notification-kicker">تاریخچه ارتباطات</span>
                    <h2 id="notificationCenterTitle">اعلان‌های من</h2>
                    <p>پیام‌ها و اطلاعیه‌های سازمانی خود را اینجا مدیریت کنید.</p>
                </div>
                <button type="button" data-notification-center-close aria-label="بستن" @click="emit('close')">×</button>
            </header>
            <div class="notification-center-toolbar">
                <label class="notification-search">
                    <span class="sr-only">جستجو</span>
                    <input v-model="search" type="search" id="userNotificationSearch" placeholder="جستجو در اعلان‌ها…">
                </label>
                <div class="notification-filter-tabs" role="group" aria-label="فیلتر وضعیت">
                    <button
                        v-for="tab in STATE_TABS"
                        :key="tab.value"
                        type="button"
                        :class="{ 'active': state === tab.value }"
                        :data-notification-state="tab.value"
                        @click="selectState(tab.value)"
                    >{{ tab.label }}</button>
                </div>
                <button type="button" class="notification-secondary-btn" id="notificationReadAll" @click="markAllRead">خواندن همه</button>
            </div>
            <div class="notification-user-list" id="notificationUserList">
                <div v-if="loading" class="notification-loading">در حال دریافت اعلان‌ها…</div>
                <div v-else-if="error" class="notification-error">{{ error }}</div>
                <template v-else>
                    <div v-if="filtered.length === 0" class="notification-empty-state">
                        <span class="notification-empty-icon">✓</span>
                        <strong>اعلانی پیدا نشد</strong>
                        <p>فیلتر یا عبارت جستجو را تغییر دهید.</p>
                    </div>
                    <article
                        v-for="item in filtered"
                        :key="item.id"
                        class="notification-user-item"
                        :class="{ 'is-unread': !item.read_at }"
                        tabindex="0"
                        @click="showDetail(item)"
                        @keydown.enter="showDetail(item)"
                    >
                        <span class="notification-type-icon" :class="`notification-type-icon--${item.type}`" aria-hidden="true"></span>
                        <div class="notification-user-content">
                            <div class="notification-user-heading">
                                <strong>{{ item.title }}</strong>
                                <span v-if="!item.read_at" class="notification-unread-label">خوانده‌نشده</span>
                            </div>
                            <p>{{ item.content }}</p>
                            <time>{{ formatDate(item.published_at) }}</time>
                        </div>
                        <div class="notification-user-actions">
                            <button type="button" class="notification-action-btn" :data-action="item.read_at ? 'unread' : 'read'" :data-id="item.id" @click.stop="mutate(item.read_at ? 'unread' : 'read', item)">{{ item.read_at ? 'خوانده‌نشده' : 'خوانده‌شده' }}</button>
                            <button type="button" class="notification-action-btn" data-action="dismiss" :data-id="item.id" @click.stop="mutate('dismiss', item)">حذف</button>
                        </div>
                    </article>
                </template>
            </div>
            <div class="notification-pagination" id="userNotificationPagination">
                <template v-if="pages > 1">
                    <button type="button" class="notification-secondary-btn" :disabled="page <= 1" @click="load(page - 1)">قبلی</button>
                    <span>صفحه {{ fa(page) }} از {{ fa(pages) }}</span>
                    <button type="button" class="notification-secondary-btn" :disabled="page >= pages" @click="load(page + 1)">بعدی</button>
                </template>
            </div>
        </section>
    </div>

    <div v-if="detail" class="notification-detail" id="notificationDetail" aria-hidden="false">
        <div class="notification-detail-backdrop" data-notification-detail-close @click="closeDetail"></div>
        <article role="dialog" aria-modal="true" aria-labelledby="notificationDetailTitle">
            <button type="button" class="notification-detail-close" data-notification-detail-close aria-label="بستن" @click="closeDetail">×</button>
            <div id="notificationDetailBody">
                <div class="notification-detail-type">
                    <span class="notification-badge" :class="`notification-badge--${detail.type}`">{{ typeLabel(detail.type) }}</span>
                    <span class="notification-badge" :class="`notification-badge--${detail.priority}`">{{ priorityLabel(detail.priority) }}</span>
                </div>
                <h2 id="notificationDetailTitle">{{ detail.title }}</h2>
                <p class="notification-detail-message">{{ detail.content }}</p>
                <dl>
                    <dt>زمان انتشار</dt>
                    <dd>{{ formatDate(detail.published_at) }}</dd>
                    <dt>فرستنده</dt>
                    <dd>{{ detail.created_by || 'سامانه' }}</dd>
                </dl>
                <a v-if="detail.action_url" class="notification-primary-btn" :href="detail.action_url">{{ detail.action_label || 'مشاهده' }}</a>
            </div>
        </article>
    </div>
</template>

