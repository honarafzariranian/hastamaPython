/* ── Output escaping helpers (security hardening) ──────────────────────────
   Values rendered into innerHTML come from the database: usernames, names,
   departments, ticket subjects, leave reasons.  Registration and profile input
   already rejects markup server-side, but legacy rows and defence-in-depth
   require escaping at the sink as well.
     esc(v)   — HTML text/attribute context
     jsStr(v) — value placed inside a JavaScript string inside an HTML attribute
                (e.g. onclick="fn('...')"); JSON encoding + HTML escaping keeps
                quotes and backslashes inert instead of terminating the string.
   ------------------------------------------------------------------------- */
function esc(v) {
    return String(v === null || v === undefined ? '' : v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/`/g, '&#96;')
        .replace(/=/g, '&#61;');
}
function jsStr(v) {
    return esc(JSON.stringify(String(v === null || v === undefined ? '' : v)).slice(1, -1));
}
// سازنده‌ی کوچک عناصر برای کارت‌های موبایل و پیام‌های پویا.
function el(tag, className, text) {
    var element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined && text !== null) element.textContent = text;
    return element;
}

// تنظیم پلیس هولدر برای فیلدهای تاریخ// تنظیم پلیس هولدر برای فیلدهای تاریخ// تنظیم پلیس هولدر برای فیلدهای تاریخ
// تنظیم پلیس هولدر برای فیلدهای تاریخ// تنظیم پلیس هولدر برای فیلدهای تاریخ// تنظیم پلیس هولدر برای فیلدهای تاریخ
// تنظیم پلیس هولدر برای فیلدهای تاریخ// تنظیم پلیس هولدر برای فیلدهای تاریخ// تنظیم پلیس هولدر برای فیلدهای تاریخ

document.getElementById('fromDate').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('toDate').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('start_date').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('end_date').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById("start_date").addEventListener("input", convertInputToPersian);
document.getElementById("end_date").addEventListener("input", convertInputToPersian);
document.getElementById('start_date_hourlypass').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('end_date_hourlypass').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('shanbeh').placeholder = convertToPersianNumbers('12:00 - 24:00');
document.getElementById('yekshanbeh').placeholder = convertToPersianNumbers('12:00 - 24:00');
document.getElementById('doshanbeh').placeholder = convertToPersianNumbers('12:00 - 24:00');
document.getElementById('seshanbeh').placeholder = convertToPersianNumbers('12:00 - 24:00');
document.getElementById('chrshanbeh').placeholder = convertToPersianNumbers('12:00 - 24:00');
document.getElementById('panjshanbeh').placeholder = convertToPersianNumbers('12:00 - 24:00');
document.getElementById('start_date_hozoor').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('end_date_hozoor').placeholder = convertToPersianNumbers('1404/01/01');
document.getElementById('newhozoorNum').placeholder = convertToPersianNumbers('باید 8 رقمی وارد کنید');
document.getElementById("start_date_hozoor").addEventListener("input", convertInputToPersian);
document.getElementById("end_date_hozoor").addEventListener("input", convertInputToPersian);

// فارسی شدن ساعت کاری در باکس ایجاد کاربر جدید
document.addEventListener("DOMContentLoaded", function() {
    var options = document.querySelectorAll("select option");
    options.forEach(function(option) {
        option.textContent = convertToPersianNumbers(option.textContent);
    });
});

// تابع برای تبدیل اعداد انگلیسی به فارسی
function convertToPersianNumbers(number) {
    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return String(number).replace(/\d/g, (digit) => persianNumbers[digit]);
}

function updateTopbarClock() {
    const dateEl = document.getElementById('adminTopbarDateText');
    const timeEl = document.getElementById('adminTopbarTimeText');
    if (!dateEl || !timeEl) return;

    const now = new Date();
    const persianDate = new Intl.DateTimeFormat('fa-IR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(now);
    const persianTime = new Intl.DateTimeFormat('fa-IR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    }).format(now);

    /* فقط وقتی محتوا تغییر کرده باشد DOM را لمس کن — جلوگیری از layout-shift مداوم */
    var dateText = convertToPersianNumbers(persianDate);
    var timeText = convertToPersianNumbers(persianTime);
    if (dateEl.textContent !== dateText) dateEl.textContent = dateText;
    if (timeEl.textContent !== timeText) timeEl.textContent = timeText;
}

function renderDashboardBarHeights() {
    document.querySelectorAll('.dashboard-chart .bar-value').forEach(bar => {
        const percent = parseInt(bar.dataset.percent, 10);
        if (!isNaN(percent)) {
            bar.style.height = `${percent}%`;
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    updateTopbarClock();
    renderDashboardBarHeights();

    document.querySelectorAll('[data-panel]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
            openProfilePanel(this.getAttribute('data-panel'));
        });
    });

    const overlay = document.getElementById('profilePanelOverlay');
    if (overlay) {
        overlay.addEventListener('click', closeProfilePanel);
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeProfilePanel();
            closeProfileDropdown();
            closeMobileSidebar();
        }
    });

    /* بستن دراپ‌داون پروفایل با کلیک بیرون */
    document.addEventListener('click', function(event) {
        var dropdown = document.getElementById('profileDropdown');
        if (dropdown && dropdown.classList.contains('open')) {
            if (!dropdown.contains(event.target) && !event.target.closest('[onclick*="toggleProfileDropdown"]')) {
                closeProfileDropdown();
            }
        }
    });
});
setInterval(updateTopbarClock, 30000);

// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع
// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع
// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع// فارسی کردن اعداد جدول گزارش کلی افراد به صورت جامع

function convertToPersian() {
    const elements = document.querySelectorAll('.overTime-allreport-table td');
    elements.forEach(function(element) {
        element.innerHTML = element.innerHTML.replace(/\d+/g, function(match) {
            return match.split('').map(function(digit) {
                return '۰۱۲۳۴۵۶۷۸۹'.charAt(digit);
            }).join('');
        });
    });
}

// addEventListener به‌جای window.onload — تا اسکریپت‌های تزریق‌شدهٔ شخص‌ثالث
// نتوانند با بازنویسی window.onload این هندلر را غیرفعال کنند.
window.addEventListener('load', function() {
    convertToPersian();
});

// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر
// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر
// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر// بستن پیام با کلیک روی ضربدر

function closeSuccessMessage() {
    var message = document.getElementById('successMessage');
    if (!message) return;
    message.classList.remove('show');
    message.classList.add('is-closing');
    setTimeout(function() {
        message.hidden = true;
        message.classList.remove('is-closing');
    }, 450);
}

// ─── SPA URL Routing ──────────────────────────────────────────────
var SECTION_URLS = {
    'dashboardBox':  '/admin/dashboard',
    'coworkerBox':   '/admin/coworkers',
    'vacationBox':   '/admin/vacation',
    'overtimeBox':   '/admin/overtime',
    'hourlyPassBox': '/admin/hourly-pass',
    'ticketBox':     '/admin/tickets',
    'internalAutomationAdminBox': '/admin/internal-automation',
    'shiftBox':      '/admin/shifts',
    'hozoorbox':     '/admin/attendance',
    'payrollBox':    '/admin/payroll',
    // regRequestsBox is now a tab inside coworkerBox
};
var URL_TO_SECTION = {};
for (var k in SECTION_URLS) { URL_TO_SECTION[SECTION_URLS[k]] = k; }

function navTo(boxId, el, url) {
    toggleBox(boxId, el);
    if (url && window.history && window.history.pushState) {
        window.history.pushState({box: boxId}, '', url);
    }
}
window.addEventListener('popstate', function(e) {
    var section = e.state && e.state.box ? e.state.box : URL_TO_SECTION[window.location.pathname];
    if (section) {
        var el = document.querySelector('.icon-container[onclick*="' + section + '"]');
        toggleBox(section, el);
    }
});
// On page load, read URL and show correct section
(function() {
    var section = URL_TO_SECTION[window.location.pathname];
    if (section) {
        var el = document.querySelector('.icon-container[onclick*="' + section + '"]');
        toggleBox(section, el);
        window.history.replaceState({box: section}, '', window.location.pathname);
    }
})();

// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها
// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها
// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها// تابع نمایش باکس ها

function toggleBox(boxId, iconContainer) {
    // تغییر آیکون‌های فعال به حالت پیش‌فرض
    const sidebarIcons = document.querySelectorAll('.sidebar-icon');
    sidebarIcons.forEach(icon => {
        const defaultSrc = icon.getAttribute('data-default-src');
        if (defaultSrc) {
            icon.src = defaultSrc;
        }
    });

    // حذف کلاس فعال از تمام آیتم‌های سایدبار
    const sidebarItems = document.querySelectorAll('.icon-container');
    sidebarItems.forEach(item => item.classList.remove('active'));

    // فعال کردن آیکون و کلاس انتخاب شده
    if (iconContainer) {
        const icon = iconContainer.querySelector('.sidebar-icon');
        if (icon) {
            const activeSrc = icon.getAttribute('data-active-src');
            if (activeSrc) {
                icon.src = activeSrc;
            }
        }
        iconContainer.classList.add('active');
    }

    // پنهان کردن تمام باکس‌ها
    const boxes = document.querySelectorAll('.management-box');
    boxes.forEach(box => {
        box.style.display = 'none';
        box.classList.remove('is-visible');
    });

    // ردیف خلاصه/ثبت دستی حضور و غیاب، خودش management-box نیست؛
    // بنابراین باید جداگانه مخفی شود تا در بخش‌هایی مثل مدیریت شیفت‌ها دیده نشود.
    const attendancePanelsRow = document.getElementById('attendancePanelsRow');
    if (attendancePanelsRow) {
        attendancePanelsRow.style.display = 'none';
        attendancePanelsRow.classList.remove('is-visible');
    }

    // نمایش باکس انتخابی
    const selectedBox = document.getElementById(boxId);
    if (selectedBox) {
        selectedBox.classList.add('is-visible');
        if (boxId === 'payrollBox') {
            selectedBox.style.display = 'block';
        } else {
            selectedBox.style.display = 'flex';
        }
    }

    // اگر مدیریت تیکت‌ها انتخاب شده باشد، باکس زیر را نمایش دهید
    if (boxId === 'ticketBox') {
        const ticketBox = document.getElementById('ticketBox');
        if (ticketBox) {
            ticketBox.style.display = 'flex';
            // مرکز جدید تیکت‌ها فقط از API نرمال‌شده استفاده می‌کند.
            if (window.TicketingWorkspace) window.TicketingWorkspace.loadAdmin(1);
        }

        if (boxId === 'internalAutomationAdminBox' && window.InternalAutomationAdmin) {
            window.InternalAutomationAdmin.load();
        }
    }

    if (boxId === 'hozoorbox') {
        const hozoorbox = document.getElementById('hozoorbox');
        if (hozoorbox) {
            hozoorbox.style.display = 'block';
        }

        const attendancePanelsRow = document.getElementById('attendancePanelsRow');
        if (attendancePanelsRow) {
            attendancePanelsRow.classList.add('is-visible');
            attendancePanelsRow.style.display = 'flex';
        }

        const sabtdst = document.getElementById('sabtdst');
        if (sabtdst) {
            sabtdst.style.display = 'block';
        }

    }

    // coworkerBox اکنون شامل تب‌هاست — نیازی به نمایش جداگانه نیست
}

// تابع سوئیچ تب‌های بخش حقوق و دستمزد
function switchPayrollTab(tabId, btnEl) {
    // غیرفعال کردن تمام دکمه‌ها
    document.querySelectorAll('.payroll-tab-btn').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    // غیرفعال کردن تمام محتواها
    document.querySelectorAll('.payroll-tab-content').forEach(c => c.classList.remove('active'));
    // فعال کردن دکمه انتخاب شده
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    // فعال کردن محتوای مربوطه
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
    if (tabId === 'hourly-payroll-calculation') computeHourlyPayrollTotals();
    if (tabId === 'overtime-calculation') loadOvertimePayrollData(false);
    if (tabId === 'karaneh-calculation') loadKaranehData(false);
}

// تابع سوئیچ تب‌های بخش حضور و غیاب (گزارش / خلاصه و ثبت دستی)
function switchAttendanceTab(tabId, btnEl) {
    document.querySelectorAll('.attendance-tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.attendance-tab-content').forEach(function (c) { c.classList.remove('active'); });
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

// تابع سوئیچ تب‌های بخش مدیریت کارکنان
function switchCoworkerTab(tabId, btnEl) {
    document.querySelectorAll('.coworker-tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.coworker-tab-content').forEach(function (c) { c.classList.remove('active'); });
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
    // بارگذاری درخواست‌های ثبت نام در صورت انتخاب تب مربوطه
    if (tabId === 'cw-reg-requests-tab' && typeof loadRegRequests === 'function') {
        loadRegRequests();
    }
}

// تابع سوئیچ تب‌های بخش مدیریت مرخصی
function switchVacationTab(tabId, btnEl) {
    document.querySelectorAll('.vacation-tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.vacation-tab-content').forEach(function (c) { c.classList.remove('active'); });
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

function switchOvertimeTab(tabId, btnEl) {
    document.querySelectorAll('.overtime-tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.overtime-tab-content').forEach(function (c) { c.classList.remove('active'); });
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

function switchHourlyPassTab(tabId, btnEl) {
    document.querySelectorAll('.hourlyPass-tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.hourlyPass-tab-content').forEach(function (c) { c.classList.remove('active'); });
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

// ===== محاسبه جامع حقوق و دستمزد =====
// فیلدهای پرداختی (مجموع پرداختی را می‌سازند)
// بن کارگری، حق مسکن، حق اولاد و حق تأهل در یک ستون واحد (benefits) تعریف می‌شوند.
var PAYROLL_PAY_FIELDS = ['dailySalary', 'benefits', 'overtime', 'bonus', 'eidiSanavat'];
// فیلدهای کسورات (از مجموع پرداختی کم می‌شوند)
var PAYROLL_DEDUCT_FIELDS = ['leave', 'workDeduction', 'insurance', 'advance'];
// فیلدهای ساعتی/عددی که مجموعشان هم در ردیف پایین نمایش داده می‌شود
var PAYROLL_HOUR_FIELDS = ['workHours'];
var PAYROLL_ALL_FIELDS = PAYROLL_PAY_FIELDS.concat(PAYROLL_DEDUCT_FIELDS, PAYROLL_HOUR_FIELDS);

/** تبدیل ارقام فارسی به انگلیسی (برای محاسبه) */
function persianDigitsToEnglish(str) {
    var fa = '۰۱۲۳۴۵۶۷۸۹';
    return String(str).replace(/[۰-۹]/g, function (d) { return String(fa.indexOf(d)); });
}

function numVal(el) {
    if (!el) return 0;
    var v = parseFloat(persianDigitsToEnglish(el.value).replace(/[،,]/g, ''));
    return isNaN(v) ? 0 : v;
}

function formatPayrollNumber(n) {
    if (n === 0) return '۰';
    var persian = n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '،');
    return convertToPersianDigits(persian);
}

function convertToPersianDigits(str) {
    var map = { '0': '۰', '1': '۱', '2': '۲', '3': '۳', '4': '۴', '5': '۵', '6': '۶', '7': '۷', '8': '۸', '9': '۹' };
    return String(str).replace(/[0-9]/g, function (d) { return map[d]; });
}

/** هنگام تایپ در ورودی‌های عددی، ارقام انگلیسی به فارسی تبدیل می‌شوند */
function toPersianPayrollInput(el) {
    if (!el || el.type === 'number') return;
    var before = el.value;
    var after = convertToPersianDigits(before);
    if (after !== before) el.value = after;
}

/** تعداد کاراکترهای عددی (رقم یا ممیز) قبل از موقعیت داده‌شده — جداکننده‌ها شمارش نمی‌شوند */
function countNumChars(str, pos) {
    var n = 0;
    for (var i = 0; i < pos && i < str.length; i++) {
        if (/[0-9۰-۹.]/.test(str.charAt(i))) n++;
    }
    return n;
}

/** قرار دادن مکان‌نما بعد از n کاراکتر عددی در مقدار جدید (جداکننده‌ها نادیده گرفته می‌شوند) */
function setCaretAfterNumChars(el, count) {
    var v = el.value, pos = 0, seen = 0;
    while (pos < v.length && seen < count) {
        if (/[0-9۰-۹.]/.test(v.charAt(pos))) seen++;
        pos++;
    }
    el.setSelectionRange(pos, pos);
}

/** هنگام تایپ در سلول‌های جدول: ارقام فارسی + جداکننده سه‌رقمی زنده */
function formatPayrollInputValue(el) {
    if (!el) return;
    var raw = el.value;
    var selStart = el.selectionStart != null ? el.selectionStart : raw.length;
    var numBefore = countNumChars(raw, selStart);
    var field = el.getAttribute('data-field') || '';
    var isHours = field === 'workHours';
    var cleaned = raw.replace(/[،,]/g, '');

    if (isHours) {
        /* ساعت کارکرد: فقط ارقام فارسی + ممیز، بدون جداکننده */
        var h = cleaned.replace(/[^0-9۰-۹.]/g, '');
        var dotIdx = h.indexOf('.');
        if (dotIdx !== -1) h = h.slice(0, dotIdx + 2);
        var pers = convertToPersianDigits(h);
        if (pers !== raw) {
            el.value = pers;
            setCaretAfterNumChars(el, numBefore);
        }
        return;
    }

    /* فیلدهای مبلغ: فقط رقم + جداکننده سه‌رقمی (ریال) */
    var digits = cleaned.replace(/[^0-9۰-۹]/g, '');
    if (digits === '') {
        if (raw !== '') el.value = '';
        return;
    }
    var english = persianDigitsToEnglish(digits).replace(/^0+(?=\d)/, '');
    var grouped = english.replace(/\B(?=(\d{3})+(?!\d))/g, '،');
    var formatted = convertToPersianDigits(grouped);
    if (formatted !== raw) {
        el.value = formatted;
        setCaretAfterNumChars(el, numBefore);
    }
}

/** پیدا کردن تمام ورودی‌های یک پرسنل (چه در جدول، چه در کارت موبایل) */
function payrollInputsFor(username) {
    var all = document.querySelectorAll('.payroll-input');
    var out = [];
    for (var i = 0; i < all.length; i++) {
        if (all[i].getAttribute('data-username') === username) out.push(all[i]);
    }
    return out;
}

/** مقدار یک فیلد برای یک پرسنل */
function payrollFieldValue(username, field) {
    var inputs = payrollInputsFor(username);
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].getAttribute('data-field') === field) return numVal(inputs[i]);
    }
    return 0;
}

/** به‌روزرسانی سلول‌های نتیجه (جدول یا کارت) برای یک پرسنل */
function setPayrollResult(username, resultKey, value) {
    var all = document.querySelectorAll('[data-result="' + resultKey + '"][data-username="' + username + '"]');
    for (var i = 0; i < all.length; i++) all[i].textContent = formatPayrollNumber(value);
}

// محاسبه مجموع پرداختی و مانده قابل پرداخت برای یک پرسنل
function computePayrollRow(username) {
    var totalPay = 0;
    PAYROLL_PAY_FIELDS.forEach(function (f) { totalPay += payrollFieldValue(username, f); });
    var totalDeduct = 0;
    PAYROLL_DEDUCT_FIELDS.forEach(function (f) { totalDeduct += payrollFieldValue(username, f); });
    var netPay = Math.max(0, totalPay - totalDeduct);
    setPayrollResult(username, 'totalPay', totalPay);
    setPayrollResult(username, 'netPay', netPay);
    return { totalPay: totalPay, netPay: netPay };
}

// محاسبه مجموع ستون‌ها در ردیف پایانی
function computePayrollTotals() {
    var table = document.getElementById('payrollComprehensiveTable');
    if (!table) return;
    var rows = table.querySelectorAll('tbody .payroll-row');
    var sums = {};
    PAYROLL_ALL_FIELDS.forEach(function (f) { sums[f] = 0; });
    var grandTotalPay = 0;
    var grandNetPay = 0;
    rows.forEach(function (row) {
        var username = row.getAttribute('data-username');
        PAYROLL_ALL_FIELDS.forEach(function (f) {
            sums[f] += payrollFieldValue(username, f);
        });
        var res = computePayrollRow(username);
        grandTotalPay += res.totalPay;
        grandNetPay += res.netPay;
    });
    PAYROLL_ALL_FIELDS.forEach(function (f) {
        var cell = table.querySelector('tfoot [data-total="' + f + '"]');
        if (cell) cell.textContent = formatPayrollNumber(sums[f]);
    });
    var totalPayCell = table.querySelector('tfoot [data-total="totalPay"]');
    var netPayCell = table.querySelector('tfoot [data-total="netPay"]');
    if (totalPayCell) totalPayCell.textContent = formatPayrollNumber(grandTotalPay);
    if (netPayCell) netPayCell.textContent = formatPayrollNumber(grandNetPay);
    var mobileNet = document.getElementById('payrollMobileNetTotal');
    if (mobileNet) mobileNet.textContent = formatPayrollNumber(grandNetPay);
    updatePayrollSummary();
}

// ===== نمای موبایل جدول محاسبه جامع (کارت به ازای هر پرسنل) =====
// برخلاف الگوی عمومی cards، این جدول همه‌جا ورودی دارد؛ پس ورودی‌ها به کارت‌ها
// «منتقل» می‌شوند تا رویدادها و مقادیر حفظ شوند (همان اصل adopt در responsive-tables).
var PAYROLL_MOBILE_BP = 768;
var payrollMql = window.matchMedia ? window.matchMedia('(max-width: ' + PAYROLL_MOBILE_BP + 'px)') : null;
var payrollMobileActive = false;
var payrollMobileCards = null;   // کانتینر کارت‌ها
var payrollAdoptions = [];       // { td, input } برای بازگرداندن ورودی‌ها به جدول

function payrollFieldLabel(field) {
    var table = document.getElementById('payrollComprehensiveTable');
    if (!table) return field;
    var th = table.querySelector('thead .payroll-table-subhead th[data-field="' + field + '"]');
    if (!th) return field;
    var title = th.querySelector('.payroll-column-title');
    return title ? title.textContent : th.textContent;
}

function buildPayrollMobileCards() {
    var table = document.getElementById('payrollComprehensiveTable');
    if (!table || payrollMobileCards) return;

    var content = table.closest('.payroll-tab-content');
    var scrollWrap = table.closest('.payroll-table-scroll');
    if (!content) return;

    var container = document.createElement('div');
    container.className = 'payroll-mobile-cards';
    content.insertBefore(container, scrollWrap ? scrollWrap.nextSibling : null);
    payrollMobileCards = container;

    var rows = table.querySelectorAll('tbody .payroll-row');
    rows.forEach(function (row) {
        var username = row.getAttribute('data-username');
        var nameEl = row.querySelector('.payroll-person-name');
        var metaEl = row.querySelector('.payroll-person-meta');
        var name = nameEl ? nameEl.textContent : username;
        var meta = metaEl ? metaEl.textContent : '';

        var card = document.createElement('article');
        card.className = 'payroll-card';
        card.setAttribute('data-username', username);

        var head = document.createElement('header');
        head.className = 'payroll-card__head';
        var title = document.createElement('div');
        title.className = 'payroll-card__title';
        title.appendChild(el('strong', 'payroll-card__name', name));
        if (meta) title.appendChild(el('span', 'payroll-card__meta', meta));
        head.appendChild(title);
        card.appendChild(head);

        var fields = document.createElement('div');
        fields.className = 'payroll-card__fields';
        PAYROLL_ALL_FIELDS.forEach(function (field) {
            var input = row.querySelector('.payroll-input[data-field="' + field + '"]');
            if (!input) return;
            var label = document.createElement('label');
            label.className = 'payroll-card__field' + (field === 'workHours' ? ' is-hours' : '');
            label.appendChild(el('span', 'payroll-card__field-label', payrollFieldLabel(field)));
            /* انتقال ورودی از سلول جدول به کارت — مقادیر/رویدادها حفظ می‌شوند */
            var td = input.parentNode;
            label.appendChild(input);
            fields.appendChild(label);
            payrollAdoptions.push({ td: td, input: input });
        });
        card.appendChild(fields);

        var results = document.createElement('div');
        results.className = 'payroll-card__results';
        results.appendChild(el('div', 'payroll-card__result', 'مجموع پرداختی: <b data-result="totalPay" data-username="' + username + '">—</b>'));
        results.appendChild(el('div', 'payroll-card__result is-net', 'مانده قابل پرداخت: <b data-result="netPay" data-username="' + username + '">—</b>'));
        card.appendChild(results);

        container.appendChild(card);
    });

    /* ردیف مجموع کل هم در موبایل نمایش داده شود */
    var totalRow = table.querySelector('tfoot .payroll-total-row');
    if (totalRow) {
        var netCell = totalRow.querySelector('[data-total="netPay"]');
        var sumBox = document.createElement('div');
        sumBox.className = 'payroll-mobile-total';
        sumBox.appendChild(el('span', 'payroll-mobile-total__label', 'مجموع کل مانده قابل پرداخت:'));
        var sumVal = document.createElement('b');
        sumVal.className = 'payroll-mobile-total__value';
        sumVal.id = 'payrollMobileNetTotal';
        sumVal.textContent = netCell ? netCell.textContent : '—';
        sumBox.appendChild(sumVal);
        container.appendChild(sumBox);
    }
}

function restorePayrollMobileCards() {
    payrollAdoptions.forEach(function (a) {
        if (a.input && a.td) a.td.appendChild(a.input);
    });
    payrollAdoptions = [];
    if (payrollMobileCards) {
        payrollMobileCards.remove();
        payrollMobileCards = null;
    }
}

function applyPayrollMobile(on) {
    if (on === payrollMobileActive) return;
    payrollMobileActive = on;
    if (on) {
        buildPayrollMobileCards();
        computePayrollTotals();
    } else {
        restorePayrollMobileCards();
    }
}

// ===== محاسبه اضافه‌کاری و کسری‌کاری ماهانه =====
var overtimePayrollCache = {};
var overtimePayrollLoading = false;

function overtimePayrollParseHours(value) {
    if (typeof value === 'number') return value;
    var text = persianDigitsToEnglish(String(value || '')).replace(/[،,]/g, '').trim();
    var parts = text.split(':');
    if (parts.length >= 2) {
        var h = parseFloat(parts[0]) || 0;
        var m = parseFloat(parts[1]) || 0;
        return h + (m / 60);
    }
    var numeric = parseFloat(text);
    return isNaN(numeric) ? 0 : numeric;
}

function overtimePayrollFormatHours(hours) {
    var sign = hours < 0 ? '-' : '';
    var absolute = Math.abs(Number(hours) || 0);
    var totalMinutes = Math.round(absolute * 60);
    return convertToPersianDigits(sign + Math.floor(totalMinutes / 60) + ':' + String(totalMinutes % 60).padStart(2, '0'));
}

function overtimePayrollFormatSignedMinutes(minutes) {
    var sign = minutes < 0 ? '-' : '';
    var absolute = Math.abs(Math.round(minutes || 0));
    return convertToPersianDigits(sign + Math.floor(absolute / 60) + ':' + String(absolute % 60).padStart(2, '0'));
}

function overtimePayrollInputValue(row, field) {
    var input = row.querySelector('.overtime-payroll-input[data-overtime-field="' + field + '"]');
    return input ? numVal(input) : 0;
}

function formatOvertimePayrollInputValue(input) {
    if (!input) return;
    var raw = input.value;
    var caret = input.selectionStart == null ? raw.length : input.selectionStart;
    var before = countNumChars(raw, caret);
    var digits = raw.replace(/[،,]/g, '').replace(/[^0-9۰-۹]/g, '');
    if (!digits) {
        if (raw) input.value = '';
        return;
    }
    var english = persianDigitsToEnglish(digits).replace(/^0+(?=\d)/, '');
    var grouped = english.replace(/\B(?=(\d{3})+(?!\d))/g, '،');
    var value = convertToPersianDigits(grouped);
    if (value !== raw) {
        input.value = value;
        setCaretAfterNumChars(input, before);
    }
}

function overtimePayrollSet(row, key, value) {
    var cell = row.querySelector('[data-overtime-result="' + key + '"]');
    if (cell) cell.textContent = value;
}

function computeOvertimePayrollRow(row) {
    var username = row.getAttribute('data-username');
    var data = overtimePayrollCache[username] || { workedHours: 0, status: 'بدون داده' };
    var minuteRate = overtimePayrollInputValue(row, 'minuteRate');
    var hourRate = overtimePayrollInputValue(row, 'hourRate');
    var workedHours = Number(data.workedHours) || 0;
    var mandatoryHours = overtimePayrollGetMandatoryHours();
    var differenceMinutes = Math.round((workedHours - mandatoryHours) * 60);
    var overtimeMinutes = Math.max(0, differenceMinutes);
    var deficitMinutes = Math.max(0, -differenceMinutes);
    var allowedDeficitMinutes = 10 * 60;
    var excessDeficitMinutes = Math.max(0, deficitMinutes - allowedDeficitMinutes);

    /* کسری بیش از ۱۰ ساعت ابتدا از اضافه‌کاری جبران می‌شود. */
    var compensatedOvertimeMinutes = Math.max(0, overtimeMinutes - excessDeficitMinutes);
    var remainingExcessDeficitMinutes = Math.max(0, excessDeficitMinutes - overtimeMinutes);
    var overtimeHoursPart = Math.floor(compensatedOvertimeMinutes / 60);
    var overtimeMinutePart = compensatedOvertimeMinutes % 60;
    var deficitHoursPart = Math.floor(remainingExcessDeficitMinutes / 60);
    var deficitMinutePart = remainingExcessDeficitMinutes % 60;
    var overtimeBaseCost = (overtimeHoursPart * hourRate) + (overtimeMinutePart * minuteRate);
    var deficitCost = (deficitHoursPart * hourRate) + (deficitMinutePart * minuteRate);
    var premium = overtimeBaseCost * 0.4;
    var totalCost = overtimeBaseCost + premium - deficitCost;
    var finalWorkHours = workedHours - (remainingExcessDeficitMinutes / 60);
    var status = differenceMinutes > 0 ? 'اضافه‌کاری' : differenceMinutes < 0 ? 'کسری‌کاری' : 'مطابق موظفی';

    overtimePayrollSet(row, 'signedMinutes', overtimePayrollFormatSignedMinutes(differenceMinutes));
    overtimePayrollSet(row, 'signedHours', overtimePayrollFormatHours(differenceMinutes / 60));
    overtimePayrollSet(row, 'totalCost', formatPayrollNumber(totalCost));
    overtimePayrollSet(row, 'status', status);
    overtimePayrollSet(row, 'workedHours', overtimePayrollFormatHours(workedHours));
    overtimePayrollSet(row, 'mandatoryHours', overtimePayrollFormatHours(mandatoryHours));
    overtimePayrollSet(row, 'difference', overtimePayrollFormatHours(differenceMinutes / 60));
    overtimePayrollSet(row, 'premium', formatPayrollNumber(premium));
    overtimePayrollSet(row, 'finalWorkHours', overtimePayrollFormatHours(finalWorkHours));
    overtimePayrollSet(row, 'finalOvertimeHours', overtimePayrollFormatHours(compensatedOvertimeMinutes / 60));

    return {
        signedMinutes: differenceMinutes,
        totalCost: totalCost,
        workedHours: workedHours,
        mandatoryHours: mandatoryHours,
        premium: premium,
        finalWorkHours: finalWorkHours,
        finalOvertimeHours: compensatedOvertimeMinutes / 60
    };
}

function overtimePayrollGetMandatoryHours() {
    var input = document.getElementById('overtimePayrollMandatoryHours') || document.getElementById('payrollMandatoryHours');
    var value = numVal(input);
    return value > 0 ? value : 176;
}

function overtimePayrollSetTotals(results) {
    var totals = { signedMinutes: 0, totalCost: 0, workedHours: 0, mandatoryHours: 0, difference: 0, premium: 0, finalWorkHours: 0, finalOvertimeHours: 0 };
    results.forEach(function (result) {
        Object.keys(totals).forEach(function (key) { totals[key] += result[key] || 0; });
    });
    var table = document.getElementById('overtimePayrollTable');
    if (!table) return;
    Object.keys(totals).forEach(function (key) {
        var cell = table.querySelector('[data-overtime-total="' + key + '"]');
        if (!cell) return;
        if (key === 'totalCost' || key === 'premium') cell.textContent = formatPayrollNumber(totals[key]);
        else if (key === 'signedMinutes') cell.textContent = overtimePayrollFormatSignedMinutes(totals[key]);
        else cell.textContent = overtimePayrollFormatHours(totals[key]);
    });
}

function overtimePayrollMonthRange() {
    var monthSelect = document.getElementById('overtimePayrollMonth') || document.getElementById('payrollMonth');
    var yearInput = document.getElementById('overtimePayrollYear') || document.getElementById('payrollYear');
    var month = monthSelect ? monthSelect.selectedIndex + 1 : 1;
    var year = parseInt(persianDigitsToEnglish((yearInput && yearInput.value) || '1405'), 10) || 1405;
    var days = typeof daysInJMonth === 'function' ? daysInJMonth(year, month) : (month <= 6 ? 31 : month <= 11 ? 30 : 29);
    function pad(value) { return String(value).padStart(2, '0'); }
    return { start: year + '/' + pad(month) + '/01', end: year + '/' + pad(month) + '/' + pad(days) };
}

function overtimePayrollRenderLoading() {
    document.querySelectorAll('#overtimePayrollTable tbody .overtime-payroll-row').forEach(function (row) {
        overtimePayrollSet(row, 'status', 'در حال دریافت…');
    });
}

async function loadOvertimePayrollData(force) {
    if (overtimePayrollLoading && !force) return;
    overtimePayrollLoading = true;
    overtimePayrollRenderLoading();
    /* Reset rate inputs to zero for new period */
    document.querySelectorAll('#overtimePayrollTable tbody .overtime-payroll-input').forEach(function (inp) { inp.value = '۰'; });
    var range = overtimePayrollMonthRange();
    var rows = Array.prototype.slice.call(document.querySelectorAll('#overtimePayrollTable tbody .overtime-payroll-row'));
    try {
        await Promise.all(rows.map(async function (row) {
            var username = row.getAttribute('data-username');
            try {
                var response = await fetch('/get_hozoor/' + encodeURIComponent(username) + '?start_date=' + encodeURIComponent(range.start) + '&end_date=' + encodeURIComponent(range.end), { credentials: 'same-origin' });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                var payload = await response.json();
                var records = Array.isArray(payload) ? payload : [];
                var workedHours = records.reduce(function (sum, item) { return sum + overtimePayrollParseHours(item.WorkedHours); }, 0);
                var status = records.some(function (item) { return String(item.Status || '').indexOf('اضافه') !== -1; }) ? 'اضافه‌کاری' : records.some(function (item) { return String(item.Status || '').indexOf('خروج') !== -1 || String(item.Status || '').indexOf('تاخیر') !== -1; }) ? 'کسری‌کاری' : 'مطابق موظفی';
                overtimePayrollCache[username] = { workedHours: workedHours, status: status, records: records };
            } catch (error) {
                overtimePayrollCache[username] = { workedHours: 0, status: 'خطا در دریافت' };
            }
        }));
        var results = rows.map(computeOvertimePayrollRow);
        overtimePayrollSetTotals(results);
        /* Restore saved rates AFTER fresh attendance data is loaded */
        await loadSavedPayrollChanges('overtime');
    } finally {
        overtimePayrollLoading = false;
    }
}

function payrollCurrentPeriod(type) {
    var yearInput = (type === 'overtime' ? document.getElementById('overtimePayrollYear') : null) || document.getElementById('payrollYear');
    var monthSelect = (type === 'overtime' ? document.getElementById('overtimePayrollMonth') : null) || document.getElementById('payrollMonth');
    var yearText = persianDigitsToEnglish((yearInput && yearInput.value) || '').replace(/[^0-9]/g, '');
    var year = parseInt(yearText, 10);
    var month = monthSelect ? (monthSelect.value || String(monthSelect.selectedIndex + 1)) : 'فروردین';
    return { year: year, month: month };
}

var PAYROLL_MONTH_NAMES = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function payrollPreviousPeriod(period) {
    var monthIndex = PAYROLL_MONTH_NAMES.indexOf(period.month);
    if (monthIndex < 0) return null;
    return {
        year: monthIndex === 0 ? period.year - 1 : period.year,
        month: PAYROLL_MONTH_NAMES[monthIndex === 0 ? 11 : monthIndex - 1]
    };
}

function payrollInputForField(username, field) {
    var inputs = payrollInputsFor(username);
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].getAttribute('data-field') === field) return inputs[i];
    }
    return null;
}

/** انتقال یک ستون از دوره‌ی قبلی؛ سایر ستون‌های ماه جاری دست‌نخورده می‌مانند. */
async function copyComprehensiveColumnFromPrevious(field, button) {
    if (PAYROLL_ALL_FIELDS.indexOf(field) === -1) return;

    var period = payrollCurrentPeriod();
    var previous = payrollPreviousPeriod(period);
    if (!period.year || period.year <= 1300 || !previous) {
        payrollPeriodStatus('برای این دوره، ماه قبل معتبر نیست.', true);
        return;
    }
    if (button && button.disabled) return;

    var originalText = button ? button.textContent : '';
    if (button) {
        button.disabled = true;
        button.textContent = 'در حال دریافت…';
    }
    payrollPeriodStatus('در حال دریافت مقدار ستون از ' + previous.month + ' ' + convertToPersianDigits(String(previous.year)) + '…', false);

    try {
        var response = await fetch('/api/admin/payroll/load?calculation_type=comprehensive&period_year=' + encodeURIComponent(previous.year) + '&period_month=' + encodeURIComponent(previous.month), { credentials: 'same-origin' });
        var data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'بازیابی ماه قبل ناموفق بود.');

        var previousRows = Object.create(null);
        (data.items || []).forEach(function (item) {
            if (!item || !item.payload) return;
            previousRows[String(item.username || '').trim()] = item.payload;
        });

        var applied = 0;
        document.querySelectorAll('#payrollComprehensiveTable tbody .payroll-row').forEach(function (row) {
            var username = String(row.getAttribute('data-username') || '').trim();
            var payload = previousRows[username];
            if (!payload || !Object.prototype.hasOwnProperty.call(payload, field)) return;
            var input = payrollInputForField(row.getAttribute('data-username') || username, field);
            if (!input) return;
            input.value = payload[field] === null || payload[field] === undefined ? '' : String(payload[field]);
            formatPayrollInputValue(input);
            applied++;
        });

        computePayrollTotals();
        if (applied) {
            payrollPeriodStatus('مقدار ستون از ' + previous.month + ' ' + convertToPersianDigits(String(previous.year)) + ' برای ' + convertToPersianDigits(String(applied)) + ' ردیف اعمال شد؛ برای ثبت دائمی «ذخیره تغییرات» را بزنید.', false);
        } else {
            payrollPeriodStatus('برای ستون انتخاب‌شده در ماه قبل داده‌ای پیدا نشد.', true);
        }
    } catch (error) {
        payrollPeriodStatus(error.message || 'خطا در دریافت اطلاعات ماه قبل.', true);
    } finally {
        if (button) {
            button.disabled = false;
            button.textContent = originalText;
        }
    }
}

function payrollPeriodConfig(type) {
    var workDays = (type === 'overtime' ? document.getElementById('overtimePayrollWorkDays') : null) || document.getElementById('payrollWorkDays');
    var mandatoryHours = (type === 'overtime' ? document.getElementById('overtimePayrollMandatoryHours') : null) || document.getElementById('payrollMandatoryHours');
    return {
        workDays: workDays ? workDays.value : '۲۲',
        mandatoryHours: mandatoryHours ? mandatoryHours.value : '۱۷۶'
    };
}

function adminSystemMessageKind(message, isError) {
    var value = String(message || '');
    if (!isError) return value.indexOf('در حال') === 0 ? 'info' : 'success';
    return /پیدا نشد|فاقد داده|بدون داده/.test(value) ? 'warning' : 'error';
}

function notifyAdminSystem(message, isError) {
    var notificationSystem = window.NotificationSystem;
    if (!notificationSystem || typeof notificationSystem.announce !== 'function' || !message) return;
    notificationSystem.announce(message, adminSystemMessageKind(message, isError));
}

function payrollPeriodStatus(message, isError) {
    var element = document.getElementById('payrollPeriodStatus');
    var kind = adminSystemMessageKind(message, isError);
    notifyAdminSystem(message, isError);
    if (!element) return;
    element.textContent = message || '';
    element.classList.toggle('is-error', kind === 'error');
    element.classList.toggle('is-warning', kind === 'warning');
}

function resetPayrollPeriodData() {
    document.querySelectorAll('#payrollComprehensiveTable .payroll-input').forEach(function (input) {
        input.value = '۰';
    });
    document.querySelectorAll('#hourlyPayrollTable .hourly-payroll-input').forEach(function (input) {
        input.value = '۰';
    });
    document.querySelectorAll('#overtimePayrollTable .overtime-payroll-input').forEach(function (input) {
        input.value = '۰';
    });
    overtimePayrollCache = {};
    document.querySelectorAll('[data-result="totalPay"], [data-result="netPay"], [data-hourly-result="totalPay"], [data-hourly-result="netPay"]').forEach(function (cell) {
        cell.textContent = '۰';
    });
    computePayrollTotals();
    computeHourlyPayrollTotals();
}

async function showPayrollPeriod() {
    var button = document.getElementById('payrollDisplayPeriodBtn');
    var period = payrollCurrentPeriod();
    if (!period.year || period.year < 1300 || period.year > 1600) {
        payrollPeriodStatus('لطفاً سال معتبر بین ۱۳۰۰ تا ۱۶۰۰ وارد کنید.', true);
        return;
    }

    if (button) button.disabled = true;
    resetPayrollPeriodData();
    var workDays = document.getElementById('payrollWorkDays');
    var mandatoryHours = document.getElementById('payrollMandatoryHours');
    if (workDays) workDays.value = '۲۲';
    if (mandatoryHours) mandatoryHours.value = '۱۷۶';
    payrollPeriodStatus('در حال بارگذاری اطلاعات دوره…', false);
    try {
        /* ابتدا تنظیمات ذخیره‌شده‌ی دوره را بخوان تا محاسبه‌ی اضافه‌کاری
           با ساعت موظفی همان ماه انجام شود. */
        var summaryCount = await loadSavedPayrollChanges('summary');
        await loadOvertimePayrollData(true);
        var results = await Promise.all([
            loadSavedPayrollChanges('comprehensive'),
            loadSavedPayrollChanges('hourly'),
            loadSavedPayrollChanges('overtime')
        ]);
        var savedCount = results.reduce(function (sum, count) { return sum + (Number(count) || 0); }, Number(summaryCount) || 0);
        payrollPeriodStatus(
            'اطلاعات دوره ' + (document.getElementById('payrollMonth').selectedOptions[0].textContent) +
            ' ' + convertToPersianDigits(String(period.year)) +
            ' نمایش داده شد (' + convertToPersianDigits(String(savedCount)) + ' ردیف ذخیره‌شده).',
            false
        );
    } catch (error) {
        payrollPeriodStatus('خطا در نمایش اطلاعات این دوره. لطفاً دوباره تلاش کنید.', true);
    } finally {
        if (button) button.disabled = false;
    }
}

function payrollSaveStatus(type, message, isError) {
    var id = type === 'comprehensive' ? 'comprehensivePayrollSaveStatus' : type === 'hourly' ? 'hourlyPayrollSaveStatus' : type === 'summary' ? 'summaryPayrollSaveStatus' : 'overtimePayrollSaveStatus';
    notifyAdminSystem(message, isError);
    var element = document.getElementById(id);
    if (!element) return;
    element.textContent = message || '';
    element.classList.toggle('is-error', Boolean(isError));
    element.classList.toggle('is-warning', !isError && String(message || '').indexOf('در حال') !== 0);
}

function payrollSummaryDisplayValue(id) {
    var element = document.getElementById(id);
    if (!element) return 0;
    var value = persianDigitsToEnglish(element.textContent || '').replace(/[،,]/g, '').trim();
    var number = parseFloat(value);
    return isNaN(number) ? 0 : number;
}

function payrollRowsPayload(type) {
    if (type === 'summary') {
        return [{
            username: '__summary__',
            payload: {
                insurance: payrollSummaryDisplayValue('payrollSummaryInsurance'),
                bonus: payrollSummaryDisplayValue('payrollSummaryBonus'),
                eidiSanavat: payrollSummaryDisplayValue('payrollSummaryEidiSanavat'),
                advance: payrollSummaryDisplayValue('payrollSummaryAdvance'),
                netPay: payrollSummaryDisplayValue('payrollSummaryNetPay'),
                totalPay: payrollSummaryDisplayValue('payrollSummaryTotalPay'),
                periodConfig: payrollPeriodConfig()
            }
        }];
    }
    var selector = type === 'comprehensive' ? '#payrollComprehensiveTable tbody .payroll-row' : type === 'hourly' ? '#hourlyPayrollTable tbody .hourly-payroll-row' : '#overtimePayrollTable tbody .overtime-payroll-row';
    var rows = document.querySelectorAll(selector);
    var result = [];
    rows.forEach(function (row) {
        var payload = {};
        if (type === 'comprehensive') {
            row.querySelectorAll('.payroll-input').forEach(function (input) {
                payload[input.getAttribute('data-field')] = input.value;
            });
        } else if (type === 'hourly') {
            row.querySelectorAll('.hourly-payroll-input').forEach(function (input) {
                payload[input.getAttribute('data-hourly-field')] = input.value;
            });
        } else {
            row.querySelectorAll('.overtime-payroll-input').forEach(function (input) {
                payload[input.getAttribute('data-overtime-field')] = input.value;
            });
            payload.cache = overtimePayrollCache[row.getAttribute('data-username')] || {};
        }
        payload.periodConfig = payrollPeriodConfig(type);
        result.push({ username: row.getAttribute('data-username'), payload: payload });
    });
    return result;
}

async function savePayrollChanges(type, button) {
    var period = payrollCurrentPeriod(type);
    payrollSaveStatus(type, 'در حال ذخیره‌سازی…', false);
    if (button) button.disabled = true;
    try {
        var response = await fetch('/api/admin/payroll/save', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                calculation_type: type,
                period_year: period.year,
                period_month: period.month,
                period_config: payrollPeriodConfig(type),
                rows: payrollRowsPayload(type)
            })
        });
        var data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'ذخیره تغییرات ناموفق بود.');
        payrollSaveStatus(type, data.message || 'تغییرات با موفقیت ذخیره شد.', false);
    } catch (error) {
        payrollSaveStatus(type, error.message || 'خطا در ذخیره تغییرات.', true);
    } finally {
        if (button) button.disabled = false;
    }
}

function applyPayrollPeriodConfig(config, type) {
    if (!config || typeof config !== 'object') return;
    if (type === 'overtime') {
        var otWorkDays = document.getElementById('overtimePayrollWorkDays');
        var otMandatory = document.getElementById('overtimePayrollMandatoryHours');
        if (otWorkDays && config.workDays !== undefined) otWorkDays.value = config.workDays;
        if (otMandatory && config.mandatoryHours !== undefined) otMandatory.value = config.mandatoryHours;
    } else {
        var workDays = document.getElementById('payrollWorkDays');
        var mandatoryHours = document.getElementById('payrollMandatoryHours');
        if (workDays && config.workDays !== undefined) workDays.value = config.workDays;
        if (mandatoryHours && config.mandatoryHours !== undefined) mandatoryHours.value = config.mandatoryHours;
    }
}

function applySavedPayrollPayload(type, items) {
    if (type === 'summary') {
        var summaryItem = (items || [])[0];
        var summaryPayload = summaryItem && summaryItem.payload;
        if (!summaryPayload) return;
        var summaryMap = {
            insurance: 'payrollSummaryInsurance',
            bonus: 'payrollSummaryBonus',
            eidiSanavat: 'payrollSummaryEidiSanavat',
            advance: 'payrollSummaryAdvance',
            netPay: 'payrollSummaryNetPay',
            totalPay: 'payrollSummaryTotalPay'
        };
        Object.keys(summaryMap).forEach(function (key) {
            var element = document.getElementById(summaryMap[key]);
            if (element && summaryPayload[key] !== undefined) element.textContent = formatPayrollNumber(Number(summaryPayload[key]) || 0);
        });
        applyPayrollPeriodConfig(summaryPayload.periodConfig);
        return;
    }
    if (type === 'overtime') overtimePayrollCache = {};
    (items || []).forEach(function (item) {
        var selector = type === 'comprehensive' ? '#payrollComprehensiveTable tbody .payroll-row' : type === 'hourly' ? '#hourlyPayrollTable tbody .hourly-payroll-row' : '#overtimePayrollTable tbody .overtime-payroll-row';
        var rows = document.querySelectorAll(selector);
        var row = Array.prototype.find.call(rows, function (candidate) {
            return String(candidate.getAttribute('data-username') || '').trim() === String(item.username || '').trim();
        });
        if (!row || !item.payload) return;
        applyPayrollPeriodConfig(item.payload.periodConfig, type);
        if (type === 'comprehensive') {
            Object.keys(item.payload).forEach(function (field) {
                var input = row.querySelector('.payroll-input[data-field="' + field + '"]');
                if (input) input.value = item.payload[field];
            });
        } else if (type === 'hourly') {
            Object.keys(item.payload).forEach(function (field) {
                var input = row.querySelector('.hourly-payroll-input[data-hourly-field="' + field + '"]');
                if (input) input.value = item.payload[field];
            });
        } else {
            Object.keys(item.payload).forEach(function (field) {
                var input = row.querySelector('.overtime-payroll-input[data-overtime-field="' + field + '"]');
                if (input) input.value = item.payload[field];
            });
            /* Do NOT restore cache from saved payload — use freshly computed attendance data instead */
        }
    });
}

async function loadSavedPayrollChanges(type) {
    var period = payrollCurrentPeriod(type);
    try {
        var response = await fetch('/api/admin/payroll/load?calculation_type=' + encodeURIComponent(type) + '&period_year=' + encodeURIComponent(period.year) + '&period_month=' + encodeURIComponent(period.month), { credentials: 'same-origin' });
        if (!response.ok) return 0;
        var data = await response.json();
        if (!data.success) return 0;
        applySavedPayrollPayload(type, data.items);
        if (type === 'comprehensive') computePayrollTotals();
        if (type === 'hourly') computeHourlyPayrollTotals();
        if (type === 'overtime') {
            var rows = Array.prototype.slice.call(document.querySelectorAll('#overtimePayrollTable tbody .overtime-payroll-row'));
            overtimePayrollSetTotals(rows.map(computeOvertimePayrollRow));
        }
    } catch (error) {
        console.warn('خطا در بازیابی محاسبات حقوق:', error);
        return 0;
    }
    return data.items ? data.items.length : 0;
}

// اتصال رویدادها و محاسبه اولیه
document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('payrollComprehensiveTable');
    if (!table) return;
    document.addEventListener('input', function (e) {
        var target = e.target;
        var hourlyInput = target && target.closest ? target.closest('.hourly-payroll-input') : null;
        if (hourlyInput) {
            formatHourlyPayrollInputValue(hourlyInput);
            computeHourlyPayrollTotals();
            return;
        }
        var overtimeInput = target && target.closest ? target.closest('.overtime-payroll-input') : null;
        if (overtimeInput) {
            formatOvertimePayrollInputValue(overtimeInput);
            var overtimeRow = overtimeInput.closest('.overtime-payroll-row');
            if (overtimeRow) {
                computeOvertimePayrollRow(overtimeRow);
                overtimePayrollSetTotals(Array.prototype.slice.call(document.querySelectorAll('#overtimePayrollTable tbody .overtime-payroll-row')).map(computeOvertimePayrollRow));
            }
            return;
        }
        var input = target && target.closest ? target.closest('.payroll-input') : null;
        if (!input) {
            /* فیلدهای تنظیمات دوره (سال، روزهای کاری، ساعت موظفی) */
            if (target && target.closest && target.closest('.payroll-config-item') && target.matches && target.matches('input')) {
                toPersianPayrollInput(target);
            }
            return;
        }
        /* ارقام فارسی + جداکننده سه‌رقمی زنده هنگام تایپ */
        formatPayrollInputValue(input);
        computePayrollTotals();
    });
    computePayrollTotals();
    computeHourlyPayrollTotals();
    loadOvertimePayrollData(false).then(function () {
        return Promise.all([
            loadSavedPayrollChanges('comprehensive'),
            loadSavedPayrollChanges('hourly'),
            loadSavedPayrollChanges('overtime')
        ]);
    }).then(function () {
        return loadSavedPayrollChanges('summary');
    });

    /* نمای موبایل */
    if (payrollMql) {
        if (payrollMql.addEventListener) payrollMql.addEventListener('change', function (e) { applyPayrollMobile(e.matches); });
        else if (payrollMql.addListener) payrollMql.addListener(function (e) { applyPayrollMobile(e.matches); });
        applyPayrollMobile(payrollMql.matches);
    }
    if (hourlyPayrollMql) {
        if (hourlyPayrollMql.addEventListener) hourlyPayrollMql.addEventListener('change', function (e) { applyHourlyPayrollMobile(e.matches); });
        else if (hourlyPayrollMql.addListener) hourlyPayrollMql.addListener(function (e) { applyHourlyPayrollMobile(e.matches); });
        applyHourlyPayrollMobile(hourlyPayrollMql.matches);
    }
});

// تابع برای باز و بسته کردن منوی گزینه‌ها// تابع برای باز و بسته کردن منوی گزینه‌ها// تابع برای باز و بسته کردن منوی گزینه‌ها
// تابع برای باز و بسته کردن منوی گزینه‌ها// تابع برای باز و بسته کردن منوی گزینه‌ها// تابع برای باز و بسته کردن منوی گزینه‌ها
// تابع برای باز و بسته کردن منوی گزینه‌ها// تابع برای باز و بسته کردن منوی گزینه‌ها// تابع برای باز و بسته کردن منوی گزینه‌ها

// ===== محاسبه حقوق ساعتی پرسنل غیررسمی =====
var HOURLY_HOUR_FIELDS = ['hourlyWorkHours', 'hourlyLeaveHours', 'hourlyPenaltyHours', 'hourlyOvertimeHours'];
var HOURLY_ALL_FIELDS = ['hourlyRate', 'hourlyWorkHours', 'hourlyLeaveHours', 'hourlyPenaltyHours', 'hourlyOvertimeHours', 'hourlyBonus', 'hourlyEidiSanavat', 'hourlyAdvance'];
var hourlyPayrollMql = window.matchMedia ? window.matchMedia('(max-width: ' + PAYROLL_MOBILE_BP + 'px)') : null;
var hourlyPayrollMobileActive = false;
var hourlyPayrollMobileCards = null;
var hourlyPayrollAdoptions = [];

function hourlyPayrollInputsFor(username) {
    var all = document.querySelectorAll('.hourly-payroll-input'), result = [];
    for (var i = 0; i < all.length; i++) {
        if (all[i].getAttribute('data-username') === username) result.push(all[i]);
    }
    return result;
}

function hourlyPayrollFieldValue(username, field) {
    var inputs = hourlyPayrollInputsFor(username);
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].getAttribute('data-hourly-field') === field) return numVal(inputs[i]);
    }
    return 0;
}

function setHourlyPayrollResult(username, key, value) {
    var nodes = document.querySelectorAll('[data-hourly-result="' + key + '"][data-username="' + username + '"]');
    for (var i = 0; i < nodes.length; i++) nodes[i].textContent = formatPayrollNumber(value);
}

function hourlyPayrollFieldLabel(field) {
    var table = document.getElementById('hourlyPayrollTable');
    var th = table && table.querySelector('thead th[data-hourly-field="' + field + '"]');
    return th ? th.textContent : field;
}

function formatHourlyPayrollInputValue(input) {
    if (!input) return;
    var raw = input.value;
    var caret = input.selectionStart == null ? raw.length : input.selectionStart;
    var before = countNumChars(raw, caret);
    var field = input.getAttribute('data-hourly-field') || '';
    var cleaned = raw.replace(/[،,]/g, '');
    var isHours = HOURLY_HOUR_FIELDS.indexOf(field) !== -1;

    if (isHours) {
        var hours = cleaned.replace(/[^0-9۰-۹.]/g, '');
        var dot = hours.indexOf('.');
        if (dot !== -1) hours = hours.slice(0, dot + 3);
        var hoursValue = convertToPersianDigits(hours);
        if (hoursValue !== raw) {
            input.value = hoursValue;
            setCaretAfterNumChars(input, before);
        }
        return;
    }

    var digits = cleaned.replace(/[^0-9۰-۹]/g, '');
    if (!digits) {
        if (raw) input.value = '';
        return;
    }
    var english = persianDigitsToEnglish(digits).replace(/^0+(?=\d)/, '');
    var grouped = english.replace(/\B(?=(\d{3})+(?!\d))/g, '،');
    var value = convertToPersianDigits(grouped);
    if (value !== raw) {
        input.value = value;
        setCaretAfterNumChars(input, before);
    }
}

function computeHourlyPayrollRow(username) {
    var rate = hourlyPayrollFieldValue(username, 'hourlyRate');
    var work = hourlyPayrollFieldValue(username, 'hourlyWorkHours');
    var leave = hourlyPayrollFieldValue(username, 'hourlyLeaveHours');
    var penalty = hourlyPayrollFieldValue(username, 'hourlyPenaltyHours');
    var overtime = hourlyPayrollFieldValue(username, 'hourlyOvertimeHours');
    var bonus = hourlyPayrollFieldValue(username, 'hourlyBonus');
    var eidi = hourlyPayrollFieldValue(username, 'hourlyEidiSanavat');
    var advance = hourlyPayrollFieldValue(username, 'hourlyAdvance');

    /* مبلغ مرخصی و جریمه بر اساس نرخ ساعتی کسر می‌شوند. */
    var totalPay = (rate * work) + (rate * overtime) + bonus + eidi;
    var deductions = (rate * leave) + (rate * penalty) + advance;
    var netPay = Math.max(0, totalPay - deductions);
    setHourlyPayrollResult(username, 'totalPay', totalPay);
    setHourlyPayrollResult(username, 'netPay', netPay);
    return { totalPay: totalPay, netPay: netPay };
}

function computeHourlyPayrollTotals() {
    var table = document.getElementById('hourlyPayrollTable');
    if (!table) return;
    var rows = table.querySelectorAll('tbody .hourly-payroll-row');
    var sums = {}, total = 0, net = 0, rates = 0, rateCount = 0;
    HOURLY_ALL_FIELDS.forEach(function (field) { sums[field] = 0; });
    for (var i = 0; i < rows.length; i++) {
        var username = rows[i].getAttribute('data-username');
        HOURLY_ALL_FIELDS.forEach(function (field) { sums[field] += hourlyPayrollFieldValue(username, field); });
        var rate = hourlyPayrollFieldValue(username, 'hourlyRate');
        if (rate) { rates += rate; rateCount++; }
        var result = computeHourlyPayrollRow(username);
        total += result.totalPay;
        net += result.netPay;
    }
    var rateCell = table.querySelector('[data-hourly-total="hourlyRate"]');
    if (rateCell) rateCell.textContent = formatPayrollNumber(rateCount ? rates / rateCount : 0);
    HOURLY_ALL_FIELDS.forEach(function (field) {
        if (field === 'hourlyRate') return;
        var cell = table.querySelector('[data-hourly-total="' + field + '"]');
        if (cell) cell.textContent = formatPayrollNumber(sums[field]);
    });
    var totalCell = table.querySelector('[data-hourly-total="totalPay"]');
    var netCell = table.querySelector('[data-hourly-total="netPay"]');
    if (totalCell) totalCell.textContent = formatPayrollNumber(total);
    if (netCell) netCell.textContent = formatPayrollNumber(net);
    if (hourlyPayrollMobileCards) {
        var mobileNet = hourlyPayrollMobileCards.querySelector('.hourly-payroll-mobile-total__value');
        if (mobileNet) mobileNet.textContent = formatPayrollNumber(net);
    }
    updatePayrollSummary();
}

// ===== محاسبه کارانه =====
var karanehLoading = false;

function karanehParseDuration(durationStr) {
    if (!durationStr) return 0;
    var parts = String(durationStr).split(':');
    var hours = parseInt(parts[0], 10) || 0;
    var minutes = parseInt(parts[1], 10) || 0;
    return hours * 60 + minutes;
}

function karanehFormatMinutes(totalMinutes) {
    var h = Math.floor(Math.max(0, totalMinutes) / 60);
    var m = Math.max(0, totalMinutes) % 60;
    return convertToPersianDigits(String(h)) + ' ساعت و ' + convertToPersianDigits(String(m)) + ' دقیقه';
}

function karanehClassifyPass(passTitle, durationMinutes) {
    var result = { first: 0, between: 0, last: 0, valid: true, overflow: 0 };

    if (passTitle === 'avalpss') {
        if (durationMinutes <= 30) {
            result.first = durationMinutes;
        } else {
            result.first = 30;
            result.overflow = durationMinutes - 30;
            result.between = result.overflow;
        }
    } else if (passTitle === 'akhrpss') {
        if (durationMinutes <= 30) {
            result.last = durationMinutes;
        } else {
            result.last = 30;
            result.overflow = durationMinutes - 30;
            result.between = result.overflow;
        }
    } else if (passTitle === 'beynpss') {
        result.between = durationMinutes;
    }

    return result;
}

function karanehComputeRow(username, passes) {
    var totalFirst = 0;
    var totalBetween = 0;
    var totalLast = 0;
    var totalAll = 0;
    var validCount = 0;
    var invalidCount = 0;

    for (var i = 0; i < passes.length; i++) {
        var pass = passes[i];
        var duration = karanehParseDuration(pass.pass_duration);
        var classified = karanehClassifyPass(pass.pass_title, duration);

        totalFirst += classified.first;
        totalBetween += classified.between;
        totalLast += classified.last;
    }

    totalAll = totalFirst + totalBetween + totalLast;

    var firstValid = totalFirst <= 30;
    var lastValid = totalLast <= 30;
    var combinedValid = (totalFirst + totalLast) <= 120;
    var betweenValid = totalBetween <= 480;

    if (firstValid && lastValid && combinedValid && betweenValid) {
        validCount = passes.length;
    } else {
        invalidCount = passes.length;
    }

    return {
        username: username,
        firstMinutes: totalFirst,
        betweenMinutes: totalBetween,
        lastMinutes: totalLast,
        totalMinutes: totalAll,
        valid: validCount > 0,
        passes: passes
    };
}

function karanehSet(row, field, value) {
    var cell = row.querySelector('[data-karaneh="' + field + '"]');
    if (cell) cell.textContent = value;
}

function karanehRenderLoading() {
    document.querySelectorAll('#karanehPayrollTable tbody .karaneh-payroll-row').forEach(function (row) {
        karanehSet(row, 'firstHours', '—');
        karanehSet(row, 'firstMinutes', '—');
        karanehSet(row, 'firstStatus', '—');
        karanehSet(row, 'betweenHours', '—');
        karanehSet(row, 'betweenMinutes', '—');
        karanehSet(row, 'betweenStatus', '—');
        karanehSet(row, 'lastHours', '—');
        karanehSet(row, 'lastMinutes', '—');
        karanehSet(row, 'lastStatus', '—');
        karanehSet(row, 'totalMinutes', '—');
        karanehSet(row, 'overallStatus', 'در حال دریافت…');
    });
}

function karanehUpdateTotals(results) {
    var totalFirst = 0;
    var totalBetween = 0;
    var totalLast = 0;
    var totalAll = 0;
    var validTotal = 0;
    var invalidTotal = 0;

    for (var i = 0; i < results.length; i++) {
        totalFirst += results[i].firstMinutes;
        totalBetween += results[i].betweenMinutes;
        totalLast += results[i].lastMinutes;
        totalAll += results[i].totalMinutes;
        if (results[i].valid) validTotal++;
        else invalidTotal++;
    }

    var setTotal = function (id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    setTotal('karanehTotalFirst', karanehFormatMinutes(totalFirst));
    setTotal('karanehTotalBetween', karanehFormatMinutes(totalBetween));
    setTotal('karanehTotalLast', karanehFormatMinutes(totalLast));
    setTotal('karanehTotalAll', karanehFormatMinutes(totalAll));
    setTotal('karanehValidCount', convertToPersianDigits(String(validTotal)));
    setTotal('karanehInvalidCount', convertToPersianDigits(String(invalidTotal)));

    var tfoot = document.querySelector('#karanehPayrollTable tfoot');
    if (tfoot) {
        var setFoot = function (field, value) {
            var cell = tfoot.querySelector('[data-karaneh-total="' + field + '"]');
            if (cell) cell.textContent = value;
        };
        var totalFirstH = Math.floor(totalFirst / 60);
        var totalFirstM = totalFirst % 60;
        var totalBetweenH = Math.floor(totalBetween / 60);
        var totalBetweenM = totalBetween % 60;
        var totalLastH = Math.floor(totalLast / 60);
        var totalLastM = totalLast % 60;
        setFoot('firstHours', convertToPersianDigits(String(totalFirstH)));
        setFoot('firstMinutes', convertToPersianDigits(String(totalFirstM)));
        setFoot('betweenHours', convertToPersianDigits(String(totalBetweenH)));
        setFoot('betweenMinutes', convertToPersianDigits(String(totalBetweenM)));
        setFoot('lastHours', convertToPersianDigits(String(totalLastH)));
        setFoot('lastMinutes', convertToPersianDigits(String(totalLastM)));
        setFoot('totalMinutes', karanehFormatMinutes(totalAll));
    }
}

async function loadKaranehData(force) {
    if (karanehLoading && !force) return;
    karanehLoading = true;
    karanehRenderLoading();
    var range = overtimePayrollMonthRange();
    var rows = Array.prototype.slice.call(document.querySelectorAll('#karanehPayrollTable tbody .karaneh-payroll-row'));
    var results = [];
    try {
        await Promise.all(rows.map(async function (row) {
            var username = row.getAttribute('data-username');
            try {
                var response = await fetch('/get_hourly_pass_report', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: username,
                        start_date: range.start,
                        end_date: range.end
                    })
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                var payload = await response.json();
                var passes = Array.isArray(payload) ? payload : [];
                var result = karanehComputeRow(username, passes);
                results.push(result);
                karanehRenderRow(row, result);
            } catch (error) {
                karanehSet(row, 'overallStatus', 'خطا');
                results.push({ username: username, firstMinutes: 0, betweenMinutes: 0, lastMinutes: 0, totalMinutes: 0, valid: false, passes: [] });
            }
        }));
        karanehUpdateTotals(results);
    } finally {
        karanehLoading = false;
    }
}

function karanehRenderRow(row, result) {
    var firstH = Math.floor(result.firstMinutes / 60);
    var firstM = result.firstMinutes % 60;
    var betweenH = Math.floor(result.betweenMinutes / 60);
    var betweenM = result.betweenMinutes % 60;
    var lastH = Math.floor(result.lastMinutes / 60);
    var lastM = result.lastMinutes % 60;

    karanehSet(row, 'firstHours', convertToPersianDigits(String(firstH)));
    karanehSet(row, 'firstMinutes', convertToPersianDigits(String(firstM)));
    karanehSet(row, 'firstStatus', result.firstMinutes > 30 ? 'غیرمجاز' : 'مجاز');
    karanehSet(row, 'betweenHours', convertToPersianDigits(String(betweenH)));
    karanehSet(row, 'betweenMinutes', convertToPersianDigits(String(betweenM)));
    karanehSet(row, 'betweenStatus', result.betweenMinutes > 480 ? 'غیرمجاز' : 'مجاز');
    karanehSet(row, 'lastHours', convertToPersianDigits(String(lastH)));
    karanehSet(row, 'lastMinutes', convertToPersianDigits(String(lastM)));
    karanehSet(row, 'lastStatus', result.lastMinutes > 30 ? 'غیرمجاز' : 'مجاز');
    karanehSet(row, 'totalMinutes', karanehFormatMinutes(result.totalMinutes));

    var firstValid = result.firstMinutes <= 30;
    var lastValid = result.lastMinutes <= 30;
    var combinedValid = (result.firstMinutes + result.lastMinutes) <= 120;
    var betweenValid = result.betweenMinutes <= 480;
    var overallValid = firstValid && lastValid && combinedValid && betweenValid;

    karanehSet(row, 'overallStatus', overallValid ? 'مجاز' : 'غیرمجاز');
    var statusCell = row.querySelector('[data-karaneh="overallStatus"]');
    if (statusCell) {
        statusCell.classList.remove('karaneh-valid', 'karaneh-invalid');
        statusCell.classList.add(overallValid ? 'karaneh-valid' : 'karaneh-invalid');
    }
}

function updatePayrollSummary() {
    var summary = {
        insurance: 0,
        bonus: 0,
        eidiSanavat: 0,
        advance: 0,
        totalPay: 0,
        netPay: 0
    };

    var comprehensiveTable = document.getElementById('payrollComprehensiveTable');
    if (comprehensiveTable) {
        var rows = comprehensiveTable.querySelectorAll('tbody .payroll-row');
        for (var i = 0; i < rows.length; i++) {
            var username = rows[i].getAttribute('data-username');
            summary.insurance += payrollFieldValue(username, 'insurance');
            summary.bonus += payrollFieldValue(username, 'bonus');
            summary.eidiSanavat += payrollFieldValue(username, 'eidiSanavat');
            summary.advance += payrollFieldValue(username, 'advance');
            var result = computePayrollRow(username);
            summary.totalPay += result.totalPay;
            summary.netPay += result.netPay;
        }
    }

    var hourlyTable = document.getElementById('hourlyPayrollTable');
    if (hourlyTable) {
        var hourlyRows = hourlyTable.querySelectorAll('tbody .hourly-payroll-row');
        for (var j = 0; j < hourlyRows.length; j++) {
            var hourlyUsername = hourlyRows[j].getAttribute('data-username');
            summary.bonus += hourlyPayrollFieldValue(hourlyUsername, 'hourlyBonus');
            summary.eidiSanavat += hourlyPayrollFieldValue(hourlyUsername, 'hourlyEidiSanavat');
            summary.advance += hourlyPayrollFieldValue(hourlyUsername, 'hourlyAdvance');
            var hourlyResult = computeHourlyPayrollRow(hourlyUsername);
            summary.totalPay += hourlyResult.totalPay;
            summary.netPay += hourlyResult.netPay;
        }
    }

    var outputMap = {
        payrollSummaryInsurance: summary.insurance,
        payrollSummaryBonus: summary.bonus,
        payrollSummaryEidiSanavat: summary.eidiSanavat,
        payrollSummaryAdvance: summary.advance,
        payrollSummaryNetPay: summary.netPay,
        payrollSummaryTotalPay: summary.totalPay
    };
    Object.keys(outputMap).forEach(function (id) {
        var element = document.getElementById(id);
        if (element) element.textContent = formatPayrollNumber(outputMap[id]);
    });
}

function printPayrollTablesA4() {
    var comprehensiveTable = document.getElementById('payrollComprehensiveTable');
    var hourlyTable = document.getElementById('hourlyPayrollTable');
    if (!comprehensiveTable || !hourlyTable) {
        showSystemError('جدول‌های حقوق برای چاپ آماده نیستند.');
        return;
    }

    computePayrollTotals();
    computeHourlyPayrollTotals();

    var month = document.getElementById('payrollMonth');
    var year = document.getElementById('payrollYear');
    var monthText = month && month.options[month.selectedIndex] ? month.options[month.selectedIndex].textContent : '';
    var yearText = year ? year.value : '';
    var title = (monthText || 'دوره حقوق') + ' ' + (yearText || '۱۴۰۵');

    /* بعضی مرورگرها window.open را حتی با کلیک مستقیم هم مسدود می‌کنند.
       ابتدا پنجره‌ی چاپ را بدون feature string باز می‌کنیم و در صورت مسدود
       بودن، به iframe چاپ داخل همین صفحه برمی‌گردیم. */
    var printWindow = window.open('', '_blank');
    var printFrame = null;
    if (printWindow) {
        try { printWindow.opener = null; } catch (e) { /* noop */ }
    } else {
        printFrame = document.createElement('iframe');
        printFrame.setAttribute('aria-hidden', 'true');
        printFrame.className = 'payroll-print-frame';
        document.body.appendChild(printFrame);
        printWindow = printFrame.contentWindow;
    }

    if (!printWindow || !printWindow.document) {
        if (printFrame) printFrame.remove();
        showSystemError('امکان آماده‌سازی چاپ وجود ندارد.');
        return;
    }

    function clonePrintableTable(source, heading) {
        var table = source.cloneNode(true);
        table.removeAttribute('id');
        table.querySelectorAll('.payroll-copy-previous-btn').forEach(function (button) {
            button.remove();
        });
        table.querySelectorAll('input, select, textarea').forEach(function (control) {
            var value = control.value || '۰';
            if (control.tagName === 'SELECT' && control.options[control.selectedIndex]) {
                value = control.options[control.selectedIndex].textContent;
            }
            var text = document.createElement('span');
            text.className = 'print-value';
            text.textContent = value;
            control.replaceWith(text);
        });
        table.querySelectorAll('[style]').forEach(function (element) {
            element.removeAttribute('style');
        });
        var section = document.createElement('section');
        section.className = 'print-table-section';
        var headingElement = document.createElement('h2');
        headingElement.textContent = heading;
        section.appendChild(headingElement);
        section.appendChild(table);
        return section;
    }

    var firstSection = clonePrintableTable(comprehensiveTable, 'محاسبه جامع حقوق و دستمزد');
    var secondSection = clonePrintableTable(hourlyTable, 'حقوق ساعتی پرسنل غیررسمی');
    var report = document.createElement('div');
    report.className = 'payroll-print-report';
    report.appendChild(firstSection);
    report.appendChild(secondSection);

    var printDocument = printWindow.document;
    printDocument.open();
    printDocument.write('<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' + title + '</title><style>' +
        '@page{size:A4 landscape;margin:7mm}' +
        '*{box-sizing:border-box}' +
        'html,body{margin:0;padding:0;background:#fff;color:#132b3a;font-family:Vazir,Tahoma,Arial,sans-serif}' +
        'body{direction:rtl;font-size:7px}' +
        '.payroll-print-report{width:100%}' +
        '.print-title{text-align:center;font-size:14px;font-weight:800;margin:0 0 3mm;padding-bottom:2mm;border-bottom:1px solid #234b63}' +
        '.print-meta{text-align:center;font-size:7px;color:#526d7d;margin:-1mm 0 3mm}' +
        '.print-table-section{margin:0 0 2mm;break-inside:avoid;page-break-inside:avoid}' +
        '.print-table-section h2{font-size:8px;text-align:right;margin:0;padding:1.5mm 2mm;color:#fff;background:#245a78;border:1px solid #19455e;line-height:1.2}' +
        'table{width:100%;border-collapse:collapse;table-layout:fixed;direction:rtl}' +
        'th,td{border:0.35px solid #8298a5;padding:1.1mm .55mm;text-align:center;vertical-align:middle;line-height:1.25;overflow:hidden;word-break:break-word}' +
        'thead th{background:#dcecf4;color:#163c50;font-weight:800;font-size:6.2px}' +
        'thead tr:first-child th{background:#28627f;color:#fff;font-size:6.7px}' +
        'tbody td{font-size:6.2px}' +
        'tbody tr:nth-child(even) td{background:#f5f9fb}' +
        'tfoot td{background:#e6f2f7;font-weight:800;font-size:6.2px}' +
        '.payroll-person{display:block}.payroll-person-name{font-weight:800}.payroll-person-meta{display:block;font-size:5.4px;color:#5c7481}' +
        '.print-value{display:block;min-height:2.5mm;white-space:nowrap}' +
        '.print-table-section:first-of-type table th,.print-table-section:first-of-type table td{font-size:5.5px;padding:.75mm .35mm}' +
        '.print-table-section:first-of-type table thead th{font-size:5.4px}' +
        '.print-table-section:first-of-type table thead tr:first-child th{font-size:5.8px}' +
        '.print-table-section:first-of-type table .payroll-person-name{font-size:5.8px}' +
        '.print-table-section:first-of-type table .payroll-person-meta{font-size:4.8px}' +
        '</style></head><body></body></html>');
    printDocument.close();
    printDocument.body.appendChild(function () {
        var wrapper = printDocument.createElement('div');
        wrapper.className = 'payroll-print-report';
        var titleElement = printDocument.createElement('h1');
        titleElement.className = 'print-title';
        titleElement.textContent = title;
        wrapper.appendChild(titleElement);
        var meta = printDocument.createElement('div');
        meta.className = 'print-meta';
        meta.textContent = 'گزارش پرداخت پرسنل';
        wrapper.appendChild(meta);
        wrapper.appendChild(printDocument.importNode(firstSection, true));
        wrapper.appendChild(printDocument.importNode(secondSection, true));
        return wrapper;
    }());

    printWindow.focus();
    setTimeout(function () {
        printWindow.print();
        if (printFrame) {
            setTimeout(function () { printFrame.remove(); }, 1000);
        } else {
            printWindow.close();
        }
    }, 450);
}

function openPayrollReportPreview() {
    var comprehensiveTable = document.getElementById('payrollComprehensiveTable');
    var hourlyTable = document.getElementById('hourlyPayrollTable');
    if (!comprehensiveTable || !hourlyTable) {
        showSystemError('جدول‌های حقوق برای نمایش آماده نیستند.');
        return;
    }

    computePayrollTotals();
    computeHourlyPayrollTotals();

    var month = document.getElementById('payrollMonth');
    var year = document.getElementById('payrollYear');
    var monthText = month && month.options[month.selectedIndex] ? month.options[month.selectedIndex].textContent : '';
    var yearText = year ? year.value : '';
    var title = (monthText || 'دوره حقوق') + ' ' + (yearText || '۱۴۰۵');

    function clonePrintableTable(source, heading) {
        var table = source.cloneNode(true);
        table.removeAttribute('id');
        table.querySelectorAll('.payroll-copy-previous-btn').forEach(function (button) {
            button.remove();
        });
        table.querySelectorAll('input, select, textarea').forEach(function (control) {
            var value = control.value || '۰';
            if (control.tagName === 'SELECT' && control.options[control.selectedIndex]) {
                value = control.options[control.selectedIndex].textContent;
            }
            var text = document.createElement('span');
            text.className = 'print-value';
            text.textContent = value;
            control.replaceWith(text);
        });
        table.querySelectorAll('[style]').forEach(function (element) {
            element.removeAttribute('style');
        });
        return { heading: heading, html: table.outerHTML };
    }

    try {
        sessionStorage.setItem('payrollPreviewData', JSON.stringify({
            title: title,
            sections: [
                clonePrintableTable(comprehensiveTable, 'محاسبه جامع حقوق و دستمزد'),
                clonePrintableTable(hourlyTable, 'حقوق ساعتی پرسنل غیررسمی')
            ]
        }));
    } catch (error) {
        showSystemError('حجم گزارش برای نمایش بیش از حد مجاز است.');
        return;
    }

    var previewWindow = window.open('/payroll_report_page', '_blank');
    if (!previewWindow) {
        showSystemError('صفحه گزارش توسط مرورگر مسدود شد؛ لطفاً اجازه باز شدن پنجره را فعال کنید.');
    }
}

function hourlyPayrollText(tag, className, text) {
    var node = document.createElement(tag);
    node.className = className || '';
    node.textContent = text;
    return node;
}

function buildHourlyPayrollMobileCards() {
    var table = document.getElementById('hourlyPayrollTable');
    if (!table || hourlyPayrollMobileCards) return;
    var content = table.closest('.payroll-tab-content');
    var scroll = table.closest('.hourly-payroll-table-scroll');
    if (!content) return;
    var container = document.createElement('div');
    container.className = 'hourly-payroll-mobile-cards';
    content.insertBefore(container, scroll ? scroll.nextSibling : null);
    hourlyPayrollMobileCards = container;

    var rows = table.querySelectorAll('tbody .hourly-payroll-row');
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i], username = row.getAttribute('data-username');
        var card = document.createElement('article');
        card.className = 'hourly-payroll-card';
        var head = document.createElement('header');
        head.className = 'hourly-payroll-card__head';
        var title = document.createElement('div');
        var name = row.querySelector('.payroll-person-name');
        var meta = row.querySelector('.payroll-person-meta');
        title.appendChild(hourlyPayrollText('strong', 'hourly-payroll-card__name', name ? name.textContent : username));
        if (meta && meta.textContent) title.appendChild(hourlyPayrollText('span', 'hourly-payroll-card__meta', meta.textContent));
        head.appendChild(title);
        card.appendChild(head);

        var fields = document.createElement('div');
        fields.className = 'hourly-payroll-card__fields';
        HOURLY_ALL_FIELDS.forEach(function (field) {
            var input = row.querySelector('.hourly-payroll-input[data-hourly-field="' + field + '"]');
            if (!input) return;
            var label = document.createElement('label');
            label.className = 'hourly-payroll-card__field';
            label.appendChild(hourlyPayrollText('span', 'hourly-payroll-card__field-label', hourlyPayrollFieldLabel(field)));
            var cell = input.parentNode;
            label.appendChild(input);
            fields.appendChild(label);
            hourlyPayrollAdoptions.push({ td: cell, input: input });
        });
        card.appendChild(fields);

        var results = document.createElement('div');
        results.className = 'hourly-payroll-card__results';
        [['netPay', 'مبلغ مانده قابل پرداخت'], ['totalPay', 'مجموع پرداختی']].forEach(function (item) {
            var result = document.createElement('div');
            result.className = 'hourly-payroll-card__result';
            result.appendChild(hourlyPayrollText('span', '', item[1]));
            var value = hourlyPayrollText('b', '', '—');
            value.setAttribute('data-hourly-result', item[0]);
            value.setAttribute('data-username', username);
            result.appendChild(value);
            results.appendChild(result);
        });
        card.appendChild(results);
        container.appendChild(card);
    }
    var totalBox = document.createElement('div');
    totalBox.className = 'hourly-payroll-mobile-total';
    totalBox.appendChild(hourlyPayrollText('span', 'hourly-payroll-mobile-total__label', 'مجموع مانده قابل پرداخت'));
    totalBox.appendChild(hourlyPayrollText('b', 'hourly-payroll-mobile-total__value', '۰'));
    container.appendChild(totalBox);
}

function restoreHourlyPayrollMobileCards() {
    hourlyPayrollAdoptions.forEach(function (item) {
        if (item.input && item.td) item.td.appendChild(item.input);
    });
    hourlyPayrollAdoptions = [];
    if (hourlyPayrollMobileCards) {
        hourlyPayrollMobileCards.remove();
        hourlyPayrollMobileCards = null;
    }
}

function applyHourlyPayrollMobile(on) {
    if (on === hourlyPayrollMobileActive) return;
    hourlyPayrollMobileActive = on;
    if (on) {
        buildHourlyPayrollMobileCards();
        computeHourlyPayrollTotals();
    } else {
        restoreHourlyPayrollMobileCards();
    }
}

// تابع برای باز و بسته کردن نوار کناری// تابع برای باز و بسته کردن نوار کناری// تابع برای باز و بسته کردن نوار کناری
// تابع برای باز و بسته کردن نوار کناری// تابع برای باز و بسته کردن نوار کناری// تابع برای باز و بسته کردن نوار کناری
// تابع برای باز و بسته کردن نوار کناری// تابع برای باز و بسته کردن نوار کناری// تابع برای باز و بسته کردن نوار کناری

function toggleProfileDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        dropdown.classList.toggle('open');
    }
}

function closeProfileDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        dropdown.classList.remove('open');
    }
}

function openProfilePanel(panelType) {
    closeProfileDropdown();

    const overlay = document.getElementById('profilePanelOverlay');
    const panel = document.getElementById('profilePanel');
    const title = document.getElementById('profilePanelTitle');
    const subtitle = document.getElementById('profilePanelSubtitle');
    const body = document.getElementById('profilePanelBody');

    if (!overlay || !panel || !title || !subtitle || !body) {
        return;
    }

    const panelConfig = {
        profile: {
            title: 'پروفایل من',
            subtitle: 'اطلاعات حساب و وضعیت دسترسی',
            content: `
                <div class="profile-panel-avatar-wrap">
                    <div class="profile-panel-avatar">
                        <img src="/static/images/user.png" alt="پروفایل کاربر">
                    </div>
                    <div class="profile-panel-user">
                        <strong>مدیر سیستم</strong>
                        <span>ادمین | سطح دسترسی ۳</span>
                    </div>
                </div>
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>اطلاعات پایه</h4>
                    <ul>
                        <li><span>نام کاربری</span><strong>admin</strong></li>
                        <li><span>ایمیل</span><strong>admin@hastama.ir</strong></li>
                        <li><span>آخرین ورود</span><strong>امروز ۱۴:۳۰</strong></li>
                    </ul>
                </div>
            `
        },
        security: {
            title: 'امنیت و رمز عبور',
            subtitle: 'تنظیمات حفاظت از حساب کاربری',
            content: `
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>تغییر رمز عبور</h4>
                    <form class="profile-form">
                        <label for="profileCurrentPassword">رمز عبور فعلی<input type="password" id="profileCurrentPassword" name="current_password" value="********"></label>
                        <label for="profileNewPassword">رمز عبور جدید<input type="password" id="profileNewPassword" name="new_password" placeholder="رمز عبور جدید"></label>
                        <label for="profileConfirmPassword">تکرار رمز عبور<input type="password" id="profileConfirmPassword" name="confirm_password" placeholder="تکرار رمز عبور"></label>
                        <button type="button">ذخیره تغییرات</button>
                    </form>
                </div>
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>اقدامات پیشنهادی</h4>
                    <div class="profile-setting-row"><span>احراز هویت دو مرحله‌ای</span><strong>فعال</strong></div>
                    <div class="profile-setting-row"><span>هشدار ورود مشکوک</span><strong>روشن</strong></div>
                </div>
            `
        },
        subscription: {
            title: 'اشتراک من',
            subtitle: 'وضعیت بسته اشتراک و امکانات',
            content: `
                <div class="profile-panel-card">
                    <div class="profile-panel-badge">اشتراک حرفه‌ای</div>
                    <div class="profile-panel-stat"><strong>۲۳۱ روز باقی‌مانده</strong><span>تا ۱۴۰۵/۱۲/۲۹</span></div>
                </div>
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>امکانات فعال</h4>
                    <div class="profile-setting-row"><span>پشتیبانی ویژه</span><strong>فعال</strong></div>
                    <div class="profile-setting-row"><span>گزارش‌های پیشرفته</span><strong>فعال</strong></div>
                    <div class="profile-setting-row"><span>مدیریت چندین شعبه</span><strong>فعال</strong></div>
                </div>
                <button type="button" class="profile-panel-action">تمدید اشتراک</button>
            `
        },
        billing: {
            title: 'فاکتورها و پرداخت',
            subtitle: 'تاریخچه صورتحساب و وضعیت پرداخت',
            content: `
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>آخرین فاکتورها</h4>
                    <div class="profile-invoice-item">
                        <div>
                            <strong>فاکتور خرداد ۱۴۰۵</strong>
                            <span>پرداخت شده · ۲۵/۰۳/۱۴۰۵</span>
                        </div>
                        <strong>۲٬۴۵۰٬۰۰۰ تومان</strong>
                    </div>
                    <div class="profile-invoice-item">
                        <div>
                            <strong>فاکتور اردیبهشت ۱۴۰۵</strong>
                            <span>در انتظار پرداخت</span>
                        </div>
                        <strong>۱٬۸۹۰٬۰۰۰ تومان</strong>
                    </div>
                </div>
                <button type="button" class="profile-panel-action">مشاهده همه فاکتورها</button>
            `
        },
        support: {
            title: 'پشتیبانی فنی',
            subtitle: 'ارسال درخواست و راه‌های تماس',
            content: `
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>ارسال تیکت جدید</h4>
                    <form class="profile-form">
                        <label for="profileSupportSubject">موضوع<input type="text" id="profileSupportSubject" name="subject" placeholder="مشکل در ورود"></label>
                        <label for="profileSupportDescription">توضیح<textarea id="profileSupportDescription" name="description" placeholder="شرح مشکل خود را بنویسید"></textarea></label>
                        <button type="button">ارسال درخواست</button>
                    </form>
                </div>
                <div class="profile-panel-card profile-panel-card--stacked">
                    <h4>راه‌های دسترسی</h4>
                    <div class="profile-setting-row"><span>تلفن پشتیبانی</span><strong>۰۲۱-۴۴۴۴۵۵۵۵</strong></div>
                    <div class="profile-setting-row"><span>پشتیبانی آنلاین</span><strong>۲۴ ساعته</strong></div>
                </div>
            `
        },
        settings: {
            title: 'تنظیمات سامانه',
            subtitle: 'سفارشی‌سازی تجربه کاربری',
            content: `
                <div class="profile-panel-card profile-panel-card--stacked">
                    <div class="profile-setting-row"><span>حالت تیره</span><strong>روشن</strong></div>
                    <div class="profile-setting-row"><span>اعلان‌های کشویی</span><strong>فعال</strong></div>
                    <div class="profile-setting-row"><span>زبان پیش‌فرض</span><strong>فارسی</strong></div>
                </div>
                <button type="button" class="profile-panel-action">ذخیره تنظیمات</button>
            `
        }
    };

    const config = panelConfig[panelType];
    if (!config) {
        return;
    }

    title.textContent = config.title;
    subtitle.textContent = config.subtitle;
    body.innerHTML = config.content;

    overlay.hidden = false;
    panel.hidden = false;
    requestAnimationFrame(() => {
        overlay.classList.add('open');
        panel.classList.add('open');
    });
}

function closeProfilePanel() {
    const overlay = document.getElementById('profilePanelOverlay');
    const panel = document.getElementById('profilePanel');
    if (!overlay || !panel) {
        return;
    }

    overlay.classList.remove('open');
    panel.classList.remove('open');
    setTimeout(() => {
        overlay.hidden = true;
        panel.hidden = true;
    }, 220);
}

function toggleNotifications(event) {
    event.stopPropagation();
    const panel = document.getElementById('notificationsPanel');
    if (panel) {
        panel.classList.toggle('open');
    }
}

// مدیریت تم به لایهٔ مشترک theme.js منتقل شد (ذخیره در localStorage + هماهنگی
// کلاس‌های dark-mode/dark-theme در همهٔ صفحات). این تابع فقط برای سازگاری با
// onclick موجود در admin.html نگه داشته شده است.
function toggleTheme() {
    if (window.HastamaTheme) {
        window.HastamaTheme.toggle();
        return;
    }
    document.body.classList.toggle('dark-theme');
    document.body.classList.toggle('dark-mode');
}

function toggleSidebar(event) {
    const sidebar = document.querySelector('.rightSidebar');
    if (!sidebar) return;

    if (event) {
        event.stopPropagation();
    }

    const isOpen = sidebar.classList.toggle('open');
    document.body.classList.toggle('mobile-sidebar-open', isOpen && window.innerWidth <= 768);
    const toggle = document.querySelector('.mobile-menu-toggle');
    if (toggle) {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
    const pageShell = document.querySelector('.page-shell');
    if (pageShell) {
        pageShell.classList.toggle('sidebar-expanded', isOpen && window.innerWidth > 768);
    }

    if (isOpen) {
        document.addEventListener('click', documentClickCloseSidebar);
    } else {
        document.removeEventListener('click', documentClickCloseSidebar);
    }
}

function documentClickCloseSidebar(event) {
    const sidebar = document.querySelector('.rightSidebar');
    if (!sidebar || !sidebar.classList.contains('open')) return;
    const target = event.target;
    if (sidebar.contains(target) || target.closest('.mobile-menu-toggle')) {
        return;
    }
    closeMobileSidebar();
}

function closeMobileSidebar() {
    const sidebar = document.querySelector('.rightSidebar');
    if (!sidebar) return;
    sidebar.classList.remove('open');
    document.body.classList.remove('mobile-sidebar-open');
    const toggle = document.querySelector('.mobile-menu-toggle');
    if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
    }
    const pageShell = document.querySelector('.page-shell');
    if (pageShell) {
        pageShell.classList.remove('sidebar-expanded');
    }
    document.removeEventListener('click', documentClickCloseSidebar);
}

// باز/بسته شدن سایدبار هنگام هاور (دسکتاپ) — کلاس sidebar-expanded روی
// .page-shell اضافه/حذف می‌شود تا باکس‌های مدیریتی بتوانند بر همان اساس فشرده شوند
document.addEventListener('DOMContentLoaded', function () {
    const sidebarEl = document.querySelector('.rightSidebar');
    const pageShellEl = document.querySelector('.page-shell');
    if (!sidebarEl || !pageShellEl) return;

    sidebarEl.addEventListener('mouseenter', function () {
        pageShellEl.classList.add('sidebar-expanded');
    });

    sidebarEl.addEventListener('mouseleave', function () {
        // اگر با کلیک (روی موبایل) باز نگه داشته شده، با خارج شدن ماوس بسته نشود
        if (!sidebarEl.classList.contains('open')) {
            pageShellEl.classList.remove('sidebar-expanded');
        }
    });
});

// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی
// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی
// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی// تابع برای تبدیل اعداد به فارسی

function convertToPersianNumbers(str) {
    var persianNumbers = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
    return str.replace(/[0-9]/g, function(digit) {
        return persianNumbers[digit];
    });
}

// تابع برای تغییر فرمت تاریخ از YYYY-MM-DD به YYYY/MM/DD// تابع برای تغییر فرمت تاریخ از YYYY-MM-DD به YYYY/MM/DD
// تابع برای تغییر فرمت تاریخ از YYYY-MM-DD به YYYY/MM/DD// تابع برای تغییر فرمت تاریخ از YYYY-MM-DD به YYYY/MM/DD
// تابع برای تغییر فرمت تاریخ از YYYY-MM-DD به YYYY/MM/DD// تابع برای تغییر فرمت تاریخ از YYYY-MM-DD به YYYY/MM/DD

function formatDate(dateStr) {
    if (!dateStr || typeof dateStr !== "string") return dateStr; // بررسی صحت ورودی
    return dateStr.replace(/-/g, "/"); // جایگزینی '-' با '/'
}

// تابع دریافت اطلاعات درخواست مرخصی های کاربران// تابع دریافت اطلاعات درخواست مرخصی های کاربران// تابع دریافت اطلاعات درخواست مرخصی های کاربران
// تابع دریافت اطلاعات درخواست مرخصی های کاربران// تابع دریافت اطلاعات درخواست مرخصی های کاربران// تابع دریافت اطلاعات درخواست مرخصی های کاربران
// تابع دریافت اطلاعات درخواست مرخصی های کاربران// تابع دریافت اطلاعات درخواست مرخصی های کاربران// تابع دریافت اطلاعات درخواست مرخصی های کاربران

function loadLeaveRequests() {
    fetch('/get_leave_requests')
        .then(response => response.json())
        .then(data => {
            // بررسی اینکه داده دریافتی یک آرایه است
            if (!Array.isArray(data)) {
                console.error('Invalid data received:', data);
                return; // اگر داده آرایه نیست، ادامه نمی‌دهیم
            }

            const tableBody = document.querySelector('#leaveRequestsTable tbody');
            tableBody.innerHTML = ''; // پاک کردن محتوای قبلی

            data.forEach(request => {
                // فقط درخواست‌هایی که وضعیت آنها "انتظار تایید" است نمایش داده می‌شود
                if (request.status === 'انتظار تایید') {
                    const row = document.createElement('tr');
                    row.id = `row_${request.id}`; // اضافه کردن ID برای ردیف جهت حذف بعدی
                    row.innerHTML = `
                        <td>${esc(convertToPersianNumbers(request.username))}</td>
                        <td>${convertToPersianNumbers(formatDate(request.start_date))}</td>
                        <td>${convertToPersianNumbers(formatDate(request.end_date))}</td>
                        <td>${convertToPersianNumbers(request.days)}</td>
                        <td>${esc(convertToPersianNumbers(request.substitute))}</td>
                        <td>
                            <div class="status-container">
                                <div class="status-navbar" id="statusNavbar_${request.id}" onclick="toggleDropdown(${request.id})">
                                    ${convertToPersianNumbers(request.status)}
                                </div>
                                <div class="status-dropdown" id="statusDropdown_${request.id}" style="display: none;">
                                    <div class="status-option approved" onclick="changeStatus(${request.id}, 'تایید شده')">تایید شده</div>
                                    <div class="status-option rejected" onclick="changeStatus(${request.id}, 'رد شده')">رد شده</div>
                                    <div class="status-option cancelled" onclick="changeStatus(${request.id}, 'انصراف')">انصراف</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <button class="update-button" onclick="updateStatus(${request.id})">تایید تغییرات</button>
                        </td>
                    `;
                    tableBody.appendChild(row);

                    changeStatus(request.id, request.status); // اعمال رنگ وضعیت
                    const dropdown = document.querySelector(`#statusDropdown_${request.id}`);
                    dropdown.style.display = 'none'; // پنهان کردن dropdown
                }
            });
        // افزودن event listener برای بستن dropdown زمانی که خارج از آن کلیک می‌شود
        document.addEventListener('click', function (event) {
            const clickedNavbar = event.target.closest('.status-navbar');
            const clickedContainer = clickedNavbar ? clickedNavbar.closest('.status-container') : null;

            const dropdowns = document.querySelectorAll('.status-dropdown');
            dropdowns.forEach(dropdown => {
                const origParent = dropdown.__origParent || dropdown.parentElement;
                const dropdownContainer = origParent ? origParent.closest('.status-container') : null;

                // اگر کلیک داخل خود dropdown یا روی navbar متعلق به آن بوده، نادیده بگیر
                if (dropdown.contains(event.target)) return;
                if (clickedContainer && dropdownContainer && clickedContainer === dropdownContainer) return;

                // در غیر این صورت dropdown را ببند و در صورت جابجا شدن، آن را به والد اصلی بازگردان
                if (dropdown.style.display === 'flex') {
                    dropdown.style.display = 'none';
                }
                if (dropdown.__origParent) {
                    dropdown.__origParent.appendChild(dropdown);
                    dropdown.style.position = '';
                    dropdown.style.left = '';
                    dropdown.style.top = '';
                    dropdown.style.right = '';
                    dropdown.style.visibility = '';
                    dropdown.__origParent = null;
                }
            });
        });
    })
    .catch(error => {
        console.error('Error fetching leave requests:', error);
    });
}

