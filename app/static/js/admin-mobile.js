/* ============================================================================
 * admin-mobile.js — لایهٔ تعامل موبایل پنل مدیریت هستما
 * ----------------------------------------------------------------------------
 * مسئولیت‌ها (همه فقط در عرض ≤768px فعال می‌شوند):
 *   ۱) ساخت «نوار تب پایین» (Bottom Tab Bar) از همان آیتم‌های سایدبار —
 *      بنابراین برچسب‌ها، آیکون‌های SVG و مقصدها همیشه با دسکتاپ یکی است.
 *   ۲) همگام‌سازی وضعیت فعال بین سایدبار، نوار پایین و URL (بدون منطق دوم؛
 *      از همان navTo/toggleBox موجود در admin.js استفاده می‌کند).
 *   ۳) رفتار هدر: جمع‌شدن در اسکرول به پایین، بازگشت در اسکرول به بالا.
 *   ۴) تعامل برگه‌ها: کلاس body هنگام بازبودن مودال/برگه + بستن با سوایپ.
 *   ۵) اسکرول خودکار تب فعال به وسط نوار تب.
 *   ۶) ورود پله‌ای محتوای هر بخش هنگام جابه‌جایی بین بخش‌ها.
 *
 * هیچ درخواست شبکه‌ای، هیچ تغییر منطق و هیچ تغییری در دسکتاپ ایجاد نمی‌شود.
 * ==========================================================================*/
