/* ============================================================================
 * responsive-tables.js — لایهٔ ارائهٔ ریسپانسیو جداول سامانهٔ هستما
 * ----------------------------------------------------------------------------
 * اصل معماری:
 *
 *     Backend/API  →  دادهٔ مشترک  →  منطق مشترک (فیلتر/مرتب‌سازی/اعتبارسنجی)
 *                            ↓
 *                   لایهٔ ارائه (این فایل)
 *                   ├── دسکتاپ → همان جدول معنایی <table>
 *                   └── موبایل → کارت / فهرست فشرده / جدول اسکرول‌شونده
 *
 * این فایل «فقط لایهٔ ارائه» است:
 *   • هیچ درخواست API جدیدی ارسال نمی‌کند؛
 *   • هیچ منطق دومی (فیلتر/مرتب‌سازی/صفحه‌بندی/دسترسی) ندارد؛
 *   • منبع دادهٔ نمای موبایل، «همان DOM جدول دسکتاپ» است که توسط همان
 *     قالب‌های سرور و همان توابع قبلی پر می‌شود؛
 *   • با MutationObserver هر تغییری در جدول (بارگذاری، حذف ردیف، تغییر
 *     وضعیت و...) بلافاصله در نمای موبایل هم اعمال می‌شود؛
 *   • عناصر تعاملی (دکمه‌ها، منوی وضعیت، فرم حذف و...) «منتقل» می‌شوند نه
 *     کپی؛ بنابراین همهٔ رویدادها، دسترسی‌ها و رفتارها عیناً حفظ می‌شود؛
 *   • با خروج از حالت موبایل، همهٔ عناصر به سلول اصلی خود برمی‌گردند و
 *     رابط دسکتاپ دقیقاً مثل قبل رندر می‌شود.
 *
 * الگوها (Pattern) — برای هر جدول جداگانه انتخاب می‌شود:
 *   cards  → کارت/فهرست داده (رکوردهای مستقل: مرخصی، اضافه‌کاری، تیکت، ...)
 *   list   → فهرست فشرده + جزئیات (داده‌های اداری پیچیده: کاربران)
 *   scroll → جدول با اسکرول افقی کنترل‌شده + ستون/سرصفحهٔ چسبان (گزارش‌های
 *            تحلیلی و مالی که مقایسهٔ ستونی دارند)
 *   keep   → جدول کم‌ستونه که در موبایل هم جدول می‌ماند (فقط فشرده/لمسی)
 * ==========================================================================*/