// تابع تغییر وضعیت و رنگ navbar هنگام کلیک
function changeStatus(requestId, newStatus) {
    const statusNavbar = document.querySelector(`#statusNavbar_${requestId}`);
    statusNavbar.textContent = convertToPersianNumbers(newStatus);

    // تغییر کلاس navbar بر اساس وضعیت جدید
    if (newStatus === 'تایید شده') {
        statusNavbar.className = 'status-navbar approved';
    } else if (newStatus === 'رد شده') {
        statusNavbar.className = 'status-navbar rejected';
    } else if (newStatus === 'انتظار تایید') {
        statusNavbar.className = 'status-navbar pending';
    } else if (newStatus === 'انصراف') {
        statusNavbar.className = 'status-navbar cancelled';
    }

    toggleDropdown(requestId);
}

// تابع به‌روزرسانی وضعیت در دیتابیس
function updateStatus(requestId) {
    const statusNavbar = document.querySelector(`#statusNavbar_${requestId}`);
    const currentStatus = statusNavbar.textContent.trim(); // وضعیت فعلی که در خانه جدول نمایش داده می‌شود

    // بررسی اینکه وضعیت "انتظار تایید" نباشد
    if (currentStatus === 'انتظار تایید') {
        showSystemError('درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.');
        return; // متوقف کردن ادامه عملیات
    }

    // ارسال وضعیت فعلی به سرور
    fetch('/update_leave_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ requestId, status: currentStatus })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('وضعیت با موفقیت تغییر کرد!');
            // فقط در صورتی که تغییرات با موفقیت انجام شد، ردیف از جدول حذف می‌شود
            setTimeout(() => removeRequestFromTable(requestId), 100); // حذف ردیف بعد از کمی تاخیر
        } else {
            showSystemError('خطا در به‌روزرسانی وضعیت!');
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
        showSystemError('خطا در به‌روزرسانی وضعیت!');
    });
}

