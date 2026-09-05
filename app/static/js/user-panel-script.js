// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها
// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها
// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها// فارسی سازی اعداد و فیلدها

// فرمت کردن اعداد ورودی به فارسی
function formatPersianNumbers(event) {
    let input = event.target;
    let value = input.value.replace(/[^\d\u0660-\u0669\u06F0-\u06F9]/g, ''); // فقط اعداد فارسی یا انگلیسی مجاز هستند
    input.value = convertToPersianNumbers(parseInt(convertToEnglishNumbers(value)) || 1); // مقدار پیش‌فرض ۱
}

// تبدیل اعداد به فارسی در فیلدها و placeholder به صورت زنده
['startDate', 'endDate', 'substitute'].forEach(function(elementId) {
    var element = document.getElementById(elementId);
    if (element) {
        element.addEventListener('input', function() {
            this.value = convertToPersianNumbers(this.value);
        });
    }
});

// تبدیل placeholder ها به فارسی
function convertPlaceholdersToPersian() {
    document.querySelectorAll('input[placeholder], textarea[placeholder]').forEach(function(input) {
        input.placeholder = convertToPersianNumbers(input.placeholder);
    });
}

// اعمال تبدیل placeholderها زمانی که صفحه بارگذاری می‌شود
window.addEventListener('load', function() {convertPlaceholdersToPersian();});

// Delegated handlers to replace inline onclick/onchange attributes (CSP-safe)
window.addEventListener('load', function() {
    // Click delegation for elements with data-action
    document.body.addEventListener('click', function(e) {
        var el = e.target.closest('[data-action]');
        if (!el) return;
        var action = el.getAttribute('data-action');
        switch (action) {
            case 'toggle-theme':
                // theme.js خودش این کلیک را می‌گیرد و تم را ذخیره می‌کند؛
                // اینجا فقط برای حالتی که theme.js لود نشده باشد fallback داریم.
                if (!window.HastamaTheme) {
                    document.body.classList.toggle('dark-mode');
                    document.body.classList.toggle('dark-theme');
                }
                break;
            case 'open-file-input':
                var fi = document.getElementById('fileInput'); if (fi) fi.click();
                break;
            case 'confirm-delete':
                if (typeof confirmDelete === 'function') confirmDelete();
                break;
            case 'open-hourly-pass':
                if (typeof openHourlyPassModal === 'function') openHourlyPassModal();
                break;
            case 'open-overtime':
                if (typeof openOvertimeModal === 'function') openOvertimeModal();
                break;
            case 'open-leave':
                if (typeof openLeaveModal === 'function') openLeaveModal();
                break;
            case 'open-ticket':
                if (typeof openTicketModal === 'function') openTicketModal();
                break;
            case 'open-more-morakhc':
                var target = document.getElementById('showMoreMorakhc'); if (target) target.click();
                break;
            case 'open-ticket-list':
                if (typeof openTicketListPopup === 'function') openTicketListPopup();
                break;
            case 'attendance-checkin':
                submitUserAttendance('checkin');
                break;
            case 'attendance-checkout':
                submitUserAttendance('checkout');
                break;
            case 'logout':
                if (typeof logout === 'function') logout();
                break;
            case 'toggle-settings-panel':
                toggleSettingsPanel();
                break;
            case 'close-settings-panel':
                closeSettingsPanel();
                break;
            case 'open-profile-panel':
                openProfilePanel();
                break;
            case 'close-profile-panel':
                closeProfilePanel();
                break;
        }
    });

    // File input change handler -> submit upload form
    var fileInputEl = document.getElementById('fileInput');
    if (fileInputEl) {
        fileInputEl.addEventListener('change', function() {
            var uploadForm = document.getElementById('uploadForm');
            if (uploadForm) uploadForm.submit();
        });
    }

    // Mobile menu button is handled inline in the template to avoid double toggling.
});

function updatePresenceFromAttendance(checkIn, checkOut, serverNow, workStart, workEnd) {
    var wrap = document.querySelector('.timeline-ring-wrap');
    if (!wrap) return;

    wrap.setAttribute('data-entry-time', checkIn || '');
    wrap.setAttribute('data-check-out', checkOut || '');
    if (serverNow) wrap.setAttribute('data-server-now', serverNow);
    if (workStart) wrap.setAttribute('data-work-start', workStart);
    if (workEnd) wrap.setAttribute('data-work-end', workEnd);
    wrap.dataset.loadedAtMs = String(Date.now());

    if (typeof updatePresenceRing === 'function') updatePresenceRing();
}

function updateUserAttendanceState(status, checkIn, checkOut, serverNow, workStart, workEnd) {
    var card = document.getElementById('attendanceActionCard');
    if (!card) return;

    var checkInButton = card.querySelector('[data-action="attendance-checkin"]');
    var checkOutButton = card.querySelector('[data-action="attendance-checkout"]');
    var statusEl = document.getElementById('attendanceActionStatus');
    var checkInEl = document.getElementById('attendanceCheckInText');
    var checkOutEl = document.getElementById('attendanceCheckOutText');

    if (checkInEl) checkInEl.textContent = checkIn || '--:--';
    if (checkOutEl) checkOutEl.textContent = checkOut || '--:--';
    updatePresenceFromAttendance(checkIn, checkOut, serverNow, workStart, workEnd);
    if (checkInButton) checkInButton.disabled = status !== 'not_checked_in';
    if (checkOutButton) checkOutButton.disabled = status !== 'checked_in';
    if (statusEl) {
        statusEl.textContent = status === 'checked_in'
            ? 'ورود ثبت شده؛ خروج خود را ثبت کنید'
            : status === 'checked_out'
                ? 'ورود و خروج امروز ثبت شده است'
                : 'هنوز ورود امروز ثبت نشده است';
        statusEl.dataset.status = status || 'not_checked_in';
    }
}

function loadUserAttendanceState() {
    var card = document.getElementById('attendanceActionCard');
    if (!card) return;
    fetch('/get_hozoor_today', { credentials: 'same-origin' })
        .then(function(response) {
            if (!response.ok) throw new Error('خطا در دریافت وضعیت حضور');
            return response.json();
        })
        .then(function(payload) {
            var current = payload.data && payload.data.users && payload.data.users[0];
            if (current) updateUserAttendanceState(
                current.status, current.check_in, current.check_out,
                payload.data.server_now, payload.data.work_start, payload.data.work_end
            );
            else updateUserAttendanceState(
                'not_checked_in', null, null, payload.data && payload.data.server_now,
                payload.data && payload.data.work_start, payload.data && payload.data.work_end
            );
        })
        .catch(function(error) {
            var statusEl = document.getElementById('attendanceActionStatus');
            if (statusEl) statusEl.textContent = error.message;
        });
}

function submitUserAttendance(action) {
    var card = document.getElementById('attendanceActionCard');
    if (!card || card.dataset.loading === 'true') return;

    var username = card.getAttribute('data-username');
    var endpoint = action === 'checkin' ? '/sabt_hozoor_checkin' : '/sabt_hozoor_checkout';
    card.dataset.loading = 'true';

    fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ username: username })
    })
        .then(function(response) {
            return response.json().then(function(payload) {
                if (!response.ok || !payload.success) throw new Error(payload.message || 'ثبت حضور انجام نشد');
                return payload;
            });
        })
        .then(function(payload) {
            var data = payload.data || {};
            updateUserAttendanceState(data.status, data.check_in, data.check_out, data.server_now);
        })
        .catch(function(error) {
            var statusEl = document.getElementById('attendanceActionStatus');
            if (statusEl) statusEl.textContent = error.message;
            if (action === 'checkin') loadUserAttendanceState();
        })
        .finally(function() {
            card.dataset.loading = 'false';
        });
}

window.addEventListener('load', loadUserAttendanceState);

// تبدیل اعداد انگلیسی به فارسی
function convertToPersianNumbers(str) {
    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str.replace(/\d/g, function (match) {
        return persianNumbers[match];
    });
}

// تبدیل اعداد فارسی به انگلیسی
function convertToEnglishNumbers(str) {
    const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str.replace(/[\u0660-\u0669\u06F0-\u06F9]/g, function (match) {
        return englishNumbers['۰۱۲۳۴۵۶۷۸۹'.indexOf(match)];
    });
}

// فرمت کردن اعداد ورودی به فارسی
function formatPersianNumbers(event) {
    let input = event.target;
    let value = input.value.replace(/[^\d\u0660-\u0669\u06F0-\u06F9]/g, ''); // فقط اعداد فارسی یا انگلیسی مجاز هستند
    input.value = convertToPersianNumbers(parseInt(convertToEnglishNumbers(value)) || 1); // مقدار پیش‌فرض ۱
}

// اعمال تبدیل placeholderها زمانی که صفحه بارگذاری می‌شود
window.addEventListener('load', function() {convertPlaceholdersToPersian();});

function toggleSettingsPanel() {
    var panel = document.getElementById('settingsPanel');
    if (!panel) return;
    panel.classList.toggle('is-open');
    document.body.classList.toggle('settings-panel-open', panel.classList.contains('is-open'));
    panel.setAttribute('aria-hidden', panel.classList.contains('is-open') ? 'false' : 'true');
}

function closeSettingsPanel() {
    var panel = document.getElementById('settingsPanel');
    if (!panel) return;
    panel.classList.remove('is-open');
    document.body.classList.remove('settings-panel-open');
    panel.setAttribute('aria-hidden', 'true');
}

function openProfilePanel() {
    var panel = document.getElementById('profilePanel');
    if (!panel) return;
    panel.classList.add('is-open');
    panel.removeAttribute('inert');
    document.body.classList.add('profile-panel-open');
}

function closeProfilePanel() {
    var panel = document.getElementById('profilePanel');
    if (!panel) return;
    panel.classList.remove('is-open');
    panel.setAttribute('inert', '');
    document.body.classList.remove('profile-panel-open');
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSettingsPanel();
        closeProfilePanel();
        closeMobileSidebar();
    }
});

document.addEventListener('click', function (event) {
    var toggle = event.target.closest('.settings-accordion-toggle');
    if (!toggle) return;

    var section = toggle.closest('.settings-section');
    if (!section) return;

    var isOpen = section.classList.contains('is-open');
    document.querySelectorAll('.settings-section.is-open').forEach(function (item) {
        if (item !== section) {
            item.classList.remove('is-open');
        }
    });

    section.classList.toggle('is-open', !isOpen);
});

var settingsPanelBackdrop = document.getElementById('settingsPanelBackdrop');
if (settingsPanelBackdrop) {
    settingsPanelBackdrop.addEventListener('click', closeSettingsPanel);
}

