<script setup>
/**
 * The body of the legacy overtime modal
 * (`#overtimeModal > .modal > .modal-content`).
 *
 * Same four fields, same form-urlencoded body to `POST /submit_overtime` as the
 * section page had.  The Python handler reloaded `/user_panel` on success so the
 * dashboard numbers refreshed; here the panel emits `submitted` and the layout
 * remounts the dashboard instead of reloading the document.
 */
import { computed, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits } from '@/utils/numbers';

/* Bound so the bundler does not resolve the legacy absolute path as a module. */
const CLOSE_ICON_URL = '/images/close.png?v=20260928';

const emit = defineEmits(['close', 'submitted']);

const submitting = ref(false);
const error = ref('');
const notice = ref('');

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

function resetForm() {
    overtimeDate.value = '';
    fromTime.value = '';
    toTime.value = '';
    description.value = '';
}

function cancel() {
    error.value = '';
    emit('close');
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
            <h2 id="overtimeModalTitle">ثبت اضافه کار</h2>
        </div>
        <form id="overtimeForm" style="padding: 0 var(--req-body-padding) 8px;" @submit.prevent="submitOvertime">
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
                <button type="button" class="btn-cancel" @click="cancel">انصراف</button>
            </div>
        </form>
    </div>
</template>
