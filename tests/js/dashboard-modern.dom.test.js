/* سوئیت DOM برای app/static/js/dashboard-modern.js
 *
 * رفتار موردانتظار لایهٔ حرکتی داشبورد:
 *   • شمارش اعداد با حفظ ارقام فارسی و ترتیب کاراکترها (۹۹٪ → ۱۰۰٪)
 *   • ارتفاع میله‌های نمودار از data-percent و با متغیر --hx-h
 *   • پر شدن حلقه‌های پیشرفت از data-hx-ring (stroke-dashoffset)
 *   • عرض نوارهای سهم و نوار توزیع کارکنان
 *   • تبدیل ارقام رتبه‌ها به فارسی (data-hx-fa)
 *   • افزودن کلاس is-in برای انیمیشن ورود
 *   • سهم زمانی («۱۵:۰۵») دقیق محاسبه شود، نه بر اساس رشته
 *
 * اجرا: node tests/js/dashboard-modern.dom.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const ROOT = path.resolve(__dirname, '..', '..');
const DASH_JS = fs.readFileSync(path.join(ROOT, 'app', 'static', 'js', 'dashboard-modern.js'), 'utf8');

let passed = 0;
const failures = [];
const queue = [];

function check(name, fn) {
  queue.push(async () => {
    try {
      await fn();
      passed += 1;
    } catch (err) {
      failures.push(`${name}: ${err && err.message ? err.message : err}`);
    }
  });
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg || 'assertion failed');
}

const BODY = `
<div class="management-box hx-dashboard" id="dashboardBox">
  <div class="hx-dash">
    <header class="hx-head hx-reveal" style="--hx-i:0">
      <div class="hx-head__side"><span class="hx-chip hx-chip--soft" id="hxLiveClock">—</span></div>
    </header>
    <section class="hx-hero hx-reveal" style="--hx-i:1">
      <h3 class="hx-hero__title"><span class="hx-num" data-hx-count>۲۳۱</span><span class="hx-hero__unit">روز باقی‌مانده</span></h3>
      <div class="hx-hero__ring">
        <svg class="hx-ring" viewBox="0 0 148 148">
          <circle class="hx-ring__value" cx="74" cy="74" r="60" data-hx-ring="63"></circle>
        </svg>
        <div class="hx-ring__center"><strong><span data-hx-count>۶۳</span><small>٪</small></strong></div>
      </div>
    </section>
    <section class="hx-spot-row">
      <article class="hx-spot hx-reveal" style="--hx-i:2" data-accent="overtime">
        <div class="hx-spot__share" data-hx-share data-hx-part="۱۵:۰۵" data-hx-whole="۳۰:۱۰">
          <span class="hx-spot__share-bar"><i></i></span>
        </div>
      </article>
    </section>
    <section class="hx-kpis">
      <div class="hx-kpi-grid">
        <article class="hx-kpi hx-reveal" style="--hx-i:6" data-accent="blue">
          <span class="hx-kpi__text">
            <span class="hx-kpi__value" data-hx-count>۱۶</span>
          </span>
        </article>
        <article class="hx-kpi hx-reveal" style="--hx-i:7" data-accent="violet">
          <span class="hx-kpi__text"><span class="hx-kpi__value" data-hx-count>۱۵:۰۵</span></span>
        </article>
      </div>
    </section>
    <section class="hx-insights">
      <article class="hx-card hx-reveal" style="--hx-i:14">
        <div class="hx-gauge">
          <svg viewBox="0 0 118 118">
            <circle class="hx-ring__value" cx="59" cy="59" r="48" data-hx-ring="۴۴"></circle>
          </svg>
          <div class="hx-ring__center"><strong><span data-hx-count>۴۴</span><small>٪</small></strong></div>
        </div>
      </article>
      <article class="hx-card hx-reveal" style="--hx-i:16">
        <div class="hx-split" data-hx-split data-hx-total="۱۶">
          <span class="hx-split__seg" data-hx-part="۱" style="width: 0%"><i></i></span>
          <span class="hx-split__seg" data-hx-part="۱۵" style="width: 0%"><i></i></span>
        </div>
      </article>
    </section>
    <section class="hx-charts">
      <article class="hx-card hx-chart-card hx-reveal" style="--hx-i:17">
        <div class="hx-chart">
          <div class="hx-col" style="--hx-i:0">
            <span class="hx-col__track"><span class="hx-col__fill" data-accent="blue" data-percent="100"></span></span>
          </div>
          <div class="hx-col" style="--hx-i:1">
            <span class="hx-col__track"><span class="hx-col__fill" data-accent="blue" data-percent="42"></span></span>
          </div>
          <div class="hx-col" style="--hx-i:2">
            <span class="hx-col__track"><span class="hx-col__fill" data-accent="blue" data-percent="0"></span></span>
          </div>
        </div>
      </article>
    </section>
    <section class="hx-tables">
      <article class="hx-card hx-table-card hx-reveal" style="--hx-i:19">
        <table class="dashboard-table">
          <tbody>
            <tr><td><span class="hx-rank" data-hx-fa>1</span></td></tr>
            <tr><td><span class="hx-rank" data-hx-fa>2</span></td></tr>
            <tr><td><span class="hx-rank" data-hx-fa>۳</span></td></tr>
          </tbody>
        </table>
      </article>
    </section>
  </div>
</div>
`;

function makeDom(bodyHtml) {
  const dom = new JSDOM(
    `<!doctype html><html lang="fa"><head></head><body>${bodyHtml}</body></html>`,
    { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/admin/dashboard' }
  );
  const { window } = dom;
  // jsdom چیدمان واقعی ندارد؛ فقط برای تست، لایهٔ حرکتی را مستقیماً اجرا می‌کنیم.
  window.eval(DASH_JS);
  return window;
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/* ماژول در <head> بارگذاری می‌شود و اجرا را به DOMContentLoaded می‌سپارد؛
   در jsdom این رویداد ناهمگام است، پس مثل مرورگر واقعی منتظر می‌مانیم. */