// تابع تبدیل اعداد به فارسی
function convertToPersian(input) {
    var englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    var persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    input.value = input.value.replace(/[0-9]/g, function(w) {
        return persianNumbers[englishNumbers.indexOf(w)];
    });
}

function toPersianDigits(input) {
    const persianDigits = "۰۱۲۳۴۵۶۷۸۹";
    return input.replace(/\d/g, (digit) => persianDigits[digit]);
}

function formatAttendanceTime(value) {
    if (value === null || value === undefined) return '--:--';

    const normalized = String(value).trim();
    if (!normalized || normalized === '0000') return '--:--';

    const digits = convertToEnglishNumbers(normalized).replace(/\D/g, '');
    if (!digits) return '--:--';

    if (digits.length >= 4) {
        return convertToPersianNumbers(`${digits.slice(0, 2)}:${digits.slice(2, 4)}`);
    }

    if (digits.length === 3) {
        return convertToPersianNumbers(`${digits.slice(0, 1)}:${digits.slice(1)}`);
    }

    if (digits.length === 2) {
        return convertToPersianNumbers(`${digits}:00`);
    }

    return convertToPersianNumbers(normalized);
}

function getCurrentPersianDate() {
    const formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric'
    });
    const parts = formatter.formatToParts(new Date());
    const values = {};
    parts.forEach((part) => {
        if (part.type !== 'literal') {
            values[part.type] = part.value;
        }
    });
    return {
        year: Number(convertToEnglishNumbers(values.year || '1404')),
        month: Number(convertToEnglishNumbers(values.month || '1')),
        day: Number(convertToEnglishNumbers(values.day || '1'))
    };
}

function getPersianMonthLength(year, month) {
    if (month <= 6) return 31;
    if (month <= 11) return 30;
    return isPersianLeapYear(year) ? 30 : 29;
}

function isPersianLeapYear(year) {
    return (((year * 8) + 13) % 33) < 8;
}

function getPersianWeekdayIndex(year, month, day) {
    const gregorianDate = persianToGregorian(year, month, day);
    const weekday = gregorianDate.getUTCDay();

    // در تقویم فارسی، شنبه اولین روز هفته است: شنبه=0, یکشنبه=1, ..., جمعه=6
    if (weekday === 6) return 0;
    return weekday + 1;
}

function parsePersianDateValue(value) {
    if (!value) return null;
    const normalized = String(value).trim();
    const match = normalized.match(/([۰-۹0-9]{2,4})[\/-]([۰-۹0-9]{1,2})[\/-]([۰-۹0-9]{1,2})/);
    if (!match) return null;
    return {
        year: Number(convertToEnglishNumbers(match[1])),
        month: Number(convertToEnglishNumbers(match[2])),
        day: Number(convertToEnglishNumbers(match[3]))
    };
}

function formatPersianDateValue(year, month, day) {
    return `${convertToPersianNumbers(String(year))}/${convertToPersianNumbers(String(month).padStart(2, '0'))}/${convertToPersianNumbers(String(day).padStart(2, '0'))}`;
}

function persianToGregorian(year, month, day) {
    const targetKey = year * 10000 + month * 100 + day;
    let low = Date.UTC(2000, 0, 1);
    let high = Date.UTC(2050, 0, 1);

    while (low <= high) {
        const midTime = Math.floor((low + high) / 2);
        const midDate = new Date(midTime);
        const parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            timeZone: 'UTC'
        }).formatToParts(midDate);

        const values = {};
        parts.forEach((part) => {
            if (part.type !== 'literal') {
                values[part.type] = part.value;
            }
        });

        const currentKey = Number(convertToEnglishNumbers(values.year || '0')) * 10000 +
            Number(convertToEnglishNumbers(values.month || '0')) * 100 +
            Number(convertToEnglishNumbers(values.day || '0'));

        if (currentKey === targetKey) {
            return midDate;
        }

        if (currentKey < targetKey) {
            low = midTime + 86400000;
        } else {
            high = midTime - 86400000;
        }
    }

    return new Date(Date.UTC(year, month - 1, day));
}

function renderLeaveDatePicker(picker, state) {
    const monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const dayNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    const days = getPersianMonthLength(state.year, state.month);
    const firstWeekday = getPersianWeekdayIndex(state.year, state.month, 1);

    state.day = Math.min(Math.max(state.day || 1, 1), days);

    const cells = [];
    for (let i = 0; i < firstWeekday; i += 1) {
        cells.push('<div class="leave-date-picker-day is-empty"></div>');
    }

    for (let day = 1; day <= days; day += 1) {
        const isSelected = state.day === day;
        cells.push(`<button type="button" class="leave-date-picker-day${isSelected ? ' is-selected' : ''}" data-action="select-day" data-day="${day}">${convertToPersianNumbers(String(day))}</button>`);
    }

    const totalCells = cells.length;
    const rows = Math.ceil(totalCells / 7);
    const remainingCells = rows * 7 - totalCells;
    for (let i = 0; i < remainingCells; i += 1) {
        cells.push('<div class="leave-date-picker-day is-empty"></div>');
    }

    picker.innerHTML = `
        <div class="leave-date-picker-header">
            <button type="button" class="leave-date-picker-nav" data-action="prev-month">‹</button>
            <div class="leave-date-picker-controls">
                <select class="leave-date-picker-month" data-action="month-change">
                    ${monthNames.map((name, index) => `<option value="${index + 1}" ${index + 1 === state.month ? 'selected' : ''}>${name}</option>`).join('')}
                </select>
                <select class="leave-date-picker-year" data-action="year-change">
                    ${Array.from({ length: 21 }, (_, i) => state.year - 10 + i).map((year) => `<option value="${year}" ${year === state.year ? 'selected' : ''}>${convertToPersianNumbers(String(year))}</option>`).join('')}
                </select>
            </div>
            <button type="button" class="leave-date-picker-nav" data-action="next-month">›</button>
        </div>
        <div class="leave-date-picker-weekdays">
            ${dayNames.map((name) => `<div class="leave-date-picker-weekday">${name}</div>`).join('')}
        </div>
        <div class="leave-date-picker-days">
            ${cells.join('')}
        </div>
    `;

    picker.querySelector('[data-action="prev-month"]').addEventListener('click', () => {
        state.month -= 1;
        if (state.month < 1) {
            state.month = 12;
            state.year -= 1;
        }
        renderLeaveDatePicker(picker, state);
    });

    picker.querySelector('[data-action="next-month"]').addEventListener('click', () => {
        state.month += 1;
        if (state.month > 12) {
            state.month = 1;
            state.year += 1;
        }
        renderLeaveDatePicker(picker, state);
    });

    picker.querySelector('.leave-date-picker-month').addEventListener('change', (event) => {
        state.month = Number(event.target.value);
        renderLeaveDatePicker(picker, state);
    });

    picker.querySelector('.leave-date-picker-year').addEventListener('change', (event) => {
        state.year = Number(event.target.value);
        renderLeaveDatePicker(picker, state);
    });

    picker.querySelectorAll('[data-action="select-day"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const selectedDay = Number(btn.dataset.day);
            state.day = selectedDay;
            const targetInput = document.getElementById(picker.dataset.inputId);
            if (targetInput) {
                targetInput.value = formatPersianDateValue(state.year, state.month, state.day);
                targetInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            picker.hidden = true;
        });
    });
}

function openLeaveDatePicker(input, picker) {
    const parsed = parsePersianDateValue(input.value);
    const today = getCurrentPersianDate();
    const state = {
        year: parsed ? parsed.year : today.year,
        month: parsed ? parsed.month : today.month,
        day: parsed ? parsed.day : today.day
    };
    picker.dataset.inputId = input.id;
    picker.hidden = false;
    renderLeaveDatePicker(picker, state);
}

function attachDatePickerToInput(input) {
    if (!input || input.dataset.datePickerBound === 'true') return;

    let shell = input.closest('.date-input-shell');
    if (!shell) {
        shell = document.createElement('div');
        shell.className = 'date-input-shell';
        input.parentNode.insertBefore(shell, input);
        shell.appendChild(input);
    }

    let picker = shell.querySelector('.leave-date-picker');
    if (!picker) {
        picker = document.createElement('div');
        picker.className = 'leave-date-picker';
        picker.hidden = true;
        picker.setAttribute('role', 'dialog');
        picker.setAttribute('aria-label', 'انتخاب تاریخ');
        shell.appendChild(picker);
    }

    input.addEventListener('focus', (event) => {
        event.stopPropagation();
        openLeaveDatePicker(input, picker);
    });
    input.addEventListener('click', (event) => {
        event.stopPropagation();
        openLeaveDatePicker(input, picker);
    });
    input.addEventListener('touchstart', (event) => {
        event.stopPropagation();
        openLeaveDatePicker(input, picker);
    }, { passive: true });

    input.dataset.datePickerBound = 'true';
}

