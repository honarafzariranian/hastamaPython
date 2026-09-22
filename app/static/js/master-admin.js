/* ═══════════════════════════════════════════════════════════════
   HASTAMA MASTER ADMIN — Control Center JavaScript
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const BASE = '/master-admin/api';
  const SECTION = document.body.dataset.section || 'dashboard';

  // Keep the auxiliary left rail in sync even when a cached template omits its active class.
  const labelPrinterLink = document.querySelector('.ma-sidebar-left a[href="/master-admin/label-printer"]');
  if (labelPrinterLink && SECTION === 'label-printer') labelPrinterLink.classList.add('active');

  // ── Toast (from toast.js) ────────────────────────────────
  function showToast(msg, type = 'success') {
    window.showToast(msg, type);
  }

  function maConfirm({ title, msg, confirmText = 'تأیید', cancelText = 'انصراف', type = 'danger' } = {}) {
    return new Promise(resolve => {
      const overlay = document.createElement('div');
      overlay.className = 'ma-confirm-overlay';
      overlay.innerHTML = `
        <div class="ma-confirm-box">
          <div class="ma-confirm-box__icon ma-confirm-box__icon--${type}">
            ${type === 'danger' ? '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' : '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'}
          </div>
          <div class="ma-confirm-box__title">${title || 'تأیید عملیات'}</div>
          <div class="ma-confirm-box__msg">${msg || 'آیا مطمئن هستید؟'}</div>
          <div class="ma-confirm-box__actions">
            <button class="ma-btn ma-btn--${type === 'danger' ? 'danger' : 'primary'} ma-btn--sm" id="maConfirmYes">${confirmText}</button>
            <button class="ma-btn ma-btn--ghost ma-btn--sm" id="maConfirmNo">${cancelText}</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);
      requestAnimationFrame(() => overlay.classList.add('is-visible'));
      const cleanup = (result) => {
        overlay.classList.remove('is-visible');
        setTimeout(() => overlay.remove(), 250);
        resolve(result);
      };
      overlay.querySelector('#maConfirmYes').onclick = () => cleanup(true);
      overlay.querySelector('#maConfirmNo').onclick = () => cleanup(false);
      overlay.addEventListener('click', e => { if (e.target === overlay) cleanup(false); });
    });
  }

  // ── API Helper ───────────────────────────────────────────
  function _getCsrfToken() {
    if (window.HastamaCSRF && typeof window.HastamaCSRF.getToken === 'function') {
      return window.HastamaCSRF.getToken();
    }
    var match = document.cookie.match(/(^|;\s*)csrf_token=([^;]*)/);
    return match ? decodeURIComponent(match[2]) : '';
  }

  async function api(path, opts = {}) {
    const url = BASE + path;
    const csrf = _getCsrfToken();
    const method = (opts.method || 'GET').toUpperCase();
    const headers = { 'Content-Type': 'application/json', ...opts.headers };
    if (method !== 'GET' && method !== 'HEAD' && csrf) {
      headers['X-CSRF-Token'] = csrf;
    }
    const fetchOpts = { headers, ...opts };
    if (opts.body && typeof opts.body === 'object') fetchOpts.body = JSON.stringify(opts.body);
    try {
      const res = await fetch(url, fetchOpts);
      const data = await res.json();
      if (!res.ok) throw new Error(data.detail || data.message || 'خطا');
      return data;
    } catch (e) {
      showToast(e.message, 'error');
      throw e;
    }
  }

  // ── Pagination State ─────────────────────────────────────
  /* ── HTML escaping ─────────────────────────────────────────
     Every value that originates from the database or an HTTP header is
     untrusted: names come from the registration form, IP addresses from the
     X-Forwarded-For header, ticket subjects from users.  All of it is rendered
     through esc() before being placed in an innerHTML template. */
  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/`/g, '&#96;')
      .replace(/=/g, '&#61;');
  }
  window.__maEsc = esc;

  const state = { page: 1, perPage: 25, filters: {} };

  function renderPagination(container, total, pages, onPage) {
    container.innerHTML = '';
    if (pages <= 1) return;
    const mk = (label, pg, disabled = false, active = false) => {
      const b = document.createElement('button');
      b.className = 'ma-pagination__btn' + (active ? ' is-active' : '');
      b.textContent = label;
      b.disabled = disabled;
      b.onclick = () => { state.page = pg; onPage(); };
      container.appendChild(b);
    };
    mk('«', state.page - 1, state.page <= 1);
    const start = Math.max(1, state.page - 2);
    const end = Math.min(pages, state.page + 2);
    for (let i = start; i <= end; i++) mk(String(i), i, false, i === state.page);
    mk('»', state.page + 1, state.page >= pages);
  }

  // ── Table Renderer ───────────────────────────────────────
  function renderTable(container, columns, rows, emptyMsg = 'داده‌ای موجود نیست') {
    if (!rows.length) {
      container.innerHTML = `<div class="ma-empty"><div class="ma-empty__icon">📭</div><div class="ma-empty__text">${emptyMsg}</div></div>`;
      return;
    }
    let html = '<div class="ma-table__scroll"><table class="ma-table"><thead><tr>';
    columns.forEach(c => html += `<th>${esc(c.label)}</th>`);
    html += '</tr></thead><tbody>';
    rows.forEach(r => {
      html += '<tr>';
      columns.forEach(c => {
        let val = r[c.key] ?? '—';
        if (c.render) val = c.render(val, r);
        else val = esc(val);
        html += `<td>${val}</td>`;
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
    container.querySelectorAll('[data-ma-action]').forEach(btn => {
      btn.addEventListener('click', handleMaAction);
    });
  }

  function handleMaAction(e) {
    const btn = e.currentTarget;
    const action = btn.dataset.maAction;
    const id = btn.dataset.maId;
    if (action && id) {
      const fn = window['ma_' + action];
      if (fn) fn(id);
    }
  }

  // ── Status Badge ─────────────────────────────────────────
  const STATUS_MAP = {
    active: { label: 'فعال', cls: 'success' },
    disabled: { label: 'غیرفعال', cls: 'danger' },
    admin: { label: 'مدیر', cls: 'purple' },
    user: { label: 'کاربر', cls: 'info' },
    success: { label: 'موفق', cls: 'success' },
    failure: { label: 'ناموفق', cls: 'danger' },
    error: { label: 'خطا', cls: 'danger' },
    info: { label: 'اطلاعات', cls: 'info' },
    pending: { label: 'انتظار', cls: 'warning' },
    approved: { label: 'تأیید شده', cls: 'success' },
    rejected: { label: 'رد شده', cls: 'danger' },
        completed: { label: 'تکمیل شده', cls: 'info' },
    expired: { label: 'منقضی شده', cls: 'neutral' },
    cancelled: { label: 'لغو شده', cls: 'neutral' },
    open: { label: 'باز', cls: 'warning' },
    investigating: { label: 'در حال بررسی', cls: 'info' },
    resolved: { label: 'حل‌شده', cls: 'success' },
    ignored: { label: 'نادیده', cls: 'neutral' },
    false_positive: { label: 'مثبت کاذب', cls: 'neutral' },
    low: { label: 'کم', cls: 'neutral' },
    medium: { label: 'متوسط', cls: 'warning' },
    high: { label: 'زیاد', cls: 'danger' },
    critical: { label: 'بحرانی', cls: 'critical' },
    new: { label: 'جدید', cls: 'info' },
  };

  function badge(val) {
    const s = STATUS_MAP[String(val).toLowerCase()] || { label: val, cls: 'neutral' };
    return `<span class="ma-badge ma-badge--${s.cls}">${esc(s.label)}</span>`;
  }

  function dot(val) {
    const colors = { success: 'green', failure: 'red', error: 'red', warning: 'amber', info: 'blue' };
    return `<span class="ma-dot ma-dot--${colors[val] || 'blue'}"></span>`;
  }

  // ── Label Studio (طراحی و چاپ لیبل نوبت) ─────────────────────
  let labelStudioReady = false;

  function initLabelStudio() {
    const preview = document.getElementById('maLabelPreview');
    if (!preview) return;
    if (labelStudioReady) return;
    labelStudioReady = true;

    const stage = preview.closest('.ma-label-preview-stage');
    const width = document.getElementById('maLabelWidth');
    const height = document.getElementById('maLabelHeight');
    const readout = document.getElementById('maLabelSizeReadout');
    const ratio = document.getElementById('maLabelRatio');
    const template = document.getElementById('maLabelTemplate');
    const timeEl = document.getElementById('maPreviewTime');
    const nameRow = document.getElementById('maPreviewNameRow');
    const hintEl = document.getElementById('maPreviewHint');

    const stored = (() => { try { return JSON.parse(localStorage.getItem('hastama-label-settings') || '{}'); } catch (_) { return {}; } })();
    [width, height, template].forEach(el => { if (el && stored[el.id] != null) el.value = stored[el.id]; });
    if (!stored.maLabelLayoutVersion) {
      width.value = 55;
      height.value = 50;
      stored.maLabelLayoutVersion = 2;
      localStorage.setItem('hastama-label-settings', JSON.stringify(stored));
    }
    ['maShowName', 'maShowTime', 'maShowHint', 'maThermalPreview'].forEach(id => {
      const el = document.getElementById(id);
      if (el && stored[id] != null) el.checked = stored[id];
    });

    const fa = value => String(value).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const clampMm = (value, min, max) => {
      const n = Number(value);
      return Math.min(max, Math.max(min, Number.isFinite(n) ? n : min));
    };

    function printedAt() {
      const now = new Date();
      return `${now.toLocaleDateString('fa-IR')} - ${now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' })}`;
    }

    // اگر محتوا از فضای پیش‌نمایش (عرض یا ارتفاع) تجاوز کند، کل لیبل را کوچک می‌کند (فقط مرورگر)
    function fitLabelPreview() {
      const content = preview.querySelector('.lbl__content');
      if (!content) return;
      const previousHeight = content.style.height;
      content.style.transform = 'scale(1)';
      content.style.height = 'auto';
      content.style.flex = 'none';
      const naturalW = content.scrollWidth;
      const naturalH = content.scrollHeight;
      const availableW = preview.clientWidth - 4;
      const availableH = preview.clientHeight - 4;
      const scale = Math.min(1, availableW / Math.max(1, naturalW), availableH / Math.max(1, naturalH));
      content.style.height = previousHeight || '100%';
      content.style.flex = '';
      content.style.transformOrigin = 'top center';
      content.style.transform = `scale(${Math.max(.58, scale)})`;
    }

    // اگر عرض جعبهٔ شماره کم باشد (لیبل باریک)، اندازهٔ رقم را کم می‌کند تا بریده نشود
    function fitQueueNumber() {
      const numEl = preview.querySelector('.lbl__queue-number');
      const boxEl = preview.querySelector('.lbl__number-box');
      if (!numEl || !boxEl) return;
      numEl.style.fontSize = '';
      const base = parseFloat(window.getComputedStyle(numEl).fontSize) || 26;
      const natural = numEl.scrollWidth;
      const available = boxEl.clientWidth - 24; // حاشیهٔ داخلی + کادر جعبه
      if (natural > available) numEl.style.fontSize = `${Math.max(10, base * available / natural)}px`;
    }

    function refresh() {
      const w = Math.round(clampMm(Number(width.value), 30, 150));
      const h = Math.round(clampMm(Number(height.value), 20, 100));
      const stageWidth = stage ? Math.max(180, stage.clientWidth - 48) : 420;
      const stageHeight = stage ? Math.max(160, stage.clientHeight - 48) : 280;
      const pxPerMm = Math.min(4, stageWidth / w, stageHeight / h);
      preview.style.setProperty('width', `${Math.max(120, Math.round(w * pxPerMm))}px`, 'important');
      preview.style.setProperty('height', `${Math.max(80, Math.round(h * pxPerMm))}px`, 'important');
      preview.style.aspectRatio = `${w} / ${h}`;
      preview.dataset.template = (template && template.value) || 'queue';
      fitLabelPreview();
      fitQueueNumber();
      if (readout) readout.textContent = `${fa(w)} × ${fa(h)} میلی‌متر`;
      if (ratio) ratio.textContent = `نسبت ${fa((w / h).toFixed(2)).replace('.', '٫')}`;
      if (timeEl && timeEl.querySelector('.lbl__datetime-value')) timeEl.querySelector('.lbl__datetime-value').textContent = printedAt();
      if (nameRow) nameRow.hidden = !document.getElementById('maShowName').checked;
      if (timeEl) timeEl.hidden = !document.getElementById('maShowTime').checked;
      if (hintEl) hintEl.hidden = !document.getElementById('maShowHint').checked;
      preview.classList.toggle('is-thermal', document.getElementById('maThermalPreview').checked);
      const settings = { maLabelWidth: w, maLabelHeight: h, maLabelTemplate: (template && template.value) || 'queue' };
      ['maShowName', 'maShowTime', 'maShowHint', 'maThermalPreview'].forEach(id => { settings[id] = document.getElementById(id).checked; });
      localStorage.setItem('hastama-label-settings', JSON.stringify(settings));
    }

    [width, height, template, ...['maShowName', 'maShowTime', 'maShowHint', 'maThermalPreview'].map(id => document.getElementById(id))]
      .filter(Boolean)
      .forEach(el => {
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
      });
    refresh();
    if (window.ResizeObserver) new ResizeObserver(refresh).observe(stage || preview);

    document.getElementById('maResetLabelBtn').addEventListener('click', function () {
      width.value = 55; height.value = 50; template.value = 'queue';
      ['maShowName', 'maShowTime', 'maShowHint'].forEach(id => { document.getElementById(id).checked = true; });
      document.getElementById('maThermalPreview').checked = false;
      refresh();
      showToast('تنظیمات لیبل بازنشانی شد', 'success');
    });

    document.getElementById('maCheckPrinterBtn').addEventListener('click', async function () {
      const status = document.getElementById('maPrinterStatus');
      const text = status.querySelector('.ma-printer-status__text');
      let connected = false;
      try {
        if (navigator.usb && navigator.usb.getDevices) connected = (await navigator.usb.getDevices()).length > 0;
        if (!connected && navigator.serial && navigator.serial.getPorts) connected = (await navigator.serial.getPorts()).length > 0;
      } catch (_) { connected = false; }
      status.dataset.state = connected ? 'connected' : 'disconnected';
      text.textContent = connected ? 'چاپگر متصل و آماده' : 'چاپگر قابل شناسایی نیست';
      showToast(connected ? 'چاپگر آماده استفاده است' : 'چاپگر متصل شناسایی نشد', connected ? 'success' : 'error');
    });

    // چاپ: پنجرهٔ چاپ اختصاصی با ابعاد دقیق بر حسب میلی‌متر و شیووریت چاپ اختصاصی
    function openPrintWindow() {
      const w = Math.round(clampMm(Number(width.value), 30, 150));
      const h = Math.round(clampMm(Number(height.value), 20, 100));
      const contentEl = preview.querySelector('.lbl__content');
      if (!contentEl) return;

      // کلون بدون استایل درون‌خطی (مقیاس پیش‌نمایش به چاپ نشت نکند)
      const clone = contentEl.cloneNode(true);
      clone.removeAttribute('style');
      const root = document.createElement('div');
      root.className = 'lbl lbl--print';
      root.setAttribute('data-template', preview.dataset.template || 'queue');
      root.style.setProperty('--lbl-mm-w', String(w));
      root.style.setProperty('--lbl-mm-h', String(h));
      root.appendChild(clone);

      const win = window.open('', '_blank', 'width=560,height=700');
      if (!win) { showToast('پنجره چاپ توسط مرورگر مسدود شد', 'error'); return; }

      const fitGuard = '(function(){try{var r=document.querySelector(".lbl");var c=document.querySelector(".lbl__content");if(!r||!c)return;'
        + 'var n=document.querySelector(".lbl__queue-number");var b=document.querySelector(".lbl__number-box");'
        + 'if(n&&b){n.style.fontSize="";var base=parseFloat(window.getComputedStyle(n).fontSize)||26;var nat=n.scrollWidth;var av=b.clientWidth-24;if(nat>av){n.style.fontSize=Math.max(10,base*av/nat)+"px";}}'
        + 'var h=c.style.height;c.style.height="auto";c.style.flex="none";var cw=c.scrollWidth,ch=c.scrollHeight;c.style.height=h||"100%";c.style.flex="";'
        + 'var sx=(r.clientWidth-4)/Math.max(1,cw);var sy=(r.clientHeight-4)/Math.max(1,ch);var s=Math.min(1,sx,sy);'
        + 'if(s<0.995){c.style.transformOrigin="top center";c.style.transform="scale("+Math.max(0.5,s).toFixed(3)+")";}}catch(e){}})();';
      win.document.write([
        '<!doctype html>',
        '<html lang="fa" dir="rtl"><head><meta charset="utf-8">',
        '<title>چاپ لیبل نوبت — ' + fa(w) + ' × ' + fa(h) + ' میلی‌متر</title>',
        '<style>@page{size:' + w + 'mm ' + h + 'mm;margin:0}</style>',
        '<link rel="stylesheet" href="/static/css/vazir.css">',
        '<link rel="stylesheet" href="/static/css/label-print.css">',
        '</head><body class="lbl-print-page">',
        root.outerHTML,
        '<script>' + fitGuard + '<\/script>',
        '</body></html>'
      ].join(''));
      win.document.close();
      win.focus();

      // صبر برای آماده‌شدن فونت و لوگوها و سپس باز کردن دیالوگ چاپ
      let printed = false;
      const doPrint = () => { if (printed) return; printed = true; try { win.print(); } catch (e) {} };
      const readyJobs = Array.prototype.slice.call(win.document.images)
        .map(img => img.complete ? Promise.resolve() : new Promise(res => { img.onload = img.onerror = res; }));
      if (win.document.fonts && win.document.fonts.ready) readyJobs.push(win.document.fonts.ready);
      Promise.all(readyJobs).then(doPrint);
      setTimeout(doPrint, 2500);
    }

    document.getElementById('maPrintLabelBtn').addEventListener('click', openPrintWindow);
  }

  // ── Dashboard ────────────────────────────────────────────
  async function loadDashboard() {
    try {
      const [statsRes, activityRes] = await Promise.all([
        api('/dashboard/stats'),
        api('/dashboard/activity?limit=30'),
      ]);
      const s = statsRes.data;
      // تبدیل اعداد انگلیسی به فارسی
      function toFa(n) {
        const fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        return String(n).replace(/[0-9]/g, d => fa[d]);
      }

      const statsEl = document.getElementById('maStats');
      if (statsEl) {
        const cards = [
          { icon: '👥', value: s.total_users, label: 'کل کاربران', gradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', delay: 0, link: '/master-admin/users' },
          { icon: '🟢', value: s.active_users, label: 'کاربران فعال', gradient: 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)', delay: 50 },
          { icon: '🔗', value: s.online_sessions, label: 'نشست‌های فعال', gradient: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)', delay: 100, link: '/master-admin/sessions' },
          { icon: '🔑', value: s.logins_today, label: 'ورودهای امروز', gradient: 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)', delay: 150, link: '/master-admin/audit-logs' },
          { icon: '⚠️', value: s.failed_logins_today, label: 'ورود ناموفق', gradient: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)', alert: s.failed_logins_today > 0, delay: 200, link: '/master-admin/security' },
          { icon: '🔐', value: s.pending_password_resets, label: 'بازیابی رمز', gradient: 'linear-gradient(135deg, #fccb90 0%, #d57eeb 100%)', alert: s.pending_password_resets > 0, delay: 250, link: '/master-admin/password-resets' },
          { icon: '🛡️', value: s.open_security_events, label: 'رویداد امنیتی', gradient: 'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)', alert: s.open_security_events > 0, delay: 300, link: '/master-admin/security' },
          { icon: '🐛', value: s.open_errors, label: 'خطاهای باز', gradient: 'linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%)', alert: s.open_errors > 0, delay: 350, link: '/master-admin/errors' },
          { icon: '🎫', value: s.open_tickets, label: 'تیکت‌های باز', gradient: 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)', delay: 400, link: '/master-admin/tickets' },
          { icon: '📋', value: s.events_today, label: 'رویدادهای امروز', gradient: 'linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%)', delay: 450, link: '/master-admin/audit-logs' },
        ];
        statsEl.innerHTML = cards.map((c, i) => `
          <div class="ma-glow-card" style="animation-delay:${c.delay}ms;--card-gradient:${c.gradient}">
            <div class="ma-glow-card__shine"></div>
            ${c.link
              ? `<a href="${c.link}" class="ma-glow-card__content" style="text-decoration:none;color:inherit;cursor:pointer">`
              : `<div class="ma-glow-card__content" style="text-decoration:none;color:inherit;cursor:default">`}
              <div class="ma-glow-card__top">
                <div class="ma-glow-card__icon">${c.icon}</div>
                ${c.alert ? '<div class="ma-glow-card__alert"></div>' : ''}
              </div>
              <div class="ma-glow-card__value" data-count="${c.value}">${toFa(c.value)}</div>
              <div class="ma-glow-card__label">${c.label}</div>
            ${c.link ? '</a>' : '</div>'}
          </div>
        `).join('');
        // Animate numbers
        statsEl.querySelectorAll('.ma-glow-card__value').forEach(el => {
          const target = parseInt(el.dataset.count) || 0;
          if (target === 0) return;
          let current = 0;
          const step = Math.max(1, Math.floor(target / 20));
          const timer = setInterval(() => {
            current += step;
            if (current >= target) { current = target; clearInterval(timer); }
            el.textContent = toFa(current);
          }, 30);
        });
      }

      // Activity feed
      const actEl = document.getElementById('maActivity');
      if (actEl && activityRes.data) {
        if (!activityRes.data.length) {
          actEl.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">📭</div><div class="ma-empty__text">هنوز رویدادی ثبت نشده است</div></div>';
        } else {
          let html = '<div class="ma-timeline">';
          activityRes.data.forEach(e => {
            const dotCls = e.status === 'failure' ? 'danger' : e.status === 'error' ? 'danger' : e.severity === 'high' || e.severity === 'critical' ? 'warning' : 'success';
            const time = e.created_at ? new Date(e.created_at).toLocaleTimeString('fa-IR') : '';
            html += `<div class="ma-timeline__item"><div class="ma-timeline__dot ma-timeline__dot--${dotCls}"></div><div class="ma-timeline__time">${esc(time)}</div><div class="ma-timeline__text">${esc(e.status)} <strong>${esc(e.username || '—')}</strong> ${esc(e.action)} ${e.module ? 'در ' + esc(e.module) : ''}</div><div class="ma-timeline__meta">${esc(e.event_id)} · ${esc(e.ip_address || '—')}</div></div>`;
          });
          html += '</div>';
          actEl.innerHTML = html;
        }
      }
    } catch (e) { /* toast already shown */ }
  }

  // ── Audit Logs ───────────────────────────────────────────
  async function loadAuditLogs() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    Object.entries(state.filters).forEach(([k, v]) => { if (v) params.set(k, v); });
    try {
      const res = await api(`/audit-logs?${params}`);
      const cols = [
        { key: 'event_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${esc(v)}</code>` },
        { key: 'created_at', label: 'زمان', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'event_type', label: 'نوع', render: v => badge(v) },
        { key: 'action', label: 'عملیات' },
        { key: 'username', label: 'کاربر' },
        { key: 'module', label: 'ماژول' },
        { key: 'severity', label: 'اولویت', render: v => badge(v) },
        { key: 'status', label: 'وضعیت', render: v => badge(v) },
        { key: 'ip_address', label: 'IP' },
      ];
      renderTable(container, cols, res.data, 'لاگ حسابرسی موجود نیست');
      renderPagination(pagEl, res.total, res.pages, loadAuditLogs);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری لاگ‌ها</div></div>'; }
  }

  // ── Users ────────────────────────────────────────────────
  async function loadUsers() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    Object.entries(state.filters).forEach(([k, v]) => { if (v) params.set(k, v); });
    try {
      const res = await api(`/users?${params}`);
      const cols = [
        { key: 'id', label: 'ID' },
        { key: 'username', label: 'نام کاربری' },
        { key: 'name', label: 'نام', render: (v, r) => esc(`${v || ''} ${r.last_name || ''}`.trim()) },
        { key: 'department', label: 'بخش' },
        { key: 'role', label: 'نقش', render: v => badge(v) },
        { key: 'is_active', label: 'وضعیت', render: v => badge(v || 'active') },
        { key: 'last_login', label: 'آخرین ورود', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'username', label: 'عملیات', render: v => `<a href="#" class="ma-btn ma-btn--ghost ma-btn--sm" data-ma-action="viewUser" data-ma-id="${esc(encodeURIComponent(String(v || '').trim()))}">مشاهده</a>` },
      ];
      renderTable(container, cols, res.data, 'کاربری یافت نشد');
      renderPagination(pagEl, res.total, res.pages, loadUsers);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری کاربران</div></div>'; }
  }

  // ── Customer subscriptions ──────────────────────────────
  async function loadSubscriptions() {
    const container = document.getElementById('maSubscriptionTable');
    const pagEl = document.getElementById('maSubscriptionPagination');
    if (!container) return;
    const fa = value => String(value ?? 0).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const date = value => value ? new Date(value).toLocaleDateString('fa-IR') : '—';
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    const search = document.getElementById('subscriptionSearch');
    const status = document.getElementById('subscriptionStatus');
    if (search && search.value.trim()) params.set('search', search.value.trim());
    if (status && status.value) params.set('status', status.value);
    try {
      const [summaryRes, listRes] = await Promise.all([
        api('/subscriptions/summary'),
        api(`/subscriptions?${params}`),
      ]);
      const summary = summaryRes.data || {};
      const stats = document.getElementById('maSubscriptionStats');
      if (stats) {
        const cards = [
          ['کل مشتریان', summary.total_customers, 'ثبت‌شده در سامانه'],
          ['اشتراک‌های فعال', summary.active_subscriptions, 'در حال استفاده'],
          ['در آستانه تمدید', summary.expiring_soon, 'کمتر از ۳۰ روز'],
          ['ظرفیت کاربران', summary.total_seats_used + ' / ' + summary.total_seats, 'کاربر فعال / ظرفیت'],
        ];
        stats.innerHTML = cards.map(c => `<div class="ma-subscription-stat"><span class="ma-subscription-stat__label">${esc(c[0])}</span><strong class="ma-subscription-stat__value">${esc(fa(c[1] ?? '—'))}</strong><span class="ma-subscription-stat__meta">${esc(c[2])}</span></div>`).join('');
      }
      const cols = [
        { key: 'customer_name', label: 'مشتری', render: (v, r) => `<div class="ma-subscription-customer"><strong>${esc(v || 'بدون نام')}</strong><small>${esc(r.customer_code || r.contact || '—')}</small></div>` },
        { key: 'plan_name', label: 'طرح', render: v => esc(v || '—') },
        { key: 'status', label: 'وضعیت', render: (v, r) => badge(v || r.computed_status || 'unknown') },
        { key: 'purchased_at', label: 'تاریخ خرید', render: v => date(v) },
        { key: 'expires_at', label: 'تاریخ پایان', render: v => date(v) },
        { key: 'remaining_days', label: 'زمان باقی‌مانده', render: (v, r) => `<span class="ma-subscription-days ${Number(v) >= 0 && Number(v) <= 30 ? 'ma-subscription-days--urgent' : ''} ${Number(v) < 0 ? 'ma-subscription-days--expired' : ''}">${Number(v) < 0 ? 'منقضی شده' : esc(fa(v)) + ' روز'}</span>` },
        { key: 'seats_used', label: 'کاربران', render: (v, r) => {
          const used = Number(v) || 0, total = Number(r.max_users) || 0, pct = total ? Math.min(100, Math.round(used * 100 / total)) : 0;
          return `<div class="ma-subscription-progress"><div class="ma-subscription-progress__track"><div class="ma-subscription-progress__fill" style="width:${pct}%"></div></div><div class="ma-subscription-progress__label"><span>${esc(fa(used))} فعال</span><span>${esc(fa(total))} ظرفیت</span></div></div>`;
        }},
      ];
      renderTable(container, cols, listRes.data || [], 'اشتراک ثبت‌شده‌ای یافت نشد');
      container.querySelectorAll('tbody tr').forEach((row, index) => {
        const customer = listRes.data[index];
        if (customer) {
          row.classList.add('ma-subscription-row');
          row.addEventListener('click', () => window.ma_viewSubscription(customer.id));
        }
      });
      renderPagination(pagEl, listRes.total || 0, listRes.pages || 1, loadSubscriptions);
    } catch (e) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">اطلاعات اشتراک‌ها در دسترس نیست؛ ابتدا مهاجرت پایگاه‌داده را اجرا کنید.</div></div>';
    }
  }

  function closeSubscriptionDetail() {
    const overlay = document.getElementById('maSubscriptionDetailOverlay');
    if (!overlay) return;
    document.querySelectorAll('.ma-customer-date-picker').forEach(picker => picker.remove());
    overlay.classList.remove('is-visible');
    setTimeout(() => overlay.remove(), 220);
  }

  function maPersianParts(dateValue) {
    const parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC' }).formatToParts(dateValue);
    const values = Object.fromEntries(parts.filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
    const digits = value => Number(String(value).replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)));
    return { year: digits(values.year), month: digits(values.month), day: digits(values.day) };
  }

  function maPersianToGregorian(year, month, day) {
    let low = Date.UTC(2000, 0, 1), high = Date.UTC(2050, 0, 1), target = year * 10000 + month * 100 + day;
    while (low <= high) {
      const mid = Math.floor((low + high) / 2);
      const current = maPersianParts(new Date(mid));
      const key = current.year * 10000 + current.month * 100 + current.day;
      if (key === target) return new Date(mid);
      if (key < target) low = mid + 86400000;
      else high = mid - 86400000;
    }
    return new Date(Date.UTC(year, month - 1, day));
  }

  function maAttachDatePicker(form, displayName, hiddenName, isoValue) {
    const input = form.querySelector(`[data-date-display="${displayName}"]`);
    const hidden = form.querySelector(`input[name="${hiddenName}"]`);
    if (!input || !hidden) return;
    const monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const dayNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    const selected = isoValue ? maPersianParts(new Date(`${isoValue}T00:00:00Z`)) : maPersianParts(new Date());
    const state = { year: selected.year, month: selected.month, day: selected.day };
    let picker = document.querySelector(`.ma-customer-date-picker[data-input-id="${input.id || displayName}"]`);
    if (!picker) {
      picker = document.createElement('div');
      picker.className = 'leave-date-picker ma-customer-date-picker';
      picker.hidden = true;
      picker.setAttribute('role', 'dialog');
      picker.setAttribute('aria-label', 'انتخاب تاریخ');
      document.body.appendChild(picker);
    }
    const digits = value => String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
    const monthLength = (year, month) => month <= 6 ? 31 : month <= 11 ? 30 : (((year * 8 + 13) % 33) < 8 ? 30 : 29);
    const render = () => {
      const weekday = maPersianToGregorian(state.year, state.month, 1).getUTCDay();
      const offset = weekday === 6 ? 0 : weekday + 1;
      const cells = Array.from({ length: offset }, () => '<div class="leave-date-picker-day is-empty"></div>');
      for (let day = 1; day <= monthLength(state.year, state.month); day += 1) cells.push(`<button type="button" class="leave-date-picker-day${state.day === day ? ' is-selected' : ''}" data-action="select-day" data-day="${day}">${digits(day)}</button>`);
      const remainder = (7 - (cells.length % 7)) % 7;
      for (let index = 0; index < remainder; index += 1) cells.push('<div class="leave-date-picker-day is-empty"></div>');
      picker.innerHTML = `<div class="leave-date-picker-header"><button type="button" class="leave-date-picker-nav" data-action="prev-month">‹</button><div class="leave-date-picker-controls"><select id="${picker.dataset.inputId}-month" name="${picker.dataset.inputId}-month" class="leave-date-picker-month" data-action="month-change">${monthNames.map((name, i) => `<option value="${i + 1}" ${state.month === i + 1 ? 'selected' : ''}>${name}</option>`).join('')}</select><select id="${picker.dataset.inputId}-year" name="${picker.dataset.inputId}-year" class="leave-date-picker-year" data-action="year-change">${Array.from({ length: 21 }, (_, i) => state.year - 10 + i).map(year => `<option value="${year}" ${state.year === year ? 'selected' : ''}>${digits(year)}</option>`).join('')}</select></div><button type="button" class="leave-date-picker-nav" data-action="next-month">›</button></div><div class="leave-date-picker-weekdays">${dayNames.map(name => `<div class="leave-date-picker-weekday">${name}</div>`).join('')}</div><div class="leave-date-picker-days">${cells.join('')}</div>`;
      picker.querySelector('[data-action="prev-month"]').onclick = () => { state.month -= 1; if (state.month < 1) { state.month = 12; state.year -= 1; } render(); };
      picker.querySelector('[data-action="next-month"]').onclick = () => { state.month += 1; if (state.month > 12) { state.month = 1; state.year += 1; } render(); };
      picker.querySelector('.leave-date-picker-month').onchange = event => { state.month = Number(event.target.value); render(); };
      picker.querySelector('.leave-date-picker-year').onchange = event => { state.year = Number(event.target.value); render(); };
      picker.querySelectorAll('[data-day]').forEach(button => { button.onclick = () => { state.day = Number(button.dataset.day); hidden.value = maPersianToGregorian(state.year, state.month, state.day).toISOString().slice(0, 10); input.value = `${digits(state.year)}/${digits(String(state.month).padStart(2, '0'))}/${digits(String(state.day).padStart(2, '0'))}`; picker.hidden = true; }; });
    };
    picker.dataset.inputId = input.id || displayName;
    input.dataset.datePickerBound = 'true';
    input.value = `${digits(state.year)}/${digits(String(state.month).padStart(2, '0'))}/${digits(String(state.day).padStart(2, '0'))}`;
    input.onclick = event => {
      event.stopPropagation();
      const rect = input.getBoundingClientRect();
      const pickerWidth = 320;
      const left = Math.max(8, Math.min(rect.right - pickerWidth, window.innerWidth - pickerWidth - 8));
      picker.style.top = `${rect.bottom + 6}px`;
      picker.style.left = `${left}px`;
      picker.style.transform = '';
      picker.hidden = false;
      render();
    };
    input.onfocus = input.onclick;
    render();
  }

  window.ma_viewSubscription = async function (id) {
    closeSubscriptionDetail();
    const overlay = document.createElement('div');
    overlay.id = 'maSubscriptionDetailOverlay';
    overlay.className = 'ma-detail-overlay';
    overlay.innerHTML = `
      <section class="ma-detail-modal" role="dialog" aria-modal="true" aria-labelledby="maSubscriptionDetailTitle">
        <button type="button" class="ma-detail-modal__close" aria-label="بستن">×</button>
        <div class="ma-detail-modal__body"><div class="ma-detail-loading">در حال بارگذاری اطلاعات مشتری...</div></div>
      </section>`;
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('is-visible'));
    overlay.querySelector('.ma-detail-modal__close').onclick = closeSubscriptionDetail;
    overlay.addEventListener('click', e => { if (e.target === overlay) closeSubscriptionDetail(); });
    try {
      const res = await api(`/subscriptions/${encodeURIComponent(id)}`);
      const d = res.data || {};
      const fa = value => String(value ?? '—').replace(/[0-9]/g, n => '۰۱۲۳۴۵۶۷۸۹'[n]);
      const date = value => value ? new Date(value).toLocaleDateString('fa-IR') : '—';
      const val = key => esc(d[key] ?? '');
      const day = key => esc(d[key] ? String(d[key]).slice(0, 10) : '');
      const users = (d.users || []).map(user => `<li><strong>${esc(`${user.name || ''} ${user.last_name || ''}`.trim() || user.username)}</strong><span>${esc(user.department || user.role || '—')}</span></li>`).join('');
      overlay.querySelector('.ma-detail-modal__body').innerHTML = `
        <div class="ma-detail-modal__eyebrow">CUSTOMER PROFILE</div>
        <h2 id="maSubscriptionDetailTitle">ویرایش اطلاعات مشتری</h2>
        <form class="ma-detail-form" id="maSubscriptionDetailForm">
          <label>شناسه مشتری<input name="customer_id" value="${val('customer_id')}" required></label>
          <label>کد مشتری<input name="customer_code" value="${val('customer_code')}"></label>
          <label>نام مشتری<input name="customer_name" value="${val('customer_name')}" required></label>
          <label>نام رابط<input name="contact_name" value="${val('contact_name')}"></label>
          <label>ایمیل<input type="email" name="contact_email" value="${val('contact_email')}"></label>
          <label>تلفن<input name="contact_phone" value="${val('contact_phone')}"></label>
          <label>طرح<input name="plan_name" value="${val('plan_name')}" required></label>
          <label>وضعیت<select name="subscription_status"><option value="active" ${d.subscription_status === 'active' ? 'selected' : ''}>فعال</option><option value="suspended" ${d.subscription_status === 'suspended' ? 'selected' : ''}>متوقف</option><option value="expired" ${d.subscription_status === 'expired' ? 'selected' : ''}>منقضی</option></select></label>
          <label>تاریخ شروع<div class="date-input-shell"><input type="text" data-date-display="starts_at" placeholder="۱۴۰۳/۰۱/۰۱" autocomplete="off" readonly required><input type="hidden" name="starts_at" value="${day('starts_at')}"><div class="leave-date-picker" hidden></div></div></label>
          <label>تاریخ پایان<div class="date-input-shell"><input type="text" data-date-display="expires_at" placeholder="۱۴۰۳/۰۱/۰۱" autocomplete="off" readonly required><input type="hidden" name="expires_at" value="${day('expires_at')}"><div class="leave-date-picker" hidden></div></div></label>
          <label>ظرفیت کاربران<input type="number" min="0" name="max_users" value="${val('max_users')}"></label>
          <label>مبلغ<input type="number" min="0" step="0.01" name="price" value="${val('price')}"></label>
          <label>واحد پول<input name="currency" value="${val('currency')}"></label>
          <label>روش پرداخت<input name="payment_method" value="${val('payment_method')}"></label>
          <label>شماره فاکتور<input name="invoice_number" value="${val('invoice_number')}"></label>
          <label class="ma-detail-form__wide">یادداشت<textarea name="notes" rows="3">${val('notes')}</textarea></label>
          <div class="ma-detail-form__actions"><button type="submit" class="ma-btn ma-btn--primary">ذخیره تغییرات</button><span class="ma-detail-users-count">کاربران زیرمجموعه: ${fa((d.users || []).length)}</span></div>
        </form>
        <div class="ma-detail-users"><h3>کاربران زیرمجموعه</h3>${users ? `<ul>${users}</ul>` : '<p>کاربر ثبت‌شده‌ای برای این مشتری پیدا نشد.</p>'}</div>`;
      const form = overlay.querySelector('#maSubscriptionDetailForm');
      maAttachDatePicker(form, 'starts_at', 'starts_at', day('starts_at'));
      maAttachDatePicker(form, 'expires_at', 'expires_at', day('expires_at'));
      overlay.querySelector('#maSubscriptionDetailForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.max_users = payload.max_users === '' ? 0 : Number(payload.max_users);
        payload.price = payload.price === '' ? null : payload.price;
        try {
          await api(`/subscriptions/${encodeURIComponent(id)}`, { method: 'PATCH', body: payload });
          showToast('اطلاعات مشتری ذخیره شد');
          closeSubscriptionDetail();
          loadSubscriptions();
        } catch (e) { /* toast shown */ }
      });
    } catch (e) {
      const body = overlay.querySelector('.ma-detail-modal__body');
      if (body) body.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">اطلاعات مشتری در دسترس نیست.</div></div>';
    }
  };

  // ── Sessions ─────────────────────────────────────────────
  async function loadSessions() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage, active_only: 'true' });
    try {
      const res = await api(`/sessions?${params}`);
      const cols = [
        { key: 'username', label: 'کاربر' },
        { key: 'ip_address', label: 'IP' },
        { key: 'login_at', label: 'زمان ورود', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'last_activity', label: 'آخرین فعالیت', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'is_active', label: 'وضعیت', render: v => v ? badge('active') : badge('disabled') },
        { key: 'session_key', label: 'عملیات', render: v => `<button class="ma-btn ma-btn--danger ma-btn--sm" data-ma-action="terminateSession" data-ma-id="${esc(v)}">خاتمه</button>` },
      ];
      renderTable(container, cols, res.data, 'نشست فعالی موجود نیست');
      renderPagination(pagEl, res.total, res.pages, loadSessions);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری نشست‌ها</div></div>'; }
  }

  // ── Password Resets ──────────────────────────────────────
  async function loadPasswordResets() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    if (state.filters.status) params.set('status', state.filters.status);
    try {
      const res = await api(`/password-resets?${params}`);
      const cols = [
        { key: 'request_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${esc(v)}</code>` },
        { key: 'username', label: 'کاربر' },
        { key: 'created_at', label: 'زمان', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'ip_address', label: 'IP' },
        { key: 'status', label: 'وضعیت', render: v => badge(v) },
        { key: 'code_attempts', label: 'تلاش‌ها' },
        { key: 'request_id', label: 'عملیات', render: (v, r) => r.status === 'pending' ? `<button class="ma-btn ma-btn--primary ma-btn--sm" data-ma-action="approveReset" data-ma-id="${esc(v)}">تأیید</button> <button class="ma-btn ma-btn--danger ma-btn--sm" data-ma-action="rejectReset" data-ma-id="${esc(v)}">رد</button>` : '—' },
      ];
      renderTable(container, cols, res.data, 'درخواست بازیابی موجود نیست');
      renderPagination(pagEl, res.total, res.pages, loadPasswordResets);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری درخواست‌ها</div></div>'; }
  }

  // ── Security Events ──────────────────────────────────────
  async function loadSecurity() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    if (state.filters.severity) params.set('severity', state.filters.severity);
    if (state.filters.status) params.set('status', state.filters.status);
    try {
      const res = await api(`/security?${params}`);
      const cols = [
        { key: 'event_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${v}</code>` },
        { key: 'created_at', label: 'زمان', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'event_type', label: 'نوع' },
        { key: 'severity', label: 'اولویت', render: v => badge(v) },
        { key: 'username', label: 'کاربر' },
        { key: 'description', label: 'توضیحات', render: v => (v || '').substring(0, 80) },
        { key: 'status', label: 'وضعیت', render: v => badge(v) },
        { key: 'event_id', label: 'عملیات', render: (v, r) => r.status === 'open' ? `<button class="ma-btn ma-btn--primary ma-btn--sm" data-ma-action="resolveSecurity" data-ma-id="${esc(v)}">بررسی شد</button>` : '—' },
      ];
      renderTable(container, cols, res.data, 'رویداد امنیتی موجود نیست');
      renderPagination(pagEl, res.total, res.pages, loadSecurity);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری رویدادها</div></div>'; }
  }

  // ── Errors ───────────────────────────────────────────────
  async function loadErrors() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    if (state.filters.severity) params.set('severity', state.filters.severity);
    if (state.filters.status) params.set('status', state.filters.status);
    try {
      const res = await api(`/errors?${params}`);
      const cols = [
        { key: 'error_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${esc(v)}</code>` },
        { key: 'first_seen', label: 'زمان', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'error_type', label: 'نوع', render: v => badge(v) },
        { key: 'severity', label: 'اولویت', render: v => badge(v) },
        { key: 'message', label: 'پیام', render: v => (v || '').substring(0, 80) },
        { key: 'username', label: 'کاربر' },
        { key: 'occurrences', label: 'تعداد' },
        { key: 'status', label: 'وضعیت', render: v => badge(v) },
      ];
      renderTable(container, cols, res.data, 'خطایی ثبت نشده است');
      renderPagination(pagEl, res.total, res.pages, loadErrors);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری خطاها</div></div>'; }
  }

  // ── User Detail (360 View) ──────────────────────────────
  var _viewingUser = null;
  window.__maViewUser = function(u) {
    _viewingUser = u;
    window.location.hash = 'user-detail';
  };

  async function loadUserDetail() {
    const container = document.getElementById('maUserDetail');
    if (!container) return;
    const targetUsername = _viewingUser || new URLSearchParams(window.location.search).get('u');
    if (!targetUsername) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">👤</div><div class="ma-empty__text">نام کاربری مشخص نشده است</div></div>';
      return;
    }
    try {
      const res = await api(`/users/${encodeURIComponent(targetUsername)}`);
      const u = res.data;
      const auditRows = (u.recent_audit || []).slice(0, 20);
      const sessions = (u.sessions || []).slice(0, 10);
      const resets = (u.password_resets || []).slice(0, 5);
      container.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
          <a href="/master-admin/users" class="ma-btn ma-btn--ghost" style="font-size:0.82rem">← بازگشت به کاربران</a>
          <h2 style="margin:0;font-size:1.1rem;font-weight:800;color:#0f172a">${esc(u.username)} — ${esc(u.name || '')} ${esc(u.last_name || '')}</h2>
          ${badge(u.role)} ${badge(u.is_active || 'active')}
        </div>
        <div class="ma-grid-2" style="margin-bottom:20px">
          <div class="ma-panel-card">
            <div class="ma-panel-card__header"><div class="ma-panel-card__title">📋 اطلاعات حساب</div></div>
            <div class="ma-panel-card__body">
              <table style="width:100%;font-size:0.82rem;border-collapse:collapse">
                <tr><td style="padding:6px 0;color:#64748b;width:140px">نام کاربری</td><td style="padding:6px 0;font-weight:600">${esc(u.username)}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">نام</td><td style="padding:6px 0">${esc(u.name || '—')} ${esc(u.last_name || '')}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">بخش</td><td style="padding:6px 0">${esc(u.department || '—')}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">ساعت کاری</td><td style="padding:6px 0">${esc(u.work_hours || '—')}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">آخرین ورود</td><td style="padding:6px 0">${u.last_login ? new Date(u.last_login).toLocaleString('fa-IR') : '—'}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">تلاش ناموفق ورود</td><td style="padding:6px 0">${u.failed_login_count || 0}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">آخرین تغییر رمز</td><td style="padding:6px 0">${u.password_changed_at ? new Date(u.password_changed_at).toLocaleString('fa-IR') : '—'}</td></tr>
              </table>
            </div>
          </div>
          <div class="ma-panel-card">
            <div class="ma-panel-card__header"><div class="ma-panel-card__title">🔐 نشست‌ها</div></div>
            <div class="ma-panel-card__body">
              ${sessions.length ? sessions.map(s => `
                <div style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:0.82rem">
                  <div style="display:flex;justify-content:space-between;align-items:center">
                    <span>${esc(s.ip_address || '—')}</span>
                    ${s.is_active ? badge('active') : badge('disabled')}
                  </div>
                  <div style="color:#64748b;font-size:0.75rem;margin-top:2px">${s.login_at ? new Date(s.login_at).toLocaleString('fa-IR') : '—'}</div>
                </div>
              `).join('') : '<div class="ma-empty" style="padding:20px"><div class="ma-empty__text">نشستی ثبت نشده</div></div>'}
            </div>
          </div>
        </div>
        <div class="ma-panel-card" style="margin-bottom:20px">
          <div class="ma-panel-card__header"><div class="ma-panel-card__title">📋 آخرین رویدادها</div></div>
          <div class="ma-panel-card__body--flush">
            <div class="ma-table__scroll">
              <table class="ma-table">
                <thead><tr><th>زمان</th><th>عملیات</th><th>ماژول</th><th>وضعیت</th><th>اولویت</th><th>IP</th></tr></thead>
                <tbody>
                  ${auditRows.length ? auditRows.map(e => `
                    <tr>
                      <td>${e.created_at ? new Date(e.created_at).toLocaleString('fa-IR') : '—'}</td>
                      <td>${esc(e.action)}</td>
                      <td>${esc(e.module || '—')}</td>
                      <td>${badge(e.status)}</td>
                      <td>${badge(e.severity)}</td>
                      <td>${esc(e.ip_address || '—')}</td>
                    </tr>
                  `).join('') : '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8">رویدادی ثبت نشده</td></tr>'}
                </tbody>
              </table>
            </div>
          </div>
        </div>
        ${resets.length ? `
        <div class="ma-panel-card">
          <div class="ma-panel-card__header"><div class="ma-panel-card__title">🔑 تاریخچه بازیابی رمز</div></div>
          <div class="ma-panel-card__body--flush">
            <div class="ma-table__scroll">
              <table class="ma-table">
                <thead><tr><th>شناسه</th><th>زمان</th><th>وضعیت</th><th>IP</th></tr></thead>
                <tbody>
                  ${resets.map(r => `
                    <tr>
                      <td><code style="font-size:.75rem">${esc(r.request_id)}</code></td>
                      <td>${r.created_at ? new Date(r.created_at).toLocaleString('fa-IR') : '—'}</td>
                      <td>${badge(r.status)}</td>
                      <td>${esc(r.ip_address || '—')}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>
        ` : ''}
      `;
    } catch (e) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری اطلاعات کاربر</div></div>';
    }
  }

  // ── Admin Actions ────────────────────────────────────────
  async function loadAdminActions() {
    const container = document.getElementById('maTableContainer');
    const pagEl = document.getElementById('maPagination');
    if (!container) return;
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    try {
      const res = await api(`/admin-actions?${params}`);
      const cols = [
        { key: 'action_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${v}</code>` },
        { key: 'created_at', label: 'زمان', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'admin_username', label: 'مدیر' },
        { key: 'action', label: 'عملیات' },
        { key: 'target_username', label: 'هدف' },
        { key: 'description', label: 'توضیحات', render: v => (v || '').substring(0, 80) },
      ];
      renderTable(container, cols, res.data, 'عملیات مدیریتی ثبت نشده است');
      renderPagination(pagEl, res.total, res.pages, loadAdminActions);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری عملیات</div></div>'; }
  }

  // ── System Settings ─────────────────────────────────────
  async function loadSystemSettings() {
    const container = document.getElementById('maSystemSettings');
    if (!container) return;
    try {
      const res = await api('/config');
      if (!res.success) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری تنظیمات</div></div>'; return; }
      const configs = res.data || [];
      const getConfig = (key) => configs.find(c => c.config_key === key) || {};

      const captchaCfg = getConfig('captcha_enabled');
      const idleEnabledCfg = getConfig('idle_timeout_enabled');
      const idleSecondsCfg = getConfig('idle_timeout_seconds');

      container.innerHTML = `
        <div class="ma-settings-grid">
          <div class="ma-settings-card">
            <div class="ma-settings-card__header">
              <div class="ma-settings-card__icon">🔐</div>
              <div>
                <div class="ma-settings-card__title">کپچای صفحه ورود</div>
                <div class="ma-settings-card__desc">فعال یا غیرفعال کردن کد امنیتی در صفحه ورود</div>
              </div>
            </div>
            <div class="ma-settings-card__body">
              <label class="ma-toggle">
                <input type="checkbox" id="maCfgCaptcha" ${captchaCfg.config_value === '1' ? 'checked' : ''}>
                <span class="ma-toggle__slider"></span>
              </label>
              <span class="ma-toggle-label" id="maCfgCaptchaLabel">${captchaCfg.config_value === '1' ? 'فعال' : 'غیرفعال'}</span>
            </div>
            <div class="ma-settings-card__footer">
              ${captchaCfg.updated_by ? `<span class="ma-settings-card__meta">آخرین تغییر: ${captchaCfg.updated_by} — ${captchaCfg.updated_at ? new Date(captchaCfg.updated_at).toLocaleString('fa-IR') : '—'}</span>` : ''}
            </div>
          </div>

          <div class="ma-settings-card">
            <div class="ma-settings-card__header">
              <div class="ma-settings-card__icon">⏱️</div>
              <div>
                <div class="ma-settings-card__title">خروج خودکار (بیکاری)</div>
                <div class="ma-settings-card__desc">خروج خودکار کاربران پس از مدتی بیکاری</div>
              </div>
            </div>
            <div class="ma-settings-card__body">
              <label class="ma-toggle">
                <input type="checkbox" id="maCfgIdleEnabled" ${idleEnabledCfg.config_value === '1' ? 'checked' : ''}>
                <span class="ma-toggle__slider"></span>
              </label>
              <span class="ma-toggle-label" id="maCfgIdleEnabledLabel">${idleEnabledCfg.config_value === '1' ? 'فعال' : 'غیرفعال'}</span>
            </div>
            <div class="ma-settings-card__body" style="margin-top:12px">
              <label for="maCfgIdleSeconds" style="font-size:.85rem;color:#64748b;display:block;margin-bottom:6px">زمان بیکاری (ثانیه)</label>
              <div style="display:flex;align-items:center;gap:8px">
                <input type="number" id="maCfgIdleSeconds" class="ma-filter" style="width:120px" min="10" max="86400" value="${idleSecondsCfg.config_value || '300'}">
                <button class="ma-btn ma-btn--primary ma-btn--sm" id="maSaveIdleSeconds">ذخیره زمان</button>
              </div>
              <div style="font-size:.78rem;color:#94a3b8;margin-top:4px">پیش‌فرض: ۳۰۰ ثانیه (۵ دقیقه)</div>
            </div>
            <div class="ma-settings-card__footer">
              ${idleEnabledCfg.updated_by ? `<span class="ma-settings-card__meta">آخرین تغییر: ${idleEnabledCfg.updated_by} — ${idleEnabledCfg.updated_at ? new Date(idleEnabledCfg.updated_at).toLocaleString('fa-IR') : '—'}</span>` : ''}
            </div>
          </div>
        </div>

        <div style="margin-top:24px;padding:16px;background:rgba(99,102,241,.06);border-radius:12px;border:1px solid rgba(99,102,241,.12)">
          <div style="font-size:.85rem;color:#6366f1;font-weight:600;margin-bottom:6px">💡 راهنما</div>
          <ul style="font-size:.82rem;color:#64748b;margin:0;padding-inline-start:18px;line-height:1.8">
            <li>غیرفعال کردن کپچا: کاربران فقط با نام کاربری و رمز عبور وارد می‌شوند (امنیت کمتر).</li>
            <li>غیرفعال کردن خروج خودکار: کاربران تا زمانی که خودشان خارج شوند، در سامانه می‌مانند.</li>
            <li>زمان بیکاری: حداقل ۱۰ ثانیه، حداکثر ۸۶۴۰۰ ثانیه (۲۴ ساعت).</li>
          </ul>
        </div>
      `;

      document.getElementById('maCfgCaptcha').addEventListener('change', async function() {
        const val = this.checked ? '1' : '0';
        await api('/config', { method: 'POST', body: { key: 'captcha_enabled', value: val } });
        document.getElementById('maCfgCaptchaLabel').textContent = this.checked ? 'فعال' : 'غیرفعال';
        showToast(this.checked ? 'کپچا فعال شد' : 'کپچا غیرفعال شد');
      });

      document.getElementById('maCfgIdleEnabled').addEventListener('change', async function() {
        const val = this.checked ? '1' : '0';
        await api('/config', { method: 'POST', body: { key: 'idle_timeout_enabled', value: val } });
        document.getElementById('maCfgIdleEnabledLabel').textContent = this.checked ? 'فعال' : 'غیرفعال';
        showToast(this.checked ? 'خروج خودکار فعال شد' : 'خروج خودکار غیرفعال شد');
      });

      document.getElementById('maSaveIdleSeconds').addEventListener('click', async function() {
        const input = document.getElementById('maCfgIdleSeconds');
        const val = parseInt(input.value, 10);
        if (isNaN(val) || val < 10 || val > 86400) {
          showToast('زمان باید بین ۱۰ تا ۸۶۴۰۰ ثانیه باشد', 'error');
          return;
        }
        await api('/config', { method: 'POST', body: { key: 'idle_timeout_seconds', value: String(val) } });
        showToast('زمان بیکاری ذخیره شد');
      });

    } catch (e) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری تنظیمات</div></div>';
    }
  }

  // ── Tickets ─────────────────────────────────────────────
  const TICKET_STATUS_MAP = {
    new: { label: 'جدید', cls: 'info' },
    open: { label: 'باز', cls: 'warning' },
    in_progress: { label: 'در حال بررسی', cls: 'info' },
    waiting_for_user: { label: 'در انتظار کاربر', cls: 'purple' },
    waiting_for_support: { label: 'در انتظار پشتیبانی', cls: 'warning' },
    resolved: { label: 'حل‌شده', cls: 'success' },
    closed: { label: 'بسته‌شده', cls: 'neutral' },
  };
  const TICKET_PRIORITY_MAP = {
    low: { label: 'کم', cls: 'neutral' },
    normal: { label: 'عادی', cls: 'info' },
    high: { label: 'زیاد', cls: 'warning' },
    urgent: { label: 'فوری', cls: 'critical' },
  };

  function ticketBadge(val, map) {
    const s = map[String(val).toLowerCase()] || { label: val, cls: 'neutral' };
    return `<span class="ma-badge ma-badge--${s.cls}">${esc(s.label)}</span>`;
  }

  async function loadTicketStats() {
    const el = document.getElementById('maTicketStats');
    if (!el) return;
    try {
      const res = await api('/tickets/stats');
      const s = res.data;
      const openTotal = s.open || 0;
      el.innerHTML = `
        <div class="ma-top-cards-row" style="margin-bottom:16px">
          <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#a1c4fd,#c2e9fb)"><div class="ma-glow-card__content"><div class="ma-glow-card__top"><div class="ma-glow-card__icon">🎫</div></div><div class="ma-glow-card__label">کل تیکت‌ها</div><div class="ma-glow-card__value">${s.total || 0}</div></div></div>
          <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#f093fb,#f5576c)"><div class="ma-glow-card__content"><div class="ma-glow-card__top"><div class="ma-glow-card__icon">📬</div></div><div class="ma-glow-card__label">تیکت‌های باز</div><div class="ma-glow-card__value">${openTotal}</div></div></div>
          <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#11998e,#38ef7d)"><div class="ma-glow-card__content"><div class="ma-glow-card__top"><div class="ma-glow-card__icon">✅</div></div><div class="ma-glow-card__label">حل‌شده</div><div class="ma-glow-card__value">${(s.by_status && s.by_status.resolved) || 0}</div></div></div>
          <div class="ma-glow-card" style="--card-gradient:linear-gradient(135deg,#ff9a9e,#fad0c4)"><div class="ma-glow-card__content"><div class="ma-glow-card__top"><div class="ma-glow-card__icon">🔴</div></div><div class="ma-glow-card__label">فوری</div><div class="ma-glow-card__value">${(s.by_priority && s.by_priority.urgent) || 0}</div></div></div>
        </div>`;
    } catch (e) { /* toast shown */ }
  }

  async function loadTickets() {
    const container = document.getElementById('maTicketTable');
    const pagEl = document.getElementById('maTicketPagination');
    if (!container) return;
    await loadTicketStats();
    const searchEl = document.getElementById('ticketFilterSearch');
    const statusEl = document.getElementById('ticketFilterStatus');
    const priorityEl = document.getElementById('ticketFilterPriority');
    const sortEl = document.getElementById('ticketFilterSort');
    const params = new URLSearchParams({ page: state.page, per_page: 20 });
    if (state.filters.ticket_search) params.set('search', state.filters.ticket_search);
    if (state.filters.ticket_status) params.set('status', state.filters.ticket_status);
    if (state.filters.ticket_priority) params.set('priority', state.filters.ticket_priority);
    if (state.filters.ticket_sort) params.set('sort', state.filters.ticket_sort);
    try {
      const res = await api(`/tickets?${params}`);
      const items = res.data.items || [];
      if (!items.length) {
        container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">📭</div><div class="ma-empty__text">تیکتی موجود نیست</div></div>';
        if (pagEl) pagEl.innerHTML = '';
        return;
      }
      let html = '<div class="ma-table__scroll"><table class="ma-table"><thead><tr>';
      html += '<th>شماره</th><th>موضوع</th><th>درخواست‌کننده</th><th>گیرنده</th><th>وضعیت</th><th>اولویت</th><th>تاریخ</th><th>عملیات</th>';
      html += '</tr></thead><tbody>';
      items.forEach(t => {
        const time = t.created_at ? new Date(t.created_at).toLocaleDateString('fa-IR') : '—';
        html += `<tr>
          <td><code style="font-size:.75rem">${esc(t.ticket_number || 'HT-' + String(t.id).padStart(8,'0'))}</code></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(t.subject || '')}">${esc(t.subject || '—')}</td>
          <td>${esc(t.requester_username || '—')}</td>
          <td>${esc(t.recipient_username || '—')}</td>
          <td>${ticketBadge(t.status, TICKET_STATUS_MAP)}</td>
          <td>${ticketBadge(t.priority, TICKET_PRIORITY_MAP)}</td>
          <td style="font-size:.75rem">${esc(time)}</td>
          <td>
            <a href="#" class="ma-btn ma-btn--ghost ma-btn--sm" data-ma-action="viewTicket" data-ma-id="${t.id}">مشاهده</a>
            <button class="ma-btn ma-btn--danger ma-btn--sm" data-ma-action="deleteTicket" data-ma-id="${t.id}">حذف</button>
          </td>
        </tr>`;
      });
      html += '</tbody></table></div>';
      container.innerHTML = html;
      container.querySelectorAll('[data-ma-action]').forEach(btn => {
        btn.addEventListener('click', handleMaAction);
      });
      renderPagination(pagEl, res.data.total, res.data.pages, loadTickets);
    } catch (e) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری تیکت‌ها</div></div>';
    }
  }

  window.maApplyTicketFilters = function() {
    const searchEl = document.getElementById('ticketFilterSearch');
    const statusEl = document.getElementById('ticketFilterStatus');
    const priorityEl = document.getElementById('ticketFilterPriority');
    const sortEl = document.getElementById('ticketFilterSort');
    state.filters.ticket_search = searchEl ? searchEl.value : '';
    state.filters.ticket_status = statusEl ? statusEl.value : '';
    state.filters.ticket_priority = priorityEl ? priorityEl.value : '';
    state.filters.ticket_sort = sortEl ? sortEl.value : '';
    state.page = 1;
    loadTickets();
  };

  window.ma_viewTicket = function(id) {
    window.location.href = '/master-admin/ticket-detail?t=' + id;
  };

  window.ma_deleteTicket = async function(id) {
    const yes = await maConfirm({ title: 'حذف تیکت', msg: 'آیا از حذف این تیکت اطمینان دارید؟ این عملیات قابل بازگشت نیست.', confirmText: 'حذف شود', type: 'danger' });
    if (!yes) return;
    await api(`/tickets/${id}`, { method: 'DELETE' });
    showToast('تیکت حذف شد');
    loadTickets();
  };

  // ── Ticket Detail ───────────────────────────────────────
  async function loadTicketDetail() {
    const container = document.getElementById('maTicketDetail');
    if (!container) return;
    const ticketId = new URLSearchParams(window.location.search).get('t') || window.__maViewTicket;
    if (!ticketId) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">🎫</div><div class="ma-empty__text">شناسه تیکت مشخص نشده</div></div>';
      return;
    }
    try {
      const res = await api(`/tickets/${ticketId}`);
      const t = res.data;
      const usersRes = await api('/tickets/users/all');
      const users = usersRes.data || [];
      const categoriesRes = await api('/tickets/categories/all');
      const categories = categoriesRes.data || [];

      const statusOpts = Object.entries(TICKET_STATUS_MAP).map(([k,v]) => `<option value="${k}" ${t.status===k?'selected':''}>${v.label}</option>`).join('');
      const priorityOpts = Object.entries(TICKET_PRIORITY_MAP).map(([k,v]) => `<option value="${k}" ${t.priority===k?'selected':''}>${v.label}</option>`).join('');
      const assigneeOpts = `<option value="">بدون واگذاری</option>` + users.map(u => `<option value="${esc(u.username)}" ${t.assigned_to===u.username?'selected':''}>${esc(u.name || u.username)} — ${esc(u.department || '')}</option>`).join('');
      const catOpts = `<option value="">بدون دسته</option>` + categories.map(c => `<option value="${esc(c.id)}" ${t.category_id==c.id?'selected':''}>${esc(c.name)}</option>`).join('');

      let html = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
          <a href="/master-admin/tickets" class="ma-btn ma-btn--ghost" style="font-size:0.82rem">← بازگشت به تیکت‌ها</a>
          <h2 style="margin:0;font-size:1.1rem;font-weight:800;color:#0f172a">${esc(t.ticket_number || 'HT-' + String(t.id).padStart(8,'0'))} — ${esc(t.subject)}</h2>
          ${ticketBadge(t.status, TICKET_STATUS_MAP)} ${ticketBadge(t.priority, TICKET_PRIORITY_MAP)}
        </div>
        <div class="ma-grid-2" style="margin-bottom:20px">
          <div class="ma-panel-card">
            <div class="ma-panel-card__header"><div class="ma-panel-card__title">📋 اطلاعات تیکت</div></div>
            <div class="ma-panel-card__body">
              <table style="width:100%;font-size:0.82rem;border-collapse:collapse">
                <tr><td style="padding:6px 0;color:#64748b;width:140px">شماره</td><td style="padding:6px 0;font-weight:600">${t.ticket_number || 'HT-' + String(t.id).padStart(8,'0')}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">موضوع</td><td style="padding:6px 0">${esc(t.subject)}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">درخواست‌کننده</td><td style="padding:6px 0">${esc(t.requester_username)}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">گیرنده</td><td style="padding:6px 0">${esc(t.recipient_username)}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">واگذار شده به</td><td style="padding:6px 0">${esc(t.assigned_to || '—')}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">دسته‌بندی</td><td style="padding:6px 0">${esc(t.category_name || '—')}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">تاریخ ایجاد</td><td style="padding:6px 0">${t.created_at ? new Date(t.created_at).toLocaleString('fa-IR') : '—'}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">آخرین به‌روزرسانی</td><td style="padding:6px 0">${t.updated_at ? new Date(t.updated_at).toLocaleString('fa-IR') : '—'}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">آخرین پیام</td><td style="padding:6px 0">${t.last_message_at ? new Date(t.last_message_at).toLocaleString('fa-IR') : '—'}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">SLA</td><td style="padding:6px 0">${t.sla_due_at ? new Date(t.sla_due_at).toLocaleString('fa-IR') : '—'} ${t.sla_state === 'overdue' ? '<span style="color:#dc2626;font-weight:700"> — سررسید گذشته</span>' : ''}</td></tr>
              </table>
            </div>
          </div>
          <div class="ma-panel-card">
            <div class="ma-panel-card__header"><div class="ma-panel-card__title">⚙️ مدیریت تیکت</div></div>
            <div class="ma-panel-card__body">
              <div style="display:flex;flex-direction:column;gap:12px">
                <label style="font-size:.82rem;font-weight:600;color:#415466">وضعیت</label>
                <select id="maTicketStatus" class="ma-filter" style="width:100%">${statusOpts}</select>
                <label style="font-size:.82rem;font-weight:600;color:#415466">اولویت</label>
                <select id="maTicketPriority" class="ma-filter" style="width:100%">${priorityOpts}</select>
                <label style="font-size:.82rem;font-weight:600;color:#415466">واگذاری به</label>
                <select id="maTicketAssignee" class="ma-filter" style="width:100%">${assigneeOpts}</select>
                <label style="font-size:.82rem;font-weight:600;color:#415466">دسته‌بندی</label>
                <select id="maTicketCategory" class="ma-filter" style="width:100%">${catOpts}</select>
                <div style="display:flex;gap:8px;margin-top:8px">
                  <button class="ma-btn ma-btn--primary" onclick="maSaveTicketChanges(${t.id})">ذخیره تغییرات</button>
                  <button class="ma-btn ma-btn--danger" onclick="maDeleteTicketFromDetail(${t.id})">حذف تیکت</button>
                </div>
              </div>
            </div>
          </div>
        </div>`;

      // Messages
      html += `<div class="ma-panel-card" style="margin-bottom:20px">
        <div class="ma-panel-card__header"><div class="ma-panel-card__title">💬 پیام‌ها (${(t.messages||[]).length})</div></div>
        <div class="ma-panel-card__body" style="max-height:400px;overflow-y:auto">`;
      if (t.messages && t.messages.length) {
        t.messages.forEach(m => {
          const isInternal = m.visibility === 'internal';
          const time = m.created_at ? new Date(m.created_at).toLocaleString('fa-IR') : '';
          const borderStyle = isInternal ? 'border-right:3px solid #f59e0b;background:#fffbeb' : '';
          html += `<div style="padding:12px;margin-bottom:10px;border-radius:10px;border:1px solid #e4e7ec;${borderStyle}">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
              <div>
                <strong style="font-size:.85rem">${esc(m.author_username)}</strong>
                ${isInternal ? '<span class="ma-badge ma-badge--warning" style="margin-right:6px;font-size:.65rem">یادداشت داخلی</span>' : ''}
              </div>
              <span style="font-size:.72rem;color:#94a3b8">${esc(time)}</span>
            </div>
            <div style="font-size:.85rem;color:#334155;line-height:1.7;white-space:pre-wrap">${esc(m.body)}</div>
          </div>`;
        });
      } else {
        html += '<div class="ma-empty" style="padding:20px"><div class="ma-empty__text">هنوز پیامی ارسال نشده</div></div>';
      }
      html += `</div></div>`;

      // Reply form
      html += `
        <div class="ma-panel-card" style="margin-bottom:20px">
          <div class="ma-panel-card__header"><div class="ma-panel-card__title">✏️ ارسال پاسخ</div></div>
          <div class="ma-panel-card__body">
            <textarea id="maTicketReplyBody" class="ma-filter" style="width:100%;min-height:100px;resize:vertical" placeholder="متن پاسخ..."></textarea>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
              <label style="font-size:.82rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                <input type="checkbox" id="maTicketReplyInternal"> یادداشت داخلی (فقط مدیران)
              </label>
              <button class="ma-btn ma-btn--primary" onclick="maSendTicketReply(${t.id})">ارسال پاسخ</button>
            </div>
          </div>
        </div>`;

      // Audit events
      if (t.events && t.events.length) {
        html += `<div class="ma-panel-card">
          <div class="ma-panel-card__header"><div class="ma-panel-card__title">📋 تاریخچه رویدادها</div></div>
          <div class="ma-panel-card__body" style="max-height:250px;overflow-y:auto">
            <div class="ma-timeline">`;
        t.events.forEach(e => {
          const time = e.created_at ? new Date(e.created_at).toLocaleString('fa-IR') : '';
          let meta = '';
          if (e.metadata && typeof e.metadata === 'object') {
            meta = Object.entries(e.metadata).map(([k,v]) => `${esc(k)}: ${esc(v)}`).join(', ');
          }
          html += `<div class="ma-timeline__item">
            <div class="ma-timeline__dot ma-timeline__dot--success"></div>
            <div class="ma-timeline__time">${esc(time)}</div>
            <div class="ma-timeline__text"><strong>${esc(e.actor_username)}</strong> ${esc(e.event_type)}</div>
            <div class="ma-timeline__meta">${esc(meta)}</div>
          </div>`;
        });
        html += `</div></div></div>`;
      }

      container.innerHTML = html;
    } catch (e) {
      container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری تیکت</div></div>';
    }
  }

  window.maSaveTicketChanges = async function(ticketId) {
    const status = document.getElementById('maTicketStatus').value;
    const priority = document.getElementById('maTicketPriority').value;
    const assigned_to = document.getElementById('maTicketAssignee').value || null;
    const category_id = document.getElementById('maTicketCategory').value ? parseInt(document.getElementById('maTicketCategory').value) : null;
    try {
      await api(`/tickets/${ticketId}`, {
        method: 'PATCH',
        body: { status, priority, assigned_to, category_id }
      });
      showToast('تیکت به‌روزرسانی شد');
      loadTicketDetail();
    } catch (e) { /* toast shown */ }
  };

  window.maDeleteTicketFromDetail = async function(ticketId) {
    const yes = await maConfirm({ title: 'حذف تیکت', msg: 'آیا از حذف این تیکت اطمینان دارید؟ این عملیات قابل بازگشت نیست.', confirmText: 'حذف شود', type: 'danger' });
    if (!yes) return;
    await api(`/tickets/${ticketId}`, { method: 'DELETE' });
    showToast('تیکت حذف شد');
    window.location.href = '/master-admin/tickets';
  };

  window.maSendTicketReply = async function(ticketId) {
    const bodyEl = document.getElementById('maTicketReplyBody');
    const internalEl = document.getElementById('maTicketReplyInternal');
    const body = bodyEl ? bodyEl.value.trim() : '';
    if (!body) { showToast('لطفاً متن پاسخ را وارد کنید', 'error'); return; }
    const visibility = internalEl && internalEl.checked ? 'internal' : 'public';
    try {
      await api(`/tickets/${ticketId}/reply`, {
        method: 'POST',
        body: { body, visibility }
      });
      showToast('پاسخ ارسال شد');
      loadTicketDetail();
    } catch (e) { /* toast shown */ }
  };

  // ── Global Actions ───────────────────────────────────────
  window.maTerminateSession = async function (key) {
    const yes = await maConfirm({ title: 'خاتمه نشست', msg: 'آیا از خاتمه این نشست اطمینان دارید؟', confirmText: 'خاتمه یابد', type: 'danger' });
    if (!yes) return;
    await api(`/sessions/${key}/terminate`, { method: 'POST' });
    showToast('نشست خاتمه یافت');
    loadSessions();
  };

  window.maApproveReset = async function (id) {
    const res = await api(`/password-resets/${id}/approve`, { method: 'POST' });
    if (res.success) {
      showToast(`کد بازیابی: ${res.code}`, 'success');
      loadPasswordResets();
    }
  };

  window.maRejectReset = async function (id) {
    const yes = await maConfirm({ title: 'رد درخواست بازیابی', msg: 'آیا از رد این درخواست اطمینان دارید؟', confirmText: 'رد شود', type: 'danger' });
    if (!yes) return;
    await api(`/password-resets/${id}/reject`, { method: 'POST' });
    showToast('درخواست رد شد');
    loadPasswordResets();
  };

  window.maResolveSecurity = async function (id) {
    await api(`/security/${id}/resolve`, { method: 'POST', body: { status: 'resolved' } });
    showToast('رویداد بررسی شد');
    loadSecurity();
  };

  // ── Section Loader ───────────────────────────────────────
  const loaders = {
    dashboard: loadDashboard,
    'audit-logs': loadAuditLogs,
    users: loadUsers,
    subscriptions: loadSubscriptions,
    'user-detail': loadUserDetail,
    sessions: loadSessions,
    'password-resets': loadPasswordResets,
    security: loadSecurity,
    errors: loadErrors,
    'admin-actions': loadAdminActions,
    tickets: loadTickets,
    'ticket-detail': loadTicketDetail,
    'system-settings': loadSystemSettings,
    'label-printer': initLabelStudio,
  };

  const subscriptionApply = document.getElementById('subscriptionApplyFilters');
  if (subscriptionApply) subscriptionApply.addEventListener('click', () => {
    state.page = 1;
    loadSubscriptions();
  });
  const subscriptionRefresh = document.getElementById('maSubscriptionsRefresh');
  if (subscriptionRefresh) subscriptionRefresh.addEventListener('click', loadSubscriptions);
  const subscriptionSearch = document.getElementById('subscriptionSearch');
  if (subscriptionSearch) subscriptionSearch.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      state.page = 1;
      loadSubscriptions();
    }
  });

  // ── Expose filter setter for inline onclick handlers ─────
  window.__maSetFilters = function(f) {
    Object.assign(state.filters, f);
    state.page = 1;
    if (loaders[SECTION]) loaders[SECTION]();
  };

  // ── Global Search ────────────────────────────────────────
  const searchInput = document.getElementById('maGlobalSearch');
  const searchResults = document.getElementById('maSearchResults');
  let searchTimer = null;
  if (searchInput && searchResults) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      const q = this.value.trim();
      if (q.length < 2) { searchResults.classList.remove('is-open'); return; }
      searchTimer = setTimeout(async () => {
        try {
          const res = await api(`/search?q=${encodeURIComponent(q)}`);
          if (!res.results.length) {
            searchResults.innerHTML = '<div style="padding:12px;text-align:center;color:#94a3b8;font-size:.85rem">نتیجه‌ای یافت نشد</div>';
          } else {
            searchResults.innerHTML = res.results.map(r => `<a href="${esc(r.link)}" class="ma-topbar__search-item"><span>${esc(r.title)}</span><span style="font-size:.75rem;color:#94a3b8">${esc(r.subtitle)}</span></a>`).join('');
          }
          searchResults.classList.add('is-open');
        } catch (e) { searchResults.classList.remove('is-open'); }
      }, 400);
    });
    document.addEventListener('click', e => {
      if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) searchResults.classList.remove('is-open');
    });
  }

  // ── Init ─────────────────────────────────────────────────
  if (loaders[SECTION]) loaders[SECTION]();

})();

// ── Profile Dropdown ───────────────────────────────────────
function toggleMaProfileDropdown(e) {
  e.stopPropagation();
  var dd = document.getElementById('maProfileDropdown');
  if (dd) dd.classList.toggle('is-open');
}
document.addEventListener('click', function(e) {
  var dd = document.getElementById('maProfileDropdown');
  if (dd && !dd.contains(e.target) && !e.target.closest('#maProfileButton')) {
    dd.classList.remove('is-open');
  }
});

// ── Profile Panel ────────────────────────────────────────
function openMaProfilePanel(panelType) {
  var dd = document.getElementById('maProfileDropdown');
  if (dd) dd.classList.remove('is-open');
  var overlay = document.getElementById('profilePanelOverlay');
  var panel = document.getElementById('profilePanel');
  var title = document.getElementById('profilePanelTitle');
  var subtitle = document.getElementById('profilePanelSubtitle');
  var body = document.getElementById('profilePanelBody');
  if (!overlay || !panel) return;
  var configs = {
    profile: { title: 'پروفایل من', subtitle: 'اطلاعات حساب کاربری', content: '<div style="padding:20px;text-align:center;color:#64748b">پروفایل در حال بارگذاری...</div>' },
    security: { title: 'امنیت و رمز عبور', subtitle: 'تنظیمات حفاظت از حساب', content: '<div style="padding:20px;text-align:center;color:#64748b">امنیت در حال بارگذاری...</div>' },
    subscription: { title: 'اشتراک من', subtitle: 'وضعیت اشتراک', content: '<div style="padding:20px;text-align:center;color:#64748b">اشتراک در حال بارگذاری...</div>' },
    billing: { title: 'فاکتورها', subtitle: 'تاریخچه پرداخت', content: '<div style="padding:20px;text-align:center;color:#64748b">فاکتورها در حال بارگذاری...</div>' },
    support: { title: 'پشتیبانی فنی', subtitle: 'ارسال درخواست', content: '<div style="padding:20px;text-align:center;color:#64748b">پشتیبانی در حال بارگذاری...</div>' },
    settings: { title: 'تنظیمات', subtitle: 'تنظیمات سامانه', content: '<div style="padding:20px;text-align:center;color:#64748b">تنظیمات در حال بارگذاری...</div>' }
  };
  var cfg = configs[panelType];
  if (!cfg) return;
  if (title) title.textContent = cfg.title;
  if (subtitle) subtitle.textContent = cfg.subtitle;
  if (body) body.innerHTML = cfg.content;
  overlay.hidden = false;
  panel.hidden = false;
  requestAnimationFrame(function() {
    overlay.classList.add('open');
    panel.classList.add('open');
  });
}
function closeMaProfilePanel() {
  var overlay = document.getElementById('profilePanelOverlay');
  var panel = document.getElementById('profilePanel');
  if (overlay) overlay.classList.remove('open');
  if (panel) panel.classList.remove('open');
  setTimeout(function() {
    if (overlay) overlay.hidden = true;
    if (panel) panel.hidden = true;
  }, 250);
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('[data-panel]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openMaProfilePanel(this.getAttribute('data-panel'));
    });
  });
  var overlay = document.getElementById('profilePanelOverlay');
  if (overlay) overlay.addEventListener('click', closeMaProfilePanel);
});

// ── Event Delegation Handlers (جایگزین onclick inline) ──
window.ma_viewUser = function(id) {
  window.__maViewUser(id);
};
window.ma_terminateSession = async function(key) {
  if (typeof window.maTerminateSession === 'function') window.maTerminateSession(key);
};
window.ma_approveReset = async function(id) {
  if (typeof window.maApproveReset === 'function') window.maApproveReset(id);
};
window.ma_rejectReset = async function(id) {
  if (typeof window.maRejectReset === 'function') window.maRejectReset(id);
};
window.ma_resolveSecurity = async function(id) {
  if (typeof window.maResolveSecurity === 'function') window.maResolveSecurity(id);
};
window.ma_viewTicket = function(id) {
  window.location.href = '/master-admin/ticket-detail?t=' + id;
};