function ready(window) {
  if (window.document.readyState !== 'loading') return Promise.resolve();
  return new Promise((resolve) => {
    window.document.addEventListener('DOMContentLoaded', () => resolve());
    setTimeout(resolve, 400);
  });
}

/* ── تست‌ها ─────────────────────────────────────────────────────────── */

check('ماژول روی window.HastamaDashboard منتشر می‌شود', () => {
  const window = makeDom(BODY);
  assert(window.HastamaDashboard, 'HastamaDashboard تعریف نشده است');
  ['refresh', 'runNow', 'toFaDigits', 'toLatinDigits'].forEach((fn) => {
    assert(typeof window.HastamaDashboard[fn] === 'function', `${fn} موجود نیست`);
  });
});

check('اعداد فارسی درست به لاتین و برعکس تبدیل می‌شوند', () => {
  const window = makeDom(BODY);
  const api = window.HastamaDashboard;
  assert(api.toLatinDigits('۱۵:۰۵') === '15:05', 'تبدیل به لاتین ناموفق');
  assert(api.toFaDigits('15:05') === '۱۵:۰۵', 'تبدیل به فارسی ناموفق');
  assert(api.parseAmount('۱۵:۰۵') === 15 * 3600 + 5 * 60, 'سهم زمانی درست محاسبه نشد');
  assert(api.parseAmount('۱۶') === 16, 'عدد ساده درست خوانده نشد');
});

check('ارتفاع میله‌ها از data-percent ست می‌شود', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(900);
  const fills = window.document.querySelectorAll('.hx-col__fill');
  assert(fills[0].style.getPropertyValue('--hx-h') === '100%', `میلهٔ اول: ${fills[0].style.getPropertyValue('--hx-h')}`);
  assert(fills[1].style.getPropertyValue('--hx-h') === '42%', `میلهٔ دوم: ${fills[1].style.getPropertyValue('--hx-h')}`);
  assert(fills[2].style.getPropertyValue('--hx-h') === '0%', `میلهٔ صفر باید صفر بماند: ${fills[2].style.getPropertyValue('--hx-h')}`);
});