function initLeaveDatePickers() {
    ['startDate', 'endDate', 'overtimeDate'].forEach((inputId) => {
        attachDatePickerToInput(document.getElementById(inputId));
    });

    document.querySelectorAll('#hourlyPassModal input[id="date"]').forEach((input) => {
        attachDatePickerToInput(input);
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.date-input-shell').forEach((shell) => {
            const picker = shell.querySelector('.leave-date-picker');
            if (!picker) return;
            if (!shell.contains(event.target)) {
                picker.hidden = true;
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initLeaveDatePickers);

// تابع برای فرمت کردن زمان
function formatTime(timeString) {
    // تبدیل رشته زمان به شیء Date بدون افزودن 'Z'
    const time = new Date('1970-01-01T' + timeString); 

    const hours = time.getHours().toString().padStart(2, '0'); // گرفتن ساعت و افزودن صفر به ابتدای آن
    const minutes = time.getMinutes().toString().padStart(2, '0'); // گرفتن دقیقه و افزودن صفر به ابتدای آن

    return hours + ':' + minutes; // برگرداندن فرمت جدید
}

// تبدیل اعداد به فارسی
function convertToPersianNumbers(input) {
    const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    let output = input;

    // تبدیل هر عدد انگلیسی به عدد فارسی
    for (let i = 0; i < englishNumbers.length; i++) {
        const regex = new RegExp(englishNumbers[i], 'g');
        output = output.replace(regex, persianNumbers[i]);
    }
    
    return output;
}

//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری
//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری
//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری//تنظمیات نوار ابزار کناری

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

let ringAnimationToken = 0;
let ringCurrentGreenLength = 0;
let ringCurrentBlueLength = 0;
let ringCurrentBlueOffset = 0;

function normalizeShiftMinutes(currentMinutes, startMinutes, endMinutes) {
    let effectiveStartMinutes = startMinutes;
    let effectiveEndMinutes = endMinutes;

    if (effectiveEndMinutes <= effectiveStartMinutes) {
        effectiveEndMinutes += 24 * 60;
    }

    let normalizedCurrentMinutes = currentMinutes;
    if (effectiveEndMinutes > 24 * 60 && normalizedCurrentMinutes < effectiveStartMinutes) {
        normalizedCurrentMinutes += 24 * 60;
    }

    return {
        effectiveStartMinutes,
        effectiveEndMinutes,
        normalizedCurrentMinutes
    };
}

function getShiftTimelineState(currentMinutes, startMinutes, endMinutes) {
    const { effectiveStartMinutes, effectiveEndMinutes, normalizedCurrentMinutes } = normalizeShiftMinutes(currentMinutes, startMinutes, endMinutes);
    const duration = Math.max(1, effectiveEndMinutes - effectiveStartMinutes);

    let progressPercent = 0;
    let workedMinutes = 0;
    let overtimeMinutes = 0;

    if (normalizedCurrentMinutes <= effectiveStartMinutes) {
        progressPercent = 0;
    } else if (normalizedCurrentMinutes < effectiveEndMinutes) {
        workedMinutes = normalizedCurrentMinutes - effectiveStartMinutes;
        progressPercent = Math.min(100, Math.max(0, (workedMinutes / duration) * 100));
    } else {
        workedMinutes = duration;
        progressPercent = 100;
        overtimeMinutes = Math.max(0, normalizedCurrentMinutes - effectiveEndMinutes);
    }

    return {
        progressPercent,
        workedMinutes,
        overtimeMinutes,
        duration,
        effectiveStartMinutes,
        effectiveEndMinutes,
        normalizedCurrentMinutes
    };
}

function updatePresenceRing() {
    const wrap = document.querySelector('.timeline-ring-wrap');
    if (!wrap) return;

    const entryTime = wrap.getAttribute('data-entry-time');
    const checkOutTime = wrap.getAttribute('data-check-out');
    const workStart = wrap.getAttribute('data-work-start');
    const workEnd = wrap.getAttribute('data-work-end');
    const serverNow = wrap.getAttribute('data-server-now');
    const greenCircle = document.getElementById('presenceRingGreen');
    const blueCircle = document.getElementById('presenceRingBlue');
    const checkInText = document.getElementById('ringCheckInText');
    const checkOutText = document.getElementById('ringCheckOutText');
    const workHoursText = document.getElementById('ringWorkHoursText');
    const overtimeText = document.getElementById('ringOvertimeText');

    if (!greenCircle || !blueCircle) return;

    const circumference = 327;

    const applyProgress = (circle, length, offset = 0) => {
        circle.style.strokeDasharray = `${length} ${circumference}`;
        circle.style.strokeDashoffset = `${offset}`;
    };

    const resetRing = () => {
        ringCurrentGreenLength = 0;
        ringCurrentBlueLength = 0;
        ringCurrentBlueOffset = 0;
        applyProgress(greenCircle, 0, 0);
        applyProgress(blueCircle, 0, 0);
    };

    const animateTo = (targetGreenPercent, targetBluePercent) => {
        const duration = 900;
        const startTime = performance.now();
        const startGreenLength = ringCurrentGreenLength;
        const startBlueLength = ringCurrentBlueLength;
        const startBlueOffset = ringCurrentBlueOffset;
        const targetGreenLength = (circumference * targetGreenPercent) / 100;
        const targetBlueLength = (circumference * targetBluePercent) / 100;
        const targetBlueOffset = -targetGreenLength;
        const animationId = ++ringAnimationToken;

        const tick = (now) => {
            if (animationId !== ringAnimationToken) return;
            const elapsed = now - startTime;
            const progress = Math.min(1, elapsed / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            const currentGreenLength = startGreenLength + (targetGreenLength - startGreenLength) * eased;
            const currentBlueLength = startBlueLength + (targetBlueLength - startBlueLength) * eased;
            const currentBlueOffset = startBlueOffset + (targetBlueOffset - startBlueOffset) * eased;

            ringCurrentGreenLength = currentGreenLength;
            ringCurrentBlueLength = currentBlueLength;
            ringCurrentBlueOffset = currentBlueOffset;

            greenCircle.style.transition = 'stroke-dasharray 0.9s ease, stroke-dashoffset 0.9s ease';
            blueCircle.style.transition = 'stroke-dasharray 0.9s ease, stroke-dashoffset 0.9s ease';
            applyProgress(greenCircle, currentGreenLength, 0);
            applyProgress(blueCircle, currentBlueLength, currentBlueOffset);

            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        };

        requestAnimationFrame(tick);
    };

    if (checkOutText) {
        checkOutText.textContent = checkOutTime
            ? convertToPersianNumbers(String(checkOutTime).trim())
            : '--:--';
    }

    if (checkInText) {
        checkInText.textContent = entryTime ? convertToPersianNumbers(String(entryTime).trim()) : '--:--';
    }

    const parseClock = (value) => {
        if (!value) return null;
        const cleaned = convertToEnglishNumbers(String(value).trim());
        const parts = cleaned.split(':');
        if (parts.length < 2) return null;
        const hours = parseInt(parts[0], 10);
        const minutes = parseInt(parts[1], 10);
        if (Number.isNaN(hours) || Number.isNaN(minutes)) return null;
        return hours * 60 + minutes;
    };

    const getCurrentMinutes = () => {
        const serverMinutes = parseClock(serverNow);
        if (serverMinutes === null) {
            const now = new Date();
            return now.getHours() * 60 + now.getMinutes();
        }

        if (!wrap.dataset.loadedAtMs) {
            wrap.dataset.loadedAtMs = String(Date.now());
        }

        const elapsedMinutes = Math.floor((Date.now() - Number(wrap.dataset.loadedAtMs)) / 60000);
        return serverMinutes + elapsedMinutes;
    };

    let startMinutes = parseClock(workStart);
    let endMinutes = parseClock(workEnd);
    const currentMinutes = getCurrentMinutes();

    if (endMinutes === null || startMinutes === null) {
        resetRing();
        return;
    }

    // Work-hour options are stored/displayed as the later time first (for example 15:00 - 08:00).
    // The timeline uses the actual same-day duration, so normalize that order before calculating progress.
    if (endMinutes < startMinutes) {
        [startMinutes, endMinutes] = [endMinutes, startMinutes];
    }

    const shiftState = getShiftTimelineState(currentMinutes, startMinutes, endMinutes);
    const entryMinutes = parseClock(entryTime);
    const checkOutMinutes = parseClock(checkOutTime);
    const currentNormalizedMinutes = shiftState.normalizedCurrentMinutes;

    // Helper to compute elapsed minutes since entry, using normalized current minutes
    const computeElapsedSinceEntry = (entryMin, normalizedCurrentMin) => {
        if (!Number.isInteger(entryMin) || !Number.isInteger(normalizedCurrentMin)) return 0;
        let normalizedCurrent = normalizedCurrentMin;
        if (normalizedCurrent < entryMin) normalizedCurrent += 24 * 60;
        let diff = Math.max(0, normalizedCurrent - entryMin);
        // If the page has been open many hours/days, avoid counting whole days repeatedly.
        // Keep the elapsed within a 24-hour window so displayed worked time matches reality.
        if (diff >= 24 * 60) {
            diff = diff % (24 * 60);
        }
        return diff;
    };

    let workedMinutes = 0;
    let overtimeMinutes = shiftState.overtimeMinutes;

    if (entryMinutes !== null && checkOutMinutes !== null) {
        let normalizedCheckOutMinutes = checkOutMinutes;
        if (normalizedCheckOutMinutes < entryMinutes) {
            normalizedCheckOutMinutes += 24 * 60;
        }
        workedMinutes = Math.max(
            0,
            Math.min(normalizedCheckOutMinutes, shiftState.effectiveEndMinutes) - entryMinutes
        );
        overtimeMinutes = Math.max(0, normalizedCheckOutMinutes - shiftState.effectiveEndMinutes);
    } else if (entryMinutes !== null) {
        workedMinutes = computeElapsedSinceEntry(entryMinutes, currentNormalizedMinutes);
    } else {
        workedMinutes = shiftState.workedMinutes;
    }

    // If the user has an explicit check-in but no checkout, compute progress live.
    let displayWorkedMinutes = shiftState.workedMinutes;
    displayWorkedMinutes = workedMinutes;

    const greenPercent = Math.min(100, Math.max(0, (displayWorkedMinutes / Math.max(shiftState.duration, 1)) * 100));
    const bluePercent = Math.min(100, Math.max(0, (overtimeMinutes / Math.max(shiftState.duration, 1)) * 100));
    const greenLength = (circumference * greenPercent) / 100;
    const blueLength = (circumference * bluePercent) / 100;
    const blueOffset = -greenLength;

    animateTo(greenPercent, bluePercent);

    const formatMinutes = (totalMinutes) => {
        const hours = Math.floor(Math.max(0, totalMinutes) / 60);
        const minutes = Math.max(0, totalMinutes) % 60;
        return convertToPersianNumbers(`${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`);
    };

    if (workHoursText) {
        workHoursText.textContent = formatMinutes(workedMinutes);
    }
    if (overtimeText) {
        overtimeText.textContent = `${formatMinutes(overtimeMinutes)} ساعت`;
    }
}

window.addEventListener('load', function() {
    updatePresenceRing();
    setInterval(updatePresenceRing, 30000);
});

function closeMobileSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    if (!sidebar) return;
    sidebar.classList.remove('open');
    document.body.classList.remove('mobile-sidebar-open');
    const toggle = document.querySelector('.mobile-menu-toggle');
    if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
    }
    collapseReportsSubmenu();
    document.removeEventListener('click', documentClickCloseSidebar);
}

function isSidebarExpanded() {
    const sidebar = document.querySelector('.sidebar-right');
    if (!sidebar) return false;
    // Consider it expanded if it has the `open` class, is hovered, or
    // its computed width is large (desktop expanded state).
    try {
        const computedWidth = parseFloat(getComputedStyle(sidebar).width) || 0;
        return sidebar.classList.contains('open') || sidebar.matches(':hover') || computedWidth > 100;
    } catch (e) {
        return sidebar.classList.contains('open') || sidebar.matches(':hover');
    }
}

function collapseReportsSubmenu() {
    const reportsItem = document.getElementById('showMoreEzafetime');
    const reportsSubmenu = document.getElementById('reportsSubmenu');
    if (reportsItem) {
        reportsItem.classList.remove('expanded');
        reportsItem.setAttribute('aria-expanded', 'false');
    }
    if (reportsSubmenu) {
        reportsSubmenu.style.display = 'none';
    }
}

function toggleSidebar(event) {
    const sidebar = document.querySelector('.sidebar-right');
    if (!sidebar) return;

    if (event) {
        event.stopPropagation();
    }

    const isOpen = sidebar.classList.toggle('open');
    document.body.classList.toggle('mobile-sidebar-open', isOpen && window.innerWidth <= 860);
    const toggle = document.querySelector('.mobile-menu-toggle');
    if (toggle) {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
    if (!isOpen) {
        collapseReportsSubmenu();
    }

    if (isOpen) {
        document.addEventListener('click', documentClickCloseSidebar);
    } else {
        document.removeEventListener('click', documentClickCloseSidebar);
    }
}

function setupSidebarReportsObserver() {
    const sidebar = document.querySelector('.sidebar-right');
    if (!sidebar || typeof MutationObserver === 'undefined') return;

    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.attributeName === 'class' && !sidebar.classList.contains('open')) {
                collapseReportsSubmenu();
            }
        });
    });

    observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
}

