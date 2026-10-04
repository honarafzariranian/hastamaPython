<script setup>
/**
 * The dashboard — the Vue equivalent of the legacy `.dashboard-main` block.
 *
 * Three reads, in the legacy order:
 *   * `GET /get_today_date` — today as three Jalali integers (bare object, no
 *     envelope — the one endpoint that answers without `success`/`data`);
 *   * `GET /get_user_info` — the signed-in user's profile block;
 *   * `GET /get_hozoor_today` — today's presence for the caller.
 *
 * The attendance card reproduces the legacy check-in / check-out actions:
 * `POST /sabt_hozoor_checkin` and `POST /sabt_hozoor_checkout`, both with a JSON
 * `{username}` body, exactly as `submitUserAttendance()` did.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toPersianDigits } from '@/utils/numbers';

const auth = useAuthStore();

const DEFAULT_AVATAR = '/images/user.png';

const loading = ref(true);
const error = ref('');

const today = ref(null);
const userInfo = ref(null);
const attendance = ref(null);

/* The pass-status card lists the caller's own three latest hourly passes. */
const passRecords = ref([]);
const passesLoading = ref(true);

const PERSIAN_MONTHS = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
];

const todayText = computed(() => {
    if (!today.value) {
        return '—';
    }

    const month = PERSIAN_MONTHS[today.value.month - 1] ?? '';

    return `${toPersianDigits(String(today.value.day))} ${month} ${toPersianDigits(String(today.value.year))}`;
});

const displayName = computed(() => {
    const name = userInfo.value?.name;
    const lastName = userInfo.value?.last_name;

    if (name && lastName) {
        return `${name} ${lastName}`;
    }

    return name || lastName || auth.user?.username || 'کاربر';
});

const attendanceStatusText = computed(() => {
    switch (attendance.value?.status) {
        case 'checked_in':
            return 'ورود ثبت شده؛ خروج خود را ثبت کنید';
        case 'checked_out':
            return 'ورود و خروج امروز ثبت شده است';
        default:
            return 'هنوز ورود امروز ثبت نشده است';
    }
});

const canCheckIn = computed(() => attendance.value?.status === 'not_checked_in');
const canCheckOut = computed(() => attendance.value?.status === 'checked_in');

/*
 * `navigate` still switches the remaining sections (tickets, notifications,
 * profile, final report).  `open` is the legacy modal protocol: the leave,
 * overtime and hourly-pass cards opened `#leaveModal`, `#overtimeModal` and
 * `#hourlyPassModal` in place instead of leaving the dashboard.
 */
const emit = defineEmits(['navigate', 'open', 'overlay']);

async function loadToday() {
    const response = await api.get('/get_today_date', { baseURL: '' });
    today.value = response ?? null;
}

async function loadUserInfo() {
    const response = await api.get('/get_user_info', { baseURL: '' });
    userInfo.value = response?.data ?? null;
}

async function loadAttendance() {
    const response = await api.get('/get_hozoor_today', { baseURL: '' });
    const users = response?.data?.users;
    attendance.value = Array.isArray(users) && users.length > 0
        ? {
            status: users[0].status,
            checkIn: users[0].check_in ?? null,
            checkOut: users[0].check_out ?? null,
            serverNow: response?.data?.server_now ?? null,
            workStart: response?.data?.work_start ?? null,
            workEnd: response?.data?.work_end ?? null,
        }
        : { status: 'not_checked_in', checkIn: null, checkOut: null };
}

async function loadPassRecords() {
    try {
        const response = await api.get('/get_hourly_pass_requests', { baseURL: '' });
        const rows = Array.isArray(response) ? response : [];
        passRecords.value = rows.filter((row) => String(row.username || '').trim() === auth.username);
    } catch {
        passRecords.value = [];
    } finally {
        passesLoading.value = false;
    }
}

async function submitAttendance(action) {
    if (attendance.value?.loading) {
        return;
    }

    const endpoint = action === 'checkin' ? '/sabt_hozoor_checkin' : '/sabt_hozoor_checkout';
    attendance.value = { ...attendance.value, loading: true };

    try {
        const response = await api.post(endpoint, { username: auth.username }, { baseURL: '' });
        const data = response?.data ?? {};
        attendance.value = {
            status: data.status ?? attendance.value.status,
            checkIn: data.check_in ?? null,
            checkOut: data.check_out ?? null,
            serverNow: data.server_now ?? null,
            workStart: attendance.value.workStart,
            workEnd: attendance.value.workEnd,
        };
    } catch (failure) {
        error.value = failure?.message || 'ثبت حضور انجام نشد.';
        if (action === 'checkin') {
            await loadAttendance();
        }
    } finally {
        attendance.value = { ...attendance.value, loading: false };
    }
}

