/* سوئیت DOM برای app/static/js/theme.js
 *
 * رفتار موردانتظار لایهٔ مشترک تم:
 *   • هر دو کلاس dark-mode و dark-theme هم‌زمان روی <html> و <body> ست شوند
 *     (تا استایل‌های قدیمی هر دو صفحه بدون بازنویسی کار کنند).
 *   • انتخاب کاربر در localStorage بماند و در بارگذاری بعدی بازگردد.
 *   • پیش‌فرض بدون انتخاب کاربر همیشه «روشن» باشد (تیره فقط با انتخاب صریح).
 *   • کلیک روی هر عنصر data-action="toggle-theme" تم را عوض کند.
 *   • در صفحاتی بدون کلید تم، دکمهٔ شناور ساخته شود.
 *
 * اجرا: node tests/js/theme.dom.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const ROOT = path.resolve(__dirname, '..', '..');
const THEME_JS = fs.readFileSync(path.join(ROOT, 'app', 'static', 'js', 'theme.js'), 'utf8');

let passed = 0;
const failures = [];
const queue = [];

/* تست‌ها async اجرا می‌شوند چون در jsdom رویداد DOMContentLoaded
   پس از ساخت سند و به‌صورت ناهمگام منتشر می‌شود (مثل مرورگر واقعی که
   theme.js را در <head> اجرا می‌کند و سپس boot را با DOMContentLoaded می‌بندد). */
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

function ready(window) {
  if (window.document.readyState !== 'loading') return Promise.resolve();
  return new Promise((resolve) => {
    window.document.addEventListener('DOMContentLoaded', () => resolve());
    setTimeout(resolve, 300);
  });
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg || 'assertion failed');
}

function makeDom(bodyHtml, opts) {
  const options = opts || {};
  const dom = new JSDOM(
    `<!doctype html><html lang="fa"><head></head><body>${bodyHtml || ''}</body></html>`,
    { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/' }
  );
  const { window } = dom;

  // localStorage ساده و قابل کنترل
  const store = Object.assign({}, options.storage || {});
  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k) => (Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v); },
      removeItem: (k) => { delete store[k]; },
      _store: store
    }
  });

  const listeners = [];
  window.matchMedia = (q) => ({
    media: q,
    matches: /dark/.test(q) ? !!options.systemDark : false,
    addEventListener: (_e, cb) => listeners.push(cb),
    removeEventListener: () => {},
    addListener: (cb) => listeners.push(cb),
    removeListener: () => {}
  });

  window.eval(THEME_JS);
  return { dom, window, store, listeners, ready: () => ready(window) };
}

const isDark = (w) =>
  w.document.body.classList.contains('dark-mode') &&
  w.document.body.classList.contains('dark-theme') &&
  w.document.documentElement.classList.contains('dark-mode') &&
  w.document.documentElement.classList.contains('dark-theme');

const isLight = (w) =>
  !w.document.body.classList.contains('dark-mode') &&
  !w.document.body.classList.contains('dark-theme') &&
  !w.document.documentElement.classList.contains('dark-mode') &&
  !w.document.documentElement.classList.contains('dark-theme');

check('API عمومی در دسترس است', () => {
  const { window } = makeDom('');
  assert(window.HastamaTheme, 'HastamaTheme تعریف نشده');
  ['get', 'set', 'toggle', 'isDark'].forEach((k) =>
    assert(typeof window.HastamaTheme[k] === 'function', `متد ${k} نیست`)
  );
  assert(typeof window.toggleTheme === 'function', 'toggleTheme سراسری نیست');
});

check('پیش‌فرض بدون انتخاب کاربر = روشن حتی اگر سیستم تیره باشد', () => {
  const { window } = makeDom('', { systemDark: true });
  assert(isLight(window), 'نباید تیره باشد');
  assert(window.HastamaTheme.get() === 'light');
  assert(window.document.documentElement.getAttribute('data-theme') === 'light');
});

check('انتخاب ذخیره‌شدهٔ کاربر بر پیش‌فرض روشن اولویت دارد', () => {
  const { window } = makeDom('', { systemDark: false, storage: { 'hastama-theme': 'dark' } });
  assert(isDark(window), 'انتخاب کاربر (تیره) باید غالب باشد');
});

check('تم ذخیره‌شدهٔ تیره در بارگذاری بازمی‌گردد', () => {
  const { window } = makeDom('', { storage: { 'hastama-theme': 'dark' } });
  assert(isDark(window), 'باید تیره بازگردد');
});

check('set/toggle هر دو کلاس را روی html و body می‌گذارد و ذخیره می‌کند', () => {
  const { window, store } = makeDom('');
  window.HastamaTheme.set('dark');
  assert(isDark(window), 'کلاس‌ها کامل ست نشد');
  assert(store['hastama-theme'] === 'dark', 'در localStorage ذخیره نشد');

  window.HastamaTheme.toggle();
  assert(isLight(window), 'toggle به روشن برنگشت');
  assert(store['hastama-theme'] === 'light', 'مقدار روشن ذخیره نشد');
});