function convertToPersianNumbers(value) {
    if (value === null || value === undefined) {
        return ''; // یا می‌توانید مقدار پیش‌فرض دیگری برگردانید
    }

    // اگر مقدار null یا undefined نبود، آن را به رشته تبدیل می‌کنیم و سپس به اعداد فارسی تبدیل می‌کنیم
    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return value.toString().replace(/[0-9]/g, (digit) => persianNumbers[digit]);
}

// حذف ردیف از جدول بعد از تایید
function removeRequestFromTable(requestId) {
    const row = document.querySelector(`#row_${requestId}`);
    if (row) {
        row.remove(); // حذف ردیف از جدول
    }
}

// تابع برای باز و بسته شدن dropdown
function toggleDropdown(requestId) {
    const dropdown = document.querySelector(`#statusDropdown_${requestId}`);
    const navbar = document.querySelector(`#statusNavbar_${requestId}`);
    if (!dropdown || !navbar) return;

    const isOpen = dropdown.style.display === 'flex';
    if (isOpen) {
        dropdown.style.display = 'none';
        if (dropdown.__origParent) {
            dropdown.__origParent.appendChild(dropdown);
            dropdown.style.position = 'fixed';
            dropdown.style.left = '0px';
            dropdown.style.top = '0px';
            dropdown.style.right = 'auto';
            dropdown.style.visibility = 'visible';
            dropdown.__origParent = null;
        }
        return;
    }

    document.querySelectorAll('.status-dropdown').forEach(d => {
        if (d !== dropdown) {
            d.style.display = 'none';
            if (d.__origParent) {
                d.__origParent.appendChild(d);
                d.style.position = 'fixed';
                d.style.left = '0px';
                d.style.top = '0px';
                d.style.right = 'auto';
                d.__origParent = null;
            }
        }
    });

    if (!dropdown.__origParent) dropdown.__origParent = dropdown.parentElement;

    document.body.appendChild(dropdown);
    dropdown.style.position = 'fixed';
    dropdown.style.display = 'flex';
    dropdown.style.visibility = 'hidden';
    dropdown.style.zIndex = '2147483647';

    const rect = navbar.getBoundingClientRect();
    const width = dropdown.offsetWidth || 160;
    const height = dropdown.offsetHeight || 120;

    let left = rect.left + (rect.width - width);
    let top = rect.bottom + 6;

    if (left < 12) left = 12;
    if (left + width > window.innerWidth - 12) left = window.innerWidth - width - 12;
    if (top + height > window.innerHeight - 12) top = window.innerHeight - height - 12;

    dropdown.style.left = `${left}px`;
    dropdown.style.top = `${top}px`;
    dropdown.style.right = 'auto';
    dropdown.style.visibility = 'visible';
}

