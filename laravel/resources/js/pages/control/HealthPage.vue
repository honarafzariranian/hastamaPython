<script setup>
/**
 * System health — the Vue equivalent of the legacy global search box and the
 * status probes behind the control centre.
 *
 *   * `GET /master-admin/api/system-health` — the per-probe sentinels.  The
 *     endpoint always answers 200 `success: true`; each probe reports its own
 *     failure shape, and the two counts use `-1` as the "unreadable" sentinel
 *     (0 means "there are none", so the two must render differently);
 *   * `GET /master-admin/api/search?q=…` — the global search box.  The result
 *     key is `results` (not `data`), and each result carries its own link.
 *
 * Search links point at the SPA routes: a user result opens the user detail
 * through `?page=users&u=<username>`, the security and error results open
 * their feeds, and an audit result opens the dashboard activity feed (the
 * audit-log section itself is not part of the control centre).
 */
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const router = useRouter();

const loading = ref(true);
const error = ref('');

const health = ref(null);

/* Global search state. */
const searchQuery = ref('');
const searchResults = ref([]);
const searchOpen = ref(false);
const searchLoading = ref(false);
let searchTimer = null;

const database = computed(() => health.value?.database ?? null);
const activeSessions = computed(() => health.value?.active_sessions ?? null);
const auditEventsToday = computed(() => health.value?.audit_events_today ?? null);
const serverTimeUtc = computed(() => health.value?.server_time_utc ?? '—');

const databaseStatus = computed(() => {
    if (!database.value) {
        return { label: 'نامشخص', cls: 'unknown' };
    }

    if (database.value.status === 'error') {
        return { label: database.value.message || 'خطا', cls: 'error' };
    }

    return { label: 'سالم', cls: 'ok' };
});

function formatCount(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    if (Number(value) === -1) {
        return 'نامشخص';
    }

    return toPersianDigits(Number(value).toLocaleString('en-US'));
}

function formatServerTime(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('fa-IR');
}

function resultLink(result) {
    if (result.type === 'user') {
        return `/master-admin?page=users&u=${encodeURIComponent(result.link.split('/').pop())}`;
    }

    if (result.type === 'security') {
        return '/master-admin?page=security';
    }

    if (result.type === 'error') {
        return '/master-admin?page=errors';
    }

    return '/master-admin?page=dashboard';
}

async function loadHealth() {
    const response = await api.get('/master-admin/api/system-health', { baseURL: '' });
    health.value = response.data ?? null;
}

function onSearchInput() {
    clearTimeout(searchTimer);

    const query = searchQuery.value.trim();

    if (query.length < 2) {
        searchResults.value = [];
        searchOpen.value = false;
        return;
    }

    searchTimer = setTimeout(() => runSearch(query), 400);
}

async function runSearch(query) {
    searchLoading.value = true;

    try {
        const response = await api.get('/master-admin/api/search', {
            baseURL: '',
            params: { q: query },
        });

        searchResults.value = Array.isArray(response.results) ? response.results : [];
        searchOpen.value = true;
    } catch {
        searchResults.value = [];
        searchOpen.value = false;
    } finally {
        searchLoading.value = false;
    }
}

function closeSearch() {
    searchOpen.value = false;
}

function openResult(link) {
    closeSearch();
    router.push(link).catch(() => {});
}

onMounted(async () => {
    try {
        await loadHealth();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در دریافت اطلاعات سلامت سیستم';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <div class="ma-search" style="position:relative;max-width:30rem">
            <input
                v-model="searchQuery"
                type="text"
                class="ma-filter"
                style="width:100%;padding:10px 14px 10px 36px;border:1px solid #e4e7ec;border-radius:10px;font-family:'Vazir',sans-serif;font-size:0.82rem;outline:none;direction:rtl"
                placeholder="جستجوی سراسری..."
                aria-label="جستجوی سراسری"
                @input="onSearchInput"
                @focus="searchOpen = searchResults.length > 0"
            >

            <div v-if="searchOpen" style="position:absolute;top:calc(100% + 0.35rem);inset-inline:0;z-index:25;display:flex;flex-direction:column;gap:0.2rem;padding:0.4rem;border:1px solid rgb(15 23 42 / 0.1);border-radius:var(--radius-token-md);background:#fff;box-shadow:0 12px 30px rgb(15 23 42 / 0.15)" role="listbox" aria-label="نتایج جستجو">
                <div v-if="searchLoading" style="padding:0.8rem;text-align:center;color:#94a3b8;font-size:0.82rem">در حال جستجو…</div>

                <div v-else-if="!searchResults.length" style="padding:0.8rem;text-align:center;color:#94a3b8;font-size:0.82rem">نتیجه‌ای یافت نشد</div>

                <button
                    v-for="result in searchResults"
                    :key="`${result.type}-${result.title}`"
                    type="button"
                    style="display:flex;flex-direction:column;gap:0.15rem;width:100%;padding:0.55rem 0.7rem;border:0;border-radius:var(--radius-token-sm);background:none;color:inherit;font:inherit;text-align:start;cursor:pointer"
                    role="option"
                    @click="openResult(resultLink(result))"
                >
                    <span style="font-size:0.85rem;font-weight:600">{{ result.title }}</span>
                    <span style="color:#94a3b8;font-size:0.72rem">{{ result.subtitle }}</span>
                </button>
            </div>
        </div>

        <div v-if="loading" class="ma-empty">
            <div class="ma-empty__text">در حال بررسی سلامت سیستم…</div>
        </div>

        <div v-else class="ma-grid-3">
            <div class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">پایگاه‌داده</div>
                    <span
                        style="padding:0.15rem 0.55rem;border-radius:999px;font-size:0.7rem;font-weight:700"
                        :class="{
                            'ma-badge--success': databaseStatus.cls === 'ok',
                            'ma-badge--danger': databaseStatus.cls === 'error',
                            'ma-badge--neutral': databaseStatus.cls === 'unknown'
                        }"
                    >
                        {{ databaseStatus.label }}
                    </span>
                </div>
                <div class="ma-panel-card__body">
                    <p v-if="database?.status !== 'error' && database?.latency_ms !== undefined" style="margin:0;font-size:1.3rem;font-weight:800">
                        تأخیر: {{ toPersianDigits(Number(database.latency_ms).toLocaleString('en-US')) }} میلی‌ثانیه
                    </p>
                </div>
            </div>

            <div class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">نشست‌های فعال</div>
                </div>
                <div class="ma-panel-card__body">
                    <p style="margin:0;font-size:1.3rem;font-weight:800">{{ formatCount(activeSessions) }}</p>
                </div>
            </div>

            <div class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">رویدادهای حسابرسی امروز</div>
                </div>
                <div class="ma-panel-card__body">
                    <p style="margin:0;font-size:1.3rem;font-weight:800">{{ formatCount(auditEventsToday) }}</p>
                </div>
            </div>

            <div class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">زمان سرور (UTC)</div>
                </div>
                <div class="ma-panel-card__body">
                    <p style="margin:0;font-size:0.95rem;font-weight:600;direction:ltr;text-align:start">{{ formatServerTime(serverTimeUtc) }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
