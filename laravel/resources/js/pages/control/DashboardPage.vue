<script setup>
/**
 * The control-centre dashboard — the Vue equivalent of `loadDashboard()` in
 * `app/static/js/master-admin.js` plus the server-rendered skeleton in
 * `app/templates/master-admin.html`.
 *
 * Two reads, in the legacy order:
 *   * `GET /master-admin/api/dashboard/stats` — the summary counters;
 *   * `GET /master-admin/api/dashboard/activity?limit=30` — the activity feed.
 *
 * **The ten cards are the legacy ten, not eleven.**  The endpoint also returns
 * `admin_count`, and the legacy renderer simply never displayed it — only the
 * ticket surface reads it.  Adding an eleventh card here is what made the two
 * dashboards look different, so it is deliberately absent.
 *
 * Every card carries its *own* gradient, its own `animation-delay` and, when
 * the legacy card had one, its own target path; the legacy renderer wrote all
 * three into the card's inline `style` and wrapped the content in an `<a>` when
 * there was a destination.  Reproduced literally: the gradient list, the 0/50/…
 * delay ladder and the anchor-vs-div split are the legacy values, not a
 * restyling.
 *
 * The counters count up from zero on a 30 ms `setInterval` in steps of
 * `max(1, floor(target / 20))`, which is the legacy `glowCardIn` companion.  A
 * zero counter is *not* animated — the legacy loop returned early and left the
 * already-rendered `۰` in place.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const emit = defineEmits(['navigate']);

const loading = ref(true);
const error = ref('');

const stats = ref(null);
const activity = ref([]);

/**
 * The ten counters, in the legacy card order.  `gradient`, `delay`, `href` and
 * `alert` are copied from the `cards` array in `loadDashboard()`.
 */
const CARDS = [
    {
        key: 'total_users',
        label: 'کل کاربران',
        icon: '👥',
        gradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        delay: 0,
        href: '/master-admin/users',
        section: 'users',
    },
    {
        key: 'active_users',
        label: 'کاربران فعال',
        icon: '🟢',
        gradient: 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)',
        delay: 50,
    },
    {
        key: 'online_sessions',
        label: 'نشست‌های فعال',
        icon: '🔗',
        gradient: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        delay: 100,
        href: '/master-admin/sessions',
        section: 'sessions',
    },
    {
        key: 'logins_today',
        label: 'ورودهای امروز',
        icon: '🔑',
        gradient: 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        delay: 150,
        href: '/master-admin/audit-logs',
        section: 'audit-logs',
    },
    {
        key: 'failed_logins_today',
        label: 'ورود ناموفق',
        icon: '⚠️',
        gradient: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        delay: 200,
        href: '/master-admin/security',
        section: 'security',
        alert: true,
    },
    {
        key: 'pending_password_resets',
        label: 'بازیابی رمز',
        icon: '🔐',
        gradient: 'linear-gradient(135deg, #fccb90 0%, #d57eeb 100%)',
        delay: 250,
        href: '/master-admin/password-resets',
        section: 'password-resets',
        alert: true,
    },
    {
        key: 'open_security_events',
        label: 'رویداد امنیتی',
        icon: '🛡️',
        gradient: 'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)',
        delay: 300,
        href: '/master-admin/security',
        section: 'security',
        alert: true,
    },
    {
        key: 'open_errors',
        label: 'خطاهای باز',
        icon: '🐛',
        gradient: 'linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%)',
        delay: 350,
        href: '/master-admin/errors',
        section: 'errors',
        alert: true,
    },
    {
        key: 'open_tickets',
        label: 'تیکت‌های باز',
        icon: '🎫',
        gradient: 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)',
        delay: 400,
        href: '/master-admin/tickets',
        section: 'tickets',
    },
    {
        key: 'events_today',
        label: 'رویدادهای امروز',
        icon: '📋',
        gradient: 'linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%)',
        delay: 450,
        href: '/master-admin/audit-logs',
        section: 'audit-logs',
    },
];

