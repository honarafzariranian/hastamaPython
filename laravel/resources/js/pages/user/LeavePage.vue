<script setup>
/**
 * Leave requests — the Vue equivalent of the legacy leave modal
 * (`#leaveModal`) and the leave report table (`#leaveTable`).
 *
 * The form reproduces the legacy field set and order: `startDate`, `endDate`,
 * an auto-calculated `days`, and a `substitute` picker.  The day count is the
 * Julian-day-number difference between the two Jalali dates, exactly as
 * `calculateLeaveDays()` computed it.  Submission is multipart form data with
 * the four legacy field names to `POST /submit_leave`.
 *
 * The list is `GET /get_leave_info` — the signed-in user's own requests, as a
 * bare `{success, data}` envelope whose rows carry `start_date`, `end_date`,
 * `days` and `status`.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const loading = ref(true);
const submitting = ref(false);
const error = ref('');
const notice = ref('');

const leaves = ref([]);

const startDate = ref('');
const endDate = ref('');
const days = ref('۰');
const substitute = ref('');
const substituteOpen = ref(false);

const SUBSTITUTE_OPTIONS = [
    'بدون جانشین',
    'فاطمه سالاری فر',
    'مریم براتی',
    'زهره محمودی',
    'محدثه گنجمه',
    'عالیه سقایی',
    'فرشته ایزدی',
    'مرتضی کمالی',
    'فاطمه پاک نفس',
    'سیدمرتضی موسوی پور',
    'محمدمهدی صمدیان',
    'صبا حلاجی',
];

const canSubmit = computed(() => startDate.value.trim() !== '' && endDate.value.trim() !== '');

function showNotice(message) {
    notice.value = message;

    window.setTimeout(() => {
        notice.value = '';
    }, 4000);
}

/**
 * `persianToJulianDayNumber()` from the legacy script — the JDN of a Jalali
 * date, used to count the inclusive day span between two dates.
 */
function persianToJulianDayNumber(year, month, day) {
    const epbase = year - (year >= 0 ? 474 : 473);
    const epyear = 474 + (epbase % 2820);
    const monthOffset = month <= 6 ? month - 1 : month + 5;

    return day
        + Math.floor((epyear * 682 - 110) / 2816)
        + (epyear - 1) * 365
        + Math.floor(epbase / 2820) * 1029983
        + monthOffset * 31
        + 1948320;
}

function parsePersianDate(value) {
    const normalized = toLatinDigits(String(value).trim());
    const match = normalized.match(/(\d{2,4})[/-](\d{1,2})[/-](\d{1,2})/);

    if (!match) {
        return null;
    }

    return {
        year: Number(match[1]),
        month: Number(match[2]),
        day: Number(match[3]),
    };
}

/**
 * Recalculate the day count whenever either date changes, mirroring the
 * legacy `change` / `blur` / `input` handlers on both inputs.
 */
function recalculateDays() {
    const start = parsePersianDate(startDate.value);
    const end = parsePersianDate(endDate.value);

    if (!start || !end) {
        days.value = '۰';
        return;
    }

    const startJdn = persianToJulianDayNumber(start.year, start.month, start.day);
    const endJdn = persianToJulianDayNumber(end.year, end.month, end.day);

    days.value = toPersianDigits(String(Math.max(1, endJdn - startJdn + 1)));
}

function toggleSubstitute() {
    substituteOpen.value = !substituteOpen.value;
}

function selectSubstitute(option) {
    substitute.value = option;
    substituteOpen.value = false;
}

async function loadLeaves() {
    const response = await api.get('/get_leave_info', { baseURL: '' });
    leaves.value = Array.isArray(response?.data) ? response.data : [];
}