function documentClickCloseSidebar(event) {
    const sidebar = document.querySelector('.sidebar-right');
    if (!sidebar || !sidebar.classList.contains('open')) return;
    const target = event.target;
    if (sidebar.contains(target) || target.closest('.mobile-menu-toggle')) {
        return;
    }
    closeMobileSidebar();
}

// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی
// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی
// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی// تنظیمات پاپ اپ ثبت درخواست مرخصی

function updateModalOverlayState() {
    const openStates = [
        { id: 'leaveModal', display: 'flex' },
        { id: 'overtimeModal', display: 'flex' },
        { id: 'hourlyPassModal', display: 'block' },
        { id: 'ticketModal', display: 'flex' },
        { id: 'popupOverlayMorakhsi', display: 'flex' },
        { id: 'popupOverlayezafe', display: 'flex' },
        { id: 'popupOverlay', display: 'flex' },
        { id: 'popupHozoor', display: 'flex' }
    ];

    const isAnyOpen = openStates.some(({ id, display }) => {
        const element = document.getElementById(id);
        return element && window.getComputedStyle(element).display === display;
    });

    document.body.classList.toggle('leave-modal-open', isAnyOpen);
}

// باز کردن پاپ‌آپ ثبت مرخصی// باز کردن پاپ‌آپ ثبت مرخصی// باز کردن پاپ‌آپ ثبت مرخصی// باز کردن پاپ‌آپ ثبت مرخصی// باز کردن پاپ‌آپ ثبت مرخصی
function openLeaveModal() {
    const leaveModal = document.getElementById("leaveModal");
    if (!leaveModal) return;
    leaveModal.style.display = "flex";
    updateModalOverlayState();
}

// بستن پاپ‌آپ مرخصی// بستن پاپ‌آپ مرخصی// بستن پاپ‌آپ مرخصی// بستن پاپ‌آپ مرخصی// بستن پاپ‌آپ مرخصی// بستن پاپ‌آپ مرخصی// بستن پاپ‌آپ مرخصی
function closeLeaveModal() {
    const leaveModal = document.getElementById("leaveModal");
    if (!leaveModal) return;
    leaveModal.style.display = "none";
    updateModalOverlayState();
}

function persianToJulianDayNumber(year, month, day) {
    const epbase = year - (year >= 0 ? 474 : 473);
    const epyear = 474 + (epbase % 2820);
    const monthOffset = month <= 6 ? month - 1 : month + 5;

    return day +
        Math.floor((epyear * 682 - 110) / 2816) +
        (epyear - 1) * 365 +
        Math.floor(epbase / 2820) * 1029983 +
        monthOffset * 31 +
        1948320;
}

function calculateLeaveDays() {
    const startInput = document.getElementById('startDate');
    const endInput = document.getElementById('endDate');
    const daysInput = document.getElementById('days');

    if (!startInput || !endInput || !daysInput) return;

    const startValue = startInput.value.trim();
    const endValue = endInput.value.trim();

    const startDate = parsePersianDateValue(startValue);
    const endDate = parsePersianDateValue(endValue);

    if (!startDate || !endDate) {
        daysInput.value = '۰';
        return;
    }

    const startJdn = persianToJulianDayNumber(startDate.year, startDate.month, startDate.day);
    const endJdn = persianToJulianDayNumber(endDate.year, endDate.month, endDate.day);
    const safeDays = Math.max(1, endJdn - startJdn + 1);

    daysInput.value = convertToPersianNumbers(String(safeDays));
}

function attachLeaveDateCalculation() {
    ['startDate', 'endDate'].forEach((inputId) => {
        const input = document.getElementById(inputId);
        if (input) {
            input.addEventListener('change', calculateLeaveDays);
            input.addEventListener('blur', calculateLeaveDays);
            input.addEventListener('input', calculateLeaveDays);
        }
    });

    const overtimeInput = document.getElementById('overtimeDate');
    if (overtimeInput) {
        overtimeInput.addEventListener('change', () => {
            overtimeInput.value = overtimeInput.value.trim();
        });
    }
}

document.addEventListener('DOMContentLoaded', attachLeaveDateCalculation);

// ارسال فرم ثبت مرخصی// ارسال فرم ثبت مرخصی// ارسال فرم ثبت مرخصی// ارسال فرم ثبت مرخصی// ارسال فرم ثبت مرخصی// ارسال فرم ثبت مرخصی
document.getElementById('leaveForm').addEventListener('submit', function(e) {
    e.preventDefault();

    let startDate = document.getElementById('startDate').value;
    let endDate = document.getElementById('endDate').value;
    let days = document.getElementById('days').value;
    let substitute = document.getElementById('substitute').value;

    // تبدیل اعداد به فارسی قبل از ارسال
    startDate = convertToPersianNumbers(startDate);
    endDate = convertToPersianNumbers(endDate);
    days = convertToPersianNumbers(days);
    substitute = convertToPersianNumbers(substitute);

    const formData = new FormData();
    formData.append('startDate', startDate);
    formData.append('endDate', endDate);
    formData.append('days', days);
    formData.append('substitute', substitute);

    fetch('/submit_leave', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('درخواست مرخصی شما با موفقیت ثبت شد!');
            closeLeaveModal(); 
            window.location.href = '/user_panel';
        } else {
            showSystemError('خطا در ثبت درخواست مرخصی!');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError('مشکلی در ارسال درخواست پیش آمده است.');
    });
});

// تنظمیات انتخاب جانشین
function toggleSubstituteDropdown() {
    const dropdown = document.getElementById("substituteDropdown");
    const input = document.getElementById("substitute");
    const isDisplayed = dropdown.style.display === "block";

    // اگر منو باز نیست، بازش می‌کنیم
    if (!isDisplayed) {
        dropdown.style.display = "block";
        // تنظیم موقعیت منو نسبت به ورودی
        dropdown.style.left = input.offsetLeft + "px";
        dropdown.style.top = input.offsetTop + input.offsetHeight + "px";
    } else {
        // اگر منو باز بود، می‌بندیم
        dropdown.style.display = "none";
    }
}

// تنظمیات انتخاب جانشین
function selectSubstitute(element) {
    const substituteInput = document.getElementById("substitute");
    substituteInput.value = element.textContent;  // مقدار انتخاب‌شده را به ورودی انتقال می‌دهد
    document.getElementById("substituteDropdown").style.display = "none";  // منو را می‌بندد
}

// بستن منو با کلیک خارج از آن
document.addEventListener("click", function(event) {
    const dropdown = document.getElementById("substituteDropdown");
    const input = document.getElementById("substitute");
    // اگر خارج از منو یا ورودی کلیک شد، منو بسته می‌شود
    if (!dropdown.contains(event.target) && event.target !== input) {
        dropdown.style.display = "none";
    }
});

// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها
// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها
// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها// تنظیمات باکس گزارش مرخصی ها

// تابع دریافت اطلاعات کاربر و نمایش در جدول باکس مرخصی ها
document.addEventListener('DOMContentLoaded', function() {

    // ارسال درخواست GET برای دریافت اطلاعات مرخصی
    fetch('/get_leave_info', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // اگر درخواست موفقیت‌آمیز بود، اطلاعات مرخصی‌ها را به نمایش می‌گذاریم
            let leaveData = data.data;
            let tableBody = document.querySelector('#leaveTable tbody');
            tableBody.innerHTML = ''; // ابتدا جدول را خالی می‌کنیم

            // تابع تبدیل فرمت تاریخ
            function convertDateFormat(dateString) {
                if (dateString) {
                    let [year, month, day] = dateString.split('/');
                    return `${year}/${month.padStart(2, '0')}/${day.padStart(2, '0')}`; // بازسازی در قالب yyyy/mm/dd
                }
                return ''; // اگر تاریخ وجود نداشت، رشته خالی برمی‌گردانیم
            }

            leaveData.forEach(function(leave, index) {
                // فرمت‌دهی تاریخ‌ها
                let formattedStartDate = convertDateFormat(leave.start_date);
                let formattedEndDate = convertDateFormat(leave.end_date);

                // اضافه کردن هر ردیف به جدول
                let row = `<tr>
                    <td>${leave.status}</td>
                    <td>${convertToPersianNumbers(leave.days.toString())}</td>
                    <td>${convertToPersianNumbers(formattedEndDate)}</td>
                    <td>${convertToPersianNumbers(formattedStartDate)}</td>
                    <td>${convertToPersianNumbers((index + 1).toString())}</td> <!-- شماره ردیف فارسی -->
                </tr>`;
                tableBody.innerHTML += row;
            });
        }
    })
    .catch(error => {
        showSystemError('خطا در ارسال درخواست: ' + error);
    });
});

/* باز کردن پاپ‌آپ ثبت مرخصی از سایدبار */
document.getElementById('showMoreMorakhc').addEventListener('click', function(event) {
    event.preventDefault();
    closeMobileSidebar();
    if (typeof openLeaveModal === 'function') {
        openLeaveModal();
    }
});

/* بستن کردن پاپ اپ مرخصی کاربر */
document.getElementById('closePopupMorakhciPopuppbox').addEventListener('click', function(event) {
    event.preventDefault(); // جلوگیری از بارگذاری مجدد صفحه
    var popupOverlay = document.getElementById('popupOverlayMorakhsi');
    popupOverlay.style.display = 'none'; // بستن پاپ‌آپ
    updateModalOverlayState();
});

// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری
// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری
// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری// تنظیمات ثبت اضافه کاری

