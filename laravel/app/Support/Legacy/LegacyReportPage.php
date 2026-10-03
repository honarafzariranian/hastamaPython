<?php

namespace App\Support\Legacy;

use App\Http\Controllers\Admin\ReportPageController;
use Illuminate\Http\Response;

/**
 * The printable report documents — the `*_report_page` routes and the HTML
 * behind `download_pdf`.
 *
 * Each Python handler renders one Jinja template from `app/templates` and
 * returns it as `text/html`:
 *
 * ```python
 *
 * @app.get("/leave_report_page", response_class=HTMLResponse)
 * async def report_page(request: Request):
 *     auth_err = _request_auth(request)
 *     if auth_err:
 *         return auth_err
 *     return templates.TemplateResponse(request, "leave_report_page.html", {"request": request})
 * ```
 *
 * The templates are static shells: a Persian report document with a print
 * button, the signature blocks and the stylesheet / script tags the report
 * scripts need.  `final_report_page.html` is the only one with dynamic
 * content — the title carries the current Jalali month name and year, which
 * the handler computes and the template prints:
 *
 *    گزارش {{ month_name }} ماه {{ year }} حضور و غیاب
 *
 * The `{{ url_for('static', path='…') }}` calls are reproduced as the
 * `/static/…` URLs the running server renders (static is mounted at `/static`,
 * `app/main.py:1077`).
 *
 * `download_pdf` is different: it renders `finalReportUser.html`, inlines
 * `finalReportUserPrint.css`, writes the result to a private temporary file
 * and converts it with `pdfkit.from_file` (hardened against CVE-2025-26240).
 * **That template is missing from this installation** — it is not in
 * `app/templates` and not in `docs/migration/TEMPLATE_INVENTORY.md` — so the
 * Python answers 503 before reaching pdfkit:
 *
 * ```python
 * try:
 *     template = templates.get_template('finalReportUser.html')
 * except Exception:
 *     return JSONResponse(status_code=503, content={
 *         "success": False, "message": "قالب گزارش نهایی در این نصب موجود نیست."})
 * ```
 *
 * The port reproduces that 503.  The pdfkit pipeline itself has no PHP
 * equivalent in this project (no PDF library is a dependency) and is
 * unreachable here anyway, because the template it would render does not
 * exist; it is documented on {@see ReportPageController}
 * rather than stubbed.
 */
final class LegacyReportPage
{
    /**
     * The Jalali month names, indexed 1–12 — `MONTH_NAMES` in `app/main.py`.
     *
     * @var array<int, string>
     */
    private const MONTH_NAMES = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    /**
     * `GET /leave_report_page` — `leave_report_page.html`.
     */
    public static function leave(): string
    {
        return <<<'HTML'
<!DOCTYPE html>

<html lang="fa">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>گزارش مرخصی فردی</title>
        <link rel="stylesheet" href="/static/css/leave-report-style.css">
        <link rel="icon" href="/static/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/static/css/responsive-tables.css">
    <link rel="stylesheet" href="/static/css/tables.css">    <link rel="stylesheet" href="/static/css/vazir.css">
    <link rel="stylesheet" href="/static/css/dark-theme.css">
    <script src="/static/js/theme.js"></script>
</head>

    <body>
        <div class="report-container">
            <!-- تصویر پس‌زمینه -->
            <div class="background-image"></div>
            <img src="/static/images/report-bg.jpg" alt="test image" />
            <div class="report-box">
                <h2>گزارش مرخصی</h2>
                <div class="personal-info">
                    <div class="info-item-month">
                        <div class="custom-dropdown" id="dropdownToggle">
                            انتخاب ماه
                        </div>
                        <div class="dropdown-options" id="dropdownOptions">
                            <div class="dropdown-option">فروردین</div>
                            <div class="dropdown-option">اردیبهشت</div>
                            <div class="dropdown-option">خرداد</div>
                            <div class="dropdown-option">تیر</div>
                            <div class="dropdown-option">مرداد</div>
                            <div class="dropdown-option">شهریور</div>
                            <div class="dropdown-option">مهر</div>
                            <div class="dropdown-option">آبان</div>
                            <div class="dropdown-option">آذر</div>
                            <div class="dropdown-option">دی</div>
                            <div class="dropdown-option">بهمن</div>
                            <div class="dropdown-option">اسفند</div>
                        </div>
                        <strong>: ماه</strong>
                    </div>
                    <div class="info-item">
                        <strong>دپارتمان :</strong> <span id="department">فناوری اطلاعات</span>
                    </div>
                    <!-- اطلاعات کاربر -->
                    <div class="info-item">
                        <strong>نام خانوادگی :</strong> <span id="lastName">رضایی</span>
                    </div>
                    <div class="info-item">
                        <strong>نام :</strong> <span id="firstName">محمد</span>
                    </div>
                </div>
            </div>

            <table class="individual-report-table">
                <thead>
                    <tr>
                        <th>جانشین</th>
                        <th>تعداد روز</th>
                        <th>تا تاریخ</th>
                        <th>از تاریخ</th>
                        <th>ردیف</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <!-- ردیف‌ها در اینجا اضافه می‌شوند -->
                </tbody>
            </table>

            <div class="signature-container">
                <div class="signature-box-hamkar">
                    <strong>امضای همکار</strong>
                    <div class="signature-space">
                        <span>نام و نام خانوادگی</span>
                    </div>
                </div>
                <div class="signature-box">
                    <strong>امضای مدیر آزمایشگاه</strong>
                </div>
            </div>

            <!-- دکمه چاپ گزارش -->
            <button id="printReportBtn">چاپ گزارش</button>
        </div>

        <script src="/static/js/responsive-tables.js"></script>
        <script src="/static/js/leave-report-script.js"></script>
        <script src="/static/js/dom-escape.js"></script>

    </body>

</html>
HTML;
    }

