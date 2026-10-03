<script setup>
/**
 * The ticketing kiosk — the Vue equivalent of `app/templates/ticket-kiosk.html`,
 * which is a standalone document: its own `<style>` block (1,865 lines), its own
 * scripts, and `<body class="portrait">`.
 *
 * The stylesheet is imported with `?inline` and injected while this route is
 * mounted instead of being added to `app.css`, because the legacy block starts
 * with a document reset (`* { margin: 0; padding: 0; box-sizing: border-box }`)
 * and a `body` rule — global selectors that belong to the kiosk document and
 * must not reach any other page.  Mount/unmount therefore mirrors the document
 * being loaded and closed, and the `portrait` class on `<body>` reproduces the
 * kiosk's own body class, which the portrait rules select on.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '@/services/api';
import { useTheme } from '@/composables/useTheme';
import { toPersianDigits, toLatinDigits } from '@/utils/numbers';
import kioskCss from '../../../css/legacy/ticket-kiosk.css?inline';

const { isDark, toggleTheme } = useTheme();

/* The kiosk document's own stylesheet and body class — see the note above. */
const KIOSK_BODY_CLASS = 'portrait';
let kioskStylesheet = null;

function enterKioskDocument() {
    kioskStylesheet = document.createElement('style');
    kioskStylesheet.setAttribute('data-page', 'ticket-kiosk');
    kioskStylesheet.textContent = kioskCss;
    document.head.appendChild(kioskStylesheet);
    document.body.classList.add(KIOSK_BODY_CLASS);
}

function leaveKioskDocument() {
    if (kioskStylesheet) {
        kioskStylesheet.remove();
        kioskStylesheet = null;
    }
    document.body.classList.remove(KIOSK_BODY_CLASS);
}

const logoUrl = '/images/lab-logo.png';

