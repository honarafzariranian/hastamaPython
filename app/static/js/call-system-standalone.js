/**
 * Call Management — Standalone Page Logic
 * Queue tracking, remove feature, display monitoring.
 */
(function () {
    'use strict';

    /* ── DOM ── */
    var numberInput, callBtn, repeatBtn, testDisplayBtn, testVoiceBtn, resetBtn;
    var statusText;
    var recentList, historyEmpty;

    /* ── Queue state (mirrors TV display) ── */
    var QUEUE_MAX = 5;
    var queueData = new Array(QUEUE_MAX).fill(null); // [0]=hero, [1-4]=previous
    var historyData = [];
    var queueSlots = [];
    var queueCountEl;

    /* ── WebSocket ── */
    var ws = null;
    var reconnectDelay = 1000;
    var maxReconnectDelay = 30000;
    var reconnectTimer = null;

    /* ── Persian digits ── */
    var FA = '\u06f0\u06f1\u06f2\u06f3\u06f4\u06f5\u06f6\u06f7\u06f8\u06f9';
    function toFA(n) { return String(n).replace(/[0-9]/g, function (d) { return FA[+d]; }); }

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

    /* ── Status ── */
    function setStatus(state) {
        var badge = document.getElementById('csConnBadge');
        if (badge) badge.className = 'cs-conn-float cs-conn-float--' + state;
        // Show/hide correct icon
        var icons = {
            connecting: document.querySelector('.cs-conn-icon--connecting'),
            connected: document.querySelector('.cs-conn-icon--connected'),
            disconnected: document.querySelector('.cs-conn-icon--disconnected')
        };
        Object.keys(icons).forEach(function(k) {
            if (icons[k]) icons[k].style.display = (k === state) ? '' : 'none';
        });
        var labels = { connected: '\u0645\u062a\u0635\u0644', disconnected: '\u0642\u0637\u0639', connecting: '\u062f\u0631 \u062d\u0627\u0644 \u0627\u062a\u0635\u0627\u0644...' };
        if (statusText) statusText.textContent = labels[state] || state;
    }

    /* ── Queue rendering ── */
    function renderQueue() {
        var count = 0;
        for (var i = 0; i < QUEUE_MAX; i++) {
            var slot = queueSlots[i];
            if (!slot) continue;
            var d = queueData[i];
            var numEl = slot.querySelector('.cs-queue-slot-num');
            var deptEl = slot.querySelector('.cs-queue-slot-dept');
            var emptyEl = slot.querySelector('.cs-queue-slot-empty');

            if (d) {
                count++;
                if (numEl) numEl.textContent = d.persian_number || d.reception_number || '';
                if (deptEl) deptEl.textContent = d.department || '';
                slot.classList.add('is-active');
                slot.classList.remove('is-empty');
                // Add remove button if not exists
                if (!slot.querySelector('.cs-queue-remove')) {
                    var rmBtn = document.createElement('button');
                    rmBtn.className = 'cs-queue-remove';
                    rmBtn.textContent = '\u00d7';
                    rmBtn.title = '\u062d\u0630\u0641 \u0627\u0632 \u0646\u0645\u0627\u06cc\u0634';
                    rmBtn.addEventListener('click', (function (idx) {
                        return function (e) {
                            e.stopPropagation();
                            var item = queueData[idx];
                            if (item) removeFromDisplay(item.reception_number || item.number, idx);
                        };
                    })(i));
                    slot.appendChild(rmBtn);
                }
            } else {
                if (numEl) numEl.textContent = '';
                if (deptEl) deptEl.textContent = '';
                slot.classList.remove('is-active');
                slot.classList.add('is-empty');
                var rmOld = slot.querySelector('.cs-queue-remove');
                if (rmOld) rmOld.remove();
            }
        }
        if (queueCountEl) queueCountEl.textContent = count + ' / ' + QUEUE_MAX;
    }

    /* ── Add call to queue ── */
    function addToQueue(data) {
        var rawNum = data.reception_number || data.number;
        if (!data || !rawNum) return;
        var num = String(rawNum).replace(/[^\d]/g, '');
        // Check if already in queue — if so, skip
        for (var i = 0; i < QUEUE_MAX; i++) {
            if (queueData[i]) {
                var existing = queueData[i].reception_number || queueData[i].number;
                if (String(existing).replace(/[^\d]/g, '') === num) return;
            }
        }
        // Shift queue
        for (var j = QUEUE_MAX - 1; j > 0; j--) {
            queueData[j] = queueData[j - 1];
        }
        queueData[0] = data;
        renderQueue();
    }

    /* ── Remove from display ── */
    function removeFromDisplay(number, slotIndex) {
        // Convert Persian/Arabic digits to ASCII, then strip non-digits
        var num = String(number).replace(/[\u06f0-\u06f9\u0660-\u0669]/g, function(d) { return String(d.charCodeAt(0) - 0x06f0); }).replace(/[^\d]/g, '');
        // Send remove event to server
        fetch('/api/calls/remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ number: num })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                // Clear from local queue
                queueData[slotIndex] = null;
                // Compact
                var compacted = [];
                for (var i = 0; i < QUEUE_MAX; i++) {
                    if (queueData[i]) compacted.push(queueData[i]);
                }
                for (var k = 0; k < QUEUE_MAX; k++) {
                    queueData[k] = k < compacted.length ? compacted[k] : null;
                }
                renderQueue();
                showToast(res.message || '\u0627\u0632 \u0646\u0645\u0627\u06cc\u0634\u06af\u0631 \u062d\u0630\u0641 \u0634\u062f.', 'success');
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            showToast('\u062d\u0630\u0641 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    /* ── Add to history ── */
    function addHistoryItem(data) {
        if (!recentList || !data) return;
        if (historyEmpty) historyEmpty.style.display = 'none';

        var item = document.createElement('div');
        item.className = 'cs-history-item';
        item.innerHTML =
            '<span class="cs-history-num">' + escHtml(data.persian_number || data.reception_number) + '</span>' +
            '<span class="cs-history-dept">' + escHtml(data.department || '') + '</span>' +
            '<span class="cs-history-time">' + fmtTime(data.timestamp) + '</span>';
        recentList.insertBefore(item, recentList.firstChild);

        while (recentList.children.length > 30) {
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
        if (loading) { btn._origText = btn.textContent; btn.textContent = '\u062f\u0631 \u062d\u0627\u0644 \u0627\u0631\u0633\u0627\u0644...'; }
        else if (btn._origText) { btn.textContent = btn._origText; }
    }

    /* ── API: Make call ── */
    function makeCall() {
        var num = numberInput ? numberInput.value.trim() : '';
        if (!num) {
            showToast('\u0644\u0637\u0641\u0627\u064b \u0634\u0645\u0627\u0631\u0647 \u067e\u0630\u06cc\u0631\u0634 \u0631\u0627 \u0648\u0627\u0631\u062f \u06a9\u0646\u06cc\u062f.', 'error');
            if (numberInput) numberInput.focus();
            return;
        }
        var dept = document.getElementById('csDeptSelect');
        var deptVal = dept ? dept.value : '\u0646\u0645\u0648\u0646\u0647\u065c\u06af\u06cc\u0631\u06cc';

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
                addToQueue(res.data);
                addHistoryItem(res.data);
                showToast(res.message || '\u0641\u0631\u0627\u062e\u0648\u0627\u0646 \u0627\u0631\u0633\u0627\u0644 \u0634\u062f.', 'success');
                if (numberInput) { numberInput.value = ''; numberInput.focus(); }
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            setLoading(callBtn, false);
            showToast('\u0641\u0631\u0627\u062e\u0648\u0627\u0646 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    /* ── API: Repeat ── */
    function repeatCall() {
        setLoading(repeatBtn, true);
        fetch('/api/calls/repeat', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(repeatBtn, false);
            if (res.success) {
                addToQueue(res.data);
                addHistoryItem(res.data);
                showToast(res.message || '\u062a\u06a9\u0631\u0627\u0631 \u0627\u0631\u0633\u0627\u0644 \u0634\u062f.', 'success');
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            setLoading(repeatBtn, false);
            showToast('\u062a\u06a9\u0631\u0627\u0631 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    /* ── API: Test display ── */
    function testDisplay() {
        setLoading(testDisplayBtn, true);
        fetch('/api/calls/test-display', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(testDisplayBtn, false);
            showToast(res.message || '\u062a\u0633\u062a \u0646\u0645\u0627\u06cc\u0634\u06af\u0631 \u0627\u0631\u0633\u0627\u0644 \u0634\u062f.', 'success');
        })
        .catch(function () {
            setLoading(testDisplayBtn, false);
            showToast('\u062a\u0633\u062a \u0646\u0645\u0627\u06cc\u0634\u06af\u0631 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    /* ── Modal ── */
    function showModal(title, desc, onConfirm) {
        var modal = document.getElementById('csModal');
        var titleEl = document.getElementById('csModalTitle');
        var descEl = document.getElementById('csModalDesc');
        var cancelBtn = document.getElementById('csModalCancel');
        var confirmBtn = document.getElementById('csModalConfirm');
        if (!modal) return;
        if (titleEl) titleEl.textContent = title;
        if (descEl) descEl.textContent = desc;
        modal.classList.add('is-open');
        function close() {
            modal.classList.remove('is-open');
            cancelBtn.removeEventListener('click', close);
            confirmBtn.removeEventListener('click', confirm);
        }
        function confirm() {
            close();
            if (onConfirm) onConfirm();
        }
        cancelBtn.addEventListener('click', close);
        confirmBtn.addEventListener('click', confirm);
        modal.querySelector('.cs-modal-backdrop').addEventListener('click', close);
    }

    /* --- API: Reset display --- */
    function resetDisplay() {
        showModal(
            '\u067e\u0627\u06a9 \u06a9\u0631\u062f\u0646 \u0647\u0645\u0647',
            '\u0622\u06cc\u0627 \u0627\u0632 \u067e\u0627\u06a9 \u06a9\u0631\u062f\u0646 \u062a\u0645\u0627\u0645 \u0634\u0645\u0627\u0631\u0647\u0654\u0647\u0627 \u0627\u0632 \u0646\u0645\u0627\u06cc\u0634\u06af\u0631 \u0627\u0637\u0645\u06cc\u0646\u0627\u0646 \u062f\u0627\u0631\u06cc\u062f\u061f',
            function () {
                setLoading(resetBtn, true);
                fetch('/api/calls/reset-display', { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    setLoading(resetBtn, false);
                    if (res.success) {
                        for (var i = 0; i < QUEUE_MAX; i++) queueData[i] = null;
                        renderQueue();
                        showToast(res.message || '\u0646\u0645\u0627\u06cc\u0634\u06af\u0631 \u067e\u0627\u06a9 \u0634\u062f.', 'success');
                    } else {
                        showToast(res.detail || '\u062e\u0637\u0627', 'error');
                    }
                })
                .catch(function () {
                    setLoading(resetBtn, false);
                    showToast('\u067e\u0627\u06a9 \u06a9\u0631\u062f\u0646 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
                });
            }
        );
    }

    /* ── API: Test voice ── */
    function testVoice() {
        setLoading(testVoiceBtn, true);
        fetch('/api/calls/test-audio?number=1', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(testVoiceBtn, false);
            showToast(res.message || '\u062a\u0633\u062a \u0635\u062f\u0627 \u0627\u0631\u0633\u0627\u0644 \u0634\u062f.', 'success');
        })
        .catch(function () {
            setLoading(testVoiceBtn, false);
            showToast('\u062a\u0633\u062a \u0635\u062f\u0627 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    /* ── Load audio status ── */
    function loadAudioStatus() {
        var el = document.getElementById('csAudioStatus');
        fetch('/api/calls/audio-status')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!el) return;
            if (res.exists) {
                el.textContent = '\u0641\u0627\u06cc\u0644\u200c\u0647\u0627\u06cc \u0635\u0648\u062a\u06cc: ' + res.total_files + ' / ' + res.total_expected;
                el.className = 'cs-audio-info cs-audio-info--ok';
            } else {
                el.textContent = '\u0641\u0627\u06cc\u0644 \u0635\u0648\u062a\u06cc \u0645\u0648\u062c\u0648\u062f \u0646\u06cc\u0633\u062a';
                el.className = 'cs-audio-info cs-audio-info--missing';
            }
        })
        .catch(function () {});
    }

    /* ── Load display queue from server (persistent state) ── */
    function loadDisplayQueue() {
        fetch('/api/calls/display-queue')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.queue) return;
            for (var i = 0; i < Math.min(res.queue.length, QUEUE_MAX); i++) {
                queueData[i] = res.queue[i];
            }
            renderQueue();
        })
        .catch(function () {});
    }

    /* ── Load history (recent calls from DB) ── */
    function loadRecent() {
        fetch('/api/calls/recent?limit=30')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.calls) return;
            res.calls.forEach(function (c) { addHistoryItem(c); });
        })
        .catch(function () {});
    }

    /* ── Load status ── */
    function loadStatus() {
        fetch('/api/calls/status')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) setStatus(res.connected_displays > 0 ? 'connected' : 'disconnected');
        })
        .catch(function () { setStatus('disconnected'); });
    }

    /* ── WebSocket ── */
    function connectWS() {
        var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        var url = proto + '//' + location.host + '/api/ws/call-display';
        try { ws = new WebSocket(url); } catch (e) { scheduleReconnect(); return; }

        setStatus('connecting');

        ws.onopen = function () {
            reconnectDelay = 1000;
            setStatus('connected');
            ws._pingInterval = setInterval(function () {
                if (ws && ws.readyState === WebSocket.OPEN) ws.send('ping');
            }, 30000);
        };

        ws.onmessage = function (evt) {
            try {
                var msg = JSON.parse(evt.data);
                if (msg.type === 'pong') return;
                if (msg.type === 'reception_call' && msg.data) {
                    addToQueue(msg.data);
                    if (!msg.data.is_test) addHistoryItem(msg.data);
                }
                // remove_call handled locally by removeFromDisplay — skip WS echo
                if (msg.type === 'reset_display') {
                    for (var m = 0; m < QUEUE_MAX; m++) queueData[m] = null;
                    renderQueue();
                }
            } catch (e) {}
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
        resetBtn = document.getElementById('csResetBtn');
        statusText = document.getElementById('csStatusText');
        recentList = document.getElementById('csRecentList');
        historyEmpty = document.getElementById('csHistoryEmpty');
        queueCountEl = document.getElementById('csQueueCount');

        // Collect queue slot elements
        var heroSlot = document.getElementById('csQueueHero');
        if (heroSlot) queueSlots.push(heroSlot);
        for (var i = 1; i <= 4; i++) {
            var el = document.getElementById('csQueue' + i);
            if (el) queueSlots.push(el);
        }

        if (callBtn) callBtn.addEventListener('click', makeCall);
        if (repeatBtn) repeatBtn.addEventListener('click', repeatCall);
        if (testDisplayBtn) testDisplayBtn.addEventListener('click', testDisplay);
        if (testVoiceBtn) testVoiceBtn.addEventListener('click', testVoice);
        if (resetBtn) resetBtn.addEventListener('click', resetDisplay);

        if (numberInput) {
            numberInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); makeCall(); }
            });
        }

        loadDisplayQueue();
        loadRecent();
        loadStatus();
        loadAudioStatus();
        connectWS();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