    /**
     * `GET /hourlypass_Report_page` — `hourlypass_Report_page.html`.
     */
    public static function hourlyPass(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="fa">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش پاس ساعتی فردی</title>
    <link rel="stylesheet" href="/static/css/hourlypass-report-style.css">
    <link rel="icon" href="/static/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/static/css/responsive-tables.css">
    <link rel="stylesheet" href="/static/css/tables.css">
    <link rel="stylesheet" href="/static/css/vazir.css">
    <link rel="stylesheet" href="/static/css/dark-theme.css">
    <script src="/static/js/theme.js"></script>
</head>

<body>

    <div class="report-container">
        <!-- تصویر پس‌زمینه -->
        <div class="background-image"></div>
        <img src="/static/images/report-bg.jpg" alt="test image" />
        <div class="report-box">
            <h2>گزارش پاس ساعتی</h2>
                <div class="personal-info">
                <div class="info-item-month">
                    <div class="custom-dropdown" id="dropdownToggle">
                        انتخاب ماه
                    </div>
                    <div class="dropdown-options" id="dropdownOptions">
                        <div class="dropdown-option">فروردین</div>
                        <div class="dropdown-option">اردیبهشت</div>
                        <div class="dropdown-option">خرداد</div>
                        <div class="dropdown-option">تیر</div>
                        <div class="dropdown-option">مرداد</div>
                        <div class="dropdown-option">شهریور</div>
                        <div class="dropdown-option">مهر</div>
                        <div class="dropdown-option">آبان</div>
                        <div class="dropdown-option">آذر</div>
                        <div class="dropdown-option">دی</div>
                        <div class="dropdown-option">بهمن</div>
                        <div class="dropdown-option">اسفند</div>
                    </div>
                    <strong>: ماه</strong>
                </div>
                <div class="info-item">
                    <strong>دپارتمان :</strong> <span id="department">فناوری اطلاعات</span>
                </div>
                <!-- اطلاعات کاربر -->
                <div class="info-item">
                    <strong>نام خانوادگی :</strong> <span id="lastName">رضایی</span>
                </div>
                <div class="info-item">
                    <strong>نام :</strong> <span id="firstName">محمد</span>
                </div>
            </div>
        </div>

        <table class="individual-report-table" id="PasseSaatiReportTable">
            <thead>
                <tr>
                    <th>مدت زمان پاس</th>
                    <th>عنوان پاس</th>
                    <th>تاریخ درخواست</th>
                    <th>ردیف</th>
                </tr>
            </thead>
            <tbody>
                <!-- ردیف‌ها در اینجا اضافه می‌شوند -->
            </tbody>
        </table>


        <div class="signature-container">
            <div class="signature-box-hamkar">
                <strong>امضای همکار</strong>
                <div class="signature-space">
                    <span>نام و نام خانوادگی</span>
                </div>
            </div>
            <div class="signature-box">
                <strong>امضای مدیر آزمایشگاه</strong>
            </div>
        </div>

        <!-- دکمه چاپ گزارش -->
        <button id="printReportBtn">چاپ گزارش</button>
    </div>

    <script src="/static/js/responsive-tables.js"></script>
    <script src="/static/js/hourlypass-report-script.js"></script>
    <script src="/static/js/dom-escape.js"></script>

<script src="/static/js/csrf-bootstrap.js"></script>
<script src="/static/js/notification-system.js"></script>
</body>

</html>
HTML;
    }

