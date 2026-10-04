<script setup>
/**
 * The body of the legacy attendance pop-up
 * (`#popupHozoor > .popupHozoor-content`).
 *
 * A direct port of `openAttendanceReportPopup()`: ask `/get_today_date` for the
 * Jalali "today", turn it into the first-of-month → today range, then read
 * `/get_hozoor/{username}` for the signed-in user and render five cells per row.
 * `formatAttendanceTime()` is ported value for value, including the `0000`
 * placeholder and the 2/3/4-digit paddings.
 *
 * Both endpoints are administrator scoped on the Laravel side exactly as they
 * are in `main.py` (`get_hozoor` sits behind `_require_admin`), so a non-admin
 * user gets the same empty table the running application gives them: the
 * failure is swallowed, as the legacy `.catch()` did.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const emit = defineEmits(['close']);

const auth = useAuthStore();

const rows = ref([]);
const loading = ref(true);

/** `formatAttendanceTime()` from the legacy script. */
function formatAttendanceTime(value) {
    if (value === null || value === undefined) {
        return '--:--';
    }

    const normalized = String(value).trim();
    if (!normalized || normalized === '0000') {
        return '--:--';
    }

    const digits = toLatinDigits(normalized).replace(/\D/g, '');
    if (!digits) {
        return '--:--';
    }

    if (digits.length >= 4) {
        return toPersianDigits(`${digits.slice(0, 2)}:${digits.slice(2, 4)}`);
    }

    if (digits.length === 3) {
        return toPersianDigits(`${digits.slice(0, 1)}:${digits.slice(1)}`);
    }

    if (digits.length === 2) {
        return toPersianDigits(`${digits}:00`);
    }

    return toPersianDigits(normalized);
}

function pad(value) {
    return String(value).padStart(2, '0');
}

onMounted(async () => {
    try {
        const today = await api.get('/get_today_date', { baseURL: '' });
        const startDate = `${today.year}/${pad(today.month)}/01`;
        const endDate = `${today.year}/${pad(today.month)}/${pad(today.day)}`;
        const query = new URLSearchParams({ start_date: startDate, end_date: endDate });

        const data = await api.get(
            `/get_hozoor/${encodeURIComponent(auth.username)}?${query.toString()}`,
            { baseURL: '' },
        );

        const list = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : []);
        rows.value = list;
    } catch {
        /* the legacy handler logged the failure and left the table empty */
        rows.value = [];
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="popupHozoor-content">
        <a id="close-popupHozoor" href="#" @click.prevent="emit('close')">بستن</a>
        <h2>جدول ساعت زن</h2>
        <div class="table-container">
            <table id="HozoorTableReport">
                <thead>
                    <tr>
                        <th class="vazeiyat-hozoorTime">وضعیت</th>
                        <th class="zmnkhrj-hozoorTime">زمان خروج</th>
                        <th class="zmnvrd-hozoorTime">زمان ورود</th>
                        <th class="trkhsbt-hozoorTime">تاریخ ثبت</th>
                        <th class="radif-hozoorTime">ردیف</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td class="vazeiyat-hozoorTime" colspan="5">در حال دریافت…</td>
                    </tr>
                    <tr v-for="(entry, index) in rows" :key="index">
                        <td class="vazeiyat-hozoorTime">{{ entry.Status || '' }}</td>
                        <td class="zmnkhrj-hozoorTime">{{ formatAttendanceTime(entry.ExitTime) }}</td>
                        <td class="zmnvrd-hozoorTime">{{ formatAttendanceTime(entry.EntryTime) }}</td>
                        <td class="trkhsbt-hozoorTime">{{ toPersianDigits(String(entry.Date || '').replace(/-/g, '/')) }}</td>
                        <td class="radif-hozoorTime">{{ toPersianDigits(String(index + 1)) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