check('colorScheme و data-theme همگام می‌شوند', () => {
  const { window } = makeDom('');
  window.HastamaTheme.set('dark');
  assert(window.document.documentElement.getAttribute('data-theme') === 'dark');
  assert(window.document.documentElement.style.colorScheme === 'dark');
  window.HastamaTheme.set('light');
  assert(window.document.documentElement.getAttribute('data-theme') === 'light');
  assert(window.document.documentElement.style.colorScheme === 'light');
});

check('کلیک روی data-action="toggle-theme" تم را عوض می‌کند', async () => {
  const { window, ready } = makeDom('<button id="t" data-action="toggle-theme">tm</button>');
  await ready();
  window.document.getElementById('t').click();
  assert(isDark(window), 'کلیک اول باید تیره کند');
  window.document.getElementById('t').click();
  assert(isLight(window), 'کلیک دوم باید روشن کند');
});

check('کلیک روی فرزند دکمه هم کار می‌کند (event delegation)', async () => {
  const { window, ready } = makeDom('<button data-action="toggle-theme"><span id="ic">x</span></button>');
  await ready();
  window.document.getElementById('ic').click();
  assert(isDark(window), 'کلیک روی آیکون داخل دکمه باید کار کند');
});

check('آیدی‌های قدیمی صفحات (themeToggleBtn / themeToggleButton) پشتیبانی می‌شوند', async () => {
  const a = makeDom('<div id="themeToggleBtn">م</div>');
  await a.ready();
  a.window.document.getElementById('themeToggleBtn').click();
  assert(isDark(a.window), 'themeToggleBtn کار نکرد');

  const b = makeDom('<div id="themeToggleButton">م</div>');
  await b.ready();
  b.window.document.getElementById('themeToggleButton').click();
  assert(isDark(b.window), 'themeToggleButton کار نکرد');
});

check('در صفحات بدون کلید تم، دکمهٔ شناور ساخته می‌شود', async () => {
  const { window, ready } = makeDom('<div class="report-container">گزارش</div>');
  await ready();
  const btn = window.document.querySelector('.theme-toggle-floating');
  assert(btn, 'دکمهٔ شناور ساخته نشد');
  btn.click();
  assert(isDark(window), 'دکمهٔ شناور تم را عوض نکرد');
});

check('اگر صفحه کلید تم دارد، دکمهٔ شناور اضافه نمی‌شود', async () => {
  const { window, ready } = makeDom('<div id="themeToggleBtn">م</div>');
  await ready();
  assert(!window.document.querySelector('.theme-toggle-floating'), 'نباید دکمهٔ تکراری بسازد');
});

check('وضعیت دسترس‌پذیری دکمه‌ها همگام می‌شود', async () => {
  const { window, ready } = makeDom('<button id="t" data-action="toggle-theme">م</button>');
  await ready();
  const btn = window.document.getElementById('t');
  assert(btn.getAttribute('aria-pressed') === 'false', 'aria-pressed اولیه غلط است');
  btn.click();
  assert(btn.getAttribute('aria-pressed') === 'true', 'aria-pressed بعد از کلیک غلط است');
  assert(btn.classList.contains('is-dark'), 'کلاس is-dark ست نشد');
  assert(/روشن/.test(btn.getAttribute('aria-label') || ''), 'برچسب به‌روز نشد');
});

check('رویداد hastama:themechange منتشر می‌شود', () => {
  const { window } = makeDom('');
  let got = null;
  window.addEventListener('hastama:themechange', (e) => { got = e.detail && e.detail.theme; });
  window.HastamaTheme.set('dark');
  assert(got === 'dark', 'رویداد با مقدار درست منتشر نشد');
});

check('تغییر تم سیستم اعمال نمی‌شود مگر انتخاب صریح کاربر', () => {
  const free = makeDom('', { systemDark: false });
  assert(isLight(free.window));
  free.window.matchMedia = () => ({ matches: true });
  free.listeners.forEach((cb) => cb({ matches: true }));
  assert(isLight(free.window), 'بدون انتخاب کاربر نباید تیره شود');

  const dark = makeDom('', { storage: { 'hastama-theme': 'dark' } });
  dark.window.matchMedia = () => ({ matches: false });
  dark.listeners.forEach((cb) => cb({ matches: false }));
  assert(isDark(dark.window), 'انتخاب صریح تیره نباید بازنویسی شود');
});

check('نبود localStorage باعث خطا نمی‌شود', () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', {
    runScripts: 'outside-only', url: 'https://example.test/'
  });
  const { window } = dom;
  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    get() { throw new Error('blocked'); }
  });
  window.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} });
  window.eval(THEME_JS);           // نباید پرتاب کند
  window.HastamaTheme.set('dark'); // نباید پرتاب کند
  assert(isDark(window), 'با وجود مسدود بودن localStorage باید تم اعمال شود');
});

(async () => {
  for (const t of queue) await t();

  if (failures.length) {
    console.error(`\n✗ ${failures.length} تست ناموفق:`);
    failures.forEach((f) => console.error('  - ' + f));
    process.exit(1);
  }
  console.log(`✓ همهٔ ${passed} تست تم با موفقیت اجرا شد`);
})();
