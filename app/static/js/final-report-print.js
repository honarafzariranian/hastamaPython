/* =====================================================================
   final-report-print.js
   ---------------------------------------------------------------------
   سازندهٔ «فرم چاپ» گزارش نهایی حضور و غیاب.

   چرا یک اسکریپت جدا؟
   چیدمان نمایشگر (تب‌ها، کارت‌های رنگی، اسکرول افقی، سایه و گرادیان)
   روی کاغذ A4 معنا ندارد. این اسکریپت همان داده‌های رندرشدهٔ روی صفحه
   را می‌خواند و یک DOM کاملاً مجزا (#printReport) می‌سازد که فقط هنگام
   چاپ دیده می‌شود و به شکل یک فرم اداری سیاه‌وسفید صفحه‌بندی شده است.

   قواعد صفحه‌بندی:
     • هر صفحهٔ A4 حداکثر ۳۱ رکورد حضور و غیاب دارد.
     • اگر روزها بیشتر از ۳۱ باشد، گزارش به چند صفحه تقسیم می‌شود و
       سربرگ در همهٔ صفحه‌ها تکرار می‌شود.
     • خلاصهٔ عملکرد، جداول اضافه‌کار/مرخصی/پاس ساعتی و محل امضاها
       روی صفحهٔ آخر چاپ می‌شوند.
     • ارتفاع ردیف‌ها بر اساس فضای باقی‌ماندهٔ همان صفحه محاسبه
       می‌شود تا محتوا دقیقاً در یک برگ A4 بنشیند و سرریز نکند.
   ===================================================================== */