(function () {
    'use strict';

    var MOBILE_QUERY = '(max-width: 768px)';

    function isMobile() {
        return window.matchMedia(MOBILE_QUERY).matches;
    }

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /* ترتیب اولویت تب‌های پایین — بقیهٔ بخش‌ها پشت «بیشتر» (کشوی سایدبار) */
    var PRIMARY_ACCENTS = ['dashboard', 'staff', 'leave', 'ticket'];

    var TAB_LABELS = {
        dashboard: 'داشبورد',
        staff: 'کارکنان',
        leave: 'مرخصی',
        ticket: 'تیکت',
        hozoor: 'حضور'
    };

    var MORE_ICON =
        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<circle cx="5.5" cy="12" r="1.9" fill="currentColor"/>' +
        '<circle cx="12" cy="12" r="1.9" fill="currentColor"/>' +
        '<circle cx="18.5" cy="12" r="1.9" fill="currentColor"/></svg>';

    var tabbar = null;
    var tabs = [];
    var moreBtn = null;

    function sidebarItems() {
        return Array.prototype.slice.call(document.querySelectorAll('.rightSidebar .icon-container'));
    }

    function accentOf(item) {
        return item.getAttribute('data-accent') || '';
    }

    /* استخراج مقصد از همان onclick سایدبار: navTo('boxId', this, '/url') */
    function targetOf(item) {
        var onclick = item.getAttribute('onclick') || '';
        var box = /navTo\(\s*'([^']+)'/.exec(onclick);
        var url = /navTo\([^,]+,\s*[^,]+,\s*'([^']+)'/.exec(onclick);
        return {
            box: box ? box[1] : '',
            url: url ? url[1] : ''
        };
    }

    function fallbackLabel(accent, text) {
        return TAB_LABELS[accent] || (text || '').split(' ')[0] || 'بخش';
    }

    /* ── ۱) ساخت نوار تب پایین ───────────────────────────────────────────── */
    function buildTabbar() {
        if (tabbar || !isMobile()) return;
        var sidebar = document.querySelector('.rightSidebar');
        if (!sidebar) return;

        var items = sidebarItems();
        if (!items.length) return;

        var nav = document.createElement('nav');
        nav.className = 'adm-tabbar';
        nav.setAttribute('aria-label', 'ناوبری اصلی موبایل');

        var inner = document.createElement('div');
        inner.className = 'adm-tabbar__inner';

        var glow = document.createElement('span');
        glow.className = 'adm-tabbar__glow';
        glow.setAttribute('aria-hidden', 'true');
        inner.appendChild(glow);

        var byAccent = {};
        items.forEach(function (item) { byAccent[accentOf(item)] = item; });

        var chosen = [];
        PRIMARY_ACCENTS.forEach(function (accent) {
            if (byAccent[accent]) chosen.push(byAccent[accent]);
        });
        /* اگر یکی از بخش‌های اصلی وجود نداشت، از ترتیب سایدبار پر می‌کنیم */
        items.forEach(function (item) {
            if (chosen.length >= PRIMARY_ACCENTS.length) return;
            if (chosen.indexOf(item) === -1 && accentOf(item) !== 'exit') chosen.push(item);
        });

        tabs = [];
        chosen.forEach(function (item) {
            var accent = accentOf(item);
            var labelEl = item.querySelector('.icon-label');
            var svg = item.querySelector('.sidebar-icon-tile svg');

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'adm-tab';
            btn.setAttribute('data-accent', accent);

            var icon = document.createElement('span');
            icon.className = 'adm-tab__icon';
            if (svg) icon.innerHTML = svg.outerHTML;
            btn.appendChild(icon);

            var label = document.createElement('span');
            label.className = 'adm-tab__label';
            label.textContent = fallbackLabel(accent, labelEl ? labelEl.textContent.trim() : '');
            btn.appendChild(label);

            btn.addEventListener('click', function () {
                var target = targetOf(item);
                if (target.box && typeof window.navTo === 'function') {
                    window.navTo(target.box, item, target.url);
                }
                closeDrawer();
                syncTabs();
                scrollTabIntoView(btn);
            });

            inner.appendChild(btn);
            tabs.push(btn);
        });

        /* تب «بیشتر» → کشوی کامل سایدبار */
        var more = document.createElement('button');
        more.type = 'button';
        more.className = 'adm-tab adm-tab--more';
        more.setAttribute('data-accent', 'more');
        more.innerHTML = '<span class="adm-tab__icon">' + MORE_ICON + '</span>' +
            '<span class="adm-tab__label">بیشتر</span>';
        more.addEventListener('click', function (event) {
            event.stopPropagation();
            openDrawer();
            setTimeout(function () { scrollTabIntoView(); }, 60);
        });
        inner.appendChild(more);
        moreBtn = more;

        nav.appendChild(inner);
        document.body.appendChild(nav);
        tabbar = nav;

        window.requestAnimationFrame(function () {
            nav.classList.add('is-ready');
            syncTabs();
        });
    }

    function destroyTabbar() {
        if (!tabbar) return;
        tabbar.parentNode.removeChild(tabbar);
        tabbar = null;
        tabs = [];
        moreBtn = null;
        document.body.classList.remove('adm-sheet-open');
    }

    /* ── ۲) همگام‌سازی وضعیت فعال ───────────────────────────────────────── */
    function activeSidebarItem() {
        return document.querySelector('.rightSidebar .icon-container.active');
    }

    function syncTabs() {
        if (!tabbar) return;
        var active = activeSidebarItem();
        var accent = active ? accentOf(active) : '';
        var target = null;

        tabs.forEach(function (btn) {
            var on = !!accent && btn.getAttribute('data-accent') === accent;
            btn.classList.toggle('is-active', on);
            if (on) {
                target = btn;
                btn.setAttribute('aria-current', 'page');
            } else {
                btn.removeAttribute('aria-current');
            }
        });

        /* اگر بخش فعلی در نوار پایین نیست، تب «بیشتر» فعال می‌شود تا کاربر
           همیشه جای خودش را ببیند (همهٔ بخش‌ها داخل کشوی «بیشتر» هستند) */
        if (!target && moreBtn && accent) {
            target = moreBtn;
            var labelEl = active ? active.querySelector('.icon-label') : null;
            var name = labelEl ? labelEl.textContent.trim() : '';
            moreBtn.classList.add('is-active');
            moreBtn.setAttribute('aria-current', 'page');
            moreBtn.setAttribute('aria-label', 'بیشتر' + (name ? ' — بخش فعال: ' + name : ''));
        } else if (moreBtn) {
            moreBtn.classList.remove('is-active');
            moreBtn.removeAttribute('aria-current');
            moreBtn.setAttribute('aria-label', 'بیشتر');
        }

        var inner = tabbar.querySelector('.adm-tabbar__inner');
        if (!inner) return;

        if (!target) {
            inner.style.setProperty('--adm-tab-x', '0px');
            inner.style.setProperty('--adm-tab-w', '0px');
            return;
        }
        /* offsetLeft نسبت به کادر نوار اندازه‌گیری می‌شود؛ در RTL هم درست است */
        inner.style.setProperty('--adm-tab-w', target.offsetWidth + 'px');
        inner.style.setProperty('--adm-tab-x', target.offsetLeft + 'px');
    }

    function observeSidebar() {
        var sidebar = document.querySelector('.rightSidebar');
        if (!sidebar) return;
        /* تغییر کلاس active توسط toggleBox در admin.js → همگام‌سازی تب‌ها */
        var observer = new MutationObserver(function () { syncTabs(); });
        observer.observe(sidebar, { subtree: true, attributes: true, attributeFilter: ['class'] });
        window.addEventListener('popstate', function () { setTimeout(syncTabs, 0); });
    }

    /* ── ۳) کشوی سایدبار: سوایپ برای بستن + بازگشت فوکوس ──────────────── */
    function openDrawer() {
        if (typeof window.toggleSidebar === 'function') {
            var sidebar = document.querySelector('.rightSidebar');
            if (sidebar && !sidebar.classList.contains('open')) window.toggleSidebar();
        }
    }

    function closeDrawer() {
        if (typeof window.closeMobileSidebar === 'function') {
            window.closeMobileSidebar();
        } else {
            var toggle = document.querySelector('.mobile-menu-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }
    }

    function bindDrawerGestures() {
        var sidebar = document.querySelector('.rightSidebar');
        if (!sidebar) return;

        var startX = 0;
        var startY = 0;
        var tracking = false;

        sidebar.addEventListener('touchstart', function (event) {
            if (!isMobile() || event.touches.length !== 1) return;
            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
            tracking = true;
        }, { passive: true });

        sidebar.addEventListener('touchend', function (event) {
            if (!tracking) return;
            tracking = false;
            var touch = event.changedTouches[0];
            if (!touch) return;
            var dx = touch.clientX - startX;
            var dy = Math.abs(touch.clientY - startY);
            /* در حالت RTL کشو از راست باز می‌شود؛ سوایپ به راست = بستن */
            if (dx > 62 && dy < 48) closeDrawer();
        }, { passive: true });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) closeDrawer();
        });

        sidebar.addEventListener('click', function (event) {
            var item = event.target.closest && event.target.closest('.icon-container');
            if (!item) return;
            setTimeout(function () {
                syncTabs();
                closeDrawer();
            }, 40);
        });
    }

    /* ── ۳-ب) عنوان کشو (یک‌بار ساخته می‌شود) ───────────────────────────── */
    function buildDrawerHead() {
        var sidebar = document.querySelector('.rightSidebar');
        if (!sidebar || sidebar.querySelector('.adm-drawer-head')) return;
        var head = document.createElement('div');
        head.className = 'adm-drawer-head';
        head.innerHTML =
            '<span class="adm-drawer-head__mark" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M3 12h4l2.5-6 4 12L16 12h5"/></svg></span>' +
            '<span class="adm-drawer-head__text">' +
            '<span class="adm-drawer-head__title">منوی مدیریت</span>' +
            '<span class="adm-drawer-head__sub">همهٔ بخش‌های سامانه هستما</span></span>';
        sidebar.insertBefore(head, sidebar.firstChild);
    }

    /* ── ۴) رفتار هدر در اسکرول ────────────────────────────────────────── */
    function bindHeaderScroll() {
        var shell = document.querySelector('.page-shell');
        if (!shell) return;
        var lastY = window.pageYOffset || 0;
        var ticking = false;

        function update() {
            ticking = false;
            var y = window.pageYOffset || 0;
            shell.classList.toggle('is-scrolled', y > 10);
            if (!isMobile()) {
                shell.classList.remove('is-header-hidden');
                lastY = y;
                return;
            }
            var delta = y - lastY;
            if (y > 150 && delta > 8) {
                shell.classList.add('is-header-hidden');
            } else if (delta < -6 || y < 120) {
                shell.classList.remove('is-header-hidden');
            }
            lastY = y;
        }

        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }, { passive: true });

        update();
    }

    /* ── ۵) برگه‌ها: کلاس body تا نوار تب کنار برود + سوایپ بستن ───────── */
    var SHEET_SELECTOR = [
        '.modal', '.modal-virayesh', '.modal-hazf', '.MoshahedeOverlay',
        '.popup-overlay', '.popupHozoor-overlay', '.morakhaci-sabt',
        '.shift-popup-overlay', '.reg-detail-modal', '.notification-dialog',
        '.notification-detail', '.ticket-conversation-overlay'
    ].join(',');

    /* یک لایه «باز» است اگر واقعاً رندر شده باشد (نه پنهان با display یا hidden) */
    function anySheetOpen() {
        var nodes = document.querySelectorAll(SHEET_SELECTOR);
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.hidden) continue;
            var style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') continue;
            if (style.pointerEvents === 'none') continue;
            if (el.getBoundingClientRect().height > 24) return true;
        }
        return false;
    }

    function watchSheets() {
        var pending = false;
        function apply() {
            pending = false;
            document.body.classList.toggle('adm-sheet-open', isMobile() && anySheetOpen());
        }
        /* هم rAF و هم تایمر: در تب‌های بی‌فعالیت rAF ممکن است اجرا نشود */
        function schedule() {
            if (pending) return;
            pending = true;
            if (window.requestAnimationFrame) window.requestAnimationFrame(apply);
            window.setTimeout(function () { if (pending) apply(); }, 40);
        }
        var observer = new MutationObserver(schedule);
        observer.observe(document.body, {
            subtree: true,
            attributes: true,
            attributeFilter: ['style', 'class', 'hidden']
        });
        schedule();
    }

    function bindSheetSwipe() {
        var startY = 0;
        var active = null;

        document.addEventListener('touchstart', function (event) {
            if (!isMobile() || event.touches.length !== 1) return;
            var sheet = event.target.closest && event.target.closest(
                '.modal-content, .popup-content-virayesh, .popupHozoor-content, .sabt-ticket-dialog, ' +
                '.reg-detail-modal__card, .notification-dialog-card, .ticket-conversation-dialog, .profile-panel'
            );
            if (!sheet) return;
            var scroller = sheet.querySelector('form') || sheet;
            if (scroller.scrollTop > 4) return;
            active = sheet;
            startY = event.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchend', function (event) {
            if (!active) return;
            var touch = event.changedTouches[0];
            var dy = touch ? touch.clientY - startY : 0;
            var sheet = active;
            active = null;
            if (dy < 96) return;
            var overlay = sheet.parentElement;
            while (overlay && !overlay.onclick && overlay.parentElement && overlay.tagName !== 'BODY') {
                overlay = overlay.parentElement;
            }
            var closer = sheet.querySelector('.profile-panel-close, .notification-detail-close, .ticket-conversation-close');
            if (closer) {
                closer.click();
            } else if (overlay && overlay.onclick) {
                overlay.click();
            }
        }, { passive: true });
    }

    /* ── ۶) ورود پله‌ای محتوای بخش‌ها هنگام جابه‌جایی ────────────────────── */
    function staggerSection(box) {
        if (!box) return;
        if (prefersReducedMotion() || !isMobile()) return;
        var step = 0;
        Array.prototype.slice.call(box.children).forEach(function (child) {
            if (!child.offsetParent) return;
            child.style.setProperty('--adm-i', String(step++));
            child.style.animation = 'none';
            /* اجبار به بازنشانی انیمیشن */
            void child.offsetWidth;
            child.style.animation = '';
            child.classList.add('adm-stagger');
        });
    }

    function watchSections() {
        var boxes = document.querySelectorAll('.management-box');
        Array.prototype.slice.call(boxes).forEach(function (box) {
            var observer = new MutationObserver(function () {
                if (box.style.display !== 'none') {
                    staggerSection(box);
                    setTimeout(syncTabs, 0);
                    setTimeout(scrollActiveTabIntoView, 60);
                }
            });
            observer.observe(box, { attributes: true, attributeFilter: ['style'] });
        });
        var current = document.querySelector('.management-box.is-visible');
        if (current) staggerSection(current);
    }

    /* ── ۷) نوارهای تب اسکرولی: تب فعال به وسط می‌آید ──────────────────── */
    var TAB_STRIP_ITEMS = '.coworker-tab-btn, .shift-tab-btn, .vacation-tab-btn, ' +
        '.overtime-tab-btn, .hourlyPass-tab-btn, .attendance-tab-btn, .payroll-tab-btn, ' +
        '.internal-automation-admin-tab, .helpdesk-view';

    function scrollTabIntoView(el) {
        if (!el || !el.scrollIntoView) return;
        try {
            el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', inline: 'center', block: 'nearest' });
        } catch (e) { /* مرورگرهای قدیمی */ }
    }

    function scrollActiveTabIntoView() {
        var active = document.querySelector('.management-box.is-visible ' + TAB_STRIP_ITEMS.split(',').map(function (s) {
            return s.trim() + '.active';
        }).join(', '));
        if (active) scrollTabIntoView(active);
    }

    function bindTabStrips() {
        document.addEventListener('click', function (event) {
            if (!isMobile()) return;
            var btn = event.target.closest && event.target.closest(TAB_STRIP_ITEMS);
            if (!btn) return;
            setTimeout(function () { scrollTabIntoView(btn); }, 30);
        }, true);
    }

    /* ── راه‌اندازی ─────────────────────────────────────────────────────── */
    function init() {
        buildDrawerHead();
        buildTabbar();
        observeSidebar();
        bindDrawerGestures();
        bindHeaderScroll();
        watchSheets();
        bindSheetSwipe();
        watchSections();
        bindTabStrips();
        window.addEventListener('resize', function () {
            if (isMobile()) {
                buildTabbar();
            } else {
                destroyTabbar();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* API عمومی برای تست/توسعه */
    window.HastamaAdminMobile = {
        syncTabs: syncTabs,
        isMobile: isMobile
    };
})();
