<script setup>
/**
 * The body of the legacy leave report popup
 * (`#popupOverlayMorakhsi > #reportMorakhaciKarbariBox`).
 *
 * `GET /get_leave_info` is the legacy read, unchanged.  The two balance lines
 * are the one part of the Python box that has no endpoint behind it: the
 * running application rendered `approved_count` / `remaining_count` straight
 * from the `leave_report` row inside `_user_panel_context`, and no Laravel route
 * exposes that row, so the placeholders the page already showed are kept rather
 * than an invented number being displayed.
 */
import { onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';

const emit = defineEmits(['close']);

const loading = ref(true);
const error = ref('');
const leaves = ref([]);

async function loadLeaves() {
    const response = await api.get('/get_leave_info', { baseURL: '' });
    leaves.value = Array.isArray(response?.data) ? response.data : [];
}

onMounted(async () => {
    try {
        await loadLeaves();
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اطلاعات مرخصی.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div id="reportMorakhaciKarbariBox" class="report-hour-box">
        <h2>مشروح گزارش</h2>
        <div class="mrkhc">
            <p id="approvedLeaves">تعداد مرخصی‌های تایید شده : <span id="approvedCount">۰</span></p>
            <p id="remainingLeaves">تعداد مرخصی‌های باقی‌مانده : <span id="remainingCount">۰</span></p>
        </div>
        <table id="leaveTable">
            <thead>
                <tr>
                    <th class="vazeiyat-morkhc">وضعیت درخواست</th>
                    <th class="tedadrooz-morkhc">تعداد روز</th>
                    <th class="taTarikh-morkhc">تا تاریخ</th>
                    <th class="azTarikh-morkhc">از تاریخ</th>
                    <th class="radif-morkhc">ردیف</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td class="vazeiyat-morkhc" colspan="5">در حال دریافت…</td>
                </tr>
                <tr v-else-if="error">
                    <td class="vazeiyat-morkhc" colspan="5" role="alert">{{ error }}</td>
                </tr>
                <tr v-for="(leave, index) in leaves" :key="index">
                    <td class="vazeiyat-morkhc">{{ leave.status || 'انتظار تایید' }}</td>
                    <td class="tedadrooz-morkhc">{{ toPersianDigits(String(leave.days ?? '۰')) }}</td>
                    <td class="taTarikh-morkhc">{{ toPersianDigits(leave.end_date || '—') }}</td>
                    <td class="azTarikh-morkhc">{{ toPersianDigits(leave.start_date || '—') }}</td>
                    <td class="radif-morkhc">{{ toPersianDigits(String(index + 1)) }}</td>
                </tr>
            </tbody>
        </table>
        <a href="#" id="closePopupMorakhciPopuppbox" @click.prevent="emit('close')">بستن</a>
    </div>
</template>