    /**
     * `GET /overtime_report_page` — `overtime_report_page.html`.
     */
    public static function overtime(): string
    {
        return <<<'HTML'
<!DOCTYPE html>

<html lang="fa">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>گزارش اضافه کاری فردی</title>
        <link rel="stylesheet" href="/static/css/overtime-report-style.css">
        <link rel="icon" href="/static/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/static/css/responsive-tables.css">
    <link rel="stylesheet" href="/static/css/tables.css">    <link rel="stylesheet" href="/static/css/vazir.css">
    <link rel="stylesheet" href="/static/css/dark-theme.css">
    <script src="/static/js/theme.js"></script>
</head>

    <body>

        <div class="report-container">
            <!-- تصویر پس‌زمینه -->
            <div class="background-image"></div>
            <img src="/static/images/report-bg.jpg" alt="test image" />
            <div class="report-box">
                <h2>گزارش اضافه کاری</h2>
                <div class="personal-info">
                    <div class="info-item-month">
                        <div class="custom-dropdown" id="dropdownToggle">
                            انتخاب ماه
                        </div>
                        <div class="dropdown-options" id="dropdownOptions">
                            <div class="dropdown-option">فروردین</div>
                            <div class="dropdown-option">اردیبهشت</div>
                            <div class="dropdown-option">خرداد</div>
                            <div class="dropdown-option">تیر</div>
                            <div class="dropdown-option">مرداد</div>
                            <div class="dropdown-option">شهریور</div>
                            <div class="dropdown-option">مهر</div>
                            <div class="dropdown-option">آبان</div>
                            <div class="dropdown-option">آذر</div>
                            <div class="dropdown-option">دی</div>
                            <div class="dropdown-option">بهمن</div>
                            <div class="dropdown-option">اسفند</div>
                        </div>
                        <strong>: ماه</strong>
                    </div>
                    <div class="info-item">
                        <strong>دپارتمان :</strong> <span id="department">فناوری اطلاعات</span>
                    </div>
                    <!-- اطلاعات کاربر -->
                    <div class="info-item">
                        <strong>نام خانوادگی :</strong> <span id="lastName">رضایی</span>
                    </div>
                    <div class="info-item">
                        <strong>نام :</strong> <span id="firstName">محمد</span>
                    </div>
                </div>
            </div>

            <table class="individual-report-table" id="overTimeReportTable">
                <thead>
                    <tr>
                        <th>توضیحات</th>
                        <th>مدت اضافه کاری</th>
                        <th>تاریخ درخواست</th>
                        <th>ردیف</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- ردیف‌ها در اینجا اضافه می‌شوند -->
                </tbody>
            </table>


            <div class="signature-container">
                <div class="signature-box-hamkar">
                    <strong>امضای همکار</strong>
                    <div class="signature-space">
                        <span>نام و نام خانوادگی</span>
                    </div>
                </div>
                <div class="signature-box">
                    <strong>امضای مدیر آزمایشگاه</strong>
                </div>
            </div>

            <!-- دکمه چاپ گزارش -->
            <button id="printReportBtn">چاپ گزارش</button>
        </div>

        <script src="/static/js/responsive-tables.js"></script>
        <script src="/static/js/overtime-report-script.js"></script>
        <script src="/static/js/dom-escape.js"></script>

    <script src="/static/js/csrf-bootstrap.js"></script>
    <script src="/static/js/notification-system.js"></script>
</body>

</html>
HTML;
    }