/**
 * The five skeleton cards the Python page shipped in its markup, before
 * `loadDashboard()` replaced them with the full ten.  Shown while the two
 * requests are in flight, so the first paint is the legacy first paint.
 */
const SKELETON_CARDS = [
    { icon: '👥', label: 'کل کاربران', gradient: 'linear-gradient(135deg,#667eea,#764ba2)', width: '64px' },
    { icon: '🟢', label: 'آنلاین', gradient: 'linear-gradient(135deg,#11998e,#38ef7d)', width: '48px' },
    { icon: '🔗', label: 'نشست فعال', gradient: 'linear-gradient(135deg,#4facfe,#00f2fe)', width: '48px' },
    { icon: '🔑', label: 'درخواست بازیابی', gradient: 'linear-gradient(135deg,#43e97b,#38f9d7)', width: '48px' },
    { icon: '⚠️', label: 'رویداد امنیتی', gradient: 'linear-gradient(135deg,#f093fb,#f5576c)', width: '48px' },
];

/**
 * The «دسترسی سریع» panel, transcribed from the `master-admin.html` markup
 * rather than derived from the counters above.  The legacy panel was seven
 * fixed links with their own accent colour and feather icon each; deriving it
 * from the stat cards is what made this grid wrong, so it is spelled out here.
 */
