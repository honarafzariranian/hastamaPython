<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toPersianDigits, toLatinDigits } from '@/utils/numbers';

const auth = useAuthStore();

const DEFAULT_AVATAR = '/images/user.png';

const loading = ref(true);
const error = ref('');

const today = ref(null);
const userInfo = ref(null);
const attendance = ref(null);

const passRecords = ref([]);
const passesLoading = ref(true);

const leaveBalance = ref({ approved: 0, remaining: 0 });
const monthlyOvertime = ref('۰');
const todayDelay = ref('۰');

const PERSIAN_MONTHS = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
];

const DAYS_IN_MONTH = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 30];

const PERSIAN_MONTH_FIRST_DAYS = {
    1: 6, 2: 2, 3: 5, 4: 1, 5: 4, 6: 0,
    7: 3, 8: 6, 9: 1, 10: 0, 11: 2, 12: 4,
};

const HOLIDAYS = {
    10: [25],
};

const DAYS_OF_WEEK = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

const calendarMonth = ref(0);
const calendarYear = ref(0);
const calendarFirstDay = ref(0);

const todayText = computed(() => {
    if (!calendarYear.value || !calendarMonth.value) {
        return '—';
    }
    const month = PERSIAN_MONTHS[calendarMonth.value - 1] ?? '';
    return `${month} ${toPersianDigits(String(calendarYear.value))}`;
});