// باز کردن پاپ‌آپ ثبت اضافه‌کار
function openOvertimeModal() {
    const overtimeModal = document.getElementById("overtimeModal");
    if (!overtimeModal) return;
    overtimeModal.style.display = "flex";
    updateModalOverlayState();
}

// بستن پاپ‌آپ اضافه‌کار
function closeOvertimeModal() {
    const overtimeModal = document.getElementById("overtimeModal");
    if (!overtimeModal) return;
    overtimeModal.style.display = "none";
    updateModalOverlayState();
}

// ارسال فرم ثبت اضافه کاری
document.getElementById("overtimeForm").addEventListener("submit", function(event) {
    event.preventDefault();

    // دریافت مقادیر و تبدیل اعداد فارسی به انگلیسی
    var overtimeDate = convertToEnglishNumbers(document.getElementById("overtimeDate").value);
    var fromTime = convertToEnglish(document.getElementById("fromTime").value);
    var toTime = convertToEnglish(document.getElementById("toTime").value);
    var description = document.getElementById("description").value;

    // نمایش داده‌ها در کنسول برای بررسی
    console.log("Overtime Data to be sent:");
    console.log("Overtime Date: " + overtimeDate);
    console.log("From Time: " + fromTime);
    console.log("To Time: " + toTime);
    console.log("Description: " + description);

    // ارسال اطلاعات به سرور
    fetch('/submit_overtime', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            overtimeDate: overtimeDate,
            fromTime: fromTime,
            toTime: toTime,
            description: description
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('اضافه‌کار با موفقیت ثبت شد!');
            closeOvertimeModal(); 
            window.location.href = '/user_panel';
        } else {
            showSystemError('خطا در ثبت اضافه‌کار: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError('مشکلی در ارسال اطلاعات پیش آمده است.');
    });
});

// تنظیمات باکس گزارش اضافه کاری ها //// تنظیمات باکس گزارش اضافه کاری ها //// تنظیمات باکس گزارش اضافه کاری ها //
// تنظیمات باکس گزارش اضافه کاری ها //// تنظیمات باکس گزارش اضافه کاری ها //// تنظیمات باکس گزارش اضافه کاری ها //
// تنظیمات باکس گزارش اضافه کاری ها //// تنظیمات باکس گزارش اضافه کاری ها //// تنظیمات باکس گزارش اضافه کاری ها //

function hideAllReportPopups() {
    ['popupOverlayMorakhsi', 'popupOverlayezafe', 'popupOverlay', 'popupHozoor'].forEach((id) => {
        const popup = document.getElementById(id);
        if (popup) popup.style.display = 'none';
    });
}

function openReportPopup(reportType) {
    const popupMap = {
        leave: document.getElementById('popupOverlayMorakhsi'),
        overtime: document.getElementById('popupOverlayezafe'),
        pass: document.getElementById('popupOverlay'),
        attendance: document.getElementById('popupHozoor')
    };

    const popup = popupMap[reportType];
    if (popup) {
        hideAllReportPopups();
        popup.style.display = 'flex';
        updateModalOverlayState();
    }

    const reportsItem = document.getElementById('showMoreEzafetime');
    const reportsSubmenu = document.getElementById('reportsSubmenu');
    if (reportsItem && reportsItem.classList.contains('expanded')) {
        reportsItem.classList.remove('expanded');
        if (reportsSubmenu) {
            reportsSubmenu.setAttribute('aria-hidden', 'true');
        }
    }
}

function openAttendanceReportPopup() {
    const popup = document.getElementById('popupHozoor');
    if (!popup) return;

    hideAllReportPopups();
    popup.style.display = 'flex';
    updateModalOverlayState();

    const attendanceCard = document.getElementById('attendanceActionCard');
    const username = attendanceCard?.dataset.username?.trim()
        || document.querySelector('.topbar-user-name')?.textContent?.trim()
        || '';

    fetch('/get_today_date')
        .then((response) => response.json())
        .then((today) => {
            const startDate = `${today.year}/${String(today.month).padStart(2, '0')}/01`;
            const endDate = `${today.year}/${String(today.month).padStart(2, '0')}/${String(today.day).padStart(2, '0')}`;
            const query = new URLSearchParams({ start_date: startDate, end_date: endDate });
            return fetch(`/get_hozoor/${encodeURIComponent(username)}?${query}`);
        })
        .then(async (response) => {
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || `Attendance request failed (${response.status})`);
            }
            return data;
        })
        .then((data) => {
            const tableBody = document.querySelector('#HozoorTableReport tbody');
            if (!tableBody) return;

            if (!Array.isArray(data)) {
                throw new Error('Attendance response is not a list');
            }

            tableBody.innerHTML = '';
            data.forEach((entry, index) => {
                const row = document.createElement('tr');

                const statusCell = document.createElement('td');
                statusCell.classList.add('vazeiyat-hozoorTime');
                statusCell.textContent = entry.Status || '';
                row.appendChild(statusCell);

                const exitTimeCell = document.createElement('td');
                exitTimeCell.classList.add('zmnkhrj-hozoorTime');
                exitTimeCell.textContent = formatAttendanceTime(entry.ExitTime);
                row.appendChild(exitTimeCell);

                const entryTimeCell = document.createElement('td');
                entryTimeCell.classList.add('zmnvrd-hozoorTime');
                entryTimeCell.textContent = formatAttendanceTime(entry.EntryTime);
                row.appendChild(entryTimeCell);

                const dateCell = document.createElement('td');
                dateCell.classList.add('trkhsbt-hozoorTime');
                const formattedDate = (entry.Date || '').replace(/-/g, '/');
                dateCell.textContent = convertToPersianNumbers(formattedDate);
                row.appendChild(dateCell);

                const rowNumberCell = document.createElement('td');
                rowNumberCell.classList.add('radif-hozoorTime');
                rowNumberCell.textContent = convertToPersianNumbers((index + 1).toString());
                row.appendChild(rowNumberCell);

                tableBody.appendChild(row);
            });
        })
        .catch((error) => {
            console.error('Error fetching date or data:', error);
        });
}

/* بستن پاپ اپ اضافه کاری کاربر */
document.getElementById('closePopupezafekarijadvalbox').addEventListener('click', function(event) {
    event.preventDefault(); // جلوگیری از بارگذاری مجدد صفحه
    var popupOverlay = document.getElementById('popupOverlayezafe');
    popupOverlay.style.display = 'none'; // بستن پاپ‌آپ
    updateModalOverlayState();
});

// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر
// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر
// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر// تنظمیات ثبت پاس ساعتی کاربر

// باز کردن پاپ‌آپ پاس ساعتی
function openHourlyPassModal() {
    const hourlyPassModal = document.getElementById("hourlyPassModal");
    if (!hourlyPassModal) return;

    closeLeaveModal();
    closeOvertimeModal();
    hideAllReportPopups();
    hourlyPassModal.style.display = "block";
    updateModalOverlayState();
}

// بستن پاپ‌آپ پاس ساعتی
function closeHourlyPassModal() {
    const hourlyPassModal = document.getElementById("hourlyPassModal");
    if (!hourlyPassModal) return;
    hourlyPassModal.style.display = "none";
    updateModalOverlayState();
}

// ارسال فرم ثبت پاس ساعتی
document.getElementById('hourlyPassForm').addEventListener('submit', function(e) {
    e.preventDefault(); // جلوگیری از ارسال فرم به طور پیش‌فرض

    // دریافت فیلدهای مختلف فرم
    const officialTime = document.getElementById('officialTime') ? document.getElementById('officialTime').value : '';
    const entryTime = document.getElementById('entryTime') ? document.getElementById('entryTime').value : '';
    const exitTime = document.getElementById('exitTime') ? document.getElementById('exitTime').value : '';
    const date = document.getElementById('date') ? document.getElementById('date').value : '';

    // ایجاد یک شیء برای ارسال به سرور
    const formData = {};

    // بررسی فیلدهایی که مقدار دارند و اضافه کردن به formData
    if (officialTime) formData.officialTime = officialTime;
    if (entryTime) formData.entryTime = entryTime;
    if (exitTime) formData.exitTime = exitTime;
    if (date) formData.date = date;

    // ارسال داده‌ها به سرور
    fetch('/submit_hourly_pass', {
        method: 'POST',
        body: JSON.stringify(formData),
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess('پاس‌ها با موفقیت ثبت شد!');
            closeHourlyPassModal(); // بستن پاپ‌آپ
        } else {
            showSystemError('خطا در ثبت پاس‌ها! ' + (data.message || ''));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError('مشکلی در ارسال درخواست پیش آمده است.');
    });
});

// تنظیمات پاس های اول، بین و آخر وقت در ثبت ارسال فرم
function updateHourlyPassFields(passType) {
    const dynamicFields = document.getElementById("dynamicFields");
    dynamicFields.innerHTML = ""; // پاک کردن فیلدهای قبلی

    let content = "";

    if (passType === "first") {
        content = `
            <label for="officialTime">: ساعت موظفی</label>
            <input type="text" id="officialTime" name="officialTime" placeholder="۱۲:۰۰" required oninput="this.value = toPersianDigits(this.value)">

            <label for="entryTime">: ساعت ورودی</label>
            <input type="text" id="entryTime" name="entryTime" placeholder="۱۲:۰۰" required oninput="this.value = toPersianDigits(this.value)">

            <label for="date">: تاریخ</label>
            <input type="text" id="date" name="date" placeholder="۱۴۰۳/۰۱/۰۱" required oninput="this.value = toPersianDigits(this.value)">
        `;
    } else if (passType === "mid") {
        content = `
            <label for="exitTime">: ساعت خروج</label>
            <input type="text" id="exitTime" name="exitTime" placeholder="۱۲:۰۰" required oninput="this.value = toPersianDigits(this.value)">

            <label for="entryTime">: ساعت ورود</label>
            <input type="text" id="entryTime" name="entryTime" placeholder="۱۲:۰۰" required oninput="this.value = toPersianDigits(this.value)">

            <label for="date">: تاریخ</label>
            <input type="text" id="date" name="date" placeholder="۱۴۰۳/۰۱/۰۱" required oninput="this.value = toPersianDigits(this.value)">
        `;
    } else if (passType === "last") {
        content = `
            <label for="officialTime">: ساعت موظفی</label>
            <input type="text" id="officialTime" name="officialTime" placeholder="۱۲:۰۰" required oninput="this.value = toPersianDigits(this.value)">

            <label for="exitTime">: ساعت خروج</label>
            <input type="text" id="exitTime" name="exitTime" placeholder="۱۲:۰۰" required oninput="this.value = toPersianDigits(this.value)">

            <label for="date">: تاریخ</label>
            <input type="text" id="date" name="date" placeholder="۱۴۰۳/۰۱/۰۱" required oninput="this.value = toPersianDigits(this.value)">
        `;
    }

    dynamicFields.innerHTML = content;

    const dateInput = document.getElementById('date');
    if (dateInput) {
        attachDatePickerToInput(dateInput);
    }
}

