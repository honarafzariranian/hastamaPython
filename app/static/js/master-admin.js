/* ═══════════════════════════════════════════════════════════════
   HASTAMA MASTER ADMIN — Control Center JavaScript
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const BASE = '/master-admin/api';
  const SECTION = document.body.dataset.section || 'dashboard';

  // ── Toast ────────────────────────────────────────────────
  function showToast(msg, type = 'success') {
    let t = document.getElementById('maToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'maToast';
      t.className = 'ma-toast';
      document.body.appendChild(t);
    }
    t.className = `ma-toast ma-toast--${type} is-visible`;
    t.innerHTML = `<span>${type === 'success' ? '✅' : type === 'error' ? '❌' : '⚠️'}</span><span>${msg}</span>`;
    setTimeout(() => t.classList.remove('is-visible'), 4000);
  }

  // ── API Helper ───────────────────────────────────────────
  async function api(path, opts = {}) {
    const url = BASE + path;
    const fetchOpts = { headers: { 'Content-Type': 'application/json' }, ...opts };
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
    columns.forEach(c => html += `<th>${c.label}</th>`);
    html += '</tr></thead><tbody>';
    rows.forEach(r => {
      html += '<tr>';
      columns.forEach(c => {
        let val = r[c.key] ?? '—';
        if (c.render) val = c.render(val, r);
        html += `<td>${val}</td>`;
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
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
    completed: { label: 'تکمیل شده', cls: 'success' },
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
    return `<span class="ma-badge ma-badge--${s.cls}">${s.label}</span>`;
  }

  function dot(val) {
    const colors = { success: 'green', failure: 'red', error: 'red', warning: 'amber', info: 'blue' };
    return `<span class="ma-dot ma-dot--${colors[val] || 'blue'}"></span>`;
  }

  // ── Dashboard ────────────────────────────────────────────
  async function loadDashboard() {
    try {
      const [statsRes, activityRes] = await Promise.all([
        api('/dashboard/stats'),
        api('/dashboard/activity?limit=30'),
      ]);
      const s = statsRes.data;
      const statsEl = document.getElementById('maStats');
      if (statsEl) {
        const cards = [
          { icon: '👥', value: s.total_users, label: 'کل کاربران', gradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', delay: 0 },
          { icon: '🟢', value: s.active_users, label: 'کاربران فعال', gradient: 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)', delay: 50 },
          { icon: '🔗', value: s.online_sessions, label: 'نشست‌های فعال', gradient: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)', delay: 100 },
          { icon: '🔑', value: s.logins_today, label: 'ورودهای امروز', gradient: 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)', delay: 150 },
          { icon: '⚠️', value: s.failed_logins_today, label: 'ورود ناموفق', gradient: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)', alert: s.failed_logins_today > 0, delay: 200 },
          { icon: '🔐', value: s.pending_password_resets, label: 'بازیابی رمز', gradient: 'linear-gradient(135deg, #fccb90 0%, #d57eeb 100%)', alert: s.pending_password_resets > 0, delay: 250 },
          { icon: '🛡️', value: s.open_security_events, label: 'رویداد امنیتی', gradient: 'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)', alert: s.open_security_events > 0, delay: 300 },
          { icon: '🐛', value: s.open_errors, label: 'خطاهای باز', gradient: 'linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%)', alert: s.open_errors > 0, delay: 350 },
          { icon: '🎫', value: s.open_tickets, label: 'تیکت‌های باز', gradient: 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)', delay: 400 },
          { icon: '📋', value: s.events_today, label: 'رویدادهای امروز', gradient: 'linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%)', delay: 450 },
        ];
        statsEl.innerHTML = cards.map((c, i) => `
          <div class="ma-glow-card" style="animation-delay:${c.delay}ms;--card-gradient:${c.gradient}">
            <div class="ma-glow-card__shine"></div>
            <div class="ma-glow-card__content">
              <div class="ma-glow-card__top">
                <div class="ma-glow-card__icon">${c.icon}</div>
                ${c.alert ? '<div class="ma-glow-card__alert"></div>' : ''}
              </div>
              <div class="ma-glow-card__value" data-count="${c.value}">${c.value}</div>
              <div class="ma-glow-card__label">${c.label}</div>
            </div>
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
            el.textContent = current;
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
            html += `<div class="ma-timeline__item"><div class="ma-timeline__dot ma-timeline__dot--${dotCls}"></div><div class="ma-timeline__time">${time}</div><div class="ma-timeline__text">${dot(e.status)} <strong>${e.username || '—'}</strong> ${e.action} ${e.module ? 'در ' + e.module : ''}</div><div class="ma-timeline__meta">${e.event_id} · ${e.ip_address || '—'}</div></div>`;
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
        { key: 'event_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${v}</code>` },
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
        { key: 'name', label: 'نام', render: (v, r) => `${v || ''} ${r.last_name || ''}` },
        { key: 'department', label: 'بخش' },
        { key: 'role', label: 'نقش', render: v => badge(v) },
        { key: 'is_active', label: 'وضعیت', render: v => badge(v || 'active') },
        { key: 'last_login', label: 'آخرین ورود', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'username', label: 'عملیات', render: v => `<a href="#" class="ma-btn ma-btn--ghost ma-btn--sm" onclick="event.preventDefault();window.__maViewUser('${encodeURIComponent(v.trim())}')">مشاهده</a>` },
      ];
      renderTable(container, cols, res.data, 'کاربری یافت نشد');
      renderPagination(pagEl, res.total, res.pages, loadUsers);
    } catch (e) { container.innerHTML = '<div class="ma-empty"><div class="ma-empty__icon">⚠️</div><div class="ma-empty__text">خطا در بارگذاری کاربران</div></div>'; }
  }

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
        { key: 'session_key', label: 'عملیات', render: v => `<button class="ma-btn ma-btn--danger ma-btn--sm" onclick="maTerminateSession('${v}')">خاتمه</button>` },
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
        { key: 'request_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${v}</code>` },
        { key: 'username', label: 'کاربر' },
        { key: 'created_at', label: 'زمان', render: v => v ? new Date(v).toLocaleString('fa-IR') : '—' },
        { key: 'ip_address', label: 'IP' },
        { key: 'status', label: 'وضعیت', render: v => badge(v) },
        { key: 'code_attempts', label: 'تلاش‌ها' },
        { key: 'request_id', label: 'عملیات', render: (v, r) => r.status === 'pending' ? `<button class="ma-btn ma-btn--primary ma-btn--sm" onclick="maApproveReset('${v}')">تأیید</button> <button class="ma-btn ma-btn--danger ma-btn--sm" onclick="maRejectReset('${v}')">رد</button>` : '—' },
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
        { key: 'event_id', label: 'عملیات', render: (v, r) => r.status === 'open' ? `<button class="ma-btn ma-btn--primary ma-btn--sm" onclick="maResolveSecurity('${v}')">بررسی شد</button>` : '—' },
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
        { key: 'error_id', label: 'شناسه', render: v => `<code style="font-size:.75rem">${v}</code>` },
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
          <h2 style="margin:0;font-size:1.1rem;font-weight:800;color:#0f172a">${u.username} — ${u.name || ''} ${u.last_name || ''}</h2>
          ${badge(u.role)} ${badge(u.is_active || 'active')}
        </div>
        <div class="ma-grid-2" style="margin-bottom:20px">
          <div class="ma-panel-card">
            <div class="ma-panel-card__header"><div class="ma-panel-card__title">📋 اطلاعات حساب</div></div>
            <div class="ma-panel-card__body">
              <table style="width:100%;font-size:0.82rem;border-collapse:collapse">
                <tr><td style="padding:6px 0;color:#64748b;width:140px">نام کاربری</td><td style="padding:6px 0;font-weight:600">${u.username}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">نام</td><td style="padding:6px 0">${u.name || '—'} ${u.last_name || ''}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">بخش</td><td style="padding:6px 0">${u.department || '—'}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">ساعت کاری</td><td style="padding:6px 0">${u.work_hours || '—'}</td></tr>
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
                    <span>${s.ip_address || '—'}</span>
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
                      <td>${e.action}</td>
                      <td>${e.module || '—'}</td>
                      <td>${badge(e.status)}</td>
                      <td>${badge(e.severity)}</td>
                      <td>${e.ip_address || '—'}</td>
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
                      <td><code style="font-size:.75rem">${r.request_id}</code></td>
                      <td>${r.created_at ? new Date(r.created_at).toLocaleString('fa-IR') : '—'}</td>
                      <td>${badge(r.status)}</td>
                      <td>${r.ip_address || '—'}</td>
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

  // ── Global Actions ───────────────────────────────────────
  window.maTerminateSession = async function (key) {
    if (!confirm('آیا از خاتمه این نشست اطمینان دارید؟')) return;
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
    if (!confirm('آیا از رد این درخواست اطمینان دارید؟')) return;
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
    'user-detail': loadUserDetail,
    sessions: loadSessions,
    'password-resets': loadPasswordResets,
    security: loadSecurity,
    errors: loadErrors,
    'admin-actions': loadAdminActions,
  };

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
            searchResults.innerHTML = res.results.map(r => `<a href="${r.link}" class="ma-topbar__search-item"><span>${r.title}</span><span style="font-size:.75rem;color:#94a3b8">${r.subtitle}</span></a>`).join('');
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
