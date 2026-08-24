(() => {
    'use strict';

    const STATUS_LABELS = {
        new: 'جدید', open: 'باز', in_progress: 'در حال بررسی',
        waiting_for_user: 'در انتظار کاربر', waiting_for_support: 'در انتظار پشتیبانی',
        resolved: 'حل‌شده', closed: 'بسته‌شده'
    };
    const PRIORITY_LABELS = { low: 'کم', normal: 'عادی', high: 'زیاد', urgent: 'فوری' };
    const LEGACY_STATUS = {
        'ارسال شده': 'new', 'در حال پیگیری': 'in_progress', 'خوانده شده': 'open',
        'پاسخ داده شده': 'waiting_for_user'
    };
    const state = {
        admin: { page: 1, view: '', search: '', priority: '', sort: 'newest', detail: null, loading: false },
        user: { page: 1, status: '', search: '', priority: '', detail: null, sending: false }
    };

    const fa = new Intl.NumberFormat('fa-IR');
    const $ = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const text = (value) => String(value ?? '').trim();

    async function api(url, options = {}) {
        const config = { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.headers || {}) } };
        if (config.body && typeof config.body !== 'string') {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(config.body);
        }
        const response = await fetch(url, config);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.detail || payload.error || payload.message || 'درخواست انجام نشد.');
        return payload;
    }

    function node(tag, className, content) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (content !== undefined) element.textContent = content;
        return element;
    }

    function badge(label, kind) {
        return node('span', `ticket-workspace-badge ticket-workspace-badge--${kind || 'neutral'}`, label);
    }

    function ticketStatus(ticket) {
        const value = text(ticket.status || ticket.ticket_status);
        return STATUS_LABELS[value] ? value : (LEGACY_STATUS[value] || 'new');
    }

    function priorityKind(value) { return text(value || 'normal'); }

    function formatDate(value) {
        if (!value) return '—';
        try { return new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); }
        catch (_) { return text(value); }
    }

    function pagination(container, page, pages, callback) {
        if (!container) return;
        container.replaceChildren();
        if (pages <= 1) return;
        const previous = node('button', 'ticket-page-btn', 'قبلی');
        const next = node('button', 'ticket-page-btn', 'بعدی');
        const info = node('span', 'ticket-page-info', `صفحه ${fa.format(page)} از ${fa.format(pages)}`);
        previous.disabled = page <= 1; next.disabled = page >= pages;
        previous.onclick = () => callback(page - 1); next.onclick = () => callback(page + 1);
        container.append(previous, info, next);
    }

    function loading(container) { if (container) container.replaceChildren(node('div', 'ticket-loading-state', 'در حال دریافت تیکت‌ها…')); }
    function errorState(container, message) { if (container) container.replaceChildren(node('div', 'ticket-error-state', message || 'خطا در دریافت تیکت‌ها.')); }
    function emptyState(container, message) { if (container) container.replaceChildren(node('div', 'ticket-empty-state', message || 'تیکتی مطابق فیلترها پیدا نشد.')); }

    function updateAdminCounts(counts) {
        const map = { all: 'adminViewCountAll', new: 'adminViewCountNew', in_progress: 'adminViewCountProgress', waiting_for_user: 'adminViewCountUser', waiting_for_support: 'adminViewCountSupport', resolved: 'adminViewCountResolved', closed: 'adminViewCountClosed' };
        Object.entries(map).forEach(([status, id]) => { const target = document.getElementById(id); if (target) target.textContent = fa.format(status ? Number(counts[status] || 0) : Object.values(counts).reduce((a, b) => a + Number(b || 0), 0)); });
    }

    function renderAdminList(items) {
        const container = $('#adminTicketList');
        if (!container) return;
        container.replaceChildren();
        if (!items.length) { emptyState(container); return; }
        items.forEach(ticket => {
            const status = ticketStatus(ticket), priority = priorityKind(ticket.priority);
            const card = node('article', 'helpdesk-ticket-item'); card.dataset.ticketId = ticket.id; card.tabIndex = 0;
            const head = node('header', 'helpdesk-ticket-item-head');
            const title = node('div', 'helpdesk-ticket-item-title');
            title.append(node('strong', '', ticket.subject || 'بدون موضوع'), node('span', '', ticket.ticket_number || `HT-${ticket.id}`));
            const labels = node('div', 'helpdesk-ticket-item-badges'); labels.append(badge(STATUS_LABELS[status] || status, status), badge(PRIORITY_LABELS[priority] || priority, `priority-${priority}`));
            head.append(title, labels);
            const meta = node('div', 'helpdesk-ticket-item-meta');
            meta.append(node('span', '', `${text(ticket.requester_username) || '—'} ← ${text(ticket.recipient_username) || '—'}`), node('span', '', ticket.category_name || 'عمومی'), node('time', '', formatDate(ticket.updated_at)));
            const preview = node('p', 'helpdesk-ticket-item-preview', ticket.last_message_preview || 'پیامی ثبت نشده است.');
            const footer = node('footer', 'helpdesk-ticket-item-foot');
            const open = node('button', 'ticket-inline-action', 'مشاهده مکالمه'); open.type = 'button'; open.dataset.openTicket = ticket.id;
            footer.append(open);
            card.append(head, meta, preview, footer); container.append(card);
        });
    }

    async function loadAdmin(page = state.admin.page) {
        const container = $('#adminTicketList'); if (!container) return;
        const current = state.admin; current.page = page; current.loading = true; loading(container);
        const params = new URLSearchParams({ page: current.page, page_size: 20, search: current.search, priority: current.priority, sort: current.sort });
        if (current.view) params.set('status', current.view);
        try {
            const data = await api(`/api/tickets?${params}`);
            updateAdminCounts(data.counts || {}); renderAdminList(data.items || []);
            const summary = $('#adminTicketSummary'); if (summary) summary.textContent = `${fa.format(data.total || 0)} تیکت در این نما`;
            pagination($('#adminTicketPagination'), data.page, data.pages, loadAdmin);
        } catch (error) { errorState(container, error.message); }
        finally { current.loading = false; }
    }

    function appendMessage(container, message, isAdmin, attachments = []) {
        const requester = isAdmin ? state.admin.detail?.requester_username : state.user.detail?.requester_username;
        const isAgentMessage = message.author_username !== requester;
        const item = node('article', `ticket-workspace-message ${message.visibility === 'internal' ? 'ticket-workspace-message--internal' : (isAgentMessage ? 'ticket-workspace-message--agent' : 'ticket-workspace-message--user')}`);
        const head = node('header', 'ticket-workspace-message-head');
        head.append(node('strong', '', message.visibility === 'internal' ? 'یادداشت داخلی' : text(message.author_username) || 'کاربر'), node('time', '', formatDate(message.created_at)));
        item.append(head, node('p', 'ticket-workspace-message-body', message.body));
        const related = attachments.filter(attachment => Number(attachment.message_id) === Number(message.id));
        if (related.length) {
            const files = node('div', 'ticket-attachment-list');
            related.forEach(attachment => {
                const link = node('a', 'ticket-attachment-link', attachment.original_name || 'دانلود پیوست');
                link.href = attachment.download_url; link.target = '_blank'; link.rel = 'noopener';
                files.append(link);
            });
            item.append(files);
        }
        if (message.visibility === 'internal') item.setAttribute('data-internal', 'true');
        container.append(item);
    }

    function appendUnlinkedAttachments(container, attachments) {
        const unlinked = attachments.filter(attachment => attachment.message_id == null);
        if (!unlinked.length) return;
        const files = node('div', 'ticket-attachment-list ticket-attachment-list--standalone');
        files.append(node('strong', '', 'پیوست‌های تیکت'));
        unlinked.forEach(attachment => {
            const link = node('a', 'ticket-attachment-link', attachment.original_name || 'دانلود پیوست');
            link.href = attachment.download_url; link.target = '_blank'; link.rel = 'noopener'; files.append(link);
        });
        container.append(files);
    }

    function renderAdminContext(response) {
        const container = $('#adminTicketContext'); if (!container) return;
        container.replaceChildren();
        const messages = response.messages || [];
        const attachments = response.attachments || [];
        const ticket = response.ticket || response;
        state.admin.detail = ticket;
        const head = node('header', 'helpdesk-context-head');
        const heading = node('div'); heading.append(node('span', 'ticket-panel-kicker', ticket.ticket_number), node('h3', '', ticket.subject));
        const close = node('button', 'helpdesk-context-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'بستن جزئیات'); close.onclick = () => { container.replaceChildren(node('div', 'ticket-context-empty', 'یک تیکت را انتخاب کنید')); state.admin.detail = null; };
        head.append(heading, close); container.append(head);
        const fields = node('div', 'helpdesk-context-fields');
        const status = document.createElement('select'); status.id = 'adminTicketStatus'; status.name = 'status'; status.className = 'ticket-context-select'; status.dataset.contextField = 'status';
        Object.entries(STATUS_LABELS).forEach(([value, label]) => { const option = node('option', '', label); option.value = value; option.selected = value === ticket.status; status.append(option); });
        const priority = document.createElement('select'); priority.id = 'adminTicketPriorityContext'; priority.name = 'priority'; priority.className = 'ticket-context-select'; priority.dataset.contextField = 'priority';
        Object.entries(PRIORITY_LABELS).forEach(([value, label]) => { const option = node('option', '', label); option.value = value; option.selected = value === ticket.priority; priority.append(option); });
        fields.append(node('label', '', 'وضعیت'), status, node('label', '', 'اولویت'), priority);
        const requester = node('div', 'helpdesk-requester-card'); requester.append(node('strong', '', 'درخواست‌کننده'), node('span', '', ticket.requester_username || '—'), node('small', '', ticket.category_name || 'دسته‌بندی عمومی'));
        container.append(fields, requester);
        const timeline = node('div', 'ticket-workspace-timeline');
        messages.forEach(message => appendMessage(timeline, message, true, attachments));
        if (!messages.length) timeline.append(node('div', 'ticket-empty-state', 'هنوز پیامی ثبت نشده است.'));
        appendUnlinkedAttachments(timeline, attachments);
        container.append(timeline);
        const form = document.createElement('form'); form.className = 'ticket-workspace-composer';
        const visibility = document.createElement('select'); visibility.name = 'visibility'; visibility.innerHTML = '<option value="public">پاسخ عمومی</option><option value="internal">یادداشت داخلی</option>';
        const body = document.createElement('textarea'); body.name = 'body'; body.required = true; body.maxLength = 4000; body.placeholder = 'پاسخ یا یادداشت خود را بنویسید…';
        const submit = node('button', 'ticket-new-btn', 'ارسال پیام'); submit.type = 'submit';
        form.append(visibility, body, submit); container.append(form);
        status.onchange = () => updateAdminTicket(ticket.id, { status: status.value });
        priority.onchange = () => updateAdminTicket(ticket.id, { priority: priority.value });
        form.onsubmit = async event => { event.preventDefault(); submit.disabled = true; try { await api(`/api/tickets/${ticket.id}/messages`, { method: 'POST', body: { body: body.value, visibility: visibility.value } }); await openAdminTicket(ticket.id, { scrollToLatest: true }); body.value = ''; await loadAdmin(); } catch (error) { announce(error.message, true); } finally { submit.disabled = false; } };
    }

    async function updateAdminTicket(id, patch) {
        try { await api(`/api/tickets/${id}`, { method: 'PATCH', body: patch }); await loadAdmin(); const detail = await api(`/api/tickets/${id}`); renderAdminContext(detail); }
        catch (error) { announce(error.message, true); }
    }

    async function openAdminTicket(id, options = {}) {
        try {
            renderAdminContext(await api(`/api/tickets/${id}`));
            if (options.scrollToLatest) {
                requestAnimationFrame(() => {
                    const timeline = $('#adminTicketContext .ticket-workspace-timeline');
                    if (timeline) timeline.scrollTop = timeline.scrollHeight;
                });
            }
        }
        catch (error) { errorState($('#adminTicketContext'), error.message); }
    }

    function announce(message, isError) {
        if (window.NotificationSystem && typeof window.NotificationSystem.announce === 'function') {
            window.NotificationSystem.announce(message, isError ? 'error' : 'success');
            return;
        }
        const toast = node('div', `ticket-workspace-toast${isError ? ' is-error' : ''}`, message); toast.setAttribute('role', 'status'); document.body.append(toast); setTimeout(() => toast.remove(), 3500);
    }

    const USER_STATUS_LABELS = {
        new: 'ثبت‌شده', open: 'باز', in_progress: 'در حال بررسی',
        waiting_for_user: 'منتظر پاسخ شما', waiting_for_support: 'در صف پشتیبانی',
        resolved: 'حل‌شده', closed: 'بسته‌شده'
    };
    const USER_EVENT_LABELS = {
        status_changed: 'وضعیت درخواست تغییر کرد', priority_changed: 'اولویت درخواست تغییر کرد',
        assigned: 'درخواست به پشتیبانی ارجاع شد', reply_added: 'پاسخ جدید ثبت شد',
        attachment_added: 'پیوست جدید اضافه شد', reopened: 'درخواست دوباره باز شد'
    };

    function userStatusLabel(status) { return USER_STATUS_LABELS[status] || STATUS_LABELS[status] || 'در حال بررسی'; }

    function setUserViewActive(value) {
        all('#userSupportCenter [data-user-view]').forEach(button => {
            button.classList.toggle('is-active', (button.dataset.userView || '') === value);
        });
    }

    function updateUserSummary(data) {
        const counts = data.counts || {};
        const values = {
            userSupportAllCount: data.total || 0,
            userSupportOpenCount: Number(counts.open || 0) + Number(counts.new || 0) + Number(counts.in_progress || 0),
            userSupportWaitingCount: counts.waiting_for_user || 0,
            userSupportResolvedCount: Number(counts.resolved || 0) + Number(counts.closed || 0)
        };
        Object.entries(values).forEach(([id, value]) => { const target = document.getElementById(id); if (target) target.textContent = fa.format(value); });
    }

    function renderUserList(items) {
        const container = $('#userSupportTicketList'); if (!container) return;
        container.replaceChildren();
        if (!items.length) {
            const empty = node('div', 'user-support-empty-list');
            empty.append(node('div', 'user-support-empty-icon', '✦'), node('h3', '', state.user.search || state.user.status ? 'درخواستی مطابق جست‌وجو پیدا نشد' : 'هنوز درخواستی ثبت نکرده‌اید'), node('p', '', state.user.search || state.user.status ? 'فیلترها را تغییر دهید یا یک درخواست جدید ثبت کنید.' : 'هر زمان به کمک نیاز داشتید، درخواست خود را از همین‌جا ارسال کنید.'));
            const button = node('button', 'user-support-empty-action', 'ثبت درخواست جدید'); button.type = 'button'; button.onclick = openTicketModal; empty.append(button); container.append(empty); return;
        }
        items.forEach(ticket => {
            const status = ticketStatus(ticket), priority = priorityKind(ticket.priority);
            const card = node('article', `user-support-ticket ${status === 'waiting_for_user' ? 'is-waiting' : ''}`); card.dataset.ticketId = ticket.id; card.tabIndex = 0;
            const top = node('div', 'user-support-ticket-top');
            const number = node('span', 'user-support-ticket-number', ticket.ticket_number || `HT-${ticket.id}`);
            const statusBadge = badge(userStatusLabel(status), status); top.append(number, statusBadge);
            const subject = node('h4', 'user-support-ticket-subject', ticket.subject || 'بدون موضوع');
            const activity = node('div', 'user-support-ticket-activity');
            activity.append(node('span', '', ticket.category_name || 'عمومی'), node('span', '', `آخرین پاسخ: ${ticket.last_responder || '—'}`), node('time', '', `ثبت ${formatDate(ticket.created_at)}`));
            const foot = node('div', 'user-support-ticket-foot');
            foot.append(badge(PRIORITY_LABELS[priority] || priority, `priority-${priority}`), node('span', '', ticket.last_message_preview || 'برای مشاهده جزئیات انتخاب کنید.'));
            card.append(top, subject, activity, foot); container.append(card);
        });
    }

    async function loadUser(page = state.user.page) {
        const container = $('#userSupportTicketList'); if (!container) return;
        const current = state.user; current.page = page; loading(container);
        const params = new URLSearchParams({ page: current.page, page_size: 12, search: current.search });
        if (current.status) params.set('status', current.status);
        try {
            const data = await api(`/api/tickets?${params}`);
            updateUserSummary(data); renderUserList(data.items || []); pagination($('#userSupportPagination'), data.page, data.pages, loadUser);
            const activity = $('#userSupportActivityText');
            if (activity) activity.textContent = data.items?.length ? `آخرین تغییر در ${data.items[0].ticket_number} · ${formatDate(data.items[0].updated_at)}` : 'هنوز فعالیتی برای نمایش وجود ندارد.';
        }
        catch (error) { errorState(container, 'دریافت درخواست‌ها ناموفق بود. دوباره تلاش کنید.'); const retry = node('button', 'user-support-empty-action', 'تلاش دوباره'); retry.onclick = () => loadUser(current.page); container.append(retry); }
    }

    function renderUserDetail(response) {
        const container = $('#userSupportDetail'); if (!container) return;
        const detail = response.ticket || response;
        const messages = response.messages || detail.messages || [];
        const attachments = response.attachments || detail.attachments || [];
        const events = response.events || detail.events || [];
        state.user.detail = detail; window.__ticketingActiveDetail = detail; container.replaceChildren();
        const status = ticketStatus(detail), priority = priorityKind(detail.priority);
        const head = node('header', 'user-support-detail-head');
        const titleWrap = node('div'); titleWrap.append(node('span', 'user-support-ticket-number', detail.ticket_number), node('h3', '', detail.subject || 'بدون موضوع'), node('p', '', `آخرین به‌روزرسانی ${formatDate(detail.updated_at)}`));
        const close = node('button', 'user-support-detail-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'بستن جزئیات'); close.onclick = () => { state.user.detail = null; window.__ticketingActiveDetail = null; container.replaceChildren(node('div', 'user-support-detail-empty', 'یک درخواست را انتخاب کنید')); };
        head.append(titleWrap, close); container.append(head);
        const meta = node('div', 'user-support-detail-meta'); meta.append(badge(userStatusLabel(status), status), badge(PRIORITY_LABELS[priority] || priority, `priority-${priority}`), node('span', '', `دسته‌بندی: ${detail.category_name || 'عمومی'}`), node('span', '', `گیرنده: ${detail.recipient_username || '—'}`)); container.append(meta);
        const progress = node('div', 'user-support-progress');
        const stageIndex = { new: 0, open: 1, in_progress: 1, waiting_for_support: 1, waiting_for_user: 2, resolved: 3, closed: 3 }[status] ?? 0;
        [['new','ثبت درخواست'], ['in_progress','بررسی پشتیبانی'], ['waiting_for_user','پاسخ شما'], ['resolved','حل‌شده']].forEach(([value, label], index) => { const step = node('span', index === stageIndex ? 'is-current' : (index < stageIndex ? 'is-done' : ''), label); progress.append(step); });
        container.append(progress);
        const timeline = node('div', 'user-support-timeline');
        messages.forEach(message => {
            const isMine = message.author_username === detail.requester_username;
            const item = node('article', `user-support-message ${message.visibility === 'internal' ? 'is-system' : (isMine ? 'is-mine' : 'is-support')}`);
            const messageHead = node('header', 'user-support-message-head'); messageHead.append(node('strong', '', message.visibility === 'internal' ? 'رویداد سیستم' : (isMine ? 'شما' : 'پشتیبانی')), node('time', '', formatDate(message.created_at)));
            item.append(messageHead, node('p', 'user-support-message-body', message.body));
            const related = attachments.filter(file => Number(file.message_id) === Number(message.id));
            if (related.length) { const files = node('div', 'user-support-attachments'); related.forEach(file => { const link = node('a', '', file.original_name || 'پیوست'); link.href = file.download_url; link.target = '_blank'; link.rel = 'noopener'; files.append(link); }); item.append(files); }
            timeline.append(item);
        });
        events.filter(event => event.event_type !== 'created').forEach(event => timeline.append(node('div', 'user-support-event', `${USER_EVENT_LABELS[event.event_type] || 'رویداد درخواست'} · ${formatDate(event.created_at)}`)));
        if (!timeline.children.length) timeline.append(node('div', 'user-support-empty-list', 'هنوز پیامی در این درخواست وجود ندارد.'));
        appendUnlinkedAttachments(timeline, attachments); container.append(timeline);
        const actions = node('div', 'user-support-detail-actions');
        if (status === 'resolved') { const reopen = node('button', 'user-support-secondary-action', 'بازگشایی درخواست'); reopen.onclick = () => updateUserTicket(detail.id, { status: 'open' }); actions.append(reopen); }
        if (!['resolved','closed'].includes(status)) { const resolve = node('button', 'user-support-secondary-action', 'اعلام حل‌شدن مشکل'); resolve.onclick = () => updateUserTicket(detail.id, { status: 'resolved' }); actions.append(resolve); }
        const copy = node('button', 'user-support-secondary-action', 'کپی شماره درخواست'); copy.onclick = async () => { try { await navigator.clipboard.writeText(detail.ticket_number); announce('شماره درخواست کپی شد.'); } catch (_) { announce(detail.ticket_number); } }; actions.append(copy); container.append(actions);
        if (status !== 'closed') {
            const form = document.createElement('form'); form.className = 'user-support-composer';
            const textarea = document.createElement('textarea'); textarea.id = 'userSupportReply'; textarea.required = true; textarea.maxLength = 4000; textarea.placeholder = 'پاسخ خود را بنویسید…'; textarea.setAttribute('aria-label', 'متن پاسخ');
            const files = document.createElement('input'); files.type = 'file'; files.id = 'userSupportReplyFiles'; files.multiple = true; files.accept = '.pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx,.xls,.xlsx';
            const fileLabel = node('label', 'user-support-file-button', 'افزودن پیوست'); fileLabel.append(files);
            const statusText = node('small', 'user-support-upload-status', ''); const submit = node('button', 'user-support-send', 'ارسال پاسخ'); submit.type = 'submit';
            form.append(textarea, fileLabel, statusText, submit); container.append(form);
            form.onsubmit = async event => { event.preventDefault(); if (!text(textarea.value)) return; submit.disabled = true; try {            await api(`/api/tickets/${detail.id}/messages`, { method: 'POST', body: { body: textarea.value, visibility: 'public' } });
            if (files.files.length) {
                const refreshed = await api(`/api/tickets/${detail.id}`);
                const lastMsg = (refreshed.messages || []).at(-1);
                if (lastMsg) await uploadReplyAttachments(files, detail.id, lastMsg.id, statusText);
            }
            renderUserDetail(await api(`/api/tickets/${detail.id}`)); await loadUser(state.user.page); } catch (error) { announce(error.message, true); } finally { submit.disabled = false; } };
        }
        requestAnimationFrame(() => { const timeline = container.querySelector('.user-support-timeline'); if (timeline) timeline.scrollTop = timeline.scrollHeight; });
    }

    async function updateUserTicket(id, patch) { try { await api(`/api/tickets/${id}`, { method: 'PATCH', body: patch }); renderUserDetail(await api(`/api/tickets/${id}`)); await loadUser(state.user.page); } catch (error) { announce(error.message, true); } }

    async function uploadReplyAttachments(input, ticketId, messageId, statusText) {
        for (const file of Array.from(input.files)) {
            statusText.textContent = `در حال بارگذاری ${file.name}…`;
            const form = new FormData(); form.append('file', file);
            const response = await fetch(`/api/tickets/${ticketId}/attachments?message_id=${encodeURIComponent(messageId)}`, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: form });
            const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.detail || 'بارگذاری پیوست ناموفق بود.');
        }
        statusText.textContent = 'پیوست‌ها با موفقیت بارگذاری شدند.';
    }

    function openUserSupportCenter() { const center = $('#userSupportCenter'); if (!center) return; center.hidden = false; center.setAttribute('aria-hidden', 'false'); document.body.classList.add('user-support-open'); loadUser(state.user.page || 1); }
    function closeUserSupportCenter() { const center = $('#userSupportCenter'); if (!center) return; center.hidden = true; center.setAttribute('aria-hidden', 'true'); document.body.classList.remove('user-support-open'); state.user.detail = null; window.__ticketingActiveDetail = null; }
    async function openUserTicket(id) { try { openUserSupportCenter(); renderUserDetail(await api(`/api/tickets/${id}`)); } catch (error) { announce(error.message, true); } }
    async function sendActiveReply() { const detail = window.__ticketingActiveDetail; const input = $('#userSupportReply'); if (!detail || !input) return; const form = input.closest('form'); if (form) form.requestSubmit(); }
    function closeConversation() { closeUserSupportCenter(); }

    async function uploadTicketAttachments(ticketId) {
        const input = $('#ticketAttachments');
        if (!input || !input.files.length) return;
        for (const file of Array.from(input.files)) {
            const form = new FormData(); form.append('file', file);
            const response = await fetch(`/api/tickets/${ticketId}/attachments`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: form
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.detail || 'بارگذاری پیوست ناموفق بود.');
        }
        input.value = '';
    }

    async function loadTicketUsers() {
        const select = $('#ticketReceiver');
        if (!select || select.dataset.loaded === 'true') return;
        select.replaceChildren(node('option', '', 'در حال دریافت کاربران…'));
        select.options[0].disabled = true;
        try {
            const data = await api('/api/tickets/users');
            select.replaceChildren();
            const placeholder = node('option', '', 'انتخاب کنید');
            placeholder.value = ''; placeholder.disabled = true; placeholder.selected = true;
            select.append(placeholder);
            (data.items || []).forEach(user => {
                const option = node('option', '', user.name ? `${user.name} (${user.username})` : user.username);
                option.value = user.username;
                select.append(option);
            });
            select.dataset.loaded = 'true';
        } catch (error) {
            select.replaceChildren(node('option', '', 'دریافت کاربران ناموفق بود'));
            announce(error.message, true);
        }
    }

    function openTicketModal() {
        const modal = $('#ticketModal');
        if (!modal) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.style.display = 'flex';
        loadTicketUsers();
        loadCategories();
    }

    function closeTicketModal() {
        const modal = $('#ticketModal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';
    }

    async function submitTicketForm(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const submit = form.querySelector('[type="submit"]');
        if (submit?.disabled) return;
        if (submit) submit.disabled = true;
        const category = $('#ticketCategory')?.value || '';
        const payload = {
            recipient_username: $('#ticketReceiver')?.value || '',
            subject: ($('#ticketCreateTitle') || $('#ticketTitleAdmin'))?.value || '',
            body: $('#ticketDescription')?.value || '',
            priority: $('#ticketPriority')?.value || 'normal'
        };
        if (category) payload.category_id = Number(category);
        try {
            const ticket = await api('/api/tickets', { method: 'POST', body: payload });
            let attachmentError = null;
            try { await uploadTicketAttachments(ticket.id); } catch (error) { attachmentError = error; }
            form.reset();
            closeTicketModal();
            announce(attachmentError ? 'تیکت ثبت شد، اما بارگذاری یک پیوست ناموفق بود.' : 'تیکت با موفقیت ثبت شد.', Boolean(attachmentError));
            if ($('#adminTicketList')) await loadAdmin(1);
            if ($('#userSupportTicketList')) { openUserSupportCenter(); await loadUser(1); }
        } catch (error) {
            announce(error.message, true);
        } finally {
            if (submit) submit.disabled = false;
        }
    }

    function loadCategories() {
        const select = $('#ticketCategory'); if (!select || select.dataset.loaded) return;
        api('/api/tickets/categories').then(data => { select.replaceChildren(node('option', '', 'عمومی')); select.options[0].value = ''; (data.items || []).forEach(category => { const option = node('option', '', category.name); option.value = category.id; select.append(option); }); select.dataset.loaded = 'true'; }).catch(() => {});
    }

    function setupForms() {
        const form = $('#ticketForm'); if (form) form.addEventListener('submit', submitTicketForm);
        loadCategories();
        const reply = $('.ticket-reply-send'); if (reply) reply.addEventListener('click', sendActiveReply);
        const input = $('#matnErsali'); if (input) input.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); sendActiveReply(); } });
    }

    function setupAdmin() {
        if (!$('#adminTicketList')) return;
        all('[data-ticket-view]').forEach(button => button.addEventListener('click', () => { all('[data-ticket-view]').forEach(item => item.classList.remove('is-active')); button.classList.add('is-active'); state.admin.view = button.dataset.ticketView === 'all' ? '' : button.dataset.ticketView; loadAdmin(1); }));
        const search = $('#adminTicketSearch'); if (search) search.addEventListener('input', () => { state.admin.search = search.value; clearTimeout(state.admin.timer); state.admin.timer = setTimeout(() => loadAdmin(1), 300); });
        const priority = $('#adminTicketPriority'); if (priority) priority.addEventListener('change', () => { state.admin.priority = priority.value; loadAdmin(1); });
        const sort = $('#adminTicketSort'); if (sort) sort.addEventListener('change', () => { state.admin.sort = sort.value; loadAdmin(1); });
        const refresh = $('#refreshTicketRequests'); if (refresh) refresh.addEventListener('click', () => loadAdmin(1));
        const list = $('#adminTicketList'); list.addEventListener('click', event => { const card = event.target.closest('.helpdesk-ticket-item'); if (card) { event.preventDefault(); openAdminTicket(card.dataset.ticketId); } });
        list.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target.closest('.helpdesk-ticket-item')) openAdminTicket(event.target.closest('.helpdesk-ticket-item').dataset.ticketId); });
        loadAdmin(1);
    }

    function setupUser() {
        if (!$('#userSupportTicketList')) return;
        const search = $('#userSupportSearch'); if (search) search.addEventListener('input', () => { state.user.search = search.value; clearTimeout(state.user.timer); state.user.timer = setTimeout(() => loadUser(1), 300); });
        all('#userSupportCenter [data-user-view]').forEach(button => button.addEventListener('click', () => { state.user.status = button.dataset.userView || ''; setUserViewActive(state.user.status); loadUser(1); }));
        const list = $('#userSupportTicketList'); list.addEventListener('click', event => { const ticket = event.target.closest('[data-ticket-id]'); if (ticket) openUserTicket(ticket.dataset.ticketId); });
        list.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target.closest('[data-ticket-id]')) openUserTicket(event.target.closest('[data-ticket-id]').dataset.ticketId); });
        loadUser(1);
    }

    document.addEventListener('DOMContentLoaded', () => { setupAdmin(); setupUser(); setupForms(); });
    window.TicketingWorkspace = { loadAdmin, loadUser, openAdminTicket, openUserTicket };
    window.openUserSupportCenter = openUserSupportCenter;
    window.closeUserSupportCenter = closeUserSupportCenter;
    window.openTicketModal = openTicketModal;
    window.closeTicketModal = closeTicketModal;
    window.uploadTicketAttachments = uploadTicketAttachments;
    window.sendTicketResponse = sendActiveReply;
    window.hideViewDialog = closeConversation;
})();
