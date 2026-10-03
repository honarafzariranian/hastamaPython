<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '@/services/api';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits } from '@/utils/numbers';

const { isDark, toggleTheme } = useTheme();

const logoUrl = '/images/lab-logo.png';
const brandUrl = '/images/newlogo.png';

const settings = ref(null);
const loading = ref(true);
const error = ref('');
const printing = ref(false);

const labelData = ref({
    service: 'پذیرش',
    number: '',
    admission: '',
    name: '',
    age: '',
    national_id: '',
    phone: '',
    insurance_track: '',
    insurance_base: '',
    insurance_extra: '',
    datetime: '',
});

const clinicName = 'آزمایشگاه تشخیص طبی دکتر امینی';
const clinicSlogan = 'همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز';

function labelDateTimeText(now) {
    now = now || new Date();
    let date, time;
    try {
        date = new Intl.DateTimeFormat('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
        time = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
    } catch {
        date = now.toLocaleDateString('fa-IR');
        time = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    }
    return toPersianDigits(date + ' - ' + time);
}

async function loadConfig() {
    loading.value = true;
    error.value = '';
    try {
        const res = await api.get('/label/config');
        settings.value = res.settings || {};
        labelData.value.datetime = labelDateTimeText();
    } catch (err) {
        error.value = err.message || 'خطا در دریافت تنظیمات لیبل.';
    } finally {
        loading.value = false;
    }
}

async function printDocument() {
    printing.value = true;
    try {
        const res = await api.post('/label/print-document', {
            ticket: {
                service: labelData.value.service,
                number: labelData.value.number,
                persian_number: toPersianDigits(labelData.value.number),
            },
            patient: {
                admission_number: labelData.value.admission,
                name: labelData.value.name,
                age: labelData.value.age,
                national_id: labelData.value.national_id,
                phone: labelData.value.phone,
                insurance_track: labelData.value.insurance_track,
                insurance_base: labelData.value.insurance_base,
                insurance_extra: labelData.value.insurance_extra,
            },
        });
        if (res.html) {
            const win = window.open('', '_blank', 'width=560,height=700');
            if (win) {
                win.document.open();
                win.document.write(res.html);
                win.document.close();
                setTimeout(() => { win.focus(); win.print(); }, 600);
            }
        }
    } catch (err) {
        error.value = err.message || 'خطا در چاپ سند.';
    } finally {
        printing.value = false;
    }
}

const showName = computed(() => settings.value?.show_name !== false);
const showTime = computed(() => settings.value?.show_time !== false);
const showHint = computed(() => settings.value?.show_hint !== false);
const hasAdmission = computed(() => !!labelData.value.admission);

onMounted(() => {
    loadConfig();
});
</script>

<template>
    <div class="lbl-print-page">
        <div class="lbl-page">
            <div class="lbl" data-template="queue" aria-label="لیبل نوبت">
                <span class="lbl__accent" aria-hidden="true"></span>
                <div class="lbl__content">
                    <header class="lbl__header">
                        <span class="lbl__datetime" data-field="time" title="تاریخ و ساعت ثبت نوبت" v-if="showTime">
                            <span class="lbl__datetime-value" data-field="datetime">{{ labelData.datetime }}</span>
                        </span>
                        <div class="lbl__identity">
                            <img class="lbl__logo" :src="logoUrl" alt="لوگوی آزمایشگاه">
                            <div class="lbl__identity-text">
                                <h3 class="lbl__lab-name">{{ clinicName }}</h3>
                                <p class="lbl__slogan">{{ clinicSlogan }}</p>
                            </div>
                        </div>
                    </header>

                    <section class="lbl__queue" aria-label="شماره نوبت">
                        <div class="lbl__number-box">
                            <span class="lbl__service" data-field="service">{{ labelData.service }}</span>
                            <span class="lbl__queue-number" data-field="number">{{ toPersianDigits(labelData.number) }}</span>
                        </div>
                    </section>

                    <section class="lbl__records" aria-label="اطلاعات مراجعه‌کننده و بیمه" v-if="hasAdmission">
                        <div class="lbl__record-group lbl__record-group--patient" data-field="patient">
                            <div class="lbl__record" data-field="admission">
                                <span class="lbl__record-label">شماره پذیرش</span>
                                <span class="lbl__record-value lbl__record-value--num" data-field="admission-value">{{ toPersianDigits(labelData.admission) }}</span>
                            </div>
                            <div class="lbl__record" data-field="name" v-if="showName">
                                <span class="lbl__record-label">نام و نام خانوادگی</span>
                                <span class="lbl__record-value" data-field="name-value">{{ labelData.name || '—' }}</span>
                            </div>
                            <div class="lbl__record" data-field="age">
                                <span class="lbl__record-label">سن</span>
                                <span class="lbl__record-value" data-field="age-value">{{ labelData.age || '—' }}</span>
                            </div>
                            <div class="lbl__record" data-field="national">
                                <span class="lbl__record-label">شماره ملی</span>
                                <span class="lbl__record-value lbl__record-value--num" data-field="national-value">{{ labelData.national_id || '—' }}</span>
                            </div>
                            <div class="lbl__record" data-field="phone">
                                <span class="lbl__record-label">شماره همراه</span>
                                <span class="lbl__record-value lbl__record-value--num" data-field="phone-value">{{ labelData.phone || '—' }}</span>
                            </div>
                        </div>
                        <div class="lbl__record-group lbl__record-group--insurance" data-field="insurance">
                            <div class="lbl__record">
                                <span class="lbl__record-label">
                                    <svg width="6" height="6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                    کد پیگیری بیمه
                                </span>
                                <span class="lbl__record-value lbl__record-value--num lbl__record-value--track" data-field="insurance-track">{{ labelData.insurance_track || '—' }}</span>
                            </div>
                            <div class="lbl__record">
                                <span class="lbl__record-label">بیمه پایه</span>
                                <span class="lbl__record-value" data-field="insurance-base">{{ labelData.insurance_base || '—' }}</span>
                            </div>
                            <div class="lbl__record">
                                <span class="lbl__record-label">بیمه تکمیلی</span>
                                <span class="lbl__record-value" data-field="insurance-extra">{{ labelData.insurance_extra || '—' }}</span>
                            </div>
                        </div>
                    </section>

                    <p class="lbl__message" data-field="hint" v-if="showHint">
                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19.5 12.6 12 20l-7.5-7.4A5 5 0 1 1 12 6.3a5 5 0 1 1 7.5 6.3"></path><polyline points="8.5 12.5 11 12.5 12.5 10 14 15 15.5 12.5 17.5 12.5" stroke-width="1.8"></polyline></svg>
                        <span class="lbl__message-text">نوبت شما با دقت پیگیری می‌شود؛ به‌محض آماده‌شدن از همین سامانه اعلام خواهد شد.</span>
                    </p>

                    <footer class="lbl__footer">
                        <img class="lbl__brand-logo" :src="brandUrl" alt="هستما">
                        <div class="lbl__brand-text">
                            <strong>سامانه نوبت‌دهی و فراخوان هستما</strong>
                            <span>محصولی از شرکت هنر افزار ایرانیان</span>
                        </div>
                    </footer>
                </div>
            </div>
        </div>
    </div>
</template>
