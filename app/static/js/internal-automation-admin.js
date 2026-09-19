(() => {
    'use strict';
    const list = document.getElementById('internalAutomationAdminList');
    if (!list) return;
    const fa = new Intl.NumberFormat('fa-IR');
    const currentUsername = String(document.body.dataset.notificationActor || '').trim();
    const formatDate = value => value ? new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[ch]));
    const formatMessageDate = value => value ? new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
    const openList = document.getElementById('internalAutomationAdminOpenList');
    const openCount = document.getElementById('internalAutomationAdminOpenCount');
    const openHeading = document.querySelector('.internal-automation-admin-open-heading');
    const openPane = document.querySelector('.internal-automation-admin-open-pane');
    function showOpenConversationList() {
        const panel = document.getElementById('internalAutomationAdminCreatedConversation');
        if (openHeading) openHeading.hidden = false;
        if (openList) openList.hidden = false;
        if (panel) panel.hidden = true;
        if (openPane) openPane.classList.remove('is-conversation-view');
    }
    async function loadUsers() {
        const select = document.querySelector('#internalAutomationAdminCreateForm select[name="participant"]');
        if (!select || select.dataset.loaded === 'true') return;
        try {
            const response = await fetch('/get_users', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok || data.success === false) throw new Error(data.message || 'دریافت کاربران ناموفق بود.');
            select.replaceChildren(new Option('انتخاب کاربر', ''));
            (data.users || []).forEach(user => select.append(new Option(user.label ? `${user.label} (${user.value})` : user.value, user.value)));
            select.dataset.loaded = 'true';
        } catch (error) {
            select.replaceChildren(new Option(error.message || 'دریافت کاربران ناموفق بود.', ''));
        }
    }
    window.switchInternalAutomationAdminTab = function(tabId, button) {
        document.querySelectorAll('.internal-automation-admin-tab').forEach(tab => {
            tab.classList.toggle('active', tab === button);
            tab.setAttribute('aria-selected', tab === button ? 'true' : 'false');
        });
        document.querySelectorAll('.internal-automation-admin-tab-content').forEach(panel => {
            const active = panel.id === tabId;
            panel.classList.toggle('active', active);
            panel.hidden = !active;
        });
        if (tabId === 'internalAutomationCreateTab') {
            loadUsers();
            loadOpenConversations();
        }
    };
    const createForm = document.getElementById('internalAutomationAdminCreateForm');
    if (createForm) createForm.addEventListener('submit', async event => {
        event.preventDefault();
        const status = createForm.querySelector('.internal-automation-admin-form-status');
        const button = createForm.querySelector('button[type="submit"]');
        const payload = { subject: createForm.subject.value.trim(), participants: [createForm.participant.value], body: createForm.body.value.trim() };
        status.textContent = 'در حال ایجاد گفتگو…'; button.disabled = true;
        try {
            const response = await fetch('/api/automation', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.detail || 'ایجاد گفتگو ناموفق بود.');
            createForm.reset(); status.textContent = 'گفتگو با موفقیت ایجاد شد.';
            renderCreatedConversation(data);
            await load();
        } catch (error) { status.textContent = error.message || 'ایجاد گفتگو ناموفق بود.'; }
        finally { button.disabled = false; }
    });
    async function load() {
        list.innerHTML = '<div class="ticket-loading-state">در حال دریافت گفتگوها…</div>';
        try {
            const response = await fetch('/api/automation', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.detail || 'دریافت اطلاعات ناموفق بود.');
            renderOpenConversationList(data.items || []);
            list.replaceChildren();
            if (!(data.items || []).length) { list.innerHTML = '<div class="ticket-empty-state">گفتگویی ثبت نشده است.</div>'; return; }
            data.items.forEach(item => {
                const card = document.createElement('article');
                card.className = 'internal-automation-admin-card';
                const participants = Array.isArray(item.participants) ? item.participants : [];
                const participantLabel = participants.length
                    ? participants.map(escapeHtml).join(' <span aria-hidden="true">↔</span> ')
                    : 'اعضای گفتگو مشخص نیستند';
                card.innerHTML = `<div><span class="ticket-panel-kicker">گفت‌وگوی سازمانی</span><h3>${escapeHtml(item.subject)}</h3><p class="internal-automation-admin-participants"><span>طرفین گفتگو</span><strong>${participantLabel}</strong></p></div><div class="internal-automation-admin-meta"><span>${fa.format(item.participant_count || 0)} عضو</span><span>${fa.format(item.message_count || 0)} پیام</span><time>${formatDate(item.updated_at)}</time></div>`;
                list.append(card);
            });
        } catch (error) { list.innerHTML = `<div class="ticket-error-state">${escapeHtml(error.message)}</div>`; }
    }
    function renderOpenConversationList(items) {
        if (!openList) return;
        const openItems = items.filter(item => {
            const participants = Array.isArray(item.participants) ? item.participants : [];
            return participants.some(username => String(username || '').trim() === currentUsername);
        });
        if (openCount) openCount.textContent = fa.format(openItems.length);
        openList.replaceChildren();
        if (!openItems.length) {
            openList.innerHTML = '<div class="internal-automation-admin-detail-empty">گفت‌وگویی وجود ندارد.</div>';
            return;
        }
        openItems.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            const completed = item.status === 'completed';
            button.className = `internal-automation-admin-open-card ${completed ? 'is-completed' : 'is-open'}`;
            const participants = Array.isArray(item.participants) ? item.participants : [];
            button.innerHTML = `<strong>${escapeHtml(item.subject)}</strong><span>${participants.map(escapeHtml).join(' ↔ ')}</span><small>${fa.format(item.message_count || 0)} پیام · ${formatDate(item.updated_at)}</small><em class="internal-automation-admin-status-badge">${completed ? 'پایان‌یافته' : 'باز'}</em>`;
            button.addEventListener('click', () => loadConversation(item.id));
            openList.append(button);
        });
    }
    async function loadOpenConversations() {
        try {
            const response = await fetch('/api/automation', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.detail || 'دریافت گفتگوهای باز ناموفق بود.');
            renderOpenConversationList(data.items || []);
        } catch (error) {
            if (openList) openList.innerHTML = `<div class="ticket-error-state">${escapeHtml(error.message)}</div>`;
        }
    }
    async function loadConversation(id) {
        const panel = document.getElementById('internalAutomationAdminCreatedConversation');
        if (!panel) return;
        if (openHeading) openHeading.hidden = true;
        if (openList) openList.hidden = true;
        if (openPane) openPane.classList.add('is-conversation-view');
        panel.hidden = false;
        panel.innerHTML = '<div class="internal-automation-admin-detail-loading">در حال دریافت گفتگو…</div>';
        try {
            const response = await fetch(`/api/automation/${encodeURIComponent(id)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.detail || 'دریافت گفتگو ناموفق بود.');
            renderCreatedConversation(data);
        } catch (error) {
            panel.innerHTML = `<div class="ticket-error-state">${escapeHtml(error.message)}</div>`;
        }
    }
    function renderCreatedConversation(data) {
        const panel = document.getElementById('internalAutomationAdminCreatedConversation');
        if (!panel) return;
        if (openHeading) openHeading.hidden = true;
        if (openList) openList.hidden = true;
        if (openPane) openPane.classList.add('is-conversation-view');
        const messages = data.messages || [];
        const participants = Array.isArray(data.participants) ? data.participants : [];
        const completed = data.status === 'completed';
        const controls = [
            `<button type="button" class="internal-automation-admin-back">بازگشت به گفتگوها</button>`,
            !completed ? `<button type="button" class="internal-automation-admin-complete" data-action="admin-complete-internal-conversation" data-conversation-id="${escapeHtml(data.id)}">اتمام گفتگو</button>` : '',
            `<button type="button" class="internal-automation-admin-delete" data-action="admin-delete-internal-conversation" data-conversation-id="${escapeHtml(data.id)}">حذف گفتگو</button>`
        ].join('');
        panel.hidden = false;
        panel.innerHTML = `<header><div><span class="ticket-panel-kicker">${completed ? 'گفت‌وگوی پایان‌یافته' : 'گفت‌وگوی فعال'}</span><h3>${escapeHtml(data.subject)}</h3><p>${participants.map(escapeHtml).join(' ↔ ')}</p></div><div class="internal-automation-admin-created-tools">${controls}<span class="internal-automation-admin-created-status">${completed ? 'پایان‌یافته' : 'باز'}</span></div></header><div class="internal-automation-admin-messages">${messages.length ? messages.map(message => {
            const own = String(message.author_username || '').trim() === currentUsername;
            return `<article class="${own ? 'is-own' : 'is-other'}"><div><strong>${escapeHtml(message.author_username)}</strong><time>${formatMessageDate(message.created_at)}</time></div><p>${escapeHtml(message.body)}</p></article>`;
        }).join('') : '<p class="internal-automation-admin-detail-empty">هنوز پیامی در این گفتگو ثبت نشده است.</p>'}</div>${completed ? '<div class="internal-automation-admin-completed-note">این گفتگو به پایان رسیده است.</div>' : '<form class="internal-automation-admin-composer"><textarea name="body" maxlength="4000" required placeholder="پیام خود را بنویسید…"></textarea><div><button type="submit">ارسال پیام</button><span role="status"></span></div></form>'}`;
        panel.querySelector('.internal-automation-admin-back').addEventListener('click', showOpenConversationList);
        const composer = panel.querySelector('form');
        if (!composer) return;
        composer.addEventListener('submit', async event => {
            event.preventDefault();
            const button = composer.querySelector('button');
            const status = composer.querySelector('[role="status"]');
            const body = composer.body.value.trim();
            if (!body) return;
            button.disabled = true;
            status.textContent = 'در حال ارسال…';
            try {
                const response = await fetch(`/api/automation/${encodeURIComponent(data.id)}/messages`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ body })
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.detail || 'ارسال پیام ناموفق بود.');
                renderCreatedConversation(payload);
            } catch (error) {
                status.textContent = error.message || 'ارسال پیام ناموفق بود.';
                button.disabled = false;
            }
        });
    }
    async function completeConversation(id) {
        if (!id || !await confirmAction('اتمام گفتگو', 'آیا می‌خواهید این گفتگو را به پایان برسانید؟ پیام‌ها حفظ خواهند شد.', 'اتمام گفتگو')) return;
        try {
            const response = await fetch(`/api/automation/${encodeURIComponent(id)}/complete`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.detail || 'اتمام گفتگو ناموفق بود.');
            }
            await loadConversation(id);
            await loadOpenConversations();
            await load();
        } catch (error) {
            showSystemError(error.message || 'اتمام گفتگو ناموفق بود.');
        }
    }
    async function deleteConversation(id) {
        if (!id || !await confirmAction('حذف گفتگو', 'آیا از حذف کامل این گفتگو و پیام‌های آن مطمئن هستید؟ این عملیات قابل بازگشت نیست.', 'حذف گفتگو', true)) return;
        try {
            const response = await fetch(`/api/automation/${encodeURIComponent(id)}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.detail || 'حذف گفتگو ناموفق بود.');
            }
            showOpenConversationList();
            await loadOpenConversations();
            await load();
        } catch (error) {
            showSystemError(error.message || 'حذف گفتگو ناموفق بود.');
        }
    }
    function confirmAction(title, message, confirmLabel, danger = false) {
        return new Promise(resolve => {
            const existing = document.getElementById('internalAutomationAdminConfirm');
            if (existing) existing.remove();
            const modal = document.createElement('div');
            modal.id = 'internalAutomationAdminConfirm';
            modal.className = 'internal-automation-admin-confirm';
            modal.innerHTML = `<div class="internal-automation-admin-confirm-backdrop" data-confirm-cancel></div><section class="internal-automation-admin-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="internalAutomationAdminConfirmTitle"><div class="internal-automation-admin-confirm-icon" aria-hidden="true">${danger ? '!' : '?'}</div><h3 id="internalAutomationAdminConfirmTitle">${escapeHtml(title)}</h3><p>${escapeHtml(message)}</p><div class="internal-automation-admin-confirm-actions"><button type="button" class="internal-automation-admin-confirm-cancel" data-confirm-cancel>انصراف</button><button type="button" class="${danger ? 'internal-automation-admin-confirm-danger' : 'internal-automation-admin-confirm-primary'}" data-confirm-ok>${escapeHtml(confirmLabel)}</button></div></section>`;
            document.body.append(modal);
            const close = result => {
                modal.remove();
                resolve(result);
            };
            modal.querySelector('[data-confirm-cancel]').addEventListener('click', () => close(false));
            modal.querySelector('[data-confirm-ok]').addEventListener('click', () => close(true));
            modal.querySelector('[data-confirm-ok]').focus();
        });
    }
    window.InternalAutomationAdmin = { load };
    document.addEventListener('click', event => {
        if (event.target.closest('[data-accent="automation"]')) load();
        const action = event.target.closest('[data-action]')?.dataset.action;
        const conversationId = event.target.closest('[data-conversation-id]')?.dataset.conversationId;
        if (action === 'admin-complete-internal-conversation') completeConversation(conversationId);
        if (action === 'admin-delete-internal-conversation') deleteConversation(conversationId);
    });
    if (window.location.pathname === '/admin/internal-automation') load();
})();