// بارگذاری درخواست‌ها هنگام لود صفحه
document.addEventListener("DOMContentLoaded", loadLeaveRequests);

document.addEventListener("DOMContentLoaded", function() {
    const vacationTable = document.querySelector('.vacation-table');
    if (vacationTable) {
        const cells = vacationTable.querySelectorAll('td');
        cells.forEach(cell => {
            cell.textContent = convertToPersianNumbers(cell.textContent);
        });
    }
});

// تبدیل اعداد انگلیسی به فارسی
function convertToPersianNumbers(input) {
    const englishToPersianMap = {
        '0': '۰',
        '1': '۱',
        '2': '۲',
        '3': '۳',
        '4': '۴',
        '5': '۵',
        '6': '۶',
        '7': '۷',
        '8': '۸',
        '9': '۹'
    };

    return input.replace(/[0-9]/g, (digit) => englishToPersianMap[digit]);
}

// نظارت بر تغییرات در فیلدهای تاریخ
document.getElementById('fromDate').addEventListener('input', function (e) {
    this.value = convertToPersianNumbers(this.value);
});

document.getElementById('toDate').addEventListener('input', function (e) {
    this.value = convertToPersianNumbers(this.value);
});

function convertToFarsiNumbers(text) {
    // اطمینان از اینکه ورودی یک رشته است
    if (typeof text !== 'string') {
        text = String(text);  // اگر ورودی رشته نیست، آن را به رشته تبدیل می‌کنیم
    }

    // تبدیل اعداد انگلیسی به فارسی
    var persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return text.replace(/\d/g, function(match) {
        return persianNumbers[parseInt(match)];
    });
}

//تابع برای تهیه گزارش مرخصی انفرادی//تابع برای تهیه گزارش مرخصی انفرادی//تابع برای تهیه گزارش مرخصی انفرادی
//تابع برای تهیه گزارش مرخصی انفرادی//تابع برای تهیه گزارش مرخصی انفرادی//تابع برای تهیه گزارش مرخصی انفرادی
//تابع برای تهیه گزارش مرخصی انفرادی//تابع برای تهیه گزارش مرخصی انفرادی//تابع برای تهیه گزارش مرخصی انفرادی

document.getElementById('generategozareshmrkReportBtn').addEventListener('click', function () {
    const user = document.getElementById('userSelect').value;
    const fromDate = document.getElementById('fromDate').value;
    const toDate = document.getElementById('toDate').value;

    if (!fromDate || !toDate) {
        showSystemError('لطفاً همه فیلدها را پر کنید');
        return;
    }

    let userSelection = user;
    if (userSelection === 'all') {
        userSelection = '';
    }

    fetch('/generate_individual_report', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user: userSelection,
            fromDate: fromDate,
            toDate: toDate,
        }),
    })
    .then((response) => response.json())
    .then((data) => {
        if (data.success) {
            const tableBody = document.querySelector('.individual-report-table tbody');
            tableBody.innerHTML = '';
            let rowIndex = 1;

            data.reports.forEach((report) => {
                const row = document.createElement('tr');
                row.id = `row_${report.id}`;
                row.innerHTML = `
                    <td>
                        <button class="update-button" onclick="applyStatusChange(${report.id})">تایید تغییرات</button>
                    </td>
                    <td>
                        <div class="status-container">
                            <div class="status-navbar" id="statusNavbar_${report.id}" onclick="toggleDropdown(${report.id})">
                                ${convertToFarsiNumbers(report.status || 'انتظار تایید')}
                            </div>
                            <div class="status-dropdown" id="statusDropdown_${report.id}" style="display: none;">
                                <div class="status-option approved" onclick="changeStatus(${report.id}, 'تایید شده')">تایید شده</div>
                                <div class="status-option rejected" onclick="changeStatus(${report.id}, 'رد شده')">رد شده</div>
                                <div class="status-option cancelled" onclick="changeStatus(${report.id}, 'انصراف')">انصراف</div>
                            </div>
                        </div>
                    </td>
                    <td>${esc(convertToFarsiNumbers(report.substitute || 'ندارد'))}</td>
                    <td>${convertToFarsiNumbers(report.days)}</td>
                    <td>${convertToFarsiNumbers(report.end_date)}</td>
                    <td>${convertToFarsiNumbers(report.start_date)}</td>
                    <td>${esc(report.username ? report.username : 'همه کاربران')}</td>
                    <td>${convertToFarsiNumbers(rowIndex)}</td>
                `;
                tableBody.appendChild(row);
                rowIndex++;
                changeStatus(report.id, report.status);
            });

            document.getElementById('vacationReportResult').style.display = 'block';
            document.getElementById('downloadReportBtn').style.display = 'inline-flex';
        } else {
            showSystemError(data.message || 'خطا در دریافت گزارش');
        }
    })
    .catch((error) => {
        console.error('Error:', error);
        showSystemError('خطا در برقراری ارتباط');
    });
});

// تابع جدید برای تایید تغییرات و ارسال به سرور
function applyStatusChange(requestId) {
    const statusNavbar = document.querySelector(`#statusNavbar_${requestId}`);
    const currentStatus = statusNavbar.textContent.trim(); // وضعیت فعلی که در خانه جدول نمایش داده می‌شود

    // بررسی اینکه وضعیت "انتظار تایید" نباشد
    if (currentStatus === 'انتظار تایید') {
        showSystemError('درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.');
        return; // متوقف کردن ادامه عملیات
    }

    // ارسال وضعیت فعلی به سرور
    fetch('/update_leave_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ requestId, status: currentStatus })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('وضعیت با موفقیت تغییر کرد!');
        } else {
            showSystemError('خطا در به‌روزرسانی وضعیت!');
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
        showSystemError('خطا در به‌روزرسانی وضعیت!');
    });
}

// تابع تغییر وضعیت و رنگ navbar هنگام کلیک
function changeStatus(requestId, newStatus) {
    const statusNavbar = document.querySelector(`#statusNavbar_${requestId}`);
    statusNavbar.textContent = convertToFarsiNumbers(newStatus);

    // تغییر کلاس navbar بر اساس وضعیت جدید
    if (newStatus === 'تایید شده') {
        statusNavbar.className = 'status-navbar approved';
    } else if (newStatus === 'رد شده') {
        statusNavbar.className = 'status-navbar rejected';
    } else if (newStatus === 'انتظار تایید') {
        statusNavbar.className = 'status-navbar pending';
    } else if (newStatus === 'انصراف') {
        statusNavbar.className = 'status-navbar cancelled';
    }

    // تغییر وضعیت موقت تا تایید نهایی
    const statusDropdown = document.querySelector(`#statusDropdown_${requestId}`);
    statusDropdown.style.display = 'none'; // بستن منو پس از انتخاب
}

// تابع برای نمایش/مخفی کردن منوی وضعیت
function toggleDropdown(requestId) {
    const statusDropdown = document.querySelector(`#statusDropdown_${requestId}`);
    const allDropdowns = document.querySelectorAll('.status-dropdown');

    // بستن تمام dropdown ها
    allDropdowns.forEach(function(dropdown) {
        if (dropdown !== statusDropdown) {
            dropdown.style.display = 'none'; // بستن سایر dropdown ها
        }
    });

    // نمایش یا مخفی کردن منوی وضعیت مربوط به ردیف فعلی
    statusDropdown.style.display = statusDropdown.style.display === 'none' ? 'flex' : 'none';
}

// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش
// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش
// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش// تابع دکمه دریافت گزارش

document.getElementById('downloadReportBtn').addEventListener('click', function() {
    // دریافت داده‌های جدول
    const tableRows = document.querySelector('.individual-report-table tbody').rows;
    const reports = [];

    // ساخت آرایه‌ای از داده‌های جدول
    for (let row of tableRows) {
        const status = row.cells[1].innerText.trim();  // وضعیت (معمولاً در ستون دوم است)
        
        // فقط گزارش‌هایی که وضعیت‌شان تایید شده است را اضافه می‌کنیم
        if (status === 'تایید شده') {
            reports.push({
                substitute: row.cells[2].innerText,  // جانشین
                days: row.cells[3].innerText,       // تعداد روزها
                end_date: row.cells[4].innerText,   // تاریخ پایان
                start_date: row.cells[5].innerText, // تاریخ شروع
                row_number: row.cells[6].innerText  // شماره ردیف
            });
        }
    }

    // دریافت username از فرم یا نوار انتخاب کاربر
    const username = document.getElementById('userSelect').value;

    // انتقال داده‌ها و اطلاعات کاربر به صفحه جدید با استفاده از localStorage
    localStorage.setItem('reports', JSON.stringify(reports));
    localStorage.setItem('username', username);

    // باز کردن صفحه جدید در یک تب جدید
    window.open('/leave_report_page', '_blank');
});

// فارسی سازی اعداد درون جدول
function convertNumbersToPersian() {
    // انتخاب تمام سلول‌های جدول
    const tableCells = document.querySelectorAll('#userTable td, #userTable th');

    tableCells.forEach(cell => {
        // بررسی اینکه محتوا عدد است
        if (/\d/.test(cell.textContent)) {
            // تبدیل اعداد انگلیسی به فارسی
            cell.textContent = cell.textContent.replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
        }
    });
}

// اجرای تبدیل اعداد پس از بارگذاری صفحه
window.addEventListener('load', convertNumbersToPersian);

// نمایش مدال تایید حذف
function showConfirmDialog(username) {
    document.getElementById('confirmDeleteModal').style.display = "block";
    document.getElementById('confirmDeleteBtn').onclick = function() {
        deleteUser(username);
    };
}

// بستن مدال تایید حذف
function closeConfirmDialog() {
    document.getElementById('confirmDeleteModal').style.display = "none";
}

// حذف کاربر
function deleteUser(username) {
    var form = document.getElementById('form-' + username);
    form.submit(); // ارسال فرم حذف
}

// بستن مدال هنگام کلیک خارج از آن
// addEventListener به‌جای window.onclick — تا اسکریپت‌های تزریق‌شدهٔ شخص‌ثالث
// نتوانند با بازنویسی window.onclick این هندلر را غیرفعال کنند.
window.addEventListener('click', function(event) {
    if (event.target == document.getElementById('confirmDeleteModal')) {
        closeConfirmDialog();
    }
});

// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران
// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران
// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران// پاپ برای ویرایش اطلاعات کاربران

function openEditPopup(username, substitute, work_hours, department, employmentStatus, isActive, currentPassword) {
    // پاپ‌آپ را نمایش می‌دهیم
    document.getElementById('editPopup').style.display = 'flex';

    // نام کاربری قبلی برای پیدا کردن رکورد در زمان تغییر نام حفظ می‌شود
    document.getElementById('editOriginalUsername').value = username;

    // فیلدهای فرم را با اطلاعات کاربر پر می‌کنیم
    document.getElementById('editUsername').value = username;
    document.getElementById('editPassword').value = '';

    // نمایش رمز عبور فعلی (فقط خواندنی)
    var currentPasswordField = document.getElementById('editCurrentPassword');
    if (currentPasswordField) currentPasswordField.value = currentPassword || '—';

    var employmentStatusSelect = document.getElementById('editEmploymentStatus');
    if (employmentStatusSelect) employmentStatusSelect.value = employmentStatus || 'official';

    var isActiveSelect = document.getElementById('editIsActive');
    if (isActiveSelect) isActiveSelect.value = isActive || 'active';

    // انتخاب مقدار جانشین
    var substituteSelect = document.getElementById('editSubstitute');
    substituteSelect.value = substitute;  // مقدار جانشین را انتخاب می‌کنیم

    // انتخاب مقدار ساعت‌های کاری
    var workHoursSelect = document.getElementById('editWorkHours');
    // بررسی می‌کنیم که آیا مقدار ساعت‌های کاری با یکی از گزینه‌ها مطابقت دارد
    workHoursSelect.value = work_hours;  // مقدار ساعت‌های کاری را انتخاب می‌کنیم

    // انتخاب مقدار بخش فعالیت
    var departmentSelect = document.getElementById('editDepartment');
    departmentSelect.value = department;  // مقدار انتخابی را به مقدار department تغییر می‌دهیم
}

function openEditPopupFromData(btn) {
    openEditPopup(
        btn.getAttribute('data-edit-username'),
        btn.getAttribute('data-edit-substitute'),
        btn.getAttribute('data-edit-work-hours'),
        btn.getAttribute('data-edit-department'),
        btn.getAttribute('data-edit-employment'),
        btn.getAttribute('data-edit-active'),
        btn.getAttribute('data-edit-password')
    );
}

function closeEditPopup() {
    // پاپ‌آپ را مخفی می‌کنیم
    document.getElementById('editPopup').style.display = 'none';
}

function updateUser() {
    const currentUsername = document.getElementById("editOriginalUsername").value.trim();
    const username = document.getElementById("editUsername").value.trim();
    const password = document.getElementById("editPassword").value;
    const substitute = document.getElementById("editSubstitute").value;
    const work_hours = document.getElementById("editWorkHours").value;
    const department = document.getElementById("editDepartment").value;
    const employment_status = document.getElementById("editEmploymentStatus")?.value || "official";
    const is_active = document.getElementById("editIsActive")?.value || "active";

    if (!currentUsername || !username) {
        showSystemError("نام کاربری را وارد کنید.");
        return;
    }

    fetch("/update_user", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            current_username: currentUsername,
            username: username,
            password: password,
            substitute: substitute,
            work_hours: work_hours,
            department: department,
            employment_status: employment_status,
            is_active: is_active
        })
    })
        .then(async response => {
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || "خطا در ثبت اطلاعات");
            }
            return result;
        })
        .then(() => {
            showSystemSuccess("اطلاعات با موفقیت ثبت شد.");
            location.reload();
        })
        .catch(error => {
            console.error(error);
            showSystemError(error.message || "خطا در ثبت اطلاعات.");
        });

}

function submitForm() {
    // ارسال فرم
    document.getElementById('newUserForm').submit();
}


// هنگام بارگذاری صفحه، انیمیشن باز شدن پیام را اجرا می‌کند
window.addEventListener('DOMContentLoaded', function() {
    var message = document.getElementById('successMessage');
    if (message) {
        setTimeout(function() {
            message.classList.add('show'); // نمایش پیام
            setTimeout(closeSuccessMessage, 5000); // بسته‌شدن خودکار پس از پایان تایم‌لاین
        }, 500); // پیام بعد از نیم ثانیه باز می‌شود
    }
});

