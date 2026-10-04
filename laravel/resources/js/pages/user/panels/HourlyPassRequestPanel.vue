<script setup>
/**
 * The body of the legacy hourly-pass modal
 * (`#hourlyPassModal > .modal > .modal-content`).
 *
 * The pass type still selects which pair of times is submitted, exactly as
 * `updateHourlyPassFields()` did, and the body is the same JSON
 * `POST /submit_hourly_pass` whose handler reads only the present fields.
 */
import { computed, ref } from 'vue';
import api from '@/services/api';
import { toLatinDigits } from '@/utils/numbers';

/* Bound so the bundler does not resolve the legacy absolute path as a module. */
const CLOSE_ICON_URL = '/images/close.png?v=20260928';

const emit = defineEmits(['close', 'submitted']);

const PASS_TYPES = [
    { id: 'first', label: 'پاس اول وقت' },
    { id: 'mid', label: 'پاس بین وقت' },
    { id: 'last', label: 'پاس آخر وقت' },
];

const submitting = ref(false);
const error = ref('');
const notice = ref('');

const passType = ref('');
const typeOpen = ref(false);
const officialTime = ref('');
const entryTime = ref('');
const exitTime = ref('');
const date = ref('');

const selectedType = computed(
    () => PASS_TYPES.find((type) => type.id === passType.value) ?? null,
);

const canSubmit = computed(() => {
    if (!passType.value || !date.value.trim()) {
        return false;
    }

    if (passType.value === 'first') {
        return officialTime.value.trim() !== '' && entryTime.value.trim() !== '';
    }

    if (passType.value === 'mid') {
        return entryTime.value.trim() !== '' && exitTime.value.trim() !== '';
    }

    return officialTime.value.trim() !== '' && exitTime.value.trim() !== '';
});

function toggleTypes() {
    typeOpen.value = !typeOpen.value;
}

function selectType(id) {
    passType.value = id;
    typeOpen.value = false;
    officialTime.value = '';
    entryTime.value = '';
    exitTime.value = '';
}

function showNotice(message) {
    notice.value = message;

    window.setTimeout(() => {
        notice.value = '';
    }, 4000);
}

function resetForm() {
    officialTime.value = '';
    entryTime.value = '';
    exitTime.value = '';
    date.value = '';
}

function cancel() {
    error.value = '';
    emit('close');
}

async function submitPass() {
    if (!canSubmit.value || submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = '';

    const payload = { date: toLatinDigits(date.value) };

    if (officialTime.value.trim()) {
        payload.officialTime = toLatinDigits(officialTime.value);
    }
    if (entryTime.value.trim()) {
        payload.entryTime = toLatinDigits(entryTime.value);
    }
    if (exitTime.value.trim()) {
        payload.exitTime = toLatinDigits(exitTime.value);
    }

    try {
        const response = await api.post('/submit_hourly_pass', payload, { baseURL: '' });

        if (response?.success === false) {
            error.value = response?.message || 'خطا در ثبت پاس ساعتی.';
            return;
        }

        showNotice('پاس ساعتی با موفقیت ثبت شد.');
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
            <h2 id="hourlyPassModalTitle">پاس ساعتی</h2>
        </div>
        <form id="hourlyPassForm" style="padding: 0 var(--req-body-padding) 8px;" @submit.prevent="submitPass">
            <label for="passType">نوع پاس</label>
            <input
                :value="selectedType?.label || ''"
                type="text"
                id="passType"
                name="passType"
                placeholder="انتخاب نوع پاس"
                readonly
                required
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                aria-required="true"
                aria-haspopup="listbox"
                @click="toggleTypes"
            >
            <!-- Always in the DOM, hidden inline — as `#passTypeDropdown` was. -->
            <div id="passTypeDropdown" class="dropdown-content" role="listbox" :style="{ display: typeOpen ? 'block' : 'none' }">
                <div
                    v-for="type in PASS_TYPES"
                    :key="type.id"
                    class="dropdown-option"
                    :data-value="type.id"
                    role="option"
                    @click="selectType(type.id)"
                >
                    {{ type.label }}
                </div>
            </div>

            <div id="dynamicFields">
                <template v-if="passType === 'first'">
                    <label for="officialTime">: ساعت موظفی</label>
                    <input v-model="officialTime" type="text" id="officialTime" name="officialTime" placeholder="۱۲:۰۰" required autocomplete="off">

                    <label for="entryTime">: ساعت ورودی</label>
                    <input v-model="entryTime" type="text" id="entryTime" name="entryTime" placeholder="۱۲:۰۰" required autocomplete="off">

                    <label for="date">: تاریخ</label>
                    <input v-model="date" type="text" id="date" name="date" placeholder="۱۴۰۳/۰۱/۰۱" required autocomplete="off">
                </template>

                <template v-else-if="passType === 'mid'">
                    <label for="exitTime">: ساعت خروج</label>
                    <input v-model="exitTime" type="text" id="exitTime" name="exitTime" placeholder="۱۲:۰۰" required autocomplete="off">

                    <label for="entryTime">: ساعت ورود</label>
                    <input v-model="entryTime" type="text" id="entryTime" name="entryTime" placeholder="۱۲:۰۰" required autocomplete="off">

                    <label for="date">: تاریخ</label>
                    <input v-model="date" type="text" id="date" name="date" placeholder="۱۴۰۳/۰۱/۰۱" required autocomplete="off">
                </template>

                <template v-else-if="passType === 'last'">
                    <label for="officialTime">: ساعت موظفی</label>
                    <input v-model="officialTime" type="text" id="officialTime" name="officialTime" placeholder="۱۲:۰۰" required autocomplete="off">

                    <label for="exitTime">: ساعت خروج</label>
                    <input v-model="exitTime" type="text" id="exitTime" name="exitTime" placeholder="۱۲:۰۰" required autocomplete="off">

                    <label for="date">: تاریخ</label>
                    <input v-model="date" type="text" id="date" name="date" placeholder="۱۴۰۳/۰۱/۰۱" required autocomplete="off">
                </template>
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
