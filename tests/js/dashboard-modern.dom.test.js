/* سوئیت DOM برای app/static/js/dashboard-modern.js — «بوم داده»ی داشبورد
 *
 * رفتار موردانتظار لایهٔ حرکتی جدید:
 *   • شمارش اعداد با حفظ ارقام فارسی، جداکننده‌ها و ترتیب کاراکترها
 *   • رشد ستون‌ها: data-percent → متغیر ثبت‌شدهٔ --hx-h روی همان ستون
 *   • حلقه‌ها: data-hx-ring → --hx-len (محیط دایره) و --hx-p (۰ تا ۱)
 *   • نوار سهم: سهم «زمانی» درست حساب شود (۱۵:۰۵ از ۳۰:۱۰ = ۵۰٪)
 *   • نوار توزیع پرسنل: عرض هر بخش از data-hx-part نسبت به data-hx-total
 *   • فارسی‌سازی نشانگرهای رتبه، ساعت زندهٔ سربرگ و کلاس ورود is-in
 *   • هیچ رنگی با style اینلاین ست نمی‌شود و اجرای دوباره مقدارها را خراب نمی‌کند
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
    <header class="hx-head hx-reveal" data-hx-reveal style="--hx-i:0">
      <span class="hx-chip hx-chip--ghost hx-clock" id="hxLiveClock">—</span>
      <span class="hx-kicker">پنل مدیریت هستما</span>
    </header>

    <section class="hx-hero hx-reveal dashboard-quick-card" data-hx-reveal data-hx-spot style="--hx-i:1">
      <span class="hx-hero__num hx-num" data-hx-count>۲۳۱</span>
      <span class="hx-hero__meta"><b data-hx-count>۱۴۰۵/۱۲/۲۹</b></span>
      <div class="hx-ringwrap">
        <svg class="hx-ring" viewBox="0 0 168 168">
          <circle class="hx-ring__value" cx="84" cy="84" r="70" data-hx-ring="۶۳"></circle>
        </svg>
        <span class="hx-ringwrap__center"><strong><span data-hx-count>۶۳</span><small>٪</small></strong></span>
      </div>
    </section>

    <section class="hx-podium">
      <article class="hx-pod hx-reveal dashboard-card dashboard-card--highlight" data-hx-reveal data-hx-spot style="--hx-i:2">
        <strong class="hx-pod__value hx-num" data-hx-count>۱۵:۰۵</strong>
        <div class="hx-meter" data-hx-meter data-hx-part="۱۵:۰۵" data-hx-whole="۳۰:۱۰">
          <span class="hx-meter__track"><i class="hx-meter__fill"></i></span>
        </div>
      </article>
    </section>

    <section class="hx-metrics">
      <article class="hx-metric hx-reveal dashboard-card" data-hx-reveal style="--hx-i:3">
        <span class="hx-metric__value hx-num" data-hx-count>۱۶</span>
      </article>
    </section>

    <section class="hx-figures">
      <article class="hx-figure hx-reveal dashboard-card" data-hx-reveal style="--hx-i:4">
        <div class="hx-gauge">
          <svg viewBox="0 0 132 132">
            <circle class="hx-ring__value" cx="66" cy="66" r="56" data-hx-ring="۴۴"></circle>
          </svg>
          <span class="hx-gauge__center"><strong><span data-hx-count>۴۴</span><small>٪</small></strong></span>
        </div>
        <div class="hx-split" data-hx-split data-hx-total="۱۶">
          <span class="hx-split__seg" data-hx-part="۱"></span>
          <span class="hx-split__seg" data-hx-part="۱۵"></span>
        </div>
        <ul class="hx-legend">
          <li><b class="hx-num" data-hx-count>۱</b></li>
          <li><b class="hx-num" data-hx-count>۱۵</b></li>
        </ul>
      </article>
    </section>

    <section class="hx-charts">
      <article class="hx-chartblock hx-reveal dashboard-chart-card" data-hx-reveal style="--hx-i:5">
        <div class="hx-bars__plot">
          <div class="hx-bars__col" data-hx-bar data-percent="100"><span class="hx-bars__value">۱۵:۰۵</span></div>
          <div class="hx-bars__col" data-hx-bar data-percent="42"><span class="hx-bars__value">۰۵:۱۰</span></div>
          <div class="hx-bars__col" data-hx-bar data-percent="0"><span class="hx-bars__value">۰۰:۰۰</span></div>
        </div>
      </article>
    </section>

    <section class="hx-boards">
      <article class="hx-board hx-reveal dashboard-table-card" data-hx-reveal style="--hx-i:6">
        <table class="dashboard-table">
          <tbody>
            <tr><td><span class="hx-rank" data-hx-fa>1</span></td></tr>
            <tr><td><span class="hx-rank" data-hx-fa>۲</span></td></tr>
            <tr><td><span class="hx-rank" data-hx-fa> 03 </span></td></tr>
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
  // jsdom چیدمان واقعی ندارد؛ برای تست، لایهٔ حرکتی را مستقیماً اجرا می‌کنیم.
  window.eval(DASH_JS);
  return window;
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/* ماژول اجرای اولیه را به DOMContentLoaded می‌سپارد؛ در jsdom این رویداد
   ناهمگام است، پس مثل مرورگر واقعی منتظر می‌مانیم. */
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
  ['refresh', 'runNow', 'toFaDigits', 'toLatinDigits', 'parseAmount'].forEach((fn) => {
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

check('اجرای فوری، کلاس ورود is-in را به بلوک‌ها می‌دهد', async () => {
  const window = makeDom(BODY);
  assert(window.HastamaDashboard.runNow() === true, 'runNow مقدار درست برنگرداند');
  await wait(60);
  const reveals = window.document.querySelectorAll('.hx-reveal');
  assert(reveals.length >= 7, `تعداد بلوک‌ها کم است: ${reveals.length}`);
  Array.prototype.forEach.call(reveals, (el, i) => {
    assert(el.classList.contains('is-in'), `بلوک ${i} کلاس is-in نگرفت`);
  });
});

check('ارتفاع ستون‌ها از data-percent روی --hx-h ست می‌شود', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(700);
  const cols = window.document.querySelectorAll('[data-hx-bar]');
  const values = Array.prototype.map.call(cols, (c) => c.style.getPropertyValue('--hx-h'));
  assert(values[0] === '100%', `ستون اول: ${values[0]}`);
  assert(values[1] === '42%', `ستون دوم: ${values[1]}`);
  assert(values[2] === '0%', `ستون صفر باید صفر بماند: ${values[2]}`);
  Array.prototype.forEach.call(cols, (c, i) => {
    assert(c.classList.contains('is-in'), `ستون ${i} کلاس is-in نگرفت`);
  });
});

check('حلقه‌ها با data-hx-ring پر می‌شوند (--hx-len و --hx-p)', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(300);
  const rings = window.document.querySelectorAll('[data-hx-ring]');
  assert(rings.length === 2, `تعداد حلقه‌ها: ${rings.length}`);
  Array.prototype.forEach.call(rings, (ring) => {
    const percent = Number(window.HastamaDashboard.toLatinDigits(ring.getAttribute('data-hx-ring')));
    const r = Number(ring.getAttribute('r'));
    const length = Number(ring.style.getPropertyValue('--hx-len'));
    const p = Number(ring.style.getPropertyValue('--hx-p'));
    assert(Math.abs(length - 2 * Math.PI * r) < 1, `محیط حلقه: ${length} ≠ ${2 * Math.PI * r}`);
    assert(Math.abs(p - percent / 100) < 0.001, `نسبت حلقه: ${p} ≠ ${percent / 100}`);
  });
});