// تابع جدول درخواست اضافه کاری کاربران// تابع جدول درخواست اضافه کاری کاربران// تابع جدول درخواست اضافه کاری کاربران
// تابع جدول درخواست اضافه کاری کاربران// تابع جدول درخواست اضافه کاری کاربران// تابع جدول درخواست اضافه کاری کاربران
// تابع جدول درخواست اضافه کاری کاربران// تابع جدول درخواست اضافه کاری کاربران// تابع جدول درخواست اضافه کاری کاربران

document.addEventListener("DOMContentLoaded", function() {
    loadOvertimeRequests();  // بارگذاری درخواست‌های اضافه‌کاری
});

// تغییر در بارگذاری داده‌ها
function loadOvertimeRequests() {
    fetch('/get_overtime_requests')
        .then(response => response.json())
        .then(data => {
            const tableBody = document.querySelector('#overTimeRequestTable tbody');
            tableBody.innerHTML = ''; 

            // فیلتر کردن درخواست‌ها به‌طوری که فقط وضعیت "انتظار تایید" نمایش داده شود
            const pendingRequests = data.filter(request => request.status === 'انتظار تایید');

            pendingRequests.forEach((request, index) => {  // اضافه کردن شمارنده index
                const row = document.createElement('tr');
                const rowId = request.id; // استفاده از شناسه یکتا

                let statusClass = '';
                if (request.status === 'انتظار تایید') {
                    statusClass = 'pending-status';
                }

                row.innerHTML = `
                    <td>${esc(convertToPersianNumbers(request.username))}</td>
                    <td>${convertToPersianNumbers(request.overtime_date)}</td>
                    <td>${convertToPersianNumbers(request.daily_overtime)}</td>
                    <td>${esc(convertToPersianNumbers(request.description))}</td>
                    <td>
                        <div class="status-container">
                            <div class="status-navbar ${statusClass}" id="statusNavbar_${rowId}" onclick="toggleRequestDropdown(${rowId})">
                                ${convertToPersianNumbers(request.status)}
                            </div>
                            <div class="status-dropdown" id="requestStatusDropdown_${rowId}" style="display: none;">
                                <div class="status-option approved" onclick="changeStatusForApproval(${rowId}, 'تایید شده')">تایید شده</div>
                                <div class="status-option rejected" onclick="changeStatusForApproval(${rowId}, 'رد شده')">رد شده</div>
                                <div class="status-option cancelled" onclick="changeStatusForApproval(${rowId}, 'انصراف')">انصراف</div>
                            </div>
                        </div>
                    </td>
                    <td><button class="update-button" onclick="applyStatusChangeForApproval(${rowId})">ثبت تغییرات</button></td>
                    <td style="display: none;">${request.id}</td>
                `;

                tableBody.appendChild(row);
            });

            document.addEventListener('click', function (event) {
                const dropdowns = document.querySelectorAll('.status-dropdown');
                dropdowns.forEach(dropdown => {
                    if (!dropdown.contains(event.target) && !event.target.matches('.status-navbar')) {
                        dropdown.style.display = 'none';
                    }
                });
            });
        })
        .catch(error => {
            console.error('Error fetching overtime requests:', error);
        });
}

// تغییرات وضعیت و انتخاب گزینه
function toggleRequestDropdown(rowId) {
    const statusDropdown = document.querySelector(`#requestStatusDropdown_${rowId}`);
    const allDropdowns = document.querySelectorAll('.status-dropdown');

    allDropdowns.forEach(function(dropdown) {
        if (dropdown !== statusDropdown) {
            dropdown.style.display = 'none';
        }
    });

    statusDropdown.style.display = statusDropdown.style.display === 'none' ? 'flex' : 'none';
}

// تغییر وضعیت برای ردیف خاص
function changeStatusForApproval(rowId, newStatus) {
    const statusNavbar = document.querySelector(`#statusNavbar_${rowId}`);
    statusNavbar.textContent = convertToPersianNumbers(newStatus);

    let statusClass = '';
    if (newStatus === 'تایید شده') {
        statusClass = 'approved-status';
    } else if (newStatus === 'رد شده') {
        statusClass = 'rejected-status';
    } else if (newStatus === 'انصراف') {
        statusClass = 'cancelled-status';
    }

    statusNavbar.className = 'status-navbar ' + statusClass;

    const statusDropdown = document.querySelector(`#requestStatusDropdown_${rowId}`);
    statusDropdown.style.display = 'none';
}

// تابع تایید تغییرات وضعیت و ارسال به سرور
function applyStatusChangeForApproval(requestId) {
    const statusNavbar = document.querySelector(`#statusNavbar_${requestId}`);
    const currentStatus = statusNavbar.textContent.trim(); // وضعیت فعلی که در خانه جدول نمایش داده می‌شود

    // بررسی اینکه وضعیت "انتظار تایید" نباشد
    if (currentStatus === 'انتظار تایید') {
        showSystemError('درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.');
        return; // متوقف کردن ادامه عملیات
    }

    // دریافت id از سلول مخفی
    const row = document.querySelector(`#statusNavbar_${requestId}`).closest('tr');
    const requestIdFromRow = row.querySelector('td:nth-child(7)').textContent.trim(); // گرفتن id از سلول مخفی

    // ارسال وضعیت فعلی به سرور
    fetch('/update_overtime_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            requestId: requestIdFromRow,
            status: currentStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('وضعیت با موفقیت تغییر کرد!');
            
            // حذف ردیف از جدول پس از ثبت تغییرات
            row.remove(); // حذف ردیف
        } else {
            showSystemError('خطا در به‌روزرسانی وضعیت!');
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
        showSystemError('خطا در به‌روزرسانی وضعیت!');
    });
}

// تابع تهیه گزارش اضافه کار انفرادی// تابع تهیه گزارش اضافه کار انفرادی// تابع تهیه گزارش اضافه کار انفرادی
// تابع تهیه گزارش اضافه کار انفرادی// تابع تهیه گزارش اضافه کار انفرادی// تابع تهیه گزارش اضافه کار انفرادی
// تابع تهیه گزارش اضافه کار انفرادی// تابع تهیه گزارش اضافه کار انفرادی// تابع تهیه گزارش اضافه کار انفرادی

let filteredData = []; // متغیر برای ذخیره داده‌های فیلتر شده

document.getElementById("submitReport").addEventListener("click", function() {
    const username = document.getElementById("usernameEzafeReport").value;
    const startDate = document.getElementById("start_date").value;
    const endDate = document.getElementById("end_date").value;

    if (!username || !startDate || !endDate) {
        showSystemError("لطفاً تمام فیلدها را پر کنید.");
        return;
    }

    fetch('/get_overtime_report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            username: username,
            start_date: startDate,
            end_date: endDate
        })
    })
    .then(response => response.json())
    .then(data => {
        const tbody = document.getElementById("overTimeIndivisualReportTable").getElementsByTagName('tbody')[0];
        tbody.innerHTML = '';

        filteredData = [];
        let rowNumber = 1; // شماره ردیف از 1 شروع می‌شود
        data.forEach((row, index) => {
            if (['تایید شده', 'رد شده', 'انصراف'].includes(row.status)) {
                const newRow = tbody.insertRow();
                newRow.innerHTML = `
                    <td style="display:none;">${row.id}</td>
                    <td><button class="confirm-changes-btn" onclick="confirmChanges(${rowNumber}, ${row.id})">تایید تغییرات</button></td>
                    <td>
                        <div class="status-container">
                            <div class="status-navbar ${getStatusClass(row.status)}" id="statusNavbar_${rowNumber}" onclick="toggleDropdown(${rowNumber})">
                                ${convertToPersianNumbers(row.status)}
                            </div>
                            <div class="status-dropdown" id="statusDropdown_${rowNumber}" style="display: none;">
                                <div class="status-option approved" onclick="changeEzafeStatusForApproval(${rowNumber}, 'تایید شده')">تایید شده</div>
                                <div class="status-option rejected" onclick="changeEzafeStatusForApproval(${rowNumber}, 'رد شده')">رد شده</div>
                                <div class="status-option cancelled" onclick="changeEzafeStatusForApproval(${rowNumber}, 'انصراف')">انصراف</div>
                            </div>
                        </div>
                    </td>
                    <td>${esc(convertToPersianNumbers(row.description))}</td>
                    <td>${convertToPersianNumbers(row.daily_overtime)}</td>
                    <td>${convertToPersianNumbers(row.overtime_date)}</td>
                    <td>${esc(convertToPersianNumbers(row.username))}</td>
                    <td>${convertToPersianNumbers(rowNumber)}</td>
                `;
                filteredData.push(row);
                rowNumber++; // شماره ردیف را افزایش می‌دهیم
            }
        });

        document.getElementById('overtimeReportResult').style.display = 'block';
        document.getElementById('downloadOvertimeReport').style.display = 'inline-flex';

    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError("خطا در دریافت داده‌ها.");
    });

});

// تابع دکمه دریافت گزارش برای صفحه جدید
document.getElementById("downloadOvertimeReport").addEventListener("click", function () {
    // پاک کردن داده‌های قبلی localStorage
    localStorage.removeItem("overtimeReports");
    localStorage.removeItem("username");

    const username = document.getElementById("usernameEzafeReport").value;

    // جلوگیری از ذخیره "همه کاربران" یا مقدار خالی
    if (!username || username === "all_users") {
        showSystemError("لطفاً یک کاربر خاص انتخاب کنید.");
        return;
    }

    const reports = [];

    // فقط موارد تایید شده رو اضافه کن
    filteredData.forEach(row => {
        if (row.status === 'تایید شده') {
            reports.push({
                description: row.description,
                daily_overtime: row.daily_overtime,
                overtime_date: row.overtime_date,
                username: row.username
            });
        }
    });

    // ذخیره داده‌ها در localStorage
    localStorage.setItem("overtimeReports", JSON.stringify(reports));
    localStorage.setItem("username", username);

    console.log("کاربر انتخاب‌شده:", username);

    // باز کردن صفحه گزارش
    window.open('/overtime_report_page', '_blank');
});



// تابع تایید تغییرات که وضعیت را در دیتابیس تغییر می‌دهد
function confirmChanges(rowNumber, id) {
    const newStatus = document.getElementById('statusNavbar_' + rowNumber).innerText; // وضعیت جدید از صفحه گرفته می‌شود
    
    // ارسال وضعیت جدید به سرور
    fetch('/update_overtime_Indivisual_status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: id,
            status: newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess("وضعیت با موفقیت تغییر کرد.");
        } else {
            showSystemError("خطا در تغییر وضعیت.");
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError("خطا در ارسال درخواست.");
    });
}

// تابع تغییر وضعیت برای گزارش‌های اضافه‌کاری فردی
function changeEzafeStatusForApproval(index, status) {
    const statusNavbar = document.getElementById('statusNavbar_' + index);
    statusNavbar.innerText = convertToPersianNumbers(status);
    statusNavbar.className = 'status-navbar ' + getStatusClass(status);
    
    const currentDropdown = document.getElementById('statusDropdown_' + index);
    currentDropdown.style.display = 'none';
}

// تابع برای باز و بسته کردن منوی وضعیت‌ها
function toggleDropdown(index) {
    const allDropdowns = document.querySelectorAll('.status-dropdown');
    allDropdowns.forEach(dropdown => dropdown.style.display = 'none');
    
    const currentDropdown = document.getElementById('statusDropdown_' + index);
    if (currentDropdown.style.display === 'none' || currentDropdown.style.display === '') {
        currentDropdown.style.display = 'flex';
    } else {
        currentDropdown.style.display = 'none';
    }
}

// تابع برای دریافت کلاس مناسب برای وضعیت
function getStatusClass(status) {
    switch (status) {
        case 'تایید شده':
            return 'approved-status';
        case 'رد شده':
            return 'rejected-status';
        case 'انصراف':
            return 'cancelled-status';
        default:
            return 'pending-status';
    }
}

function convertToPersianNumbers(input) {
    if (typeof input !== 'string') {
        input = input.toString(); // تبدیل ورودی به رشته
    }
    return input.replace(/[0-9]/g, function(d) {
        return String.fromCharCode(d.charCodeAt(0) + 1728); // تبدیل اعداد لاتین به فارسی
    });
}

/* جدول مدیریت درخواست پاس های ساعتی کاربران *//* جدول مدیریت درخواست پاس های ساعتی کاربران *//* جدول مدیریت درخواست پاس های ساعتی کاربران */
/* جدول مدیریت درخواست پاس های ساعتی کاربران *//* جدول مدیریت درخواست پاس های ساعتی کاربران *//* جدول مدیریت درخواست پاس های ساعتی کاربران */
/* جدول مدیریت درخواست پاس های ساعتی کاربران *//* جدول مدیریت درخواست پاس های ساعتی کاربران *//* جدول مدیریت درخواست پاس های ساعتی کاربران */

function formatTimeToHourMinute(time) {
    const timeParts = time.split(':'); // جدا کردن ساعت، دقیقه و ثانیه
    return `${timeParts[0]}:${timeParts[1]}`; // فقط ساعت و دقیقه را برمی‌گرداند
}

// فراخوانی این تابع بعد از بارگذاری صفحه یا در هنگام لود شدن بخشی از اپلیکیشن
loadHourlyPassRequests();
function loadHourlyPassRequests() {
    fetch('/get_hourly_pass_requests')  // درخواست به سرور
        .then(response => response.json())
        .then(data => {
            const tableBody = document.querySelector('#hourlyPassReportTable tbody');
            tableBody.innerHTML = ''; 

            // نمایش داده‌ها در جدول
            data.forEach((request, index) => {
                const row = document.createElement('tr');
                const rowId = request.id;

                // انتخاب کلاس برای هر وضعیت
                let statusClass = getHourlyStatusClass(request.status);

                row.innerHTML = `
                    <td>${esc(convertToPersianNumbers(request.username))}</td>
                    <td>${convertToPersianNumbers(request.request_date)}</td>
                    <td>${esc(convertToPersianNumbers(request.pass_title))}</td>
                    <td>${convertToPersianNumbers(formatTimeToHourMinute(request.pass_duration))}</td>
                    <td>
                        <div class="status-container">
                            <div class="status-navbar ${statusClass}" id="statusNavbarHourly_${rowId}" onclick="toggleRequestHourlypassDropdown(${rowId})">
                                ${convertToPersianNumbers(request.status)}
                            </div>
                            <div class="status-dropdown" id="requestStatusDropdown_${rowId}" style="display: none;">
                                <div class="status-option approved" onclick="changeHourlyPassStatus(${rowId}, 'تایید شده')">تایید شده</div>
                                <div class="status-option rejected" onclick="changeHourlyPassStatus(${rowId}, 'رد شده')">رد شده</div>
                                <div class="status-option cancelled" onclick="changeHourlyPassStatus(${rowId}, 'انصراف')">انصراف</div>
                            </div>
                        </div>
                    </td>
                    <td><button class="update-button" onclick="applyStatusChangeForHourlyPass(${rowId})">ثبت تغییرات</button></td>
                    <td style="display: none;">${request.id}</td>
                `;

                tableBody.appendChild(row);
            });

            document.addEventListener('click', function (event) {
                const dropdowns = document.querySelectorAll('.status-dropdown');
                dropdowns.forEach(dropdown => {
                    if (!dropdown.contains(event.target) && !event.target.matches('.status-navbar')) {
                        dropdown.style.display = 'none';
                    }
                });
            });
        })
        .catch(error => {
            console.error('Error fetching hourly pass requests:', error);
        });
}

// تغییر وضعیت و باز و بسته کردن منوی کشویی
function toggleRequestHourlypassDropdown(rowId) {
    const statusNavbar = document.querySelector(`#statusNavbarHourly_${rowId}`);
    const currentDropdown = document.querySelector(`#requestStatusDropdown_${rowId}`);

    // بستن همه منوهای کشویی
    const allHourlyDropdowns = document.querySelectorAll('.status-dropdown');
    allHourlyDropdowns.forEach(dropdown => dropdown.style.display = 'none');

    // باز و بسته کردن منوی کشویی مربوطه
    if (currentDropdown.style.display === 'none' || currentDropdown.style.display === '') {
        currentDropdown.style.display = 'flex';  // باز کردن منوی کشویی
    } else {
        currentDropdown.style.display = 'none';  // بستن منوی کشویی
    }
}

// تابع برای انتخاب کلاس مناسب به‌ازای هر وضعیت
function getHourlyStatusClass(status) {
    switch (status) {
        case 'تایید شده':
            return 'approved-status';
        case 'رد شده':
            return 'rejected-status';
        case 'انصراف':
            return 'cancelled-status';
        default:
            return 'pending-status';  // وضعیت پیش‌فرض (انتظار تایید)
    }
}



// تابع تغییر وضعیت و رنگ navbar هنگام کلیک
function changeHourlyPassStatus(requestId, newStatus) {
    const statusNavbar = document.getElementById('statusNavbarHourly_' + requestId);
    statusNavbar.innerText = convertToPersianNumbers(newStatus);
    
    // تغییر کلاس navbar بر اساس وضعیت جدید
    statusNavbar.className = 'status-navbar ' + getHourlyStatusClass(newStatus);

    const currentDropdown = document.getElementById('requestStatusDropdown_' + requestId);
    currentDropdown.style.display = 'none';  // بستن منوی کشویی
}

// تابع برای ارسال تغییرات به سرور (برای اعمال تغییرات در دیتابیس)
function applyStatusChangeForHourlyPass(rowId) {
    const statusNavbar = document.querySelector(`#statusNavbarHourly_${rowId}`);
    const currentStatus = statusNavbar.textContent.trim(); // وضعیت فعلی که در خانه جدول نمایش داده می‌شود

    // بررسی اینکه وضعیت "انتظار تایید" نباشد
    if (currentStatus === 'انتظار تایید') {
        showSystemError('درخواست در وضعیت انتظار تایید است. تغییرات قابل ثبت نیستند.');
        return; // متوقف کردن ادامه عملیات
    }

    // دریافت id از سلول مخفی
    const row = document.querySelector(`#statusNavbarHourly_${rowId}`).closest('tr');
    const requestIdFromRow = row.querySelector('td:nth-child(7)').textContent.trim(); // گرفتن id از سلول مخفی

    // ارسال وضعیت فعلی به سرور
    fetch('/change_hourly_pass_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            id: requestIdFromRow, // ارسال شناسه درخواست
            status: currentStatus // ارسال وضعیت فعلی
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('وضعیت با موفقیت تغییر کرد!');
            
            // بروزرسانی وضعیت نمایش داده‌شده در جدول
            statusNavbar.textContent = convertToPersianNumbers(currentStatus);  // تبدیل وضعیت به اعداد فارسی
    
            // تغییر کلاس‌های وضعیت (برای اعمال رنگ و استایل جدید)
            statusNavbar.className = `status-navbar ${getHourlyStatusClass(currentStatus)}`;
    
            // بستن منوی کشویی
            const statusDropdown = document.querySelector(`#requestStatusDropdown_${rowId}`);
            statusDropdown.style.display = 'none';
    
            // حذف ردیف از جدول پس از تایید
            const row = document.querySelector(`#statusNavbarHourly_${rowId}`).closest('tr');
            row.remove();  // حذف ردیف از جدول
        } else {
            showSystemError('خطا در به‌روزرسانی وضعیت!');
        }
    })
    
    .catch(error => {
        console.error('Error updating status:', error);
        showSystemError('خطا در به‌روزرسانی وضعیت!');
    });
}

/* تابع برای فارسی کردن اعداد جدول گزارش کلی پاس های ساعتی کاربران *//* تابع برای فارسی کردن اعداد جدول گزارش کلی پاس های ساعتی کاربران */
/* تابع برای فارسی کردن اعداد جدول گزارش کلی پاس های ساعتی کاربران *//* تابع برای فارسی کردن اعداد جدول گزارش کلی پاس های ساعتی کاربران */
/* تابع برای فارسی کردن اعداد جدول گزارش کلی پاس های ساعتی کاربران *//* تابع برای فارسی کردن اعداد جدول گزارش کلی پاس های ساعتی کاربران */

function toPersianNumber(number) {
    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return number.toString().replace(/\d/g, function(digit) {
        return persianDigits[parseInt(digit)];
    });
}

// استفاده در جدول‌ها و تبدیل اعداد
function convertTableNumbers() {
    const cells = document.querySelectorAll('td, th');
    cells.forEach(cell => {
        if (cell.innerText.match(/\d/)) {
            cell.innerText = toPersianNumber(cell.innerText);
        }
    });
}

// تبدیل اعداد در زمان بارگذاری صفحه
document.addEventListener('DOMContentLoaded', function() {
    convertTableNumbers();
});

// تابع تبدیل اعداد انگلیسی به فارسی (تعریف‌شده توسط شما)
function toPersianNumber(number) {
    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return number.toString().replace(/\d/g, function(digit) {
        return persianDigits[parseInt(digit)];
    });
}

// رویداد تبدیل اعداد هنگام تایپ
function convertInputToPersian(event) {
    event.target.value = toPersianNumber(event.target.value);
}

// اعمال تبدیل به فیلدهای ورودی
document.getElementById("start_date_hourlypass").addEventListener("input", convertInputToPersian);
document.getElementById("end_date_hourlypass").addEventListener("input", convertInputToPersian);

/* تابع جدول گزارش انفرادی پاس های ساعتی کاربران *//* تابع جدول گزارش انفرادی پاس های ساعتی کاربران */
/* تابع جدول گزارش انفرادی پاس های ساعتی کاربران *//* تابع جدول گزارش انفرادی پاس های ساعتی کاربران */
/* تابع جدول گزارش انفرادی پاس های ساعتی کاربران *//* تابع جدول گزارش انفرادی پاس های ساعتی کاربران */

document.getElementById("submitHourlyPassReport").addEventListener("click", function() {
    const username = document.getElementById("usernameHourlypass").value;
    const startDate = document.getElementById("start_date_hourlypass").value;
    const endDate = document.getElementById("end_date_hourlypass").value;

    if (!username || !startDate || !endDate) {
        showSystemError("لطفاً تمام فیلدها را پر کنید.");
        return;
    }

    fetch('/get_hourly_pass_report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            username: username,
            start_date: startDate,
            end_date: endDate
        })
    })
    .then(response => response.json())
    .then(data => {
    var resultContainer = document.getElementById("hourlyPassReportResult");
    if (resultContainer) resultContainer.style.display = "block";

    const tbody = document.getElementById("hourlyPassIndivisualuserReportTable").getElementsByTagName('tbody')[0];
    tbody.innerHTML = ''; // خالی کردن جدول قبل از پر کردن

    // همیشه دکمه نمایش داده شود
    document.getElementById("downloadHourlyPassReport").style.display = "block";

    data.forEach((row) => {
        const statusClass = getHourlyPassIndivisualStatusClass(row.status); // دریافت کلاس وضعیت
        const newRow = tbody.insertRow();
        newRow.innerHTML = `
            <td style="display:none;">${row.id}</td>
            <td>
                <button class="update-button" data-id="${row.id}" onclick="confirmChangesPass(${row.id})">تأیید تغییرات</button>
            </td>
            <td>
                <div class="status-container">
                    <div class="status-navbar ${statusClass}" id="statusHourlyPassNavbar_${row.id}" onclick="toggleRequestHourlypassDropdownReport(${row.id})">
                        ${convertToPersianNumbers(row.status)}
                    </div>
                    <div class="status-dropdown" id="requestHourlyPassIndiStatusDropdown_${row.id}" style="display: none;">
                        <div class="status-option approved" onclick="changeStatuHourlyPassIndiForApproval(${row.id}, 'تایید شده')">تایید شده</div>
                        <div class="status-option rejected" onclick="changeStatuHourlyPassIndiForApproval(${row.id}, 'رد شده')">رد شده</div>
                        <div class="status-option cancelled" onclick="changeStatuHourlyPassIndiForApproval(${row.id}, 'انصراف')">انصراف</div>
                    </div>
                </div>
            </td>
            <td>${convertToPersianNumbers(row.pass_duration)}</td>
            <td>${esc(convertToPersianNumbers(row.pass_title))}</td>
            <td>${convertToPersianNumbers(row.request_date)}</td>
            <td>${esc(convertToPersianNumbers(row.username))}</td>
        `;
    });
})

    .catch(error => {
        console.error('Error:', error);
        showSystemError("خطا در دریافت داده‌ها.");
    });
});

// هدایت کاربر به صفحه گزارش هنگام کلیک روی دکمه
document.getElementById("downloadHourlyPassReport").addEventListener("click", function () {
    const tableRows = Array.from(document.querySelectorAll("#hourlyPassIndivisualuserReportTable tbody tr"));

    const reportData = tableRows
        .filter(row => {
            const statusElement = row.querySelector(".status-navbar");
            if (!statusElement) return false;
            const statusText = statusElement.textContent.trim();
            return statusText === 'تاييد شده';
        })
        .map((row, index) => {
            const requestDate = row.cells[5].textContent.trim();
            const passTitle = row.cells[4].textContent.trim();
            const passDuration = row.cells[3].textContent.trim();
            return { index: index + 1, requestDate, passTitle, passDuration };
        });

    const username = document.getElementById("usernameHourlypass")?.value || '';

    console.log("اطلاعات گزارش پاس ساعتی (فقط تایید شده):", reportData);
    console.log("یوزرنیم انتخاب‌شده:", username);

    localStorage.setItem("hourlyPassReportData", JSON.stringify(reportData));
    localStorage.setItem("hourlyPassUsername", username);

    window.open("/hourlypass_Report_page", "_blank");
});







// تابع برای باز کردن منوی کشویی وضعیت
function toggleRequestHourlypassDropdownReport(rowId) {
    const statusNavbar = document.querySelector(`#statusHourlyPassNavbar_${rowId}`);
    const currentDropdown = document.querySelector(`#requestHourlyPassIndiStatusDropdown_${rowId}`);

    // بستن همه منوهای کشویی
    const allHourlyDropdowns = document.querySelectorAll('.status-dropdown');
    allHourlyDropdowns.forEach(dropdown => dropdown.style.display = 'none');

    // باز و بسته کردن منوی کشویی مربوطه
    if (currentDropdown.style.display === 'none' || currentDropdown.style.display === '') {
        currentDropdown.style.display = 'flex';
    } else {
        currentDropdown.style.display = 'none';
    }
}




// تنظیم اولیه برای پنهان کردن دکمه
document.getElementById("downloadHourlyPassReport").style.display = "none";

// تابع برای تعیین کلاس وضعیت بر اساس وضعیت
function getHourlyPassIndivisualStatusClass(status) {
    // نرمال‌سازی مقدار وضعیت
    const normalizedStatus = status.trim().replace(/\s+/g, ' '); // حذف فاصله‌های اضافی
    switch (normalizedStatus) {
        case 'تایید شده': // وضعیت نرمال‌شده
        case 'تاييد شده': // تطابق با وضعیت متفاوت
            return 'approved-status';
        case 'رد شده':
            return 'rejected-status';
        case 'انصراف':
            return 'cancelled-status';
        default:
            console.warn(`Unknown status: ${status}`); // هشدار برای وضعیت ناشناخته
            return 'unknown-status'; // مقدار پیش‌فرض برای وضعیت‌های ناشناخته
    }
}

// تابع برای تغییر وضعیت درخواست (بدون ارسال به سرور)
function changeStatuHourlyPassIndiForApproval(rowId, status) {
    const statusNavbar = document.getElementById(`statusHourlyPassNavbar_${rowId}`);
    if (!statusNavbar) {
        console.error(`Element with id 'statusHourlyPassNavbar_${rowId}' not found.`);
        return;
    }

    statusNavbar.innerHTML = convertToPersianNumbers(status);

    const statusClass = getHourlyPassIndivisualStatusClass(status);
    statusNavbar.className = `status-navbar ${statusClass}`;

    const dropdown = document.getElementById(`requestHourlyPassIndiStatusDropdown_${rowId}`);
    dropdown.style.display = 'none';
}

// تابع برای تأیید تغییرات و ارسال به سرور
function confirmChangesPass(rowId) {
    const statusNavbar = document.getElementById(`statusHourlyPassNavbar_${rowId}`);
    
    if (!statusNavbar) {
        console.error(`Element with id 'statusHourlyPassNavbar_${rowId}' not found.`);
        return;
    }

    const status = statusNavbar.innerText.trim(); // استفاده از innerText به جای innerHTML برای دریافت محتوای متنی

    console.log(`Confirming changes for row ${rowId} with status: ${status}`);

    fetch('/update_hourly_pass_status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: rowId,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess("وضعیت با موفقیت تغییر کرد.");
        } else {
            showSystemError("خطا در تغییر وضعیت.");
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError("خطا در ارسال درخواست.");
    });
}

// تنظیمات باکس مدیریت درخواست های تیکت کاربران// تنظیمات باکس مدیریت درخواست های تیکت کاربران// تنظیمات باکس مدیریت درخواست های تیکت کاربران
// تنظیمات باکس مدیریت درخواست های تیکت کاربران// تنظیمات باکس مدیریت درخواست های تیکت کاربران// تنظیمات باکس مدیریت درخواست های تیکت کاربران
// تنظیمات باکس مدیریت درخواست های تیکت کاربران// تنظیمات باکس مدیریت درخواست های تیکت کاربران// تنظیمات باکس مدیریت درخواست های تیکت کاربران

