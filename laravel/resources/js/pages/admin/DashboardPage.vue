<script setup>
/**
 * Admin dashboard — the legacy `#dashboardBox` (`app/templates/admin.html`).
 *
 * **Why the markup is copied and not redesigned.**  The running application
 * loads `admin.css`, whose dashboard rules are all scoped to `#dashboardBox`
 * and select the bento vocabulary: `.dash-head`, `.dash-bento`,
 * `.dashboard-card`, `.dash-row`, `.dashboard-chart`, `.dashboard-table`.  The
 * stylesheet is ported byte-for-byte, so the page is identical only when the
 * markup is the same — a `.dash__cards` grid of `.h-card` (what this file used
 * to render) has no rule in common with it and paints a different page.
 * The root is therefore a fragment: every section is a **direct** child of the
 * `<main id="dashboardBox">` the layout renders, because `#dashboardBox > *`
 * is itself a rule (it lifts each section above the `::before` backdrop).
 *
 * **Where the numbers come from.**  In the Python page they were template
 * context built by `_render_admin_page` — sums over `user_table`,
 * `totalpass_table`, `ezafe_total_table` and `leave_report`, formatted before
 * rendering.  There was no URL for them, so `DashboardStatsController` serves
 * the same sums (and the same Persian formatting) as JSON, and this page binds
 * what the template bound: `H:MM` for the aggregate pass time, `HH:MM` for
 * every per-user time, English digits for `data-percent` (the bar heights are
 * `parseInt`ed) and Persian digits everywhere a person reads a number.
 *
 * **Motion.**  `admin.js` runs `initDashboardMotion()` once per document:
 * bar heights (`--p`), the counter roll-up (`data-count-to`), the percentage
 * rings (`data-ring`) and the pointer spotlight on `.dashboard-card`.  The
 * same four run here — once when the markup mounts (with the empty state the
 * legacy page starts from: bars at 0, rings drawn to 0) and again once the
 * figures land, which is the port's equivalent of the legacy page arriving
 * already filled in.  Everything is wrapped in `try`/`catch` exactly as the
 * legacy is: a motion failure must never take the dashboard down.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

/**
 * The empty state — the numbers the markup carries before the request lands.
 *
 * The legacy page never had one (the figures were server-rendered), so this
 * has to read as the *start* of the legacy animation rather than as a different
 * page: `۰` where a counter rolls up, `۰:۰۰` where a duration is printed and
 * `-` where a name is looked up.
 */
const EMPTY = {
    total_users: '۰',
    unique_departments: '۰',
    overtime_user_count: '۰',
    no_overtime_users: '۰',
    total_pass_time: '۰:۰۰',
    total_overtime_time: '۰:۰۰',
    average_pass_per_user: '۰:۰۰',
    average_overtime_per_user: '۰:۰۰',
    top_pass_user: null,
    top_overtime_user: null,
    top_department_name: '-',
    top_department_count: '۰',
    total_leave_taken: '۰',
    total_leave_requests: '۰',
    pass_percent: '۰',
    overtime_percent: '۰',
    pass_chart_data: [],
    overtime_chart_data: [],
};

const stats = reactive({ ...EMPTY });
const error = ref('');

const topOvertimeUser = computed(() => stats.top_overtime_user ?? { username: '-', total_ezafe_time: '-' });
const topPassUser = computed(() => stats.top_pass_user ?? { username: '-', total_pass_time: '-' });

async function loadStats() {
    error.value = '';

    try {
        const payload = await api.get('/admin/dashboard/stats', { baseURL: '' });

        Object.assign(stats, EMPTY, payload ?? {});
        await nextTick();
        runMotion(true);
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت اطلاعات داشبورد.';
    }
}

/* ── Motion — `initDashboardMotion` / `replayDashboardMotion` (admin.js) ── */

const BOX_ID = 'dashboardBox';
const spotlightCards = [];

function dashboardBox() {
    return document.getElementById(BOX_ID);
}

