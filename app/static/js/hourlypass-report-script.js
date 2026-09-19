// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش
// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش// تابع برای دکمه چاپ گزارش
document.getElementById("printReportBtn").addEventListener("click", function () {
    window.print();
});

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

// بستن دراپ داون در صورت کلیک خارج از آن// بستن دراپ داون در صورت کلیک خارج از آن// بستن دراپ داون در صورت کلیک خارج از آن
// بستن دراپ داون در صورت کلیک خارج از آن// بستن دراپ داون در صورت کلیک خارج از آن// بستن دراپ داون در صورت کلیک خارج از آن
document.addEventListener('click', function (event) {
    if (!dropdownToggle.contains(event.target) && !dropdownOptions.contains(event.target)) {
        dropdownOptions.classList.remove('show');
    }
});

// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت
// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت
// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت// دریافت اطلاعات جدول از صفحه مدیریت

document.addEventListener("DOMContentLoaded", function () {
    const tableBody = document.querySelector("#PasseSaatiReportTable tbody");
    const reportData = JSON.parse(localStorage.getItem("hourlyPassReportData") || "[]");

    reportData.forEach((row) => {
        const newRow = document.createElement("tr");
        newRow.innerHTML = `
            <td>${esc(convertToPersianNumbers(row.passDuration))}}</td>
            <td>${esc(convertToPersianNumbers(row.passTitle))}}</td>
            <td>${esc(convertToPersianNumbers(row.requestDate))}}</td>
            <td>${esc(convertToPersianNumbers(row.index))}}</td>
        `;
        tableBody.appendChild(newRow);
    });

    // حذف داده‌ها از localStorage برای جلوگیری از استفاده مجدد
    localStorage.removeItem("hourlyPassReportData");
});

window.addEventListener('load', function() {
    // ارسال درخواست برای دریافت نام کاربری از سشن
    const username = localStorage.getItem("hourlyPassUsername");  // یا هر متغیری که داری
fetch(`/get_user_info_report?username=${encodeURIComponent(username)}`, {
    method: 'GET',
})

    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // نمایش اطلاعات کاربر در صفحه
            document.getElementById('department').textContent = data.data.department || 'اطلاعات موجود نیست';
            document.getElementById('lastName').textContent = data.data.last_name || 'اطلاعات موجود نیست';
            document.getElementById('firstName').textContent = data.data.name || 'اطلاعات موجود نیست';
        } else {
            showSystemError(data.message); // نمایش خطا در صورت عدم وجود اطلاعات
        }
    })
    .catch(error => {
        console.error('خطا در دریافت اطلاعات کاربر:', error);
        showSystemError('خطا در دریافت اطلاعات کاربر');
    });

});

// تابع تبدیل اعداد به فارسی
function convertToPersianNumbers(number) {
    const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
    return number.toString().replace(/\d/g, (digit) => persianDigits[digit]);
}