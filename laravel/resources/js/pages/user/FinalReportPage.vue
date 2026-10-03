<script setup>
/**
 * The final report — the Vue equivalent of the legacy final-report screen's
 * name block plus the user panel's own attendance view.
 *
 * The name block comes from `GET /get_user_info_final_report_page/{username}`,
 * which is administrator scoped.  A non-admin caller is refused, so the page
 * falls back to `GET /get_user_info` — the profile data the user panel can
 * always read — and says so.  The attendance half is `GET /get_hozoor_today`,
 * the signed-in user's presence for today, which is the report data the user
 * panel owns.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toPersianDigits } from '@/utils/numbers';

const auth = useAuthStore();

const loading = ref(true);
const error = ref('');

const profile = ref(null);
const usedFallback = ref(false);
const attendance = ref(null);

const displayName = computed(() => {
    const name = profile.value?.name;
    const lastName = profile.value?.last_name;

    if (name && lastName) {
        return `${name} ${lastName}`;
    }

    return name || lastName || auth.user?.username || '—';
});

const attendanceStatusText = computed(() => {
    switch (attendance.value?.status) {
        case 'checked_in':
            return 'ورود ثبت شده';
        case 'checked_out':
            return 'ورود و خروج ثبت شده';
        default:
            return 'غایب';
    }
});

async function loadProfile() {
    try {
        const response = await api.get(
            `/get_user_info_final_report_page/${encodeURIComponent(auth.username)}`,
            { baseURL: '' },
        );
        profile.value = response ?? null;
        usedFallback.value = false;
    } catch (failure) {
        if (failure?.status === 403 || failure?.status === 500) {
            const fallback = await api.get('/get_user_info', { baseURL: '' });
            profile.value = fallback?.data ?? null;
            usedFallback.value = true;
            return;
        }

        throw failure;
    }
}

async function loadAttendance() {
    const response = await api.get('/get_hozoor_today', { baseURL: '' });
    const users = response?.data?.users;

    attendance.value = Array.isArray(users) && users.length > 0
        ? {
            status: users[0].status,
            checkIn: users[0].check_in ?? null,
            checkOut: users[0].check_out ?? null,
            workStart: response?.data?.work_start ?? null,
            workEnd: response?.data?.work_end ?? null,
        }
        : { status: 'not_checked_in', checkIn: null, checkOut: null };
}

onMounted(async () => {
    try {
        await Promise.all([loadProfile(), loadAttendance()]);
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت گزارش.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="final-report-page" aria-label="گزارش نهایی">
        <div class="report-content">
            <div class="report-name-block">
                <h2>گزارش نهایی</h2>
                <div class="report-user-info">
                    <div class="report-field">
                        <span class="report-label">نام و نام خانوادگی</span>
                        <span class="report-value">{{ displayName }}</span>
                    </div>
                    <div class="report-field">
                        <span class="report-label">نام کاربری</span>
                        <span class="report-value">{{ auth.user?.username || '—' }}</span>
                    </div>
                    <div class="report-field">
                        <span class="report-label">بخش</span>
                        <span class="report-value">{{ profile?.department || '—' }}</span>
                    </div>
                </div>
                <p v-if="usedFallback" class="report-fallback-note">
                    اطلاعات کاربر از پروفایل خوانده شد چون گزارش کامل فقط برای مدیران است.
                </p>
            </div>

            <div class="report-attendance-block">
                <h3>حضور امروز</h3>
                <div class="report-attendance-grid">
                    <div class="report-field">
                        <span class="report-label">وضعیت</span>
                        <span class="report-value">{{ attendanceStatusText }}</span>
                    </div>
                    <div class="report-field">
                        <span class="report-label">زمان ورود</span>
                        <span class="report-value">{{ attendance?.checkIn ? toPersianDigits(attendance.checkIn) : '--:--' }}</span>
                    </div>
                    <div class="report-field">
                        <span class="report-label">زمان خروج</span>
                        <span class="report-value">{{ attendance?.checkOut ? toPersianDigits(attendance.checkOut) : '--:--' }}</span>
                    </div>
                    <div class="report-field">
                        <span class="report-label">ساعت کاری</span>
                        <span class="report-value">
                            <template v-if="attendance?.workStart && attendance?.workEnd">
                                {{ toPersianDigits(attendance.workStart) }} تا {{ toPersianDigits(attendance.workEnd) }}
                            </template>
                            <template v-else>—</template>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