(function () {
    'use strict';

    /* ── ثابت‌های صفحه‌بندی (میلی‌متر) ───────────────────────────────
       A4 = 210×297 و @page margin = 8mm 7mm  →  ناحیهٔ چاپ 196×281
       (برای جلوگیری از سرریزِ rounding، ارتفاع برگ 279mm در نظر گرفته شده) */
    var MAX_ROWS_PER_PAGE = 31;

    var GEO = {
        pageH: 279,
        headerH: 17,
        footerH: 6,
        gap: 2.4,
        sectTitleH: 6.5,
        attHeadH: 9,
        summaryH: 30,
        signH: 19,
        sideRowH: 4.7,
        minSideRowH: 3.4,
        maxBandH: 118,
        minRowH: 4.6,
        maxRowH: 9.5
    };

    /* ── ابزارها ───────────────────────────────────────────────────── */
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function toFa(value) {
        return String(value == null ? '' : value).replace(/\d/g, function (d) {
            return FA_DIGITS[Number(d)];
        });
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function clean(value) {
        var text = String(value == null ? '' : value)
            .replace(/[\u200f\u200e]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        return text;
    }

    function cellOrDash(value) {
        var text = clean(value);
        return text === '' ? '' : text;
    }

    function textOf(el) {
        return el ? clean(el.textContent) : '';
    }

    /* مقدار زمانی/عددی که از صفحه خوانده می‌شود؛ ارقام لاتینی به فارسی
       یکدست می‌شوند تا خروجی چاپ مخلوطِ «03:45» و «۰۳:۴۵» نباشد. */
    function timeOf(el, fallback) {
        var text = toFa(textOf(el));
        return text === '' ? (fallback || '۰۰:۰۰') : text;
    }

    function readRows(tableId, minCells) {
        var table = document.getElementById(tableId);
        if (!table) return [];
        var trs = table.querySelectorAll('tbody tr');
        var out = [];
        Array.prototype.forEach.call(trs, function (tr) {
            var tds = tr.getElementsByTagName('td');
            if (tds.length < minCells) return;
            var cells = [];
            for (var i = 0; i < tds.length; i++) cells.push(textOf(tds[i]));
            out.push({ cells: cells, holiday: tr.classList.contains('holiday-row') });
        });
        return out;
    }

    function todayFa() {
        try {
            return new Intl.DateTimeFormat('fa-IR', {
                year: 'numeric', month: '2-digit', day: '2-digit'
            }).format(new Date());
        } catch (e) {
            return '';
        }
    }

    /* ── تعریف ستون‌ها ─────────────────────────────────────────────── */
    /* ترتیب سلول‌های جدول نمایشگر (final-report-script.js):
       0 ردیف، 1 تاریخ، 2 روز، 3 ورود، 4 خروج، 5 ورود۲، 6 خروج۲،
       7 تاخیر، 8 شروع‌زود، 9 خروج‌زود، 10 اضافه‌کار، 11 مجموع حضور */
    var ATT_COLUMNS = [
        { title: 'ردیف', w: '9mm', src: 0 },
        { title: 'تاریخ', w: '21mm', src: 1 },
        { title: 'روز هفته', w: '18mm', src: 2 },
        { title: 'ورود', w: '15.5mm', src: 3 },
        { title: 'خروج', w: '15.5mm', src: 4 },
        { title: 'ورود دوم', w: '15.5mm', src: 5 },
        { title: 'خروج دوم', w: '15.5mm', src: 6 },
        { title: 'تاخیر', w: '14mm', src: 7 },
        { title: 'شروع زودهنگام', w: '17mm', src: 8 },
        { title: 'خروج زودهنگام', w: '17mm', src: 9 },
        { title: 'اضافه‌کاری', w: '15mm', src: 10 },
        { title: 'مجموع حضور', w: '23mm', src: 11, bold: true }
    ];

    /* سلول‌های جدول اضافه‌کار: 0 توضیحات، 1 مجموع، 2 تا ساعت، 3 از ساعت، 4 تاریخ، 5 ردیف */
    var OVERTIME_COLUMNS = [
        { title: 'ردیف', w: '9.5%', src: 5 },
        { title: 'تاریخ', w: '20.5%', src: 4 },
        { title: 'از ساعت', w: '15%', src: 3 },
        { title: 'تا ساعت', w: '15%', src: 2 },
        { title: 'مدت', w: '15.8%', src: 1, bold: true },
        { title: 'توضیحات', w: '24.2%', src: 0 }
    ];

    /* سلول‌های جدول مرخصی: 0 جانشین، 1 تعداد روز، 2 تا تاریخ، 3 از تاریخ، 4 ردیف */
    var LEAVE_COLUMNS = [
        { title: 'ردیف', w: '9.5%', src: 4 },
        { title: 'از تاریخ', w: '20.5%', src: 3 },
        { title: 'تا تاریخ', w: '20.5%', src: 2 },
        { title: 'تعداد روز', w: '13%', src: 1, bold: true },
        { title: 'جانشین', w: '36.5%', src: 0 }
    ];

    /* سلول‌های پاس ساعتی: 0 مدت، 1 نوع پاس، 2 تاریخ، 3 ردیف */
    var PASS_COLUMNS = [
        { title: 'ردیف', w: '11%', src: 3 },
        { title: 'تاریخ', w: '22%', src: 2 },
        { title: 'نوع پاس', w: '34.8%', src: 1 },
        { title: 'مدت پاس', w: '32.2%', src: 0, bold: true }
    ];

    /* ── رندر جدول ─────────────────────────────────────────────────── */
    function renderTable(columns, rows, extraClass) {
        var html = '<table class="pr-table ' + (extraClass || '') + '">';
        html += '<colgroup>' + columns.map(function (c) {
            return '<col style="width:' + c.w + '">';
        }).join('') + '</colgroup>';

        html += '<thead><tr>' + columns.map(function (c) {
            return '<th>' + esc(c.title) + '</th>';
        }).join('') + '</tr></thead>';

        html += '<tbody>';
        if (!rows.length) {
            html += '<tr><td class="pr-empty-row" colspan="' + columns.length +
                '">موردی ثبت نشده است</td></tr>';
        } else {
            rows.forEach(function (row) {
                var cls = row.holiday ? ' class="pr-row--holiday"' : '';
                html += '<tr' + cls + '>' + columns.map(function (c) {
                    var value = cellOrDash(row.cells[c.src]);
                    var tdClass = [];
                    if (c.bold) tdClass.push('pr-cell--total');
                    if (value === '') tdClass.push('pr-empty');
                    var attr = tdClass.length ? ' class="' + tdClass.join(' ') + '"' : '';
                    return '<td' + attr + '>' + (value === '' ? '—' : esc(value)) + '</td>';
                }).join('') + '</tr>';
            });
        }
        html += '</tbody></table>';
        return html;
    }

    /* ── سربرگ (در همهٔ صفحه‌ها تکرار می‌شود) ───────────────────────── */
    function renderHead(ctx, pageNo, pageCount) {
        return '' +
            '<header class="pr-head">' +
                '<div class="pr-head__cell pr-head__brand">' +
                    '<span class="pr-head__org">سامانه هستما</span>' +
                    '<span class="pr-head__sys">سامانهٔ حضور و غیاب و تردد پرسنل</span>' +
                '</div>' +
                '<div class="pr-head__cell pr-head__main">' +
                    '<h1 class="pr-head__title">' + esc(ctx.title) + '</h1>' +
                    '<p class="pr-head__sub">گزارش فردی — قابل تحویل به امور اداری</p>' +
                '</div>' +
                '<div class="pr-head__cell pr-head__meta">' +
                    '<span>صفحه</span>' +
                    '<b>' + toFa(pageNo) + ' از ' + toFa(pageCount) + '</b>' +
                    (ctx.printDate ? '<span>تاریخ چاپ: ' + esc(ctx.printDate) + '</span>' : '') +
                '</div>' +
            '</header>' +
            '<div class="pr-idstrip">' +
                '<div><span>نام و نام خانوادگی: </span><b>' + esc(ctx.userName || '—') + '</b></div>' +
                '<div><span>بخش فعالیت: </span><b>' + esc(ctx.department || '—') + '</b></div>' +
                '<div><span>نام کاربری: </span><b>' + esc(ctx.username || '—') + '</b></div>' +
            '</div>';
    }

    /* ── خلاصهٔ عملکرد ─────────────────────────────────────────────── */
    function renderSummary(ctx) {
        var stats = [
            { label: 'روزهای حضور ثبت‌شده', value: toFa(ctx.daysCount) + ' روز' },
            { label: 'مجموع زمان حضور (سیستم)', value: ctx.attendanceSystem },
            { label: 'مجموع زمان حضور (سامانه)', value: ctx.attendanceSamaneh },
            { label: 'مجموع اضافه‌کاری (سیستم)', value: ctx.overtimeSystem },
            { label: 'مجموع اضافه‌کاری (سامانه)', value: ctx.overtimeSamaneh },
            { label: 'مجموع تاخیر', value: ctx.totalDelay },
            { label: 'مجموع شروع زودهنگام', value: ctx.totalEarlyStart },
            { label: 'مجموع خروج زودهنگام', value: ctx.totalEarlyExit },
            { label: 'مجموع پاس‌های ساعتی', value: ctx.totalPass },
            { label: 'مجموع مرخصی (روز)', value: toFa(ctx.leaveDays) + ' روز' }
        ];
        var perRow = 5;

        var cells = stats.map(function (s, i) {
            var lastRow = i >= stats.length - perRow;
            return '<div class="pr-stat' + (lastRow ? ' pr-stat--last-row' : '') + '">' +
                '<span class="pr-stat__label">' + esc(s.label) + '</span>' +
                '<span class="pr-stat__value">' + esc(s.value || '—') + '</span>' +
            '</div>';
        }).join('');

        return '<section class="pr-sect pr-sect--summary">' +
            '<div class="pr-sect__title">خلاصهٔ عملکرد' +
                '<small>مقادیر «سیستم» محاسبهٔ سامانهٔ هستما و «سامانه» مقدار ثبت‌شده در دستگاه تردد است</small>' +
            '</div>' +
            '<div class="pr-summary">' + cells + '</div>' +
        '</section>';
    }

    /* ── امضاها ─────────────────────────────────────────────────────── */
    function renderSignatures(ctx) {
        return '<section class="pr-sign pr-sect--signatures">' +
            '<div class="pr-sign__box">' +
                '<span class="pr-sign__label">امضای همکار</span>' +
                '<div class="pr-sign__line"></div>' +
                '<span class="pr-sign__hint">صحت مندرجات این گزارش را تایید می‌نمایم.</span>' +
            '</div>' +
            '<div class="pr-sign__box">' +
                '<span class="pr-sign__label">امضا و مهر مدیر آزمایشگاه</span>' +
                '<div class="pr-sign__line"></div>' +
                '<span class="pr-sign__hint">روزهای موظفی ماه: ' +
                    esc(ctx.dutyDays ? toFa(ctx.dutyDays) : '………………') +
                '</span>' +
            '</div>' +
        '</section>';
    }

    function renderFoot(ctx, pageNo, pageCount) {
        return '<footer class="pr-foot">' +
            '<span>سامانهٔ هستما — گزارش خودکار حضور و غیاب</span>' +
            '<span>' + esc(ctx.printDate || '') + '</span>' +
            '<span>برگ ' + toFa(pageNo) + '/' + toFa(pageCount) + '</span>' +
        '</footer>';
    }

    /* ── محاسبهٔ تقسیم صفحه‌ها ──────────────────────────────────────── */
    function bandHeight(sideRows, sideRowH) {
        return GEO.sectTitleH + (sideRows + 1) * sideRowH;
    }

    function computePlan(totalRows, sideRows) {
        var sideRowH = GEO.sideRowH;
        var maxSide = Math.max(sideRows, 1);
        /* اگر جداول جانبی بلند باشند، ارتفاع ردیف آن‌ها تا «حداقلِ خوانا»
           کوچک می‌شود تا نوار در همان برگ جا شود. از آن حداقل پایین‌تر
           نمی‌رویم؛ در آن حالت ارتفاع واقعی نوار بزرگ‌تر از بودجه می‌شود و
           برگ آخر به‌جای بریدن، به‌صورت جریان‌دار (flow) صفحه‌بندی می‌شود. */
        if (bandHeight(maxSide, sideRowH) > GEO.maxBandH) {
            sideRowH = Math.max(GEO.minSideRowH, (GEO.maxBandH - GEO.sectTitleH) / (maxSide + 1));
        }
        var band = bandHeight(maxSide, sideRowH);

        var fixedOnLast =
            GEO.headerH + GEO.footerH +
            GEO.summaryH + band + GEO.signH +
            GEO.sectTitleH + GEO.attHeadH +
            GEO.gap * 4;

        var roomForRows = GEO.pageH - fixedOnLast;
        var lastPageCap = roomForRows <= 0
            ? 0
            : Math.min(MAX_ROWS_PER_PAGE, Math.floor(roomForRows / GEO.minRowH));
        lastPageCap = Math.max(lastPageCap, 0);

        /* تقسیم رکوردها: صفحه‌های وسط همیشه ۳۱ ردیف می‌گیرند و صفحهٔ آخر
           (که خلاصه/جداول جانبی/امضا روی آن است) هرگز بیشتر از ظرفیت
           محاسبه‌شده ردیف نمی‌گیرد. */
        var chunks = [];
        var index = 0;
        while (index < totalRows) {
            var remaining = totalRows - index;
            var size;
            if (remaining <= lastPageCap) {
                size = remaining;
            } else if (remaining <= MAX_ROWS_PER_PAGE) {
                /* همهٔ ردیف‌های باقی‌مانده در یک برگ ۳۱تایی جا می‌شوند، اما آن
                   برگ «برگ آخر» است و ظرفیت کمتری دارد؛ پس بخشی از ردیف‌ها را
                   نگه می‌داریم تا برگ آخر سرریز نکند. */
                size = Math.max(1, remaining - lastPageCap);
            } else {
                size = MAX_ROWS_PER_PAGE;
            }
            size = Math.max(1, Math.min(size, remaining));
            chunks.push({ start: index, size: size });
            index += size;
        }

        /* آخرین برگ همیشه برگِ خلاصه/امضا است؛ اگر ظرفیتی برای ردیف نداشت،
           یک برگ خالیِ خلاصه اضافه می‌کنیم. */
        if (!chunks.length || chunks[chunks.length - 1].size > lastPageCap) {
            chunks.push({ start: totalRows, size: 0 });
        }

        /* ارتفاع ردیف هر برگ طوری انتخاب می‌شود که برگ را پر کند. */
        chunks.forEach(function (chunk, i) {
            var isLast = i === chunks.length - 1;
            var available;
            if (isLast) {
                available = GEO.pageH - (
                    GEO.headerH + GEO.footerH + GEO.summaryH + band + GEO.signH +
                    GEO.sectTitleH + GEO.attHeadH + GEO.gap * 4
                );
            } else {
                available = GEO.pageH - (
                    GEO.headerH + GEO.footerH + GEO.sectTitleH + GEO.attHeadH + GEO.gap * 2
                );
            }
            var rows = Math.max(chunk.size, 1);
            var rowH = available / rows;
            chunk.rowH = Math.max(GEO.minRowH, Math.min(GEO.maxRowH, rowH));
            chunk.isLast = isLast;
            /* برگِ جریان‌دار: وقتی نوار جداول جانبی آن‌قدر بلند است که با
               ارتفاع ثابتِ A4 نمی‌گنجد، ارتفاع برگ آزاد می‌شود تا محتوا به
               صفحهٔ بعد برود و هیچ ردیفی بریده نشود. */
            chunk.flow = chunk.size === 0 && available < GEO.minRowH;
        });

        return { chunks: chunks, sideRowH: sideRowH, bandH: band };
    }

    /* ── ساخت کل فرم چاپ ───────────────────────────────────────────── */
    function collectContext() {
        var attendance = readRows('hozoorUsersReportTable', 12);
        var overtime = readRows('ezafeKarUsersReportTable', 6);
        var leave = readRows('morkhcUsersReportTable', 5);
        var passes = readRows('hourlyPassUsersReportTable', 4);

        var titleBox = document.querySelector('.titleBox');
        var dutyInput = document.getElementById('holidayDays');

        return {
            title: textOf(titleBox) || 'گزارش حضور و غیاب',
            userName: textOf(document.getElementById('userNameID')),
            department: textOf(document.getElementById('userIdID')),
            username: clean(localStorage.getItem('selectedUsername') || ''),
            printDate: todayFa(),
            daysCount: attendance.length,
            attendanceSystem: timeOf(document.getElementById('attendanceNumBoxID')),
            attendanceSamaneh: timeOf(document.getElementById('attendanceSamanehValue')),
            overtimeSystem: timeOf(document.getElementById('ezafeNumBoxID')),
            overtimeSamaneh: timeOf(document.querySelector('.samanehTime')),
            totalDelay: timeOf(document.getElementById('totalDelayID')),
            totalEarlyStart: timeOf(document.getElementById('totalEarlyStartID')),
            totalEarlyExit: timeOf(document.getElementById('totalEarlyExitID')),
            totalPass: timeOf(document.getElementById('passNumBoxID')),
            leaveDays: textOf(document.getElementById('roozeMorkhc')) || '۰',
            dutyDays: dutyInput ? clean(dutyInput.value) : '',
            attendance: attendance,
            overtime: overtime,
            leave: leave,
            passes: passes
        };
    }

    function buildPrintReport() {
        var host = document.getElementById('printReport');
        if (!host) return false;

        var ctx = collectContext();
        if (!ctx.attendance.length) {
            host.innerHTML = '';
            document.body.classList.remove('pr-ready');
            return false;
        }

        var sideRows = Math.max(ctx.overtime.length, ctx.leave.length, ctx.passes.length);
        var plan = computePlan(ctx.attendance.length, sideRows);
        var pageCount = plan.chunks.length;

        var html = plan.chunks.map(function (chunk, i) {
            var pageNo = i + 1;
            var rows = ctx.attendance.slice(chunk.start, chunk.start + chunk.size);

            var parts = [renderHead(ctx, pageNo, pageCount)];
            parts.push('<div class="pr-gap"></div>');

            if (chunk.isLast) {
                parts.push(renderSummary(ctx));
                parts.push('<div class="pr-gap"></div>');
            }

            if (chunk.size > 0) {
                parts.push(
                    '<section class="pr-sect pr-sect--attendance">' +
                        '<div class="pr-sect__title">جدول حضور و غیاب' +
                            '<small>رکوردهای ' + toFa(chunk.start + 1) + ' تا ' +
                            toFa(chunk.start + chunk.size) + ' از ' + toFa(ctx.attendance.length) +
                            ' — ردیف‌های خاکستری روزهای تعطیل هستند</small>' +
                        '</div>' +
                        '<div class="pr-sect__body" style="--pr-row-h:' + chunk.rowH.toFixed(2) + 'mm">' +
                            renderTable(ATT_COLUMNS, rows, 'pr-att') +
                        '</div>' +
                    '</section>'
                );
            }

            if (chunk.isLast) {
                parts.push('<div class="pr-gap"></div>');
                parts.push(
                    '<div class="pr-details" style="--pr-side-row-h:' + plan.sideRowH.toFixed(2) + 'mm">' +
                        '<section class="pr-sect pr-sect--overtime">' +
                            '<div class="pr-sect__title">اضافه‌کاری</div>' +
                            renderTable(OVERTIME_COLUMNS, ctx.overtime, 'pr-side-table') +
                        '</section>' +
                        '<section class="pr-sect pr-sect--leave">' +
                            '<div class="pr-sect__title">مرخصی</div>' +
                            renderTable(LEAVE_COLUMNS, ctx.leave, 'pr-side-table') +
                        '</section>' +
                        '<section class="pr-sect pr-sect--hourly">' +
                            '<div class="pr-sect__title">پاس‌های ساعتی</div>' +
                            renderTable(PASS_COLUMNS, ctx.passes, 'pr-side-table') +
                        '</section>' +
                    '</div>'
                );
                parts.push('<div class="pr-gap"></div>');
                parts.push(renderSignatures(ctx));
            }

            parts.push(renderFoot(ctx, pageNo, pageCount));

            return '<section class="pr-sheet' + (chunk.flow ? ' pr-sheet--flow' : '') +
                '" data-page="' + pageNo + '">' + parts.join('') + '</section>';
        }).join('');

        host.innerHTML = html;
        host.setAttribute('aria-hidden', 'true');
        document.body.classList.add('pr-ready');
        return true;
    }

    /* ── زمان‌بندی اجرا ─────────────────────────────────────────────── */
    function scheduleBuild() {
        /* اطلاعات کاربر به‌صورت async پر می‌شود؛ پیش از چاپ یک‌بار دیگر
           فرم را می‌سازیم تا مقادیر نهایی روی کاغذ بنشینند. */
        buildPrintReport();
    }

    window.addEventListener('load', scheduleBuild);
    window.addEventListener('beforeprint', scheduleBuild);

    if (document.readyState === 'complete') scheduleBuild();

    /* ── دکمه‌های چاپ و دریافت PDF ───────────────────────────────────
       دادهٔ گزارش در localStorage مرورگر است، پس فایل PDF هم باید سمت
       کلاینت ساخته شود؛ هر دو دکمه همان فرم چاپ را باز می‌کنند و کاربر
       در گفت‌وگوی چاپ گزینهٔ Save as PDF را انتخاب می‌کند. */
    function initPrintButtons() {
        ['printReportBtn', 'savePdfReportBtn'].forEach(function (id) {
            var btn = document.getElementById(id);
            if (!btn || btn.dataset.prWired === '1') return;
            btn.dataset.prWired = '1';
            btn.addEventListener('click', function () {
                buildPrintReport();
                window.print();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrintButtons);
    } else {
        initPrintButtons();
    }

    /* دسترسی دستی (برای تست و برای دکمهٔ چاپ) */
    window.HastamaPrintReport = {
        rebuild: buildPrintReport,
        computePlan: computePlan,
        collectContext: collectContext,
        maxRowsPerPage: MAX_ROWS_PER_PAGE,
        geometry: GEO
    };
})();
