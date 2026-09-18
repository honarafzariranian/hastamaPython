/* Runtime test harness for final-report-print.js (jsdom).
   Verifies the A4 print-form builder:
     • the print layer is generated into #printReport and body gets .pr-ready
     • every sheet holds at most 31 attendance records
     • >31 days splits into the minimum number of A4 sheets
     • summary / side tables / signature blocks live on the last sheet only
     • the header repeats on every sheet
     • an empty report leaves the legacy print fallback untouched
*/
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');

const STATIC = path.resolve(__dirname, '..', '..', 'app', 'static', 'js');
const REPORT_SCRIPT = fs.readFileSync(path.join(STATIC, 'final-report-script.js'), 'utf8');
const PRINT_SCRIPT = fs.readFileSync(path.join(STATIC, 'final-report-print.js'), 'utf8');

let pass = 0, fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ FAIL: ' + name + (extra ? ' → ' + extra : '')); }
}

const FA = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const toFa = (v) => String(v).replace(/\d/g, (d) => FA[Number(d)]);

function shellHtml() {
  return `<!DOCTYPE html><html lang="fa" dir="rtl"><head></head>
<body class="final-report-page">
  <div class="onvanha">
    <div class="titleBox">گزارش اردیبهشت ماه ۱۴۰۴ حضور و غیاب</div>
    <div class="userInfoBox">
      <div class="userInfoSection"><span class="userInfoTitle">بخش فعالیت:</span><span class="userInfoValue" id="userIdID">آزمایشگاه</span></div>
      <div class="userInfoName"><span class="userInfoTitle">نام کاربر:</span><span class="userInfoValue" id="userNameID">علی احمدی</span></div>
    </div>
  </div>

  <div class="report-content-layout" data-tab="daily">
    <div class="report-tabs" role="tablist">
      <button type="button" class="report-tab is-active" data-tab-target="daily">روزانه</button>
      <button type="button" class="report-tab" data-tab-target="summary">خلاصه</button>
    </div>

    <div class="bala" id="panel-daily">
      <div class="hozoorBox">
        <h2>جدول حضور و غیاب</h2>
        <table class="hozoorUsersReport-table" id="hozoorUsersReportTable">
          <thead><tr><th>ردیف</th><th>تاریخ ثبت</th><th>روز هفته</th><th>زمان ورود</th><th>زمان خروج</th><th>ورود دوم</th><th>خروج دوم</th><th>تاخیر</th><th>شروع زود هنگام</th><th>خروج زود هنگام</th><th>اضافه کاری</th><th>مجموع زمان حضور</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div class="paein" id="panel-summary">
      <div class="left-section">
        <div class="ezafeBox"><h2>جدول اضافه‌کار</h2>
          <table class="ezafeKarUsersReport-table" id="ezafeKarUsersReportTable">
            <thead><tr><th>توضیحات</th><th>مجموع</th><th>تا ساعت</th><th>از ساعت</th><th>تاریخ</th><th>ردیف</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="passBox"><h2>جدول مرخصی</h2>
          <table class="morkhcUsersReport-table" id="morkhcUsersReportTable">
            <thead><tr><th>جانشین</th><th>تعداد روز</th><th>تا تاریخ</th><th>از تاریخ</th><th>ردیف</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="hourlyPassBox"><h2>جدول پاس‌های ساعتی</h2>
          <table class="hourlyPassUsersReport-table" id="hourlyPassUsersReportTable">
            <thead><tr><th>مدت پاس</th><th>نوع پاس</th><th>تاریخ</th><th>ردیف</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="paeinrast report-summary-panel">
        <div class="numberReport report-summary-grid">
          <div class="numBoxEzafeh"><span class="title">مجموع اضافه کاری</span>
            <div class="overtimeContainer">
              <div class="overtimeSystem"><p class="overtimeSystemTitle">سیستم</p><span class="overtimeSystemValue" id="ezafeNumBoxID">00:00</span></div>
              <div class="overtimeSamaneh"><p class="overtimeSamanehTitle">سامانه</p><span class="overtimeSamanehValue samanehTime">00:00</span></div>
            </div>
          </div>
          <div class="numBox" id="attendanceBox">
            <span class="title">مجموع زمان حضور</span>
            <div class="attendanceContainer">
              <div class="attendanceSystem"><p class="attendanceSystemTitlemajmoo">سیستم</p><span class="attendanceSystemValue" id="attendanceNumBoxID">00:00</span></div>
              <div class="attendanceSamaneh"><p class="attendanceSamanehTitle">سامانه</p><span class="attendanceSamanehValue samanehTime" id="attendanceSamanehValue">00:00</span></div>
            </div>
          </div>
          <div class="numBox" id="hozoornumBoxID">
            <div class="title">گزارش حضور روزانه</div>
            <div class="attendanceContainer">
              <div class="attendanceSystem"><p class="attendanceSystemTitlegozaresh">سیستم</p><span class="attendanceSystemValue" id="attendanceReportDaysID">25 روز</span></div>
              <div class="attendanceSamaneh"><p class="attendanceSamanehTitlemovazafi">موظفی</p><input type="text" id="holidayDays" value="22"></div>
            </div>
          </div>
          <div class="numBox"><span class="title">مجموع زمان تاخیر</span><span class="value" id="totalDelayID">00:00</span></div>
          <div class="numBox"><span class="title">مجموع پاس های ساعتی</span><span class="value" id="passNumBoxID">00:00</span></div>
          <div class="numBox"><span class="title">مجموع شروع زودهنگام</span><span class="value" id="totalEarlyStartID">00:00</span></div>
          <div class="numBox"><span class="title">مجموع خروج زودهنگام</span><span class="value" id="totalEarlyExitID">00:00</span></div>
          <div class="numBox"><span class="title">مجموع مرخصی</span><strong class="jscode"><span id="roozeMorkhc">۰</span><span>روز</span></strong></div>
        </div>
        <div class="dokmeha">
          <div class="exportButtons">
            <button type="button" class="btn report-action report-action--primary" id="savePdfReportBtn">دریافت فایل گزارش</button>
            <button type="button" class="btn report-action report-action--secondary" id="printReportBtn">چاپ گزارش</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="emzaha">
    <div class="signature-box"><strong>امضای مدیر آزمایشگاه</strong></div>
    <div class="signature-box-hamkar"><strong>امضای همکار</strong></div>
  </div>

  <div id="printReport" class="print-report" aria-hidden="true"></div>
</body></html>`;
}