async function submitLeave() {
    if (!canSubmit.value || submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = '';

    const formData = new FormData();
    formData.append('startDate', startDate.value);
    formData.append('endDate', endDate.value);
    formData.append('days', days.value);
    formData.append('substitute', substitute.value);

    try {
        const response = await api.post('/submit_leave', formData, { baseURL: '' });

        if (response?.success === false) {
            error.value = response?.message || 'خطا در ثبت درخواست مرخصی.';
            return;
        }

        showNotice(response?.message || 'درخواست مرخصی شما با موفقیت ثبت شد.');
        startDate.value = '';
        endDate.value = '';
        days.value = '۰';
        substitute.value = '';
        await loadLeaves();
    } catch (failure) {
        error.value = failure?.message || 'مشکلی در ارسال درخواست پیش آمده است.';
    } finally {
        submitting.value = false;
    }
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
    <section class="leave-page" aria-label="مرخصی">
        <div class="modal-content">
            <div class="form-section-header">
                <h2 id="leaveModalTitle">ثبت مرخصی</h2>
            </div>
            <form id="leaveForm" class="leave-form" style="padding: 0 var(--req-body-padding) 8px;" @submit.prevent="submitLeave">
                <label for="startDate">از تاریخ</label>
                <input
                    v-model="startDate"
                    type="text"
                    id="startDate"
                    name="startDate"
                    placeholder="۱۴۰۳/۰۱/۰۱"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    inputmode="numeric"
                    aria-required="true"
                    @input="recalculateDays"
                    @change="recalculateDays"
                >

                <label for="endDate">تا تاریخ</label>
                <input
                    v-model="endDate"
                    type="text"
                    id="endDate"
                    name="endDate"
                    placeholder="۱۴۰۳/۰۱/۰۱"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    inputmode="numeric"
                    aria-required="true"
                    @input="recalculateDays"
                    @change="recalculateDays"
                >

                <div class="input-container">
                    <input type="text" id="days" name="days" :value="days" readonly required aria-label="تعداد روز">
                    <span class="days-hint">تعداد روز</span>
                </div>

                <label for="substitute">جانشین</label>
                <input
                    v-model="substitute"
                    type="text"
                    id="substitute"
                    name="substitute"
                    placeholder="انتخاب جانشین"
                    readonly
                    required
                    aria-required="true"
                    aria-haspopup="listbox"
                    @click="toggleSubstitute"
                >
                <div v-if="substituteOpen" id="substituteDropdown" class="dropdown-content" role="listbox">
                    <div
                        v-for="option in SUBSTITUTE_OPTIONS"
                        :key="option"
                        class="dropdown-option"
                        :data-value="option"
                        role="option"
                        @click="selectSubstitute(option)"
                    >
                        {{ option }}
                    </div>
                </div>

                <p v-if="error" class="req-error" role="alert">{{ error }}</p>
                <p v-else-if="notice" class="req-success" role="status">{{ notice }}</p>

                <div class="modal-buttons">
                    <button type="submit" class="btn-confirm" :disabled="!canSubmit || submitting">
                        {{ submitting ? 'در حال ثبت…' : 'تایید' }}
                    </button>
                    <button type="button" class="btn-cancel" @click="startDate = ''; endDate = ''; days = '۰'; substitute = ''">انصراف</button>
                </div>
            </form>
        </div>

        <!-- پاپ اپ جدول مرخصی های کاربر -->
        <div class="report-hour-box">
            <h2>مشروح گزارش</h2>
            <div class="mrkhc">
                <p>تعداد مرخصی‌های تایید شده : <span>۰</span></p>
                <p>تعداد مرخصی‌های باقی‌مانده : <span>۰</span></p>
            </div>
            <div class="table-scroll">
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
                        <tr v-for="(leave, index) in leaves" :key="index">
                            <td class="vazeiyat-morkhc">{{ leave.status || 'انتظار تایید' }}</td>
                            <td class="tedadrooz-morkhc">{{ toPersianDigits(String(leave.days ?? '۰')) }}</td>
                            <td class="taTarikh-morkhc">{{ toPersianDigits(leave.end_date || '—') }}</td>
                            <td class="azTarikh-morkhc">{{ toPersianDigits(leave.start_date || '—') }}</td>
                            <td class="radif-morkhc">{{ toPersianDigits(String(index + 1)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