// بارگذاری مرکز جدید توسط ticketing.js انجام می‌شود؛ تابع قدیمی زیر فقط برای
// سازگاری با کدهای قدیمی نگه داشته شده و دیگر در رابط جدید فراخوانی نمی‌شود.
function loadTicketRequests() {
    fetch('/get_ticket_requests_admin')
        .then(async response => {
            const data = await response.json();
            if (!response.ok || !Array.isArray(data)) {
                throw new Error(data.error || 'خطا در دریافت تیکت‌ها');
            }
            return data;
        })
        .then(data => {
            const tableBody = document.querySelector('#ticketUsersReportTable tbody');
            if (!tableBody) return;
            tableBody.replaceChildren();

            const statusClasses = {
                'ارسال شده': 'status-sent',
                'در حال پیگیری': 'status-following',
                'خوانده شده': 'status-read',
                'پاسخ داده شده': 'status-answered'
            };
            const pendingCount = data.filter(ticket => ['ارسال شده', 'در حال پیگیری'].includes(String(ticket.ticket_status || '').trim())).length;
            const answeredCount = data.filter(ticket => String(ticket.ticket_status || '').trim() === 'پاسخ داده شده').length;
            const unreadCount = data.filter(ticket => !ticket.is_read).length;
            const setMetric = (id, value) => {
                const element = document.getElementById(id);
                if (element) element.textContent = value.toLocaleString('fa-IR');
            };
            setMetric('adminTicketTotal', data.length);
            setMetric('adminTicketPending', pendingCount);
            setMetric('adminTicketAnswered', answeredCount);
            setMetric('adminTicketUnread', unreadCount);

            if (!data.length) {
                const emptyRow = document.createElement('tr');
                const emptyCell = document.createElement('td');
                emptyCell.colSpan = 6;
                emptyCell.className = 'ticket-empty-state';
                emptyCell.textContent = 'هنوز مکالمه‌ای ثبت نشده است.';
                emptyRow.appendChild(emptyCell);
                tableBody.appendChild(emptyRow);
                return;
            }

            data.forEach(ticket => {
                const row = document.createElement('tr');
                row.dataset.ticketId = ticket.id;
                const rowId = Number(ticket.id);
                const ticketStatus = String(ticket.ticket_status || 'ارسال شده').trim();
                const appendTextCell = (value) => {
                    const cell = document.createElement('td');
                    cell.textContent = value == null ? '—' : String(value);
                    return cell;
                };
                const createAction = (className, image, label, handler) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = className;
                    button.setAttribute('aria-label', label);
                    const icon = document.createElement('img');
                    icon.src = image;
                    icon.alt = '';
                    const tooltip = document.createElement('span');
                    tooltip.className = 'tooltip-text-table-' + (className === 'trash-btn' ? 'del' : className === 'view-btn' ? 'view' : 'check');
                    tooltip.textContent = label;
                    button.append(icon, tooltip);
                    button.addEventListener('click', handler);
                    return button;
                };

                const actionsCell = document.createElement('td');
                const actions = document.createElement('div');
                actions.className = 'ticket-action-group';
                actions.append(
                    createAction('trash-btn', '/static/images/trash.png', 'حذف', () => openConfirmDialogTicket(rowId)),
                    createAction('view-btn', '/static/images/view.png', 'مشاهده', () => openViewDialog(rowId)),
                    createAction('check-btn', '/static/images/check-mark.png', 'ذخیره وضعیت', () => confirmTicketStatusChange(rowId))
                );
                actionsCell.appendChild(actions);
                row.appendChild(actionsCell);

                const statusCell = document.createElement('td');
                const statusContainer = document.createElement('div');
                statusContainer.className = 'status-container';
                const statusButton = document.createElement('button');
                statusButton.type = 'button';
                statusButton.className = `status-navbar ${statusClasses[ticketStatus] || ''}`;
                statusButton.id = `statusNavbar_${rowId}`;
                statusButton.textContent = ticketStatus;
                statusButton.addEventListener('click', () => toggleTicketDropdown(rowId));
                const dropdown = document.createElement('div');
                dropdown.className = 'status-dropdown';
                dropdown.id = `ticketStatusDropdown_${rowId}`;
                dropdown.style.display = 'none';
                ['در حال پیگیری', 'خوانده شده', 'پاسخ داده شده'].forEach(status => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'status-option';
                    option.textContent = status;
                    option.addEventListener('click', event => {
                        event.stopPropagation();
                        changeTicketStatus(rowId, status);
                    });
                    dropdown.appendChild(option);
                });
                statusContainer.append(statusButton, dropdown);
                statusCell.appendChild(statusContainer);
                row.appendChild(statusCell);
                row.appendChild(appendTextCell(ticket.ticketDescription));
                row.appendChild(appendTextCell(ticket.ticketTitle));
                row.appendChild(appendTextCell(toPersianDigits(ticket.ticket_date || '')));
                row.appendChild(appendTextCell(ticket.username));
                tableBody.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Error fetching ticket requests:', error);
            const tableBody = document.querySelector('#ticketUsersReportTable tbody');
            if (!tableBody) return;
            const emptyRow = document.createElement('tr');
            const emptyCell = document.createElement('td');
            emptyCell.colSpan = 6;
            emptyCell.className = 'ticket-empty-state ticket-empty-state--error';
            emptyCell.textContent = error.message || 'خطا در دریافت تیکت‌ها.';
            emptyRow.appendChild(emptyCell);
            tableBody.replaceChildren(emptyRow);
        });
}

// تابع برای باز و بسته کردن دراپ‌داون
function toggleTicketDropdown(rowId) {
    const dropdown = document.querySelector(`#ticketStatusDropdown_${rowId}`);
    dropdown.style.display = dropdown.style.display === 'none' ? 'flex' : 'none';
}

// تابع برای تغییر وضعیت
function changeTicketStatus(rowId, newStatus) {
    const statusNavbar = document.querySelector(`#statusNavbar_${rowId}`);

    // به‌روزرسانی متن وضعیت
    statusNavbar.textContent = newStatus;

    // حذف تمام کلاس‌های وضعیت
    statusNavbar.classList.remove('status-sent', 'status-following', 'status-read', 'status-answered');

    // اضافه کردن کلاس مرتبط با وضعیت جدید
    if (newStatus === 'ارسال شده') {
        statusNavbar.classList.add('status-sent');
    } else if (newStatus === 'در حال پیگیری') {
        statusNavbar.classList.add('status-following');
    } else if (newStatus === 'خوانده شده') {
        statusNavbar.classList.add('status-read');
    } else if (newStatus === 'پاسخ داده شده') {
        statusNavbar.classList.add('status-answered');
    }

    // بستن دراپ‌داون بعد از انتخاب
    const dropdown = document.querySelector(`#ticketStatusDropdown_${rowId}`);
    dropdown.style.display = 'none';
}

// تابع برای ارسال وضعیت جدید به سرور
function confirmTicketStatusChange(rowId) {
    const statusNavbar = document.querySelector(`#statusNavbar_${rowId}`);
    const newStatus = statusNavbar.textContent.trim();

    fetch('/update_ticket_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            id: rowId,
            ticket_status: newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess("وضعیت تیکت‌های مرتبط به‌روزرسانی شد.");
            loadTicketRequests();
        } else {
            showSystemError("خطایی رخ داد: " + data.error);
        }
    })
    .catch(error => {
        console.error("Error updating ticket status:", error);
    });
}

// تنمظیمات پاپ اپ حذف تیکت کاربر
function openConfirmDialogTicket(ticketId) {
    const modal = document.getElementById('confirmDeleteModalHazf');
    modal.style.display = 'block'; // نمایش باکس تایید

    // ذخیره `ticketId` برای استفاده در تایید
    const confirmBtn = document.getElementById('confirmDeleteBtnTicket');
    confirmBtn.onclick = function () {
        deleteTicket(ticketId); // فراخوانی تابع حذف
        closeConfirmDialogTicketKarbr(); // بستن باکس تایید پس از حذف
    };
}

function closeConfirmDialogTicketKarbr() {
    const modal = document.getElementById('confirmDeleteModalHazf');
    modal.style.display = 'none'; // بستن باکس تایید
}

// نمایش مدال تایید حذف و ذخیره شناسه تیکت انتخابی
function showTaaeidConfirmDialog(ticketId) {
    currentTicketId = parseInt(ticketId, 10);  // تبدیل به عدد صحیح
    if (isNaN(currentTicketId)) {
        console.error("Invalid ticket ID:", ticketId);  // چاپ شناسه نامعتبر
    }
    document.getElementById('confirmDeleteModalHazf').style.display = "block";
}

// بستن مدال تایید حذف
function closeConfirmDialogTicketKarbr() {document.getElementById('confirmDeleteModalHazf').style.display = "none";}

// تابع تبدیل اعداد انگلیسی به فارسی
function toPersianDigits(input) {
    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return input.replace(/\d/g, (digit) => persianDigits[digit]);
}

// تابع برای نمایش پاپ‌آپ
let ticketData = null;

function openViewDialog(ticketId) {
    // نمایش پاپ‌آپ
    document.querySelector('#popupMoshahedeoverlay').style.display = 'block';
    document.querySelector('#MoshahedePopupbox').style.display = 'block';

    // پاک کردن پیام‌های قبلی قبل از نمایش پیام‌های جدید
    const kadrMatnContainer = document.querySelector('#MoshahedePopupbox .kadr-matn');
    kadrMatnContainer.innerHTML = '';  // این خط باعث حذف پیام‌های قبلی می‌شود

    // درخواست به سرور برای دریافت جزئیات تیکت
    fetch(`/get_ticket_details/${ticketId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                showSystemError('خطایی در دریافت اطلاعات تیکت رخ داده است.');
                return;
            }

            // ذخیره اطلاعات تیکت در متغیر ticketData
            ticketData = data;

            // نمایش عنوان تیکت در بخش p
            document.querySelector('#ticketConversationTitle').textContent = data.ticketTitle;

            // مرتب‌سازی پیام‌ها بر اساس تاریخ
            const sortedMessages = data.messages.sort((a, b) => new Date(a.ticket_date) - new Date(b.ticket_date));

            let userClasses = {};
            sortedMessages.forEach((message) => {
                const messageDiv = document.createElement('div');
                const currentUsername = message.username.trim().toLowerCase();

                if (!userClasses['karbar1']) {
                    userClasses['karbar1'] = currentUsername;
                    messageDiv.classList.add('matn2');
                } else if (!userClasses['karbar2'] && currentUsername !== userClasses['karbar1']) {
                    userClasses['karbar2'] = currentUsername;
                    messageDiv.classList.add('matn1');
                } else if (currentUsername === userClasses['karbar1']) {
                    messageDiv.classList.add('matn2');
                } else if (currentUsername === userClasses['karbar2']) {
                    messageDiv.classList.add('matn1');
                } else {
                    messageDiv.classList.add('matn3');
                }

                const messageText = document.createElement('p');
                messageText.textContent = message.ticketDescription || '';
                const messageTime = document.createElement('div');
                messageTime.className = 'zaman';
                messageTime.textContent = message.ticket_date || '';
                messageDiv.append(messageText, messageTime);
                kadrMatnContainer.appendChild(messageDiv);
            });

            // اسکرول به پایین‌ترین قسمت پس از بارگذاری پیام‌ها
            kadrMatnContainer.scrollTop = kadrMatnContainer.scrollHeight;
        })
        .catch(error => {
            console.error('Error fetching ticket details:', error);
        });
}


// تابع برای بستن پاپ‌آپ
function hideViewDialog() {
    document.querySelector('#popupMoshahedeoverlay').style.display = 'none';
    document.querySelector('#MoshahedePopupbox').style.display = 'none';
}

// تابع برای ارسال پیام توسط آیکون ارسال پیام// تابع برای ارسال پیام توسط آیکون ارسال پیام// تابع برای ارسال پیام توسط آیکون ارسال پیام
// تابع برای ارسال پیام توسط آیکون ارسال پیام// تابع برای ارسال پیام توسط آیکون ارسال پیام// تابع برای ارسال پیام توسط آیکون ارسال پیام
// تابع برای ارسال پیام توسط آیکون ارسال پیام// تابع برای ارسال پیام توسط آیکون ارسال پیام// تابع برای ارسال پیام توسط آیکون ارسال پیام

if (!window.__modernTicketing) document.querySelector('.ersal-icon').addEventListener('click', () => {
    if (!ticketData) {
        showSystemError('اطلاعات تیکت در دسترس نیست.');
        return;
    }

    // اطلاعات لازم برای ثبت پاسخ
    const ticketTitle = ticketData.ticketTitle;
    const ticketDescription = document.querySelector('#matnErsali').value;
    const target_username = ticketData.target_username;
    const username = ticketData.username;
    const parent_id = ticketData.parent_id;
    const ticket_status = ticketData.ticket_status;

    // ارسال درخواست به سرور
    fetch('/add_ticket_response', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            ticketDescription: ticketDescription,
            parent_id: parent_id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {

            // افزودن پیام جدید به DOM
            const kadrMatnContainer = document.querySelector('#MoshahedePopupbox .kadr-matn');
            const messageDiv = document.createElement('div');
            messageDiv.classList.add('matn1');
            const currentDateTime = new Date();
            const formattedDate = `${currentDateTime.toLocaleDateString('fa-IR')} - ${currentDateTime.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' })}`;
            const messageText = document.createElement('p');
            messageText.textContent = ticketDescription;
            const messageTime = document.createElement('div');
            messageTime.className = 'zaman';
            messageTime.textContent = formattedDate;
            messageDiv.append(messageText, messageTime);
            kadrMatnContainer.appendChild(messageDiv);

            // اسکرول به انتهای باکس
            kadrMatnContainer.scrollTop = kadrMatnContainer.scrollHeight;

            // پاک کردن متن ورودی
            document.querySelector('#matnErsali').value = '';
        } else {
            console.error(data.error);
            showSystemError('خطا در ارسال پاسخ تیکت');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError('خطای سرور');
    });
});

// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی
// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی
// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی// تنظیمات باز و بسته کردن پاپ تعریف ساعت هفتگی

// فرم تعریف کاربر جدید مستقیماً ارسال می‌شود (بدون پاپ‌آپ)

// اعمال ساعت بر همه روزها
var applyAllBtn = document.getElementById('applyAllBtn');
if (applyAllBtn) {
    applyAllBtn.addEventListener('click', function () {
        var val = document.getElementById('applyAllDays').value;
        if (!val) return;
        var dayIds = ['shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chrshanbeh', 'panjshanbeh', 'jomeh'];
        dayIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = val;
        });
    });
}

// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج
// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج
// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج// تنظیمات دکمه خروج

function logout() {
    fetch('/logout', {
        method: 'GET',
        credentials: 'same-origin'
    }).then(response => {
        if (response.redirected) {
            window.location.href = response.url; // ریدایرکت به صفحه مورد نظر
        }
    });
}

// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت
// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت
// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت// تنظیمات تایمر خروج از صفحه مدیریت

// ── Idle Timeout (configurable from master admin) ──
var _idleTimeoutMs = 300000; // default 5 minutes
var _idleTimeoutEnabled = true;
var _idleTimer = null;

fetch('/api/system-config').then(function(r){return r.json();}).then(function(res){
    if(!res.success||!res.data)return;
    _idleTimeoutEnabled = res.data.idle_timeout_enabled !== '0';
    var sec = parseInt(res.data.idle_timeout_seconds,10);
    if(!isNaN(sec) && sec > 0) _idleTimeoutMs = sec * 1000;
    if(_idleTimeoutEnabled) _startIdleTimer();
}).catch(function(){});

function _startIdleTimer(){
    clearTimeout(_idleTimer);
    _idleTimer = setTimeout(function(){ window.location.href = '/login'; }, _idleTimeoutMs);
}

document.addEventListener('mousemove', function(){ if(_idleTimeoutEnabled) _startIdleTimer(); });
document.addEventListener('keydown', function(){ if(_idleTimeoutEnabled) _startIdleTimer(); });

// تنظیمات ارسال به صفحه گزارش نهایی کاربر// تنظیمات ارسال به صفحه گزارش نهایی کاربر// تنظیمات ارسال به صفحه گزارش نهایی کاربر
// تنظیمات ارسال به صفحه گزارش نهایی کاربر// تنظیمات ارسال به صفحه گزارش نهایی کاربر// تنظیمات ارسال به صفحه گزارش نهایی کاربر
// تنظیمات ارسال به صفحه گزارش نهایی کاربر// تنظیمات ارسال به صفحه گزارش نهایی کاربر// تنظیمات ارسال به صفحه گزارش نهایی کاربر



// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت
// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت
// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت// تنظیمات ثبت تیکت

// باز کردن پاپ‌آپ ثبت تیکت
function openTicketModal() {
    const modal = document.getElementById("ticketModal");
    if (!modal) return;
    modal.style.display = "flex";
    // reflow کوچک تا مرورگر حالت اولیه (opacity:0) رو رندر کنه
    void modal.offsetHeight;
    modal.classList.add('is-open');
    document.body.classList.add('ticket-dialog-open');
    fetch('/get_receivers')
        .then(response => response.json())
        .then(data => {
            const receiverSelect = document.getElementById('ticketReceiver');
            receiverSelect.innerHTML = '<option value="" disabled selected>انتخاب کنید</option>';
            data.forEach(receiver => {
                const option = document.createElement('option');
                option.value = receiver;
                option.textContent = receiver;
                receiverSelect.appendChild(option);
            });
        })
        .catch(error => console.error('Error fetching receivers:', error));
}

// بستن پاپ‌آپ ثبت تیکت با انیمیشن
function closeTicketModal() {
    const modal = document.getElementById("ticketModal");
    modal.classList.remove('is-open');
    modal.classList.add('is-closing');
    modal.addEventListener('transitionend', function handler() {
        modal.classList.remove('is-closing');
        modal.style.display = 'none';
        document.body.classList.remove('ticket-dialog-open');
        modal.removeEventListener('transitionend', handler);
    });
}

// تنظیمات ثبت تیکت

// ارسال فرم ثبت تیکت
document.getElementById('ticketForm').addEventListener('submit', function(e) {
    if (window.__modernTicketing) return;
    e.preventDefault(); // جلوگیری از ارسال فرم به طور پیش‌فرض

    // دریافت فیلدهای مختلف فرم
    const ticketReceiver = document.getElementById('ticketReceiver') ? document.getElementById('ticketReceiver').value : '';
    const ticketTitle = document.getElementById('ticketTitleAdmin') ? document.getElementById('ticketTitleAdmin').value : '';
    const ticketDescription = document.getElementById('ticketDescription') ? document.getElementById('ticketDescription').value : '';
    // هویت فرستنده فقط از session سمت سرور تعیین می‌شود.
    const formData = {};

    // بررسی فیلدهایی که مقدار دارند و اضافه کردن به formData
    if (ticketReceiver) formData.recipient_username = ticketReceiver;
    if (ticketTitle) formData.subject = ticketTitle;
    if (ticketDescription) formData.body = ticketDescription;
    formData.priority = document.getElementById('ticketPriority')?.value || 'normal';
    const category = document.getElementById('ticketCategory')?.value || '';
    if (category) formData.category_id = Number(category);
    // ارسال داده‌ها به API جدید تیکتینگ
    fetch('/api/tickets', {
        method: 'POST',
        body: JSON.stringify(formData),
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data && data.id) {
            showSystemSuccess('تیکت شما با موفقیت ثبت شد!');
            closeTicketModal(); // بستن پاپ‌آپ
            const refresh = () => window.TicketingWorkspace && window.TicketingWorkspace.loadAdmin(1);
            (window.uploadTicketAttachments ? window.uploadTicketAttachments(data.id) : Promise.resolve())
                .then(refresh)
                .catch(error => { console.error(error); showSystemError('تیکت ثبت شد اما بارگذاری پیوست ناموفق بود.'); refresh(); });
        } else {
            showSystemError('خطا در ثبت تیکت! ' + (data.message || ''));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError('مشکلی در ارسال درخواست پیش آمده است.');
    });
});



















































// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن
// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن
// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن// تنظیمات گزارش گیری ساعت زن

function convertNumbersToPersianNumber(number) {
    // تبدیل ورودی به رشته قبل از استفاده از replace
    return String(number).replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
}

// تابع برای فرمت کردن زمان
function formatTime(timeValue) {
    if (timeValue === null || timeValue === undefined || timeValue === "") {
        return "00:00";
    }
    let str = String(timeValue).padStart(4, "0");  // تبدیل به رشته 4 رقمی و اضافه کردن صفرهای پیش‌نیاز
    return `${str.substring(0, 2)}:${str.substring(2, 4)}`;  // تبدیل به فرمت HH:MM
}

// تابع برای تبدیل زمان HH:MM به دقیقه
function convertTimeToMinutes(timeValue) {
    let [hours, minutes] = timeValue.split(":").map(Number);  // تقسیم زمان به ساعت و دقیقه
    return hours * 60 + minutes;  // تبدیل به دقیقه
}

document.getElementById("extractButton").addEventListener("click", function () {
    let selectedUsername = document.getElementById("usernameGozareshHozoor").value;
    let startDate = document.getElementById("start_date_hozoor").value;
    let endDate = document.getElementById("end_date_hozoor").value;

    if (!selectedUsername || !startDate || !endDate) {
        showSystemError("لطفاً تمام فیلدها را پر کنید.");
        return;
    }

    // تعریف آرایه تعطیلات رسمی (شمسی به فرمت YYYY-MM-DD)
    const OFFICIAL_HOLIDAYS = [
        "1405-01-01", "1405-01-02", "1405-01-03", "1405-01-04",
        "1405-01-12", "1405-01-13", "1405-01-25", "1405-03-06",
        "1405-03-14", "1405-04-03", "1405-04-04", "1405-04-18",
        "1405-05-23", "1405-06-02", "1405-06-10", "1405-06-19",
        "1405-09-03", "1405-10-13", "1405-10-27", "1405-11-15",
        "1405-11-22", "1405-12-20"
    ];

    fetch(`/get_hozoor/${selectedUsername}?start_date=${startDate}&end_date=${endDate}`)
        .then(response => response.json())
        .then(resp => {
            // debug info
            if (resp._debug) { console.table(resp._debug); }
            const data = Array.isArray(resp) ? resp : (resp.data || []);
            if (Array.isArray(data)) {
                let tableBody = document.querySelector("#hozoorUsersReportTable tbody");
                tableBody.innerHTML = "";

                let totalPresenceDuration = 0;
                let totalOvertime = 0;
                let totalDelay = 0;
                let totalEarlyStart = 0;
                let totalEarlyExit = 0;

                data.forEach(day => {
                    let entryTime = formatTime(day.EntryTime);
                    let exitTime = formatTime(day.ExitTime);

                    let entryTime2 = formatTime(day.EntryTime2);
                    let exitTime2 = formatTime(day.ExitTime2);

                    let workStart = day.WorkStart;
                    let workEnd = day.WorkEnd;

                    let entryMinutes = convertTimeToMinutes(entryTime);
                    let exitMinutes = convertTimeToMinutes(exitTime);

                    let entryMinutes2 = convertTimeToMinutes(entryTime2);
                    let exitMinutes2 = convertTimeToMinutes(exitTime2);

                    let workStartMinutes = convertTimeToMinutes(workStart);
                    let workEndMinutes = convertTimeToMinutes(workEnd);

                    // آیا خروج دوم معتبره؟ (ورود دوم و خروج دوم هر دو مقدار دارن و خروج دوم بعد از ورود دومه)
                    let hasValidExit2 =
                        day.EntryTime2 !== "0000" &&
                        day.ExitTime2 !== "0000" &&
                        exitMinutes2 > entryMinutes2;

                    // اگه خروج دوم معتبر بود، ملاک تشخیص وضعیت (زودهنگام/اضافه‌کاری) خروج دوم میشه
                    let finalExitForStatus = hasValidExit2 ? exitMinutes2 : exitMinutes;

                    let presenceDuration = 0;
                    let overtime = 0, delay = 0, earlyStart = 0, earlyExit = 0;
                    let status = "";

                    // آیا اصلاً برای این روز ورود/خروجی ثبت شده؟ اگر نه (هر دو صفر)، هیچ محاسبه‌ای انجام نشه
                    let hasNoRecord = day.EntryTime === "0000" && day.ExitTime === "0000";

                    if (!hasNoRecord) {
                        presenceDuration = exitMinutes - entryMinutes;

                        // محاسبه وضعیت
                        if (entryMinutes < workStartMinutes) {
                            earlyStart = workStartMinutes - entryMinutes;
                            if (finalExitForStatus < workEndMinutes) {
                                earlyExit = workEndMinutes - finalExitForStatus;
                                presenceDuration = exitMinutes - workStartMinutes;
                                status = "خروج زودهنگام";
                            } else if (finalExitForStatus === workEndMinutes) {
                                presenceDuration = workEndMinutes - workStartMinutes;
                                status = "شروع زودهنگام و تایید سامانه در خروج";
                            } else {
                                overtime = finalExitForStatus - workEndMinutes;
                                if (overtime < 10) overtime = 0;
                                presenceDuration = overtime > 0 ? exitMinutes - workStartMinutes : workEndMinutes - workStartMinutes;
                                status = "شروع زودهنگام و اضافه کاری";
                            }
                        } else if (entryMinutes === workStartMinutes) {
                            if (finalExitForStatus < workEndMinutes) {
                                earlyExit = workEndMinutes - finalExitForStatus;
                                presenceDuration = exitMinutes - workStartMinutes;
                                status = "تایید سامانه در ورود و خروج زود هنگام";
                            } else if (finalExitForStatus === workEndMinutes) {
                                presenceDuration = workEndMinutes - workStartMinutes;
                                status = "تایید سامانه در ورود و خروج";
                            } else {
                                overtime = finalExitForStatus - workEndMinutes;
                                if (overtime < 10) overtime = 0;
                                presenceDuration = overtime > 0 ? exitMinutes - workStartMinutes : workEndMinutes - workStartMinutes;
                                status = "تایید سامانه در ورود و اضافه کاری";
                            }
                        } else {
                            delay = entryMinutes - workStartMinutes;
                            if (finalExitForStatus < workEndMinutes) {
                                earlyExit = workEndMinutes - finalExitForStatus;
                                presenceDuration = exitMinutes - entryMinutes;
                                status = "تاخیر در ورود و خروج زود هنگام";
                            } else if (finalExitForStatus === workEndMinutes) {
                                presenceDuration = workEndMinutes - entryMinutes;
                                status = "تاخیر در ورود و تایید سامانه در خروج";
                            } else {
                                overtime = finalExitForStatus - workEndMinutes;
                                if (overtime < 10) overtime = 0;
                                presenceDuration = overtime > 0 ? exitMinutes - entryMinutes : workEndMinutes - entryMinutes;
                                status = "تاخیر در ورود و اضافه کاری";
                            }
                        }

                        if (hasValidExit2) {
                            presenceDuration += (exitMinutes2 - entryMinutes2);
                        }
                    }

                    // محافظ نهایی: هیچ‌کدام از مقادیر نباید منفی نمایش داده بشن
                    presenceDuration = Math.max(0, presenceDuration);
                    overtime = Math.max(0, overtime);
                    delay = Math.max(0, delay);
                    earlyStart = Math.max(0, earlyStart);
                    earlyExit = Math.max(0, earlyExit);

                    totalPresenceDuration += presenceDuration;
                    totalOvertime += overtime;
                    totalDelay += delay;
                    totalEarlyStart += earlyStart;
                    totalEarlyExit += earlyExit;

                    // محاسبه روز هفته بر اساس تاریخ شمسی
                    let weekdayName = new persianDate(day.Date.split('-').map(Number)).format('dddd');

                    // ایجاد ردیف جدول
                    let row = document.createElement("tr");

                    // اگر جمعه یا تعطیل رسمی بود، ردیف را قرمز کن
                    if (weekdayName === "جمعه" || OFFICIAL_HOLIDAYS.includes(day.Date)) {
                        row.classList.add("holiday-row");
                    }

                    row.innerHTML = `
                        <td class="mjmoo-hozoor-gzrsh">${convertNumbersToPersianNumber(formatTimeFromMinutes(presenceDuration))}</td>
                        <td class="ezafe-hozoor-gzrsh">${convertNumbersToPersianNumber(overtime > 0 ? formatTimeFromMinutes(overtime) : "00:00")}</td>
                        <td class="khorojzd-hozoor-gzrsh">${convertNumbersToPersianNumber(earlyExit > 0 ? formatTimeFromMinutes(earlyExit) : "00:00")}</td>
                        <td class="shorozd-hozoor-gzrsh">${convertNumbersToPersianNumber(earlyStart > 0 ? formatTimeFromMinutes(earlyStart) : "00:00")}</td>
                        <td class="takhir-hozoor-gzrsh">${convertNumbersToPersianNumber(delay > 0 ? formatTimeFromMinutes(delay) : "00:00")}</td>

                        <td class="zmnkhrj2-hozoor-gzrsh">${convertNumbersToPersianNumber(exitTime2)}</td>
                        <td class="zmnvrd2-hozoor-gzrsh">${convertNumbersToPersianNumber(entryTime2)}</td>

                        <td class="zmnkhrj-hozoor-gzrsh">${convertNumbersToPersianNumber(exitTime)}</td>
                        <td class="zmnvrd-hozoor-gzrsh">${convertNumbersToPersianNumber(entryTime)}</td>
                        <td class="hfte-hozoor-gzrsh">${weekdayName}</td>
                        <td class="sbt-hozoor-gzrsh">${convertNumbersToPersianNumber(day.Date.replace(/-/g, "/"))}</td>
                    `;
                    tableBody.appendChild(row);
                });

                document.querySelector(".box1-hozoor .attendance-stat__value").innerText =
                    convertNumbersToPersianNumber(formatTimeFromMinutes(totalPresenceDuration));

                document.querySelector(".box2-hozoor .attendance-stat__value").innerText =
                    convertNumbersToPersianNumber(totalOvertime > 0 ? formatTimeFromMinutes(totalOvertime) : "00:00");

                document.querySelector(".box3-hozoor .attendance-stat__value").innerText =
                    convertNumbersToPersianNumber(totalDelay > 0 ? formatTimeFromMinutes(totalDelay) : "00:00");

                document.querySelector(".box4-hozoor .attendance-stat__value").innerText =
                    convertNumbersToPersianNumber(totalEarlyStart > 0 ? formatTimeFromMinutes(totalEarlyStart) : "00:00");

                document.querySelector(".box5-hozoor .attendance-stat__value").innerText =
                    convertNumbersToPersianNumber(totalEarlyExit > 0 ? formatTimeFromMinutes(totalEarlyExit) : "00:00");

                document.getElementById("natigehHozoor").style.display = "block";

            } else {
                console.error("داده‌ها به فرمت صحیح نیستند:", data);
            }
        })
        .catch(error => console.error("خطا در دریافت داده‌ها:", error));
});