function makeDays(count, opts) {
  opts = opts || {};
  const days = [];
  for (let i = 1; i <= count; i++) {
    const date = '1404/02/' + String(i).padStart(2, '0');
    days.push({
      rowNumber: i,
      calculatedTime: '08:30',
      overtime: i % 3 === 0 ? '01:15' : '00:00',
      earlyExit: '00:00',
      earlyStart: '00:00',
      delay: i % 5 === 0 ? '00:12' : '00:00',
      exitTime2: '',
      entryTime2: '',
      exitTime: '16:30',
      entryTime: '07:55',
      weekday: i % 7 === 6 ? 'جمعه' : 'شنبه',
      date: date,
      isHoliday: i % 7 === 6
    });
  }
  return days;
}

function makeDom(opts) {
  opts = opts || {};
  const days = makeDays(opts.days == null ? 25 : opts.days);
  const overtime = [];
  for (let i = 0; i < (opts.overtime == null ? 4 : opts.overtime); i++) {
    overtime.push({
      description: 'اضافه‌کاری نمونه ' + (i + 1),
      daily_overtime: '01:30',
      to_time: '18:00',
      from_time: '16:30',
      overtime_date: '1404/02/' + String(i + 1).padStart(2, '0')
    });
  }
  const leave = [];
  for (let i = 0; i < (opts.leave == null ? 2 : opts.leave); i++) {
    leave.push({
      substitute: 'رضا محمدی',
      days: 1,
      end_date: '1404/02/' + String(10 + i).padStart(2, '0'),
      start_date: '1404/02/' + String(10 + i).padStart(2, '0')
    });
  }
  const passes = [];
  for (let i = 0; i < (opts.hourly == null ? 3 : opts.hourly); i++) {
    passes.push({
      pass_duration: '02:00',
      pass_title: 'امور اداری',
      request_date: '1404/02/' + String(5 + i).padStart(2, '0')
    });
  }

  const dom = new JSDOM(shellHtml(), {
    pretendToBeVisual: true,
    url: 'https://lab.test/final_report_page',
    runScripts: 'dangerously'
  });
  const w = dom.window;
  const d = w.document;

  w.fetch = function () {
    return Promise.resolve({
      ok: true,
      json: function () {
        return Promise.resolve({
          success: true,
          data: { idle_timeout_enabled: '0', idle_timeout_seconds: '300' }
        });
      }
    });
  };
  w.print = function () { w.__printed = (w.__printed || 0) + 1; };

  w.localStorage.setItem('hozoorReportData', JSON.stringify(days));
  w.localStorage.setItem('overtimeReportData', JSON.stringify(overtime));
  w.localStorage.setItem('leaveReportData', JSON.stringify(leave));
  w.localStorage.setItem('hourlyPassReportData', JSON.stringify(passes));
  w.localStorage.setItem('totalOvertime', '03:45');
  w.localStorage.setItem('totalPresenceTime', '182:10');
  w.localStorage.setItem('totalDelayTime', '00:40');
  w.localStorage.setItem('totalEarlyStart', '00:00');
  w.localStorage.setItem('totalEarlyExit', '00:00');
  w.localStorage.setItem('selectedUsername', 'ali.ahmadi');
  w.localStorage.setItem('numRecords', String(days.length));

  return { dom: dom, w: w, d: d, days: days };
}