    /**
     * `GET /payroll_report_page` — `payroll_report_page.html`.
     */
    public static function payroll(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>پیش‌نمایش گزارش حقوق</title>
    <link rel="stylesheet" href="/static/css/vazir.css">
    <link rel="stylesheet" href="/static/css/payroll-report-preview.css">
</head>
<body>
    <main class="preview-page">
        <header class="preview-toolbar">
            <div>
                <span class="preview-kicker">پیش‌نمایش گزارش</span>
                <h1 id="payrollPreviewTitle">گزارش حقوق و دستمزد</h1>
                <p>اطلاعات زیر آماده چاپ یا دریافت فایل است.</p>
            </div>
            <div class="preview-actions" aria-label="عملیات گزارش">
                <button type="button" id="payrollPrintButton" class="preview-btn preview-btn-print">چاپ گزارش</button>
                <button type="button" id="payrollDownloadButton" class="preview-btn preview-btn-download">دریافت فایل گزارش</button>
            </div>
        </header>

        <section id="payrollPreviewContent" class="report-sheet" aria-live="polite">
            <div class="preview-empty">در حال آماده‌سازی گزارش…</div>
        </section>
    </main>
    <script src="/static/js/payroll-report-preview.js"></script>
<script src="/static/js/csrf-bootstrap.js"></script>
<script src="/static/js/notification-system.js"></script>
</body>
</html>
HTML;
    }