/** `persianDigitsToEnglish()` — `data-count-to` and `data-ring` arrive in Persian. */
function toLatinDigits(value) {
    const digits = '۰۱۲۳۴۵۶۷۸۹';

    return String(value ?? '').replace(/[۰-۹]/g, (character) => digits.indexOf(character));
}

function prefersReducedMotion() {
    return typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

function hoverCapable() {
    return typeof window !== 'undefined' && !!window.matchMedia?.('(hover: hover)').matches;
}

/** `animateDashboardCounter()` — the Persian roll-up on `[data-count-to]`. */
function animateCounter(element) {
    const target = Number.parseInt(toLatinDigits(element.dataset.countTo), 10);

    if (Number.isNaN(target)) {
        return;
    }

    if (target === 0 || prefersReducedMotion() || typeof window.requestAnimationFrame !== 'function') {
        element.textContent = toPersianDigits(target);
        return;
    }

    const duration = 850;
    let startedAt = null;
    element.textContent = toPersianDigits(0);

    const step = (timestamp) => {
        if (startedAt === null) {
            startedAt = timestamp;
        }

        const progress = Math.min(1, (timestamp - startedAt) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        element.textContent = toPersianDigits(Math.round(target * eased));

        if (progress < 1) {
            window.requestAnimationFrame(step);
        }
    };

    window.requestAnimationFrame(step);
}

/** `paintDashboardRing()` — the radius is read from the SVG, not assumed. */
function paintRing(circle, replay) {
    const percent = Number.parseFloat(toLatinDigits(circle.dataset.ring));

    if (Number.isNaN(percent)) {
        return;
    }

    const clamped = Math.max(0, Math.min(100, percent));
    const radius = Number.parseFloat(circle.getAttribute('r')) || 0;

    if (!radius) {
        return;
    }

    const circumference = 2 * Math.PI * radius;
    const target = circumference * (1 - clamped / 100);

    circle.style.transition = 'none';
    circle.style.strokeDasharray = circumference.toFixed(2);
    circle.style.strokeDashoffset = circumference.toFixed(2);

    if (!replay || prefersReducedMotion()) {
        circle.style.transition = '';
        circle.style.strokeDashoffset = target.toFixed(2);
        return;
    }

    void circle.getBoundingClientRect();
    circle.style.transition = '';
    window.requestAnimationFrame(() => {
        circle.style.strokeDashoffset = target.toFixed(2);
    });
}

/** `renderDashboardBarHeights()` — `--p` drives the `translateY` on `.bar-value`. */
function renderBars(replay) {
    dashboardBox()?.querySelectorAll('.dashboard-chart .bar-value').forEach((bar) => {
        const percent = Number.parseInt(bar.dataset.percent ?? '', 10);

        if (Number.isNaN(percent)) {
            return;
        }

        const ratio = Math.max(0, Math.min(100, percent)) / 100;

        if (replay && !prefersReducedMotion()) {
            bar.style.setProperty('--p', '0');
            void bar.offsetHeight;
        }

        bar.style.setProperty('--p', String(ratio));
    });
}

/** `attachDashboardSpotlight()` — `--mx` / `--my` follow the pointer on a card. */
function spotlight(event) {
    const card = event.currentTarget;
    const rect = card.getBoundingClientRect();

    if (!rect.width || !rect.height) {
        return;
    }

    card.style.setProperty('--mx', `${(((event.clientX - rect.left) / rect.width) * 100).toFixed(1)}%`);
    card.style.setProperty('--my', `${(((event.clientY - rect.top) / rect.height) * 100).toFixed(1)}%`);
}

function attachSpotlight(card) {
    if (spotlightCards.includes(card)) {
        return;
    }

    card.addEventListener('pointermove', spotlight);
    spotlightCards.push(card);
}

/** `initDashboardMotion()` / `replayDashboardMotion()` — one pass over the box. */
function runMotion(replay) {
    const box = dashboardBox();

    if (!box) {
        return;
    }

    try {
        renderBars(replay);
        box.querySelectorAll('[data-count-to]').forEach(animateCounter);
        box.querySelectorAll('[data-ring]').forEach((circle) => paintRing(circle, replay));

        if (hoverCapable() && !prefersReducedMotion()) {
            box.querySelectorAll('.dashboard-card').forEach(attachSpotlight);
        }
    } catch (failure) {
        console.warn('dashboard motion skipped', failure);
    }
}

onMounted(async () => {
    runMotion(true);
    await loadStats();
});

onBeforeUnmount(() => {
    spotlightCards.forEach((card) => card.removeEventListener('pointermove', spotlight));
    spotlightCards.length = 0;
});
</script>

<template>
    <header class="dash-head dash-anim" style="--i: 0">
        <div class="dash-head__main">
            <span class="dash-head__badge">نمای کلی سازمان</span>
            <h2 class="dash-head__title">داشبورد مدیریت</h2>
            <p class="dash-head__sub">تصویر زندهٔ کارکرد، پاس ساعتی، اضافه‌کاری و مرخصی کارکنان</p>
        </div>
        <div class="dash-head__stats">
            <span class="dash-chip dash-chip--live"><i aria-hidden="true"></i>دادهٔ زنده</span>
            <span class="dash-chip">{{ stats.total_users }} پرسنل فعال</span>
            <span class="dash-chip">{{ stats.unique_departments }} دپارتمان</span>
        </div>
    </header>

    <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

    <section class="dash-bento">
        <article class="dashboard-quick-card dash-anim" style="--i: 1">
            <span class="dash-orb dash-orb--a" aria-hidden="true"></span>
            <span class="dash-orb dash-orb--b" aria-hidden="true"></span>
            <div class="quick-card-head">
                <span class="quick-card-title">اشتراک حرفه‌ای</span>
                <span class="dash-pill">فعال</span>
            </div>
            <div class="quick-card-ring">
                <svg viewBox="0 0 120 120" role="img" aria-label="اشتراک حرفه‌ای فعال است">
                    <defs>
                        <linearGradient id="dashHeroRing" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#5eead4"></stop>
                            <stop offset="1" stop-color="#7dd3fc"></stop>
                        </linearGradient>
                    </defs>
                    <circle class="ring-track" cx="60" cy="60" r="52"></circle>
                    <circle class="ring-value" cx="60" cy="60" r="52" data-ring="63"></circle>
                </svg>
                <div class="quick-card-ring-text">
                    <strong>۲۳۱</strong>
                    <span>روز باقی‌مانده</span>
                </div>
            </div>
            <span class="quick-card-meta">اعتبار تا ۱۴۰۵/۱۲/۲۹</span>
            <button class="quick-card-btn" type="button">
                <span>تمدید اشتراک</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"></path></svg>
            </button>
        </article>

        <article class="dashboard-card dashboard-card--blue dash-anim" style="--i: 2">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"></circle><path d="M3.4 19c.2-3.1 2.6-5.2 5.6-5.2s5.4 2.1 5.6 5.2"></path><path d="M16.4 5.6a3 3 0 0 1 0 5.8"></path><path d="M17.8 19c-.1-2.1-.8-3.8-1.9-4.9"></path></svg>
            </span>
            <span class="card-title">کل پرسنل فعال</span>
            <span class="card-value" :data-count-to="stats.total_users">{{ stats.total_users }}</span>
            <span class="card-meta">کارکنان فعال سامانه</span>
        </article>

        <article class="dashboard-card dashboard-card--indigo dash-anim" style="--i: 3">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4.5 20V6.6c0-.8.7-1.5 1.5-1.5h5.5c.8 0 1.5.7 1.5 1.5V20"></path><path d="M13 10.5h4.5c.8 0 1.5.7 1.5 1.5V20"></path><path d="M2.8 20h18.4"></path><path d="M7.5 9h3M7.5 12.5h3M7.5 16h3M16 14h.8M16 17h.8"></path></svg>
            </span>
            <span class="card-title">دپارتمان‌های فعال</span>
            <span class="card-value" :data-count-to="stats.unique_departments">{{ stats.unique_departments }}</span>
            <span class="card-meta">واحد سازمانی فعال</span>
        </article>

        <article class="dashboard-card dashboard-card--teal dash-anim" style="--i: 4">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="7.4"></circle><path d="M12 9.6V13l2.4 1.9"></path><path d="M9.6 3.2h4.8"></path></svg>
            </span>
            <span class="card-title">کارکنان با اضافه‌کاری</span>
            <span class="card-value" :data-count-to="stats.overtime_user_count">{{ stats.overtime_user_count }}</span>
            <span class="card-meta">دارای ثبت اضافه‌کاری</span>
        </article>

        <article class="dashboard-card dashboard-card--green dash-anim" style="--i: 5">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M20.4 14.2A8.5 8.5 0 0 1 9.8 3.5a8.5 8.5 0 1 0 10.6 10.7z"></path></svg>
            </span>
            <span class="card-title">بدون اضافه‌کاری</span>
            <span class="card-value" :data-count-to="stats.no_overtime_users">{{ stats.no_overtime_users }}</span>
            <span class="card-meta">بدون ثبت اضافه‌کاری</span>
        </article>

        <article class="dashboard-card dashboard-card--amber dash-anim" style="--i: 6">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3.5 16.8l5.2-5.2 3.4 3.4 6.4-6.6"></path><path d="M14.2 8.4h5.1v5.1"></path></svg>
            </span>
            <span class="card-title">کل اضافه‌کاری ماه</span>
            <span class="card-value">{{ stats.total_overtime_time }}</span>
            <span class="card-meta">مجموع ثبت‌شدهٔ ماه</span>
        </article>

        <article class="dashboard-card dashboard-card--sky dash-anim" style="--i: 7">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7.5 3.6h9M7.5 20.4h9"></path><path d="M8.6 3.6v3.1c0 2 3.4 3.5 3.4 5.3s-3.4 3.3-3.4 5.3v3.1"></path><path d="M15.4 3.6v3.1c0 2-3.4 3.5-3.4 5.3s3.4 3.3 3.4 5.3v3.1"></path></svg>
            </span>
            <span class="card-title">پاس ساعتی کل</span>
            <span class="card-value">{{ stats.total_pass_time }}</span>
            <span class="card-meta">مجموع پاس تأییدشده</span>
        </article>

        <article class="dashboard-card dashboard-card--violet dash-anim" style="--i: 8">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M17.5 5.4H7l5.2 6.6L7 18.6h10.5"></path></svg>
            </span>
            <span class="card-title">میانگین اضافه‌کاری</span>
            <span class="card-value">{{ stats.average_overtime_per_user }}</span>
            <span class="card-meta">برای هر کاربر</span>
        </article>

        <article class="dashboard-card dashboard-card--rose dash-anim" style="--i: 9">
            <span class="card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3.6 12.6h4L10 6.2l3.4 11.6 2.4-5.2h4.6"></path></svg>
            </span>
            <span class="card-title">میانگین پاس</span>
            <span class="card-value">{{ stats.average_pass_per_user }}</span>
            <span class="card-meta">برای هر کاربر</span>
        </article>
    </section>

    <section class="dash-row dash-row--podium">
        <article
            class="dashboard-card dashboard-card--highlight dash-anim"
            style="--i: 10; --tint: var(--dash-amber); --tint-soft: rgba(217, 119, 6, .18); --tint-glow: rgba(217, 119, 6, .16)"
        >
            <span class="dash-medal" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 3.5s4.5 4 4.5 8.2a4.5 4.5 0 0 1-9 0c0-1.6.8-3.1 1.7-4.1.3 1.2 1 2 1.9 2.3.5-2 .9-3.9.9-6.4z"></path></svg>
            </span>
            <span class="card-title">بیشترین اضافه‌کاری</span>
            <span class="card-value">{{ topOvertimeUser.username }}</span>
            <span class="card-meta">{{ topOvertimeUser.total_ezafe_time }}</span>
        </article>

        <article
            class="dashboard-card dashboard-card--highlight dash-anim"
            style="--i: 11; --tint: var(--dash-violet); --tint-soft: rgba(124, 58, 237, .16); --tint-glow: rgba(124, 58, 237, .16)"
        >
            <span class="dash-medal" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 3.7l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.9-5.4 2.9 1-6L3.3 10.1l6-.9z"></path></svg>
            </span>
            <span class="card-title">پاس ساعتی برتر</span>
            <span class="card-value">{{ topPassUser.username }}</span>
            <span class="card-meta">{{ topPassUser.total_pass_time }}</span>
        </article>

        <article
            class="dashboard-card dashboard-card--highlight dash-anim"
            style="--i: 12; --tint: var(--dash-teal); --tint-soft: rgba(13, 148, 136, .18); --tint-glow: rgba(13, 148, 136, .16)"
        >
            <span class="dash-medal" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4.5 20V6.6c0-.8.7-1.5 1.5-1.5h5.5c.8 0 1.5.7 1.5 1.5V20"></path><path d="M13 10.5h4.5c.8 0 1.5.7 1.5 1.5V20"></path><path d="M2.8 20h18.4"></path><path d="M7.5 9h3M7.5 12.5h3M7.5 16h3M16 14h.8M16 17h.8"></path></svg>
            </span>
            <span class="card-title">دپارتمان برتر</span>
            <span class="card-value">{{ stats.top_department_name }}</span>
            <span class="card-meta">{{ stats.top_department_count }} نفر</span>
        </article>
    </section>

    <section class="dash-row dash-row--insight">
        <article class="dashboard-card dashboard-card--panel dashboard-card--teal dash-anim" style="--i: 13">
            <div class="dash-panel-head">
                <span class="dash-panel-title">شاخص‌های بهره‌وری</span>
                <span class="dash-tag">ظرفیت ساعتی ماه</span>
            </div>
            <div class="dash-rings">
                <div class="dash-ring">
                    <div class="dash-ring__visual">
                        <svg viewBox="0 0 100 100" role="img" aria-label="نسبت پاس ساعتی">
                            <circle class="dash-ring__track" cx="50" cy="50" r="44"></circle>
                            <circle class="dash-ring__value" cx="50" cy="50" r="44" :data-ring="stats.pass_percent"></circle>
                        </svg>
                        <div class="dash-ring__text">
                            <strong><span :data-count-to="stats.pass_percent">{{ stats.pass_percent }}</span>%</strong>
                        </div>
                    </div>
                    <span class="dash-ring__label">نسبت پاس</span>
                </div>
                <div
                    class="dash-ring"
                    style="--tint: var(--dash-amber); --tint-soft: rgba(217, 119, 6, .14); --tint-glow: rgba(217, 119, 6, .3)"
                >
                    <div class="dash-ring__visual">
                        <svg viewBox="0 0 100 100" role="img" aria-label="نسبت اضافه‌کاری">
                            <circle class="dash-ring__track" cx="50" cy="50" r="44"></circle>
                            <circle
                                class="dash-ring__value"
                                cx="50"
                                cy="50"
                                r="44"
                                :data-ring="stats.overtime_percent"
                            ></circle>
                        </svg>
                        <div class="dash-ring__text">
                            <strong><span :data-count-to="stats.overtime_percent">{{ stats.overtime_percent }}</span>%</strong>
                        </div>
                    </div>
                    <span class="dash-ring__label">نسبت اضافه‌کاری</span>
                </div>
            </div>
        </article>

        <article class="dashboard-card dashboard-card--panel dashboard-card--rose dash-anim" style="--i: 14">
            <div class="dash-panel-head">
                <span class="dash-panel-title">مرخصی کارکنان</span>
                <span class="dash-tag">جمع کل</span>
            </div>
            <div class="dash-stat-list">
                <div class="dash-stat">
                    <span class="card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M6.2 5.2h11.6c.9 0 1.6.7 1.6 1.6v11.6c0 .9-.7 1.6-1.6 1.6H6.2c-.9 0-1.6-.7-1.6-1.6V6.8c0-.9.7-1.6 1.6-1.6z"></path><path d="M4.6 9.8h14.8"></path><path d="M8.4 3.2v3.6M15.6 3.2v3.6"></path></svg>
                    </span>
                    <span>
                        <span class="card-title">کل مرخصی استفاده‌شده</span>
                        <span class="card-value" :data-count-to="stats.total_leave_taken">{{ stats.total_leave_taken }}</span>
                    </span>
                    <span class="dash-stat__unit">روز</span>
                </div>
                <div class="dash-stat">
                    <span class="card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.4"></circle><path d="M12 7.6V12l3 1.9"></path></svg>
                    </span>
                    <span>
                        <span class="card-title">درخواست‌های مرخصی</span>
                        <span class="card-value" :data-count-to="stats.total_leave_requests">{{ stats.total_leave_requests }}</span>
                    </span>
                    <span class="dash-stat__unit">درخواست</span>
                </div>
            </div>
        </article>
    </section>

    <section class="dash-row dash-row--charts">
        <article class="dashboard-chart-card dash-anim" style="--i: 15">
            <div class="dash-card-head">
                <span class="card-title">نمودار پاس ساعتی — کاربران برتر</span>
                <span class="dash-tag">۵ نفر برتر</span>
            </div>
            <div class="dashboard-chart">
                <div
                    v-for="row in stats.pass_chart_data"
                    :key="`pass-${row.username}`"
                    class="chart-bar"
                >
                    <span class="bar-number">{{ row.display }}</span>
                    <div class="bar-fill">
                        <div class="bar-value" :data-percent="row.percent"></div>
                    </div>
                    <span class="bar-label" :title="row.username">{{ row.username }}</span>
                </div>
                <p v-if="!stats.pass_chart_data.length" class="dash-empty">هنوز پاس ساعتی تأییدشده‌ای ثبت نشده است.</p>
            </div>
        </article>

        <article class="dashboard-chart-card dashboard-chart-card--overtime dash-anim" style="--i: 16">
            <div class="dash-card-head">
                <span class="card-title">نمودار اضافه‌کاری — کاربران برتر</span>
                <span class="dash-tag">۵ نفر برتر</span>
            </div>
            <div class="dashboard-chart">
                <div
                    v-for="row in stats.overtime_chart_data"
                    :key="`overtime-${row.username}`"
                    class="chart-bar"
                >
                    <span class="bar-number">{{ row.display }}</span>
                    <div class="bar-fill">
                        <div class="bar-value overtime" :data-percent="row.percent"></div>
                    </div>
                    <span class="bar-label" :title="row.username">{{ row.username }}</span>
                </div>
                <p v-if="!stats.overtime_chart_data.length" class="dash-empty">هنوز اضافه‌کاری‌ای برای این ماه ثبت نشده است.</p>
            </div>
        </article>
    </section>

    <section class="dash-row dash-row--tables">
        <article class="dashboard-table-card dash-anim" style="--i: 17">
            <div class="dash-card-head">
                <span class="table-title">جدول پاس‌های برتر</span>
                <span class="dash-tag">پاس کل</span>
            </div>
            <table v-if="stats.pass_chart_data.length" class="dashboard-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>کاربر</th>
                        <th>پاس کل</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in stats.pass_chart_data" :key="`pass-row-${row.username}`">
                        <td>{{ index + 1 }}</td>
                        <td>{{ row.username }}</td>
                        <td>{{ row.display }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="dash-empty">داده‌ای برای نمایش نیست.</p>
        </article>

        <article class="dashboard-table-card dashboard-table-card--overtime dash-anim" style="--i: 18">
            <div class="dash-card-head">
                <span class="table-title">جدول اضافه‌کاری برتر</span>
                <span class="dash-tag">اضافه‌کاری کل</span>
            </div>
            <table v-if="stats.overtime_chart_data.length" class="dashboard-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>کاربر</th>
                        <th>اضافه‌کاری کل</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in stats.overtime_chart_data" :key="`overtime-row-${row.username}`">
                        <td>{{ index + 1 }}</td>
                        <td>{{ row.username }}</td>
                        <td>{{ row.display }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="dash-empty">داده‌ای برای نمایش نیست.</p>
        </article>
    </section>
</template>