const calendarDays = computed(() => {
    if (!calendarMonth.value) {
        return [];
    }

    const cells = [];
    const days = DAYS_IN_MONTH[calendarMonth.value - 1];
    const firstDay = calendarFirstDay.value;

    for (let i = 0; i < firstDay; i++) {
        cells.push({ empty: true });
    }

    for (let i = 1; i <= days; i++) {
        const dayOfWeek = (firstDay + i - 1) % 7;
        const isFriday = dayOfWeek === 6;
        const isHoliday = HOLIDAYS[calendarMonth.value]?.includes(i);
        const isToday = today.value && i === today.value.day
            && calendarMonth.value === today.value.month
            && calendarYear.value === today.value.year;

        cells.push({
            day: i,
            isFriday,
            isHoliday,
            isToday,
        });
    }

    return cells;
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

const emit = defineEmits(['navigate', 'open', 'overlay']);

async function loadToday() {
    const response = await api.get('/get_today_date', { baseURL: '' });
    today.value = response ?? null;
    if (response) {
        calendarYear.value = response.year;
        calendarMonth.value = response.month;
        calendarFirstDay.value = PERSIAN_MONTH_FIRST_DAYS[response.month] ?? 0;
    }
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
            loadedAtMs: Date.now(),
        }
        : { status: 'not_checked_in', checkIn: null, checkOut: null, loadedAtMs: Date.now() };
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

async function loadLeaveBalance() {
    try {
        const response = await api.get('/get_leave_info', { baseURL: '' });
        const rows = Array.isArray(response?.data) ? response.data : [];
        const approved = rows.filter((r) => r.status === 'تایید شده').length;
        const totalDays = rows.reduce((sum, r) => sum + (Number(r.days) || 0), 0);
        leaveBalance.value = {
            approved,
            remaining: Math.max(0, totalDays),
        };
    } catch {
        leaveBalance.value = { approved: 0, remaining: 0 };
    }
}

async function loadMonthlyOvertime() {
    try {
        const response = await api.get('/get_overtime_requests', { baseURL: '' });
        const rows = Array.isArray(response) ? response : [];
        const userRows = rows.filter((r) => String(r.username || '').trim() === auth.username);
        let totalMinutes = 0;
        for (const row of userRows) {
            const raw = String(row.daily_overtime || '00:00');
            const cleaned = toLatinDigits(raw).trim();
            const parts = cleaned.split(':');
            if (parts.length >= 2) {
                totalMinutes += (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
            }
        }
        const hours = Math.floor(totalMinutes / 60);
        const mins = totalMinutes % 60;
        monthlyOvertime.value = toPersianDigits(`${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`);
    } catch {
        monthlyOvertime.value = '۰';
    }
}

async function loadTodayDelay() {
    try {
        const response = await api.get('/get_hozoor_today', { baseURL: '' });
        const data = response?.data;
        const users = data?.users;
        if (!Array.isArray(users) || users.length === 0) {
            todayDelay.value = '۰';
            return;
        }
        const user = users[0];
        const workStart = data?.work_start;
        const checkIn = user.check_in;
        if (!workStart || !checkIn) {
            todayDelay.value = '۰';
            return;
        }
        const startMin = parseClock(workStart);
        const entryMin = parseClock(checkIn);
        if (startMin === null || entryMin === null) {
            todayDelay.value = '۰';
            return;
        }
        const delayMinutes = Math.max(0, entryMin - startMin);
        todayDelay.value = toPersianDigits(String(delayMinutes));
    } catch {
        todayDelay.value = '۰';
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
            loadedAtMs: Date.now(),
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

function prevMonth() {
    if (calendarMonth.value === 1) {
        calendarMonth.value = 12;
        calendarYear.value -= 1;
    } else {
        calendarMonth.value -= 1;
    }
    calendarFirstDay.value = PERSIAN_MONTH_FIRST_DAYS[calendarMonth.value] ?? 0;
}

function nextMonthNav() {
    if (calendarMonth.value === 12) {
        calendarMonth.value = 1;
        calendarYear.value += 1;
    } else {
        calendarMonth.value += 1;
    }
    calendarFirstDay.value = PERSIAN_MONTH_FIRST_DAYS[calendarMonth.value] ?? 0;
}

// ── Progress ring ──
const CIRCUMFERENCE = 327;
const ringGreenRef = ref(null);
const ringBlueRef = ref(null);
const ringWorkHoursRef = ref(null);
const ringOvertimeRef = ref(null);
const ringCheckInRef = ref(null);
const ringCheckOutRef = ref(null);

let ringAnimToken = 0;
let ringGreenLength = 0;
let ringBlueLength = 0;
let ringBlueOffset = 0;
let ringInterval = null;

function parseClock(value) {
    if (!value) return null;
    const cleaned = toLatinDigits(String(value).trim());
    const parts = cleaned.split(':');
    if (parts.length < 2) return null;
    const hours = parseInt(parts[0], 10);
    const minutes = parseInt(parts[1], 10);
    if (Number.isNaN(hours) || Number.isNaN(minutes)) return null;
    return hours * 60 + minutes;
}

function getCurrentMinutes() {
    const serverMinutes = parseClock(attendance.value?.serverNow);
    if (serverMinutes === null) {
        const now = new Date();
        return now.getHours() * 60 + now.getMinutes();
    }
    const loadedAt = attendance.value?.loadedAtMs || Date.now();
    const elapsed = Math.floor((Date.now() - loadedAt) / 60000);
    return serverMinutes + elapsed;
}

function normalizeShiftMinutes(currentMinutes, startMinutes, endMinutes) {
    let effStart = startMinutes;
    let effEnd = endMinutes;

    if (effEnd <= effStart) {
        effEnd += 24 * 60;
    }

    let normCurrent = currentMinutes;
    if (effEnd > 24 * 60 && normCurrent < effStart) {
        normCurrent += 24 * 60;
    }

    return { effStart, effEnd, normCurrent };
}

function getShiftTimelineState(currentMinutes, startMinutes, endMinutes) {
    const { effStart, effEnd, normCurrent } = normalizeShiftMinutes(currentMinutes, startMinutes, endMinutes);
    const duration = Math.max(1, effEnd - effStart);

    let progressPercent = 0;
    let workedMinutes = 0;
    let overtimeMinutes = 0;

    if (normCurrent <= effStart) {
        progressPercent = 0;
    } else if (normCurrent < effEnd) {
        workedMinutes = normCurrent - effStart;
        progressPercent = Math.min(100, Math.max(0, (workedMinutes / duration) * 100));
    } else {
        workedMinutes = duration;
        progressPercent = 100;
        overtimeMinutes = Math.max(0, normCurrent - effEnd);
    }

    return { progressPercent, workedMinutes, overtimeMinutes, duration, effStart, effEnd, normCurrent };
}

function formatMinutes(totalMinutes) {
    const hours = Math.floor(Math.max(0, totalMinutes) / 60);
    const minutes = Math.max(0, totalMinutes) % 60;
    return toPersianDigits(`${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`);
}

function applyProgress(circle, length, offset = 0) {
    if (!circle) return;
    circle.style.strokeDasharray = `${length} ${CIRCUMFERENCE}`;
    circle.style.strokeDashoffset = `${offset}`;
}

function updatePresenceRing() {
    const greenCircle = ringGreenRef.value;
    const blueCircle = ringBlueRef.value;

    if (!greenCircle || !blueCircle) return;

    const entryTime = attendance.value?.checkIn || '';
    const checkOutTime = attendance.value?.checkOut || '';
    const workStart = attendance.value?.workStart || '';
    const workEnd = attendance.value?.workEnd || '';

    if (ringCheckInRef.value) {
        ringCheckInRef.value.textContent = entryTime ? toPersianDigits(String(entryTime).trim()) : '--:--';
    }
    if (ringCheckOutRef.value) {
        ringCheckOutRef.value.textContent = checkOutTime ? toPersianDigits(String(checkOutTime).trim()) : '--:--';
    }

    let startMinutes = parseClock(workStart);
    let endMinutes = parseClock(workEnd);
    const currentMinutes = getCurrentMinutes();

    if (endMinutes === null || startMinutes === null) {
        ringGreenLength = 0;
        ringBlueLength = 0;
        ringBlueOffset = 0;
        applyProgress(greenCircle, 0, 0);
        applyProgress(blueCircle, 0, 0);
        if (ringWorkHoursRef.value) ringWorkHoursRef.value.textContent = formatMinutes(0);
        if (ringOvertimeRef.value) ringOvertimeRef.value.textContent = `${formatMinutes(0)} ساعت`;
        return;
    }

    if (endMinutes < startMinutes) {
        [startMinutes, endMinutes] = [endMinutes, startMinutes];
    }

    const shiftState = getShiftTimelineState(currentMinutes, startMinutes, endMinutes);
    const entryMinutes = parseClock(entryTime);
    const checkOutMinutes = parseClock(checkOutTime);

    const computeElapsedSinceEntry = (entryMin, normCurrent) => {
        if (!Number.isInteger(entryMin) || !Number.isInteger(normCurrent)) return 0;
        let norm = normCurrent;
        if (norm < entryMin) norm += 24 * 60;
        let diff = Math.max(0, norm - entryMin);
        if (diff >= 24 * 60) diff = diff % (24 * 60);
        return diff;
    };

    let workedMinutes = 0;
    let overtimeMinutes = shiftState.overtimeMinutes;

    if (entryMinutes !== null && checkOutMinutes !== null) {
        let normCheckOut = checkOutMinutes;
        if (normCheckOut < entryMinutes) normCheckOut += 24 * 60;
        workedMinutes = Math.max(0, Math.min(normCheckOut, shiftState.effEnd) - entryMinutes);
        overtimeMinutes = Math.max(0, normCheckOut - shiftState.effEnd);
    } else if (entryMinutes !== null) {
        workedMinutes = computeElapsedSinceEntry(entryMinutes, shiftState.normCurrent);
    }

    const greenPercent = Math.min(100, Math.max(0, (workedMinutes / Math.max(shiftState.duration, 1)) * 100));
    const bluePercent = Math.min(100, Math.max(0, (overtimeMinutes / Math.max(shiftState.duration, 1)) * 100));

    const targetGreenLength = (CIRCUMFERENCE * greenPercent) / 100;
    const targetBlueLength = (CIRCUMFERENCE * bluePercent) / 100;
    const targetBlueOffset = -targetGreenLength;

    const duration = 900;
    const startTime = performance.now();
    const startGreen = ringGreenLength;
    const startBlue = ringBlueLength;
    const startBlueOff = ringBlueOffset;
    const animId = ++ringAnimToken;

    const tick = (now) => {
        if (animId !== ringAnimToken) return;
        const elapsed = now - startTime;
        const progress = Math.min(1, elapsed / duration);
        const eased = 1 - Math.pow(1 - progress, 3);

        ringGreenLength = startGreen + (targetGreenLength - startGreen) * eased;
        ringBlueLength = startBlue + (targetBlueLength - startBlue) * eased;
        ringBlueOffset = startBlueOff + (targetBlueOffset - startBlueOff) * eased;

        applyProgress(greenCircle, ringGreenLength, 0);
        applyProgress(blueCircle, ringBlueLength, ringBlueOffset);

        if (progress < 1) {
            requestAnimationFrame(tick);
        }
    };

    requestAnimationFrame(tick);

    if (ringWorkHoursRef.value) {
        ringWorkHoursRef.value.textContent = formatMinutes(workedMinutes);
    }
    if (ringOvertimeRef.value) {
        ringOvertimeRef.value.textContent = `${formatMinutes(overtimeMinutes)} ساعت`;
    }
}

onMounted(async () => {
    loadPassRecords();
    loadLeaveBalance();
    loadMonthlyOvertime();
    try {
        await Promise.all([loadToday(), loadUserInfo(), loadAttendance()]);
        loadTodayDelay();
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اطلاعات داشبورد.';
    } finally {
        loading.value = false;
    }

    await nextTick();
    updatePresenceRing();
    ringInterval = setInterval(updatePresenceRing, 30000);
});

onUnmounted(() => {
    if (ringInterval) {
        clearInterval(ringInterval);
    }
});

watch(attendance, () => {
    nextTick(() => updatePresenceRing());
}, { deep: true });
</script>

<template>
    <div class="dashboard-content">
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
                        <div v-if="passRecords.length > 3" class="pass-more">+{{ toPersianDigits(String(passRecords.length - 3)) }} بیشتر</div>
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
                        <span>{{ toPersianDigits(String(leaveBalance.remaining)) }} روز باقی‌مانده</span>
                    </div>
                </div>
                <div class="leave-row">
                    <span class="leave-icon-circle tint-blue">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </span>
                    <div class="leave-row-text">
                        <strong>مرخصی بدون حقوق</strong>
                        <span>{{ toPersianDigits(String(leaveBalance.approved)) }} روز استفاده شده</span>
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
                <span class="stat-value">{{ todayDelay }}</span>
                <span class="stat-sub">دقیقه</span>
            </div>

            <div class="stat-card">
                <span class="stat-icon-circle tint-orange" data-action="open-overtime" title="ثبت اضافه‌کار" style="cursor:pointer" @click="emit('open', 'overtime')">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                <span class="stat-label">اضافه کاری این ماه</span>
                <span class="stat-value">{{ monthlyOvertime }}</span>
                <span class="stat-sub">ساعت</span>
            </div>
        </section>

        <section class="calendar-row">
            <div class="stat-card timeline-ring-card">
                <div class="timeline-ring-top">
                    <span class="stat-icon-circle tint-purple">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 8v4l2.5 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <span class="stat-label">رویداد امروز</span>
                </div>
                <div class="timeline-ring-body">
                    <div class="timeline-ring-wrap">
                        <svg class="progress-ring" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="52" class="ring-bg" />
                            <circle ref="ringGreenRef" cx="60" cy="60" r="52" class="ring-fg ring-fg-green" />
                            <circle ref="ringBlueRef" cx="60" cy="60" r="52" class="ring-fg ring-fg-blue" />
                        </svg>
                        <div class="progress-ring-text">
                            <strong ref="ringWorkHoursRef">۰۰:۰۰</strong>
                            <span>کارکرد</span>
                        </div>
                    </div>
                    <div class="timeline-ring-details">
                        <div class="timeline-ring-item">
                            <span>ورود</span>
                            <strong ref="ringCheckInRef">{{ attendance?.checkIn ? toPersianDigits(attendance.checkIn) : '--:--' }}</strong>
                        </div>
                        <div class="timeline-ring-item">
                            <span>خروج</span>
                            <strong ref="ringCheckOutRef">{{ attendance?.checkOut ? toPersianDigits(attendance.checkOut) : '--:--' }}</strong>
                        </div>
                        <div class="timeline-ring-item">
                            <span>اضافه‌کاری</span>
                            <strong ref="ringOvertimeRef">۰۰:۰۰ ساعت</strong>
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
                            <span class="prev-month" role="button" tabindex="0" @click="prevMonth">&#10094;</span>
                            <span class="month-year">{{ todayText }}</span>
                            <span class="next-month" role="button" tabindex="0" @click="nextMonthNav">&#10095;</span>
                        </div>
                        <div class="calendar-grid">
                            <div v-for="name in DAYS_OF_WEEK" :key="name" class="day-name">{{ name }}</div>
                            <template v-for="(cell, idx) in calendarDays" :key="idx">
                                <div v-if="cell.empty" class="day empty"></div>
                                <div
                                    v-else
                                    class="day"
                                    :class="{
                                        'red-day': cell.isFriday,
                                        'holiday': cell.isHoliday,
                                        'today': cell.isToday,
                                    }"
                                >{{ toPersianDigits(String(cell.day)) }}</div>
                            </template>
                        </div>
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

<style scoped>
.dashboard-content {
    gap: 8px;
    display: contents;
}
</style>