    /**
     * `GET /final_report_page` — `final_report_page.html`.
     *
     * The title carries the current Jalali month name and year, which the
     * handler computes from `JalaliDate.today()` (`app/main.py:5456`).
     */
    public static function final(): string
    {
        $today = LegacyDate::today();
        $monthName = self::MONTH_NAMES[$today->month];
        $year = $today->year;

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش پاس ساعتی فردی</title>
    <link rel="stylesheet" href="/static/css/final-report-style.css">
    <link rel="stylesheet" href="/static/css/responsive-mobile.css">
    <link rel="stylesheet" href="/static/css/responsive-tables.css">
    <link rel="stylesheet" href="/static/css/tables.css">
    <link rel="icon" href="/static/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/static/css/vazir.css">
    <link rel="stylesheet" href="/static/css/dark-theme.css">
    <link rel="stylesheet" href="/static/css/final-report-modern.css">
    <link rel="stylesheet" href="/static/css/toast.css">
    <!-- لایهٔ اختصاصی چاپ: باید آخرین stylesheet باشد تا بر قوانین print قدیمی اولویت داشته باشد -->
    <link rel="stylesheet" href="/static/css/final-report-print.css">
    <script src="/static/js/theme.js"></script>
</head>

<body class="final-report-page" data-no-theme-toggle>

    <div class="onvanha">
        <div class="titleBox">
            گزارش {$monthName} ماه {$year} حضور و غیاب
        </div>

        <div class="userInfoBox">
            <div class="userInfoSection">
                <span class="userInfoTitle">بخش فعالیت:</span>
                <span class="userInfoValue" id="userIdID">تست</span>
            </div>
            <div class="userInfoName">
                <span class="userInfoTitle">نام کاربر:</span>
                <span class="userInfoValue" id="userNameID">تست</span>
            </div>
        </div>
    </div>

    <!-- نوار تب‌بندی گزارش -->
    <!-- جدول حضور و غیاب نهایی -->
    <div class="report-content-layout" data-tab="daily">
        <div class="report-tabs" role="tablist" aria-label="بخش‌های گزارش نهایی">
            <button type="button" class="report-tab is-active" role="tab" aria-selected="true"
                    aria-controls="panel-daily" data-tab-target="daily">
                <span class="report-tab__icon" aria-hidden="true">📅</span>
                <span>جزئیات روزانهٔ ثبت‌شده</span>
            </button>
            <button type="button" class="report-tab" role="tab" aria-selected="false"
                    aria-controls="panel-summary" data-tab-target="summary">
                <span class="report-tab__icon" aria-hidden="true">📊</span>
                <span>جداول و خلاصهٔ عملکرد</span>
            </button>
        </div>

        <div class="bala" id="panel-daily">
        <div class="hozoorBox">
            <h2>جدول حضور و غیاب</h2>
            <table class="hozoorUsersReport-table" id="hozoorUsersReportTable">
                <thead>
                    <tr>
                        <th class="stn1">ردیف</th>
                        <th class="stn2">تاریخ ثبت</th>
                        <th class="stn3">روز هفته</th>

                        <th class="stn4">زمان ورود</th>
                        <th class="stn5">زمان خروج</th>

                        <th class="stn6">ورود دوم</th>
                        <th class="stn7">خروج دوم</th>

                        <th class="stn8">تاخیر</th>
                        <th class="stn9">شروع زود هنگام</th>
                        <th class="stn10">خروج زود هنگام</th>
                        <th class="stn11">اضافه کاری</th>
                        <th class="stn12">مجموع زمان حضور</th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>

    <!-- باکس های آمار و اعداد و جداول مرخصی، پاس ساعتی و اضافه کاری -->
    <div class="paein" id="panel-summary">
        <div class="left-section">
            <div class="ezafeBox">
                <h2>جدول اضافه‌کار</h2>
                <table class="ezafeKarUsersReport-table" id="ezafeKarUsersReportTable">
                    <thead>
                        <tr>
                            <th class="stn6-ezafeBox">توضیحات</th>
                            <th class="stn5-ezafeBox">مجموع</th>
                            <th class="stn4-ezafeBox">تا ساعت</th>
                            <th class="stn3-ezafeBox">از ساعت</th>
                            <th class="stn2-ezafeBox">تاریخ</th>
                            <th class="stn1-ezafeBox">ردیف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- داده‌ها اینجا اضافه می‌شوند -->
                    </tbody>
                </table>
            </div>

            <div class="passBox">
                <h2>جدول مرخصی</h2>
                <table class="morkhcUsersReport-table" id="morkhcUsersReportTable">
                    <thead>
                        <tr>
                            <th class="stn5-morkhcBox">جانشین</th>
                            <th class="stn4-morkhcBox">تعداد روز</th>
                            <th class="stn3-morkhcBox">تا تاریخ</th>
                            <th class="stn2-morkhcBox">از تاریخ </th>
                            <th class="stn1-morkhcBox">ردیف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- داده‌ها اینجا اضافه می‌شوند -->
                    </tbody>
                </table>
            </div>

            <div class="hourlyPassBox">
                <h2>جدول پاس‌های ساعتی</h2>
                <table class="hourlyPassUsersReport-table" id="hourlyPassUsersReportTable">
                    <thead>
                        <tr>
                            <th class="stn4-hourlyPassBox">مدت پاس</th>
                            <th class="stn3-hourlyPassBox">نوع پاس</th>
                            <th class="stn2-hourlyPassBox">تاریخ</th>
                            <th class="stn1-hourlyPassBox">ردیف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- داده‌ها اینجا اضافه می‌شوند -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="paeinrast report-summary-panel">
            <div class="report-summary-heading">
                <div>
                    <span>At a glance</span>
                    <h2>خلاصهٔ عملکرد</h2>
                </div>
                <strong aria-hidden="true">✦</strong>
            </div>
            <div class="numberReport report-summary-grid">
                <!-- ردیف اول -->
                <div class="numBoxEzafeh report-summary-card report-summary-card--wide report-summary-card--green">
                    <span class="title">مجموع اضافه کاری</span>
                    <div class="overtimeContainer">
                        <div class="overtimeSystem">
                            <p class="overtimeSystemTitle">سیستم</p>
                            <span class="overtimeSystemValue" id="ezafeNumBoxID">00:00</span>
                        </div>
                        <div class="overtimeSamaneh">
                            <p class="overtimeSamanehTitle">سامانه</p>
                            <span class="overtimeSamanehValue samanehTime">00:00</span>
                        </div>
                    </div>
                </div>

                <div class="numBox report-summary-card report-summary-card--wide report-summary-card--blue">
                    <span class="title">مجموع زمان حضور</span>
                    <div class="attendanceContainer">
                        <div class="attendanceSystem">
                            <p class="attendanceSystemTitlemajmoo">سیستم</p>
                            <span class="attendanceSystemValue" id="attendanceNumBoxID">00:00</span>
                        </div>
                        <div class="attendanceSamaneh">
                            <p class="attendanceSamanehTitle">سامانه</p>
                            <span class="attendanceSamanehValue samanehTime" id="attendanceSamanehValue">00:00</span>
                        </div>
                    </div>
                </div>

                <div class="numBox report-summary-card report-summary-card--wide report-summary-card--violet" id="hozoornumBoxID">
                    <div class="title">گزارش حضور روزانه</div>
                    <div class="attendanceContainer">
                        <div class="attendanceSystem">
                            <p class="attendanceSystemTitlegozaresh">سیستم</p>
                            <span class="attendanceSystemValue" id="attendanceReportDaysID">25 روز</span>
                        </div>
                        <div class="attendanceSamaneh">
                            <p class="attendanceSamanehTitlemovazafi">موظفی</p>
                            <input type="text" id="holidayDays" class="attendanceSamanehValueInput" placeholder=" " />
                        </div>
                    </div>
                </div>

                <!-- ردیف دوم -->
                <div class="numBox report-summary-card report-summary-card--compact report-stat-card--amber">
                    <span class="title">مجموع زمان تاخیر</span>
                    <span class="value" id="totalDelayID">00:00</span>
                </div>
                <div class="numBox report-summary-card report-summary-card--compact report-stat-card--blue">
                    <span class="title">مجموع پاس های ساعتی</span>
                    <span class="value" id="passNumBoxID">00:00</span>
                </div>
                <div class="numBox report-summary-card report-summary-card--compact report-stat-card--green">
                    <span class="title">مجموع شروع زودهنگام</span>
                    <span class="value" id="totalEarlyStartID">00:00</span>
                </div>
                <div class="numBox report-summary-card report-summary-card--compact report-stat-card--red">
                    <span class="title">مجموع خروج زودهنگام</span>
                    <span class="value" id="totalEarlyExitID">00:00</span>
                </div>                <div class="numBox report-summary-card report-summary-card--compact report-stat-card--slate">
                    <span class="title">     مجموع مرخصی     </span>
                    <strong class="jscode"><span id="roozeMorkhc">۰</span><span>روز</span></strong>
                </div>
            </div>

            <div class="dokmeha">
                <div class="exportButtons">
                    <button type="button" class="btn report-action report-action--primary" id="savePdfReportBtn">
                        <span aria-hidden="true">↓</span>دریافت فایل گزارش (PDF)
                    </button>
                    <button type="button" class="btn report-action report-action--secondary" id="printReportBtn">
                        <span aria-hidden="true">⎙</span>چاپ گزارش
                    </button>
                </div>
            </div>
        </div>
    </div>

    </div>


    <div class="emzaha">
        <div class="signature-box">
            <strong>امضای مدیر آزمایشگاه</strong>
        </div>

        <div class="signature-box-hamkar">
            <strong>امضای همکار</strong>
        </div>
    </div>


    <!-- ────────────────────────────────────────────────────────────────
         لایهٔ چاپ: این ظرف روی نمایشگر پنهان است و فقط هنگام چاپ دیده
         می‌شود. محتوای آن را final-report-print.js از همان داده‌های
         رندرشدهٔ صفحه می‌سازد (حداکثر ۳۱ رکورد در هر برگ A4).
         ──────────────────────────────────────────────────────────────── -->
    <div id="printReport" class="print-report" aria-hidden="true"></div>


    <!-- moment/moment-jalaali CDN removed — not used by final-report-script.js -->
        <script src="/static/js/responsive-tables.js"></script>
<script src="/static/js/final-report-script.js"></script>
        <script src="/static/js/dom-escape.js"></script>        <script src="/static/js/toast.js"></script>
        <!-- html2pdf (906 KB) is no longer loaded here: it is fetched on demand by
             final-report-print.js the first time the "دریافت PDF" button is pressed.
             Loading it on every page view cost ~906 KB for a report page that is
             normally read or window.print()ed. -->
        <script src="/static/js/final-report-print.js?v=20260928"></script>



</body>

</html>
HTML;
    }

    /**
     * Wrap a report document in the response the Python returned.
     *
     * `HTMLResponse` is `text/html` with a UTF-8 charset; the Persian report
     * text must not be re-encoded.
     */
    public static function response(string $html): Response
    {
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