// به‌روزرسانی فیلدهای پاس بر اساس نوع انتخاب شده
document.getElementById('passType').addEventListener('click', function () {updateHourlyPassFields(this.value);});

// نمایش یا مخفی کردن منوی کشویی برای انتخاب نوع پاس
function togglePassTypeDropdown() {
    const dropdown = document.getElementById('passTypeDropdown');
    dropdown.style.display = (dropdown.style.display === "none" || dropdown.style.display === "") ? "block" : "none";
}

document.querySelectorAll('.dropdown-option').forEach(option => {
    option.addEventListener('click', function() {
        document.getElementById('passType').value = this.textContent;
        document.getElementById("passTypeDropdown").style.display = "none";  // بستن منو
        updateHourlyPassFields(this.getAttribute("data-value"));  // به‌روزرسانی فیلدهای پاس
    });
});

// باز کردن پاپ اپ گزارش پاس ساعتی کاربر
// این رفتار برای دکمه تنظیمات غیرفعال است تا فقط پنل تنظیمات باز شود.
document.getElementById('showMorepopuphourbox').addEventListener('click', function(event) {
    if (event.target.closest('[data-action="toggle-settings-panel"]')) {
        return;
    }
    event.preventDefault();
    var popupOverlay = document.getElementById('popupOverlay');
    if (popupOverlay) {
        popupOverlay.style.display = 'flex';
        updateModalOverlayState();
    }
});

// بستن پاپ اپ گزارش پاس ساعتی کاربر
document.getElementById('closePopup').addEventListener('click', function(event) {
    event.preventDefault(); // جلوگیری از بارگذاری مجدد صفحه
    var popupOverlay = document.getElementById('popupOverlay');
    popupOverlay.style.display = 'none'; // بستن پاپ‌آپ
    updateModalOverlayState();
});

// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر
// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر
// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر// تنظمیات ثبت تیکت کاربر

// باز کردن پاپ‌آپ ثبت تیکت
function openTicketModal() {
    const ticketModal = document.getElementById("ticketModal");
    if (!ticketModal) return;
    ticketModal.style.display = "flex";
    updateModalOverlayState();
    fetch('/get_receivers')
        .then(response => response.json())
        .then(data => {
            const receiverSelect = document.getElementById('ticketReceiver');
            if (!receiverSelect) return;
            receiverSelect.innerHTML = '<option value="" disabled selected>انتخاب کنید</option>';  // ابتدا گزینه پیش‌فرض را قرار می‌دهیم
            data.forEach(receiver => {
                const option = document.createElement('option');
                option.value = receiver;  // نام کاربری
                option.textContent = receiver;  // نام کاربری که در لیست نمایش داده می‌شود
                receiverSelect.appendChild(option);
            });
        })
        .catch(error => console.error('Error fetching receivers:', error));
}

// بستن پاپ‌آپ ثبت تیکت
function closeTicketModal() {
    const ticketModal = document.getElementById("ticketModal");
    if (!ticketModal) return;
    ticketModal.style.display = "none";
    updateModalOverlayState();
}

// باز کردن پاپ‌آپ لیست تیکت‌ها (فقط در موبایل)
function openTicketListPopup() {
    if (!window.matchMedia('(max-width: 768px)').matches) {
        return; // فقط موبایل باید پاپ‌آپ را باز کند
    }
    document.getElementById("ticketListOverlay").style.display = "flex";
}

// بستن پاپ‌آپ لیست تیکت‌ها
function closeTicketListPopup(event) {
    if (event) event.preventDefault();
    document.getElementById("ticketListOverlay").style.display = "none";
}

// ارسال فرم ثبت تیکت
document.getElementById('ticketForm').addEventListener('submit', function(e) {
    if (window.__modernTicketing) return;
    e.preventDefault(); // جلوگیری از ارسال فرم به طور پیش‌فرض

    // دریافت فیلدهای مختلف فرم
    const ticketReceiver = document.getElementById('ticketReceiver') ? document.getElementById('ticketReceiver').value : '';
    const ticketTitle = document.getElementById('ticketCreateTitle') ? document.getElementById('ticketCreateTitle').value : '';
    const ticketDescription = document.getElementById('ticketDescription') ? document.getElementById('ticketDescription').value : '';
    // هویت فرستنده فقط از session سمت سرور تعیین می‌شود.
    const formData = {};

    // بررسی فیلدهایی که مقدار دارند و اضافه کردن به formData
    if (ticketReceiver) formData.recipient_username = ticketReceiver;
    if (ticketTitle) formData.subject = ticketTitle;
    if (ticketDescription) formData.body = ticketDescription;
    const priority = document.getElementById('ticketPriority')?.value || 'normal';
    const category = document.getElementById('ticketCategory')?.value || '';
    formData.priority = priority;
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
            const refresh = () => location.reload();
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

// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها
// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها
// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها// تنظیمات پنجره ویرایش تیکت ها

// بارگذاری لیست دریافت‌کنندگان در باکس ویرایش تیکت
function loadReceivers() {
    fetch('/get_receivers')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('ticketReceiver');
            data.forEach(username => {
                const option = document.createElement('option');
                option.value = username;
                option.textContent = username;
                select.appendChild(option);
            });
        })
        .catch(error => console.error('Error fetching receivers:', error));
}

// بارگذاری فهرست گیرندگان مجاز برای ویرایش
function loadUsersForEdit(selectedReceiver) {
    const receiverSelect = document.getElementById('ticketEditReceiver');
    if (!receiverSelect) return Promise.resolve();

    return fetch('/get_receivers')
        .then(response => {
            if (!response.ok) throw new Error('خطا در دریافت گیرندگان');
            return response.json();
        })
        .then(data => {
            if (!Array.isArray(data)) {
                throw new Error(data.message || 'خطا در دریافت گیرندگان');
            }

            receiverSelect.replaceChildren();
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.disabled = true;
            placeholder.textContent = 'انتخاب کنید';
            receiverSelect.appendChild(placeholder);

            data.forEach(username => {
                const option = document.createElement('option');
                option.value = username;
                option.textContent = username;
                receiverSelect.appendChild(option);
            });
            receiverSelect.value = selectedReceiver || '';
        });
}

// باز کردن پاپ‌آپ ثبت تیکت برای ویرایش
function openEditTicketModal(button) {
    // گرفتن اطلاعات از دکمه
    const ticketId = button.getAttribute('data-id');  // دریافت ID تیکت
    const receiver = button.getAttribute('data-receiver');
    const title = button.getAttribute('data-title');
    const description = button.getAttribute('data-description');

    // پر کردن فیلدها در فرم
    document.getElementById("ticketEditTitle").value = title;
    document.getElementById("ticketEditDescription").value = description;

    // فهرست گیرندگان از سرور می‌آید؛ مقدار انتخابی بعد از بارگذاری اعمال می‌شود.
    loadUsersForEdit(receiver).catch(error => {
        console.error('Error fetching receivers for ticket edit:', error);
    });

    // نمایش پاپ‌آپ
    document.getElementById("ticketEditModal").style.display = "block";

    // ذخیره ID تیکت در یک متغیر جهانی
    window.currentTicketId = ticketId;
}

// بستن پاپ‌آپ ویرایش تیکت
function closeEditTicketModal() {document.getElementById("ticketEditModal").style.display = "none";}

// ارسال داده‌ها به سرور هنگام تایید ویرایش
document.getElementById("ticketEditeForm").addEventListener("submit", function(event) {
    event.preventDefault();  // جلوگیری از ارسال فرم به طور معمول

    const receiver = document.getElementById("ticketEditReceiver").value;
    const title = document.getElementById("ticketEditTitle").value;
    const description = document.getElementById("ticketEditDescription").value;

    // ارسال داده‌ها به سرور
    fetch('/update_ticket', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            id: window.currentTicketId,  // ارسال ID تیکت
            receiver: receiver,
            title: title,
            description: description
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSystemSuccess("تیکت با موفقیت ویرایش شد.");
            closeEditTicketModal();  // بستن پاپ‌آپ
            location.reload();  // رفرش صفحه برای نمایش تغییرات
        } else {
            showSystemError("خطا در ویرایش تیکت.");
        }
    })
    .catch(error => {
        console.error("Error:", error);
        showSystemError("مشکلی پیش آمده است.");
    });
});

let currentTicketId = null;

// نمایش مدال تایید حذف و ذخیره شناسه تیکت انتخابی
function showConfirmDialog(ticketId) {
    currentTicketId = parseInt(ticketId, 10);  // تبدیل به عدد صحیح
    if (isNaN(currentTicketId)) {
        console.error("Invalid ticket ID:", ticketId);  // چاپ شناسه نامعتبر
    }
    document.getElementById('confirmDeleteModal').style.display = "block";
}

// بستن باکس مشاهده پیام
function hideViewDialog() {
    document.getElementById("popupMoshahedeoverlay").style.display = "none";
    document.getElementById("MoshahedePopupbox").style.display = "none";
    location.reload(); // ریلود شدن صفحه
}

// بستن مدال تایید حذف
function closeConfirmDialog() {document.getElementById('confirmDeleteModal').style.display = "none";}

