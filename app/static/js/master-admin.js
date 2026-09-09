/* ═══════════════════════════════════════════════════════════════
   HASTAMA MASTER ADMIN — Control Center JavaScript
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const BASE = '/master-admin';
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
        statsEl.innerHTML = `
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--blue">👥</div></div><div class="ma-stat__value">${s.total_users}</div><div class="ma-stat__label">کل کاربران</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--green">🟢</div></div><div class="ma-stat__value">${s.active_users}</div><div class="ma-stat__label">کاربران فعال</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--cyan">🔗</div></div><div class="ma-stat__value">${s.online_sessions}</div><div class="ma-stat__label">نشست‌های فعال</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--blue">🔑</div></div><div class="ma-stat__value">${s.logins_today}</div><div class="ma-stat__label">ورودهای امروز</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--red">⚠️</div><span class="ma-stat__badge ma-stat__badge--${s.failed_logins_today > 0 ? 'red' : 'green'}">${s.failed_logins_today > 0 ? '! ' + s.failed_logins_today : '—'}</span></div><div class="ma-stat__value">${s.failed_logins_today}</div><div class="ma-stat__label">ورود ناموفق امروز</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--amber">🔑</div><span class="ma-stat__badge ma-stat__badge--${s.pending_password_resets > 0 ? 'amber' : 'green'}">${s.pending_password_resets > 0 ? s.pending_password_resets + ' جدید' : '—'}</span></div><div class="ma-stat__value">${s.pending_password_resets}</div><div class="ma-stat__label">درخواست بازیابی رمز</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--red">🛡️</div></div><div class="ma-stat__value">${s.open_security_events}</div><div class="ma-stat__label">رویدادهای امنیتی باز</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--red">🐛</div></div><div class="ma-stat__value">${s.open_errors}</div><div class="ma-stat__label">خطاهای باز</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--purple">🎫</div></div><div class="ma-stat__value">${s.open_tickets}</div><div class="ma-stat__label">تیکت‌های باز</div></div>
          <div class="ma-stat"><div class="ma-stat__header"><div class="ma-stat__icon ma-stat__icon--blue">📋</div></div><div class="ma-stat__value">${s.events_today}</div><div class="ma-stat__label">رویدادهای امروز</div></div>
        `;
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
        { key: 'username', label: 'عملیات', render: v => `<a href="/master-admin/users/${v.trim()}" class="ma-btn ma-btn--ghost ma-btn--sm">مشاهده</a>` },
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
