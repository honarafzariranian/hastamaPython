// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش
// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش
// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش
document.getElementById("printReportBtn").addEventListener("click", function () {
    window.print();
});

// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال
// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال
// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال// تابع برای انتخاب ماه های سال
const dropdownToggle = document.getElementById('dropdownToggle');
const dropdownOptions = document.getElementById('dropdownOptions');

dropdownToggle.addEventListener('click', function () {
    dropdownOptions.classList.toggle('show');
});

document.querySelectorAll('.dropdown-option').forEach(option => {
    option.addEventListener('click', function () {
        dropdownToggle.textContent = this.textContent;
        dropdownOptions.classList.remove('show');
    });
});

// بستن dropdown در صورت کلیک خارج از آن
document.addEventListener('click', function (event) {
    if (!dropdownToggle.contains(event.target) && !dropdownOptions.contains(event.target)) {
        dropdownOptions.classList.remove('show');
    }
});

// دریافت اطلاعات کاربر از سشن و نمایش در صفحه// دریافت اطلاعات کاربر از سشن و نمایش در صفحه// دریافت اطلاعات کاربر از سشن و نمایش در صفحه
// دریافت اطلاعات کاربر از سشن و نمایش در صفحه// دریافت اطلاعات کاربر از سشن و نمایش در صفحه// دریافت اطلاعات کاربر از سشن و نمایش در صفحه
// دریافت اطلاعات کاربر از سشن و نمایش در صفحه// دریافت اطلاعات کاربر از سشن و نمایش در صفحه// دریافت اطلاعات کاربر از سشن و نمایش در صفحه

window.addEventListener('load', function () {
    const username = localStorage.getItem("username");

    if (!username) {
        showSystemError('نام کاربری انتخاب نشده است.');
        return;
    }

    console.log("کاربر دریافت‌شده از صفحه مدیریت:", username);

    // گرفتن اطلاعات کاربر از سرور
    fetch(`/get_user_info_report?username=${encodeURIComponent(username)}`, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('department').textContent = data.data.department || 'اطلاعات موجود نیست';
            document.getElementById('lastName').textContent = data.data.last_name || 'اطلاعات موجود نیست';
            document.getElementById('firstName').textContent = data.data.name || 'اطلاعات موجود نیست';
        } else {
            showSystemError(data.message);
        }
    })
    .catch(error => {
        console.error('خطا در دریافت اطلاعات کاربر:', error);
        showSystemError('خطا در دریافت اطلاعات کاربر');
    });

    // نمایش نام کاربر
    const usernameElem = document.getElementById('usernameDisplay');
    if (usernameElem) {
        usernameElem.textContent = username;
    }

    // گرفتن داده اضافه‌کاری
    const overtimeData = JSON.parse(localStorage.getItem("overtimeReports") || '[]');

    const tbody = document.getElementById("overTimeReportTable")?.getElementsByTagName('tbody')[0];
    if (tbody) {
        tbody.innerHTML = '';
        overtimeData.forEach((row, index) => {
            const newRow = tbody.insertRow();
            newRow.innerHTML = `
                <td>${convertToPersianNumbers(row.description)}</td>
                <td>${convertToPersianNumbers(row.daily_overtime)}</td>
                <td>${convertToPersianNumbers(row.overtime_date)}</td>
                <td>${convertToPersianNumbers(index + 1)}</td>
            `;
        });
    }

    // پاک کردن داده‌ها بعد از لود
    localStorage.removeItem("overtimeReports");
    localStorage.removeItem("username");
});

function convertToPersianNumbers(input) {
    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return input.toString().replace(/\d/g, (match) => persianDigits[match]);
}
