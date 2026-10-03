<script setup>
/**
 * Announcements / notifications — the Vue equivalent of the legacy
 * notification centre (`#notificationCenter`) and the bell dropdown.
 *
 * Uses the user notification surface:
 *   * `GET  /api/notifications` — the signed-in user's inbox, paginated, with
 *     `items`, `total`, `unread`, `page` and `pages`;
 *   * `POST /api/notifications/{id}/read` — mark one as read;
 *   * `POST /api/notifications/read-all` — mark the whole inbox read;
 *   * `GET  /api/notifications/unread-count` — the bell badge number.
 *
 * The list supports the same state filter the legacy centre had (all / unread
 * / read) and a client-side search over title and content.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const loading = ref(true);
const error = ref('');

const items = ref([]);
const total = ref(0);
const unread = ref(0);
const page = ref(1);
const pages = ref(1);

const state = ref('all');
const search = ref('');

const STATE_TABS = [
    { value: 'all', label: 'همه' },
    { value: 'unread', label: 'خوانده‌نشده' },
    { value: 'read', label: 'خوانده‌شده' },
];

const filteredItems = computed(() => {
    const query = search.value.trim();

    if (query === '') {
        return items.value;
    }

    return items.value.filter((item) => (
        (item.title || '').includes(query) || (item.content || '').includes(query)
    ));
});

async function loadNotifications() {
    const params = new URLSearchParams({
        page: String(page.value),
        page_size: '12',
        state: state.value,
    });

    if (search.value.trim() !== '') {
        params.set('search', search.value.trim());
    }

    const response = await api.get(`/notifications?${params.toString()}`);
    items.value = Array.isArray(response?.items) ? response.items : [];
    total.value = response?.total ?? 0;
    unread.value = response?.unread ?? 0;
    page.value = response?.page ?? 1;
    pages.value = response?.pages ?? 1;
}

async function loadUnreadCount() {
    const response = await api.get('/notifications/unread-count');
    unread.value = response?.unread ?? 0;
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

async function markAllRead() {
    try {
        await api.post('/notifications/read-all');
        items.value = items.value.map((item) => ({ ...item, read_at: item.read_at || new Date().toISOString() }));
        unread.value = 0;
    } catch (failure) {
        error.value = failure?.message || 'خطا در خواندن همه اعلان‌ها.';
    }
}

function selectState(value) {
    state.value = value;
    page.value = 1;
    loadNotifications().catch(() => {});
}

onMounted(async () => {
    try {
        await Promise.all([loadNotifications(), loadUnreadCount()]);
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اعلان‌ها.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="notification-center-page" aria-label="اعلان‌ها">
        <div class="notification-center-panel" role="dialog" aria-modal="true" aria-labelledby="notificationCenterTitle">
            <header class="notification-center-head">
                <div>
                    <span class="notification-kicker">تاریخچه ارتباطات</span>
                    <h2 id="notificationCenterTitle">اعلان‌های من</h2>
                    <p>پیام‌ها و اطلاعیه‌های سازمانی خود را اینجا مدیریت کنید.</p>
                </div>
                <button type="button" data-notification-center-close aria-label="بستن">×</button>
            </header>
            <div class="notification-center-toolbar">
                <label class="notification-search">
                    <span class="sr-only">جستجو</span>
                    <input v-model="search" id="userNotificationSearch" type="search" placeholder="جستجو در اعلان‌ها…">
                </label>
                <div class="notification-filter-tabs" role="group" aria-label="فیلتر وضعیت">
                    <button
                        v-for="tab in STATE_TABS"
                        :key="tab.value"
                        type="button"
                        :class="{ 'active': state === tab.value }"
                        :data-notification-state="tab.value"
                        @click="selectState(tab.value)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
                <button type="button" class="notification-secondary-btn" id="notificationReadAll" @click="markAllRead">خواندن همه</button>
            </div>
            <div class="notification-user-list" id="notificationUserList">
                <div v-if="loading" class="notification-loading">در حال دریافت اعلان‌ها…</div>
                <div v-else-if="filteredItems.length === 0" class="notification-empty-state">
                    <span class="notification-empty-icon">✓</span>
                    <strong>اعلانی پیدا نشد</strong>
                    <p>فیلتر یا عبارت جستجو را تغییر دهید.</p>
                </div>
                <article
                    v-for="item in filteredItems"
                    :key="item.id"
                    class="notification-user-item"
                    :class="{ 'is-unread': !item.read_at }"
                    tabindex="0"
                >
                    <span class="notification-type-icon notification-type-icon--{{ item.type }}" aria-hidden="true"></span>
                    <div class="notification-user-content">
                        <div class="notification-user-heading">
                            <strong>{{ item.title }}</strong>
                            <span v-if="!item.read_at" class="notification-unread-label">خوانده‌نشده</span>
                        </div>
                        <p>{{ item.content }}</p>
                        <time>{{ toPersianDigits(item.published_at || '') }}</time>
                    </div>
                    <div class="notification-user-actions">
                        <button
                            v-if="!item.read_at"
                            type="button"
                            class="notification-secondary-btn"
                            @click="markRead(item)"
                        >
                            خوانده‌شده
                        </button>
                    </div>
                </article>
            </div>
            <div class="notification-pagination" id="userNotificationPagination">
                <button
                    v-if="pages > 1"
                    type="button"
                    class="notification-secondary-btn"
                    :disabled="page <= 1"
                    @click="page -= 1; loadNotifications().catch(() => {})"
                >
                    قبلی
                </button>
                <span v-if="pages > 1">صفحه {{ toPersianDigits(String(page)) }} از {{ toPersianDigits(String(pages)) }}</span>
                <button
                    v-if="pages > 1"
                    type="button"
                    class="notification-secondary-btn"
                    :disabled="page >= pages"
                    @click="page += 1; loadNotifications().catch(() => {})"
                >
                    بعدی
                </button>
            </div>
        </div>
    </section>
</template>