const CHECKUPS = [
    {
        id: 'general', name: 'چکاپ عمومی سالانه', color: '#0ea5e9',
        desc: 'این چکاپ برای همه افرادی که می‌خواهند از سلامت کلی بدنشان مطمئن شوند مناسب است. با یک آزمایش ساده خون و ادرار، وضعیت قند، چربی، کلیه، کبد و کم‌خونی شما بررسی می‌شود.',
        tests: ['قند خون ناشتا و هموگلوبین A1c', 'چربی خون: کلسترول، تری‌گلیسیرید، HDL و LDL', 'عملکرد کلیه: اوره و کراتینین', 'عملکرد کبد: آنزیم‌های کبدی', 'کم‌خونی و ویتامین‌ها: CBC، آهن، ویتامین D و B12', 'آنالیز کامل ادرار'],
        time: 'جواب آزمایش‌های اصلی معمولاً همان روز و حداکثر ۲۴ ساعت بعد حاضر است.',
        shortTime: '۲۴ تا ۴۸ ساعت',
        urgent: 'اگر جواب شما خیلی خارج از محدوده نرمال بود، حتماً همان روز با پزشک مشورت کنید.'
    },
    {
        id: 'hormone', name: 'چکاپ هورمونی', color: '#8b5cf6',
        desc: 'هورمون‌ها تنظیم‌کننده تقریباً همه چیز در بدن هستند: خواب، وزن، انرژی، خلق‌وخو و قاعدگی. اگر احساس خستگی دائمی، ریزش مو، تغییر وزن بی‌دلیل یا بی‌نظمی قاعدگی دارید، این پنل به پزشک کمک می‌کند ریشه مشکل را پیدا کند.',
        tests: ['TSH، T3 و T4 (عملکرد تیروئید)', 'هورمون‌های زنانه: FSH، LH، استرادیول و پروژسترون', 'هورمون‌های مردانه: تستوسترون آزاد و کلی', 'پرولاکتین (هورمون شیردهی)', 'کورتیزول صبحگاهی (هورمون استرس)', 'انسولین ناشتا'],
        time: 'بیشتر هورمون‌ها ظرف ۲۴ تا ۴۸ ساعت جواب می‌دهند. برخی تست‌های خاص ممکن است تا ۷۲ ساعت زمان ببرند.',
        shortTime: '۲۴ تا ۷۲ ساعت',
        urgent: 'اگر TSH خیلی پایین یا خیلی بالا بود، حتماً زودتر به پزشک غدد مراجعه کنید.'
    },
    {
        id: 'marriage', name: 'چکاپ پیش از ازدواج', color: '#f0c040',
        desc: 'این چکاپ قبل از عقد و ازدواج توصیه می‌شود تا هر دو نفر از سلامت خود و احتمال انتقال برخی بیماری‌ها مطمئن شوند.',
        tests: ['گروه خونی و فاکتور Rh دو نفر', 'تالاسمی مینور: CBC و تست هموگلوبین الکتروفورز', 'سازگاری Rh زوج‌ها', 'سیفلیس (VDRL)، هپاتیت B و C و HIV', 'قند خون ناشتا', 'معاینه و مشاوره ژنتیک در صورت نیاز'],
        time: 'جواب اکثر آزمایش‌ها همان روز حاضر است. تست تالاسمی معمولاً ۲ تا ۳ روز زمان می‌برد.',
        shortTime: '۱ تا ۳ روز',
        urgent: 'اگر نتیجه تالاسمی مینور برای هر دو نفر مثبت بود، حتماً قبل از تصمیم نهایی با مشاور ژنتیک صحبت کنید.'
    },
    {
        id: 'sti', name: 'چکاپ بیماری‌های مقاربتی', color: '#14b8a6',
        desc: 'این پنل برای کسانی است که می‌خواهند از سلامت خود در زمینه بیماری‌های منتقله از راه تماس جنسی مطمئن شوند. خیلی از این عفونت‌ها هیچ علائمی ندارند ولی قابل درمان هستند.',
        tests: ['HIV (ویروس نقص ایمنی)', 'هپاتیت B و C', 'سیفلیس (VDRL / RPR)', 'کلامیدیا و سوزاک (PCR)', 'هرپس تناسلی (HSV نوع ۱ و ۲)', 'تریکوموناس'],
        time: 'آزمایش‌های خونی ظرف ۲۴ ساعت جواب می‌دهند. تست‌های PCR حدود ۴۸ تا ۷۲ ساعت زمان می‌برند.',
        shortTime: '۲۴ تا ۷۲ ساعت',
        urgent: 'اگر جواب HIV یا سیفلیس مثبت شد، نگران نباشید — هر دو قابل درمان و کنترل هستند.'
    },
    {
        id: 'heart', name: 'چکاپ قلب و عروق', color: '#ef4444',
        desc: 'این پنل ریسک‌های قلبی شما را بررسی می‌کند: فشار خون، چربی‌های خون و التهاب عروق.',
        tests: ['پروفایل کامل چربی خون', 'لیپوپروتئین(a) و ApoA / ApoB', 'هموسیستئین', 'hs-CRP (پروتئین واکنشی C حساس)', 'قند خون و هموگلوبین A1c', 'الکترولیت‌ها و عملکرد کلیه'],
        time: 'اکثر آزمایش‌ها ۲۴ ساعته آماده می‌شوند. لیپوپروتئین(a) ممکن است تا ۷۲ ساعت زمان ببرد.',
        shortTime: '۲۴ تا ۷۲ ساعت',
        urgent: 'اگر درد قفسه سینه، تنگی نفس غیرعادی یا درد فک و بازو دارید، منتظر جواب آزمایش نمانید.'
    },
    {
        id: 'diabetes', name: 'چکاپ قند و دیابت', color: '#f59e0b',
        desc: 'دیابت در مراحل اولیه هیچ علامتی ندارد و معمولاً سال‌ها بی‌صدا پیش می‌رود. این پنل مشخص می‌کند که سالم هستید، در مرز خطر هستید یا دیابت دارید.',
        tests: ['قند خون ناشتا و قند تصادفی', 'هموگلوبین A1c', 'انسولین ناشتا و مقاومت به انسولین (HOMA-IR)', 'کراتینین و اوره', 'چربی خون همراه', 'ادرار ۲۴ ساعته در صورت نیاز پزشک'],
        time: 'قند ناشتا و A1c همان روز جواب می‌دهند. سایر تست‌ها حداکثر تا ۴۸ ساعت حاضر می‌شوند.',
        shortTime: '۲۴ تا ۴۸ ساعت',
        urgent: 'اگر قند تصادفی بالای ۳۰۰ بود یا علائمی مثل تشنگی شدید دارید، فوراً به اورژانس بروید.'
    },
    {
        id: 'thyroid', name: 'چکاپ تیروئید کامل', color: '#38bdf8',
        desc: 'تیروئید موتور بدن است. کم‌کاری آن باعث خستگی و اضافه وزن می‌شود و پرکاری آن باعث تپش قلب و لاغری.',
        tests: ['TSH حساس نسل سوم', 'T4 آزاد و T3 آزاد', 'آنتی‌بادی‌های تیروئید: Anti-TPO و Anti-TG', 'تیروگلوبولین', 'سونوگرافی تیروئید در صورت توصیه پزشک'],
        time: 'TSH و هورمون‌ها ۲۴ ساعته جواب می‌دهند. آنتی‌بادی‌ها معمولاً ۴۸ ساعت زمان می‌برند.',
        shortTime: '۲۴ تا ۴۸ ساعت',
        urgent: 'اگر همراه جواب پرکاری، تپش قلب شدید و کاهش وزن سریع دارید سریع به پزشک مراجعه کنید.'
    },
    {
        id: 'vitamin', name: 'چکاپ ویتامین و کم‌خونی', color: '#22c55e',
        desc: 'کمبود ویتامین D و آهن در کشور ما خیلی شایع است و علائم آن (خستگی، ریزش مو، ضعف) معمولاً به چیز دیگری نسبت داده می‌شود.',
        tests: ['ویتامین D (25-OH)', 'ویتامین B12 و فولات', 'آهن سرم، فریتین و ظرفیت اتصال آهن (TIBC)', 'شمارش کامل خون (CBC)', 'روی (Zn) و منیزیم', 'کلسیم و فسفر'],
        time: 'CBC و آهن همان روز جواب می‌دهند. ویتامین D و B12 معمولاً ۴۸ ساعت زمان می‌برند.',
        shortTime: '۲۴ تا ۴۸ ساعت',
        urgent: 'اگر هموگلوبین شما زیر ۸ بود، همزمان با دریافت مکمل به پزشک مراجعه کنید.'
    },
    {
        id: 'pregnancy', name: 'چکاپ بارداری و پیش از بارداری', color: '#ec4899',
        desc: 'اگر قصد بارداری دارید یا تازه باردار شده‌اید، این پنل کمک می‌کند سلامت مادر و جنین از همان اول تضمین شود.',
        tests: ['تست بارداری (β-hCG)', 'گروه خونی و Rh مادر', 'CDC کامل و آهن و فریتین', 'ویتامین D و کلسیم', 'هپاتیت B، HIV و سیفلیس', 'بررسی ایمنی سرخجه و آبله مرغان (IgG)'],
        time: 'تست بارداری همان روز جواب می‌دهد. سایر آزمایش‌ها ۲۴ تا ۴۸ ساعته حاضر می‌شوند.',
        shortTime: '۲ ساعت تا ۴۸ ساعت',
        urgent: 'اگر باردار هستید و خونریزی یا درد شدید شکم دارید، فوراً به اورژانس زنان مراجعه کنید.'
    },
    {
        id: 'liver', name: 'چکاپ کبد', color: '#d97706',
        desc: 'کبد یک ارگان ساکت است — تا مشکل بزرگ نشود، علامتی نمی‌دهد. اگر الکل مصرف می‌کنید، چاق هستید یا داروهای طولانی‌مدت می‌خورید، این چکاپ سالانه برای شماست.',
        tests: ['آنزیم‌های کبدی: AST، ALT، ALP و GGT', 'بیلی‌روبین کل و مستقیم', 'پروتئین کل و آلبومین', 'هپاتیت A، B و C', 'شاخص FIB-4 (برآورد چربی و فیبروز کبد)', 'زمان پروترومبین (PT)'],
        time: 'آنزیم‌های کبدی و بیلی‌روبین همان روز جواب می‌دهند. تست‌های هپاتیت ۲۴ تا ۴۸ ساعت زمان می‌برند.',
        shortTime: '۲۴ تا ۴۸ ساعت',
        urgent: 'زردی پوست و چشم، ادرار پررنگ مثل چای و درد شدید بالای شکم سمت راست، نشانه مشکلات حاد کبدی است.'
    }
];

const selectedService = ref(null);
const showChoice = ref(false);
const showCheckups = ref(false);
const showCheckupDetail = ref(false);
const activeCheckup = ref(null);
const showSampling = ref(false);
const showResult = ref(false);
const showPatientForm = ref(false);
const showEdit = ref(false);
const showTicketModal = ref(false);
const showKeyboard = ref(false);

