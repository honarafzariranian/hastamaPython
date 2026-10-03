<script setup>
/**
 * The control-centre dashboard — the Vue equivalent of the legacy
 * `loadDashboard()` in `master-admin.js`.
 *
 * Two reads, in the legacy order:
 *   * `GET /master-admin/api/dashboard/stats` — the eleven summary counters;
 *   * `GET /master-admin/api/dashboard/activity?limit=30` — the recent-activity
 *     timeline.
 *
 * The counters render with Persian digits and carry an alert marker when the
 * legacy UI treated the value as a warning (failed logins, pending resets,
 * open security events, open errors).  Cards that had a target section in the
 * legacy markup navigate there through the layout's `navigate` event.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const emit = defineEmits(['navigate']);

const loading = ref(true);
const error = ref('');

const stats = ref(null);
const activity = ref([]);

/**
 * The eleven counters, in the legacy card order.  `alert` marks the cards the
 * legacy UI flagged when the count was non-zero; `target` is the section the
 * legacy card linked to.
 */
const CARDS = [
    { key: 'total_users', label: 'کل کاربران', icon: '👥', target: 'users' },
    { key: 'active_users', label: 'کاربران فعال', icon: '🟢' },
    { key: 'online_sessions', label: 'نشست‌های فعال', icon: '🔗', target: 'sessions' },
    { key: 'logins_today', label: 'ورودهای امروز', icon: '🔑' },
    { key: 'failed_logins_today', label: 'ورود ناموفق', icon: '⚠️', alert: true, target: 'security' },
    { key: 'pending_password_resets', label: 'بازیابی رمز', icon: '🔐', alert: true, target: 'password-resets' },
    { key: 'open_security_events', label: 'رویداد امنیتی', icon: '🛡️', alert: true, target: 'security' },
    { key: 'open_errors', label: 'خطاهای باز', icon: '🐛', alert: true, target: 'errors' },
    { key: 'open_tickets', label: 'تیکت‌های باز', icon: '🎫' },
    { key: 'admin_count', label: 'مدیران', icon: '👮' },
    { key: 'events_today', label: 'رویدادهای امروز', icon: '📋' },
];

const cards = computed(() => {
    if (!stats.value) {
        return [];
    }

    return CARDS.map((card) => ({
        ...card,
        value: Number(stats.value[card.key] ?? 0),
        isAlert: Boolean(card.alert && Number(stats.value[card.key] ?? 0) > 0),
    }));
});

function formatTime(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(String(value).includes(' ') && !String(value).includes('T')
        ? String(value).replace(' ', 'T')
        : value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('fa-IR');
}

function activityDotClass(entry) {
    if (entry.status === 'failure' || entry.status === 'error') {
        return 'danger';
    }

    if (entry.severity === 'high' || entry.severity === 'critical') {
        return 'warning';
    }

    return 'success';
}

async function loadStats() {
    const response = await api.get('/master-admin/api/dashboard/stats', { baseURL: '' });
    stats.value = response.data ?? {};
}

async function loadActivity() {
    const response = await api.get('/master-admin/api/dashboard/activity', {
        baseURL: '',
        params: { limit: 30 },
    });
    activity.value = Array.isArray(response.data) ? response.data : [];
}

onMounted(async () => {
    try {
        await Promise.all([loadStats(), loadActivity()]);
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در دریافت اطلاعات داشبورد.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <div v-if="loading" class="ma-top-cards-row">
            <div v-for="card in CARDS" :key="card.key" class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="ma-glow-card__content">
                    <div class="ma-glow-card__top">
                        <div class="ma-glow-card__icon">{{ card.icon }}</div>
                    </div>
                    <div class="ma-glow-card__label">{{ card.label }}</div>
                    <div class="ma-glow-card__value ma-skeleton" style="width:64px;height:28px"></div>
                </div>
            </div>
        </div>

        <template v-else>
            <div class="ma-top-cards-row">
                <div
                    v-for="card in cards"
                    :key="card.key"
                    class="ma-glow-card"
                    :style="{ '--card-gradient': 'linear-gradient(135deg,#667eea,#764ba2)' }"
                >
                    <div class="ma-glow-card__shine"></div>
                    <div
                        class="ma-glow-card__content"
                        :style="card.target ? 'cursor:pointer' : 'cursor:default'"
                        @click="card.target && emit('navigate', card.target)"
                    >
                        <div class="ma-glow-card__top">
                            <div class="ma-glow-card__icon">{{ card.icon }}</div>
                            <div v-if="card.isAlert" class="ma-glow-card__alert"></div>
                        </div>
                        <div class="ma-glow-card__value">{{ toPersianDigits(card.value) }}</div>
                        <div class="ma-glow-card__label">{{ card.label }}</div>
                    </div>
                </div>
            </div>

            <div class="ma-grid-2">
                <div class="ma-panel-card">
                    <div class="ma-panel-card__header">
                        <div class="ma-panel-card__title">📋 آخرین رویدادها</div>
                    </div>
                    <div class="ma-panel-card__body">
                        <div v-if="!activity.length" class="ma-empty">
                            <div class="ma-empty__icon">📭</div>
                            <div class="ma-empty__text">هنوز رویدادی ثبت نشده است</div>
                        </div>

                        <div v-else class="ma-timeline">
                            <div v-for="entry in activity" :key="entry.event_id" class="ma-timeline__item">
                                <div class="ma-timeline__dot" :class="`ma-timeline__dot--${activityDotClass(entry)}`"></div>
                                <div class="ma-timeline__time">{{ formatTime(entry.created_at) }}</div>
                                <div class="ma-timeline__text">
                                    {{ entry.status }} <strong>{{ entry.username || '—' }}</strong> {{ entry.action }}<template v-if="entry.module"> در {{ entry.module }}</template>
                                </div>
                                <div class="ma-timeline__meta">{{ entry.event_id }} · {{ entry.ip_address || '—' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ma-panel-card">
                    <div class="ma-panel-card__header">
                        <div class="ma-panel-card__title">⚡ دسترسی سریع</div>
                    </div>
                    <div class="ma-panel-card__body">
                        <div class="ma-quick-grid">
                            <a
                                v-for="card in cards.filter((item) => item.target)"
                                :key="`quick-${card.key}`"
                                href="#"
                                class="ma-qcard"
                                :style="{ '--accent': '#0ea5e9', '--accent-bg': 'rgba(14,165,233,.1)' }"
                                @click.prevent="emit('navigate', card.target)"
                            >
                                <div class="ma-qcard__icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                                </div>
                                <span class="ma-qcard__label">{{ card.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
