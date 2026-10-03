<script setup>
/**
 * Hourly passes — the Vue equivalent of the legacy hourly-pass modal
 * (`#hourlyPassModal`) and the pass report table (`#passsaatiReportTable`).
 *
 * The pass type selects which pair of times is submitted, exactly as the
 * legacy `updateHourlyPassFields()` did:
 *   * پاس اول وقت  → officialTime (ساعت موظفی) + entryTime (ساعت ورود)
 *   * پاس بین وقت  → entryTime (ساعت ورود) + exitTime (ساعت خروج)
 *   * پاس آخر وقت  → officialTime (ساعت موظفی) + exitTime (ساعت خروج)
 * plus a Jalali `date`.  The body is JSON to `POST /submit_hourly_pass`, whose
 * handler reads only the fields that are present.
 *
 * The list is `GET /get_hourly_pass_requests` (administrator scoped, bare
 * array), filtered to the signed-in user's own rows.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toLatinDigits, toPersianDigits } from '@/utils/numbers';

const auth = useAuthStore();

const PASS_TYPES = [
    { id: 'first', label: 'پاس اول وقت' },
    { id: 'mid', label: 'پاس بین وقت' },
    { id: 'last', label: 'پاس آخر وقت' },
];

const loading = ref(true);
const submitting = ref(false);
const error = ref('');
const notice = ref('');

const records = ref([]);
const listDenied = ref(false);

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

async function loadRecords() {
    listDenied.value = false;

    const response = await api.get('/get_hourly_pass_requests', { baseURL: '' });
    const rows = Array.isArray(response) ? response : [];

    records.value = rows.filter(
        (row) => String(row.username || '').trim() === auth.username,
    );
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
        officialTime.value = '';
        entryTime.value = '';
        exitTime.value = '';
        date.value = '';
        await loadRecords();
    } catch (failure) {
        if (failure?.status === 403) {
            error.value = 'شما مجاز به مشاهده همه درخواست‌های پاس ساعتی نیستید.';
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
            error.value = failure?.message || 'خطا در دریافت اطلاعات پاس ساعتی.';
        }
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="pass-page" aria-label="پاس ساعتی">
        <div class="modal-content">
            <div class="form-section-header">
                <h2 id="hourlyPassModalTitle">پاس ساعتی</h2>
            </div>
            <form id="hourlyPassForm" class="pass-form" style="padding: 0 var(--req-body-padding) 8px;" @submit.prevent="submitPass">
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
                <div v-if="typeOpen" id="passTypeDropdown" class="dropdown-content" role="listbox">
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
                    <button type="button" class="btn-cancel" @click="passType = ''; officialTime = ''; entryTime = ''; exitTime = ''; date = ''">انصراف</button>
                </div>
            </form>
        </div>

        <!-- پاپ اپ جدول پاس های ساعتی -->
        <div class="report-hour-box">
            <h2>مشروح گزارش</h2>
            <div id="text-hour-box">
                <p>مدت زمان پاس های ساعتی: ۰</p>
            </div>
            <div class="table-scroll">
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
                        <tr v-for="(record, index) in records" :key="record.id || index">
                            <td>{{ record.status || 'انتظار تایید' }}</td>
                            <td>{{ toPersianDigits(record.pass_duration || '—') }}</td>
                            <td>{{ record.pass_title || '—' }}</td>
                            <td>{{ toPersianDigits(record.request_date || '—') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