function goToFinalReport() {
    let tableRows = document.querySelectorAll("#hozoorUsersReportTable tbody tr");
    let tableData = [];

    const OFFICIAL_HOLIDAYS = [
        "1404/01/01", "1404/01/02", "1404/01/03", "1404/01/04",
        "1404/01/12", "1404/01/13", "1404/02/04", "1404/03/14",
        "1404/03/15", "1404/03/24", "1404/04/14", "1404/04/15",
        "1404/05/23", "1404/06/02", "1404/06/10", "1404/06/19",
        "1404/09/03", "1404/10/13", "1404/10/27", "1404/11/15",
        "1404/11/22", "1404/12/20"
    ];

    tableRows.forEach((row, index) => {

        let cells = row.getElementsByTagName("td");

        let date = cells[10].innerText;

        let dateEnglish = persianToEnglishNumbers(date)
            .replace(/-/g, "/")
            .replace(/٫/g, "/");

        let isHoliday = OFFICIAL_HOLIDAYS.includes(dateEnglish);

        let weekday = cells[9].innerText;

        if (weekday === "جمعه")
            isHoliday = true;

        tableData.push({
            rowNumber: index + 1,

            calculatedTime: cells[0].innerText,
            overtime: cells[1].innerText,
            earlyExit: cells[2].innerText,
            earlyStart: cells[3].innerText,
            delay: cells[4].innerText,

            exitTime2: cells[5].innerText,
            entryTime2: cells[6].innerText,

            exitTime: cells[7].innerText,
            entryTime: cells[8].innerText,

            weekday: cells[9].innerText,
            date: cells[10].innerText,

            isHoliday: isHoliday
        });
    });

    localStorage.setItem("numRecords", tableData.length);
    localStorage.setItem("hozoorReportData", JSON.stringify(tableData));

    localStorage.setItem("totalPresenceTime", document.querySelector(".box1-hozoor .attendance-stat__value").innerText);
    localStorage.setItem("totalOvertime", document.querySelector(".box2-hozoor .attendance-stat__value").innerText);
    localStorage.setItem("totalDelayTime", document.querySelector(".box3-hozoor .attendance-stat__value").innerText);
    localStorage.setItem("totalEarlyStart", document.querySelector(".box4-hozoor .attendance-stat__value").innerText);
    localStorage.setItem("totalEarlyExit", document.querySelector(".box5-hozoor .attendance-stat__value").innerText);

    let username = document.getElementById("usernameGozareshHozoor").value;
    let start_date = document.getElementById("start_date_hozoor").value;
    let end_date = document.getElementById("end_date_hozoor").value;

    fetch('/get_overtime_report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, start_date, end_date })
    })
    .then(response => response.json())
    .then(data => {
        localStorage.setItem("overtimeReportData", JSON.stringify(data));

        return fetch('/generate_individual_report', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user: username,
                fromDate: start_date,
                toDate: end_date
            })
        });
    })
    .then(response => response.json())
    .then(data => {
        localStorage.setItem("leaveReportData", JSON.stringify(data.reports));

        return fetch('/get_hourly_pass_report', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username,
                start_date,
                end_date
            })
        });
    })
    .then(response => response.json())
    .then(data => {

        localStorage.setItem("hourlyPassReportData", JSON.stringify(data));

        localStorage.setItem("selectedUsername", username);

        window.open("/final_report_page", "_blank");

    })
    .catch(error => console.error("❌ خطا در دریافت گزارش:", error));

    function persianToEnglishNumbers(str) {
        const persianNums = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

        for (let i = 0; i < persianNums.length; i++) {
            str = str.replace(new RegExp(persianNums[i], 'g'), i);
        }

        return str;
    }
}

// تابع برای فرمت کردن زمان به دقیقه (از فرمت HH:MM)
function formatTimeFromMinutes(minutes) {
    let hours = Math.floor(minutes / 60);
    let mins = minutes % 60;
    return `${String(hours).padStart(2, "0")}:${String(mins).padStart(2, "0")}`;
}

// تنظیمات دکمه ثبت حضور و غیاب دستی// تنظیمات دکمه ثبت حضور و غیاب دستی// تنظیمات دکمه ثبت حضور و غیاب دستی
// تنظیمات دکمه ثبت حضور و غیاب دستی// تنظیمات دکمه ثبت حضور و غیاب دستی// تنظیمات دکمه ثبت حضور و غیاب دستی
// تنظیمات دکمه ثبت حضور و غیاب دستی// تنظیمات دکمه ثبت حضور و غیاب دستی// تنظیمات دکمه ثبت حضور و غیاب دستی

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById("sabt-btn").addEventListener("click", function () {
        const form = document.getElementById("sabtdastHozoor");
        const formData = new FormData(form);

        const data = {
            username: formData.get("usernamedast"),
            tarikh: formData.get("tarikh"),
            vorood: formData.get("vorood"),
            khorooj: formData.get("khorooj")
        };

        fetch("/sabt_hozoor", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error("خطا در ارسال اطلاعات");
            }
            return response.json();
        })
        .then(result => {
            showSystemSuccess("اطلاعات با موفقیت ثبت شد");
            form.reset();
        })
        .catch(error => {
            showSystemError("خطا در ثبت اطلاعات: " + error.message);
        });
    });
});

// ============================================================================
// ثبت ورود / خروج از صفحهٔ کاربران (Check-In / Check-Out)
// زمان رسمی حضور و غیاب همیشه در سمت سرور تعیین می‌شود؛ این کد فقط وضعیت را
// از سرور می‌گیرد و نتیجه را در جدول کاربران منعکس می‌کند.
// ============================================================================

var ATTENDANCE_STATUS_LABELS = {
    "not_checked_in": "ثبت نشده",
    "checked_in": "در حال کار",
    "checked_out": "تکمیل شده"
};

var ATTENDANCE_BUTTON_LABELS = {
    "not_checked_in": '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
    "checked_in": '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    "checked_out": '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
};

// آخرین وضعیت تأییدشده توسط سرور، برای بازگردانی UI پس از خطا
var ATTENDANCE_CACHE = {};
var ATTENDANCE_SERVER_NOW = null;

function findAttendanceRow(username) {
    var rows = document.querySelectorAll("#userTable tbody tr[data-username]");
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].getAttribute("data-username") === username) return rows[i];
    }
    return null;
}

function findAttendanceButton(username) {
    // دکمه ممکن است در جدول (دسکتاپ) یا داخل کارت موبایل (منتقل‌شده توسط لایهٔ ریسپانسیو) باشد
    var buttons = document.querySelectorAll(".attendance-action-btn[data-username]");
    for (var i = 0; i < buttons.length; i++) {
        if (buttons[i].getAttribute("data-username") === username) return buttons[i];
    }
    return null;
}

function findManualCheckoutButton(username) {
    var buttons = document.querySelectorAll(".manual-checkout-btn[data-username]");
    for (var i = 0; i < buttons.length; i++) {
        if (buttons[i].getAttribute("data-username") === username) return buttons[i];
    }
    return null;
}

function applyAttendanceState(username, status, checkIn, checkOut) {
    ATTENDANCE_CACHE[username] = { status: status, check_in: checkIn, check_out: checkOut };

    var row = findAttendanceRow(username);
    var statusEl = row ? row.querySelector(".attendance-status") : null;
    var checkInEl = row ? row.querySelector(".attendance-checkin") : null;
    var checkOutEl = row ? row.querySelector(".attendance-checkout") : null;

    if (statusEl) {
        statusEl.textContent = ATTENDANCE_STATUS_LABELS[status] || "—";
        statusEl.setAttribute("data-state", status);
    }
    if (checkInEl) checkInEl.textContent = checkIn || "—";
    if (checkOutEl) checkOutEl.textContent = checkOut || "—";

    var btn = findAttendanceButton(username);
    if (btn) {
        btn.disabled = false;
        // فقط آیکون SVG را جایگزین کن، span tooltip را حفظ کن
        // «ثبت ورود دستی» همیشه آیکون ورود را نشان می‌دهد؛ خروج با دکمهٔ جداگانه انجام می‌شود
        var oldSvg = btn.querySelector('svg');
        var iconKey = status === "checked_out" ? "checked_out" : "not_checked_in";
        var newSvgHtml = ATTENDANCE_BUTTON_LABELS[iconKey] || '';
        if (oldSvg && newSvgHtml) {
            var tmp = document.createElement('div');
            tmp.innerHTML = newSvgHtml;
            var newSvg = tmp.querySelector('svg');
            if (newSvg) oldSvg.replaceWith(newSvg);
        }
        btn.setAttribute("data-action", status);
        btn.classList.remove("attendance-action-btn--checkin", "attendance-action-btn--checkout", "attendance-action-btn--done", "attendance-action-btn--loading");
        btn.classList.add(
            status === "checked_out" ? "attendance-action-btn--done" :
            "attendance-action-btn--checkin"
        );
        // «ثبت ورود دستی» فقط وقتی فعال است که هنوز ورود ثبت نشده باشد
        btn.disabled = status !== "not_checked_in";
    }

    // دکمهٔ «ثبت خروج دستی» — فقط وقتی کاربر داخل است فعال می‌شود
    var coBtn = findManualCheckoutButton(username);
    if (coBtn) {
        coBtn.disabled = status !== "checked_in";
    }

    // بازتاب تغییرات در نمای موبایل (کارت/فهرست) در صورت فعال بودن
    if (window.RT && window.RT.refresh) {
        try { window.RT.refresh(); } catch (e) { /* noop */ }
    }
}

function setAttendanceLoading(username, loading) {
    var btn = findAttendanceButton(username);
    if (!btn) return;
    if (loading) {
        btn.disabled = true;
        btn.classList.add("attendance-action-btn--loading");
    } else {
        btn.classList.remove("attendance-action-btn--loading");
        // بازگردانی از حافظهٔ کش (آخرین وضعیت تأییدشده)
        var cached = ATTENDANCE_CACHE[username];
        if (cached) applyAttendanceState(username, cached.status, cached.check_in, cached.check_out);
    }
}

function loadAttendanceStatuses() {
    fetch("/get_hozoor_today", { method: "GET", credentials: "same-origin" })
        .then(function (resp) {
            if (!resp.ok) throw new Error("HTTP " + resp.status);
            return resp.json();
        })
        .then(function (data) {
            if (!data.success || !data.data) throw new Error(data.message || "خطا");
            if (data.data.server_now) ATTENDANCE_SERVER_NOW = data.data.server_now;
            var users = data.data.users || [];
            for (var i = 0; i < users.length; i++) {
                applyAttendanceState(users[i].username, users[i].status, users[i].check_in, users[i].check_out);
            }
        })
        .catch(function (err) {
            console.error("خطا در دریافت وضعیت حضور:", err);
        });
}

function doCheckIn(username) {
    setAttendanceLoading(username, true);
    fetch("/sabt_hozoor_checkin", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ username: username })
    })
        .then(function (resp) {
            return resp.json().then(function (data) { return { ok: resp.ok, data: data }; });
        })
        .then(function (r) {
            if (!r.ok || !r.data.success) throw new Error(r.data.message || "خطا در ثبت ورود");
            var d = r.data.data || {};
            applyAttendanceState(username, d.status, d.check_in, d.check_out);
            showSystemSuccess(r.data.message || "ورود با موفقیت ثبت شد.");
        })
        .catch(function (err) {
            console.error(err);
            showSystemError(err.message || "خطا در ثبت ورود.");
            setAttendanceLoading(username, false);
        });
}

function doCheckOut(username) {
    closeCheckoutConfirm();
    setAttendanceLoading(username, true);
    fetch("/sabt_hozoor_checkout", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ username: username })
    })
        .then(function (resp) {
            return resp.json().then(function (data) { return { ok: resp.ok, data: data }; });
        })
        .then(function (r) {
            if (!r.ok || !r.data.success) throw new Error(r.data.message || "خطا در ثبت خروج");
            var d = r.data.data || {};
            applyAttendanceState(username, d.status, d.check_in, d.check_out);
            showSystemSuccess(r.data.message || "خروج با موفقیت ثبت شد.");
        })
        .catch(function (err) {
            console.error(err);
            showSystemError(err.message || "خطا در ثبت خروج.");
            setAttendanceLoading(username, false);
        });
}

var CHECKOUT_PENDING_USERNAME = null;

function openCheckoutConfirm(username) {
    var cached = ATTENDANCE_CACHE[username];
    var checkIn = cached ? cached.check_in : "—";
    var nowLabel = ATTENDANCE_SERVER_NOW || formatCurrentTime();

    var userEl = document.getElementById("checkoutConfirmUser");
    var checkInEl = document.getElementById("checkoutConfirmCheckIn");
    var nowEl = document.getElementById("checkoutConfirmNow");
    if (userEl) userEl.textContent = username;
    if (checkInEl) checkInEl.textContent = checkIn || "—";
    if (nowEl) nowEl.textContent = nowLabel;

    CHECKOUT_PENDING_USERNAME = username;
    document.getElementById("checkoutConfirmModal").style.display = "block";
}

function closeCheckoutConfirm() {
    CHECKOUT_PENDING_USERNAME = null;
    var modal = document.getElementById("checkoutConfirmModal");
    if (modal) modal.style.display = "none";
}

function formatCurrentTime() {
    var now = new Date();
    var hh = String(now.getHours()).padStart(2, "0");
    var mm = String(now.getMinutes()).padStart(2, "0");
    return hh + ":" + mm;
}

document.addEventListener("click", function (e) {
    var target = e.target;
    if (!target || !target.closest) return;

    var btn = target.closest(".attendance-action-btn");
    if (btn && !btn.disabled) {
        e.preventDefault();
        var username = btn.getAttribute("data-username");
        var action = btn.getAttribute("data-action");
        if (!username) return;
        if (action === "not_checked_in") {
            doCheckIn(username);
        }
        return;
    }

    // دکمهٔ «ثبت خروج دستی» — همان مدال تایید خروج را باز می‌کند
    var coBtn = target.closest(".manual-checkout-btn");
    if (coBtn && !coBtn.disabled) {
        e.preventDefault();
        var coUsername = coBtn.getAttribute("data-username");
        if (coUsername) openCheckoutConfirm(coUsername);
        return;
    }

    // بستن مدال تایید خروج با کلیک روی پس‌زمینه
    if (target === document.getElementById("checkoutConfirmModal")) {
        closeCheckoutConfirm();
    }
});

document.addEventListener("DOMContentLoaded", function () {
    var confirmBtn = document.getElementById("checkoutConfirmBtn");
    if (confirmBtn) {
        confirmBtn.addEventListener("click", function () {
            if (CHECKOUT_PENDING_USERNAME) doCheckOut(CHECKOUT_PENDING_USERNAME);
        });
    }
    loadAttendanceStatuses();
});

// تنظمیات انتخاب روز از تقویم شخصی سازی شده// تنظمیات انتخاب روز از تقویم شخصی سازی شده// تنظمیات انتخاب روز از تقویم شخصی سازی شده
// تنظمیات انتخاب روز از تقویم شخصی سازی شده// تنظمیات انتخاب روز از تقویم شخصی سازی شده// تنظمیات انتخاب روز از تقویم شخصی سازی شده
// تنظمیات انتخاب روز از تقویم شخصی سازی شده// تنظمیات انتخاب روز از تقویم شخصی سازی شده// تنظمیات انتخاب روز از تقویم شخصی سازی شده

const persianMonths = ["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"];

function toJalaali(gy, gm, gd) {
  const g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
  let jy, jm, jd;
  let gy2 = (gm > 2) ? (gy + 1) : gy;
  let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
  jy = -1595 + (33 * Math.floor(days / 12053));
  days %= 12053;
  jy += 4 * Math.floor(days / 1461);
  days %= 1461;
  if (days > 365) {
    jy += Math.floor((days - 1) / 365);
    days = (days - 1) % 365;
  }
  if (days < 186) {
    jm = 1 + Math.floor(days / 31);
    jd = 1 + (days % 31);
  } else {
    jm = 7 + Math.floor((days - 186) / 30);
    jd = 1 + ((days - 186) % 30);
  }
  return { jy, jm, jd };
}

function toGregorian(jy, jm, jd) {
  jy = parseInt(jy);
  jm = parseInt(jm);
  jd = parseInt(jd);
  let gy, gd;
  let jy2 = jy - 979;
  let days = 365 * jy2 + Math.floor(jy2 / 33) * 8 + Math.floor(((jy2 % 33) + 3) / 4);
  for (let i = 1; i < jm; ++i) days += daysInJMonth(jy, i);
  days += jd - 1;
  gy = 1600 + 400 * Math.floor(days / 146097);
  days %= 146097;
  let leap = true;
  if (days >= 36525) {
    days--;
    gy += 100 * Math.floor(days / 36524);
    days %= 36524;
    if (days >= 365) days++;
    else leap = false;
  }
  gy += 4 * Math.floor(days / 1461);
  days %= 1461;
  if (days >= 366) {
    leap = false;
    days--;
    gy += Math.floor(days / 365);
    days = days % 365;
  }
  gd = days + 1;
  let sal_a = [0,31, (leap ? 29 : 28),31,30,31,30,31,31,30,31,30,31];
  let gm = 0;
  while (gm < 13 && gd > sal_a[gm]) {
    gd -= sal_a[gm];
    gm++;
  }
  return { gy, gm, gd };
}

function isJLeap(jy) {
  let mod = ((jy - ((jy > 0) ? 474 : 473)) % 2820) + 474 + 38;
  return ((mod * 682) % 2816) < 682;
}

function daysInJMonth(jy, jm) {
  if (jm <= 6) return 31;
  if (jm <= 11) return 30;
  if (isJLeap(jy)) return 30;
  return 29;
}

function getWeekDay(jy, jm, jd) {
  const g = toGregorian(jy, jm, jd);
  const d = new Date(g.gy, g.gm - 1, g.gd);
  const jsDay = d.getDay();
  return (jsDay + 3) % 7;
}

const inputTarikh = document.getElementById("tarikh");
const calendarBox = document.getElementById("calendar-box");
const calendarMonth = document.getElementById("calendar-month");
const calendarYear = document.getElementById("calendar-year");
const calendarDates = document.getElementById("calendar-dates");

let selectedDate = null;

function renderMonthYearSelectors(currentYear, currentMonth) {
  calendarMonth.innerHTML = persianMonths.map((m, i) => `<option value="${i + 1}" ${i + 1 === currentMonth ? 'selected' : ''}>${m}</option>`).join("");
  const thisYear = toJalaali(new Date().getFullYear(), new Date().getMonth() + 1, new Date().getDate()).jy;
  let yearOptions = "";
  for (let y = 1390; y <= thisYear; y++) {
    yearOptions += `<option value="${y}" ${y === currentYear ? 'selected' : ''}>${y}</option>`;
  }
  calendarYear.innerHTML = yearOptions;
}

// تغییر اعداد تقویم
function renderCalendar(year, month, selectedDay) {
    renderMonthYearSelectors(year, month);
    calendarDates.innerHTML = "";
    let firstDayOfMonth = getWeekDay(year, month, 1);
    for (let i = 0; i < firstDayOfMonth; i++) {
        calendarDates.appendChild(document.createElement("div"));
    }
    const daysCount = daysInJMonth(year, month);
    for (let day = 1; day <= daysCount; day++) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.textContent = convertToPersianNumbers(day); // <-- تبدیل به فارسی
        if (selectedDay === day) btn.classList.add("selected");
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            selectedDate = { year, month, day };
            updateInputDate();
            hideCalendar();
        });
        calendarDates.appendChild(btn);
    }
}

// نمایش تاریخ انتخاب شده با اعداد فارسی
function updateInputDate() {
    if (!selectedDate) return;
    const { year, month, day } = selectedDate;
    inputTarikh.value = convertToPersianNumbers(`${year}/${String(month).padStart(2, "0")}/${String(day).padStart(2, "0")}`);
}

function showCalendar() {
  calendarBox.style.display = "block";
  calendarBox.setAttribute("aria-hidden", "false");

    const inputRect = inputTarikh.getBoundingClientRect();
    const calendarWidth = calendarBox.offsetWidth;
    const viewportPadding = 8;
    const left = Math.min(
        Math.max(viewportPadding, inputRect.right - calendarWidth),
        window.innerWidth - calendarWidth - viewportPadding
    );

    calendarBox.style.top = `${inputRect.bottom + 6}px`;
    calendarBox.style.left = `${left}px`;
}


function hideCalendar() {
  calendarBox.style.display = "none";
  calendarBox.setAttribute("aria-hidden", "true");
}

inputTarikh.addEventListener("click", () => {
  const today = new Date();
  const jToday = toJalaali(today.getFullYear(), today.getMonth() + 1, today.getDate());
  selectedDate = { year: jToday.jy, month: jToday.jm, day: jToday.jd };
  renderCalendar(selectedDate.year, selectedDate.month, selectedDate.day);
  showCalendar();
});

calendarMonth.addEventListener("change", () => {
  selectedDate.month = parseInt(calendarMonth.value);
  renderCalendar(selectedDate.year, selectedDate.month, selectedDate.day);
});

calendarYear.addEventListener("change", () => {
  selectedDate.year = parseInt(calendarYear.value);
  renderCalendar(selectedDate.year, selectedDate.month, selectedDate.day);
});

document.addEventListener("click", (e) => {
  if (!calendarBox.contains(e.target) && e.target !== inputTarikh) hideCalendar();
});

window.addEventListener("load", () => {
  const now = new Date();
  const jNow = toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
  selectedDate = { year: jNow.jy, month: jNow.jm, day: jNow.jd };
  updateInputDate();
});


// تنظیمات انتخاب ساعت و دقیقه از باکس ثبت حضور و غیاب دستی // تنظیمات انتخاب ساعت و دقیقه از باکس ثبت حضور و غیاب دستی
// تنظیمات انتخاب ساعت و دقیقه از باکس ثبت حضور و غیاب دستی // تنظیمات انتخاب ساعت و دقیقه از باکس ثبت حضور و غیاب دستی
// تنظیمات انتخاب ساعت و دقیقه از باکس ثبت حضور و غیاب دستی // تنظیمات انتخاب ساعت و دقیقه از باکس ثبت حضور و غیاب دستی

function toggleTimePicker(inputId) {
  // همگام‌سازی ساعت و دقیقه تایم‌پیکر با مقدار داخل input
  const input = document.getElementById(inputId);
  const [hour, minute] = input.value.split(":");

  if (/^\d{2}$/.test(hour) && /^\d{2}$/.test(minute)) {
    document.getElementById(`hour-${inputId}`).innerText = hour;
    document.getElementById(`minute-${inputId}`).innerText = minute;

    // وضعیت انتخاب رو هم فعال کن، که اگه کاربر فقط دقیقه یا فقط ساعت رو تغییر داد، مقدار جدید ثبت بشه
    selectedFields[inputId].hourSelected = true;
    selectedFields[inputId].minuteSelected = true;
  }

  // بقیه کد اصلی
  document.querySelectorAll(".time-picker").forEach(el => el.style.display = "none");
  const picker = document.getElementById(`timepicker-${inputId}`);
  picker.style.display = picker.style.display === "flex" ? "none" : "flex";

}


function positionTimePicker(inputId) {
  const input = document.getElementById(inputId);
  const picker = document.getElementById(`timepicker-${inputId}`);
  const rect = input.getBoundingClientRect();
  picker.style.top = rect.bottom + window.scrollY + "px";
  picker.style.left = rect.left + window.scrollX + "px";
}


const selectedFields = {
  vorood: { hourSelected: false, minuteSelected: false, timer: null },
  khorooj: { hourSelected: false, minuteSelected: false, timer: null }
};

function changeTime(inputId, type, delta) {
  const hourEl = document.getElementById(`hour-${inputId}`);
  const minuteEl = document.getElementById(`minute-${inputId}`);
  const input = document.getElementById(inputId);

  let hour = parseInt(hourEl.innerText);
  let minute = parseInt(minuteEl.innerText);

  if (type === "hour") {
    hour = (hour + delta + 24) % 24;
    selectedFields[inputId].hourSelected = true;
  } else if (type === "minute") {
    minute = (minute + delta + 60) % 60;
    selectedFields[inputId].minuteSelected = true;
  }

  const newHour = hour.toString().padStart(2, '0');
  const newMinute = minute.toString().padStart(2, '0');

  hourEl.innerText = newHour;
  minuteEl.innerText = newMinute;

  // فقط اگر هر دو انتخاب شدن، مقدار توی input نوشته بشه
  if (selectedFields[inputId].hourSelected && selectedFields[inputId].minuteSelected) {
    input.value = `${newHour}:${newMinute}`;

    // اگر فیلد vorood بود، منتظر 1.5 ثانیه بمون و بعد برو به khorooj
    if (inputId === "vorood") {
      if (selectedFields[inputId].timer) {
        clearTimeout(selectedFields[inputId].timer);
      }

      selectedFields[inputId].timer = setTimeout(() => {
        const targetInput = document.getElementById("khorooj");
        targetInput.focus();
        toggleTimePicker("khorooj");
      }, 1500);
    }
  }
}

// تابعی برای اصلاح فرمت ورودی تایم بدون نمایش خطا یا پاک کردن مقدار
function fixTimeFormat(inputId) {
  const input = document.getElementById(inputId);
  let val = input.value.trim();

  // اگر فرمت درست بود، دست نزن
  if (/^\d{2}:\d{2}$/.test(val)) return;

  // حذف کاراکترهای غیرعددی
  val = val.replace(/\D/g, '');

  let hour = "--", minute = "--";

  if (val.length === 3) {
    hour = '0' + val.charAt(0);
    minute = val.slice(1);
  } else if (val.length === 4) {
    hour = val.slice(0, 2);
    minute = val.slice(2);
  } else if (val.length <= 2) {
    hour = val.padStart(2, '-');
  }

  // فقط مقدار رو تنظیم کن، نه alert بده، نه پاکش کن
  input.value = `${hour.padEnd(2, "-")}:${minute.padEnd(2, "-")}`;
}

// وقتی کلیک خارج از تایم‌پیکر شد، باکس رو ببند و تایمر رو لغو کن
document.addEventListener("click", function(event) {
  const timePickers = document.querySelectorAll(".time-picker");

  timePickers.forEach(picker => {
    const inputId = picker.id.replace("timepicker-", "");
    const input = document.getElementById(inputId);

    if (
      !picker.contains(event.target) &&
      event.target !== input
    ) {
      picker.style.display = "none";

      if (selectedFields[inputId] && selectedFields[inputId].timer) {
        clearTimeout(selectedFields[inputId].timer);
        selectedFields[inputId].timer = null;
      }
    }
  });
});

