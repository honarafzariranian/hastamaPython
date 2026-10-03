<script setup>
/**
 * The laboratory rules page — the Vue equivalent of `app/templates/rules.html`.
 *
 * The template is the legacy markup element for element: the same divs, the same
 * class names, the same order, the same SVG icons and the same Persian labels.
 * The stylesheet that styles it (`resources/css/legacy/rules.css`, ported
 * verbatim from `app/static/css/rules.css`) is already loaded globally, so this
 * component carries no styles of its own.
 *
 * What the legacy `rules.js` added on top is ported here:
 *
 *   * a reading-progress bar that fills with the scroll position;
 *   * a sticky table of contents that highlights the section in view;
 *   * a scroll-reveal for each section (the `.visible` class is the legacy
 *     one — rules.css defines `.rl-section.visible`, not `.is-visible`);
 *   * a mobile toggle that collapses the TOC into a panel (`.rl-toc.open`).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

const { isDark, toggleTheme } = useTheme();

const logoUrl = '/images/login-mobile-logo.png';

const sections = [
    { id: 's1', num: '۱', title: 'مقدمه' },
    { id: 's2', num: '۲', title: 'تعاریف' },
    { id: 's3', num: '۳', title: 'شرایط استفاده' },
    { id: 's4', num: '۴', title: 'حساب کاربری' },
    { id: 's5', num: '۵', title: 'امنیت اطلاعات' },
    { id: 's6', num: '۶', title: 'ثبت و مدیریت اطلاعات' },
    { id: 's7', num: '۷', title: 'قوانین حضور و غیاب' },
    { id: 's8', num: '۸', title: 'مسئولیت کاربران' },
    { id: 's9', num: '۹', title: 'استفاده مجاز' },
    { id: 's10', num: '۱۰', title: 'فعالیت‌های غیرمجاز' },
    { id: 's11', num: '۱۱', title: 'حریم خصوصی' },
    { id: 's12', num: '۱۲', title: 'پشتیبانی و گزارش خطا' },
    { id: 's13', num: '۱۳', title: 'تغییر قوانین' },
];

const overviewCards = [
    { icon: '🛡️', modifier: 'shield', title: 'امنیت', text: 'حفاظت از اطلاعات حساب کاربری و رعایت اصول امنیتی.' },
    { icon: '👤', modifier: 'user', title: 'مسئولیت کاربر', text: 'هر کاربر مسئول حفظ اطلاعات ورود و فعالیت‌های حساب خود است.' },
    { icon: '🕐', modifier: 'clock', title: 'حضور و ثبت اطلاعات', text: 'اطلاعات ثبت‌شده در سامانه باید مطابق واقعیت و قوانین مجموعه باشد.' },
    { icon: '📋', modifier: 'file', title: 'رعایت مقررات', text: 'استفاده از امکانات هستما باید مطابق قوانین مجموعه و سیاست‌های سامانه باشد.' },
];

const definitions = [
    { term: 'سامانه', desc: 'منظور از سامانه، نرم‌افزار و خدمات مرتبط با هستما است.' },
    { term: 'کاربر', desc: 'هر شخصی که با مجوز مجموعه به سامانه دسترسی دارد.' },
    { term: 'مدیر سامانه', desc: 'شخص یا اشخاصی که مسئول مدیریت، تنظیمات و نظارت بر سامانه هستند.' },
    { term: 'اطلاعات کاربر', desc: 'اطلاعاتی که در ارتباط با حساب کاربری، فعالیت‌ها و فرآیندهای سامانه ثبت یا پردازش می‌شود.' },
];

const accountRules = [
    { icon: 'shield', style: 'background:rgba(47,124,246,0.1);color:var(--rl-brand-1)', text: 'هر کاربر باید فقط از حساب کاربری اختصاصی خود استفاده کند.' },
    { icon: 'lock', style: 'background:rgba(47,124,246,0.1);color:var(--rl-brand-1)', text: 'اطلاعات ورود باید محرمانه نگهداری شود.' },
    { icon: 'users', style: 'background:rgba(239,68,68,0.1);color:var(--rl-red)', text: 'اشتراک‌گذاری نام کاربری و رمز عبور با دیگران مجاز نیست.' },
    { icon: 'alert', style: 'background:rgba(245,158,11,0.1);color:var(--rl-amber)', text: 'کاربر مسئول فعالیت‌هایی است که با حساب او انجام می‌شود.' },
    { icon: 'refresh', style: 'background:rgba(20,184,166,0.1);color:var(--rl-brand-3)', text: 'در صورت مشاهده فعالیت مشکوک، کاربر باید موضوع را به مدیر سامانه اطلاع دهد.' },
    { icon: 'eyeOff', style: 'background:rgba(239,68,68,0.1);color:var(--rl-red)', text: 'کاربر نباید اطلاعات ورود خود را در اختیار افراد غیرمجاز قرار دهد.' },
];

const securityCards = [
    { icon: 'lock', title: 'رمز عبور امن', text: 'از رمز عبور خود محافظت کنید.' },
    { icon: 'users', title: 'عدم اشتراک‌گذاری', text: 'اطلاعات ورود خود را در اختیار دیگران قرار ندهید.' },
    { icon: 'report', title: 'گزارش فعالیت مشکوک', text: 'هر فعالیت غیرعادی را سریعاً گزارش کنید.' },
    { icon: 'logout', title: 'خروج از حساب', text: 'پس از پایان کار، در سیستم‌های عمومی یا مشترک از حساب خود خارج شوید.' },
];

const warnCards = ['اطلاعات نادرست', 'تغییر غیرمجاز', 'حذف غیرمجاز', 'ثبت اطلاعات به جای کاربر دیگر'];

const responsibilities = [
    'حفظ اطلاعات ورود',
    'استفاده صحیح از امکانات',
    'ثبت اطلاعات صحیح',
    'رعایت سطح دسترسی',
    'گزارش مشکلات و فعالیت‌های مشکوک',
    'رعایت قوانین مجموعه',
];

const allowedUses = [
    'استفاده مطابق سطح دسترسی',
    'ثبت اطلاعات صحیح',
    'مشاهده اطلاعات مجاز',
    'استفاده از امکانات تعریف‌شده',
    'گزارش مشکلات',
    'رعایت قوانین مجموعه',
];

const prohibitedActs = [
    'تلاش برای دسترسی غیرمجاز',
    'استفاده از حساب کاربری دیگران',
    'اشتراک‌گذاری اطلاعات ورود',
    'تغییر یا حذف غیرمجاز اطلاعات',
    'ایجاد اختلال در سامانه',
    'استفاده از روش‌های غیرمجاز برای دور زدن محدودیت‌ها',
    'استخراج یا انتشار اطلاعات بدون مجوز',
    'استفاده از سامانه برای فعالیت‌هایی که با قوانین مجموعه مغایرت دارد',
];

const progress = ref(0);
const activeSection = ref(sections[0].id);
const tocOpen = ref(false);

let revealObserver = null;
let ticking = false;

function onScroll() {
    if (ticking) {
        return;
    }

    ticking = true;

    requestAnimationFrame(() => {
        const doc = document.documentElement;
        const max = doc.scrollHeight - doc.clientHeight;
        progress.value = max > 0 ? Math.min((doc.scrollTop / max) * 100, 100) : 0;

        let current = sections[0].id;

        for (const section of sections) {
            const el = document.getElementById(section.id);

            if (el && el.getBoundingClientRect().top <= 140) {
                current = section.id;
            }
        }

        activeSection.value = current;
        ticking = false;
    });
}

onMounted(() => {
    const doc = document.documentElement;
    const max = doc.scrollHeight - doc.clientHeight;
    progress.value = max > 0 ? Math.min((doc.scrollTop / max) * 100, 100) : 0;

    if ('IntersectionObserver' in window) {
        revealObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        revealObserver.unobserve(entry.target);
                    }
                }
            },
            { threshold: 0.08, rootMargin: '0px 0px -40px 0px' },
        );

        document.querySelectorAll('.rl-section').forEach((el) => revealObserver.observe(el));
    } else {
        document.querySelectorAll('.rl-section').forEach((el) => el.classList.add('visible'));
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', onScroll);
    revealObserver?.disconnect();
});
</script>

<template>
    <div class="rl">
        <!-- Reading Progress -->
        <div class="rl-progress" aria-hidden="true">
            <div class="rl-progress__bar" :style="{ width: progress + '%' }"></div>
        </div>

        <!-- Top Navigation -->
        <nav class="rl-topnav" role="navigation" aria-label="ناوبری صفحه">
            <div class="rl-topnav__brand">
                <img :src="logoUrl" alt="هستما">
            </div>
            <div class="rl-topnav__actions">
                <button type="button" class="rl-theme-btn" data-action="toggle-theme" aria-label="تغییر تم" @click="toggleTheme">
                    <span class="rl-theme-btn__moon">🌙</span>
                    <span class="rl-theme-btn__sun" :style="{ display: isDark ? 'inline' : 'none' }">☀️</span>
                </button>
                <RouterLink to="/login" class="rl-topnav__back">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
                    بازگشت به ورود
                </RouterLink>
            </div>
        </nav>

        <!-- Hero -->
        <section class="rl-hero">
            <div class="rl-hero__bg" aria-hidden="true">
                <div class="rl-hero__grid"></div>
                <div class="rl-hero__orb rl-hero__orb--1"></div>
                <div class="rl-hero__orb rl-hero__orb--2"></div>
                <div class="rl-hero__orb rl-hero__orb--3"></div>
            </div>
            <div class="rl-hero__content">
                <div class="rl-hero__badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    راهنمای استفاده از هستما
                </div>
                <h1 class="rl-hero__title">قوانین و مقررات استفاده از <span>سامانه هستما</span></h1>
                <p class="rl-hero__desc">برای استفاده بهتر، امن‌تر و حرفه‌ای‌تر از هستما، لطفاً قوانین و مقررات زیر را با دقت مطالعه کنید.</p>
            </div>
        </section>

        <!-- Overview Cards -->
        <section class="rl-overview">
            <div class="rl-overview__grid">
                <div v-for="card in overviewCards" :key="card.title" class="rl-overview-card">
                    <div class="rl-overview-card__icon" :class="`rl-overview-card__icon--${card.modifier}`">{{ card.icon }}</div>
                    <div class="rl-overview-card__title">{{ card.title }}</div>
                    <div class="rl-overview-card__text">{{ card.text }}</div>
                </div>
            </div>
        </section>

        <!-- Main Content -->
        <main class="rl-main">
            <!-- Sticky TOC -->
            <aside class="rl-toc" :class="{ open: tocOpen }" role="navigation" aria-label="فهرست مطالب">
                <div class="rl-toc__title">فهرست مطالب</div>
                <ol class="rl-toc__list">
                    <li v-for="section in sections" :key="section.id" class="rl-toc__item">
                        <a
                            :href="'#' + section.id"
                            :class="{ active: activeSection === section.id }"
                            @click="tocOpen = false"
                        >
                            <span class="rl-toc__num">{{ section.num }}</span>{{ section.title }}
                        </a>
                    </li>
                </ol>
            </aside>

            <!-- Content Sections -->
            <div class="rl-content">
                <!-- Section 1 — مقدمه -->
                <section class="rl-section" id="s1">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۱</div>
                        <h2 class="rl-section__title">مقدمه</h2>
                    </div>
                    <p>سامانه هستما یک بستر نرم‌افزاری برای مدیریت و ثبت اطلاعات مرتبط با حضور، فعالیت و فرآیندهای سازمانی است. هدف سامانه، ساده‌سازی فرآیندها، افزایش دقت اطلاعات، کاهش خطاهای انسانی و ایجاد دسترسی سریع و منظم به اطلاعات مورد نیاز کاربران است.</p>
                    <p>استفاده از سامانه به معنی پذیرش قوانین و مقررات تعیین‌شده توسط مجموعه و رعایت الزامات امنیتی و اجرایی آن است.</p>
                </section>

                <!-- Section 2 — تعاریف -->
                <section class="rl-section" id="s2">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۲</div>
                        <h2 class="rl-section__title">تعاریف</h2>
                    </div>
                    <div class="rl-defs">
                        <div v-for="def in definitions" :key="def.term" class="rl-def-card">
                            <div class="rl-def-card__term">{{ def.term }}</div>
                            <div class="rl-def-card__desc">{{ def.desc }}</div>
                        </div>
                    </div>
                </section>

                <!-- Section 3 — شرایط استفاده -->
                <section class="rl-section" id="s3">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۳</div>
                        <h2 class="rl-section__title">شرایط استفاده از سامانه</h2>
                    </div>
                    <p>کاربر باید فقط در حدود دسترسی تعیین‌شده توسط مجموعه از سامانه استفاده کند.</p>
                    <p>استفاده از حساب کاربری دیگران، تلاش برای دسترسی به بخش‌های غیرمجاز، تغییر غیرمجاز اطلاعات یا ایجاد اختلال در عملکرد سامانه مجاز نیست.</p>
                    <p>کاربر باید اطلاعات ارائه‌شده در سامانه را با دقت و مطابق واقعیت ثبت کند.</p>
                    <div class="rl-info-box rl-info-box--info">
                        <div class="rl-info-box__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        </div>
                        <div>استفاده از هستما باید در چارچوب دسترسی و مسئولیت تعیین‌شده برای هر کاربر انجام شود.</div>
                    </div>
                </section>

                <!-- Section 4 — حساب کاربری -->
                <section class="rl-section" id="s4">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۴</div>
                        <h2 class="rl-section__title">حساب کاربری</h2>
                    </div>
                    <ul class="rl-rules">
                        <li v-for="rule in accountRules" :key="rule.text" class="rl-rule">
                            <span class="rl-rule__icon" :style="rule.style">
                                <svg v-if="rule.icon === 'shield'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                <svg v-else-if="rule.icon === 'lock'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <svg v-else-if="rule.icon === 'users'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                                <svg v-else-if="rule.icon === 'alert'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                                <svg v-else-if="rule.icon === 'refresh'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 1l22 22M16.72 11.06A10.94 10.94 0 0 1 19 12.55M5 12.55a10.94 10.94 0 0 1 5.17-2.39M10.71 5.05A16 16 0 0 1 22.56 9M1.42 9a15.91 15.91 0 0 1 4.7-2.88M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/></svg>
                            </span>
                            {{ rule.text }}
                        </li>
                    </ul>
                </section>

                <!-- Section 5 — امنیت اطلاعات -->
                <section class="rl-section" id="s5">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۵</div>
                        <h2 class="rl-section__title">امنیت اطلاعات</h2>
                    </div>
                    <p>امنیت اطلاعات یکی از اصول اصلی استفاده از هستما است.</p>
                    <p>کاربران باید از اطلاعات ورود خود محافظت کنند و از انجام اقداماتی که می‌تواند امنیت سامانه یا اطلاعات سایر کاربران را تحت تأثیر قرار دهد خودداری کنند.</p>
                    <div class="rl-security-grid">
                        <div v-for="card in securityCards" :key="card.title" class="rl-security-card">
                            <div class="rl-security-card__icon">
                                <svg v-if="card.icon === 'lock'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <svg v-else-if="card.icon === 'users'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>
                                <svg v-else-if="card.icon === 'report'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><path d="M21 3v9h-9"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            </div>
                            <div>
                                <div class="rl-security-card__title">{{ card.title }}</div>
                                <div class="rl-security-card__text">{{ card.text }}</div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Section 6 — ثبت و مدیریت اطلاعات -->
                <section class="rl-section" id="s6">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۶</div>
                        <h2 class="rl-section__title">ثبت و مدیریت اطلاعات</h2>
                    </div>
                    <p>اطلاعات ثبت‌شده در سامانه باید دقیق، صحیح و مطابق فرآیندهای تعریف‌شده توسط مجموعه باشد.</p>
                    <p>هرگونه ثبت اطلاعات نادرست، تغییر غیرمجاز یا حذف اطلاعات بدون مجوز ممنوع است.</p>
                    <div class="rl-warn-grid">
                        <div v-for="card in warnCards" :key="card" class="rl-warn-card">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            {{ card }}
                        </div>
                    </div>
                </section>

                <!-- Section 7 — قوانین حضور و غیاب -->
                <section class="rl-section" id="s7">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۷</div>
                        <h2 class="rl-section__title">قوانین مربوط به حضور و غیاب</h2>
                    </div>
                    <p>اطلاعات حضور و غیاب باید بر اساس فرآیندهای تعریف‌شده توسط مجموعه ثبت و بررسی شود.</p>
                    <p>کاربران موظف هستند از ثبت یا تغییر غیرمجاز اطلاعات حضور و غیاب خود یا دیگران خودداری کنند.</p>
                    <p>در صورت مشاهده مغایرت در اطلاعات، موضوع باید از طریق مسئول یا مدیر مربوطه پیگیری شود.</p>
                    <div class="rl-info-box rl-info-box--warn">
                        <div class="rl-info-box__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div>جزئیات قوانین حضور، تأخیر، اضافه‌کاری، مأموریت، مرخصی و سایر موارد می‌تواند بر اساس سیاست‌های هر مجموعه متفاوت باشد.</div>
                    </div>
                </section>

                <!-- Section 8 — مسئولیت کاربران -->
                <section class="rl-section" id="s8">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۸</div>
                        <h2 class="rl-section__title">مسئولیت کاربران</h2>
                    </div>
                    <div class="rl-num-grid">
                        <div v-for="(item, index) in responsibilities" :key="item" class="rl-num-card">
                            <div class="rl-num-card__num">{{ toPersianDigits(String(index + 1).padStart(2, '0')) }}</div>
                            <div class="rl-num-card__text">{{ item }}</div>
                        </div>
                    </div>
                </section>

                <!-- Section 9 — استفاده مجاز -->
                <section class="rl-section" id="s9">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۹</div>
                        <h2 class="rl-section__title">استفاده مجاز از سامانه</h2>
                    </div>
                    <ul class="rl-allowed-list">
                        <li v-for="item in allowedUses" :key="item" class="rl-allowed-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                            {{ item }}
                        </li>
                    </ul>
                </section>

                <!-- Section 10 — فعالیت‌های غیرمجاز -->
                <section class="rl-section" id="s10">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۱۰</div>
                        <h2 class="rl-section__title">فعالیت‌های غیرمجاز</h2>
                    </div>
                    <ul class="rl-rules">
                        <li v-for="item in prohibitedActs" :key="item" class="rl-rule">
                            <span class="rl-rule__icon" style="background:rgba(239,68,68,0.1);color:var(--rl-red)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </span>
                            {{ item }}
                        </li>
                    </ul>
                    <div class="rl-info-box rl-info-box--danger">
                        <div class="rl-info-box__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div>در صورت مشاهده فعالیت غیرمجاز، دسترسی کاربر ممکن است مطابق سیاست‌های مجموعه محدود یا بررسی شود.</div>
                    </div>
                </section>

                <!-- Section 11 — حریم خصوصی -->
                <section class="rl-section" id="s11">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۱۱</div>
                        <h2 class="rl-section__title">حریم خصوصی و اطلاعات کاربران</h2>
                    </div>
                    <p>اطلاعات کاربران باید فقط در چارچوب اهداف و فرآیندهای تعریف‌شده توسط مجموعه استفاده شود.</p>
                    <p>دسترسی به اطلاعات باید بر اساس سطح دسترسی تعیین‌شده انجام شود.</p>
                    <p>کاربران نباید اطلاعات مربوط به سایر افراد را بدون مجوز مشاهده، کپی، منتشر یا در اختیار اشخاص غیرمجاز قرار دهند.</p>
                    <div class="rl-info-box rl-info-box--info">
                        <div class="rl-info-box__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>جزئیات نگهداری، پردازش و اشتراک‌گذاری اطلاعات می‌تواند تابع سیاست‌های حریم خصوصی و مقررات سازمان استفاده‌کننده از هستما باشد.</div>
                    </div>
                </section>

                <!-- Section 12 — پشتیبانی و گزارش خطا -->
                <section class="rl-section" id="s12">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۱۲</div>
                        <h2 class="rl-section__title">پشتیبانی و گزارش خطا</h2>
                    </div>
                    <p>در صورت مشاهده خطا، مشکل امنیتی، مغایرت اطلاعات یا رفتار غیرعادی سامانه، کاربر باید موضوع را از مسیر پشتیبانی یا مسئول مربوطه پیگیری کند.</p>
                    <p>لطفاً هنگام گزارش مشکل، توضیحات کافی و اطلاعات مورد نیاز برای بررسی را ارائه کنید.</p>
                    <div class="rl-support-card">
                        <div class="rl-support-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r=".5" fill="currentColor"/></svg>
                        </div>
                        <div>
                            <div class="rl-support-card__title">مشکلی در سامانه مشاهده کرده‌اید؟</div>
                            <div class="rl-support-card__text">موضوع را از مسیر پشتیبانی مجموعه گزارش کنید تا بررسی شود.</div>
                        </div>
                    </div>
                </section>

                <!-- Section 13 — تغییر قوانین -->
                <section class="rl-section" id="s13">
                    <div class="rl-section__head">
                        <div class="rl-section__num">۱۳</div>
                        <h2 class="rl-section__title">تغییر قوانین و مقررات</h2>
                    </div>
                    <p>ممکن است قوانین، امکانات و فرآیندهای هستما در طول زمان به‌روزرسانی شوند.</p>
                    <p>در صورت ایجاد تغییرات مهم، نسخه جدید قوانین از طریق مسیرهای تعیین‌شده توسط مجموعه اطلاع‌رسانی خواهد شد.</p>
                    <p>ادامه استفاده از سامانه پس از اعمال تغییرات، تابع قوانین و مقررات به‌روزشده خواهد بود.</p>
                </section>

                <!-- پذیرش ضمنی قوانین -->
                <section class="rl-section rl-final" id="s14">
                    <div class="rl-info-box rl-info-box--info" style="margin-top:12px">
                        <div class="rl-info-box__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>با ثبت‌نام و ورود به سامانه هستما، قوانین و مقررات فوق‌الذکر را به‌طور ضمنی پذیرفته‌اید.</div>
                    </div>
                    <RouterLink to="/login" class="rl-cta-btn" style="margin-top:20px">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
                        بازگشت به ورود
                    </RouterLink>
                </section>
            </div>
        </main>

        <!-- Footer -->
        <footer class="rl-footer">هستما — سامانه مدیریت هوشمند</footer>

        <!-- Mobile TOC Toggle -->
        <button
            type="button"
            class="rl-toc-toggle"
            :aria-expanded="String(tocOpen)"
            aria-label="فهرست مطالب"
            @click="tocOpen = !tocOpen"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        </button>
    </div>
</template>

<style scoped>
/* The legacy topnav is a fixed frosted-glass bar; inside the application shell
   it sits over the shell's own sticky header, so the glass is made opaque to
   keep the two headers from ghosting through each other. */
.rl-topnav {
    background: var(--rl-surface);
}
</style>
