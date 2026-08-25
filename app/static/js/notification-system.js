/* Integrated admin/user notification centre. No third-party dependency. */
(function () {
    'use strict';
    var fa = new Intl.NumberFormat('fa-IR');
    var faDate = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' });
    var adminState = { page: 1, items: new Map(), targets: null, loaded: false };
    var userState = { page: 1, state: 'all', search: '', items: new Map() };
    var debounceTimer;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }
    function label(map, value) { return (map[value] || value || '—'); }
    var types = { general:'عمومی', announcement:'اطلاعیه', system:'سیستمی', warning:'هشدار', information:'اطلاعات', success:'موفقیت', reminder:'یادآوری' };
    var priorities = { normal:'عادی', important:'مهم', high:'بالا', critical:'بحرانی' };
    var statuses = { draft:'پیش‌نویس', scheduled:'زمان‌بندی‌شده', published:'منتشرشده', archived:'بایگانی' };
    var targets = { all:'همه کاربران', selected:'کاربران منتخب', role:'نقش کاربری', department:'واحد سازمانی' };
    var typeIcons = { general:'●', announcement:'◆', system:'⚙', warning:'!', information:'i', success:'✓', reminder:'◷' };
    function formatDate(value) { try { return value ? faDate.format(new Date(value)) : '—'; } catch (_) { return '—'; } }

    async function api(url, options) {
        var config = Object.assign({ credentials: 'same-origin', headers: { 'Accept':'application/json' } }, options || {});
        if (config.body && typeof config.body !== 'string') {
            config.headers = Object.assign({}, config.headers, { 'Content-Type':'application/json' });
            config.body = JSON.stringify(config.body);
        }
        var response = await fetch(url, config);
        var payload = await response.json().catch(function () { return {}; });
        if (!response.ok) {
            var detail = payload.detail;
            if (Array.isArray(detail)) detail = detail.map(function (x) { return x.msg; }).join('، ');
            throw new Error(detail || 'درخواست انجام نشد. دوباره تلاش کنید.');
        }
        return payload;
    }

    /* اعلان بومی Chrome برای زمانی که کاربر در تب دیگری یا نرم‌افزار دیگری است. */
    var browserNotificationState = {
        initialized: false,
        timer: null,
        notifications: Object.create(null),
        polling: false,
        stream: null,
        testSent: false
    };
    function browserNotificationsSupported() {
        return typeof window !== 'undefined' && 'Notification' in window;
    }

    function urlBase64ToUint8Array(value) {
        var padding = '='.repeat((4 - value.length % 4) % 4);
        var raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, function (char) { return char.charCodeAt(0); });
    }

    async function registerWebPush() {
        if (!browserNotificationsSupported() || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
        if (window.isSecureContext === false) return;
        try {
            var registration = await navigator.serviceWorker.register('/hastama-sw.js', { scope: '/' });
            if (!registration.active && !registration.waiting && !registration.installing) {
                throw new Error('Service Worker فعال نشد.');
            }
            var config = await api('/api/push/config');
            if (!config.enabled || !config.public_key) {
                console.info('Web Push فعال نیست: کلید VAPID روی سرور تنظیم نشده است.');
                return;
            }
            var subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(config.public_key)
                });
            }
            await api('/api/push/subscribe', { method: 'POST', body: subscription.toJSON() });
        } catch (error) {
            console.warn('ثبت Web Push انجام نشد:', error.message || error);
        }
    }

    function requestBrowserNotificationPermission() {
        if (!browserNotificationsSupported()) {
            announce('این مرورگر از اعلان دسکتاپ پشتیبانی نمی‌کند.', true);
            return Promise.resolve('unsupported');
        }
        if (window.isSecureContext === false) {
            var hostHint = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
                ? 'آدرس فعلی باید با http://localhost باز شود.'
                : 'اگر روی همین سیستم هستید با http://localhost وارد شوید؛ برای دسترسی شبکه‌ای HTTPS لازم است.';
            announce('اعلان Chrome در این آدرس فعال نمی‌شود. ' + hostHint, 'warning');
            return Promise.resolve('insecure');
        }
        if (Notification.permission === 'granted') {
            registerWebPush();
            return Promise.resolve('granted');
        }
        if (Notification.permission === 'denied') {
            announce('اعلان‌ها مسدود هستند؛ آن‌ها را از تنظیمات سایت Chrome فعال کنید.', true);
            return Promise.resolve('denied');
        }
        return Notification.requestPermission().then(function (permission) {
            if (permission === 'granted') registerWebPush();
            announce(permission === 'granted' ? 'اعلان‌های مرورگر فعال شد.' : 'اجازه اعلان داده نشد.', permission !== 'granted');
            return permission;
        }).catch(function () {
            announce('دریافت اجازه اعلان مرورگر ناموفق بود.', true);
            return 'error';
        });
    }

    function sendBrowserNotification(title, body, tag, actionUrl) {
        if (!browserNotificationsSupported() || Notification.permission !== 'granted' || window.isSecureContext === false) return;
        try {
            var notification = new Notification(title, {
                body: body,
                icon: '/static/images/newlogo.png',
                tag: tag || 'hastama-notification'
            });
            notification.onclick = function () {
                window.focus();
                notification.close();
                if (actionUrl && actionUrl.charAt(0) === '/') window.location.assign(actionUrl);
            };
        } catch (error) {
            console.warn('اعلان مرورگر ارسال نشد:', error);
        }
    }

    function pollBrowserNotifications() {
        if (!browserNotificationsSupported() || browserNotificationState.polling) return;
        browserNotificationState.polling = true;
        api('/api/notifications/poll').then(function (data) {
            var notifications = data.notifications || [];
            if (!browserNotificationState.initialized) {
                notifications.forEach(function (item) { browserNotificationState.notifications[String(item.id)] = true; });
                browserNotificationState.initialized = true;
                return;
            }
            notifications.forEach(function (item) {
                var key = String(item.id);
                if (browserNotificationState.notifications[key]) return;
                browserNotificationState.notifications[key] = true;
                sendBrowserNotification(
                    item.title || 'اعلان جدید هستما',
                    item.content || 'یک اعلان جدید دریافت شد.',
                    'notification-' + key,
                    item.action_url
                );
            });
        }).catch(function () { /* قطع موقت شبکه نباید رابط کاربری را مختل کند. */ })
            .then(function () { browserNotificationState.polling = false; });
    }

    function startBrowserNotificationPolling() {
        if (!browserNotificationsSupported()) return;
        pollBrowserNotifications();
        // این poll پشتیبان SSE است؛ وضعیت polling از هم‌پوشانی جلوگیری می‌کند.
        browserNotificationState.timer = setInterval(pollBrowserNotifications, 5000);
    }

    function startNotificationStream() {
        if (!browserNotificationsSupported() || typeof EventSource === 'undefined') return;
        var stream = new EventSource('/api/notifications/stream');
        stream.onmessage = function (event) {
            try {
                var item = JSON.parse(event.data || '{}');
                var key = String(item.id || '');
                if (!key || browserNotificationState.notifications[key]) return;
                browserNotificationState.notifications[key] = true;
                sendBrowserNotification(
                    item.title || 'اعلان جدید هستما',
                    item.content || 'یک اعلان جدید دریافت شد.',
                    'notification-' + key,
                    item.action_url
                );
            } catch (_) { /* داده‌ی خراب از stream نباید رابط کاربری را متوقف کند. */ }
        };
    }

    function announce(message, error, options) {
        var requestedKind = options && options.kind;
        var kind = requestedKind || (typeof error === 'string' ? error : (error ? 'error' : 'success'));
        if (['success', 'warning', 'error', 'info'].indexOf(kind) === -1) kind = 'info';
        var titles = { success: 'عملیات موفق', warning: 'توجه سامانه', error: 'خطای سامانه', info: 'پیام سامانه' };
        var icons = { success: '✓', warning: '!', error: '×', info: 'i' };
        var duration = kind === 'error' ? 6000 : 4500;
        var stack = document.getElementById('notificationToastStack');
        if (!stack) {
            stack = el('div', 'notification-toast-stack');
            stack.id = 'notificationToastStack';
            document.body.appendChild(stack);
        }
        var toast = el('div', 'notification-toast notification-toast--' + kind);
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
        toast.style.setProperty('--notification-duration', duration + 'ms');
        var head = el('div', 'notification-toast__head');
        var icon = el('span', 'notification-toast__icon', icons[kind]);
        icon.setAttribute('aria-hidden', 'true');
        var title = el('strong', 'notification-toast__title', (options && options.title) || titles[kind]);
        var close = el('button', 'notification-toast__close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'بستن پیام');
        var messageNode = el('span', 'notification-toast__message', message);
        var timeline = el('div', 'notification-toast__timeline');
        timeline.append(el('span'));
        head.append(icon, title, close);
        toast.append(head, messageNode, timeline);
        stack.append(toast);
        var dismissed = false;
        var dismiss = function () {
            if (dismissed) return;
            dismissed = true;
            clearTimeout(timer);
            toast.classList.remove('show');
            toast.classList.add('is-closing');
            setTimeout(function () { toast.remove(); }, 450);
        };
        close.addEventListener('click', dismiss);
        var timer = setTimeout(dismiss, duration);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        return toast;
    }

    function pagination(container, page, pages, callback) {
        if (!container) return; container.replaceChildren();
        if (pages <= 1) return;
        var prev = el('button', '', 'قبلی'); prev.disabled = page <= 1;
        var next = el('button', '', 'بعدی'); next.disabled = page >= pages;
        var info = el('span', '', 'صفحه ' + fa.format(page) + ' از ' + fa.format(pages));
        prev.onclick = function(){ callback(page - 1); }; next.onclick = function(){ callback(page + 1); };
        container.append(prev, info, next);
    }

    async function loadAdmin(page) {
        var root = document.getElementById('notificationAdminBox'); if (!root) return;
        adminState.page = page || adminState.page;
        var query = new URLSearchParams({ page:adminState.page, page_size:15,
            search:(document.getElementById('adminNotificationSearch').value || ''),
            status:document.getElementById('adminNotificationStatus').value,
            type:document.getElementById('adminNotificationType').value,
            priority:document.getElementById('adminNotificationPriority').value });
        var body = document.getElementById('adminNotificationRows');
        body.replaceChildren(); var loadingRow=el('tr'); var loading=el('td','notification-loading','در حال دریافت اعلان‌ها…'); loading.colSpan=6; loadingRow.append(loading); body.append(loadingRow);
        try {
            var data = await api('/api/admin/notifications?' + query);
            adminState.loaded = true; adminState.items.clear(); data.items.forEach(function(item){ adminState.items.set(String(item.id), item); });
            renderAdminRows(data.items);
            ['total','published','draft','scheduled','archived'].forEach(function(key){
                var id='notificationStat'+key.charAt(0).toUpperCase()+key.slice(1); var node=document.getElementById(id);
                if(node) node.textContent=fa.format(Number(data.stats[key] || 0));
            });
            var count=document.getElementById('adminScheduledCount'); var scheduled=Number(data.stats.scheduled || 0);
            if(count){ count.textContent=fa.format(scheduled); count.hidden=!scheduled; }
            pagination(document.getElementById('adminNotificationPagination'), data.page, data.pages, loadAdmin);
        } catch(error) {
            body.replaceChildren(); var row=el('tr'); var cell=el('td','notification-error',error.message); cell.colSpan=6; row.append(cell); body.append(row);
        }
    }

    function badge(kind, value, map) { var b=el('span','notification-badge notification-badge--'+value); b.append(el('span','notification-badge-icon', kind==='type' ? typeIcons[value] : '●'), document.createTextNode(label(map,value))); return b; }
    function renderAdminRows(items) {
        var body=document.getElementById('adminNotificationRows'); body.replaceChildren();
        if(!items.length){ var r=el('tr'), c=el('td','notification-empty','اعلانی مطابق فیلترها پیدا نشد.'); c.colSpan=6; r.append(c); body.append(r); return; }
        items.forEach(function(item){
            var row=el('tr');
            var title=el('td','notification-title-cell'); title.append(el('strong','',item.title),el('span','',item.content));
            var kind=el('td','notification-badges'); var badgeList=el('div','notification-badges-list'); badgeList.append(badge('type',item.type,types),badge('priority',item.priority,priorities)); kind.append(badgeList);
            var audience=el('td','',label(targets,item.target_type));
            var state=el('td'); state.append(badge('status',item.status,statuses));
            var date=el('td','',formatDate(item.published_at || item.scheduled_at || item.created_at));
            var actions=el('td','notification-row-actions');
            if(item.status==='draft'||item.status==='scheduled') actions.append(actionButton('ویرایش','edit',item.id),actionButton('انتشار','publish',item.id));
            if(item.status!=='archived') actions.append(actionButton('بایگانی','archive',item.id));
            if(item.status!=='published') actions.append(actionButton('حذف','delete',item.id));
            row.append(title,kind,audience,state,date,actions); body.append(row);
        });
    }
    function actionButton(text, action, id){ var b=el('button','notification-action-btn',text); b.type='button'; b.dataset.action=action; b.dataset.id=id; return b; }

    async function markAllAdminRead(){
        var button=document.getElementById('adminMarkAllRead');
        if(button) button.disabled=true;
        try{
            var result=await api('/api/admin/notifications/read-all',{method:'POST'});
            announce(result.updated ? 'همه اعلان‌های شما خوانده شد.' : 'اعلان خوانده‌نشده‌ای برای شما وجود ندارد.');
        }catch(error){
            announce(error.message,true);
        }finally{
            if(button) button.disabled=false;
        }
    }

    async function ensureTargets(){ if(!adminState.targets) adminState.targets=await api('/api/admin/notification-targets'); return adminState.targets; }
    function openEditor(item){
        var dialog=document.getElementById('notificationEditor'); dialog.hidden=false; document.body.classList.add('notification-dialog-open');
        document.getElementById('notificationEditorTitle').textContent=item?'ویرایش اعلان':'ایجاد اعلان';
        document.getElementById('notificationId').value=item?item.id:''; document.getElementById('notificationTitle').value=item?item.title:'';
        document.getElementById('notificationContent').value=item?item.content:''; document.getElementById('notificationType').value=item?item.type:'general';
        document.getElementById('notificationPriority').value=item?item.priority:'normal'; document.getElementById('notificationTargetType').value=item?item.target_type:'all';
        document.getElementById('notificationActionLabel').value=item&&item.action_label||''; document.getElementById('notificationActionUrl').value=item&&item.action_url||'';
        document.getElementById('notificationScheduledAt').value=item&&item.scheduled_at ? new Date(item.scheduled_at).toISOString().slice(0,16):'';
        document.getElementById('notificationFormError').textContent=''; updateCount(); updateTargetOptions(item ? String(item.target_values||'').split('||') : []);
        setTimeout(function(){ document.getElementById('notificationTitle').focus(); },30);
    }
    function closeEditor(){ var d=document.getElementById('notificationEditor'); if(d)d.hidden=true; document.body.classList.remove('notification-dialog-open'); }
    async function updateTargetOptions(selected){
        var type=document.getElementById('notificationTargetType').value, field=document.getElementById('notificationTargetsField'), select=document.getElementById('notificationTargets');
        field.hidden=type==='all'; select.replaceChildren(); if(type==='all') return;
        try { var data=await ensureTargets(), options=[];
            if(type==='selected') options=data.users.map(function(u){return {value:u.username,text:(u.name+' '+u.last_name).trim()+' — '+u.username};});
            else options=(type==='role'?data.roles:data.departments).map(function(v){return {value:v,text:v};});
            options.forEach(function(o){var op=el('option','',o.text);op.value=o.value;op.selected=selected.indexOf(o.value)>=0;select.append(op);});
        } catch(error){ document.getElementById('notificationFormError').textContent=error.message; }
    }
    function updateCount(){ var value=document.getElementById('notificationContent').value.length; document.getElementById('notificationContentCount').textContent=fa.format(value); }
    async function submitNotification(status){
        var scheduleField=document.getElementById('notificationScheduleField'), schedule=document.getElementById('notificationScheduledAt');
        scheduleField.hidden=status!=='scheduled';
        if(status==='scheduled'&&!schedule.value){ schedule.focus(); document.getElementById('notificationFormError').textContent='زمان انتشار را انتخاب کنید، سپس دوباره «زمان‌بندی» را بزنید.'; return; }
        var form=document.getElementById('notificationForm'); if(!form.reportValidity()) return;
        var payload={ title:document.getElementById('notificationTitle').value, content:document.getElementById('notificationContent').value,
            type:document.getElementById('notificationType').value, priority:document.getElementById('notificationPriority').value, status:status,
            target_type:document.getElementById('notificationTargetType').value, targets:Array.from(document.getElementById('notificationTargets').selectedOptions).map(function(o){return o.value;}),
            action_label:document.getElementById('notificationActionLabel').value||null, action_url:document.getElementById('notificationActionUrl').value||null,
            scheduled_at:status==='scheduled'?new Date(schedule.value).toISOString():null };
        var id=document.getElementById('notificationId').value, buttons=form.querySelectorAll('button'); buttons.forEach(function(b){b.disabled=true;});
        try { await api(id?'/api/admin/notifications/'+id:'/api/admin/notifications',{method:id?'PUT':'POST',body:payload}); closeEditor(); announce(status==='published'?'اعلان منتشر شد.':'اعلان ذخیره شد.'); loadAdmin(1); }
        catch(error){ document.getElementById('notificationFormError').textContent=error.message; }
        finally { buttons.forEach(function(b){b.disabled=false;}); }
    }
    async function adminAction(action,id){
        if(action==='edit'){openEditor(adminState.items.get(String(id)));return;}
        if(action==='delete'&&!confirm('این اعلان برای همیشه حذف شود؟'))return;
        if(action==='archive'&&!confirm('این اعلان بایگانی شود؟'))return;
        try{await api('/api/admin/notifications/'+id+(action==='publish'?'/publish':action==='archive'?'/archive':''),{method:action==='delete'?'DELETE':'POST'});announce(action==='publish'?'اعلان منتشر شد.':'تغییرات انجام شد.');loadAdmin();}catch(error){announce(error.message,true);}
    }

    function setUnread(count){ ['notificationUnreadBadge','sidebarNotificationCount'].forEach(function(id){var n=document.getElementById(id);if(n){n.textContent=count>99?'۹۹+':fa.format(count);n.hidden=!count;}}); }
    async function loadUser(page, recent){
        userState.page=page||1; var query=new URLSearchParams({page:userState.page,page_size:recent?5:12,state:recent?'all':userState.state,search:recent?'':userState.search});
        var container=document.getElementById(recent?'notificationRecentList':'notificationUserList'); if(!container)return;
        container.replaceChildren(el('div','notification-loading','در حال دریافت اعلان‌ها…'));
        try{var data=await api('/api/notifications?'+query);setUnread(data.unread);data.items.forEach(function(i){userState.items.set(String(i.id),i);});renderUserItems(container,data.items,recent);if(!recent)pagination(document.getElementById('userNotificationPagination'),data.page,data.pages,function(p){loadUser(p,false);});}
        catch(error){container.replaceChildren(el('div','notification-error',error.message));}
    }
    function renderUserItems(container,items,recent){container.replaceChildren();if(!items.length){var empty=el('div','notification-empty-state');empty.append(el('span','notification-empty-icon','✓'),el('strong','',recent?'اعلان تازه‌ای ندارید':'اعلانی پیدا نشد'),el('p','',recent?'همه‌چیز را دیده‌اید.':'فیلتر یا عبارت جستجو را تغییر دهید.'));container.append(empty);return;}
        items.forEach(function(item){var card=el('article','notification-user-item'+(!item.read_at?' is-unread':''));card.tabIndex=0;card.dataset.id=item.id;
            var icon=el('span','notification-type-icon notification-type-icon--'+item.type,typeIcons[item.type]);icon.setAttribute('aria-hidden','true');
            var content=el('div','notification-user-content');var heading=el('div','notification-user-heading');heading.append(el('strong','',item.title));if(!item.read_at)heading.append(el('span','notification-unread-label','خوانده‌نشده'));content.append(heading,el('p','',item.content),el('time','',formatDate(item.published_at||item.delivered_at)));
            var menu=el('div','notification-user-actions');if(!recent){menu.append(actionButton(item.read_at?'خوانده‌نشده':'خوانده‌شده',item.read_at?'unread':'read',item.id),actionButton('حذف','dismiss',item.id));}
            card.append(icon,content,menu);container.append(card);});
    }
    async function mutateUser(action,id){try{await api('/api/notifications/'+id+(action==='dismiss'?'':('/'+action)),{method:action==='dismiss'?'DELETE':'POST'});await loadUser(userState.page,false);loadUser(1,true);}catch(error){announce(error.message,true);}}
    function showDetail(item){if(!item)return;var dialog=document.getElementById('notificationDetail'),body=document.getElementById('notificationDetailBody');body.replaceChildren();
        var top=el('div','notification-detail-type');top.append(badge('type',item.type,types),badge('priority',item.priority,priorities));body.append(top,el('h2','',item.title),el('p','notification-detail-message',item.content));
        var meta=el('dl');meta.append(el('dt','','زمان انتشار'),el('dd','',formatDate(item.published_at||item.delivered_at)),el('dt','','فرستنده'),el('dd','',item.created_by||'سامانه'));
        body.append(meta);if(item.action_url){var link=el('a','notification-primary-btn',item.action_label||'مشاهده');link.href=item.action_url;body.append(link);}dialog.hidden=false;document.body.classList.add('notification-dialog-open');
        if(!item.read_at){item.read_at=new Date().toISOString();api('/api/notifications/'+item.id+'/read',{method:'POST'}).then(refreshCount).catch(function(){});}
    }
    function closeDetail(){var d=document.getElementById('notificationDetail');if(d)d.hidden=true;document.body.classList.remove('notification-dialog-open');}
    function openCenter(){var c=document.getElementById('notificationCenter');if(!c)return;c.hidden=false;document.body.classList.add('notification-dialog-open');var d=document.getElementById('notificationDropdown');if(d)d.hidden=true;loadUser(1,false);}
    function closeCenter(){var c=document.getElementById('notificationCenter');if(c)c.hidden=true;document.body.classList.remove('notification-dialog-open');}
    async function markAll(){try{await api('/api/notifications/read-all',{method:'POST'});setUnread(0);loadUser(1,true);if(!document.getElementById('notificationCenter').hidden)loadUser(1,false);announce('همه اعلان‌ها خوانده شدند.');}catch(error){announce(error.message,true);}}
    async function refreshCount(){try{var d=await api('/api/notifications/unread-count');setUnread(d.unread);}catch(_) {}}

    document.addEventListener('DOMContentLoaded',function(){
        if(document.getElementById('notificationAdminBox')){
            document.getElementById('newNotificationButton').onclick=function(){openEditor(null);};
            document.getElementById('adminMarkAllRead').onclick=markAllAdminRead;
            document.querySelectorAll('[data-notification-close]').forEach(function(n){n.onclick=closeEditor;});
            document.getElementById('notificationTargetType').onchange=function(){updateTargetOptions([]);};document.getElementById('notificationContent').oninput=updateCount;
            document.querySelectorAll('[data-submit-status]').forEach(function(b){b.onclick=function(){submitNotification(b.dataset.submitStatus);};});
            document.getElementById('adminNotificationRows').onclick=function(e){var b=e.target.closest('[data-action]');if(b)adminAction(b.dataset.action,b.dataset.id);};
            ['adminNotificationStatus','adminNotificationType','adminNotificationPriority'].forEach(function(id){document.getElementById(id).onchange=function(){loadAdmin(1);};});
            document.getElementById('adminNotificationSearch').oninput=function(){clearTimeout(debounceTimer);debounceTimer=setTimeout(function(){loadAdmin(1);},350);};
        }
        var adminBell=document.getElementById('notificationsButton');
        if(adminBell) adminBell.addEventListener('click', function () {
            requestBrowserNotificationPermission().then(function (permission) {
                // یک تست واقعی فقط در اولین کلیک انجام می‌شود تا کاربر بداند مجوز درست است.
                if (permission === 'granted' && !browserNotificationState.testSent) {
                    browserNotificationState.testSent = true;
                    sendBrowserNotification('اعلان آزمایشی هستما', 'اعلان Chrome برای پنل ادمین فعال است.', 'admin-notification-test');
                }
            });
        });
        var bell=document.getElementById('notificationBell');if(bell){
            bell.onclick=function(e){e.stopPropagation();requestBrowserNotificationPermission();var d=document.getElementById('notificationDropdown');d.hidden=!d.hidden;bell.setAttribute('aria-expanded',String(!d.hidden));if(!d.hidden)loadUser(1,true);};
            document.addEventListener('click',function(e){var d=document.getElementById('notificationDropdown');if(!d.hidden&&!d.contains(e.target)&&e.target!==bell)d.hidden=true;});
            document.querySelectorAll('[data-action="open-notification-center"]').forEach(function(n){n.addEventListener('click',openCenter);});
            document.querySelectorAll('[data-notification-center-close]').forEach(function(n){n.onclick=closeCenter;});document.querySelectorAll('[data-notification-detail-close]').forEach(function(n){n.onclick=closeDetail;});
            document.getElementById('notificationDropdownReadAll').onclick=markAll;document.getElementById('notificationReadAll').onclick=markAll;
            document.querySelectorAll('[data-notification-state]').forEach(function(b){b.onclick=function(){document.querySelectorAll('[data-notification-state]').forEach(function(x){x.classList.remove('active');});b.classList.add('active');userState.state=b.dataset.notificationState;loadUser(1,false);};});
            document.getElementById('userNotificationSearch').oninput=function(e){userState.search=e.target.value;clearTimeout(debounceTimer);debounceTimer=setTimeout(function(){loadUser(1,false);},350);};
            ['notificationRecentList','notificationUserList'].forEach(function(id){document.getElementById(id).addEventListener('click',function(e){var button=e.target.closest('.notification-action-btn');if(button){e.stopPropagation();mutateUser(button.dataset.action,button.dataset.id);return;}var card=e.target.closest('.notification-user-item');if(card)showDetail(userState.items.get(card.dataset.id));});document.getElementById(id).addEventListener('keydown',function(e){var card=e.target.closest('.notification-user-item');if(card&&(e.key==='Enter'||e.key===' ')){e.preventDefault();showDetail(userState.items.get(card.dataset.id));}});});
            refreshCount();setInterval(refreshCount,60000);
        }
        document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeEditor();closeCenter();closeDetail();}});
        if (browserNotificationsSupported() && Notification.permission === 'granted') registerWebPush();
        startBrowserNotificationPolling();
        startNotificationStream();
    });
    window.NotificationSystem={loadAdmin:loadAdmin,openCenter:openCenter,announce:announce,requestBrowserNotificationPermission:requestBrowserNotificationPermission};
})();