onMounted(async () => {
    loadPassRecords();
    try {
        await Promise.all([loadToday(), loadUserInfo(), loadAttendance()]);
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اطلاعات داشبورد.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="dashboard-content">
        <!-- کارت‌های بالای داشبورد -->
        <section class="top-cards-row">
            <section class="greet-card">
                <div class="greet-text">
                    <h1>سلام {{ displayName }} <span class="wave">👋</span></h1>
                    <p>روز خوبی داشته باشی!</p>
                </div>
            </section>
            <section class="panel-card notif-card top-notif-card">
                <h3 class="panel-title">اعلان‌های مدیریت</h3>
                <div class="notif-item" data-action="open-notification-center" role="button" tabindex="0" @click="emit('overlay', 'notifications')">
                    <span class="notif-icon">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div class="notif-text">
                        <strong>صندوق اعلان‌ها</strong>
                        <span>اطلاعیه‌ها و پیام‌های رسمی مدیریت را مشاهده کنید.</span>
                    </div>
                </div>
                <div class="see-all-link" data-action="open-notification-center" role="button" tabindex="0" @click="emit('overlay', 'notifications')">مشاهده همه اعلان‌ها</div>
            </section>

            <section class="attendance-action-card panel-card" id="attendanceActionCard" :data-username="auth.username">
                <div class="attendance-action-header">
                    <div>
                        <span class="panel-kicker">حضور امروز</span>
                        <h3 class="panel-title">ثبت ورود و خروج</h3>
                    </div>
                    <span class="attendance-action-status" id="attendanceActionStatus">{{ attendanceStatusText }}</span>
                </div>
                <div class="attendance-action-times">
                    <div>
                        <span>زمان ورود</span>
                        <strong id="attendanceCheckInText">{{ attendance?.checkIn ? toPersianDigits(attendance.checkIn) : '--:--' }}</strong>
                    </div>
                    <div>
                        <span>زمان خروج</span>
                        <strong id="attendanceCheckOutText">{{ attendance?.checkOut ? toPersianDigits(attendance.checkOut) : '--:--' }}</strong>
                    </div>
                </div>
                <div class="attendance-action-buttons">
                    <button
                        type="button"
                        class="attendance-action-btn attendance-action-btn--in"
                        data-action="attendance-checkin"
                        :disabled="!canCheckIn || attendance?.loading"
                        @click="submitAttendance('checkin')"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        ثبت ورود
                    </button>
                    <button
                        type="button"
                        class="attendance-action-btn attendance-action-btn--out"
                        data-action="attendance-checkout"
                        :disabled="!canCheckOut || attendance?.loading"
                        @click="submitAttendance('checkout')"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        ثبت خروج
                    </button>
                </div>
            </section>
        </section>

        <!-- کارت‌های آماری -->
        <section class="stats-row">
            <div class="stat-card pass-status-card">
                <span class="stat-icon-circle tint-green">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 2v20M2 12h20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="stat-label">وضعیت پاس‌های ساعتی</span>
                <div class="pass-list">
                    <div v-if="passesLoading" class="pass-item no-pass">در حال دریافت…</div>
                    <template v-else-if="passRecords.length === 0">
                        <div class="pass-item no-pass">پاسی ثبت نشده است</div>
                    </template>
                    <template v-else>
                        <div v-for="record in passRecords.slice(0, 3)" :key="record.id" class="pass-item">
                            <strong class="pass-title">{{ record.pass_title || record.title || 'پاس ساعتی' }}</strong>
                            <span class="pass-time">{{ record.pass_duration || record.duration || '—' }}</span>
                        </div>
                        <div v-if="passRecords.length > 3" class="pass-more">+{{ passRecords.length - 3 }} بیشتر</div>
                    </template>
                </div>
            </div>

            <div class="panel-card leave-balance-card">
                <h3 class="panel-title">موجودی مرخصی</h3>
                <div class="leave-row">
                    <span class="leave-icon-circle tint-green">
                        <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <div class="leave-row-text">
                        <strong>مرخصی استحقاقی</strong>
                        <span>— روز باقی‌مانده</span>
                    </div>
                </div>
                <div class="leave-row">
                    <span class="leave-icon-circle tint-blue">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </span>
                    <div class="leave-row-text">
                        <strong>مرخصی بدون حقوق</strong>
                        <span>— روز باقی‌مانده</span>
                    </div>
                </div>
                <button type="button" class="new-leave-btn" data-action="open-leave" @click="emit('open', 'leave')">
                    <svg viewBox="0 0 24 24" fill="none"><path d="m15 18-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    درخواست مرخصی جدید
                </button>
            </div>

            <div class="stat-card">
                <span class="stat-icon-circle tint-purple" data-action="open-hourly-pass" title="ثبت پاس ساعتی" style="cursor:pointer" @click="emit('open', 'pass')">
                    <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 8v4l2.5 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="stat-label">تاخیر امروز</span>
                <span class="stat-value">۰</span>
                <span class="stat-sub">دقیقه</span>
            </div>

            <div class="stat-card">
                <span class="stat-icon-circle tint-orange" data-action="open-overtime" title="ثبت اضافه‌کار" style="cursor:pointer" @click="emit('open', 'overtime')">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                <span class="stat-label">اضافه کاری این ماه</span>
                <span class="stat-value">۰</span>
                <span class="stat-sub">ساعت</span>
            </div>
        </section>

        <!-- ردیف تقویم و وضعیت تیکت‌ها -->
        <section class="calendar-row">
            <div class="stat-card timeline-ring-card">
                <div class="timeline-ring-top">
                    <span class="stat-icon-circle tint-purple">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 8v4l2.5 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <span class="stat-label">رویداد امروز</span>
                </div>
                <div class="timeline-ring-body">
                    <div
                        class="timeline-ring-wrap"
                        :data-entry-time="attendance?.checkIn || ''"
                        :data-check-out="attendance?.checkOut || ''"
                        :data-work-start="attendance?.workStart || ''"
                        :data-work-end="attendance?.workEnd || ''"
                        :data-server-now="attendance?.serverNow || ''"
                    >
                        <svg class="progress-ring" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="52" class="ring-bg" />
                            <circle cx="60" cy="60" r="52" class="ring-fg ring-fg-green" id="presenceRingGreen" data-percent="0" />
                            <circle cx="60" cy="60" r="52" class="ring-fg ring-fg-blue" id="presenceRingBlue" data-percent="0" />
                        </svg>
                        <div class="progress-ring-text">
                            <strong id="ringWorkHoursText">۰:۰۰</strong>
                            <span>کارکرد</span>
                        </div>
                    </div>
                    <div class="timeline-ring-details">
                        <div class="timeline-ring-item">
                            <span>ورود</span>
                            <strong id="ringCheckInText">{{ attendance?.checkIn ? toPersianDigits(attendance.checkIn) : '--:--' }}</strong>
                        </div>
                        <div class="timeline-ring-item">
                            <span>خروج</span>
                            <strong id="ringCheckOutText">{{ attendance?.checkOut ? toPersianDigits(attendance.checkOut) : '--:--' }}</strong>
                        </div>
                        <div class="timeline-ring-item">
                            <span>اضافه‌کاری</span>
                            <strong id="ringOvertimeText">۰ ساعت</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel-card calendar-card">
                <div class="panel-title-row">
                    <h3 class="panel-title">تقویم</h3>
                </div>
                <div class="calendar-card-body">
                    <div class="calendar-container">
                        <div class="calendar-header">
                            <span class="prev-month">&#10094;</span>
                            <span class="month-year">{{ todayText }}</span>
                            <span class="next-month">&#10095;</span>
                        </div>
                        <div class="calendar-grid"></div>
                    </div>
                </div>
            </div>

            <section class="panel-card user-support-launcher" aria-labelledby="supportLauncherTitle">
                <div class="support-launcher-icon" aria-hidden="true">?</div>
                <div class="support-launcher-copy">
                    <span class="ticket-panel-kicker">پشتیبانی هستما</span>
                    <h3 id="supportLauncherTitle">مرکز درخواست‌های من</h3>
                    <p>وضعیت درخواست‌ها و پاسخ‌های پشتیبانی را یکجا ببینید.</p>
                </div>
                <button type="button" class="support-launcher-action" @click="emit('overlay', 'support')">
                    مشاهده جزئیات پشتیبانی <span aria-hidden="true">←</span>
                </button>
            </section>
        </section>

        <!-- عناصر مخفی برای سازگاری با اسکریپت اصلی -->
        <div id="userInfoBox" style="display:none">
            <p>نام : <span id="userName"></span></p>
            <p>نام خانوادگی : <span id="userLastName"></span></p>
            <p>بخش فعالیت : <span id="userDepartment"></span></p>
            <p>ساعت‌های کاری : <span id="userWorkHours"></span></p>
            <p>جانشین : <span id="userSubstitute"></span></p>
            <img class="profileLogo" :src="DEFAULT_AVATAR" alt="پروفایل کاربر" width="100">
        </div>
    </div>
</template>