// ارسال درخواست حذف به سرور پس از تایید
document.getElementById('confirmDeleteBtn').onclick = function() {
    if (currentTicketId !== null && !isNaN(currentTicketId)) {
        console.log("Ticket ID to delete:", currentTicketId);  // چاپ شناسه تیکت

        fetch('/delete-ticket', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                ticket_id: currentTicketId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // حذف تیکت از جدول در صفحه (دقت کنید که تنها ردیف مربوطه حذف شود)
                const row = document.querySelector(`tr[data-ticket-id="${currentTicketId}"]`);
                if (row) row.remove();

                closeConfirmDialog(); // بستن پنجره مدال
                
                // ریلود کردن صفحه
                location.reload();
            } else {
                showSystemError('Error deleting ticket: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    } else {
        console.error("Invalid ticket ID during confirmation:", currentTicketId);
    }
};
















// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر
// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر
// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر// تنظمیات باکس تقویم و اطلاعات کاربر

// دریافت اطلاعات کاربر و نمایش در باکس اطلاعات کاربر

// اتصال دکمه‌های سایدبار به مودال‌های مربوطه (ثبت اضافه‌کاری و ثبت پاس ساعتی)
document.addEventListener('DOMContentLoaded', function() {
    setupSidebarReportsObserver();

    var overtimeBtn = document.getElementById('submitOvertime');
    if (overtimeBtn) {
        overtimeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeMobileSidebar();
            if (typeof openOvertimeModal === 'function') openOvertimeModal();
        });
    }

    var passBtn = document.getElementById('submitPass');
    if (passBtn) {
        passBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeMobileSidebar();
            if (typeof openHourlyPassModal === 'function') openHourlyPassModal();
        });
    }

    var ticketBtn = document.getElementById('ticketListIcon');
    if (ticketBtn) {
        ticketBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeMobileSidebar();
            if (typeof openTicketListPopup === 'function') openTicketListPopup();
        });
    }
    
    // Reports submenu toggle
    var reportsItem = document.getElementById('showMoreEzafetime');
    var reportsSubmenu = document.getElementById('reportsSubmenu');
    if (reportsItem) {
        reportsItem.addEventListener('click', function(e) {
            e.stopPropagation();

            // Robust check for expanded state: class, hover, or large computed width
            const sidebar = document.querySelector('.sidebar-right');
            let computedWidth = 0;
            try {
                computedWidth = sidebar ? (parseFloat(getComputedStyle(sidebar).width) || 0) : 0;
            } catch (err) {
                computedWidth = 0;
            }
            const expandedAllowed = (sidebar && (sidebar.classList.contains('open') || sidebar.matches(':hover') || computedWidth > 100));

            if (!expandedAllowed) {
                collapseReportsSubmenu();
                return;
            }

            reportsItem.classList.toggle('expanded');
            var expanded = reportsItem.classList.contains('expanded');
            reportsItem.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (reportsSubmenu) {
                reportsSubmenu.style.display = expanded ? 'flex' : 'none';
            }
        });

        document.querySelectorAll('#reportsSubmenu .sidebar-submenu-item').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                // first collapse the submenu so it is not visible when sidebar closes
                collapseReportsSubmenu();
                // then close the mobile sidebar
                closeMobileSidebar();
                const reportType = this.getAttribute('data-report');
                if (reportType === 'attendance') {
                    openAttendanceReportPopup();
                } else {
                    openReportPopup(reportType);
                }
            });
        });

        // close submenu when clicking outside
        document.addEventListener('click', function(event) {
            if (!reportsItem.contains(event.target)) {
                if (reportsItem.classList.contains('expanded')) {
                    reportsItem.classList.remove('expanded');
                    if (reportsSubmenu) reportsSubmenu.style.display = 'none';
                }
            }
        });
    }
});
function loadUserInfo() {
    fetch('/get_user_info', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // پر کردن مقادیر و تبدیل اعداد به فارسی
            document.getElementById('userName').textContent = data.data.name || 'نام موجود نیست';
            document.getElementById('userLastName').textContent = data.data.last_name || 'نام خانوادگی موجود نیست';
            document.getElementById('userDepartment').textContent = data.data.department || 'بخش موجود نیست';
            document.getElementById('userWorkHours').textContent = data.data.work_hours ? convertToPersianNumbers(data.data.work_hours) : 'ساعت‌های کاری موجود نیست';
            document.getElementById('userSubstitute').textContent = data.data.substitute || 'جانشین موجود نیست';
        } else {
            showSystemError(data.message || 'خطا در دریافت اطلاعات');
        }
    })
    .catch(error => {
        console.error('Error fetching user info:', error);
        showSystemError('خطا در ارتباط با سرور');
    });
}
window.addEventListener('load', function() {
    loadReceivers();
    loadUserInfo();
    loadUsersForEdit();
    
    var numberElement = document.querySelector('.NumberNotif');
    var number = numberElement.innerText || numberElement.textContent;
    
    // تبدیل عدد به فارسی
    var persianNumber = number.replace(/\d/g, function(match) {
        var persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return persianDigits[match];
    });
    
    // بروزرسانی عدد در صفحه
    numberElement.innerText = persianNumber;
});

// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
// ماه‌های شمسی
const persianMonths = [
    "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
    "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"
];

// تعداد روزهای هر ماه شمسی
const daysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 30];

// تعطیلات (مثال: تعطیلات شمسی ماه‌ها)
const holidays = {
    10: [25],  // مهر - تعطیلات: 25
};

// تبدیل اعداد به فارسی
const convertToPersianNumber = (str) => {
    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str.replace(/\d/g, digit => persianNumbers[digit]);
};

// متغیرهای وضعیت برای ماه و سال
let currentYear, currentMonth, currentDay, firstDayOfWeek;

// به‌روزرسانی تقویم
const updateCalendar = () => {
    document.querySelectorAll('.calendar-container').forEach((container) => {
        const monthYearElement = container.querySelector('.month-year');
        const calendarGrid = container.querySelector('.calendar-grid');

        if (!monthYearElement || !calendarGrid) return;

        // به‌روزرسانی نام ماه و سال
        monthYearElement.innerHTML = `${persianMonths[currentMonth - 1]} ${convertToPersianNumber(currentYear.toString())}`;

        // پاک کردن روزهای قبلی
        calendarGrid.innerHTML = '';

        // افزودن نام روزها
        const daysOfWeek = ["شنبه", "یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنجشنبه", "جمعه"];
        daysOfWeek.forEach(day => {
            const dayElement = document.createElement('div');
            dayElement.className = 'day-name';
            dayElement.textContent = day;
            calendarGrid.appendChild(dayElement);
        });

        // تعداد روزهای ماه جاری
        const days = daysInMonth[currentMonth - 1];

        // تعداد خالی‌ها برای شروع ماه جدید
        let emptyDays = firstDayOfWeek;

        // نمایش خالی‌ها در ابتدا
        for (let i = 0; i < emptyDays; i++) {
            const emptyElement = document.createElement('div');
            emptyElement.className = 'day empty';
            calendarGrid.appendChild(emptyElement);
        }

        // نمایش روزهای ماه
        for (let i = 1; i <= days; i++) {
            const dayElement = document.createElement('div');
            dayElement.className = 'day';
            dayElement.textContent = convertToPersianNumber(i.toString());

            // رنگ‌ها به‌جای style اینلاین با کلاس اعمال می‌شوند تا حالت تاریک
            // بتواند آن‌ها را بازنویسی کند (style اینلاین بر CSS اولویت دارد).
            const dayOfWeek = (firstDayOfWeek + i - 1) % 7;
            if (dayOfWeek === 6) {  // جمعه
                dayElement.classList.add('red-day');
            }

            // اگر روز تعطیل باشد، استایل خاصی به آن اعمال شود
            if (holidays[currentMonth] && holidays[currentMonth].includes(i)) {
                dayElement.classList.add('holiday');
            }

            // اگر روز جاری باشد، استایل خاصی به آن اعمال شود
            if (i === currentDay) {
                dayElement.classList.add('today');
            }

            calendarGrid.appendChild(dayElement);
        }
    });
};

// ذخیره‌سازی روز اول هفته برای هر ماه
const persianMonthFirstDays = {
    1: 6,   // فروردین: شنبه
    2: 2,   // اردیبهشت: سه‌شنبه
    3: 5,   // خرداد: جمعه
    4: 1,   // تیر: دوشنبه
    5: 4,   // مرداد: پنجشنبه
    6: 7,   // شهریور: شنبه
    7: 3,   // مهر: سه‌شنبه
    8: 6,   // آبان: شنبه
    9: 1,   // آذر: دوشنبه
    10: 0,  // دی: پنجشنبه
    11: 2,  // بهمن: شنبه
    12: 4   // اسفند: سه‌شنبه
};

// تعداد روزهای یک سال شمسی
const daysInPersianYear = 365; // در سال‌های عادی (نه کبیسه)

// محاسبه روز اول هفته برای سال بعد
const calculateFirstDayOfWeekForNextYear = (firstDayOfWeek, yearIsLeap) => {
    const totalDaysInYear = yearIsLeap ? daysInPersianYear + 1 : daysInPersianYear;
    return (firstDayOfWeek + totalDaysInYear) % 7;
};

// به‌روزرسانی محاسبه روز اول هفته
const fetchDateFromServer = async () => {
    try {
        const response = await fetch('/api/date');
        const data = await response.json();

        // تنظیم تاریخ اولیه
        currentYear = data.year;
        currentMonth = data.month;
        currentDay = data.day;

        // روز اول هفته برای ماه فعلی را از ذخیره‌سازی بگیرید
        firstDayOfWeek = persianMonthFirstDays[currentMonth];

        // به‌روزرسانی تقویم با استفاده از تاریخ دریافتی
        updateCalendar();
    } catch (error) {
        console.error("Error fetching date:", error);
    }
};

// تغییر ماه به ماه قبل
const prevMonth = () => {
    if (currentMonth === 1) {
        currentMonth = 12;
        currentYear -= 1;
    } else {
        currentMonth -= 1;
    }

    // روز اول هفته ماه قبلی را از ذخیره‌سازی بگیرید
    firstDayOfWeek = persianMonthFirstDays[currentMonth];

    updateCalendar();
};

// تغییر ماه به ماه بعد
const nextMonth = () => {
    if (currentMonth === 12) {
        currentMonth = 1;
        currentYear += 1;
    } else {
        currentMonth += 1;
    }

    // روز اول هفته ماه بعدی را از ذخیره‌سازی بگیرید
    firstDayOfWeek = persianMonthFirstDays[currentMonth];

    updateCalendar();
};

// اضافه کردن رویداد به دکمه‌ها
document.querySelectorAll('.prev-month').forEach((btn) => btn.addEventListener('click', prevMonth));
document.querySelectorAll('.next-month').forEach((btn) => btn.addEventListener('click', nextMonth));

// به‌روزرسانی تقویم هنگام بارگذاری صفحه
fetchDateFromServer();

/* legacy notification popup removed
    // بستن پاپ‌آپ
    closeBtn.addEventListener("click", function () {
        popup.style.top = "-10%";  // موقعیت پنهان شدن
        popup.style.opacity = "0";  // مخفی کردن پاپ‌آپ
        setTimeout(function () {
            popup.style.display = "none";  // پنهان کردن بعد از انیمیشن
        }, 300);  // مدت زمان انیمیشن
    });

    // بستن پاپ‌آپ با کلیک بیرون از آن
    window.addEventListener("click", function (e) {
        if (e.target === popup) {
            popup.style.top = "-10%";  // موقعیت پنهان شدن
            popup.style.opacity = "0";  // مخفی کردن پاپ‌آپ
            setTimeout(function () {
                popup.style.display = "none";  // پنهان کردن بعد از انیمیشن
            }, 300);
        }
    });
*/

