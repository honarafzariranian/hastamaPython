<script setup>
/**
 * The body of the legacy leave modal (`#leaveModal > .modal > .modal-content`).
 *
 * Moved out of `pages/user/LeavePage.vue` unchanged: same field set and order
 * (`startDate`, `endDate`, the auto-calculated `days`, the `substitute`
 * picker), the same Jalali day count, and the same multipart `POST
 * /submit_leave` with the four legacy field names.  The panel is now the
 * *content* of the Python modal shell the layout renders, so the page wrapper
 * (`section.leave-page`) is gone.
 */
import { computed, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

/*
 * Bound rather than written as a literal `src`: the legacy URL is the
 * `/static/images/close.png?v=20260928` the template used, and a literal
 * absolute path would be resolved by the bundler as a module.
 */
const CLOSE_ICON_URL = '/images/close.png?v=20260928';

const emit = defineEmits(['close', 'submitted']);

const submitting = ref(false);
const error = ref('');
const notice = ref('');

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

/** `persianToJulianDayNumber()` from the legacy script. */
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

    return { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
}

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

function resetForm() {
    startDate.value = '';
    endDate.value = '';
    days.value = '۰';
    substitute.value = '';
    substituteOpen.value = false;
}

/** `closeLeaveModal()` leaves the typed values in place; only the shell closes. */
function cancel() {
    error.value = '';
    emit('close');
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
        resetForm();
        emit('submitted');
    } catch (failure) {
        error.value = failure?.message || 'مشکلی در ارسال درخواست پیش آمده است.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="modal-content">
        <button type="button" class="close" @click="cancel" aria-label="بستن">
            <img :src="CLOSE_ICON_URL" alt="">
        </button>
        <div class="form-section-header">
            <h2 id="leaveModalTitle">ثبت مرخصی</h2>
        </div>
        <form id="leaveForm" style="padding: 0 var(--req-body-padding) 8px;" @submit.prevent="submitLeave">
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
            <!-- The legacy list is always in the DOM and hidden with an inline
                 `display: none`; it is revealed by `toggleSubstituteDropdown()`. -->
            <div id="substituteDropdown" class="dropdown-content" role="listbox" :style="{ display: substituteOpen ? 'block' : 'none' }">
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
                <button type="button" class="btn-cancel" @click="cancel">انصراف</button>
            </div>
        </form>
    </div>
</template>
