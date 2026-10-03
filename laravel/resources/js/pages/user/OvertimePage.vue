<script setup>
/**
 * Overtime requests — the Vue equivalent of the legacy overtime modal
 * (`#overtimeModal`) and the overtime report table (`#OverTimeTable`).
 *
 * The form posts the four legacy fields (`overtimeDate`, `fromTime`, `toTime`,
 * `description`) as form-urlencoded data to `POST /submit_overtime`, exactly as
 * the legacy `URLSearchParams` body did.
 *
 * The list is `GET /get_overtime_requests`.  That endpoint is administrator
 * scoped (it returns every request as a bare array), so the page filters the
 * rows to the signed-in user's own — the "user panel's data" view.  A refusal
 * (a non-admin caller) is surfaced as a Persian message rather than a silent
 * empty table.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const auth = useAuthStore();

const loading = ref(true);
const submitting = ref(false);
const error = ref('');
const notice = ref('');

const records = ref([]);
const listDenied = ref(false);

const overtimeDate = ref('');
const fromTime = ref('');
const toTime = ref('');
const description = ref('');

const canSubmit = computed(() => (
    overtimeDate.value.trim() !== ''
    && fromTime.value.trim() !== ''
    && toTime.value.trim() !== ''
    && description.value.trim() !== ''
));

function showNotice(message) {
    notice.value = message;

    window.setTimeout(() => {
        notice.value = '';
    }, 4000);
}

async function loadRecords() {
    listDenied.value = false;

    const response = await api.get('/get_overtime_requests', { baseURL: '' });
    const rows = Array.isArray(response) ? response : [];

    records.value = rows.filter(
        (row) => String(row.username || '').trim() === auth.username,
    );
}

async function submitOvertime() {
    if (!canSubmit.value || submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = '';

    const body = new URLSearchParams({
        overtimeDate: toLatinDigits(overtimeDate.value),
        fromTime: toLatinDigits(fromTime.value),
        toTime: toLatinDigits(toTime.value),
        description: description.value,
    });

    try {
        const response = await api.post('/submit_overtime', body, { baseURL: '' });

        if (response?.success === false) {
            error.value = response?.message || 'خطا در ثبت اضافه‌کار.';
            return;
        }

        showNotice(response?.message || 'اضافه‌کار با موفقیت ثبت شد.');
        overtimeDate.value = '';
        fromTime.value = '';
        toTime.value = '';
        description.value = '';
        await loadRecords();
    } catch (failure) {
        if (failure?.status === 403) {
            error.value = 'شما مجاز به مشاهده همه درخواست‌های اضافه‌کاری نیستید.';
        } else {
            error.value = failure?.message || 'مشکلی در ارسال درخواست پیش آمده است.';
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    try {
        await loadRecords();
    } catch (failure) {
        if (failure?.status === 403) {
            listDenied.value = true;
        } else {
            error.value = failure?.message || 'خطا در دریافت اطلاعات اضافه‌کاری.';
        }
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="overtime-page" aria-label="اضافه‌کاری">
        <div class="modal-content">
            <div class="form-section-header">
                <h2 id="overtimeModalTitle">ثبت اضافه کار</h2>
            </div>
            <form id="overtimeForm" class="overtime-form" style="padding: 0 var(--req-body-padding) 8px;" @submit.prevent="submitOvertime">
                <label for="overtimeDate">تاریخ اضافه‌کار</label>
                <div class="date-input-shell">
                    <input
                        v-model="overtimeDate"
                        type="text"
                        id="overtimeDate"
                        name="overtimeDate"
                        placeholder="۱۴۰۳/۰۱/۰۱"
                        required
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        inputmode="numeric"
                        aria-required="true"
                    >
                </div>

                <label for="fromTime">از ساعت</label>
                <input
                    v-model="fromTime"
                    type="text"
                    id="fromTime"
                    name="fromTime"
                    placeholder="۰۸:۰۰"
                    required
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    aria-required="true"
                >

                <label for="toTime">تا ساعت</label>
                <input
                    v-model="toTime"
                    type="text"
                    id="toTime"
                    name="toTime"
                    placeholder="۱۶:۰۰"
                    required
                    autocomplete="off"
                    aria-required="true"
                >

                <label for="description">توضیحات</label>
                <input
                    v-model="description"
                    type="text"
                    id="description"
                    name="description"
                    placeholder="توضیحات"
                    required
                    aria-required="true"
                >

                <div id="overtimeMessage" style="display: none;"></div>

                <p v-if="error" class="req-error" role="alert">{{ error }}</p>
                <p v-else-if="notice" class="req-success" role="status">{{ notice }}</p>

                <div class="modal-buttons">
                    <button type="submit" class="btn-confirm" :disabled="!canSubmit || submitting">
                        {{ submitting ? 'در حال ثبت…' : 'تایید' }}
                    </button>
                    <button type="button" class="btn-cancel" @click="overtimeDate = ''; fromTime = ''; toTime = ''; description = ''">انصراف</button>
                </div>
            </form>
        </div>

        <!-- پاپ اپ جدول اضافه کاری های کاربر -->
        <div class="report-hour-box">
            <h2>مشروح گزارش</h2>
            <div id="overtimeBox">
                <p><span id="dailyOvertime">۰ جمع ساعت اضافه کاری ماهانه</span></p>
            </div>
            <div class="table-scroll">
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
                        <tr v-for="(record, index) in records" :key="record.id || index">
                            <td class="vazeiyat-ezafetime">{{ record.status || 'انتظار تایید' }}</td>
                            <td class="tozihat-ezafetime">{{ record.description || '—' }}</td>
                            <td class="ezafemodat-ezafetime">{{ toPersianDigits(record.daily_overtime || '۰۰:۰۰') }}</td>
                            <td class="darkhst-ezafetime">{{ toPersianDigits(record.overtime_date || '—') }}</td>
                            <td class="radif-ezafetime">{{ toPersianDigits(String(index + 1)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
