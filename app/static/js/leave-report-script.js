document.addEventListener('DOMContentLoaded', function () {
    // دریافت username از localStorage
    const username = localStorage.getItem('username');

    // ارسال درخواست به سرور برای دریافت اطلاعات کاربر
    if (username) {
        fetch(`/fetch_user_data?username=${username}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('user-info').innerHTML = 'اطلاعات کاربر یافت نشد';
                } else {
                    // نمایش اطلاعات کاربر در باکس گزارش
                    document.getElementById('firstName').innerText = data.name || 'نام یافت نشد';
                    document.getElementById('lastName').innerText = data.last_name || 'نام خانوادگی یافت نشد';
                    document.getElementById('department').innerText = data.department || 'دپارتمان یافت نشد';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('firstName').innerText = 'خطا در بارگذاری اطلاعات';
            });
    }

    // پر کردن جدول با داده‌های گزارش
    const reports = JSON.parse(localStorage.getItem('reports'));
    const tableBody = document.getElementById('reportTableBody');
    if (reports && reports.length > 0) {
        reports.forEach((report, index) => {
            const row = document.createElement('tr');

            // نمایش جانشین یا "ندارد" اگر مقدار جانشین موجود نباشد
            const substitute = report.substitute ? report.substitute : 'ندارد';

            row.innerHTML = `
                <td>${esc(convertToFarsiNumbers(substitute))}}</td> <!-- جانشین -->
                <td>${esc(convertToFarsiNumbers(report.days))}}</td>
                <td>${esc(convertToFarsiNumbers(report.end_date))}}</td>
                <td>${esc(convertToFarsiNumbers(report.start_date))}}</td>
                <td>${esc(convertToFarsiNumbers(index + 1))}}</td> <!-- شماره ردیف -->
            `;
            tableBody.appendChild(row);
        });

        // نمایش دکمه چاپ گزارش پس از بارگذاری داده‌ها
        document.getElementById('printReportBtn').style.display = 'inline-block';
    } else {
        // در صورتی که گزارشی وجود نداشته باشد
        const row = document.createElement('tr');
        row.innerHTML = `<td colspan="5" style="text-align:center;">گزارشی برای نمایش وجود ندارد</td>`;
        tableBody.appendChild(row);
    }

    // مدیریت dropdown برای انتخاب ماه
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

    // افزودن قابلیت چاپ با دکمه
    document.getElementById('printReportBtn').addEventListener('click', function () {
        window.print(); // چاپ صفحه
    });
});

// تابع برای تبدیل اعداد به فارسی
function convertToFarsiNumbers(input) {
    const farsiNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return input.toString().replace(/\d/g, (match) => farsiNumbers[match]);
}