check('حلقه‌های پیشرفت با data-hx-ring پر می‌شوند', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(900);
  const rings = window.document.querySelectorAll('[data-hx-ring]');
  rings.forEach((ring) => {
    const percent = Number(window.HastamaDashboard.toLatinDigits(ring.getAttribute('data-hx-ring')));
    const r = Number(ring.getAttribute('r'));
    const length = 2 * Math.PI * r;
    const offset = Number(ring.style.strokeDashoffset);
    const expected = length * (1 - percent / 100);
    assert(Math.abs(offset - expected) < 1.5, `offset حلقه: ${offset} ≠ ${expected}`);
    assert(ring.style.strokeDasharray.length > 0, 'stroke-dasharray ست نشده است');
  });
});

check('نوار سهم بر اساس سهم زمانی پر می‌شود (نه رشتهٔ متنی)', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(300);
  const bar = window.document.querySelector('.hx-spot__share-bar i');
  assert(bar.style.getPropertyValue('--hx-w') === '50%', `عرض نوار سهم: ${bar.style.getPropertyValue('--hx-w')}`);
});

check('نوار توزیع کارکنان عرض هر بخش را می‌گیرد', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(300);
  const segs = window.document.querySelectorAll('[data-hx-split] [data-hx-part]');
  const widths = Array.prototype.map.call(segs, (s) => s.style.width);
  assert(widths[0] === '6%', `بخش اول: ${widths[0]}`);   // ۱ از ۱۶ → کف ۶٪ برای دیده‌شدن
  assert(widths[1] === '94%', `بخش دوم: ${widths[1]}`);
});

check('نشانگرهای رتبه به فارسی نمایش داده می‌شوند', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  const ranks = window.document.querySelectorAll('[data-hx-fa]');
  assert(ranks[0].textContent.trim() === '۱', `رتبهٔ اول: ${ranks[0].textContent}`);
  assert(ranks[1].textContent.trim() === '۲', `رتبهٔ دوم: ${ranks[1].textContent}`);
  assert(ranks[2].textContent.trim() === '۳', `رتبهٔ سوم: ${ranks[2].textContent}`);
});

check('کلاس is-in برای انیمیشن ورود اضافه می‌شود', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(120);
  const box = window.document.getElementById('dashboardBox');
  assert(box.classList.contains('is-in'), 'کلاس is-in اضافه نشده است');
});

check('شمارش اعداد با حفظ ارقام فارسی و طول رشته تمام می‌شود', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(2200);
  const numbers = window.document.querySelectorAll('[data-hx-count]');
  const texts = Array.prototype.map.call(numbers, (n) => n.textContent.trim());
  ['۲۳۱', '۶۳', '۱۶', '۱۵:۰۵', '۴۴'].forEach((expected, index) => {
    assert(texts[index] === expected, `عدد ${index}: «${texts[index]}» ≠ «${expected}»`);
  });
});

check('ساعت سربرگ داشبورد پر می‌شود', async () => {
  const window = makeDom(BODY);
  await ready(window);
  await wait(60);
  const clock = window.document.getElementById('hxLiveClock');
  const text = clock.textContent.trim();
  assert(text !== '—' && text.length > 3, `ساعت سربرگ تنظیم نشد: «${text}»`);
  assert(/[۰-۹0-9]/.test(text), 'ساعت سربرگ عدد ندارد');
});

/* ── اجرا ──────────────────────────────────────────────────────────── */

(async () => {
  for (const task of queue) {
    await task();
  }

  if (failures.length) {
    console.log('dashboard-modern.js — DOM suite');
    failures.forEach((f) => console.log('  ✗ FAIL: ' + f));
    process.exit(1);
  }

  console.log(`dashboard-modern.js — DOM suite\n  ✓ ${passed} checks passed`);
  process.exit(0);
})();