const samplingInput = ref('');
const samplingError = ref('');
const resultInput = ref('');
const resultError = ref('');
const editTicketNumber = ref('');
const editError1 = ref('');
const editError2 = ref('');
const editSuccess = ref(false);
const editTicketData = ref(null);

const currentStep = ref(0);
const totalSteps = 6;
const patientName = ref('');
const patientAge = ref('');
const patientNational = ref('');
const patientPhone = ref('');
const insuranceBase = ref('');
const insuranceExtra = ref('');
const stepError = ref('');

const pendingService = ref(null);
const pendingCheckupId = ref(null);

const issuedTicket = ref(null);
const issuedService = ref('');
const issuedHint = ref('');

const queueCount = ref(0);
const clockTime = ref('');
const clockDate = ref('');

const activeKbInput = ref(null);
const kbShift = ref(false);

const PERSIAN_ROWS = [
    ['ض', 'ص', 'ث', 'ق', 'ف', 'غ', 'ع', 'ه', 'خ', 'ح', 'ج', 'چ'],
    ['ش', 'س', 'ی', 'ب', 'ل', 'ا', 'ت', 'ن', 'م', 'ک', 'گ'],
    ['ظ', 'ط', 'ز', 'ر', 'ذ', 'د', 'پ', 'و', 'ژ']
];
const PERSIAN_SHIFT_ROWS = [
    ['َ', 'ً', 'ُ', 'ٌ', 'ّ', 'ْ', 'ٓ', 'ٰ', 'ژ', 'ِ', 'ٔ', 'ٖ'],
    ['ٛ', 'ٜ', 'ٝ', 'ٞ', 'ٟ', 'ۤ', 'ۥ', 'ۧ', 'ۨ', '٬', '٫'],
    ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹']
];

