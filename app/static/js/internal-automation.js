(() => {
    'use strict';
    const state = { active: null, users: null };
    const currentUsername = document.body.dataset.notificationActor || '';
    const $ = (s, r = document) => r.querySelector(s);
    const text = value => String(value ?? '').trim();
    const fa = new Intl.NumberFormat('fa-IR');
    function localizedError(error) {
        const message = text(error && error.message);
        if (/not found|پیدا نشد|گفتگو پیدا نشد/i.test(message)) return 'این گفتگو دیگر در دسترس نیست یا حذف شده است.';
        if (/unauthorized|forbidden|وارد سامانه/i.test(message)) return 'برای مشاهدهٔ این بخش باید دوباره وارد سامانه شوید.';
        return message || 'دریافت اطلاعات گفتگوها ناموفق بود.';
    }
    async function api(url, options = {}) {
        const config = { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.headers || {}) } };
        if (config.body && typeof config.body !== 'string') {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(config.body);
        }
        const response = await fetch(url, config);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(payload.detail || 'درخواست انجام نشد.');
            error.status = response.status;
            throw error;
        }
        return payload;
    }
    const formatDate = value => value ? new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
    function openCenter() {
        const center = $('#internalAutomationCenter');
        if (!center) return;
        center.hidden = false; center.setAttribute('aria-hidden', 'false'); document.body.classList.add('internal-automation-open');
        loadList();
    }
    function closeCenter() {
        const center = $('#internalAutomationCenter');
        if (!center) return;
        center.hidden = true; center.setAttribute('aria-hidden', 'true'); document.body.classList.remove('internal-automation-open');
    }
    function renderList(items) {
        const list = $('#internalAutomationList'); if (!list) return;
        list.replaceChildren();
        if (!items.length) {
            list.innerHTML = '<div class="internal-automation-list-empty"><span class="internal-automation-list-empty-icon">✦</span><strong>هنوز گفت‌وگویی ایجاد نکرده‌اید</strong><p>برای شروع ارتباط با همکاران، روی «گفت‌وگوی جدید» بزنید.</p><button type="button" data-action="new-internal-conversation">+ شروع گفت‌وگو</button></div>';
            return;
        }
        items.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'internal-automation-item';
            if (Number(item.id) === Number(state.active)) button.classList.add('is-active');
            button.dataset.conversationId = item.id;
            button.innerHTML = `<strong>${escapeHtml(item.subject)}</strong><span>${fa.format(item.participant_count || 0)} همکار · ${fa.format(item.message_count || 0)} پیام</span><time>${formatDate(item.updated_at)}</time>`;
            button.addEventListener('click', () => openConversation(item.id));
            list.append(button);
        });
    }
    function escapeHtml(value) { return text(value).replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[ch])); }
    async function loadList() {
        const list = $('#internalAutomationList'); if (!list) return;
        list.innerHTML = '<div class="internal-automation-loading">در حال دریافت گفتگوها…</div>';
        try { const data = await api('/api/automation'); renderList(data.items || []); }
        catch (error) {
            if (error.status === 404 || error.code === 'EMPTY_CONVERSATIONS') {
                renderList([]);
                return;
            }
            list.innerHTML = `<div class="internal-automation-error">${escapeHtml(localizedError(error))}</div>`;
        }
    }
    function renderConversation(data) {
        const pane = $('#internalAutomationConversation'); if (!pane) return;
        const messages = data.messages || [];
        const completed = data.status === 'completed';
        const ownPending = (data.reopen_requests || []).find(item => item.status === 'pending' && String(item.requester).trim() === String(currentUsername).trim());
        const pending = (data.reopen_requests || []).find(item => item.status === 'pending' && String(item.requester).trim() !== String(currentUsername).trim());
        const controls = completed
            ? (pending ? `<button type="button" class="internal-automation-reopen" data-action="approve-internal-reopen" data-conversation-id="${data.id}" data-request-id="${pending.id}">تأیید شروع مجدد</button>` : `<button type="button" class="internal-automation-reopen" data-action="request-internal-reopen" data-conversation-id="${data.id}"${ownPending ? ' disabled' : ''}>${ownPending ? 'در انتظار تأیید طرف مقابل' : 'درخواست شروع مجدد'}</button>`)
            : `<button type="button" class="internal-automation-complete" data-action="complete-internal-conversation" data-conversation-id="${data.id}">اتمام گفتگو</button>`;
        pane.innerHTML = `<header class="internal-automation-conversation-head"><div><span>${completed ? 'گفت‌وگو پایان یافته' : 'گفت‌وگوی سازمانی'}</span><h3>${escapeHtml(data.subject)}</h3></div><div class="internal-automation-conversation-tools"><small>${fa.format(messages.length)} پیام</small>${controls}<button type="button" class="internal-automation-delete" data-action="delete-internal-conversation" data-conversation-id="${data.id}" title="حذف گفتگو">حذف گفتگو</button></div></header><div class="internal-automation-messages">${messages.map(message => {
            const own = String(message.author_username || '').trim() === String(currentUsername).trim();
            return `<article class="internal-automation-message${own ? ' is-own' : ' is-other'}"><div><strong>${escapeHtml(message.author_username)}</strong><time>${formatDate(message.created_at)}</time></div><p>${escapeHtml(message.body)}</p>${(data.attachments || []).filter(file => Number(file.message_id) === Number(message.id)).map(file => `<a class="internal-automation-file" target="_blank" rel="noopener" href="/api/automation/${data.id}/attachments/${file.id}">📎 ${escapeHtml(file.original_name)}</a>`).join('')}</article>`;
        }).join('') || '<div class="internal-automation-list-empty">هنوز پیامی ثبت نشده است.</div>'}</div>${completed ? '<div class="internal-automation-completed-note">این گفتگو به پایان رسیده و برای ارسال پیام باید دوباره فعال شود.</div>' : '<form class="internal-automation-composer"><textarea name="body" maxlength="4000" required placeholder="پیام خود را بنویسید…"></textarea><div><label class="internal-automation-file-picker">📎 فایل<input type="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx,.xls,.xlsx"></label><button type="submit">ارسال پیام</button><span class="internal-automation-status"></span></div></form>'}`;
        const messagesBox = $('.internal-automation-messages', pane); messagesBox.scrollTop = messagesBox.scrollHeight;
        const composer = $('.internal-automation-composer', pane);
        if (!composer) return;
        composer.addEventListener('submit', async event => {
            event.preventDefault(); const form = event.currentTarget; const body = form.body.value.trim(); const submit = form.querySelector('button[type=submit]'); const status = $('.internal-automation-status', form);
            if (!body || submit.disabled) return; submit.disabled = true; status.textContent = 'در حال ارسال…';
            try {
                const message = await api(`/api/automation/${data.id}/messages`, { method: 'POST', body: { body } });
                const file = form.file.files[0];
                if (file) { const upload = new FormData(); upload.append('file', file); upload.append('message_id', message.messages?.at(-1)?.id || ''); await fetch(`/api/automation/${data.id}/attachments`, { method: 'POST', credentials: 'same-origin', body: upload }); }
                form.reset(); await openConversation(data.id); await loadList();
            } catch (error) { status.textContent = error.message; } finally { submit.disabled = false; }
        });
    }
    async function openConversation(id) { state.active = id; try { renderConversation(await api(`/api/automation/${id}`)); await loadList(); } catch (error) { const pane = $('#internalAutomationConversation'); if (pane) pane.innerHTML = `<div class="internal-automation-error">${escapeHtml(localizedError(error))}</div>`; } }
    async function deleteConversation(id) {
        if (!id || !window.confirm('آیا از حذف کامل این گفتگو و پیام‌های آن مطمئن هستید؟')) return;
        try {
            await api(`/api/automation/${id}`, { method: 'DELETE' });
            state.active = null;
            const pane = $('#internalAutomationConversation');
            if (pane) pane.innerHTML = '<div class="internal-automation-empty"><span>✦</span><h3>گفتگو حذف شد</h3><p>می‌توانید یک گفت‌وگوی جدید ایجاد کنید.</p></div>';
            await loadList();
        } catch (error) {
            const pane = $('#internalAutomationConversation');
            if (pane) pane.insertAdjacentHTML('afterbegin', `<div class="internal-automation-error">${escapeHtml(localizedError(error))}</div>`);
        }
        async function conversationAction(url, id, successText) {
            try { await api(`/api/automation/${id}/${url}`, { method: 'POST' }); await openConversation(id); }
            catch (error) { const pane = $('#internalAutomationConversation'); if (pane) pane.insertAdjacentHTML('afterbegin', `<div class="internal-automation-error">${escapeHtml(localizedError(error))}</div>`); }
        }
    }
    async function openCreateModal() {
        const modal = $('#internalAutomationCreateModal');
        const select = $('select[name="participant"]', modal);
        if (!modal || !select) return;
        modal.hidden = false; modal.setAttribute('aria-hidden', 'false');
        select.replaceChildren(new Option('در حال دریافت کاربران…', ''));
        try {
            if (state.users === null) state.users = (await api('/api/tickets/users')).items || [];
            select.replaceChildren(new Option('انتخاب همکار', ''));
            state.users.forEach(user => select.append(new Option(user.name ? `${user.name} (${user.username})` : user.username, user.username)));
        } catch (error) {
            select.replaceChildren(new Option('دریافت کاربران ناموفق بود', ''));
            $('.internal-automation-create-error', modal).textContent = localizedError(error);
        }
    }
    function closeCreateModal() {
        const modal = $('#internalAutomationCreateModal');
        if (!modal) return;
        modal.hidden = true; modal.setAttribute('aria-hidden', 'true');
        $('#internalAutomationCreateForm')?.reset();
        const error = $('.internal-automation-create-error', modal);
        if (error) error.textContent = '';
    }
    async function submitCreate(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('[type="submit"]');
        const error = $('.internal-automation-create-error', form);
        if (!form.reportValidity() || submit.disabled) return;
        submit.disabled = true; error.textContent = '';
        try {
            const result = await api('/api/automation', { method: 'POST', body: {
                subject: form.subject.value.trim(),
                participants: [form.participant.value],
                body: form.body.value.trim()
            }});
            closeCreateModal();
            state.active = Number(result.id || result.conversation_id);
            if (state.active && result.messages) renderConversation(result);
            else if (state.active) await openConversation(state.active);
            else throw new Error('شناسهٔ گفتگوی ایجادشده دریافت نشد.');
            await loadList();
        } catch (requestError) {
            error.textContent = localizedError(requestError);
        } finally { submit.disabled = false; }
    }
    document.addEventListener('click', event => {
        const action = event.target.closest('[data-action]')?.dataset.action;
        if (action === 'open-internal-automation') { event.preventDefault(); openCenter(); }
        if (action === 'close-internal-automation') closeCenter();
        if (action === 'refresh-internal-automation') loadList();
        if (action === 'new-internal-conversation') openCreateModal();
        if (action === 'close-internal-automation-create') closeCreateModal();
        if (action === 'delete-internal-conversation') deleteConversation(event.target.closest('[data-conversation-id]')?.dataset.conversationId);
        if (action === 'complete-internal-conversation') {
            const id = event.target.closest('[data-conversation-id]')?.dataset.conversationId;
            if (id && window.confirm('آیا می‌خواهید این گفتگو را به پایان برسانید؟ پیام‌ها حفظ خواهند شد.')) conversationAction('complete', id);
        }
        if (action === 'request-internal-reopen') conversationAction('reopen-request', event.target.closest('[data-conversation-id]')?.dataset.conversationId);
        if (action === 'approve-internal-reopen') conversationAction(`reopen-request/${event.target.closest('[data-request-id]')?.dataset.requestId}/approve`, event.target.closest('[data-conversation-id]')?.dataset.conversationId);
    });
    document.getElementById('internalAutomationCreateForm')?.addEventListener('submit', submitCreate);
    window.InternalAutomation = { open: openCenter, close: closeCenter, load: loadList };
})();