check('نوار سهم بر اساس سهم زمانی پر می‌شود (نه رشتهٔ متنی)', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(300);
  const meter = window.document.querySelector('[data-hx-meter]');
  assert(meter.style.getPropertyValue('--hx-w') === '50%', `عرض نوار سهم: ${meter.style.getPropertyValue('--hx-w')}`);
});

check('نوار توزیع پرسنل عرض هر بخش را می‌گیرد', async () => {
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

check('شمارش اعداد با ارقام فارسی، جداکننده‌ها و طول رشته تمام می‌شود', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(2200);
  const numbers = window.document.querySelectorAll('[data-hx-count]');
  const texts = Array.prototype.map.call(numbers, (n) => n.textContent.trim());
  ['۲۳۱', '۱۴۰۵/۱۲/۲۹', '۶۳', '۱۵:۰۵', '۱۶', '۴۴', '۱', '۱۵'].forEach((expected, index) => {
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

check('اجرای دوباره مقدارها را خراب نمی‌کند (idempotent)', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(700);
  const bar = window.document.querySelector('[data-hx-bar]');
  const first = bar.style.getPropertyValue('--hx-h');
  window.HastamaDashboard.runNow();
  await wait(120);
  assert(bar.style.getPropertyValue('--hx-h') === first, `مقدار ستون تغییر کرد: ${bar.style.getPropertyValue('--hx-h')}`);
});

check('هیچ رنگی با style اینلاین ست نمی‌شود', async () => {
  const window = makeDom(BODY);
  window.HastamaDashboard.runNow();
  await wait(300);
  const nodes = window.document.querySelectorAll('#dashboardBox *');
  Array.prototype.forEach.call(nodes, (node) => {
    const css = node.style.cssText || '';
    assert(!/color\s*:/i.test(css), `رنگ اینلاین روی ${node.className}: ${css}`);
    assert(!/background/i.test(css), `پس‌زمینهٔ اینلاین روی ${node.className}: ${css}`);
  });
});

check('بدون IntersectionObserver هم خطا نمی‌دهد و محتوا گم نمی‌شود', async () => {
  const window = makeDom(BODY);
  window.deleteProp && window.deleteProp('IntersectionObserver');
  window.IntersectionObserver = undefined;
  await ready(window);
  await wait(60);
  assert(window.HastamaDashboard, 'ماژول بعد از نبودن IntersectionObserver از کار افتاد');
  const api = window.HastamaDashboard;
  assert(api.runNow() === true, 'runNow در نبود IntersectionObserver کار نکرد');
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