// تنظمیات کامل باز شدن و چت کردن در تیکت ها// تنظمیات کامل باز شدن و چت کردن در تیکت ها// تنظمیات کامل باز شدن و چت کردن در تیکت ها
// تنظمیات کامل باز شدن و چت کردن در تیکت ها// تنظمیات کامل باز شدن و چت کردن در تیکت ها// تنظمیات کامل باز شدن و چت کردن در تیکت ها
// تنظمیات کامل باز شدن و چت کردن در تیکت ها// تنظمیات کامل باز شدن و چت کردن در تیکت ها// تنظمیات کامل باز شدن و چت کردن در تیکت ها

let ticketData = null;

function openViewDialog(button) {
    // اگر موبایل است، صفحه وضعیت تیکت‌ها را ببند
    if (window.matchMedia('(max-width: 768px)').matches) {
        const ticketOverlay = document.getElementById('ticketListOverlay');
        if (ticketOverlay) {
            ticketOverlay.style.display = 'none';
        }
    }

    // نمایش پاپ‌آپ
    document.querySelector('#popupMoshahedeoverlay').style.display = 'block';
    document.querySelector('#MoshahedePopupbox').style.display = 'block';

    // استخراج عنوان و شناسه تیکت از داده‌های دکمه
    const ticketId = button.getAttribute('data-id');
    const ticketTitle = button.getAttribute('data-title');

    // نمایش عنوان تیکت در پاپ‌آپ
    document.querySelector('#ticketConversationTitle').textContent = ticketTitle;

    // درخواست به سرور برای دریافت جزئیات تیکت
    fetch(`/get_ticket_details_payam/${ticketId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                showSystemError('خطایی در دریافت اطلاعات تیکت رخ داده است.');
                return;
            }

            // ذخیره اطلاعات تیکت در متغیر ticketData
            ticketData = data;

            // دریافت و نمایش پیام‌ها
            const kadrMatnContainer = document.querySelector('#MoshahedePopupbox .kadr-matn');
            kadrMatnContainer.innerHTML = ''; // پاک کردن پیام‌های قبلی

            const sortedMessages = data.messages.sort((a, b) => new Date(a.ticket_date) - new Date(b.ticket_date));

            let userClasses = {};

            sortedMessages.forEach((message) => {
                const messageDiv = document.createElement('div');
                const currentUsername = message.username.trim().toLowerCase();

                if (!userClasses['karbar1']) {
                    userClasses['karbar1'] = currentUsername;
                    messageDiv.classList.add('matn1');
                } else if (!userClasses['karbar2'] && currentUsername !== userClasses['karbar1']) {
                    userClasses['karbar2'] = currentUsername;
                    messageDiv.classList.add('matn2');
                } else if (currentUsername === userClasses['karbar1']) {
                    messageDiv.classList.add('matn1');
                } else if (currentUsername === userClasses['karbar2']) {
                    messageDiv.classList.add('matn2');
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

            // ارسال درخواست برای علامت‌گذاری پیام‌ها به‌عنوان خوانده‌شده
            fetch(`/mark_ticket_as_read/${ticketId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    console.error('خطا در به‌روزرسانی وضعیت خوانده‌شده:', result.error);
                }
            })
            .catch(error => {
                console.error('Error marking ticket as read:', error);
            });

        })
        .catch(error => {
            console.error('Error fetching ticket details:', error);
        });
}


// رویداد کلیک روی ersal-icon
if (!window.__modernTicketing) document.querySelector('.ersal-icon').addEventListener('click', sendTicketResponse);

// رویداد keydown برای دکمه Enter
if (!window.__modernTicketing) document.querySelector('#matnErsali').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault(); // جلوگیری از ارسال فرم (اگر فرم وجود داشته باشد)
        sendTicketResponse();
    }
});

function sendTicketResponse() {
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
    fetch('/add_ticket_response_userpanel', {
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
}



// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر
// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر
// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر// تنظیمات تایمر بستن صفحه پنل کاربر

let timeout = setTimeout(function() {
    window.location.href = "/login";
}, 300000); // 30 ثانیه

document.addEventListener("mousemove", resetTimer);
document.addEventListener("keydown", resetTimer);

function resetTimer() {
    clearTimeout(timeout);
    timeout = setTimeout(function() {
        window.location.href = "/login";
    }, 300000); // ریست تایمر
}

// تابع برای تبدیل اعداد به فارسی
function convertToPersianNumbers(str) {
    // اطمینان از اینکه ورودی رشته است
    str = String(str);  // تبدیل به رشته

    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str.replace(/\d/g, function (match) {
        return persianNumbers[match];
    });
}

// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن
// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن
// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن// باز کردن پاپ‌آپ ساعت زن

var hozoorReportBtn = document.getElementById('hozoorReport');
if (hozoorReportBtn) {
    hozoorReportBtn.addEventListener('click', openAttendanceReportPopup);
}

// بستن پاپ‌آپ ساعت زن
document.getElementById('close-popupHozoor').addEventListener('click', function() {
    document.getElementById('popupHozoor').style.display = 'none';
    updateModalOverlayState();
});

// تنظیمات دراپ باکس در جدول تیکت های کاربر// تنظیمات دراپ باکس در جدول تیکت های کاربر// تنظیمات دراپ باکس در جدول تیکت های کاربر
// تنظیمات دراپ باکس در جدول تیکت های کاربر// تنظیمات دراپ باکس در جدول تیکت های کاربر// تنظیمات دراپ باکس در جدول تیکت های کاربر
// تنظیمات دراپ باکس در جدول تیکت های کاربر// تنظیمات دراپ باکس در جدول تیکت های کاربر// تنظیمات دراپ باکس در جدول تیکت های کاربر

function toggleDropdown(event) {
    let dropdown = event.currentTarget.querySelector('.dropdown');
    let allDropdowns = document.querySelectorAll('.dropdown');

    // بستن سایر دراپ‌دان‌ها
    allDropdowns.forEach(d => {
        if (d !== dropdown) d.style.display = 'none';
    });

    // باز یا بسته کردن دراپ‌دان انتخاب شده
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';

    // جلوگیری از بسته شدن فوری باکس هنگام کلیک روی آن
    event.stopPropagation();
}

function changeStatus(element, newStatus) {
    let td = element.closest('.vazeiyat-ticket');  // پیدا کردن والد `td`
    let statusSpan = td.querySelector('.ticket-status'); // پیدا کردن span داخل td
    let dropdown = td.querySelector('.dropdown'); // پیدا کردن دراپ‌دان مربوطه

    // تغییر وضعیت
    statusSpan.innerText = newStatus;

    // بستن دراپ‌دان
    dropdown.style.display = 'none';
}

// بستن همه دراپ‌دان‌ها هنگام کلیک در خارج از آنها
document.addEventListener('click', function (event) {
    document.querySelectorAll('.dropdown').forEach(d => {
        d.style.display = 'none';
    });
});

// تنظیمات دکمه تایید در تیکت های کاربر// تنظیمات دکمه تایید در تیکت های کاربر// تنظیمات دکمه تایید در تیکت های کاربر
// تنظیمات دکمه تایید در تیکت های کاربر// تنظیمات دکمه تایید در تیکت های کاربر// تنظیمات دکمه تایید در تیکت های کاربر
// تنظیمات دکمه تایید در تیکت های کاربر// تنظیمات دکمه تایید در تیکت های کاربر// تنظیمات دکمه تایید در تیکت های کاربر

document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".check-btn").forEach(button => {
        button.addEventListener("click", function () {
            let row = this.closest("tr");
            let ticketId = row.querySelector(".virayesh-ticket .edit-btn")?.getAttribute("data-id") || 
                           row.querySelector(".view-btn")?.getAttribute("data-id");

            if (!ticketId) {
                showSystemError("شناسه تیکت یافت نشد!");
                return;
            }

            let currentStatus = row.querySelector(".ticket-status").innerText.trim();
            let newStatus = "تایید شده"; // مقدار جدید برای وضعیت تیکت

            fetch("/update_ticket_status", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    id: ticketId,
                    ticket_status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    row.querySelector(".ticket-status").innerText = newStatus;
                    showSystemSuccess("وضعیت تیکت با موفقیت به‌روزرسانی شد!");
                } else {
                    showSystemError("خطا در به‌روزرسانی وضعیت: " + (data.error || "نامشخص"));
                }
            })
            .catch(error => console.error("خطا در ارسال درخواست:", error));
        });
    });
});

// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل
// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل
// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل// پیام هشدار برای حذف پروفایل

function confirmDelete() {
    if (confirm("آیا از حذف عکس مطمئن هستید؟")) {
        document.getElementById("deleteForm").submit();
    }
}



// ================= افزوده‌های داشبورد جدید (v2) =================

// ساعت و تاریخ زنده در نوار بالایی
function updateTopbarClock() {
    const dateEl = document.getElementById('topbarDateText');
    const timeEl = document.getElementById('topbarTimeText');
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

    dateEl.textContent = typeof convertToPersianNumbers === 'function'
        ? convertToPersianNumbers(persianDate)
        : persianDate;
    timeEl.textContent = typeof convertToPersianNumbers === 'function'
        ? convertToPersianNumbers(persianTime)
        : persianTime;
}
setInterval(updateTopbarClock, 1000);
document.addEventListener('DOMContentLoaded', updateTopbarClock);

// باز و بسته کردن منوی پروفایل کاربر
document.addEventListener('DOMContentLoaded', function () {
    const profileCard = document.getElementById('profileCard');
    const avatarMenu = document.getElementById('avatarMenu');
    if (profileCard && avatarMenu) {
        let closeTimer;

        const openMenu = () => {
            clearTimeout(closeTimer);
            profileCard.classList.add('menu-open');
        };

        const closeMenu = () => {
            clearTimeout(closeTimer);
            closeTimer = setTimeout(() => {
                profileCard.classList.remove('menu-open');
            }, 140);
        };

        profileCard.addEventListener('mouseenter', openMenu);
        profileCard.addEventListener('mouseleave', closeMenu);
        profileCard.addEventListener('click', openMenu);
        profileCard.addEventListener('focusin', openMenu);
        profileCard.addEventListener('focusout', (event) => {
            if (!profileCard.contains(event.relatedTarget)) {
                closeMenu();
            }
        });

        avatarMenu.addEventListener('mouseenter', openMenu);
        avatarMenu.addEventListener('mouseleave', closeMenu);

        document.addEventListener('click', function (event) {
            if (!profileCard.contains(event.target)) {
                profileCard.classList.remove('menu-open');
            }
        });
    }
});