(function () {
  'use strict';

  /* ==========================================================================
   * ۱) ابزارهای کمکی
   * ==========================================================================*/
  var DEBUG = (typeof location !== 'undefined') && / rtdebug/.test(location.search);

  function log() {
    if (DEBUG && window.console) console.log.apply(console, ['[RT]'].concat([].slice.call(arguments)));
  }

  /** نرمال‌سازی متن سرستون‌ها: حذف نیم‌فاصله/کاراکترهای جهت، یکسان‌سازی ی/ک عربی */
  function norm(s) {
    return String(s == null ? '' : s)
      .replace(/[\u200b\u200c\u200e\u200f\uFEFF]/g, '')
      .replace(/ي/g, 'ی').replace(/ك/g, 'ک')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }

  function raf(fn) {
    if (window.requestAnimationFrame) window.requestAnimationFrame(fn); else setTimeout(fn, 16);
  }

  /* ==========================================================================
   * ۲) نشان وضعیت (StatusBadge) — متن + آیکون + رنگ؛ هرگز فقط رنگ نیست
   * ==========================================================================*/
  var ICONS = {
    check: '<path d="M20 6 9 17l-5-5"/>',
    cross: '<path d="M18 6 6 18M6 6l12 12"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    minus: '<path d="M5 12h14"/>',
    send: '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    reply: '<path d="M9 17H7A5 5 0 0 1 7 7h10a5 5 0 0 1 0 10h-3"/><path d="m13 15-4 4 4 4"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
    dot: '<circle cx="12" cy="12" r="5"/>'
  };

  function iconSvg(name) {
    return '<svg class="rt-badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
      ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      (ICONS[name] || ICONS.dot) + '</svg>';
  }

  /** نگاشت عبارات وضعیت فارسی → کلید معنایی + آیکون */
  var STATUS_RULES = [
    { key: 'approved', icon: 'check', words: ['تایید شده', 'تاییدشده', 'مورد تایید', 'تایید سامانه', 'present', 'حاضر', 'حضور', 'ورود ثبت شد'] },
    { key: 'rejected', icon: 'cross', words: ['رد شده', 'ردشده', 'غایب', 'غیبت', 'absent'] },
    { key: 'pending', icon: 'clock', words: ['انتظار تایید', 'در انتظار', 'pending', 'در حال پیگیری', 'تاخیر', 'زودهنگام'] },
    { key: 'cancelled', icon: 'minus', words: ['انصراف', 'لغو شده', 'cancelled'] },
    { key: 'sent', icon: 'send', words: ['ارسال شده'] },
    { key: 'read', icon: 'eye', words: ['خوانده شده'] },
    { key: 'answered', icon: 'reply', words: ['پاسخ داده شده', 'پاسخ دادهشده'] },
    { key: 'overtime', icon: 'clock', words: ['اضافه کاری', 'اضافه‌کاری', 'اضافهکاری'] },
    { key: 'leave', icon: 'calendar', words: ['مرخصی', 'ماموریت', 'مأموریت'] },
    { key: 'holiday', icon: 'minus', words: ['تعطیل', 'تعطیلات'] }
  ];

  function statusKey(text) {
    var t = norm(text);
    if (!t) return null;
    for (var i = 0; i < STATUS_RULES.length; i++) {
      var r = STATUS_RULES[i];
      for (var j = 0; j < r.words.length; j++) {
        if (t === r.words[j] || t.indexOf(r.words[j]) !== -1) return r.key + '|' + r.icon;
      }
    }
    return null;
  }

  /** ساخت نشان وضعیت یکدست (متن + آیکون + رنگ) */
  function buildBadge(text) {
    var label = String(text == null ? '' : text).trim() || '—';
    var wrap = el('span', 'rt-badge');
    var m = statusKey(label);
    if (m) {
      var p = m.split('|');
      wrap.classList.add('rt-badge--' + p[0]);
      wrap.innerHTML = iconSvg(p[1]);
      wrap.appendChild(el('span', 'rt-badge__text', label));
    } else {
      wrap.classList.add('rt-badge--neutral');
      wrap.innerHTML = iconSvg('dot');
      wrap.appendChild(el('span', 'rt-badge__text', label));
    }
    return wrap;
  }

  /* ==========================================================================
   * ۳) Bottom Sheet مشترک — برای «جزئیات» و «منوی اقدامات بیشتر»
 *    (از پایین صفحه باز می‌شود؛ کاملاً راست‌به‌چپ و دسترس‌پذیر)
   * ==========================================================================*/
  var Sheet = {
    node: null, panel: null, body: null, titleNode: null, opener: null,

    ensure: function () {
      if (this.node) return;
      var root = el('div', 'rt-sheet');
      root.setAttribute('hidden', '');
      var backdrop = el('div', 'rt-sheet__backdrop');
      var panel = el('div', 'rt-sheet__panel');
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.innerHTML =
        '<div class="rt-sheet__grab" aria-hidden="true"></div>' +
        '<header class="rt-sheet__head">' +
        '<h3 class="rt-sheet__title"></h3>' +
        '<button type="button" class="rt-sheet__close" aria-label="بستن">&#215;</button>' +
        '</header>' +
        '<div class="rt-sheet__body"></div>';
      root.appendChild(backdrop);
      root.appendChild(panel);
      document.body.appendChild(root);
      this.node = root; this.panel = panel;
      this.body = panel.querySelector('.rt-sheet__body');
      this.titleNode = panel.querySelector('.rt-sheet__title');
      var self = this;
      panel.querySelector('.rt-sheet__close').addEventListener('click', function () { self.close(); });
      backdrop.addEventListener('click', function () { self.close(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && self.isOpen()) self.close();
        if (e.key === 'Tab' && self.isOpen()) self.trap(e);
      });
    },

    isOpen: function () { return this.node && !this.node.hasAttribute('hidden'); },

    trap: function (e) {
      var f = this.panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    },

    /** گشودن شیت. opts: {title, variant, opener, onClose} — محتوا با appendBody */
    open: function (opts) {
      this.ensure();
      /* اگر شیت دیگری باز است، بسته می‌شود (onClose آن ابتدا عناصر را نجات می‌دهد) */
      if (this.isOpen()) this.close(true);
      this.opener = opts.opener || null;
      this.onClose = opts.onClose || null;
      this.node.classList.toggle('rt-sheet--menu', opts.variant === 'menu');
      this.titleNode.textContent = opts.title || '';
      this.body.innerHTML = '';
      this.node.removeAttribute('hidden');
      document.documentElement.classList.add('rt-sheet-open');
      var self = this;
      raf(function () { self.node.classList.add('is-open'); self.panel.querySelector('.rt-sheet__close').focus(); });
    },

    appendBody: function (node) { this.body.appendChild(node); },

    /*-immediate*/ close: function (immediate) {
      if (!this.node || !this.isOpen()) return;
      var self = this;
      /* پیش از پاک‌سازی، مالکِ محتوا عناصر منتقل‌شده را پس می‌گیرد */
      if (this.onClose) { try { this.onClose(); } catch (e) { /* noop */ } this.onClose = null; }
      var finish = function () {
        self.node.classList.remove('is-open');
        document.documentElement.classList.remove('rt-sheet-open');
        self.node.setAttribute('hidden', '');
        self.body.innerHTML = '';
        if (self.opener && self.opener.isConnected) { try { self.opener.focus(); } catch (e) { /* noop */ } }
        self.opener = null;
      };
      if (immediate) { finish(); return; }
      this.node.classList.remove('is-open');
      document.documentElement.classList.remove('rt-sheet-open');
      setTimeout(function () {
        self.node.setAttribute('hidden', '');
        self.body.innerHTML = '';
        if (self.opener && self.opener.isConnected) { try { self.opener.focus(); } catch (e) { /* noop */ } }
        self.opener = null;
      }, 180);
    }
  };

  /* ==========================================================================
   * ۴) کلاس جدول ریسپانسیو — هستهٔ component
   * ==========================================================================*/
  var instances = [];   // همهٔ نمونه‌ها
  var byBreakpoint = {}; // bp → matchMedia

  function ResponsiveTable(table, cfg) {
    this.table = table;
    this.cfg = cfg;
    this.sel = cfg.sel;
    this.active = false;
    this.loading = false;
    this.limit = cfg.pageSize || 40;
    this.adoptions = [];   // [{td, wrapper, injectedChildren:[], addedClasses:[]}]
    this.internalTargets = new Set();
    this.view = null;
    this.scrollWrap = null;

    table.setAttribute('data-rt', cfg.pattern);

    if (cfg.pattern === 'scroll' || cfg.pattern === 'keep') this.setupScroll();
    else this.setupCards();

    this.observe();
  }

  /* ---------- ۴-۱) سرصفحه‌ها (برچسب فارسی هر ستون) ---------- */
  ResponsiveTable.prototype.headers = function () {
    var ths = this.table.querySelectorAll('thead th');
    var out = [];
    for (var i = 0; i < ths.length; i++) out.push(norm(ths[i].textContent));
    return out;
  };

  /** یافتن ایندکس ستون بر اساس فهرست نام‌های قابل قبول */
  function findCol(headers, names, used) {
    if (!names) return -1;
    for (var i = 0; i < names.length; i++) {
      var want = norm(names[i]);
      for (var h = 0; h < headers.length; h++) {
        if (used && used[h]) continue;
        if (headers[h] === want || headers[h].indexOf(want) !== -1) return h;
      }
    }
    return -1;
  }

  /* ---------- ۴-۲) استخراج نقش ستون‌ها ---------- */
  ResponsiveTable.prototype.roles = function () {
    var headers = this.headers();
    var cfg = this.cfg;
    var used = {};
    var r = { headers: headers, title: -1, status: -1, actions: -1, rowNo: -1, visible: [], rest: [] };

    r.title = findCol(headers, cfg.titleFrom || ['عنوان درخواست', 'عنوان تیکت', 'عنوان پاس', 'موضوع', 'عنوان', 'نام کاربر', 'کاربر', 'تاریخ درخواست', 'تاریخ ثبت', 'از تاریخ', 'تاریخ'], used);
    if (r.title >= 0) used[r.title] = true;

    r.status = findCol(headers, cfg.statusFrom || ['وضعیت درخواست', 'وضعیت تیکت', 'وضعیت'], used);
    if (r.status >= 0) used[r.status] = true;

    r.actions = findCol(headers, cfg.actionsFrom || ['تغییرات', 'ثبت تغییرات', 'ویرایش', 'اقدامات', 'عملیات'], used);
    if (r.actions >= 0) used[r.actions] = true;

    r.rowNo = findCol(headers, ['ردیف'], used);
    if (r.rowNo >= 0) used[r.rowNo] = true;

    var vis = cfg.visibleFrom;
    var maxVis = cfg.maxVisible != null ? cfg.maxVisible : 4;
    if (vis && vis.length) {
      for (var i = 0; i < vis.length; i++) {
        var idx = findCol(headers, [vis[i]], used);
        if (idx >= 0) { r.visible.push(idx); used[idx] = true; }
      }
    } else {
      for (var j = 0; j < headers.length && r.visible.length < maxVis; j++) {
        if (!used[j]) { r.visible.push(j); used[j] = true; }
      }
    }
    for (var k = 0; k < headers.length; k++) if (!used[k]) r.rest.push(k);
    return r;
  };

  /* ---------- ۴-۳) ردیف‌های معنایی tbody ---------- */
  ResponsiveTable.prototype.rows = function () {
    var trs = this.table.querySelectorAll('tbody tr');
    var out = [];
    for (var i = 0; i < trs.length; i++) {
      var tr = trs[i];
      if (tr.hasAttribute('data-rt-skip')) continue;
      var tds = [];
      for (var j = 0; j < tr.children.length; j++) if (tr.children[j].tagName === 'TD') tds.push(tr.children[j]);
      if (!tds.length) continue;
      /* ردیف‌های جای‌نگهدار (تک‌سلولی با colspan) به‌عنوان «خالی» در نظر گرفته می‌شوند */
      var meaningful = tds.filter(function (td) { return norm(td.textContent) !== '' || td.querySelector('button,form,input,select,a'); });
      if (!meaningful.length) continue;
      var placeholder = tds.length === 1 && tds[0].hasAttribute('colspan');
      if (placeholder) continue;
      out.push({ tr: tr, tds: tds });
    }
    return out;
  };

  /* ---------- ۴-۴) انتقال (adopt) عناصر تعاملی سلول به کارت ---------- */
  ResponsiveTable.prototype.adoptCell = function (td, cls) {
    var wrapper = el('div', (cls ? cls + ' ' : '') + 'rt-adopted');
    /* حفظ کلاس‌های سلول تا انتخابگرها/CSS و کدهای closest() قبلی کار کنند */
    if (td.className) wrapper.classList.add.apply(wrapper.classList, td.className.split(/\s+/));
    var onclickAttr = td.getAttribute('onclick');
    if (onclickAttr) wrapper.setAttribute('onclick', onclickAttr);
    /* td را پیش از جابه‌جایی فرزندان ثبت کن تا observer آن را «خودی» تشخیص دهد */
    this.internalTargets.add(td);
    while (td.firstChild) wrapper.appendChild(td.firstChild);
    this.adoptions.push({ td: td, wrapper: wrapper, injectedChildren: [], addedClasses: [] });
    return wrapper;
  };

  /** بازگرداندن همهٔ عناصر به سلول اصلی (هنگام بازگشت به دسکتاپ/چاپ یا رندر مجدد) */
  ResponsiveTable.prototype.restore = function () {
    for (var i = 0; i < this.adoptions.length; i++) {
      var a = this.adoptions[i];
      /* حذف کلاس‌ها/برچسب‌هایی که صرفاً برای نمای موبایل اضافه شده بودند */
      for (var c = 0; c < a.addedClasses.length; c++) {
        a.addedClasses[c].node.classList.remove(a.addedClasses[c].cls);
      }
      for (var n = 0; n < a.injectedChildren.length; n++) {
        var child = a.injectedChildren[n];
        if (child.parentNode) child.parentNode.removeChild(child);
      }
      if (!a.td.isConnected) continue;
      if (a.wrapper) {
        while (a.wrapper.firstChild) a.td.appendChild(a.wrapper.firstChild);
      } else if (a.node && a.node.isConnected) {
        /* گرهٔ آزاد (مثلاً دکمهٔ اقدام) — از هرجای نمای موبایل به سلول برمی‌گردد */
        a.td.appendChild(a.node);
      }
    }
    this.adoptions = [];
    this.internalTargets.clear();
  };

  /**
   * ثبت گرهٔ آزادِ منتقل‌شده (مثل دکمه‌های اقدام) برای بازگردانی به سلول مبدأ.
   * برخلاف adoptCell، گره می‌تواند داخل کارت/منو/شیت جابه‌جا شود؛ رندر مجدد
   * ابتدا همه را به سلول برمی‌گرداند و سپس نمای جدید می‌سازد.
   */
  ResponsiveTable.prototype.trackNode = function (td, node) {
    /* سلول مبدأ به‌عنوان «جهت‌دار داخلی» علامت می‌خورد تا MutationObserver
       جابه‌جایی‌های خودِ لایهٔ موبایل را تغییر دادهٔ جدید تلقی نکند */
    this.internalTargets.add(td);
    this.adoptions.push({ td: td, wrapper: null, node: node, injectedChildren: [], addedClasses: [] });
  };

  /* ---------- ۴-۵) ساختار نمای کارتی ---------- */
  ResponsiveTable.prototype.setupCards = function () {
    var view = el('div', 'rt-view');
    view.setAttribute('hidden', '');
    view.setAttribute('data-rt-pattern', this.cfg.pattern);
    this.table.parentNode.insertBefore(view, this.table.nextSibling);
    this.view = view;
  };

  /* ---------- ۴-۶) بستهٔ اسکرول افقی کنترل‌شده (فقط کانتینر، نه کل صفحه) ---------- */
  ResponsiveTable.prototype.setupScroll = function () {
    var table = this.table;
    var parent = table.parentNode;
    /* اگر از قبل داخل wrapper هستیم (بارگذاری مجدد)، دوباره نمی‌پیچیم */
    if (parent.classList && parent.classList.contains('rt-scroll')) return;

    var wrap = el('div', 'rt-scroll-wrap');
    var scroller = el('div', 'rt-scroll');
    scroller.setAttribute('role', 'region');
    scroller.setAttribute('aria-label', this.cfg.label || 'جدول داده‌ها — قابل اسکرول افقی');
    scroller.setAttribute('tabindex', '0');
    parent.insertBefore(wrap, table);
    wrap.appendChild(scroller);
    scroller.appendChild(table);

    var hint = el('div', 'rt-scroll-hint');
    hint.setAttribute('aria-hidden', 'true');
    hint.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg><span>بیشتر</span>';
    wrap.appendChild(hint);

    if (this.cfg.pattern === 'keep') wrap.classList.add('rt-scroll-wrap--keep');
    if (this.cfg.stickyHead) {
      wrap.setAttribute('data-sticky-head', '');
      wrap.style.setProperty('--rt-mh', this.cfg.maxHeight || '62vh');
    }
    /* جهت فیزیکی جدول برای انتخاب سمت صحیح ستون‌های چسبان */
    try {
      scroller.setAttribute('data-dir', getComputedStyle(table).direction === 'rtl' ? 'rtl' : 'ltr');
    } catch (e) { scroller.setAttribute('data-dir', 'ltr'); }

    var self = this;
    function updateHint() {
      var over = scroller.scrollWidth - scroller.clientWidth > 8;
      var atStart = scroller.scrollLeft <= 4;
      var atEnd = scroller.scrollLeft >= scroller.scrollWidth - scroller.clientWidth - 4;
      wrap.classList.toggle('has-overflow', over);
      wrap.classList.toggle('at-start', over && atStart);
      wrap.classList.toggle('at-end', over && atEnd);
    }
    scroller.addEventListener('scroll', updateHint, { passive: true });
    window.addEventListener('resize', function () { self.applySticky(); updateHint(); });
    this.scrollWrap = wrap;
    this.scroller = scroller;
    this.updateHint = updateHint;
    this.applySticky();

    /* باکس‌های مدیریتی با display:none باز/بسته می‌شوند؛ هنگام نمایان شدن
       جدول، عرض ستون‌های چسبان باید دوباره اندازه‌گیری شود */
    if ('IntersectionObserver' in window) {
      this._io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) self.applySticky(); });
      });
      this._io.observe(table);
    }
    window.addEventListener('load', function () { self.applySticky(); });
  };

  /** اعمال ستون‌های چسبان + سرصفحهٔ چسبان (فقط در عرض موبایل مؤثر است) */
  ResponsiveTable.prototype.applySticky = function () {
    if (this.cfg.pattern !== 'scroll' || !this.scroller) return;
    var cfg = this.cfg;
    var rows = [this.table.querySelector('thead tr')].concat([].slice.call(this.table.querySelectorAll('tbody tr')));
    var startN = cfg.stickyStart || 0, endN = cfg.stickyEnd || 0;

    /* پاک‌سازی کلاس‌های قبلی */
    this.table.querySelectorAll('.rt-sticky').forEach(function (c) { c.classList.remove('rt-sticky', 'rt-sticky--s1', 'rt-sticky--s2', 'rt-sticky--e1', 'rt-sticky--e2', 'rt-sticky--e3'); });

    if (startN + endN > 0) {
      var widths = { s: [], e: [] };
      rows.forEach(function (tr) {
        if (!tr) return;
        var cells = [].slice.call(tr.children);
        var i;
        for (i = 0; i < Math.min(startN, cells.length); i++) {
          cells[i].classList.add('rt-sticky', 'rt-sticky--s' + (i + 1));
        }
        for (i = 0; i < Math.min(endN, cells.length); i++) {
          var c = cells[cells.length - 1 - i];
          c.classList.add('rt-sticky', 'rt-sticky--e' + (i + 1));
        }
      });
      /* اندازه‌گیری عرض ستون‌های چسبان برای تنظیم آفست‌ها */
      var sample = this.table.querySelector('tbody tr') || this.table.querySelector('thead tr');
      if (sample) {
        var cells = [].slice.call(sample.children);
        var i2, w;
        for (i2 = 0; i2 < Math.min(startN, cells.length); i2++) widths.s[i2] = cells[i2].getBoundingClientRect().width;
        var ew = [];
        for (i2 = 0; i2 < Math.min(endN, cells.length); i2++) ew.unshift(cells[cells.length - 1 - i2].getBoundingClientRect().width);
        widths.e = ew;
        var style = this.scrollWrap.style;
        var acc = 0;
        for (i2 = 0; i2 < widths.s.length; i2++) { acc += widths.s[i2]; style.setProperty('--rt-s' + (i2 + 2) + 'w', acc + 'px'); }
        acc = 0;
        for (i2 = widths.e.length - 1; i2 >= 0; i2--) { acc += widths.e[i2]; if (i2 > 0) style.setProperty('--rt-e' + i2 + 'w', (acc) + 'px'); }
      }
    }

    /* پس‌زمینهٔ مات سرستون‌ها/سلول‌ها برای چسبان شدن صحیح (پشتیبانی از گرادیان) */
    var th = this.table.querySelector('thead th');
    if (th) {
      try {
        var thSt = getComputedStyle(th);
        var trSt = getComputedStyle(th.parentElement);
        var thBg = thSt.backgroundColor;
        if (!thBg || thBg === 'rgba(0, 0, 0, 0)') thBg = trSt.backgroundColor;
        if (thBg && thBg !== 'rgba(0, 0, 0, 0)') this.scrollWrap.style.setProperty('--rt-th-bg', thBg);
        var thImg = trSt.backgroundImage && trSt.backgroundImage !== 'none' ? trSt.backgroundImage : thSt.backgroundImage;
        if (thImg && thImg !== 'none') this.scrollWrap.style.setProperty('--rt-th-img', thImg);
      } catch (e) { /* noop */ }
    }
    var td = this.table.querySelector('tbody td');
    if (td) {
      try {
        var tdSt = getComputedStyle(td);
        var rowSt = getComputedStyle(td.parentElement);
        var tdBg = tdSt.backgroundColor;
        if (!tdBg || tdBg === 'rgba(0, 0, 0, 0)') tdBg = rowSt.backgroundColor;
        // fallback باید تابع تم باشد؛ سفید ثابت در حالت تاریک لکهٔ روشن می‌ساخت
        var isDark = document.body.classList.contains('dark-mode') ||
                     document.body.classList.contains('dark-theme');
        var fallbackBg = isDark ? '#182233' : '#ffffff';
        this.scrollWrap.style.setProperty('--rt-td-bg', tdBg && tdBg !== 'rgba(0, 0, 0, 0)' ? tdBg : fallbackBg);
      } catch (e) { /* noop */ }
    }
    if (this.scroller) this.scroller.style.setProperty('--rt-minw', (cfg.minWidth || 640) + 'px');
    if (this.updateHint) this.updateHint();
  };

  /* ---------- ۴-۷) فعال/غیرفعال‌سازی بر اساس عرض صفحه ---------- */
  ResponsiveTable.prototype.setActive = function (on) {
    if (on === this.active) return;
    this.active = on;
    if (this.cfg.pattern === 'scroll' || this.cfg.pattern === 'keep') {
      /* جدول می‌ماند؛ فقط کلاس فعال برای CSS موبایل + اندازه‌گیری مجدد ستون چسبان */
      if (this.scrollWrap) this.scrollWrap.classList.toggle('rt-on', on);
      if (on) this.applySticky();
      return;
    }
    if (on) {
      this.render();
      this.view.removeAttribute('hidden');
      this.table.classList.add('rt-source-hidden');
    } else {
      /* ابتدا شیت باز (در صورت وجود) بسته شود تا عناصر منتقل‌شده پس گرفته شوند */
      Sheet.close(true);
      this.restore();
      this.view.setAttribute('hidden', '');
      this.view.innerHTML = '';
      this.table.classList.remove('rt-source-hidden');
    }
  };

  /* ---------- ۴-۸) رندر ---------- */
  ResponsiveTable.prototype.render = function () {
    if (!this.view || !this.active) return;
    this._rendering = true;
    try {
      this.restore();
      this.view.innerHTML = '';
      var cfg = this.cfg;

      /* حالت بارگذاری (Skeleton) — فقط وقتی هنوز داده‌ای رندر نشده */
      var rows = this.rows();
      if (this.loading && !rows.length) {
        this.view.appendChild(this.buildSkeleton());
        return;
      }

      /* حالت خالی */
      if (!rows.length) {
        this.view.appendChild(this.buildEmpty());
        return;
      }

      var roles = this.roles();
      var shown = Math.min(rows.length, this.limit);
      var list = el('div', cfg.pattern === 'list' ? 'rt-list' : 'rt-cards');

      for (var i = 0; i < shown; i++) {
        list.appendChild(cfg.pattern === 'list' ? this.buildListRow(rows[i], roles, i) : this.buildCard(rows[i], roles, i));
      }
      this.view.appendChild(list);

      /* دکمهٔ «نمایش موارد بیشتر» برای فهرست‌های طولانی (عملکرد/کارایی) */
      if (rows.length > shown) {
        var self = this;
        var more = el('button', 'rt-more', 'نمایش موارد بیشتر (' + String(rows.length - shown) + ' مورد باقی‌مانده)');
        more.type = 'button';
        more.addEventListener('click', function () {
          self.limit += (cfg.pageSize || 40);
          self.render();
        });
        this.view.appendChild(more);
      }
    } finally {
      this._rendering = false;
    }
  };

  ResponsiveTable.prototype.scheduleRender = function () {
    var self = this;
    if (this._pending) return;
    this._pending = true;
    raf(function () { self._pending = false; self.render(); });
  };

  /* ---------- ۴-۹) Skeleton ---------- */
  ResponsiveTable.prototype.buildSkeleton = function () {
    var box = el('div', 'rt-skeletons');
    box.setAttribute('aria-hidden', 'true');
    box.setAttribute('aria-label', 'در حال بارگذاری…');
    for (var i = 0; i < 4; i++) {
      var s = el('div', 'rt-skeleton-card');
      s.innerHTML =
        '<div class="rt-sk rt-sk--head"></div>' +
        '<div class="rt-sk rt-sk--line"></div>' +
        '<div class="rt-sk rt-sk--line"></div>' +
        '<div class="rt-sk rt-sk--line rt-sk--short"></div>';
      box.appendChild(s);
    }
    return box;
  };

  /* ---------- ۴-۱۰) حالت خالی ---------- */
  ResponsiveTable.prototype.buildEmpty = function () {
    var e = this.cfg.empty || {};
    var box = el('div', 'rt-empty');
    box.innerHTML =
      '<svg class="rt-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M3 5h18M3 5v2l8 8h2l8-8V5M4 21l7-7M20 21l-7-7"/>' +
      '</svg>' +
      '<p class="rt-empty__title"></p>' +
      '<p class="rt-empty__text"></p>';
    box.querySelector('.rt-empty__title').textContent = e.title || 'موردی برای نمایش وجود نیست';
    box.querySelector('.rt-empty__text').textContent = e.text || 'داده‌ای برای این فهرست ثبت نشده است.';
    return box;
  };

  /* ---------- ۴-۱۱) ساخت یک کارت از یک ردیف جدول ---------- */
  ResponsiveTable.prototype.buildCard = function (row, roles, index) {
    var cfg = this.cfg;
    var tds = row.tds;

    /* هم‌ترازی سلول‌ها با سرستون‌ها: سلول‌های مخفیِ ابتدای ردیف (مثل id) نادیده گرفته می‌شوند */
    var cells = [].slice.call(tds);
    while (cells.length > roles.headers.length && cells[0] && (cells[0].style.display === 'none' || cells[0].getAttribute('aria-hidden') === 'true')) cells.shift();
    var cellAt = function (i) { return i >= 0 && i < cells.length ? cells[i] : null; };
    var textAt = function (i) { var c = cellAt(i); return c ? norm(c.textContent) : ''; };

    var card = el('article', 'rt-card');

    /* --- سربرگ کارت: عنوان + شماره ردیف + وضعیت --- */
    var head = el('header', 'rt-card__head');
    var titleWrap = el('div', 'rt-card__titlewrap');
    var title = el('h4', 'rt-card__title', textAt(roles.title) || 'رکورد ' + (index + 1));
    titleWrap.appendChild(title);
    if (roles.rowNo >= 0 && textAt(roles.rowNo)) {
      titleWrap.appendChild(el('span', 'rt-card__rowno', '#' + textAt(roles.rowNo)));
    }
    head.appendChild(titleWrap);

    var statusCell = cellAt(roles.status);
    var statusText = statusCell ? norm(statusCell.textContent) : '';
    if (statusCell && statusCell.querySelector('.status-container, .status-navbar, .dropdown')) {
      /* ستون وضعیت تعاملی است (منوی تغییر وضعیت ادمین/تیکت) → عیناً منتقل می‌شود */
      var adopted = this.adoptCell(statusCell, 'rt-card__status');
      var sKey = (statusKey(statusText) || 'neutral|dot').split('|')[0];
      adopted.classList.add('rt-status--' + sKey);
      head.appendChild(adopted);
    } else if (roles.status >= 0 && statusText) {
      head.appendChild(buildBadge(statusText));
    }
    card.appendChild(head);

    /* --- بدنه: فیلدهای کلیدی (برچسب ← مقدار) --- */
    var body = el('div', 'rt-card__body');
    var dl = el('dl', 'rt-card__fields');
    var visList = [];
    for (var v = 0; v < roles.visible.length; v++) {
      var vi = roles.visible[v];
      var vc = cellAt(vi);
      if (!vc) continue;
      var value = norm(vc.textContent);
      if (!value && !vc.querySelector('button,form,input')) continue;
      var field = el('div', 'rt-field');
      field.appendChild(el('dt', 'rt-field__label', roles.headers[vi] || ''));
      var dd = el('dd', 'rt-field__value', value || '—');
      if (value.length > 90) {
        dd.classList.add('rt-clamp');
        dd.title = value;
        selfClamp(dd);
      }
      field.appendChild(dd);
      dl.appendChild(field);
      visList.push(vi);
    }
    body.appendChild(dl);
    card.appendChild(body);

    /* --- فیلدهای باقی‌مانده → نمای جزئیات --- */
    var hiddenIdx = roles.rest.slice();
    var hasDetails = hiddenIdx.length > 0;

    /* --- پابرگ: اقدامات + جزئیات --- */
    var actionCell = cellAt(roles.actions);
    var actionUnits = actionCell ? this.extractActions(actionCell) : [];
    var foot = el('footer', 'rt-card__foot');

    if (actionUnits.length) {
      foot.appendChild(this.buildActions(actionUnits, cfg, false, actionCell));
    }
    if (hasDetails) {
      var self = this;
      var detailsBtn = el('button', 'rt-btn rt-btn--ghost rt-card__details', cfg.detailsLabel || 'مشاهده جزئیات');
      detailsBtn.type = 'button';
      detailsBtn.innerHTML += '<span class="rt-chevron" aria-hidden="true">‹</span>';
      detailsBtn.addEventListener('click', function () {
        self.openDetails({
          title: cfg.detailsTitle || 'جزئیات',
          rowTitle: title.textContent,
          statusText: statusText,
          cells: cells,
          roles: roles
        }, detailsBtn);
      });
      foot.appendChild(detailsBtn);
    }
    if (foot.childNodes.length) card.appendChild(foot);

    card.setAttribute('aria-label', title.textContent + (statusText ? '، وضعیت: ' + statusText : ''));
    return card;
  };

  /** محدودسازی متن بلند با امکان باز/بسته کردن */
  function selfClamp(dd) {
    dd.addEventListener('click', function () { dd.classList.toggle('is-open'); });
    dd.setAttribute('role', 'button');
    dd.setAttribute('tabindex', '0');
    dd.setAttribute('aria-expanded', 'false');
    dd.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dd.classList.toggle('is-open'); }
    });
  }

  /* ---------- ۴-۱۲) الگوی فهرست فشرده (Pattern C) ---------- */
  ResponsiveTable.prototype.buildListRow = function (row, roles, index) {
    var self = this, cfg = this.cfg;
    var cells = [].slice.call(row.tds);
    while (cells.length > roles.headers.length && cells[0] && cells[0].style.display === 'none') cells.shift();
    var cellAt = function (i) { return i >= 0 && i < cells.length ? cells[i] : null; };
    var textAt = function (i) { var c = cellAt(i); return c ? norm(c.textContent) : ''; };

    var li = el('div', 'rt-list__row');
    li.setAttribute('role', 'button');
    li.setAttribute('tabindex', '0');

    var avatar = el('div', 'rt-list__avatar', (textAt(roles.title) || '؟').trim().charAt(0) || '؟');
    var main = el('div', 'rt-list__main');
    main.appendChild(el('h4', 'rt-list__title', textAt(roles.title) || 'رکورد ' + (index + 1)));

    var metaParts = [];
    var metaFrom = cfg.metaFrom || [];
    for (var m = 0; m < metaFrom.length; m++) {
      var idx = findCol(roles.headers, [metaFrom[m]]);
      var t = idx >= 0 ? textAt(idx) : '';
      if (t) metaParts.push(t);
    }
    if (metaParts.length) main.appendChild(el('div', 'rt-list__meta', metaParts.join('  •  ')));
    li.appendChild(avatar);
    li.appendChild(main);

    /* اقدامات: اقدام اصلی + منوی «بیشتر» */
    var actionCell = cellAt(roles.actions);
    var actionUnits = actionCell ? this.extractActions(actionCell) : [];
    var side = el('div', 'rt-list__side');
    if (actionUnits.length) side.appendChild(this.buildActions(actionUnits, cfg, true, actionCell));
    li.appendChild(side);

    var self2 = this;
    function open() {
      self2.openDetails({
        title: cfg.detailsTitle || 'جزئیات',
        rowTitle: textAt(roles.title),
        statusText: roles.status >= 0 ? textAt(roles.status) : '',
        cells: cells,
        roles: roles
      }, li);
    }
    li.addEventListener('click', function (e) {
      if (e.target.closest('.rt-actions, .rt-actions-menu-btn, button, form, a, input, select')) return;
      open();
    });
    li.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        if (e.target.closest('.rt-actions, button, form, a')) return;
        e.preventDefault(); open();
      }
    });
    return li;
  };

  /* ---------- ۴-۱۳) استخراج اقدامات از سلول تغییرات ---------- */
  ResponsiveTable.prototype.extractActions = function (td) {
    var units = [];
    var forms = [].slice.call(td.querySelectorAll('form'));
    forms.forEach(function (f) { units.push({ node: f, label: actionLabel(f) }); });
    var btns = [].slice.call(td.querySelectorAll('button, a.btn, a'));
    var self = this;
    btns.forEach(function (b) {
      if (b.closest('form') && forms.indexOf(b.closest('form')) !== -1) return; /* دکمهٔ داخل فرم، بخشی از همان فرم است */
      if (b.tagName === 'A' && !b.getAttribute('href')) return;
      units.push({ node: b, label: actionLabel(b) });
    });
    if (!units.length && td.querySelector('button')) {
      var wrap = el('div');
      while (td.firstChild) wrap.appendChild(td.firstChild);
      units.push({ node: wrap, label: '' });
    }
    return units;
  };

  function actionLabel(node) {
    var tip = node.querySelector('[class*="tooltip-text"]');
    if (tip && norm(tip.textContent)) return norm(tip.textContent);
    if (node.getAttribute && node.getAttribute('aria-label')) return node.getAttribute('aria-label');
    if (norm(node.textContent)) return norm(node.textContent);
    var img = node.querySelector('img');
    return img ? (img.getAttribute('alt') || '') : '';
  }

  /** ساخت ناحیهٔ اقدامات: اقدام اصلی + منوی «⋯» برای بقیه (الگوی [اقدام اصلی][⋮]) */
  ResponsiveTable.prototype.buildActions = function (units, cfg, compact, srcCell) {
    var self = this;
    var box = el('div', 'rt-actions' + (compact ? ' rt-actions--compact' : ''));

    /* همهٔ کنترل‌های منتقل‌شده ثبت می‌شوند تا در رندر مجدد/بازگشت به دسکتاپ
       عیناً به سلول «تغییرات» برگردند و از دست نروند */
    if (srcCell) units.forEach(function (u) { self.trackNode(srcCell, u.node); });

    var primary = null, rest = [].slice.call(units);
    if (cfg.primary) {
      for (var i = 0; i < units.length; i++) {
        if (units[i].node.matches && units[i].node.matches(cfg.primary)) { primary = units[i]; rest.splice(i, 1); break; }
      }
    }
    if (!primary && units.length === 1) { primary = units[0]; rest = []; }

    var pool = el('div', 'rt-actions-pool');
    pool.setAttribute('hidden', '');

    function stylizePrimary(unit) {
      var node = unit.node;
      node.classList.add('rt-btn', 'rt-btn--primary');
      self.trackClass(node, 'rt-btn'); self.trackClass(node, 'rt-btn--primary');
      if (unit.label) {
        var lab = el('span', 'rt-btn__label', unit.label);
        node.appendChild(lab);
        var rec = self.lastAdoptionOf(node);
        if (rec) rec.injectedChildren.push(lab);
      }
      if (!node.getAttribute('aria-label') && unit.label) node.setAttribute('aria-label', unit.label);
      return node;
    }

    if (primary) box.appendChild(stylizePrimary(primary));

    if (rest.length) {
      rest.forEach(function (u) { pool.appendChild(u.node); });
      box.appendChild(pool);

      var moreBtn = el('button', 'rt-actions-menu-btn');
      moreBtn.type = 'button';
      moreBtn.setAttribute('aria-label', 'اقدامات بیشتر');
      moreBtn.setAttribute('aria-haspopup', 'menu');
      moreBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>';
      moreBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        self.openMenu(rest, pool, moreBtn);
      });
      box.appendChild(moreBtn);
    } else if (primary) {
      /* فقط یک اقدام؛ نیازی به pool نیست ولی برای بازگشت تمیز، داخل box می‌ماند */
      box.appendChild(pool);
    }
    return box;
  };

  ResponsiveTable.prototype.trackClass = function (node, cls) {
    for (var i = this.adoptions.length - 1; i >= 0; i--) {
      var rec = this.adoptions[i];
      if (rec.node === node || (rec.wrapper && (rec.wrapper === node || rec.wrapper.contains(node) || node.contains(rec.wrapper)))) {
        rec.addedClasses.push({ node: node, cls: cls });
        return;
      }
    }
  };

  ResponsiveTable.prototype.lastAdoptionOf = function (node) {
    for (var i = this.adoptions.length - 1; i >= 0; i--) {
      var rec = this.adoptions[i];
      if (rec.node === node || (rec.wrapper && (rec.wrapper === node || rec.wrapper.contains(node) || node.contains(rec.wrapper)))) return rec;
    }
    return null;
  };

  /* ---------- ۴-۱۴) منوی اقدامات بیشتر (شیت پایین) ---------- */
  ResponsiveTable.prototype.openMenu = function (units, pool, opener) {
    Sheet.open({
      title: 'اقدامات بیشتر', variant: 'menu', opener: opener,
      /* هنگام بستن شیت، کنترل‌ها به مخزن داخل کارت برمی‌گردند تا از دست نروند */
      onClose: function () { units.forEach(function (u) { try { pool.appendChild(u.node); } catch (e) { /* noop */ } }); }
    });
    var menu = el('div', 'rt-menu');
    menu.setAttribute('role', 'menu');
    units.forEach(function (u) {
      var item = el('div', 'rt-menu__item');
      item.setAttribute('role', 'none');
      if (u.label) item.appendChild(el('span', 'rt-menu__label', u.label));
      var holder = el('div', 'rt-menu__control');
      holder.appendChild(u.node);
      item.appendChild(holder);
      menu.appendChild(item);
    });
    Sheet.appendBody(menu);
  };

  /* ---------- ۴-۱۵) نمای جزئیات (شیت پایین) — همهٔ فیلدها ---------- */
  ResponsiveTable.prototype.openDetails = function (ctx, opener) {
    Sheet.open({ title: ctx.title, variant: 'details', opener: opener });
    var box = el('div', 'rt-details');
    if (ctx.rowTitle) {
      var head = el('div', 'rt-details__head');
      head.appendChild(el('h4', 'rt-details__title', ctx.rowTitle));
      if (ctx.statusText) head.appendChild(buildBadge(ctx.statusText));
      box.appendChild(head);
    }
    var dl = el('dl', 'rt-details__fields');
    var headers = ctx.roles.headers;
    var shown = {};
    [ctx.roles.title, ctx.roles.rowNo].forEach(function (i) { if (i >= 0) shown[i] = true; });
    for (var i = 0; i < headers.length; i++) {
      if (i === ctx.roles.actions || shown[i]) continue;
      var cell = ctx.cells[i];
      if (!cell) continue;
      var value = norm(cell.textContent);
      if (!value && !cell.querySelector('button,form,input')) continue;
      var field = el('div', 'rt-field rt-field--detail');
      field.appendChild(el('dt', 'rt-field__label', headers[i] || ''));
      field.appendChild(el('dd', 'rt-field__value', value || '—'));
      dl.appendChild(field);
    }
    box.appendChild(dl);
    if (!dl.childNodes.length) box.appendChild(this.buildEmpty());
    Sheet.appendBody(box);
  };

  /* ---------- ۴-۱۶) مشاهدهٔ تغییرات جدول ---------- */
  ResponsiveTable.prototype.observe = function () {
    var self = this;
    var target = this.table;
    if (!('MutationObserver' in window)) return;
    var mo = new MutationObserver(function (records) {
      if (!self.active || self._rendering) return;
      var dirty = false;
      for (var i = 0; i < records.length; i++) {
        var r = records[i];
        var t = r.target;
        if (t === self.table || (t.tagName === 'TBODY')) { dirty = true; break; }
        if (t && t.tagName === 'TR') { dirty = true; break; }
        if (t && t.tagName === 'TD' && !self.internalTargets.has(t)) { dirty = true; break; }
      }
      if (dirty) {
        Sheet.close();
        self.scheduleRender();
      }
    });
    mo.observe(target, { childList: true, subtree: true });
    this._mo = mo;
  };

  /* ---------- ۴-۱۷) حالت بارگذاری ---------- */
  ResponsiveTable.prototype.setLoading = function (on) {
    if (this.loading === !!on) return;
    this.loading = !!on;
    if (this.loading && this.view) this.view.classList.add('is-loading');
    else if (this.view) this.view.classList.remove('is-loading');
    this.scheduleRender();
  };

  /* ==========================================================================
   * ۵) مدیریت بریک‌پوینت‌ها (یک matchMedia برای هر بریک‌پوینت متمایز)
   * ==========================================================================*/
  function watchBreakpoint(bp) {
    if (byBreakpoint[bp]) return;
    var mq = window.matchMedia('(max-width: ' + bp + 'px)');
    var apply = function (on) {
      instances.forEach(function (inst) {
        if (inst.cfg.bp === bp) { try { inst.setActive(on); } catch (e) { log('activate error', e); } }
      });
    };
    if (mq.addEventListener) mq.addEventListener('change', function (e) { apply(e.matches); });
    else if (mq.addListener) mq.addListener(function (e) { apply(e.matches); });
    byBreakpoint[bp] = mq;
    return mq;
  }

  /* ==========================================================================
   * ۶) ثبت پیکربندی جداول + راه‌اندازی
   * ==========================================================================*/
  function resolveElements(sel) {
    var nodes = [].slice.call(document.querySelectorAll(sel));
    /* پشتیبانی از انتخابگر tbody (مثلاً #reportTableBody) */
    var out = [];
    nodes.forEach(function (n) {
      if (n.tagName === 'TBODY' || n.tagName === 'THEAD') {
        var t = n.closest('table');
        if (t && out.indexOf(t) === -1) out.push(t);
      } else if (n.tagName === 'TABLE' && out.indexOf(n) === -1) {
        out.push(n);
      }
    });
    return out;
  }

  function register(sel, cfg) {
    cfg = cfg || {};
    cfg.sel = sel;
    if (!cfg.bp) cfg.bp = 768;
    var tables = resolveElements(sel);
    if (!tables.length) { log('no table for', sel); return; }
    tables.forEach(function (t) {
      var inst = new ResponsiveTable(t, cfg);
      instances.push(inst);
      watchBreakpoint(inst.cfg.bp);
    });
  }

  var _initialized = false;
  function init() {
    if (_initialized) return;
    _initialized = true;
    CONFIGS.forEach(function (c) {
      try { register(c.sel, c); } catch (e) { log('register failed', c.sel, e); }
    });
    /* اعمال وضعیت اولیه */
    Object.keys(byBreakpoint).forEach(function (bp) {
      var on = byBreakpoint[bp].matches;
      instances.forEach(function (inst) { if (inst.cfg.bp === Number(bp)) inst.setActive(on); });
    });
    hookFetch();
    hookPrint();
  }

  /* ==========================================================================
   * ۷) Skeleton خودکار: نگاشت اندپوینت‌های موجود → جدول‌های متصل به آن‌ها
   *    (فقط وضعیت «بارگذاری» را نشان می‌دهد؛ هیچ درخواست جدیدی اضافه نمی‌کند)
   * ==========================================================================*/
  var ENDPOINT_MAP = [
    { re: /\/get_leave_requests/, sels: ['#leaveRequestsTable'] },
    { re: /\/generate_individual_report/, sels: ['.management-box .individual-report-table'] },
    { re: /\/get_overtime_requests/, sels: ['#overTimeRequestTable'] },
    { re: /\/get_overtime_report/, sels: ['#overTimeIndivisualReportTable', '#overTimeReportTable.individual-report-table'] },
    { re: /\/get_hourly_pass_requests/, sels: ['#hourlyPassReportTable'] },
    { re: /\/get_hourly_pass_report/, sels: ['#hourlyPassIndivisualuserReportTable'] },
    { re: /\/get_hozoor\//, sels: ['#hozoorUsersReportTable', '#HozoorTableReport'] },
    { re: /\/get_leave_info/, sels: ['#leaveTable'] },
    { re: /\/get_user_info_final_report_page\//, sels: ['.hozoorBox #hozoorUsersReportTable', '#ezafeKarUsersReportTable', '#morkhcUsersReportTable', '#hourlyPassUsersReportTable'] },
    { re: /\/get_shifts\//, sels: ['#shiftsTable'] }
  ];

  function tablesFor(url) {
    var found = [];
    for (var i = 0; i < ENDPOINT_MAP.length; i++) {
      if (!ENDPOINT_MAP[i].re.test(url)) continue;
      ENDPOINT_MAP[i].sels.forEach(function (s) {
        resolveElements(s).forEach(function (t) { if (found.indexOf(t) === -1) found.push(t); });
      });
    }
    return found.map(function (t) {
      for (var j = 0; j < instances.length; j++) if (instances[j].table === t) return instances[j];
      return null;
    }).filter(Boolean);
  }

  var fetchHooked = false;
  function hookFetch() {
    if (fetchHooked || typeof window.fetch !== 'function') return;
    fetchHooked = true;
    var orig = window.fetch;
    window.fetch = function () {
      var url = '';
      try { url = typeof arguments[0] === 'string' ? arguments[0] : (arguments[0] && arguments[0].url) || ''; } catch (e) { /* noop */ }
      var insts = url ? tablesFor(url) : [];
      var promise = orig.apply(this, arguments);
      if (insts.length) {
        insts.forEach(function (i) { i.setLoading(true); });
        var settle = function () { insts.forEach(function (i) { i.setLoading(false); }); };
        promise.then(settle, settle);
      }
      return promise;
    };
  }

  /* ==========================================================================
   * ۸) چاپ: پیش از چاپ، نمای دسکتاپ بازیابی می‌شود (گزارش‌های چاپی سالم بمانند)
   * ==========================================================================*/
  function hookPrint() {
    window.addEventListener('beforeprint', function () {
      instances.forEach(function (i) { if (i.active && i.cfg.pattern !== 'scroll' && i.cfg.pattern !== 'keep') i.setActive(false); });
    });
    window.addEventListener('afterprint', function () {
      instances.forEach(function (i) {
        if (i.cfg.pattern !== 'scroll' && i.cfg.pattern !== 'keep' && byBreakpoint[i.cfg.bp] && byBreakpoint[i.cfg.bp].matches) i.setActive(true);
      });
    });
  }

  /* ==========================================================================
   * ۹) پیکربندی جداول — برای هر جدول، الگوی مناسب بر اساس نوع داده
   * ==========================================================================*/
  var CONFIGS = [
    /* ----- پنل کاربری (بریک‌پوینت موبایل پنل: ۸۶۰px) — الگوی کارت ----- */
    {
      sel: '#ticket-status-table', pattern: 'cards', bp: 860,
      titleFrom: ['عنوان درخواست'], statusFrom: ['وضعیت'], actionsFrom: ['ویرایش'],
      visibleFrom: ['تاریخ درخواست', 'دریافت کننده'], hiddenFrom: null,
      primary: '.edit-btn, .view-btn',
      detailsTitle: 'جزئیات تیکت', detailsLabel: 'مشاهده جزئیات تیکت'
    },
    {
      sel: '#ticket-status-table-popup', pattern: 'cards', bp: 860,
      titleFrom: ['عنوان درخواست'], statusFrom: ['وضعیت'], actionsFrom: ['ویرایش'],
      visibleFrom: ['تاریخ درخواست', 'دریافت کننده'],
      primary: '.edit-btn, .view-btn',
      detailsTitle: 'جزئیات تیکت', detailsLabel: 'مشاهده جزئیات تیکت'
    },
    {
      sel: '#leaveTable', pattern: 'cards', bp: 860,
      titleFrom: ['از تاریخ'], statusFrom: ['وضعیت درخواست'],
      visibleFrom: ['تا تاریخ', 'تعداد روز'],
      detailsTitle: 'جزئیات مرخصی', detailsLabel: 'مشاهده جزئیات'
    },
    {
      sel: '#OverTimeTable', pattern: 'cards', bp: 860,
      titleFrom: ['تاریخ درخواست'], statusFrom: ['وضعیت'],
      visibleFrom: ['مدت زمان اضافه کاری', 'توضیحات'],
      detailsTitle: 'جزئیات اضافه‌کاری', detailsLabel: 'مشاهده جزئیات'
    },
    {
      sel: '#passsaatiReportTable', pattern: 'cards', bp: 860,
      titleFrom: ['عنوان پاس'], statusFrom: ['وضعیت درخواست'],
      visibleFrom: ['تاریخ درخواست', 'مدت زمان پاس'],
      detailsTitle: 'جزئیات پاس ساعتی', detailsLabel: 'مشاهده جزئیات'
    },
    {
      sel: '#HozoorTableReport', pattern: 'cards', bp: 860,
      titleFrom: ['تاریخ ثبت'], statusFrom: ['وضعیت'],
      visibleFrom: ['زمان ورود', 'زمان خروج'],
      detailsTitle: 'جزئیات حضور', detailsLabel: 'مشاهده جزئیات حضور'
    },

    /* ----- پنل مدیریت (بریک‌پوینت ۷۶۸px) ----- */
    /* کاربران: دادهٔ اداری پیچیده → فهرست فشرده + جزئیات (Pattern C) */
    {
      sel: '#userTable', pattern: 'list',
      titleFrom: ['نام کاربر'], metaFrom: ['وضعیت حضور', 'بخش فعالیت', 'ساعت‌های کاری'],
      actionsFrom: ['تغییرات'], primary: '.edit-btn',
      detailsTitle: 'جزئیات کاربر'
    },
    /* درخواست‌های مرخصی: رکوردهای مستقل → کارت (Pattern A) */
    {
      sel: '#leaveRequestsTable', pattern: 'cards',
      titleFrom: ['نام کاربر'], statusFrom: ['وضعیت درخواست'], actionsFrom: ['ثبت تغییرات'],
      primary: '.update-button',
      visibleFrom: ['از تاریخ', 'تا تاریخ', 'تعداد روزها', 'جانشین'],
      detailsTitle: 'جزئیات درخواست مرخصی'
    },
    /* شیفت‌ها: همانند درخواست‌های مرخصی، هر بازه یک کارت مستقل در موبایل */
    {
      sel: '#shiftsTable', pattern: 'cards',
      titleFrom: ['عنوان'], actionsFrom: ['تغییرات'],
      primary: '.edit-btn',
      visibleFrom: ['از روز', 'تا روز', 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'],
      detailsTitle: 'جزئیات بازهٔ شیفت', detailsLabel: 'مشاهده جزئیات شیفت'
    },
    /* گزارش خلاصهٔ مرخصی‌ها: ۳ ستون عددی مقایسه‌ای → جدول (keep) */
    { sel: '.vacation-table', pattern: 'keep', label: 'گزارش مرخصی‌ها' },
    /* گزارش انفرادی مرخصی → کارت */
    {
      sel: '.management-box .individual-report-table', pattern: 'cards',
      titleFrom: ['نام کاربر'], statusFrom: ['وضعیت درخواست'], actionsFrom: ['ثبت تغییرات'],
      primary: '.update-button',
      visibleFrom: ['از تاریخ', 'تا تاریخ', 'تعداد روز'],
      detailsTitle: 'جزئیات مرخصی'
    },
    /* مدیریت اضافه‌کاری → کارت */
    {
      sel: '#overTimeRequestTable', pattern: 'cards',
      titleFrom: ['نام کاربر'], statusFrom: ['وضعیت درخواست'], actionsFrom: ['ثبت تغییرات'],
      primary: '.update-button',
      visibleFrom: ['تاریخ درخواست', 'مدت زمان اضافه کاری', 'توضیحات'],
      detailsTitle: 'جزئیات اضافه‌کاری'
    },
    /* گزارش کلی اضافه‌کاری: ۳ ستون → جدول (keep) */
    { sel: '#overTimeReportTable.overTime-allreport-table', pattern: 'keep', label: 'گزارش کلی اضافه‌کاری' },
    /* گزارش انفرادی اضافه‌کاری → کارت */
    {
      sel: '#overTimeIndivisualReportTable', pattern: 'cards',
      titleFrom: ['نام کاربر'], statusFrom: ['وضعیت درخواست'], actionsFrom: ['ثبت تغییرات'],
      primary: '.confirm-changes-btn',
      visibleFrom: ['تاریخ درخواست', 'مدت زمان اضافه کاری'],
      detailsTitle: 'جزئیات اضافه‌کاری'
    },
    /* مدیریت پاس‌های ساعتی → کارت */
    {
      sel: '#hourlyPassReportTable', pattern: 'cards',
      titleFrom: ['نام کاربر'], statusFrom: ['وضعیت درخواست'], actionsFrom: ['ثبت تغییرات'],
      primary: '.update-button',
      visibleFrom: ['عنوان پاس', 'مدت زمان پاس', 'تاریخ درخواست'],
      detailsTitle: 'جزئیات پاس ساعتی'
    },
    /* گزارش کلی پاس‌ها: ۳ ستون → جدول (keep) */
    { sel: '#hourlyPassTotaluserReportTable', pattern: 'keep', label: 'گزارش کلی پاس‌های ساعتی' },
    /* گزارش انفرادی پاس → کارت */
    {
      sel: '#hourlyPassIndivisualuserReportTable', pattern: 'cards',
      titleFrom: ['نام کاربر'], statusFrom: ['وضعیت درخواست'], actionsFrom: ['ثبت تغییرات'],
      primary: '.update-button',
      visibleFrom: ['عنوان پاس', 'مدت زمان پاس', 'تاریخ درخواست'],
      detailsTitle: 'جزئیات پاس ساعتی'
    },
    /* مدیریت تیکت‌ها → کارت تیکت پشتیبانی */
    {
      sel: '#ticketUsersReportTable', pattern: 'cards',
      titleFrom: ['عنوان'], statusFrom: ['وضعیت تیکت'], actionsFrom: ['ثبت تغییرات'],
      primary: '.view-btn',
      visibleFrom: ['نام کاربر', 'تاریخ ثبت'],
      detailsTitle: 'جزئیات تیکت', detailsLabel: 'مشاهده تیکت'
    },
    /* حضور و غیاب ادمین: هر روز یک رکورد مستقل در موبایل */
    {
      sel: '#hozoorbox #hozoorUsersReportTable', pattern: 'cards',
      titleFrom: ['تاریخ ثبت'],
      visibleFrom: ['روز هفته', 'زمان ورود', 'زمان خروج', 'مجموع زمان حضور', 'اضافه کاری'],
      detailsTitle: 'جزئیات حضور و غیاب', detailsLabel: 'مشاهده جزئیات حضور'
    },
    /* جداول کوچک داشبورد: ۳ ستون → جدول (keep) */
    { sel: '.dashboard-table', pattern: 'keep', label: 'جدول داشبورد' },

    /* ----- صفحهٔ گزارش نهایی (تحلیلی/چاپی) → Pattern B ----- */
    {
      sel: '.hozoorBox #hozoorUsersReportTable', pattern: 'scroll',
      stickyStart: 2, stickyHead: true, maxHeight: '60vh', minWidth: 920,
      label: 'جدول حضور و غیاب'
    },
    {
      sel: '#ezafeKarUsersReportTable', pattern: 'scroll',
      stickyEnd: 2, minWidth: 560, label: 'جدول اضافه‌کار'
    },
    {
      sel: '#morkhcUsersReportTable', pattern: 'scroll',
      stickyEnd: 2, minWidth: 520, label: 'جدول مرخصی'
    },
    {
      sel: '#hourlyPassUsersReportTable', pattern: 'scroll',
      stickyEnd: 2, minWidth: 460, label: 'جدول پاس‌های ساعتی'
    },

    /* ----- صفحات گزارش انفرادی چاپی → کارت (چاپ → بازیابی جدول) ----- */
    {
      sel: '#PasseSaatiReportTable', pattern: 'cards',
      titleFrom: ['عنوان پاس'],
      visibleFrom: ['تاریخ درخواست', 'مدت زمان پاس'],
      detailsTitle: 'جزئیات پاس ساعتی'
    },
    {
      sel: '#overTimeReportTable.individual-report-table', pattern: 'cards',
      titleFrom: ['تاریخ درخواست'],
      visibleFrom: ['مدت اضافه کاری', 'توضیحات'],
      detailsTitle: 'جزئیات اضافه‌کاری'
    },
    {
      sel: '#reportTableBody', pattern: 'cards',
      titleFrom: ['از تاریخ'],
      visibleFrom: ['تا تاریخ', 'تعداد روز', 'جانشین'],
      detailsTitle: 'جزئیات مرخصی'
    }
  ];

  /* ==========================================================================
   * ۱۰) API عمومی + راه‌اندازی
   * ==========================================================================*/
  window.RT = {
    register: register,
    instances: instances,
    refresh: function () { instances.forEach(function (i) { i.scheduleRender(); if (i.applySticky) i.applySticky(); }); },
    setLoading: function (sel, on) { resolveElements(sel).forEach(function (t) { for (var j = 0; j < instances.length; j++) if (instances[j].table === t) instances[j].setLoading(on); }); }
  };

  /* پس‌زمینهٔ ستون‌های چسبان از getComputedStyle خوانده می‌شود و در لحظهٔ
     راه‌اندازی «قفل» می‌شود؛ با تغییر تم باید دوباره محاسبه شود، وگرنه
     ستون چسبان رنگ تم قبلی را نگه می‌دارد. */
  window.addEventListener('hastama:themechange', function () {
    try { window.RT.refresh(); } catch (e) { log('theme refresh error', e); }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { try { init(); } catch (e) { log('init error', e); } });
  } else {
    try { init(); } catch (e) { log('init error', e); }
  }
})();
