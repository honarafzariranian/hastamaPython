<script setup>
/**
 * The body of the legacy hourly-pass report popup
 * (`#popupOverlay > #reportHourBox`).
 *
 * `GET /get_hourly_pass_requests` (administrator scoped, bare array) filtered to
 * the signed-in user's own rows — the same read the section page made.  The
 * total line keeps the page's placeholder: the Python box printed a
 * server-side sum of approved durations and no Laravel route publishes it.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toPersianDigits } from '@/utils/numbers';

const emit = defineEmits(['close']);

const auth = useAuthStore();

const loading = ref(true);
const error = ref('');
const records = ref([]);

async function loadRecords() {
    const response = await api.get('/get_hourly_pass_requests', { baseURL: '' });
    const rows = Array.isArray(response) ? response : [];

    records.value = rows.filter((row) => String(row.username || '').trim() === auth.username);
}

onMounted(async () => {
    try {
        await loadRecords();
    } catch (failure) {
        error.value = failure?.status === 403
            ? 'شما مجاز به مشاهده همه درخواست‌های پاس ساعتی نیستید.'
            : (failure?.message || 'خطا در دریافت اطلاعات پاس ساعتی.');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div id="reportHourBox" class="report-hour-box">
        <h2>مشروح گزارش</h2>
        <div id="text-hour-box">
            <p>مدت زمان پاس های ساعتی: ۰</p>
        </div>
        <table class="passsaatiReport-table" id="passsaatiReportTable">
            <thead>
                <tr>
                    <th>وضعیت درخواست</th>
                    <th>مدت زمان پاس</th>
                    <th>عنوان پاس</th>
                    <th>تاریخ درخواست</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td colspan="4">در حال دریافت…</td>
                </tr>
                <tr v-else-if="error">
                    <td colspan="4" role="alert">{{ error }}</td>
                </tr>
                <tr v-for="(record, index) in records" :key="record.id || index">
                    <td>{{ record.status || 'انتظار تایید' }}</td>
                    <td>{{ toPersianDigits(record.pass_duration || '—') }}</td>
                    <td>{{ record.pass_title || '—' }}</td>
                    <td>{{ toPersianDigits(record.request_date || '—') }}</td>
                </tr>
            </tbody>
        </table>
        <a href="#" id="closePopup" @click.prevent="emit('close')">بستن</a>
    </div>
</template>