function setupTimeInput(id) {
    const input = document.getElementById(id);
    input.value = "--:--";

    input.addEventListener("input", function (e) {
        let val = input.value.replace(/\D/g, ''); // فقط عددها
        if (val.length > 4) val = val.slice(0, 4);

        let hour = "--";
        let minute = "--";

        if (val.length >= 1) hour = val[0] + "-";
        if (val.length >= 2) hour = val.slice(0, 2);
        if (val.length >= 3) minute = val[2] + "-";
        if (val.length >= 4) minute = val.slice(2, 4);

        input.value = `${hour}:${minute}`;

        if (val.length === 4) {
            // اگر فیلد "vorood" بود و کامل شد، برو روی "khorooj"
            if (id === "vorood") {
                document.getElementById("khorooj").focus();
            }
        }
    });

    // اجازه بده روی بخش خاصی کلیک کنه (ساعت یا دقیقه) و فقط همونو تغییر بده
    input.addEventListener("click", function (e) {
        const pos = input.selectionStart;
        if (pos <= 2) {
            input.setSelectionRange(0, 2); // ساعت
        } else {
            input.setSelectionRange(3, 5); // دقیقه
        }
    });

    // روی blur، اگر فرمت مشکل داشت، پاکش کن
    input.addEventListener("blur", function () {
        const parts = input.value.split(":");
        if (parts.length !== 2 || parts[0].includes("-") || parts[1].includes("-")) {
            input.value = "--:--";
        } else {
            const h = parseInt(parts[0], 10);
            const m = parseInt(parts[1], 10);
            if (isNaN(h) || isNaN(m) || h > 23 || m > 59) {
                input.value = "--:--";
                showSystemError("فرمت ساعت نامعتبر است. لطفاً مثلاً 09:16 وارد کنید.");
            }
        }
    });

    // کنترل کلیدها
    input.addEventListener("keydown", function (e) {
        const allowed = ['ArrowLeft', 'ArrowRight', 'Backspace', 'Delete', 'Tab'];
        if (allowed.includes(e.key)) return;

        if (!/^\d$/.test(e.key)) {
            e.preventDefault();
            return;
        }

        const val = input.value.replace(/\D/g, '');
        if (val.length >= 4 && input.selectionStart === input.selectionEnd) {
            e.preventDefault();
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    setupTimeInput("vorood");
    setupTimeInput("khorooj");
});

// هنگام بارگذاری صفحه، به input ها رویدادهای لازم اضافه کن
document.addEventListener("DOMContentLoaded", function () {
  ["vorood", "khorooj"].forEach(id => {
    const input = document.getElementById(id);
    input.addEventListener("blur", () => fixTimeFormat(id));
  });
});
// ==================== مدیریت شیفت‌های ماهانه پرسنل ==================== //
// ==================== مدیریت شیفت‌های ماهانه پرسنل ==================== //
// ==================== مدیریت شیفت‌های ماهانه پرسنل ==================== //

const SHIFT_DAY_FIELDS = ['shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chaharshanbeh', 'panjshanbeh', 'jomeh'];
let currentShiftsData = [];
let activeShiftsData = [];

function populateShiftMonthYearSelectors() {
    const monthSelect = document.getElementById('shiftMonth');
    const yearSelect = document.getElementById('shiftYear');
    if (!monthSelect || !yearSelect) return;

    const today = new Date();
    const jToday = toJalaali(today.getFullYear(), today.getMonth() + 1, today.getDate());

    monthSelect.innerHTML = persianMonths.map((m, i) =>
        `<option value="${i + 1}" ${i + 1 === jToday.jm ? 'selected' : ''}>${convertToPersianNumbers(m)}</option>`
    ).join('');

    let yearOptions = '';
    for (let y = jToday.jy - 2; y <= jToday.jy + 2; y++) {
        yearOptions += `<option value="${y}" ${y === jToday.jy ? 'selected' : ''}>${convertToPersianNumbers(String(y))}</option>`;
    }
    yearSelect.innerHTML = yearOptions;
}

document.addEventListener('DOMContentLoaded', populateShiftMonthYearSelectors);

function openShiftPopup() {
    const tabUser = document.getElementById('shiftUsername') ? document.getElementById('shiftUsername').value : '';
    if (!tabUser) {
        showSystemError('لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید');
        return;
    }
    resetShiftForm();

    const tabYear = document.getElementById('shiftYear') ? document.getElementById('shiftYear').value : '';
    const tabMonth = document.getElementById('shiftMonth') ? document.getElementById('shiftMonth').value : '';

    const modalUserEl = document.getElementById('shiftModalUsername');
    if (modalUserEl) modalUserEl.value = tabUser;
    const modalYearEl = document.getElementById('shiftModalYear');
    if (modalYearEl) modalYearEl.value = tabYear;
    const modalMonthEl = document.getElementById('shiftModalMonth');
    if (modalMonthEl) modalMonthEl.value = tabMonth;

    const metaContainer = document.getElementById('shiftPopupMeta');
    const metaUser = document.getElementById('shiftPopupMetaUser');
    const metaDate = document.getElementById('shiftPopupMetaDate');
    if (metaContainer && metaUser && metaDate) {
        metaUser.textContent = tabUser;
        var monthName = (typeof persianMonths !== 'undefined' && tabMonth && persianMonths[Number(tabMonth) - 1]) ? persianMonths[Number(tabMonth) - 1] : ('ماه ' + tabMonth);
        metaDate.textContent = monthName + (tabYear ? (' ' + convertToPersianNumbers(String(tabYear))) : '');
        metaContainer.style.display = 'flex';
    }

    const saveBtn = document.getElementById('saveShiftBtn');
    if (saveBtn) {
        const textSpan = saveBtn.querySelector('span');
        if (textSpan) textSpan.textContent = 'ذخیره بازه‌ی شیفت';
    }

    const deleteBtn = document.getElementById('deleteShiftModalBtn');
    if (deleteBtn) deleteBtn.style.display = 'none';

    document.getElementById('shiftPopupOverlay').style.display = 'flex';
}

function closeShiftPopup(event) {
    if (event && event.target !== document.getElementById('shiftPopupOverlay')) return;
    const overlay = document.getElementById('shiftPopupOverlay');
    if (overlay) overlay.style.display = 'none';
    resetShiftForm();
}

function resetShiftForm() {
    const shiftIdEl = document.getElementById('shiftId');
    if (shiftIdEl) shiftIdEl.value = '';
    const userEl = document.getElementById('shiftModalUsername');
    if (userEl) userEl.value = '';
    const yearEl = document.getElementById('shiftModalYear');
    if (yearEl) yearEl.value = '';
    const monthEl = document.getElementById('shiftModalMonth');
    if (monthEl) monthEl.value = '';

    const titleEl = document.getElementById('shiftTitle');
    if (titleEl) titleEl.value = '';
    const startEl = document.getElementById('shiftStartDay');
    if (startEl) startEl.value = '';
    const endEl = document.getElementById('shiftEndDay');
    if (endEl) endEl.value = '';

    SHIFT_DAY_FIELDS.forEach(f => {
        const input = document.getElementById('shift_' + f);
        if (input) input.value = '';
    });

    const formTitle = document.getElementById('shiftFormTitle');
    if (formTitle) formTitle.textContent = 'افزودن بازه‌ی شیفت جدید';
    const subTitle = document.getElementById('shiftFormSubtitle');
    if (subTitle) subTitle.textContent = 'بازه‌ی روزها را مشخص و ساعت شیفت هر روز هفته را وارد کنید';

    const metaContainer = document.getElementById('shiftPopupMeta');
    if (metaContainer) metaContainer.style.display = 'none';

    const saveBtn = document.getElementById('saveShiftBtn');
    if (saveBtn) {
        const textSpan = saveBtn.querySelector('span');
        if (textSpan) textSpan.textContent = 'ذخیره بازه‌ی شیفت';
    }

    const deleteBtn = document.getElementById('deleteShiftModalBtn');
    if (deleteBtn) deleteBtn.style.display = 'none';

    const cancelBtn = document.getElementById('cancelShiftEditBtn');
    if (cancelBtn) {
        cancelBtn.style.display = 'inline-flex';
        cancelBtn.textContent = 'انصراف';
    }
}

function loadShifts() {
    const username = document.getElementById('shiftUsername').value;
    const year = document.getElementById('shiftYear').value;
    const month = document.getElementById('shiftMonth').value;

    if (!username) {
        showSystemError('لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید');
        return;
    }

    resetShiftForm();

    const url = `/get_shifts/${encodeURIComponent(username)}/${year}/${month}`;
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showSystemError(data.message || 'خطا در دریافت اطلاعات شیفت‌ها');
                return;
            }
            renderShiftsTable(data.shifts);
        })
        .catch(error => {
            console.error(error);
            showSystemError('خطا در دریافت اطلاعات شیفت‌ها');
        });
}

function renderShiftsTable(shifts) {
    currentShiftsData = shifts || [];
    const tbody = document.getElementById('shiftsTableBody');
    tbody.innerHTML = '';

    /* شمارندهٔ بازه‌ها در سربرگ جدول */
    const badge = document.getElementById('shiftCountBadge');
    if (badge) {
        if (shifts && shifts.length > 0) {
            badge.textContent = convertToPersianNumbers(String(shifts.length));
            badge.hidden = false;
            badge.style.animation = 'none';
            void badge.offsetWidth; /* restart pop animation */
            badge.style.animation = '';
        } else {
            badge.hidden = true;
        }
    }

    if (!shifts || shifts.length === 0) {
        const row = document.createElement('tr');
        row.className = 'shifts-empty-row';
        row.innerHTML = `<td colspan="11">هیچ بازه‌ی شیفتی برای این پرسنل/ماه تعریف نشده است</td>`;
        tbody.appendChild(row);
        return;
    }

    shifts.forEach(shift => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <button type="button" class="edit-btn" onclick="editShift(${shift.id})">
                    <img src="/static/images/writing.png" alt="ویرایش">
                </button>
                <button type="button" class="trash-icon" onclick="deleteShift(${shift.id})">
                    <img src="/static/images/trash.png" alt="حذف">
                </button>
            </td>
            <td>${esc(shift.title ? convertToPersianNumbers(shift.title) : '-')}</td>
            <td>${shift.jomeh ? convertToPersianNumbers(shift.jomeh) : 'تعطیل/پیش‌فرض'}</td>
            <td>${shift.panjshanbeh ? convertToPersianNumbers(shift.panjshanbeh) : 'پیش‌فرض'}</td>
            <td>${shift.chaharshanbeh ? convertToPersianNumbers(shift.chaharshanbeh) : 'پیش‌فرض'}</td>
            <td>${shift.seshanbeh ? convertToPersianNumbers(shift.seshanbeh) : 'پیش‌فرض'}</td>
            <td>${shift.doshanbeh ? convertToPersianNumbers(shift.doshanbeh) : 'پیش‌فرض'}</td>
            <td>${shift.yekshanbeh ? convertToPersianNumbers(shift.yekshanbeh) : 'پیش‌فرض'}</td>
            <td>${shift.shanbeh ? convertToPersianNumbers(shift.shanbeh) : 'پیش‌فرض'}</td>
            <td>${convertToPersianNumbers(String(shift.end_day))}</td>
            <td>${convertToPersianNumbers(String(shift.start_day))}</td>
        `;
        tbody.appendChild(row);
    });
}

function editShift(shiftId) {
    let shift = (currentShiftsData || []).find(s => s.id === shiftId);
    if (!shift) {
        shift = (activeShiftsData || []).find(s => s.id === shiftId);
    }
    if (!shift) return;

    document.getElementById('shiftId').value = shift.id;
    document.getElementById('shiftTitle').value = shift.title || '';
    document.getElementById('shiftStartDay').value = shift.start_day;
    document.getElementById('shiftEndDay').value = shift.end_day;

    const modalUserEl = document.getElementById('shiftModalUsername');
    const tabUser = document.getElementById('shiftUsername') ? document.getElementById('shiftUsername').value : '';
    const username = shift.username || tabUser || '';
    if (modalUserEl) modalUserEl.value = username;

    const modalYearEl = document.getElementById('shiftModalYear');
    const tabYear = document.getElementById('shiftYear') ? document.getElementById('shiftYear').value : '';
    const year = shift.jalali_year || tabYear || '';
    if (modalYearEl) modalYearEl.value = year;

    const modalMonthEl = document.getElementById('shiftModalMonth');
    const tabMonth = document.getElementById('shiftMonth') ? document.getElementById('shiftMonth').value : '';
    const month = shift.jalali_month || tabMonth || '';
    if (modalMonthEl) modalMonthEl.value = month;

    // نمایش مشخصات کاربر و دوره شیفت
    const metaContainer = document.getElementById('shiftPopupMeta');
    const metaUser = document.getElementById('shiftPopupMetaUser');
    const metaDate = document.getElementById('shiftPopupMetaDate');
    if (metaContainer && metaUser && metaDate) {
        metaUser.textContent = username || '—';
        var monthName = (typeof persianMonths !== 'undefined' && month && persianMonths[Number(month) - 1]) ? persianMonths[Number(month) - 1] : ('ماه ' + month);
        metaDate.textContent = monthName + (year ? (' ' + convertToPersianNumbers(String(year))) : '');
        metaContainer.style.display = 'flex';
    }

    SHIFT_DAY_FIELDS.forEach(f => {
        const input = document.getElementById('shift_' + f);
        if (input) input.value = shift[f] || '';
    });

    document.getElementById('shiftFormTitle').textContent = 'ویرایش بازه‌ی شیفت';
    const subTitle = document.getElementById('shiftFormSubtitle');
    if (subTitle) subTitle.textContent = 'ساعات و روزهای شیفت را تنظیم کرده و ذخیره نمایید';

    const saveBtn = document.getElementById('saveShiftBtn');
    if (saveBtn) {
        const textSpan = saveBtn.querySelector('span');
        if (textSpan) textSpan.textContent = 'اعمال تغییرات شیفت';
    }

    const cancelBtn = document.getElementById('cancelShiftEditBtn');
    if (cancelBtn) {
        cancelBtn.style.display = 'inline-flex';
        cancelBtn.textContent = 'انصراف';
    }

    const deleteBtn = document.getElementById('deleteShiftModalBtn');
    if (deleteBtn) {
        deleteBtn.style.display = 'inline-flex';
    }

    document.getElementById('shiftPopupOverlay').style.display = 'flex';
}

function saveShift() {
    const hiddenUser = document.getElementById('shiftModalUsername') ? document.getElementById('shiftModalUsername').value : '';
    const username = hiddenUser || (document.getElementById('shiftUsername') ? document.getElementById('shiftUsername').value : '');
    const hiddenYear = document.getElementById('shiftModalYear') ? document.getElementById('shiftModalYear').value : '';
    const year = hiddenYear || (document.getElementById('shiftYear') ? document.getElementById('shiftYear').value : '');
    const hiddenMonth = document.getElementById('shiftModalMonth') ? document.getElementById('shiftModalMonth').value : '';
    const month = hiddenMonth || (document.getElementById('shiftMonth') ? document.getElementById('shiftMonth').value : '');
    const shiftId = document.getElementById('shiftId').value;
    const startDay = document.getElementById('shiftStartDay').value;
    const endDay = document.getElementById('shiftEndDay').value;
    const title = document.getElementById('shiftTitle').value;

    const isEdit = !!shiftId;

    if (!isEdit && !username) {
        showSystemError('لطفاً ابتدا پرسنل مورد نظر را انتخاب کنید');
        return;
    }
    if (!startDay || !endDay) {
        showSystemError('لطفاً بازه‌ی روز را وارد کنید');
        return;
    }

    const sDay = parseInt(startDay, 10);
    const eDay = parseInt(endDay, 10);
    if (isNaN(sDay) || isNaN(eDay) || sDay < 1 || sDay > 31 || eDay < 1 || eDay > 31 || sDay > eDay) {
        showSystemError('بازه‌ی روز نامعتبر است (باید بین ۱ تا ۳۱ باشد و روز شروع نباید بعد از روز پایان باشد)');
        return;
    }

    const payload = {
        username: username,
        jalali_year: year,
        jalali_month: month,
        start_day: sDay,
        end_day: eDay,
        title: title
    };
    SHIFT_DAY_FIELDS.forEach(f => {
        const input = document.getElementById('shift_' + f);
        payload[f] = input ? input.value : '';
    });

    if (isEdit) payload.id = parseInt(shiftId, 10);

    fetch(isEdit ? '/update_shift' : '/add_shift', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showSystemError(data.message || 'خطا در ذخیره‌ی شیفت');
                return;
            }
            showSystemSuccess(data.message || (isEdit ? 'شیفت با موفقیت ویرایش شد' : 'شیفت با موفقیت ذخیره شد'));
            closeShiftPopup();
            loadActiveShifts();
            if (document.getElementById('shiftUsername') && document.getElementById('shiftUsername').value) {
                loadShifts();
            }
        })
        .catch(error => {
            console.error(error);
            showSystemError('خطا در ذخیره‌ی شیفت');
        });
}

async function deleteShift(shiftId) {
    if (!shiftId) {
        shiftId = document.getElementById('shiftId') ? document.getElementById('shiftId').value : null;
    }
    if (!shiftId) return;

    var confirmed = await HastamaUX.confirm({
        title: 'حذف شیفت',
        message: 'آیا از حذف این بازه‌ی شیفت مطمئن هستید؟',
        confirmText: 'حذف',
        danger: true
    });
    if (!confirmed) return;

    fetch(`/delete_shift/${shiftId}`, { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showSystemError(data.message || 'خطا در حذف شیفت');
                return;
            }
            showSystemSuccess(data.message || 'شیفت با موفقیت حذف شد');
            closeShiftPopup();
            loadActiveShifts();
            if (document.getElementById('shiftUsername') && document.getElementById('shiftUsername').value) {
                loadShifts();
            }
        })
        .catch(error => {
            console.error(error);
            showSystemError('خطا در حذف شیفت');
        });
}

/* ─── تب‌بندی شیفت ─── */
function switchShiftTab(tabId, btnEl) {
    document.querySelectorAll('.shift-tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.shift-tab-content').forEach(function (c) { c.classList.remove('active'); });
    if (btnEl) {
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');
    }
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');

    /* بارگذاری خودکار شیفت‌های فعال هنگام باز شدن تب دوم */
    if (tabId === 'shift-active') loadActiveShifts();
}

/* ─── بارگذاری شیفت‌های فعال امروز ─── */
function loadActiveShifts() {
    var container = document.getElementById('shiftActiveCards');
    if (!container) return;
    container.innerHTML = '<p class="shifts-empty-row">در حال بارگذاری…</p>';

    fetch('/get_active_shifts')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                container.innerHTML = '<p class="shifts-empty-row">' + (data.message || 'خطا در دریافت اطلاعات') + '</p>';
                return;
            }
            var shifts = data.shifts || [];
            activeShiftsData = shifts;
            if (shifts.length === 0) {
                container.innerHTML = '<p class="shifts-empty-row">هیچ شیفت فعالی برای امروز (روز ' + data.today + ' ماه ' + data.month + ' سال ' + data.year + ') یافت نشد</p>';
                return;
            }
            var dayNames = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];
            var dayKeys = ['shanbeh','yekshanbeh','doshanbeh','seshanbeh','chaharshanbeh','panjshanbeh','jomeh'];
            var html = '';
            shifts.forEach(function (s) {
                var scheduleRows = '';
                dayKeys.forEach(function (k, i) {
                    var val = s[k];
                    if (val && val.trim()) {
                        scheduleRows += '<div class="shift-active-card__schedule-row"><span class="shift-active-card__day">' + dayNames[i] + '</span><span class="shift-active-card__time">' + convertToPersianNumbers(val) + '</span></div>';
                    }
                });
                html += '<div class="shift-active-card" role="button" tabindex="0" onclick="editShift(' + s.id + ')" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();editShift(' + s.id + ');}" title="کلیک برای ویرایش این شیفت">'
                    + '<div class="shift-active-card__header">'
                    + '<span class="shift-active-card__user">' + esc(s.username) + '</span>'
                    + (s.title ? '<span class="shift-active-card__title">' + esc(s.title) + '</span>' : '')
                    + '<span class="shift-active-card__range">روز ' + convertToPersianNumbers(String(s.start_day)) + ' تا ' + convertToPersianNumbers(String(s.end_day)) + '</span>'
                    + '<button type="button" class="shift-active-card__edit-btn" onclick="event.stopPropagation(); editShift(' + s.id + ')" title="ویرایش شیفت" aria-label="ویرایش شیفت">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
                    + '<span>ویرایش</span>'
                    + '</button>'
                    + '</div>'
                    + (scheduleRows ? '<div class="shift-active-card__schedule">' + scheduleRows + '</div>' : '<p class="shift-active-card__empty">ساعتی ثبت نشده</p>')
                    + '<div class="shift-active-card__footer">'
                    + '<span class="shift-active-card__edit-hint">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>'
                    + 'برای ویرایش شیفت کلیک کنید'
                    + '</span>'
                    + '</div>'
                    + '</div>';
            });
            container.innerHTML = html;
        })
        .catch(function (err) {
            console.error(err);
            container.innerHTML = '<p class="shifts-empty-row">خطا در بارگذاری شیفت‌های فعال</p>';
        });
}

// ═══════════════════════════════════════════════════════════════════════
// تقویم شمسی (Persian Date Picker) — مشترک با پنل کاربری
// ═══════════════════════════════════════════════════════════════════════

function convertToEnglishNumbers(str) {
    return String(str).replace(/[\u0660-\u0669\u06F0-\u06F9]/g, function (match) {
        return ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'].indexOf(match);
    });
}

function adminGetCurrentPersianDate() {
    var formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
        year: 'numeric', month: 'numeric', day: 'numeric'
    });
    var parts = formatter.formatToParts(new Date());
    var values = {};
    parts.forEach(function (part) {
        if (part.type !== 'literal') values[part.type] = part.value;
    });
    return {
        year: Number(convertToEnglishNumbers(values.year || '1404')),
        month: Number(convertToEnglishNumbers(values.month || '1')),
        day: Number(convertToEnglishNumbers(values.day || '1'))
    };
}

function adminPersianToGregorian(year, month, day) {
    var targetKey = year * 10000 + month * 100 + day;
    var low = Date.UTC(2000, 0, 1);
    var high = Date.UTC(2050, 0, 1);
    while (low <= high) {
        var midTime = Math.floor((low + high) / 2);
        var midDate = new Date(midTime);
        var parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
            year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC'
        }).formatToParts(midDate);
        var values = {};
        parts.forEach(function (part) {
            if (part.type !== 'literal') values[part.type] = part.value;
        });
        var currentKey = Number(convertToEnglishNumbers(values.year || '0')) * 10000 +
            Number(convertToEnglishNumbers(values.month || '0')) * 100 +
            Number(convertToEnglishNumbers(values.day || '0'));
        if (currentKey === targetKey) return midDate;
        if (currentKey < targetKey) low = midTime + 86400000;
        else high = midTime - 86400000;
    }
    return new Date(Date.UTC(year, month - 1, day));
}

function adminGetPersianMonthLength(year, month) {
    if (month <= 6) return 31;
    if (month <= 11) return 30;
    return (((year * 8) + 13) % 33) < 8 ? 30 : 29;
}

function adminGetPersianWeekdayIndex(year, month, day) {
    var gregorianDate = adminPersianToGregorian(year, month, day);
    var weekday = gregorianDate.getUTCDay();
    return weekday === 6 ? 0 : weekday + 1;
}

function adminParsePersianDateValue(value) {
    if (!value) return null;
    var normalized = String(value).trim();
    var match = normalized.match(/([\u06F0-\u06F90-9]{2,4})[\/\-]([\u06F0-\u06F90-9]{1,2})[\/\-]([\u06F0-\u06F90-9]{1,2})/);
    if (!match) return null;
    return {
        year: Number(convertToEnglishNumbers(match[1])),
        month: Number(convertToEnglishNumbers(match[2])),
        day: Number(convertToEnglishNumbers(match[3]))
    };
}

function adminFormatPersianDateValue(year, month, day) {
    return convertToPersianNumbers(String(year)) + '/' +
        convertToPersianNumbers(String(month).padStart(2, '0')) + '/' +
        convertToPersianNumbers(String(day).padStart(2, '0'));
}

function adminRenderDatePicker(picker, state) {
    var monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var dayNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var days = adminGetPersianMonthLength(state.year, state.month);
    var firstWeekday = adminGetPersianWeekdayIndex(state.year, state.month, 1);

    state.day = Math.min(Math.max(state.day || 1, 1), days);

    var cells = [];
    for (var i = 0; i < firstWeekday; i++) {
        cells.push('<div class="leave-date-picker-day is-empty"></div>');
    }
    for (var day = 1; day <= days; day++) {
        var isSelected = state.day === day;
        cells.push('<button type="button" class="leave-date-picker-day' + (isSelected ? ' is-selected' : '') + '" data-action="select-day" data-day="' + day + '">' + convertToPersianNumbers(String(day)) + '</button>');
    }
    var totalCells = cells.length;
    var rows = Math.ceil(totalCells / 7);
    var remainingCells = rows * 7 - totalCells;
    for (var j = 0; j < remainingCells; j++) {
        cells.push('<div class="leave-date-picker-day is-empty"></div>');
    }

    picker.innerHTML =
        '<div class="leave-date-picker-header">' +
            '<button type="button" class="leave-date-picker-nav" data-action="prev-month">&#8249;</button>' +
            '<div class="leave-date-picker-controls">' +
                '<select id="' + picker.dataset.inputId + '-month' + '" name="' + picker.dataset.inputId + '-month' + '" class="leave-date-picker-month" data-action="month-change">' +
                    monthNames.map(function (name, index) {
                        return '<option value="' + (index + 1) + '"' + (index + 1 === state.month ? ' selected' : '') + '>' + name + '</option>';
                    }).join('') +
                '</select>' +
                '<select id="' + picker.dataset.inputId + '-year' + '" name="' + picker.dataset.inputId + '-year' + '" class="leave-date-picker-year" data-action="year-change">' +
                    Array.from({ length: 21 }, function (_, i) { return state.year - 10 + i; }).map(function (year) {
                        return '<option value="' + year + '"' + (year === state.year ? ' selected' : '') + '>' + convertToPersianNumbers(String(year)) + '</option>';
                    }).join('') +
                '</select>' +
            '</div>' +
            '<button type="button" class="leave-date-picker-nav" data-action="next-month">&#8250;</button>' +
        '</div>' +
        '<div class="leave-date-picker-weekdays">' +
            dayNames.map(function (name) { return '<div class="leave-date-picker-weekday">' + name + '</div>'; }).join('') +
        '</div>' +
        '<div class="leave-date-picker-days">' +
            cells.join('') +
        '</div>';

    picker.querySelector('[data-action="prev-month"]').addEventListener('click', function () {
        state.month -= 1;
        if (state.month < 1) { state.month = 12; state.year -= 1; }
        adminRenderDatePicker(picker, state);
    });

    picker.querySelector('[data-action="next-month"]').addEventListener('click', function () {
        state.month += 1;
        if (state.month > 12) { state.month = 1; state.year += 1; }
        adminRenderDatePicker(picker, state);
    });

    picker.querySelector('.leave-date-picker-month').addEventListener('change', function (event) {
        state.month = Number(event.target.value);
        adminRenderDatePicker(picker, state);
    });

    picker.querySelector('.leave-date-picker-year').addEventListener('change', function (event) {
        state.year = Number(event.target.value);
        adminRenderDatePicker(picker, state);
    });

    picker.querySelectorAll('[data-action="select-day"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var selectedDay = Number(btn.dataset.day);
            state.day = selectedDay;
            var targetInput = document.getElementById(picker.dataset.inputId);
            if (targetInput) {
                targetInput.value = adminFormatPersianDateValue(state.year, state.month, state.day);
                targetInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            picker.hidden = true;
        });
    });
}

function adminPositionDatePicker(input, picker) {
    var rect = input.getBoundingClientRect();
    var pickerWidth = 320;
    picker.style.transform = '';

    // محاسبه فضا بالا و پایین ورودی
    var spaceBelow = window.innerHeight - rect.bottom;
    var spaceAbove = rect.top;
    var showBelow = spaceBelow >= 360 || spaceBelow > spaceAbove;

    if (showBelow) {
        picker.style.top = (rect.bottom + 6) + 'px';
    } else {
        picker.style.top = (rect.top - 6) + 'px';
        picker.style.transform = 'translateY(-100%)';
    }

    // راست‌چین: لبه راست تقویم با لبه راست ورودی
    var leftPos = rect.right - pickerWidth;
    if (leftPos < 8) leftPos = 8;
    if (leftPos + pickerWidth > window.innerWidth - 8) leftPos = window.innerWidth - pickerWidth - 8;
    picker.style.left = leftPos + 'px';
}

function adminOpenDatePicker(input, picker) {
    var parsed = adminParsePersianDateValue(input.value);
    var today = adminGetCurrentPersianDate();
    var state = {
        year: parsed ? parsed.year : today.year,
        month: parsed ? parsed.month : today.month,
        day: parsed ? parsed.day : today.day
    };
    picker.dataset.inputId = input.id;
    picker.hidden = false;
    adminRenderDatePicker(picker, state);
    adminPositionDatePicker(input, picker);
}

function adminAttachDatePickerToInput(input) {
    if (!input || input.dataset.datePickerBound === 'true') return;

    // ایجاد تقویم به‌صورت مستقیم در body برای فرار از clip والدین
    var picker = document.querySelector('.leave-date-picker[data-input-id="' + input.id + '"]');
    if (!picker) {
        picker = document.createElement('div');
        picker.className = 'leave-date-picker';
        picker.hidden = true;
        picker.setAttribute('role', 'dialog');
        picker.setAttribute('aria-label', 'انتخاب تاریخ');
        picker.dataset.inputId = input.id;
        document.body.appendChild(picker);
    }

    input.addEventListener('focus', function (event) {
        event.stopPropagation();
        adminOpenDatePicker(input, picker);
    });
    input.addEventListener('click', function (event) {
        event.stopPropagation();
        adminOpenDatePicker(input, picker);
    });
    input.addEventListener('touchstart', function (event) {
        event.stopPropagation();
        adminOpenDatePicker(input, picker);
    }, { passive: true });

    input.dataset.datePickerBound = 'true';
}

function initAdminDatePickers() {
    var dateInputIds = [
        'fromDate', 'toDate',
        'start_date', 'end_date',
        'start_date_hourlypass', 'end_date_hourlypass',
        'start_date_hozoor', 'end_date_hozoor'
    ];

    dateInputIds.forEach(function (inputId) {
        var input = document.getElementById(inputId);
        if (input) adminAttachDatePickerToInput(input);
    });

    // بستن تقویم با کلیک خارج
    document.addEventListener('click', function (event) {
        var target = event.target;
        // اگر روی خود تقویم یا ورودی تاریخ کلیک شده، کاری نکن
        if (target.closest('.leave-date-picker') || target.closest('[data-date-picker-bound]')) return;
        document.querySelectorAll('.leave-date-picker').forEach(function (picker) {
            picker.hidden = true;
        });
    });

    // بستن تقویم با اسکرول صفحه
    window.addEventListener('scroll', function () {
        document.querySelectorAll('.leave-date-picker').forEach(function (picker) {
            picker.hidden = true;
        });
    }, { passive: true });
}

document.addEventListener('DOMContentLoaded', initAdminDatePickers);
