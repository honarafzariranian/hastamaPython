<script setup>
/**
 * The body of the legacy overtime report popup
 * (`#popupOverlayezafe > #reportezafekariBox`).
 *
 * `GET /get_overtime_requests` is administrator scoped, so — exactly as the
 * section page did — the rows are filtered to the signed-in user.  The monthly
 * total line is the same placeholder the page showed: the Python box printed a
 * server-side sum from `ezafe_table` and no Laravel route publishes it.
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
    const response = await api.get('/get_overtime_requests', { baseURL: '' });
    const rows = Array.isArray(response) ? response : [];

    records.value = rows.filter((row) => String(row.username || '').trim() === auth.username);
}

onMounted(async () => {
    try {
        await loadRecords();
    } catch (failure) {
        error.value = failure?.status === 403
            ? 'شما مجاز به مشاهده همه درخواست‌های اضافه‌کاری نیستید.'
            : (failure?.message || 'خطا در دریافت اطلاعات اضافه‌کاری.');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div id="reportezafekariBox" class="report-hour-box">
        <h2>مشروح گزارش</h2>
        <div id="overtimeBox">
            <p><span id="dailyOvertime">۰ جمع ساعت اضافه کاری ماهانه</span></p>
        </div>
        <table id="OverTimeTable">
            <thead>
                <tr>
                    <th class="vazeiyat-ezafetime">وضعیت</th>
                    <th class="tozihat-ezafetime">توضیحات</th>
                    <th class="ezafemodat-ezafetime">مدت زمان اضافه کاری</th>
                    <th class="darkhst-ezafetime">تاریخ درخواست</th>
                    <th class="radif-ezafetime">ردیف</th>
                </tr>
            </thead>
            <tbody id="overtimeTableBody">
                <tr v-if="loading">
                    <td class="vazeiyat-ezafetime" colspan="5">در حال دریافت…</td>
                </tr>
                <tr v-else-if="error">
                    <td class="vazeiyat-ezafetime" colspan="5" role="alert">{{ error }}</td>
                </tr>
                <tr v-for="(record, index) in records" :key="record.id || index">
                    <td class="vazeiyat-ezafetime">{{ record.status || 'انتظار تایید' }}</td>
                    <td class="tozihat-ezafetime">{{ record.description || '—' }}</td>
                    <td class="ezafemodat-ezafetime">{{ toPersianDigits(record.daily_overtime || '۰۰:۰۰') }}</td>
                    <td class="darkhst-ezafetime">{{ toPersianDigits(record.overtime_date || '—') }}</td>
                    <td class="radif-ezafetime">{{ toPersianDigits(String(index + 1)) }}</td>
                </tr>
            </tbody>
        </table>
        <a href="#" id="closePopupezafekarijadvalbox" @click.prevent="emit('close')">بستن</a>
    </div>
</template>
