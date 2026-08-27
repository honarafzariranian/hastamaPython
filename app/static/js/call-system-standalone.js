/**
 * سامانه فراخوان نمونه‌گیری — Standalone Management Page Logic
 * Completely independent from Admin/User dashboard JavaScript.
 */
(function () {
    'use strict';

    /* ── DOM refs ── */
    var numberInput, callBtn, repeatBtn, testDisplayBtn, testVoiceBtn;
    var statusDot, statusText;
    var currentNumber, currentDept, currentEmpty, currentTime, currentCard;
    var recentList;

    /* ── State ── */
    var ws = null;
    var reconnectDelay = 1000;
    var maxReconnectDelay = 30000;
    var reconnectTimer = null;

    /* ── Persian digits ── */
    var FA = '۰۱۲۳۴۵۶۷۸۹';
    function toFA(n) {
        return String(n).replace(/[0-9]/g, function (d) { return FA[+d]; });
    }

    /* ── Toast ── */
    function showToast(msg, type) {
        var el = document.getElementById('csToast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'cs-toast cs-toast--' + (type || 'success') + ' show';
        clearTimeout(el._timer);
        el._timer = setTimeout(function () { el.className = 'cs-toast'; }, 3000);
    }

    /* ── Format time ── */
    function fmtTime(iso) {
        if (!iso) return '';
        try {
            var d = new Date(iso);
            return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
        } catch (e) { return ''; }
    }

    /* ── Update display status ── */
    function setStatus(state) {
        if (!statusDot) return;
        var map = {
            connected: 'cs-status-dot--connected',
            disconnected: 'cs-status-dot--disconnected',
            connecting: 'cs-status-dot--connecting'
        };
        statusDot.className = 'cs-status-dot ' + (map[state] || map.disconnected);
        var labels = { connected: 'نمایشگر متصل', disconnected: 'اتصال قطع است', connecting: 'در حال اتصال...' };
        if (statusText) statusText.textContent = labels[state] || state;
    }

    /* ── Show current call ── */
    function showCurrentCall(data) {
        if (!currentNumber) return;
        if (!data || !data.number) {
            currentNumber.textContent = '';
            currentDept.textContent = '';
            currentTime.textContent = '';
            currentEmpty.style.display = '';
            if (currentCard) currentCard.classList.remove('is-test');
            return;
        }
        currentEmpty.style.display = 'none';
        currentNumber.textContent = data.persian_number || data.number;
        currentDept.textContent = data.department ? 'بخش: ' + data.department : '';
        currentTime.textContent = fmtTime(data.timestamp);
        if (currentCard) currentCard.classList.toggle('is-test', !!data.is_test);
    }

    /* ── Add to recent list ── */
    function addRecentItem(data) {
        if (!recentList || !data) return;
        var emptyEl = recentList.querySelector('.cs-recent-empty');
        if (emptyEl) emptyEl.remove();

        var item = document.createElement('div');
        item.className = 'cs-recent-item';
        item.innerHTML =
            '<span class="cs-recent-number">' + escHtml(data.persian_number || data.number) + '</span>' +
            '<span class="cs-recent-dept">' + escHtml(data.department || '') + '</span>' +
            '<span class="cs-recent-time">' + fmtTime(data.timestamp) + '</span>';
        recentList.insertBefore(item, recentList.firstChild);

        while (recentList.children.length > 20) {
            recentList.removeChild(recentList.lastChild);
        }
    }

    /* ── Escape HTML ── */
    function escHtml(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s || ''));
        return div.innerHTML;
    }

    /* ── Loading state ── */
    function setLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = loading;
        if (loading) {
            btn._origText = btn.textContent;
            btn.textContent = 'در حال ارسال...';
        } else if (btn._origText) {
            btn.textContent = btn._origText;
        }
    }

    /* ── API: Create call ── */
    function makeCall() {
        var num = numberInput ? numberInput.value.trim() : '';
        if (!num) {
            showToast('لطفاً شماره پذیرش را وارد کنید.', 'error');
            if (numberInput) numberInput.focus();
            return;
        }
        var dept = document.getElementById('csDeptSelect');
        var deptVal = dept ? dept.value : 'نمونه‌گیری';

        setLoading(callBtn, true);
        fetch('/api/calls', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reception_number: num, department: deptVal })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(callBtn, false);
            if (res.success) {
                showCurrentCall(res.data);
                addRecentItem(res.data);
                showToast(res.message || 'فراخوان ارسال شد.', 'success');
                if (numberInput) { numberInput.value = ''; numberInput.focus(); }
            } else {
                showToast(res.detail || res.message || 'خطا در ارسال فراخوان.', 'error');
            }
        })
        .catch(function () {
            setLoading(callBtn, false);
            showToast('فراخوان انجام نشد. لطفاً دوباره تلاش کنید.', 'error');
        });
    }

    /* ── API: Repeat last call ── */
    function repeatCall() {
        setLoading(repeatBtn, true);
        fetch('/api/calls/repeat', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(repeatBtn, false);
            if (res.success) {
                showCurrentCall(res.data);
                addRecentItem(res.data);
                showToast(res.message || 'تکرار فراخوان ارسال شد.', 'success');
            } else {
                showToast(res.detail || res.message || 'خطا در تکرار فراخوان.', 'error');
            }
        })
        .catch(function () {
            setLoading(repeatBtn, false);
            showToast('تکرار فراخوان انجام نشد.', 'error');
        });
    }

    /* ── API: Test display ── */
    function testDisplay() {
        setLoading(testDisplayBtn, true);
        fetch('/api/calls/test-display', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(testDisplayBtn, false);
            showToast(res.message || 'تست نمایشگر ارسال شد.', 'success');
        })
        .catch(function () {
            setLoading(testDisplayBtn, false);
            showToast('تست نمایشگر انجام نشد.', 'error');
        });
    }

    /* ── API: Test voice ── */
    function testVoice() {
        setLoading(testVoiceBtn, true);
        fetch('/api/calls/test-voice', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(testVoiceBtn, false);
            showToast(res.message || 'تست صدا ارسال شد.', 'success');
        })
        .catch(function () {
            setLoading(testVoiceBtn, false);
            showToast('تست صدا انجام نشد.', 'error');
        });
    }

    /* ── Load recent calls ── */
    function loadRecent() {
        fetch('/api/calls/recent?limit=20')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.calls) return;
            if (res.calls.length > 0) showCurrentCall(res.calls[0]);
            res.calls.forEach(function (c) { addRecentItem(c); });
        })
        .catch(function () { /* silent */ });
    }

    /* ── Load display status ── */
    function loadStatus() {
        fetch('/api/calls/status')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                setStatus(res.connected_displays > 0 ? 'connected' : 'disconnected');
            }
        })
        .catch(function () { setStatus('disconnected'); });
    }

    /* ── WebSocket for real-time status ── */
    function connectWS() {
        var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        var url = proto + '//' + location.host + '/api/ws/call-display';

        try {
            ws = new WebSocket(url);
        } catch (e) {
            scheduleReconnect();
            return;
        }

        setStatus('connecting');

        ws.onopen = function () {
            reconnectDelay = 1000;
            setStatus('connected');
            ws._pingInterval = setInterval(function () {
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send('ping');
                }
            }, 30000);
        };

        ws.onmessage = function (evt) {
            try {
                var msg = JSON.parse(evt.data);
                if (msg.type === 'pong') return;
                if (msg.type === 'reception_call' && msg.data) {
                    showCurrentCall(msg.data);
                    if (!msg.data.is_test) addRecentItem(msg.data);
                }
            } catch (e) { /* ignore */ }
        };

        ws.onclose = function () {
            clearInterval(ws._pingInterval);
            setStatus('connecting');
            scheduleReconnect();
        };

        ws.onerror = function () {};
    }

    function scheduleReconnect() {
        clearTimeout(reconnectTimer);
        reconnectTimer = setTimeout(function () {
            reconnectDelay = Math.min(reconnectDelay * 1.5, maxReconnectDelay);
            connectWS();
        }, reconnectDelay);
    }

    /* ── Init ── */
    function init() {
        numberInput = document.getElementById('csNumberInput');
        callBtn = document.getElementById('csCallBtn');
        repeatBtn = document.getElementById('csRepeatBtn');
        testDisplayBtn = document.getElementById('csTestDisplayBtn');
        testVoiceBtn = document.getElementById('csTestVoiceBtn');
        statusDot = document.getElementById('csStatusDot');
        statusText = document.getElementById('csStatusText');
        currentNumber = document.getElementById('csCurrentNumber');
        currentDept = document.getElementById('csCurrentDept');
        currentEmpty = document.getElementById('csCurrentEmpty');
        currentTime = document.getElementById('csCurrentTime');
        currentCard = document.getElementById('csCurrentCard');
        recentList = document.getElementById('csRecentList');

        if (callBtn) callBtn.addEventListener('click', makeCall);
        if (repeatBtn) repeatBtn.addEventListener('click', repeatCall);
        if (testDisplayBtn) testDisplayBtn.addEventListener('click', testDisplay);
        if (testVoiceBtn) testVoiceBtn.addEventListener('click', testVoice);

        if (numberInput) {
            numberInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); makeCall(); }
            });
        }

        loadRecent();
        loadStatus();
        connectWS();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
