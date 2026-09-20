/**
 * Call Management — Standalone Page Logic
 * Queue tracking, remove feature, display monitoring.
 */
(function () {
    'use strict';

    /* ── DOM ── */
    var numberInput, callBtn, repeatBtn, testDisplayBtn, testVoiceBtn, resetBtn;
    var refreshDisplayBtn;
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

    /* ── Tab switching ── */
    window.switchCsTab = function (tabId, btnEl) {
        document.querySelectorAll('.cs-tab-btn').forEach(function (b) { b.classList.remove('cs-tab-btn--active'); });
        document.querySelectorAll('.cs-tab-content').forEach(function (c) { c.classList.remove('cs-tab-content--active'); });
        if (btnEl) btnEl.classList.add('cs-tab-btn--active');
        var target = document.getElementById(tabId);
        if (target) target.classList.add('cs-tab-content--active');
    };


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
        var label = labels[state] || state;
        if (state === 'connected' && typeof arguments[1] === 'number' && arguments[1] > 0) {
            label += ' (' + arguments[1] + ')';
        }
        if (statusText) statusText.textContent = label;
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
        var deptVal = dept ? dept.value : '\u0646\u0645\u0648\u0646\u0647\u200c\u06af\u06cc\u0631\u06cc';

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

    /* ── API: Refresh display (بارگذاری مجدد صفحه تلویزیون از راه دور) ── */
    function refreshDisplays() {
        if (refreshDisplayBtn) {
            refreshDisplayBtn.disabled = true;
            refreshDisplayBtn.classList.add('is-loading');
        }
        fetch('/api/calls/refresh-display', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (refreshDisplayBtn) {
                refreshDisplayBtn.disabled = false;
                refreshDisplayBtn.classList.remove('is-loading');
            }
            if (res.success) {
                var realCount = res.real_displays || 0;
                if (realCount > 0) {
                    showToast('دستور رفرش به ' + toPersianNum(realCount) + ' نمایشگر ارسال شد.', 'success');
                } else {
                    showToast('هیچ نمایشگر فعالی متصل نیست.', 'error');
                }
            } else {
                showToast(res.detail || 'خطا', 'error');
            }
        })
        .catch(function () {
            if (refreshDisplayBtn) {
                refreshDisplayBtn.disabled = false;
                refreshDisplayBtn.classList.remove('is-loading');
            }
            showToast('ارسال دستور رفرش انجام نشد.', 'error');
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

    function clearCallHistory() {
        if (!window.confirm('آیا از پاک کردن کامل تاریخچه فراخوان‌ها مطمئن هستید؟')) return;
        fetch('/api/calls/recent', { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error(res.detail || res.error || 'پاک کردن تاریخچه انجام نشد.');
                if (recentList) {
                    recentList.querySelectorAll('.cs-history-item').forEach(function (item) {
                        item.remove();
                    });
                }
                if (historyEmpty) historyEmpty.style.display = '';
                showToast(res.message || 'تاریخچه فراخوان‌ها پاک شد.', 'success');
            })
            .catch(function (err) {
                showToast(err.message || 'خطا در پاک کردن تاریخچه.', 'error');
            });
    }

    /* ── Load status ── */
    function loadStatus() {
        fetch('/api/calls/status')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                /* فقط دستگاه‌های واقعی نمایشگر (نه iframe پیش‌نمایش) */
                var realCount = res.real_displays || 0;
                setStatus(realCount > 0 ? 'connected' : 'disconnected', realCount);
                updateDisplayStatusCard(res);
            }
        })
        .catch(function () { setStatus('disconnected'); });
    }

    /* ── Update display status card ── */
    function updateDisplayStatusCard(res) {
        var label = document.getElementById('csDisplayStatusLabel');
        var sub = document.getElementById('csDisplayStatusSub');
        var dot = document.getElementById('csDisplayDot');
        var body = document.getElementById('csDisplayStatusBody');
        var iconWrap = body ? body.querySelector('.cs-display-status-icon') : null;
        var previewOverlay = document.getElementById('csPreviewOverlay');
        if (!label || !sub || !dot) return;

        var realCount = res.real_displays || 0;

        if (realCount > 0) {
            label.textContent = 'نمایشگر متصل است';
            sub.textContent = realCount + ' دستگاه نمایشگر فعال در شبکه';
            dot.className = 'cs-display-status-dot cs-display-status-dot--on';
            if (body) body.className = 'cs-display-status-body cs-display-status-body--connected';
            if (iconWrap) iconWrap.className = 'cs-display-status-icon cs-display-status-icon--connected';
            /* نمایشگر متصل → مخفی کردن overlay پیش‌نمایش */
            if (previewOverlay) previewOverlay.classList.add('is-hidden');
        } else {
            label.textContent = 'در انتظار اتصال نمایشگر';
            sub.textContent = 'هیچ دستگاهی صفحه نمایش را باز نکرده است';
            dot.className = 'cs-display-status-dot cs-display-status-dot--off';
            if (body) body.className = 'cs-display-status-body';
            if (iconWrap) iconWrap.className = 'cs-display-status-icon cs-display-status-icon--waiting';
            /* نمایشگر متصل نیست → نمایش overlay */
            if (previewOverlay) previewOverlay.classList.remove('is-hidden');
        }
    }

    /* ── WebSocket ── */
    function connectWS() {
        var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        var url = proto + '//' + location.host + '/api/ws/call-display';
        try { ws = new WebSocket(url); } catch (e) { scheduleReconnect(); return; }

        setStatus('connecting');

        ws.onopen = function () {
            reconnectDelay = 1000;
            /* وضعیت اتصال توسط loadStatus بر اساس real_displays تعیین می‌شود */
            /* Management page identifies as preview, not a real TV display */
            try {
                ws.send(JSON.stringify({ tag: 'preview' }));
            } catch (e) { /* ignore */ }
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

    /* ====================================================================
       WAITING QUEUE (reception → sample collection)
       ==================================================================== */
    var waitingList = null;
    var waitingEmpty = null;
    var waitingCountEl = null;
    var waitingInput = null;
    var waitingDept = null;
    var waitingAddBtn = null;
    var waitingData = []; // [{id, reception_number, department, added_by, created_at, persian_number}]

    function renderWaitingQueue() {
        if (!waitingList) return;
        // Clear existing items (keep the empty placeholder)
        var items = waitingList.querySelectorAll('.cs-waiting-item');
        items.forEach(function (it) { it.remove(); });

        if (waitingData.length === 0) {
            if (waitingEmpty) waitingEmpty.style.display = '';
            if (waitingCountEl) waitingCountEl.textContent = '۰';
            return;
        }
        if (waitingEmpty) waitingEmpty.style.display = 'none';
        if (waitingCountEl) waitingCountEl.textContent = toPersianNum(waitingData.length);

        waitingData.forEach(function (item) {
            var el = document.createElement('div');
            el.className = 'cs-waiting-item';
            el.setAttribute('data-id', item.id);

            var numSpan = document.createElement('span');
            numSpan.className = 'cs-waiting-num';
            numSpan.textContent = item.persian_number || item.reception_number;

            var deptSpan = document.createElement('span');
            deptSpan.className = 'cs-waiting-dept';
            deptSpan.textContent = item.department || '';

            var timeSpan = document.createElement('span');
            timeSpan.className = 'cs-waiting-time';
            timeSpan.textContent = fmtTime(item.created_at);

            var actionsDiv = document.createElement('div');
            actionsDiv.className = 'cs-waiting-actions';

            // Call button
            var callBtnEl = document.createElement('button');
            callBtnEl.className = 'cs-waiting-btn cs-waiting-btn--call';
            callBtnEl.title = '\u0641\u0631\u0627\u062e\u0648\u0627\u0646';
            callBtnEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
            callBtnEl.addEventListener('click', function (e) {
                e.stopPropagation();
                callFromWaitingQueue(item.id);
            });

            // Remove button
            var rmBtnEl = document.createElement('button');
            rmBtnEl.className = 'cs-waiting-btn cs-waiting-btn--remove';
            rmBtnEl.title = '\u062d\u0630\u0641';
            rmBtnEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            rmBtnEl.addEventListener('click', function (e) {
                e.stopPropagation();
                removeFromWaitingQueue(item.id);
            });

            actionsDiv.appendChild(callBtnEl);
            actionsDiv.appendChild(rmBtnEl);

            el.appendChild(numSpan);
            el.appendChild(deptSpan);
            el.appendChild(timeSpan);
            el.appendChild(actionsDiv);
            waitingList.appendChild(el);
        });
    }

    function loadWaitingQueue() {
        fetch('/api/calls/waiting-queue')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.items) return;
            waitingData = res.items;
            renderWaitingQueue();
        })
        .catch(function () {});
    }

    function addToWaitingQueue() {
        var num = waitingInput ? waitingInput.value.trim() : '';
        if (!num) {
            showToast('\u0644\u0637\u0641\u0627\u064b \u0634\u0645\u0627\u0631\u0647 \u0631\u0627 \u0648\u0627\u0631\u062f \u06a9\u0646\u06cc\u062f.', 'error');
            if (waitingInput) waitingInput.focus();
            return;
        }
        var dept = waitingDept ? waitingDept.value : '\u0646\u0645\u0648\u0646\u0647\u200c\u06af\u06cc\u0631\u06cc';
        setLoading(waitingAddBtn, true);
        fetch('/api/calls/waiting-queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ number: num, department: dept })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setLoading(waitingAddBtn, false);
            if (res.success) {
                // Reload the full queue to get proper data
                loadWaitingQueue();
                showToast(res.message || '\u0628\u0647 \u0635\u0641 \u0636\u0645\u0646\u0647 \u0634\u062f.', 'success');
                if (waitingInput) { waitingInput.value = ''; waitingInput.focus(); }
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            setLoading(waitingAddBtn, false);
            showToast('\u0636\u0645\u0646 \u0628\u0647 \u0635\u0641 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    function removeFromWaitingQueue(id) {
        fetch('/api/calls/waiting-queue/' + id, { method: 'DELETE' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                waitingData = waitingData.filter(function (d) { return d.id !== id; });
                renderWaitingQueue();
                showToast(res.message || '\u0627\u0632 \u0635\u0641 \u062d\u0630\u0641 \u0634\u062f.', 'success');
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            showToast('\u062d\u0630\u0641 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    function callFromWaitingQueue(id) {
        fetch('/api/calls/waiting-queue/' + id + '/call', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                // Remove from local waiting data
                waitingData = waitingData.filter(function (d) { return d.id !== id; });
                renderWaitingQueue();
                showToast(res.message || '\u0641\u0631\u0627\u062e\u0648\u0627\u0646 \u0627\u0631\u0633\u0627\u0644 \u0634\u062f.', 'success');
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            showToast('\u0641\u0631\u0627\u062e\u0648\u0627\u0646 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    /* --- Persian number helper --- */
    function toPersianNum(n) {
        var persianDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        return String(n).replace(/[0-9]/g, function(d) { return persianDigits[parseInt(d)]; });
    }

    /* ====================================================================
       SLIDESHOW MANAGEMENT
       ==================================================================== */
    var slidesList = null;
    var slidesEmpty = null;
    var slidesCountEl = null;
    var slideUploadBtn = null;
    var slideFileInput = null;
    var slidesData = [];

    function renderSlides() {
        if (!slidesList) return;
        var items = slidesList.querySelectorAll('.cs-slide-item');
        items.forEach(function (it) { it.remove(); });

        if (slidesData.length === 0) {
            if (slidesEmpty) slidesEmpty.style.display = '';
            if (slidesCountEl) slidesCountEl.textContent = '۰';
            return;
        }
        if (slidesEmpty) slidesEmpty.style.display = 'none';
        if (slidesCountEl) slidesCountEl.textContent = toPersianNum(slidesData.length);

        slidesData.forEach(function (slide) {
            var el = document.createElement('div');
            el.className = 'cs-slide-item' + (slide.is_active ? '' : ' is-inactive');
            el.setAttribute('data-id', slide.id);

            var thumb = document.createElement('img');
            thumb.className = 'cs-slide-thumb';
            thumb.src = slide.url;
            thumb.alt = slide.original_name;
            thumb.loading = 'lazy';

            var info = document.createElement('div');
            info.className = 'cs-slide-info';

            var name = document.createElement('div');
            name.className = 'cs-slide-name';
            name.textContent = slide.original_name;

            var status = document.createElement('div');
            status.className = 'cs-slide-status ' + (slide.is_active ? 'cs-slide-status--active' : 'cs-slide-status--inactive');
            status.textContent = slide.is_active ? 'فعال' : 'غیرفعال';

            info.appendChild(name);
            info.appendChild(status);

            var actions = document.createElement('div');
            actions.className = 'cs-slide-actions';

            var toggleBtn = document.createElement('button');
            toggleBtn.className = 'cs-slide-btn cs-slide-btn--toggle';
            toggleBtn.title = slide.is_active ? 'غیرفعال کردن' : 'فعال کردن';
            toggleBtn.innerHTML = slide.is_active
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleSlide(slide.id);
            });

            var deleteBtn = document.createElement('button');
            deleteBtn.className = 'cs-slide-btn cs-slide-btn--delete';
            deleteBtn.title = 'حذف اسلاید';
            deleteBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
            deleteBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                deleteSlide(slide.id, slide.original_name);
            });

            actions.appendChild(toggleBtn);
            actions.appendChild(deleteBtn);

            el.appendChild(thumb);
            el.appendChild(info);
            el.appendChild(actions);
            slidesList.appendChild(el);
        });
    }

    function loadSlides() {
        fetch('/api/calls/slides')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.slides) return;
            slidesData = res.slides;
            renderSlides();
        })
        .catch(function () {});
    }

    function uploadSlide() {
        var files = slideFileInput ? slideFileInput.files : null;
        if (!files || files.length === 0) {
            showToast('\u0644\u0637\u0641\u0627\u064b \u0641\u0627\u06cc\u0644 \u0627\u0637\u0644\u0627\u0639\u06cc \u0627\u0646\u062a\u062e\u0627\u0628 \u06a9\u0646\u06cc\u062f.', 'error');
            return;
        }
        var file = files[0];
        var formData = new FormData();
        formData.append('file', file);

        if (slideUploadBtn) slideUploadBtn.disabled = true;
        fetch('/api/calls/slides/upload', {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (slideUploadBtn) slideUploadBtn.disabled = false;
            if (res.success) {
                showToast(res.message || '\u0627\u0633\u0644\u0627\u06cc\u062f \u0622\u067e\u0644\u0648\u062f \u0634\u062f.', 'success');
                if (slideFileInput) slideFileInput.value = '';
                loadSlides();
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            if (slideUploadBtn) slideUploadBtn.disabled = false;
            showToast('\u0622\u067e\u0644\u0648\u062f \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
        });
    }

    function toggleSlide(id) {
        fetch('/api/calls/slides/' + id + '/toggle', { method: 'PUT' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                loadSlides();
                showToast(res.message || '\u062a\u063a\u06cc\u06cc\u0631 \u0635\u0648\u0631\u062a.', 'success');
            } else {
                showToast(res.detail || '\u062e\u0637\u0627', 'error');
            }
        })
        .catch(function () {
            showToast('\u062e\u0637\u0627 \u062f\u0631 \u062a\u063a\u06cc\u06cc\u0631.', 'error');
        });
    }

    function deleteSlide(id, name) {
        showModal(
            '\u062d\u0630\u0641 \u0627\u0633\u0644\u0627\u06cc\u062f',
            '\u0622\u06cc\u0627 \u0627\u0632 \u062d\u0630\u0641 \u0627\u0633\u0644\u0627\u06cc\u062f \u060c ' + name + ' \u0627\u0637\u0645\u06cc\u0646\u0627\u0646 \u062f\u0627\u0631\u06cc\u062f\u061f',
            function () {
                fetch('/api/calls/slides/' + id, { method: 'DELETE' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        loadSlides();
                        showToast(res.message || '\u0627\u0633\u0644\u0627\u06cc\u062f \u062d\u0630\u0641 \u0634\u062f.', 'success');
                    } else {
                        showToast(res.detail || '\u062e\u0637\u0627', 'error');
                    }
                })
                .catch(function () {
                    showToast('\u062d\u0630\u0641 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u062f.', 'error');
                });
            }
        );
    }

    /* ── Init ── */
    function init() {
        numberInput = document.getElementById('csNumberInput');
        callBtn = document.getElementById('csCallBtn');
        repeatBtn = document.getElementById('csRepeatBtn');
        testDisplayBtn = document.getElementById('csTestDisplayBtn');
        testVoiceBtn = document.getElementById('csTestVoiceBtn');
        resetBtn = document.getElementById('csResetBtn');
        refreshDisplayBtn = document.getElementById('csRefreshDisplayBtn');
        statusText = document.getElementById('csStatusText');
        recentList = document.getElementById('csRecentList');
        historyEmpty = document.getElementById('csHistoryEmpty');
        queueCountEl = document.getElementById('csQueueCount');

        // Waiting queue elements
        waitingList = document.getElementById('csWaitingList');
        waitingEmpty = document.getElementById('csWaitingEmpty');
        waitingCountEl = document.getElementById('csWaitingCount');
        waitingInput = document.getElementById('csWaitingInput');
        waitingDept = document.getElementById('csWaitingDept');
        waitingAddBtn = document.getElementById('csWaitingAddBtn');

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
        if (refreshDisplayBtn) refreshDisplayBtn.addEventListener('click', refreshDisplays);
        if (waitingAddBtn) waitingAddBtn.addEventListener('click', addToWaitingQueue);

        // Slideshow elements
        slidesList = document.getElementById('csSlidesList');
        slidesEmpty = document.getElementById('csSlidesEmpty');
        slidesCountEl = document.getElementById('csSlideCount');
        slideUploadBtn = document.getElementById('csSlideUploadBtn');
        slideFileInput = document.getElementById('csSlideFileInput');

        if (slideUploadBtn) slideUploadBtn.addEventListener('click', function () {
            if (slideFileInput) slideFileInput.click();
        });
        if (slideFileInput) slideFileInput.addEventListener('change', uploadSlide);

        if (numberInput) {
            numberInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); makeCall(); }
            });
        }
        if (waitingInput) {
            waitingInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); addToWaitingQueue(); }
            });
        }

        loadDisplayQueue();
        loadWaitingQueue();
        loadQueueTickets();
        loadRecent();
        loadStatus();
        loadAudioStatus();
        loadSlides();
        connectWS();

        /* Poll display status every 8 seconds */
        setInterval(loadStatus, 8000);

        /* Poll queue tickets every 5 seconds */
        setInterval(loadQueueTickets, 5000);

        /* ── Theme Toggle ── */
        var themeToggle = document.getElementById('csThemeToggle');
        var savedTheme = localStorage.getItem('cs-theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
        }
        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                document.body.classList.toggle('dark-mode');
                var isDark = document.body.classList.contains('dark-mode');
                localStorage.setItem('cs-theme', isDark ? 'dark' : 'light');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ── Back-Forward Cache: reconnect WebSocket on pageshow ── */
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            if (typeof ws !== 'undefined' && ws) {
                try { ws.close(); } catch (err) { /* ignore */ }
                ws = null;
            }
            connectWS();
        }
    });

    /* ═══════════════════════════════════════════════════════════════════
       Queue Tickets (سامانه نوبت‌دهی)
       ═══════════════════════════════════════════════════════════════════ */
    var ticketList = document.getElementById('csTicketList');
    var ticketEmpty = document.getElementById('csTicketEmpty');
    var ticketCountEl = document.getElementById('csTicketCount');
    var ticketData = [];
    var ticketStatusFilter = 'waiting';

    function loadQueueTickets(status, button) {
        status = status || ticketStatusFilter;
        ticketStatusFilter = status;
        document.querySelectorAll('.cs-ticket-filter').forEach(function (filter) {
            filter.classList.toggle('cs-ticket-filter--active',
                filter === button || (!button && filter.dataset.ticketStatus === status));
        });
        fetch('/api/queue/list?status=' + status)
        .then(function (r) { return r.json(); })
        .then(function (res) {
            ticketData = res.tickets || [];
            renderQueueTickets();
        })
        .catch(function () {});
    }

    function renderQueueTickets() {
        if (!ticketList) return;
        if (ticketData.length === 0) {
            ticketList.querySelectorAll('.cs-waiting-item').forEach(function (it) { it.remove(); });
            if (ticketEmpty) ticketEmpty.style.display = '';
            if (ticketCountEl) ticketCountEl.textContent = '0';
            return;
        }
        if (ticketEmpty) ticketEmpty.style.display = 'none';
        if (ticketCountEl) ticketCountEl.textContent = String(ticketData.length);

        var visibleIds = {};
        ticketData.forEach(function (item) {
            var id = String(item.id);
            visibleIds[id] = true;
            var el = ticketList.querySelector('.cs-waiting-item[data-id="' + id + '"]');
            if (!el) {
                el = createTicketElement(item);
                ticketList.appendChild(el);
            } else {
                updateTicketElement(el, item);
            }
        });

        ticketList.querySelectorAll('.cs-waiting-item').forEach(function (el) {
            if (!visibleIds[el.getAttribute('data-id')]) el.remove();
        });
    }

    function createTicketElement(item) {
        var el = document.createElement('div');
        el.className = 'cs-waiting-item';
        el.setAttribute('data-id', item.id);
        el.setAttribute('aria-expanded', 'false');
        updateTicketElement(el, item);
        return el;
    }

    function updateTicketElement(el, item) {
        var signature = [
            item.ticket_number, item.status, item.service, item.called_for,
            item.created_at, item.patient_name, item.patient_age,
            item.patient_national_id, item.patient_phone,
            item.insurance_base, item.insurance_extra
        ].map(function (value) { return value || ''; }).join('|');
        if (el.getAttribute('data-signature') === signature) return;
        el.setAttribute('data-signature', signature);
        el.replaceChildren();
        el.setAttribute('data-id', item.id);
        var numSpan = document.createElement('span');
            numSpan.className = 'cs-waiting-num';
            numSpan.textContent = item.persian_number || item.ticket_number;

            var deptSpan = document.createElement('span');
            deptSpan.className = 'cs-waiting-dept';
            deptSpan.textContent = item.service || '';

            var details = document.createElement('div');
            details.className = 'cs-ticket-patient';
            [
                ['نام', item.patient_name],
                ['سن', item.patient_age],
                ['کد ملی', item.patient_national_id],
                ['تلفن', item.patient_phone],
                ['بیمه پایه', item.insurance_base],
                ['بیمه تکمیلی', item.insurance_extra],
            ].forEach(function (field) {
                if (!field[1]) return;
                var detail = document.createElement('span');
                detail.className = 'cs-ticket-patient__field';
                var label = document.createElement('small');
                label.textContent = field[0];
                var value = document.createElement('strong');
                value.textContent = field[1];
                detail.appendChild(label);
                detail.appendChild(value);
                details.appendChild(detail);
            });
            var detailsToggle = document.createElement('span');
            detailsToggle.className = 'cs-ticket-details-toggle';
            detailsToggle.textContent = details.childNodes.length ? 'مشاهده جزئیات' : '';
            if (details.childNodes.length) {
                detailsToggle.setAttribute('role', 'button');
                detailsToggle.setAttribute('tabindex', '0');
                detailsToggle.addEventListener('click', function (event) {
                    event.stopPropagation();
                    var expanded = el.classList.toggle('is-expanded');
                    el.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    detailsToggle.textContent = expanded ? 'بستن جزئیات' : 'مشاهده جزئیات';
                });
                detailsToggle.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    detailsToggle.click();
                });
            }

            var timeSpan = document.createElement('span');
            timeSpan.className = 'cs-waiting-time';
            timeSpan.textContent = fmtTime(item.created_at);

            var actionsDiv = document.createElement('div');
            actionsDiv.className = 'cs-waiting-actions';

            if (item.status === 'waiting') {
                // Call button
                var callBtnEl = document.createElement('button');
                callBtnEl.className = 'cs-waiting-btn cs-waiting-btn--call';
                callBtnEl.title = 'فراخوان';
                callBtnEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
                callBtnEl.addEventListener('click', function (e) {
                    e.stopPropagation();
                    callQueueTicket(item.id);
                });
                actionsDiv.appendChild(callBtnEl);
                var deleteBtnEl = document.createElement('button');
                deleteBtnEl.className = 'cs-waiting-btn cs-waiting-btn--remove';
                deleteBtnEl.title = 'حذف نوبت';
                deleteBtnEl.setAttribute('aria-label', 'حذف نوبت');
                deleteBtnEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>';
                deleteBtnEl.addEventListener('click', function (e) {
                    e.stopPropagation();
                    deleteQueueTicket(item.id, item.persian_number || item.ticket_number);
                });
                actionsDiv.appendChild(deleteBtnEl);
            } else if (item.status === 'called') {
                deptSpan.textContent = (item.called_for || item.service || '') + ' — فراخوان شده';
            } else if (item.status === 'completed') {
                deptSpan.textContent = (item.called_for || item.service || '') + ' — انجام شده';
            }

            el.appendChild(numSpan);
            var info = document.createElement('div');
            info.className = 'cs-ticket-info';
            info.appendChild(deptSpan);
            if (details.childNodes.length) info.appendChild(details);
            if (details.childNodes.length) info.appendChild(detailsToggle);
            var side = document.createElement('div');
            side.className = 'cs-ticket-side';
            side.appendChild(timeSpan);
            side.appendChild(actionsDiv);
            el.appendChild(info);
            el.appendChild(side);
    }

    function callQueueTicket(id) {
        var dept = (document.getElementById('csTicketDept') || {}).value || 'پذیرش';
        fetch('/api/queue/call/' + id + '?department=' + encodeURIComponent(dept), {
            method: 'POST',
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                showToast(res.message || 'نوبت فراخوان شد.', 'success');
                loadQueueTickets();
            } else {
                showToast(res.detail || 'خطا', 'error');
            }

        })
        .catch(function () { showToast('خطا در اتصال به سرور', 'error'); });
    }

    function deleteQueueTicket(id, number) {
        if (!window.confirm('آیا از حذف نوبت ' + number + ' از صف مطمئن هستید؟')) return;
        fetch('/api/queue/' + encodeURIComponent(id), { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error(res.detail || res.error || 'حذف نوبت انجام نشد.');
                showToast(res.message || 'نوبت حذف شد.', 'success');
                loadQueueTickets();
            })
            .catch(function (err) { showToast(err.message || 'خطا در حذف نوبت.', 'error'); });
    }

    function deleteAllWaitingTickets() {
        if (!window.confirm('آیا از حذف همه نوبت‌های در انتظار مطمئن هستید؟')) return;
        fetch('/api/queue', { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error(res.detail || res.error || 'حذف نوبت‌ها انجام نشد.');
                showToast(res.message || 'نوبت‌ها حذف شدند.', 'success');
                loadQueueTickets();
            })
            .catch(function (err) { showToast(err.message || 'خطا در حذف نوبت‌ها.', 'error'); });
    }

    function callNextTicket() {
        var dept = (document.getElementById('csTicketDept') || {}).value || 'پذیرش';
        fetch('/api/queue/call-next?department=' + encodeURIComponent(dept), {
            method: 'POST',
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                showToast(res.message || 'نوبت بعدی فراخوان شد.', 'success');
                loadQueueTickets();
            } else {
                showToast(res.detail || 'خطا', 'error');
            }
        })
        .catch(function () { showToast('خطا در اتصال به سرور', 'error'); });
    }

    window.loadQueueTickets = loadQueueTickets;
    window.callNextTicket = callNextTicket;
    window.deleteAllWaitingTickets = deleteAllWaitingTickets;
    window.clearCallHistory = clearCallHistory;

    /* ── Live Preview: overlay کنترل‌شده توسط وضعیت اتصال ── */
    /* (overlay توسط updateDisplayStatusCard نمایش/مخفی می‌شود) */
})();