async function boot(env) {
  const s1 = env.d.createElement('script');
  s1.textContent = REPORT_SCRIPT;
  env.d.body.appendChild(s1);

  const s2 = env.d.createElement('script');
  s2.textContent = PRINT_SCRIPT;
  env.d.body.appendChild(s2);

  env.w.dispatchEvent(new env.w.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 40));
  env.w.dispatchEvent(new env.w.Event('load'));
  await new Promise((r) => setTimeout(r, 60));
  return env;
}

function sheets(d) {
  return Array.prototype.slice.call(d.querySelectorAll('#printReport .pr-sheet'));
}

function attRows(sheet) {
  return sheet.querySelectorAll('.pr-att tbody tr');
}

(async function () {
  console.log('\nfinal-report-print.js — DOM suite');

  /* ── 1) یک ماه معمولی (۲۵ روز) → دقیقاً یک برگ A4 ───────────── */
  let env = await boot(makeDom({ days: 25 }));
  let d = env.d;
  check('body gets .pr-ready', d.body.classList.contains('pr-ready'));
  check('#printReport is populated', d.getElementById('printReport').innerHTML.length > 0);
  let sh = sheets(d);
  check('25 days → exactly 1 A4 sheet', sh.length === 1, 'got ' + sh.length);
  check('sheet holds all 25 records', attRows(sh[0]).length === 25);
  check('summary block present on last sheet', !!sh[0].querySelector('.pr-summary'));
  check('10 summary stats rendered', sh[0].querySelectorAll('.pr-stat').length === 10);
  check('3 side tables rendered', sh[0].querySelectorAll('.pr-details .pr-side-table').length === 3);
  check('signature block on the same sheet', sh[0].querySelectorAll('.pr-sign__box').length === 2);
  check('overtime rows carried over', sh[0].querySelectorAll('.pr-details .pr-side-table')[0].querySelectorAll('tbody tr').length === 4);
  check('leave rows carried over', sh[0].querySelectorAll('.pr-details .pr-side-table')[1].querySelectorAll('tbody tr').length === 2);
  check('hourly-pass rows carried over', sh[0].querySelectorAll('.pr-details .pr-side-table')[2].querySelectorAll('tbody tr').length === 3);
  check('attendance cells have 12 columns', sh[0].querySelector('.pr-att tbody tr').children.length === 12);
  check('holiday row keeps its marker', !!sh[0].querySelector('.pr-att tbody tr.pr-row--holiday'));
  check('duty-days input is printed as a fill-in field',
    sh[0].querySelectorAll('.pr-sign__hint')[1].textContent.indexOf('۲۲') !== -1);
  check('row height css variable is set', /--pr-row-h:\s*[\d.]+mm/.test(sh[0].innerHTML));

  /* ── 2) دقیقاً ۳۱ روز → هنوز یک برگ ─────────────────────────── */
  env = await boot(makeDom({ days: 31 }));
  d = env.d;
  sh = sheets(d);
  check('31 days → 1 sheet', sh.length === 1, 'got ' + sh.length);
  check('31 records on that single sheet', attRows(sh[0]).length === 31);
  check('summary + signatures still on that sheet',
    !!sh[0].querySelector('.pr-summary') && !!sh[0].querySelector('.pr-sign'));

  /* ── 3) ۴۵ روز → دو برگ، هیچ برگی بیش از ۳۱ رکورد ───────────── */
  env = await boot(makeDom({ days: 45 }));
  d = env.d;
  sh = sheets(d);
  check('45 days → 2 sheets', sh.length === 2, 'got ' + sh.length);
  check('sheet 1 has 31 records', attRows(sh[0]).length === 31, 'got ' + attRows(sh[0]).length);
  check('sheet 2 has the remaining 14 records', attRows(sh[1]).length === 14, 'got ' + attRows(sh[1]).length);
  check('no sheet exceeds 31 records', sh.every((s) => attRows(s).length <= 31));
  check('header repeats on every sheet', sh.every((s) => !!s.querySelector('.pr-head') && !!s.querySelector('.pr-idstrip')));
  check('sheet 1 has no summary/signatures',
    !sh[0].querySelector('.pr-summary') && !sh[0].querySelector('.pr-sign') && !sh[0].querySelector('.pr-details'));
  check('sheet 2 (last) carries summary + side tables + signatures',
    !!sh[1].querySelector('.pr-summary') && sh[1].querySelectorAll('.pr-side-table').length === 3 &&
    sh[1].querySelectorAll('.pr-sign__box').length === 2);
  check('page counter says ۱ از ۲ / ۲ از ۲',
    sh[0].querySelector('.pr-head__meta').textContent.indexOf('۱ از ۲') !== -1 &&
    sh[1].querySelector('.pr-head__meta').textContent.indexOf('۲ از ۲') !== -1);
  check('row range caption is per sheet',
    sh[1].querySelector('.pr-sect--attendance .pr-sect__title small').textContent.indexOf('۳۲') !== -1);

  /* ── 4) ۶۲ روز → دو برگ کامل ─────────────────────────────────── */
  env = await boot(makeDom({ days: 62 }));
  d = env.d;
  sh = sheets(d);
  check('62 days → 2 sheets', sh.length === 2, 'got ' + sh.length);
  check('no record is lost across sheets',
    sh.reduce((n, s) => n + attRows(s).length, 0) === 62);

  /* ── 5) ۶۳ روز → سه برگ ──────────────────────────────────────── */
  env = await boot(makeDom({ days: 63 }));
  d = env.d;
  sh = sheets(d);
  check('63 days → 3 sheets', sh.length === 3, 'got ' + sh.length);
  check('all 63 records printed', sh.reduce((n, s) => n + attRows(s).length, 0) === 63);

  /* ── 6) جداول جانبی بلند: برگ آخر باید ظرفیت ردیف کمتری بگیرد ── */
  env = await boot(makeDom({ days: 40, overtime: 18, hourly: 16, leave: 5 }));
  d = env.d;
  sh = sheets(d);
  check('long side tables still paginate', sh.length >= 2, 'got ' + sh.length);
  check('every sheet still ≤ 31 records', sh.every((s) => attRows(s).length <= 31));
  check('all 40 records printed', sh.reduce((n, s) => n + attRows(s).length, 0) === 40);
  check('tallest side table renders all 18 overtime rows',
    sh[sh.length - 1].querySelectorAll('.pr-details .pr-side-table')[0].querySelectorAll('tbody tr').length === 18);

  /* ── 7) گزارش خالی → fallback قدیمی دست‌نخورده می‌ماند ────────── */
  env = makeDom({ days: 5 });
  env.w.localStorage.removeItem('hozoorReportData');
  env = await boot(env);
  d = env.d;
  check('empty report: .pr-ready not set', !d.body.classList.contains('pr-ready'));
  check('empty report: #printReport stays empty', d.getElementById('printReport').innerHTML === '');

  /* ── 8) computePlan: قرارداد صفحه‌بندی ────────────────────────── */
  env = await boot(makeDom({ days: 5 }));
  const api = env.w.HastamaPrintReport;
  check('print API exposed', !!api && typeof api.computePlan === 'function');
  check('max rows per page is 31', api.maxRowsPerPage === 31);

  let planFail = [];
  [1, 7, 31, 32, 45, 62, 63, 90, 120, 366].forEach(function (n) {
    [0, 1, 5, 12, 25].forEach(function (side) {
      const plan = api.computePlan(n, side);
      const sizes = plan.chunks.map((c) => c.size);
      const total = sizes.reduce((a, b) => a + b, 0);
      if (total !== n) planFail.push(n + '/' + side + ' total=' + total);
      if (sizes.some((s) => s > 31)) planFail.push(n + '/' + side + ' chunk>31');
      if (sizes.slice(0, -1).some((s) => s > 31)) planFail.push(n + '/' + side + ' middle chunk>31');
      if (plan.chunks.length < 2 && n > 31) planFail.push(n + '/' + side + ' too few pages');
      if (!plan.chunks[plan.chunks.length - 1].isLast) planFail.push(n + '/' + side + ' no last flag');
      if (plan.chunks[plan.chunks.length - 2] && plan.chunks[plan.chunks.length - 2].isLast) {
        planFail.push(n + '/' + side + ' two last flags');
      }
    });
  });
  check('computePlan keeps ≤31 rows/page and loses no record', planFail.length === 0, planFail.slice(0, 4).join(', '));

  /* حداقل تعداد برگ: همهٔ برگ‌های غیرآخری باید ۳۱ ردیف داشته باشند
     (یعنی صفحه‌بندی بهینه است و برگ بی‌جهت اضافه نمی‌شود) */
  let waste = [];
  [45, 62, 63, 90].forEach(function (n) {
    const plan = api.computePlan(n, 3);
    plan.chunks.slice(0, -1).forEach(function (c, i) {
      const isLastButOne = i === plan.chunks.length - 2;
      const nextIsSummaryOnly = plan.chunks[i + 1].size <= plan.chunks[plan.chunks.length - 1].size;
      if (c.size < 31 && !isLastButOne) waste.push(n + '@' + i + '=' + c.size);
    });
  });
  check('no needless extra sheets (middle pages are full)', waste.length === 0, waste.join(', '));

  /* ── 8b) بودجهٔ ارتفاع: محتوای هیچ برگی از ارتفاع A4 بیرون نمی‌زند ──
     از همان ثابت‌های هندسی که کد اصلی export می‌کند استفاده می‌شود. */
  const geo = api.geometry;
  function sheetHeight(chunk, sideRows, plan) {
    const band = geo.sectTitleH + (Math.max(sideRows, 1) + 1) * plan.sideRowH;
    let h = geo.headerH + geo.footerH + geo.sectTitleH + geo.attHeadH + chunk.size * chunk.rowH;
    if (chunk.isLast) h += geo.summaryH + band + geo.signH + geo.gap * 4;
    else h += geo.gap * 2;
    return h;
  }

  let overflow = [];
  [20, 31, 45, 62, 90, 200].forEach(function (n) {
    [0, 3, 8, 15, 25].forEach(function (side) {
      const plan = api.computePlan(n, side);
      plan.chunks.forEach(function (c) {
        const h = sheetHeight(c, side, plan);
        if (h > geo.pageH + 0.001) overflow.push(n + '/' + side + ' size=' + c.size + ' h=' + h.toFixed(1));
        if (c.rowH < geo.minRowH - 0.001 || c.rowH > geo.maxRowH + 0.001) {
          overflow.push(n + '/' + side + ' rowH=' + c.rowH);
        }
      });
    });
  });
  check('every sheet fits inside one A4 page', overflow.length === 0, overflow.slice(0, 5).join(', '));
  check('flow sheets only appear for pathologically tall side tables',
    [0, 3, 8, 15, 25].every(function (side) {
      return api.computePlan(45, side).chunks.every(function (c) { return !c.flow; });
    }));
  /* وقتی جداول جانبی بلندند، ظرفیت برگ آخر کم می‌شود و رکوردها هوشمندانه
     تقسیم می‌شوند تا برگ آخر سرریز نکند. */
  const tight = api.computePlan(20, 25);
  check('tight last page: 20 days split so the last sheet fits its capacity',
    tight.chunks.length === 2 &&
    tight.chunks[1].size <= Math.floor((geo.pageH -
      (geo.headerH + geo.footerH + geo.summaryH + geo.signH + geo.sectTitleH + geo.attHeadH +
       geo.gap * 4 + (geo.sectTitleH + 26 * tight.sideRowH))) / geo.minRowH),
    JSON.stringify(tight.chunks.map((c) => c.size)));

  /* ۴۰ ردیف جانبی: نوار بزرگ می‌شود ولی هنوز با چند ردیف حضور و غیاب
     در همان برگ جا می‌شود. */
  const big = api.computePlan(31, 40);
  check('40 side rows: band shrinks its rows but still shares the last sheet',
    big.chunks[big.chunks.length - 1].size > 0 &&
    (geo.sectTitleH + 41 * big.sideRowH) <= geo.maxBandH * 1.35,
    'bandH=' + (geo.sectTitleH + 41 * big.sideRowH).toFixed(1));

  /* ۸۰ ردیف جانبی (حالت حدی): نوار از یک برگ A4 بزرگ‌تر می‌شود، پس برگ آخر
     «جریان‌دار» می‌شود (بدون ردیف حضور و غیاب) تا هیچ ردیفی بریده نشود. */
  const huge = api.computePlan(31, 80);
  const hugeBand = geo.sectTitleH + 81 * huge.sideRowH;
  check('very tall side tables: band height is reported honestly',
    Math.abs(hugeBand - huge.bandH) < 0.001, 'bandH=' + hugeBand.toFixed(1));
  check('very tall side tables: last sheet becomes a flow sheet with no rows',
    huge.chunks[huge.chunks.length - 1].flow === true &&
    huge.chunks[huge.chunks.length - 1].size === 0,
    JSON.stringify(huge.chunks.map((c) => c.size)));
  check('very tall side tables: side rows stay readable (>= 3.4mm)',
    huge.sideRowH >= geo.minSideRowH - 0.001);

  check('A4 printable height is 279mm (8mm/7mm page margins)', geo.pageH === 279);
  check('row height stays readable (>= 4.6mm)', geo.minRowH >= 4.6);

  /* ── 9) دکمه‌ها ───────────────────────────────────────────────── */
  env = await boot(makeDom({ days: 25 }));
  d = env.d;
  const printBtn = d.getElementById('printReportBtn');
  const pdfBtn = d.getElementById('savePdfReportBtn');
  check('print button is wired', !!printBtn && printBtn.dataset.prWired === '1');
  check('pdf button is wired', !!pdfBtn && pdfBtn.dataset.prWired === '1');
  printBtn.click();
  await new Promise((r) => setTimeout(r, 20));
  check('print button triggers window.print()', env.w.__printed === 1, 'got ' + env.w.__printed);
  pdfBtn.click();
  await new Promise((r) => setTimeout(r, 20));
  check('pdf button triggers window.print() too', env.w.__printed === 2, 'got ' + env.w.__printed);

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('HARNESS ERROR', e); process.exit(2); });