const QUICK_LINKS = [
    {
        label: 'مدیریت تیکت‌ها',
        href: '/master-admin/tickets',
        section: 'tickets',
        accent: '#0ea5e9',
        accentBg: 'rgba(14,165,233,.1)',
        icon: '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>',
    },
    {
        label: 'بازیابی رمز',
        href: '/master-admin/password-resets',
        section: 'password-resets',
        accent: '#f59e0b',
        accentBg: 'rgba(245,158,11,.1)',
        icon: '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    },
    {
        label: 'رویدادهای امنیتی',
        href: '/master-admin/security',
        section: 'security',
        accent: '#10b981',
        accentBg: 'rgba(16,185,129,.1)',
        icon: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    },
    {
        label: 'خطاهای سیستم',
        href: '/master-admin/errors',
        section: 'errors',
        accent: '#ef4444',
        accentBg: 'rgba(239,68,68,.1)',
        icon: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    },
    {
        label: 'نشست‌های فعال',
        href: '/master-admin/sessions',
        section: 'sessions',
        accent: '#8b5cf6',
        accentBg: 'rgba(139,92,246,.1)',
        icon: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    },
    {
        label: 'لاگ حسابرسی',
        href: '/master-admin/audit-logs',
        section: 'audit-logs',
        accent: '#06b6d4',
        accentBg: 'rgba(6,182,212,.1)',
        icon: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    },
    {
        label: 'مدیریت کاربران',
        href: '/master-admin/users',
        section: 'users',
        accent: '#ec4899',
        accentBg: 'rgba(236,72,153,.1)',
        icon: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    },
];

const cards = computed(() => {
    if (!stats.value) {
        return [];
    }

    return CARDS.map((card) => {
        const value = Number(stats.value[card.key] ?? 0);

        return {
            ...card,
            value,
            isAlert: Boolean(card.alert && value > 0),
        };
    });
});

/**
 * The running totals of the count-up.  A key that is absent still falls back to
 * its final value, which is what the legacy markup rendered first and what a
 * zero counter keeps for the whole of its life.
 */
const countUps = ref({});
const countUpTimers = [];

function displayedValue(card) {
    return countUps.value[card.key] ?? toPersianDigits(card.value);
}

function startCountUp(card) {
    const target = Number(card.value) || 0;

    if (target === 0) {
        return;
    }

    const step = Math.max(1, Math.floor(target / 20));
    let current = 0;

    const timer = setInterval(() => {
        current += step;

        if (current >= target) {
            current = target;
            clearInterval(timer);
        }

        countUps.value = { ...countUps.value, [card.key]: toPersianDigits(current) };
    }, 30);

    countUpTimers.push(timer);
}

function formatTime(value) {
    if (!value) {
        return '';
    }

    const raw = String(value);
    /* The Laravel driver hands back a `Y-m-d H:i:s` string; the legacy
     * `pyodbc` datetime reached `new Date()` already parsed.  The swap puts
     * both on the same footing instead of letting Safari read it as local
     * time. */
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString('fa-IR');
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

function openLink(event, link) {
    event.preventDefault();
    emit('navigate', link.section);
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

    cards.value.forEach(startCountUp);
});

onBeforeUnmount(() => {
    while (countUpTimers.length) {
        clearInterval(countUpTimers.pop());
    }
});
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <div v-if="loading" class="ma-top-cards-row" id="maStats">
            <div
                v-for="card in SKELETON_CARDS"
                :key="card.label"
                class="ma-glow-card"
                :style="{ '--card-gradient': card.gradient }"
            >
                <div class="ma-glow-card__content">
                    <div class="ma-glow-card__top">
                        <div class="ma-glow-card__icon">{{ card.icon }}</div>
                    </div>
                    <div class="ma-glow-card__label">{{ card.label }}</div>
                    <div class="ma-glow-card__value ma-skeleton" :style="{ width: card.width, height: '28px' }"></div>
                </div>
            </div>
        </div>

        <div v-else class="ma-top-cards-row" id="maStats">
            <div
                v-for="card in cards"
                :key="card.key"
                class="ma-glow-card"
                :style="{ animationDelay: `${card.delay}ms`, '--card-gradient': card.gradient }"
            >
                <div class="ma-glow-card__shine"></div>
                <component
                    :is="card.href ? 'a' : 'div'"
                    class="ma-glow-card__content"
                    :href="card.href || undefined"
                    :style="card.href
                        ? 'text-decoration:none;color:inherit;cursor:pointer'
                        : 'text-decoration:none;color:inherit;cursor:default'"
                    @click="card.href && openLink($event, card)"
                >
                    <div class="ma-glow-card__top">
                        <div class="ma-glow-card__icon">{{ card.icon }}</div>
                        <div v-if="card.isAlert" class="ma-glow-card__alert"></div>
                    </div>
                    <div class="ma-glow-card__value" :data-count="card.value">{{ displayedValue(card) }}</div>
                    <div class="ma-glow-card__label">{{ card.label }}</div>
                </component>
            </div>
        </div>

        <div class="ma-grid-2">
            <div class="ma-panel-card">
                <div class="ma-panel-card__header">
                    <div class="ma-panel-card__title">📋 آخرین رویدادها</div>
                </div>
                <div class="ma-panel-card__body" id="maActivity">
                    <div v-if="loading" class="ma-skeleton" style="height:240px"></div>

                    <div v-else-if="!activity.length" class="ma-empty">
                        <div class="ma-empty__icon">📭</div>
                        <div class="ma-empty__text">هنوز رویدادی ثبت نشده است</div>
                    </div>

                    <div v-else class="ma-timeline">
                        <div v-for="entry in activity" :key="entry.event_id" class="ma-timeline__item">
                            <div class="ma-timeline__dot" :class="`ma-timeline__dot--${activityDotClass(entry)}`"></div>
                            <div class="ma-timeline__time">{{ formatTime(entry.created_at) }}</div>
                            <div class="ma-timeline__text">
                                {{ entry.status }} <strong>{{ entry.username || '—' }}</strong> {{ entry.action }}
                                <template v-if="entry.module"> در {{ entry.module }}</template>
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
                            v-for="link in QUICK_LINKS"
                            :key="link.label"
                            :href="link.href"
                            class="ma-qcard"
                            :style="{ '--accent': link.accent, '--accent-bg': link.accentBg }"
                            @click="openLink($event, link)"
                        >
                            <div class="ma-qcard__icon">
                                <svg
                                    width="22"
                                    height="22"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    v-html="link.icon"
                                ></svg>
                            </div>
                            <span class="ma-qcard__label">{{ link.label }}</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