function updateClock() {
    const now = new Date();
    try {
        clockTime.value = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        clockDate.value = now.toLocaleDateString('fa-IR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    } catch {
        clockTime.value = now.toLocaleTimeString('fa-IR');
        clockDate.value = now.toLocaleDateString('fa-IR');
    }
}

function selectService(service) {
    selectedService.value = service;
    if (service === 'پذیرش') {
        showChoice.value = true;
    } else if (service === 'نمونه‌گیری') {
        openSampling();
    } else if (service === 'جوابدهی') {
        openResult();
    } else if (service === 'اصلاح پذیرش') {
        openEdit();
    } else if (service === 'نوبت آزاد') {
        issueTicket('نوبت آزاد', {});
    }
}

function closeChoice() {
    showChoice.value = false;
}

function chooseCheckup() {
    closeChoice();
    showCheckups.value = true;
}

function chooseFreeTest() {
    closeChoice();
    showPatientForm.value = true;
    pendingService.value = 'تست آزاد';
    pendingCheckupId.value = null;
    resetPatientForm();
}

function choosePrescription() {
    closeChoice();
    showPatientForm.value = true;
    pendingService.value = 'نسخه‌دار';
    pendingCheckupId.value = null;
    resetPatientForm();
}

function openCheckups() {
    showCheckups.value = true;
}

function closeCheckups() {
    showCheckups.value = false;
    showCheckupDetail.value = false;
    activeCheckup.value = null;
}

function showCheckup(id) {
    activeCheckup.value = CHECKUPS.find(c => c.id === id);
    showCheckupDetail.value = true;
}

function hideCheckupDetail() {
    showCheckupDetail.value = false;
    activeCheckup.value = null;
}

function takeCheckupTicket(id) {
    const checkup = CHECKUPS.find(c => c.id === id);
    closeCheckups();
    showPatientForm.value = true;
    pendingService.value = checkup.name;
    pendingCheckupId.value = id;
    resetPatientForm();
}

function openSampling() {
    samplingInput.value = '';
    samplingError.value = '';
    showSampling.value = true;
    setTimeout(() => {
        const inp = document.getElementById('samplingAdmissionNo');
        if (inp) { inp.focus(); openKeyboard(inp); }
    }, 350);
}

function closeSampling() {
    showSampling.value = false;
    closeKeyboard();
    samplingInput.value = '';
    samplingError.value = '';
}

function submitSampling() {
    const raw = samplingInput.value.trim();
    const en = toLatinDigits(raw).trim();
    if (!en) {
        samplingError.value = 'شماره پذیرش الزامی است — لطفاً آن را وارد کنید';
        return;
    }
    if (en.length < 2 || en.length > 20) {
        samplingError.value = 'شماره پذیرش معتبر نیست';
        return;
    }
    closeSampling();
    issueTicket('نمونه‌گیری', {
        admission_number: en,
        admission_number_persian: raw,
        flow: 'sampling_with_admission'
    });
}

function samplingNoId() {
    closeSampling();
    showPatientForm.value = true;
    pendingService.value = 'نمونه‌گیری';
    pendingCheckupId.value = null;
    resetPatientForm();
}

function openResult() {
    resultInput.value = '';
    resultError.value = '';
    showResult.value = true;
    setTimeout(() => {
        const inp = document.getElementById('resultAdmissionNo');
        if (inp) { inp.focus(); openKeyboard(inp); }
    }, 350);
}

function closeResult() {
    showResult.value = false;
    closeKeyboard();
    resultInput.value = '';
    resultError.value = '';
}

function submitResult() {
    const raw = resultInput.value.trim();
    const en = toLatinDigits(raw).trim();
    if (!en) {
        resultError.value = 'شماره پذیرش الزامی است — لطفاً آن را وارد کنید';
        return;
    }
    if (en.length < 2 || en.length > 20) {
        resultError.value = 'شماره پذیرش معتبر نیست';
        return;
    }
    closeResult();
    issueTicket('جوابدهی', {
        admission_number: en,
        admission_number_persian: raw,
        flow: 'result_delivery'
    });
}

function openEdit() {
    resetEditToStep1();
    showEdit.value = true;
    setTimeout(() => {
        const inp = document.getElementById('editTicketNumber');
        if (inp) { inp.focus(); openKeyboard(inp); }
    }, 350);
}

function closeEdit() {
    showEdit.value = false;
    closeKeyboard();
    editTicketData.value = null;
}

function resetEditToStep1() {
    editTicketNumber.value = '';
    editError1.value = '';
    editError2.value = '';
    editSuccess.value = false;
    editTicketData.value = null;
}

async function fetchTicketForEdit() {
    const raw = editTicketNumber.value.trim();
    const num = toLatinDigits(raw).trim();
    if (!num || isNaN(parseInt(num))) {
        editError1.value = 'لطفاً شماره نوبت را وارد کنید';
        return;
    }
    editError1.value = '';
    try {
        const res = await api.get('/queue/ticket/' + encodeURIComponent(num));
        const ticket = res.ticket;
        if (ticket.status !== 'waiting') {
            editError1.value = 'این نوبت دیگر در انتظار نیست و قابل اصلاح نمی‌باشد.';
            return;
        }
        editTicketData.value = ticket;
        editError1.value = '';
        editError2.value = '';
        editSuccess.value = false;
    } catch (e) {
        editError1.value = e.message || 'خطا در جستجوی نوبت';
    }
}

async function submitEditTicket() {
    if (!editTicketData.value) return;
    editError2.value = '';
    editSuccess.value = false;

    const payload = {
        patient_name: document.getElementById('editName')?.value?.trim() || '',
        patient_age: toLatinDigits(document.getElementById('editAge')?.value?.trim() || ''),
        patient_national_id: toLatinDigits(document.getElementById('editNationalId')?.value?.trim() || ''),
        patient_phone: toLatinDigits(document.getElementById('editPhone')?.value?.trim() || ''),
        insurance_base: document.getElementById('editInsuranceBase')?.value?.trim() || '',
        insurance_extra: document.getElementById('editInsuranceExtra')?.value?.trim() || ''
    };

    if (!payload.patient_name) {
        editError2.value = 'نام الزامی است';
        return;
    }

    try {
        await api.put('/queue/ticket/' + editTicketData.value.ticket_number, payload);
        editSuccess.value = true;
        setTimeout(closeEdit, 2000);
    } catch (e) {
        editError2.value = e.message || 'خطا در اصلاح اطلاعات';
    }
}

function resetPatientForm() {
    patientName.value = '';
    patientAge.value = '';
    patientNational.value = '';
    patientPhone.value = '';
    insuranceBase.value = '';
    insuranceExtra.value = '';
    currentStep.value = 0;
    stepError.value = '';
}

function validateStep(idx) {
    stepError.value = '';
    const required = [true, true, true, true, false, false];
    if (!required[idx]) return true;

    let val = '';
    if (idx < 4) {
        const fields = [patientName, patientAge, patientNational, patientPhone];
        val = fields[idx].value.trim();
    } else if (idx === 4) {
        val = insuranceBase.value;
    }

    if (!val) {
        const labels = ['نام و نام خانوادگی', 'سن', 'شماره ملی', 'شماره تماس', 'بیمه پایه'];
        stepError.value = labels[idx] + ' را وارد کنید';
        return false;
    }
    if (idx === 1) {
        const age = parseInt(toLatinDigits(val));
        if (isNaN(age) || age < 1 || age > 120) {
            stepError.value = 'سن معتبر نیست';
            return false;
        }
    }
    if (idx === 2) {
        if (toLatinDigits(val).length !== 10) {
            stepError.value = 'شماره ملی باید ۱۰ رقم باشد';
            return false;
        }
    }
    if (idx === 3) {
        const en = toLatinDigits(val);
        if (en.length !== 11 || en.substring(0, 2) !== '09') {
            stepError.value = 'شماره تماس معتبر نیست';
            return false;
        }
    }
    return true;
}

function nextStep() {
    if (currentStep.value < totalSteps - 1) {
        if (!validateStep(currentStep.value)) return;
        currentStep.value++;
    } else {
        submitPatientForm();
    }
}

function prevStep() {
    if (currentStep.value > 0) currentStep.value--;
}

function selectInsurance(val) {
    insuranceBase.value = val;
}

async function submitPatientForm() {
    if (!validateStep(currentStep.value)) return;

    const patientData = {
        name: patientName.value.trim(),
        age: toLatinDigits(patientAge.value.trim()),
        national_id: toLatinDigits(patientNational.value.trim()),
        phone: toLatinDigits(patientPhone.value.trim()),
        insurance_base: insuranceBase.value === 'none' ? '' : insuranceBase.value,
        insurance_extra: insuranceExtra.value.trim()
    };

    showPatientForm.value = false;
    issueTicket(pendingService.value, patientData);
}

async function issueTicket(service, patientData) {
    try {
        const res = await api.post('/queue/take', { service, patient: patientData });
        const ticket = res.ticket || {};
        issuedTicket.value = ticket;
        issuedService.value = service;
        if (service === 'جوابدهی') {
            issuedHint.value = 'لطفاً منتظر بمانید تا شماره شما را صدا بزنند، سپس برای دریافت جواب آزمایش خود مراجعه کنید.';
        } else {
            issuedHint.value = 'لطفاً منتظر بمانید تا پذیرش شماره شما را صدا بزند، سپس برای انجام کارهای خود مراجعه کنید.';
        }
        showTicketModal.value = true;
        refreshQueueCount();
        printIssuedTicket(ticket, patientData);
    } catch (err) {
        showToast(err.message || 'ثبت نوبت انجام نشد.', 'error');
    }
}

function closeTicketModal() {
    showTicketModal.value = false;
}

async function refreshQueueCount() {
    try {
        const res = await api.get('/queue/list?status=waiting');
        const tickets = res.tickets || [];
        queueCount.value = tickets.length;
    } catch {
    }
}

function openKeyboard(input) {
    activeKbInput.value = input;
    showKeyboard.value = true;
}

function closeKeyboard() {
    showKeyboard.value = false;
    activeKbInput.value = null;
}

function kbType(ch) {
    if (!activeKbInput.value) return;
    activeKbInput.value.value += ch;
    activeKbInput.value.focus();
}

function kbBackspace() {
    if (!activeKbInput.value) return;
    activeKbInput.value.value = activeKbInput.value.value.slice(0, -1);
    activeKbInput.value.focus();
}

function kbShiftToggle() {
    kbShift.value = !kbShift.value;
}

const kbRows = computed(() => kbShift.value ? PERSIAN_SHIFT_ROWS : PERSIAN_ROWS);

async function printIssuedTicket(ticket, patientData) {
    try {
        await api.post('/queue/print', { ticket: ticket || {}, patient: patientData || {} });
    } catch {
        try {
            const res = await api.post('/label/print-document', { ticket: ticket || {}, patient: patientData || {} });
            if (res.html) {
                const win = window.open('', '_blank', 'width=560,height=700');
                if (win) {
                    win.document.open();
                    win.document.write(res.html);
                    win.document.close();
                    setTimeout(() => { win.focus(); win.print(); }, 600);
                }
            }
        } catch {
        }
    }
}

let clockInterval = null;
let queueInterval = null;

onMounted(() => {
    enterKioskDocument();
    updateClock();
    clockInterval = setInterval(updateClock, 1000);
    refreshQueueCount();
    queueInterval = setInterval(refreshQueueCount, 5000);
});

onUnmounted(() => {
    clearInterval(clockInterval);
    clearInterval(queueInterval);
    leaveKioskDocument();
});
</script>

<template>
    <div class="k-page">
        <div class="k-bg">
            <div class="k-orb k-orb--1"></div>
            <div class="k-orb k-orb--2"></div>
            <div class="k-orb k-orb--3"></div>
        </div>

        <div class="k-particles" id="kParticles"></div>

        <div class="k-clock">
            <div class="k-clock-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div class="k-clock-info">
                <div class="k-clock-time" id="kClockTime">{{ clockTime }}</div>
                <div class="k-clock-date" id="kClockDate">{{ clockDate }}</div>
            </div>
        </div>

        <div class="k-toolbar">
            <button type="button" class="k-tool-btn" id="themeBtn" @click="toggleTheme" title="تغییر تم روشن/تاریک">
                <svg class="k-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg class="k-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
            </button>
        </div>

        <div class="k-container">
            <div class="k-logo-section">
                <div class="k-logo">
                    <img :src="logoUrl" alt="لوگوی آزمایشگاه">
                </div>
                <div class="k-title">آزمایشگاه تشخیص طبی دکتر امینی</div>
                <div class="k-subtitle">همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز</div>
            </div>

            <div class="k-main-card">
                <div class="k-main-card__header">
                    <div class="k-main-card__title">
                        <span class="k-main-card__title-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        </span>
                        انتخاب سرویس
                    </div>
                    <div class="k-queue-badge">
                        <span class="k-queue-dot"></span>
                        <span><span id="queueCount">{{ toPersianDigits(queueCount) }}</span> نفر در صف</span>
                    </div>
                </div>

                <div class="k-services">
                    <div class="k-services-row k-services-row--top">
                        <button type="button" class="k-service-card k-service-card--primary k-service-card--wide" @click="selectService('پذیرش')">
                            <div class="k-service-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                    <circle cx="8.5" cy="7" r="4"/>
                                    <line x1="20" y1="8" x2="20" y2="14"/>
                                    <line x1="23" y1="11" x2="17" y2="11"/>
                                </svg>
                            </div>
                            <span class="k-service-label">پذیرش</span>
                            <span class="k-service-desc">پذیرش اولیه، چکاپ‌ها و آزمایش با نسخه</span>
                        </button>
                    </div>
                    <div class="k-services-row k-services-row--bottom">
                        <button type="button" class="k-service-card k-service-card--sampling" @click="selectService('نمونه‌گیری')">
                            <div class="k-service-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M7 21h10"/>
                                    <rect x="10" y="9" width="4" height="12" rx="2"/>
                                    <path d="M12 9V5a2 2 0 00-2-2H8a2 2 0 00-2 2v4"/>
                                    <path d="M12 9V5a2 2 0 012-2h2a2 2 0 012 2v4"/>
                                </svg>
                            </div>
                            <span class="k-service-label">نمونه‌گیری</span>
                            <span class="k-service-desc">فقط با شماره پذیرش</span>
                        </button>
                        <button type="button" class="k-service-card k-service-card--result" @click="selectService('جوابدهی')">
                            <div class="k-service-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                </svg>
                            </div>
                            <span class="k-service-label">جوابدهی</span>
                            <span class="k-service-desc">دریافت جواب آزمایش</span>
                        </button>
                        <button type="button" class="k-service-card k-service-card--edit" @click="selectService('اصلاح پذیرش')">
                            <div class="k-service-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            </div>
                            <span class="k-service-label">اصلاح پذیرش</span>
                            <span class="k-service-desc">ویرایش اطلاعات نوبت</span>
                        </button>
                    </div>
                    <div class="k-services-row k-services-row--blank">
                        <button type="button" class="k-service-card k-service-card--blank k-service-card--wide" @click="selectService('نوبت آزاد')">
                            <div class="k-service-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                                    <path d="M3 10h18"/>
                                    <path d="M8 15h4"/>
                                </svg>
                            </div>
                            <span class="k-service-label">نوبت آزاد</span>
                            <span class="k-service-desc">فقط شماره نوبت — بدون پرکردن اطلاعات</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <footer class="k-footer">
            <span class="k-footer-brand">سامانه نوبت دهی هستما</span>
            <span class="k-footer-sep">|</span>
            <span class="k-footer-company">محصولی از شرکت هنر افزار ایرانیان</span>
            <span class="k-footer-tm">&#x00AE;</span>
        </footer>

        <div class="k-checkup-overlay" :class="{ 'visible': showCheckups }" id="checkupOverlay">
            <div class="k-checkup-shell">
                <div class="k-checkup-header">
                    <button type="button" class="k-back-btn" @click="closeCheckups">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                        <span>بازگشت</span>
                    </button>
                    <h2>چکاپ‌های تخصصی و عمومی</h2>
                </div>
                <div class="k-checkup-sub">برای مشاهده توضیحات کامل، تست‌ها و زمان جواب، روی هر چکاپ لمس کنید</div>
                <div class="k-checkup-list" id="checkupList">
                    <button
                        v-for="(c, i) in CHECKUPS"
                        :key="c.id"
                        type="button"
                        class="k-checkup-card"
                        :style="{ '--cc': c.color, animationDelay: (i * 0.05) + 's' }"
                        @click="showCheckup(c.id)"
                    >
                        <span class="k-checkup-card__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        </span>
                        <span class="k-checkup-card__body">
                            <strong>{{ c.name }}</strong>
                            <span>{{ c.tests.length }} آزمایش در این پنل</span>
                            <em class="k-checkup-card__time">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                {{ c.shortTime }}
                            </em>
                        </span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                </div>
            </div>
            <div class="k-checkup-detail" :class="{ 'visible': showCheckupDetail }" id="checkupDetail">
                <template v-if="activeCheckup">
                    <div class="k-checkup-header">
                        <button type="button" class="k-back-btn" @click="hideCheckupDetail">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                            <span>بازگشت</span>
                        </button>
                        <h2>{{ activeCheckup.name }}</h2>
                    </div>
                    <div class="k-checkup-detail__scroll">
                        <div class="k-checkup-detail__hero">
                            <span class="k-checkup-detail__icon" :style="{ background: activeCheckup.color + '1f' }">
                                <svg viewBox="0 0 24 24" fill="none" :stroke="activeCheckup.color" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            </span>
                            <h3>{{ activeCheckup.name }}</h3>
                        </div>
                        <div class="k-checkup-detail__desc">{{ activeCheckup.desc }}</div>
                        <div class="k-checkup-section-title" :style="{ '--cc': activeCheckup.color }">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            <span>تست‌های این پنل</span>
                        </div>
                        <ul class="k-checkup-tests">
                            <li v-for="t in activeCheckup.tests" :key="t">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span>{{ t }}</span>
                            </li>
                        </ul>
                        <div class="k-checkup-time">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>{{ activeCheckup.time }}</span>
                        </div>
                        <div class="k-checkup-urgent">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span class="k-checkup-urgent__body">
                                <span class="k-checkup-urgent__title">اگر شرایط حاد داشتید چه کنید؟</span>
                                <p>{{ activeCheckup.urgent }}</p>
                            </span>
                        </div>
                        <button type="button" class="k-checkup-cta" @click="takeCheckupTicket(activeCheckup.id)">
                            نوبت «{{ activeCheckup.name }}» بگیرید
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <div class="k-modal-overlay" :class="{ 'visible': showTicketModal }" id="ticketModal">
            <div class="k-modal">
                <div class="k-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div class="k-modal-title">نوبت شما ثبت شد</div>
                <div class="k-modal-number" id="modalNumber">{{ issuedTicket?.persian_number || issuedTicket?.number || '--' }}</div>
                <div class="k-modal-service" id="modalService">{{ issuedService }} | شماره نوبت: {{ issuedTicket?.persian_number || issuedTicket?.number || '--' }}</div>
                <div class="k-modal-hint" id="modalHint">{{ issuedHint }}</div>
                <button type="button" class="k-modal-close" @click="closeTicketModal">بستن</button>
            </div>
        </div>

        <div class="k-choice-overlay" :class="{ 'visible': showChoice }" id="choiceOverlay">
            <div class="k-choice-card">
                <div class="k-choice-header">
                    <div class="k-choice-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                            <circle cx="8.5" cy="7" r="4"/>
                            <line x1="20" y1="8" x2="20" y2="14"/>
                            <line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                    </div>
                    <div>
                        <h2>نوع پذیرش</h2>
                        <p>لطفاً نوع درخواست خود را انتخاب کنید</p>
                    </div>
                    <button type="button" class="k-choice-close" @click="closeChoice" aria-label="بستن و بازگشت به صفحه اول" title="بازگشت به صفحه اول">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="k-choice-body">
                    <button type="button" class="k-choice-btn" @click="chooseCheckup">
                        <span class="k-choice-btn__icon" style="background:rgba(139,92,246,0.1)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        </span>
                        <span class="k-choice-btn__text">
                            <span class="k-choice-btn__title">چکاپ آزاد</span>
                            <span class="k-choice-btn__desc">انتخاب پنل چکاپ تخصصی و عمومی</span>
                        </span>
                        <svg class="k-choice-btn__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" class="k-choice-btn" @click="chooseFreeTest">
                        <span class="k-choice-btn__icon" style="background:rgba(14,165,233,0.1)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21h10"/><rect x="10" y="9" width="4" height="12" rx="2"/><path d="M12 9V5a2 2 0 00-2-2H8a2 2 0 00-2 2v4"/><path d="M12 9V5a2 2 0 012-2h2a2 2 0 012 2v4"/></svg>
                        </span>
                        <span class="k-choice-btn__text">
                            <span class="k-choice-btn__title">تست آزاد</span>
                            <span class="k-choice-btn__desc">آزمایش بدون نسخه پزشک</span>
                        </span>
                        <svg class="k-choice-btn__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" class="k-choice-btn" @click="choosePrescription">
                        <span class="k-choice-btn__icon" style="background:rgba(34,197,94,0.1)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M8 12h8M8 16h8"/></svg>
                        </span>
                        <span class="k-choice-btn__text">
                            <span class="k-choice-btn__title">نسخه‌دار</span>
                            <span class="k-choice-btn__desc">دارای نسخه پزشک معالج</span>
                        </span>
                        <svg class="k-choice-btn__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="k-sampling-overlay" :class="{ 'visible': showSampling }" id="samplingOverlay">
            <div class="k-sampling-card">
                <div class="k-sampling-header">
                    <div class="k-sampling-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M7 21h10"/><rect x="10" y="9" width="4" height="12" rx="2"/><path d="M12 9V5a2 2 0 00-2-2H8a2 2 0 00-2 2v4"/><path d="M12 9V5a2 2 0 012-2h2a2 2 0 012 2v4"/>
                        </svg>
                    </div>
                    <div class="k-sampling-header-text">
                        <h2>نمونه‌گیری</h2>
                        <p>ویژه مراجعین با پذیرش قبلی</p>
                    </div>
                    <button type="button" class="k-choice-close" @click="closeSampling" aria-label="بستن و بازگشت به صفحه اول" title="بازگشت به صفحه اول">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="k-sampling-body">
                    <div class="k-sampling-info">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                        </svg>
                        <p>این دکمه مخصوص <strong>آزمایشات چند مرحله‌ای</strong> و پذیرش‌هایی است که <strong>قبلاً رزرو شده</strong> و کارهای پذیرش آن‌ها انجام شده است. اگر قبلاً پذیرش شده‌اید، شماره پذیرش خود را وارد کنید تا مستقیماً نوبت نمونه‌گیری بگیرید.</p>
                    </div>
                    <div class="k-sampling-field">
                        <label for="samplingAdmissionNo">شماره پذیرش <span style="color:#ef4444">*</span></label>
                        <input id="samplingAdmissionNo" v-model="samplingInput" type="text" class="k-sampling-input" :class="{ 'error': samplingError }" placeholder="مثال: ۱۲۳۴۵" data-kb="number" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        <div class="k-sampling-error" :class="{ 'visible': samplingError }" id="samplingError">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ samplingError }}</span>
                        </div>
                    </div>
                    <div class="k-sampling-actions">
                        <button type="button" class="k-sampling-submit" @click="submitSampling">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>دریافت نوبت نمونه‌گیری</span>
                        </button>
                        <div class="k-sampling-divider">یا</div>
                        <button type="button" class="k-sampling-no-id" @click="samplingNoId">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                            <span>شماره پذیرش ندارم — ثبت اطلاعات کامل</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="k-result-overlay" :class="{ 'visible': showResult }" id="resultOverlay">
            <div class="k-result-card">
                <div class="k-result-header">
                    <div class="k-result-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                    </div>
                    <div class="k-result-header-text">
                        <h2>جوابدهی</h2>
                        <p>دریافت جواب آزمایش — وارد کردن شماره پذیرش الزامی است</p>
                    </div>
                    <button type="button" class="k-choice-close" @click="closeResult" aria-label="بستن و بازگشت به صفحه اول" title="بازگشت به صفحه اول">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="k-result-body">
                    <div class="k-result-info">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                        </svg>
                        <p>برای دریافت جواب آزمایش، <strong>شماره پذیرش</strong> خود را وارد کنید. شماره پذیرش روی برگه‌ای که هنگام مراجعه اول دریافت کرده‌اید درج شده است.</p>
                    </div>
                    <div class="k-result-field">
                        <label for="resultAdmissionNo">شماره پذیرش <span style="color:#ef4444">*</span></label>
                        <input id="resultAdmissionNo" v-model="resultInput" type="text" class="k-result-input" :class="{ 'error': resultError }" placeholder="مثال: ۱۲۳۴۵" data-kb="number" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        <div class="k-result-error" :class="{ 'visible': resultError }" id="resultError">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ resultError }}</span>
                        </div>
                    </div>
                    <button type="button" class="k-result-submit" @click="submitResult">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span>دریافت نوبت جوابدهی</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="k-patient-overlay" :class="{ 'visible': showPatientForm }" id="patientOverlay">
            <div class="k-patient-card">
                <div class="k-patient-header">
                    <div class="k-patient-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                    </div>
                    <div class="k-patient-header-text">
                        <h2>{{ pendingService === 'نمونه‌گیری' ? 'اطلاعات نمونه‌گیری (بدون شماره پذیرش)' : 'اطلاعات بیمار' }}</h2>
                        <p id="stepLabel">مرحله {{ toPersianDigits(currentStep + 1) }} از {{ toPersianDigits(totalSteps) }}</p>
                    </div>
                    <button type="button" class="k-choice-close" @click="showPatientForm = false" aria-label="بستن و بازگشت به صفحه اول" title="بازگشت به صفحه اول">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <div class="k-progress" id="progressBar">
                    <div v-for="i in totalSteps" :key="i" class="k-progress-dot" :class="{ 'active': i - 1 === currentStep, 'done': i - 1 < currentStep }"></div>
                </div>

                <div class="k-step-body">
                    <div v-show="currentStep === 0" class="k-step" :class="{ 'active': currentStep === 0 }" data-step="0">
                        <div class="k-step-icon" style="background: linear-gradient(135deg, #0ea5e91a, #6366f11a)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <div class="k-step-title">نام و نام خانوادگی</div>
                        <div class="k-step-hint">نام کامل خود را وارد کنید</div>
                        <input v-model="patientName" id="pf-name" type="text" class="k-step-input" placeholder="مثال: علی رضایی" data-kb="persian" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        <div class="k-step-error" :class="{ 'visible': stepError && currentStep === 0 }" id="err-name">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ stepError }}</span>
                        </div>
                    </div>

                    <div v-show="currentStep === 1" class="k-step" :class="{ 'active': currentStep === 1 }" data-step="1">
                        <div class="k-step-icon" style="background: linear-gradient(135deg, #8b5cf61a, #a78bfa1a)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div class="k-step-title">سن شما</div>
                        <div class="k-step-hint">سن خود را به سال وارد کنید</div>
                        <input v-model="patientAge" id="pf-age" type="text" class="k-step-input" placeholder="مثال: ۳۵" data-kb="number" maxlength="3" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        <div class="k-step-error" :class="{ 'visible': stepError && currentStep === 1 }" id="err-age">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ stepError }}</span>
                        </div>
                    </div>

                    <div v-show="currentStep === 2" class="k-step" :class="{ 'active': currentStep === 2 }" data-step="2">
                        <div class="k-step-icon" style="background: linear-gradient(135deg, #f59e0b1a, #fbbf241a)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        </div>
                        <div class="k-step-title">شماره ملی</div>
                        <div class="k-step-hint">کد ملی ۱۰ رقمی خود را وارد کنید</div>
                        <input v-model="patientNational" id="pf-national" type="text" class="k-step-input" placeholder="۱۲۳۴۵۶۷۸۹۰" data-kb="number" maxlength="10" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        <div class="k-step-error" :class="{ 'visible': stepError && currentStep === 2 }" id="err-national">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ stepError }}</span>
                        </div>
                    </div>

                    <div v-show="currentStep === 3" class="k-step" :class="{ 'active': currentStep === 3 }" data-step="3">
                        <div class="k-step-icon" style="background: linear-gradient(135deg, #14b8a61a, #2dd4bf1a)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#14b8a6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                        </div>
                        <div class="k-step-title">شماره تماس</div>
                        <div class="k-step-hint">شماره موبایل خود را وارد کنید</div>
                        <input v-model="patientPhone" id="pf-phone" type="text" class="k-step-input" placeholder="۰۹۱۲۱۲۳۴۵۶۷" data-kb="number" maxlength="11" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        <div class="k-step-error" :class="{ 'visible': stepError && currentStep === 3 }" id="err-phone">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ stepError }}</span>
                        </div>
                    </div>

                    <div v-show="currentStep === 4" class="k-step" :class="{ 'active': currentStep === 4 }" data-step="4">
                        <div class="k-step-icon" style="background: linear-gradient(135deg, #22c55e1a, #4ade801a)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div class="k-step-title">بیمه پایه</div>
                        <div class="k-step-hint">در صورت نداشتن بیمه، «ندارم» را بزنید</div>
                        <div class="k-insurance-options">
                            <button
                                v-for="opt in [
                                    { val: 'tamin', label: 'تأمین اجتماعی' },
                                    { val: 'servant', label: 'خدمات درمان' },
                                    { val: 'nirou', label: 'نیروهای مسلح' },
                                    { val: 'none', label: 'ندارم' }
                                ]"
                                :key="opt.val"
                                type="button"
                                class="k-insurance-opt"
                                :class="{ 'selected': insuranceBase === opt.val }"
                                :data-val="opt.val"
                                @click="selectInsurance(opt.val)"
                            >{{ opt.label }}</button>
                        </div>
                        <div class="k-step-error" :class="{ 'visible': stepError && currentStep === 4 }">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ stepError }}</span>
                        </div>
                    </div>

                    <div v-show="currentStep === 5" class="k-step" :class="{ 'active': currentStep === 5 }" data-step="5">
                        <div class="k-step-icon" style="background: linear-gradient(135deg, #ec48991a, #f472b61a)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#ec4899" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                        </div>
                        <div class="k-step-title">بیمه تکمیلی</div>
                        <div class="k-step-hint">نام شرکت بیمه تکمیلی خود را وارد کنید</div>
                        <input v-model="insuranceExtra" id="pf-insurance-extra" type="text" class="k-step-input" placeholder="اختیاری — نام شرکت بیمه" data-kb="persian" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                    </div>
                </div>

                <div class="k-patient-footer">
                    <button type="button" class="k-patient-back" :class="{ 'hidden': currentStep === 0 }" id="btnBack" @click="prevStep">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" class="k-patient-skip" v-if="currentStep === 4" id="btnSkip" @click="nextStep" style="display:none">رد شدن</button>
                    <button type="button" class="k-patient-next" id="btnNext" @click="nextStep">
                        <span id="btnNextText">{{ currentStep === totalSteps - 1 ? 'دریافت نوبت' : 'مرحله بعد' }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="k-keyboard" :class="{ 'visible': showKeyboard }" id="virtualKeyboard">
            <div class="k-kb-toolbar">
                <span class="k-kb-toolbar-label" id="kbLabel">{{ activeKbInput?.dataset?.kb === 'number' ? 'ورود عدد' : 'ورود متن فارسی' }}</span>
                <button type="button" class="k-kb-close" @click="closeKeyboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div id="kbBody">
                <template v-if="activeKbInput?.dataset?.kb === 'number'">
                    <div class="k-kb-num">
                        <button v-for="k in ['۱','۲','۳','۴','۵','۶','۷','۸','۹','۰']" :key="k" type="button" class="k-kb-key" @click="kbType(k)">{{ k }}</button>
                        <button type="button" class="k-kb-key k-kb-key--wide" style="grid-column:3" @click="kbBackspace">پاک کردن</button>
                    </div>
                </template>
                <template v-else>
                    <div v-for="(row, r) in kbRows" :key="r" class="k-kb-row">
                        <button v-for="ch in row" :key="ch" type="button" class="k-kb-key" @click="kbType(ch)">{{ ch }}</button>
                    </div>
                    <div class="k-kb-row">
                        <button type="button" class="k-kb-key k-kb-key--wide" :class="{ 'k-kb-key--shift-active': kbShift }" @click="kbShiftToggle">CBS</button>
                        <button type="button" class="k-kb-key k-kb-key--space" @click="kbType(' ')">فاصله</button>
                        <button type="button" class="k-kb-key k-kb-key--wide" @click="kbBackspace">پاک کردن</button>
                    </div>
                </template>
            </div>
        </div>

        <div class="k-edit-overlay" :class="{ 'visible': showEdit }" id="editOverlay">
            <div class="k-edit-card">
                <div class="k-edit-header">
                    <div class="k-edit-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </div>
                    <div class="k-edit-header-text">
                        <h2>اصلاح پذیرش</h2>
                        <p>شماره نوبت خود را وارد کنید تا اطلاعاتتان را اصلاح کنید</p>
                    </div>
                    <button type="button" class="k-choice-close" @click="closeEdit" aria-label="بستن">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <div class="k-edit-body">
                    <div v-if="!editTicketData" class="k-edit-step" id="editStep1">
                        <div class="k-edit-field" style="text-align:center">
                            <label for="editTicketNumber" style="font-size:14px;color:var(--k-text-dim);margin-bottom:4px">شماره نوبت خود را وارد کنید</label>
                            <input id="editTicketNumber" v-model="editTicketNumber" type="text" placeholder="مثال: ۱۲" data-kb="number" readonly style="text-align:center;font-size:22px;height:56px;border-radius:14px" @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        </div>
                        <div class="k-edit-error" :class="{ 'visible': editError1 }" id="editError1">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ editError1 }}</span>
                        </div>
                        <button type="button" class="k-edit-submit" style="margin-top:4px" @click="fetchTicketForEdit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span>جستجو</span>
                        </button>
                    </div>

                    <div v-else class="k-edit-step" id="editStep2">
                        <div style="width:100%;display:flex;align-items:center;gap:8px;margin-bottom:4px">
                            <div style="width:32px;height:32px;border-radius:8px;background:rgba(139,92,246,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <span style="font-size:14px;font-weight:700;color:var(--k-text)">اطلاعات بیمار</span>
                        </div>
                        <div class="k-edit-field">
                            <label for="editName">نام و نام خانوادگی</label>
                            <input id="editName" type="text" class="k-step-input" :value="editTicketData.patient_name || ''" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        </div>
                        <div class="k-edit-field-row">
                            <div class="k-edit-field">
                                <label for="editAge">سن</label>
                                <input id="editAge" type="text" class="k-step-input" :value="editTicketData.patient_age || ''" data-kb="number" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                            </div>
                            <div class="k-edit-field">
                                <label for="editNationalId">شماره ملی</label>
                                <input id="editNationalId" type="text" class="k-step-input" :value="editTicketData.patient_national_id || ''" data-kb="number" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                            </div>
                        </div>
                        <div class="k-edit-field">
                            <label for="editPhone">شماره تماس</label>
                            <input id="editPhone" type="text" class="k-step-input" :value="editTicketData.patient_phone || ''" data-kb="number" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        </div>
                        <div style="width:100%;display:flex;align-items:center;gap:8px;margin:6px 0 2px">
                            <div style="width:32px;height:32px;border-radius:8px;background:rgba(14,165,233,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            </div>
                            <span style="font-size:14px;font-weight:700;color:var(--k-text)">بیمه</span>
                        </div>
                        <div class="k-edit-field">
                            <label for="editInsuranceBase">بیمه پایه</label>
                            <input id="editInsuranceBase" type="text" class="k-step-input" :value="editTicketData.insurance_base || ''" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        </div>
                        <div class="k-edit-field">
                            <label for="editInsuranceExtra">بیمه تکمیلی</label>
                            <input id="editInsuranceExtra" type="text" class="k-step-input" :value="editTicketData.insurance_extra || ''" readonly @focus="openKeyboard($event.target)" @click="openKeyboard($event.target)">
                        </div>
                        <div class="k-edit-error" :class="{ 'visible': editError2 }" id="editError2">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>{{ editError2 }}</span>
                        </div>
                        <div class="k-edit-success" :class="{ 'visible': editSuccess }" id="editSuccess">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>اطلاعات با موفقیت اصلاح شد!</span>
                        </div>
                        <button type="button" class="k-edit-submit" style="margin-top:8px" @click="submitEditTicket">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>ذخیره اصلاحات</span>
                        </button>
                        <div class="k-edit-divider">یا</div>
                        <button type="button" class="k-edit-cancel" @click="resetEditToStep1">
                            <span>جستجوی شماره نوبت دیگر</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
