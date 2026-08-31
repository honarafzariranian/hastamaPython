/**
 * TV Display — Call System
 * WebSocket + local MP3 audio + modern hero layout
 */
(function () {
    'use strict';

    /* ── DOM ── */
    var activateOverlay = document.getElementById('activateOverlay');
    var activateBtn = document.getElementById('activateBtn');
    var statusText = document.getElementById('statusText');
    var connIconWrap = document.getElementById('connIconWrap');
    var connRow = document.querySelector('.cd-status-row--conn');
    var heroCall = document.getElementById('heroCall');
    var heroWaiting = document.getElementById('heroWaiting');
    var heroNumber = document.getElementById('heroNumber');
    var heroDept = document.getElementById('heroDept');
    var heroMessage = document.getElementById('heroMessage');
    var audioEl = document.getElementById('callAudio');

    var previousGrid = document.getElementById('previousGrid');
    var cdPrevious = document.getElementById('cdPrevious');

    /* ── Previous slots (1–4) ── */
    var MAX_PREV = 4;
    var prevSlots = [];
    for (var i = 1; i <= MAX_PREV; i++) {
        var el = document.getElementById('slot' + i);
        if (el) {
            prevSlots.push({
                el: el,
                num: el.querySelector('.cd-prev-num'),
                dept: el.querySelector('.cd-prev-dept')
            });
        }
    }
    var prevData = new Array(MAX_PREV).fill(null);

    /* ── State ── */
    var ws = null;
    var reconnectDelay = 1000;
    var maxReconnectDelay = 30000;
    var reconnectTimer = null;
    var audioReady = false;
    var audioQueue = [];
    var audioPlaying = false;
    var heroData = null;

    var AUDIO_BASE = '/static/audio/sample_call/fa-IR-DilaraNeural/';

    /* ── Persian digits ── */
    var FA = '\u06f0\u06f1\u06f2\u06f3\u06f4\u06f5\u06f6\u06f7\u06f8\u06f9';
    function toFA(n) {
        return String(n).replace(/[0-9]/g, function (d) { return FA[+d]; });
    }

    /* ── Status ── */
    function setStatus(state) {
        if (!statusText) return;
        // Update icon wrapper class
        if (connIconWrap) {
            connIconWrap.className = 'cd-status-icon-wrap cd-status-icon-wrap--' + state;
        }
        // Update row class
        if (connRow) {
            connRow.className = 'cd-status-row cd-status-row--conn cd-status-row--' + state;
        }
        // Show/hide the correct icon
        var svgs = {
            connecting: document.querySelector('.cd-status-svg--connecting'),
            connected: document.querySelector('.cd-status-svg--connected'),
            disconnected: document.querySelector('.cd-status-svg--disconnected')
        };
        Object.keys(svgs).forEach(function(k) {
            if (svgs[k]) svgs[k].style.display = (k === state) ? '' : 'none';
        });
        var labels = {
            connected: '\u0645\u062a\u0635\u0644',
            disconnected: '\u0642\u0637\u0639',
            connecting: '\u062f\u0631 \u062d\u0627\u0644 \u0627\u062a\u0635\u0627\u0644...'
        };
        statusText.textContent = labels[state] || state;
    }

    /* ── Audio status (no-op — UI removed) ── */
    function setAudioStatus() {}

    /* ── Audio activation ── */
    function initAudio() {
        if (audioReady) return;
        try {
            if (audioEl) {
                audioEl.volume = 0.01;
                audioEl.play().then(function () {
                    audioEl.pause(); audioEl.currentTime = 0; audioEl.volume = 1;
                    audioReady = true; setAudioStatus('active');
                }).catch(function () { audioReady = false; });
            }
        } catch (e) { /* ignore */ }
        audioReady = true;
        setAudioStatus('active');
        if (activateOverlay) activateOverlay.hidden = true;
    }

    /* ── Audio URL ── */
    function getAudioUrl(number) {
        var num = parseInt(String(number).replace(/[^\d]/g, ''), 10);
        if (isNaN(num) || num < 1 || num > 2000) return null;
        var padded = String(num);
        while (padded.length < 4) padded = '0' + padded;
        return AUDIO_BASE + padded + '.mp3';
    }

    /* ── Audio queue ── */
    function queueAudio(number) {
        var url = getAudioUrl(number);
        if (!url) return;
        audioQueue.push({ url: url, number: number });
        processAudioQueue();
    }

    function processAudioQueue() {
        if (audioPlaying || audioQueue.length === 0 || !audioReady) return;
        audioPlaying = true;
        var item = audioQueue.shift();
        setAudioStatus('loading');

        var audio = new Audio();
        audio.preload = 'auto';
        audio.src = item.url;

        audio.oncanplaythrough = function () {
            setAudioStatus('active');
            audio.play().catch(function () {
                setAudioStatus('error'); audioPlaying = false;
                setTimeout(processAudioQueue, 500);
            });
        };
        audio.onended = function () {
            audioPlaying = false; setAudioStatus('active');
            setTimeout(processAudioQueue, 300);
        };
        audio.onerror = function () {
            audioPlaying = false; setAudioStatus('error');
            showAudioError(item.number);
            setTimeout(processAudioQueue, 500);
        };
        audio.load();
    }

    function showAudioError(number) {
        if (!heroMessage) return;
        heroMessage.textContent = '\u0641\u0627\u06cc\u0644 \u0635\u0648\u062a\u06cc \u0634\u0645\u0627\u0631\u0647 ' + toFA(String(number)) + ' \u0645\u0648\u062c\u0648\u062f \u0646\u06cc\u0633\u062a.';
        setTimeout(function () { if (heroMessage) heroMessage.textContent = ''; }, 5000);
    }

    /* ── Display a call ── */
    function displayCall(data) {
        if (!data || !data.number) return;

        // Shift previous slots
        for (var j = MAX_PREV - 1; j > 0; j--) {
            prevData[j] = prevData[j - 1];
        }
        // Move old hero to slot 1
        if (heroData && heroData.number) {
            prevData[0] = heroData;
        }
        // Set new hero
        heroData = data;

        renderHero();
        renderPrev();

        // Play audio
        if (data.number && audioReady) queueAudio(data.number);
    }

    /* ── Render hero ── */
    function renderHero() {
        if (!heroData) {
            if (heroCall) heroCall.style.display = 'none';
            if (heroWaiting) heroWaiting.hidden = false;
            return;
        }
        if (heroWaiting) heroWaiting.hidden = true;
        if (heroCall) {
            heroCall.style.display = '';
            // Re-trigger animation
            heroCall.style.animation = 'none';
            void heroCall.offsetHeight;
            heroCall.style.animation = '';
        }
        if (heroNumber) heroNumber.textContent = heroData.persian_number || heroData.number || '';
        if (heroDept) heroDept.textContent = heroData.department || '';
        if (heroMessage) heroMessage.textContent = heroData.message || '';
    }

    /* ── Render previous slots ── */
    function renderPrev() {
        for (var i = 0; i < MAX_PREV; i++) {
            var s = prevSlots[i];
            if (!s) continue;
            var d = prevData[i];
            if (d) {
                s.num.textContent = d.persian_number || d.number || '';
                s.dept.textContent = d.department || '';
                s.el.classList.add('is-active');
                s.el.classList.remove('is-empty');
            } else {
                s.num.textContent = '';
                s.dept.textContent = '';
                s.el.classList.remove('is-active');
                s.el.classList.add('is-empty');
            }
        }
    }

    /* ── WebSocket ── */
    function connect() {
        var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        var url = proto + '//' + location.host + '/api/ws/call-display';
        try { ws = new WebSocket(url); } catch (e) { scheduleReconnect(); return; }

        setStatus('connecting');

        ws.onopen = function () {
            reconnectDelay = 1000;
            setStatus('connected');
            ws._pingInterval = setInterval(function () {
                if (ws && ws.readyState === WebSocket.OPEN) {
                    try { ws.send('ping'); } catch (e) { /* ignore */ }
                }
            }, 25000);
        };

        ws.onmessage = function (evt) {
            try {
                var msg = JSON.parse(evt.data);
                if (msg.type === 'pong') return;
                if (msg.type === 'reception_call' && msg.data) displayCall(msg.data);
            } catch (e) { /* ignore */ }
        };

        ws.onclose = function () {
            clearInterval(ws._pingInterval);
            setStatus('disconnected');
            scheduleReconnect();
        };
        ws.onerror = function () {};
    }

    function scheduleReconnect() {
        clearTimeout(reconnectTimer);
        reconnectTimer = setTimeout(function () {
            reconnectDelay = Math.min(reconnectDelay * 1.5, maxReconnectDelay);
            connect();
        }, reconnectDelay);
    }

    /* ── Init ── */
    function init() {
        if (activateOverlay) {
            activateOverlay.hidden = false;
            if (activateBtn) activateBtn.addEventListener('click', initAudio);
        } else {
            initAudio();
        }

        connect();

        document.addEventListener('dblclick', function () {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(function () {});
            } else {
                document.exitFullscreen().catch(function () {});
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
