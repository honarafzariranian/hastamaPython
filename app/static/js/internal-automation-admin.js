(() => {
    'use strict';
    const list = document.getElementById('internalAutomationAdminList');
    if (!list) return;
    const fa = new Intl.NumberFormat('fa-IR');
    const formatDate = value => value ? new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[ch]));
    async function load() {
        list.innerHTML = '<div class="ticket-loading-state">در حال دریافت گفتگوها…</div>';
        try {
            const response = await fetch('/api/automation', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.detail || 'دریافت اطلاعات ناموفق بود.');
            list.replaceChildren();
            if (!(data.items || []).length) { list.innerHTML = '<div class="ticket-empty-state">گفتگویی ثبت نشده است.</div>'; return; }
            data.items.forEach(item => {
                const card = document.createElement('article');
                card.className = 'internal-automation-admin-card';
                card.innerHTML = `<div><span class="ticket-panel-kicker">گفت‌وگوی سازمانی</span><h3>${escapeHtml(item.subject)}</h3></div><div class="internal-automation-admin-meta"><span>${fa.format(item.participant_count || 0)} عضو</span><span>${fa.format(item.message_count || 0)} پیام</span><time>${formatDate(item.updated_at)}</time></div>`;
                list.append(card);
            });
        } catch (error) { list.innerHTML = `<div class="ticket-error-state">${escapeHtml(error.message)}</div>`; }
    }
    document.addEventListener('click', event => {
        if (event.target.closest('[data-accent="automation"]')) load();
    });
})();
