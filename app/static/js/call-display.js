/**
 * سامانه فراخوان نمونه‌گیری — TV Display Page Logic
 *
 * Connects via WebSocket, displays calls in real time,
 * plays Persian voice announcements, handles browser audio restrictions,
 * and manages automatic reconnection.
 */
(function () {
    'use strict';

    /* ── DOM refs ── */
    var activateOverlay = document.getElementById('activateOverlay');
    var activateBtn = document.getElementById('activateBtn');
    var statusDot = document.getElementById('statusDot');
    var statusText = document.getElementById('statusText');
    var currentCall = document.getElementById('currentCall');
    var callMessage = document.getElementById('callMessage');
    var waitingState = document.getElementById('waitingState');
    var audioEl = document.getElementById('callAudio');

    /* ── Slots ── */
    var MAX_SLOTS = 5;
    var slots = [];
    for (var i = 0; i < MAX_SLOTS; i++) {
        var el = document.getElementById('slot' + i);
        if (el) {
            slots.push({
                el: el,
                number: el.querySelector('.call-display-slot-number'),
                dept: el.querySelector('.call-display-slot-dept')
            });
        }
    }
    var slotData = new Array(MAX_SLOTS).fill(null);

    /* ── State ── */
    var ws = null;
    var reconnectDelay = 1000;
    var maxReconnectDelay = 30000;
    var reconnectTimer = null;
    var audioReady = false;
    var speechQueue = [];
    var speaking = false;

    /* ── Persian digits ── */
    var FA = '۰۱۲۳۴۵۶۷۸۹';
    function toFA(n) {
        return String(n).replace(/[0-9]/g, function (d) { return FA[+d]; });
    }

    /* ── Status ── */
    function setStatus(state) {
        if (!statusDot) return;
        var map = {
            connected: 'call-display-status-dot--connected',
            disconnected: 'call-display-status-dot--disconnected',
            connecting: 'call-display-status-dot--connecting'
        };
        statusDot.className = 'call-display-status-dot ' + (map[state] || map.connecting);
        var labels = {
            connected: 'متصل',
            disconnected: 'اتصال قطع است',
            connecting: 'در حال اتصال...'
        };
        if (statusText) statusText.textContent = labels[state] || state;
    }

    /* ── Audio activation ── */
    function initAudio() {
        if (audioReady) return;
        // Try to play a silent sound to unlock audio context
        try {
            if (audioEl) {
                audioEl.volume = 0.01;
                audioEl.play().then(function () {
                    audioEl.pause();
                    audioEl.currentTime = 0;
                    audioEl.volume = 1;
                    audioReady = true;
                }).catch(function () {
                    audioReady = false;
                });
            }
            // Also try SpeechSynthesis
            if (window.speechSynthesis) {
                var u = new SpeechSynthesisUtterance('');
                u.volume = 0;
                window.speechSynthesis.speak(u);
            }
        } catch (e) { /* ignore */ }
        audioReady = true;
        if (activateOverlay) activateOverlay.hidden = true;
    }

    /* ── Speech synthesis ── */
    function getPreferredVoice() {
        if (!window.speechSynthesis) return null;
        var voices = window.speechSynthesis.getVoices();
        // Prefer Persian voices
        for (var i = 0; i < voices.length; i++) {
            var v = voices[i];
            if (v.lang === 'fa-IR' || v.lang === 'fa') return v;
        }
        // Fallback: any voice with 'fa' in the name
        for (var j = 0; j < voices.length; j++) {
            if (voices[j].lang && voices[j].lang.indexOf('fa') === 0) return voices[j];
        }
        return null;
    }

    function speak(text) {
        if (!text || !window.speechSynthesis) return;
        speechQueue.push(text);
        processSpeechQueue();
    }

    function processSpeechQueue() {
        if (speaking || speechQueue.length === 0) return;
        speaking = true;
        var text = speechQueue.shift();

        var utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'fa-IR';
        utterance.rate = 0.9;
        utterance.pitch = 1;
        utterance.volume = 1;

        var preferredVoice = getPreferredVoice();
        if (preferredVoice) {
            utterance.voice = preferredVoice;
        }

        utterance.onend = function () {
            speaking = false;
            // Small delay before next announcement
            setTimeout(processSpeechQueue, 300);
        };

        utterance.onerror = function () {
            speaking = false;
            setTimeout(processSpeechQueue, 300);
        };

        window.speechSynthesis.speak(utterance);
    }

    // Load voices (some browsers load asynchronously)
    if (window.speechSynthesis) {
        window.speechSynthesis.onvoiceschanged = function () { getPreferredVoice(); };
    }

    /* ── Display a call ── */
    function displayCall(data) {
        if (!data) return;

        // Hide waiting state
        if (waitingState) waitingState.hidden = true;

        // Show message
        if (currentCall) {
            currentCall.classList.add('is-active');
        }
        if (callMessage) callMessage.textContent = data.message || '';

        // Shift slots and add new call at position 0
        if (data.number) {
            // Shift existing data right
            for (var j = MAX_SLOTS - 1; j > 0; j--) {
                slotData[j] = slotData[j - 1];
            }
            slotData[0] = data;
            renderSlots();
        }

        // Speak
        if (data.voice && audioReady) {
            speak(data.voice);
        }
    }

    /* ── Render slots ── */
    function renderSlots() {
        for (var i = 0; i < MAX_SLOTS; i++) {
            var s = slots[i];
            if (!s) continue;
            var d = slotData[i];
            if (d) {
                s.number.textContent = d.persian_number || d.number || '';
                s.dept.textContent = d.department || '';
                s.el.classList.add('is-active');
                s.el.classList.toggle('is-latest', i === 0);
                s.el.classList.toggle('is-test', !!d.is_test);
                s.el.classList.remove('is-empty');
            } else {
                s.number.textContent = '';
                s.dept.textContent = '';
                s.el.classList.remove('is-active', 'is-latest', 'is-test');
                s.el.classList.add('is-empty');
            }
        }
    }

    /* ── Escape HTML ── */
    function escHtml(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s || ''));
        return div.innerHTML;
    }

    /* ── WebSocket ── */
    function connect() {
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
            reconnectDelay = 1000; // Reset delay on successful connect
            setStatus('connected');

            // Ping every 25 seconds to keep alive
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
                if (msg.type === 'reception_call' && msg.data) {
                    displayCall(msg.data);
                }
            } catch (e) { /* ignore */ }
        };

        ws.onclose = function () {
            clearInterval(ws._pingInterval);
            setStatus('disconnected');
            scheduleReconnect();
        };

        ws.onerror = function () {
            // onclose will fire after onerror
        };
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
        // Show activation overlay if needed
        if (activateOverlay) {
            activateOverlay.hidden = false;
            if (activateBtn) {
                activateBtn.addEventListener('click', function () {
                    initAudio();
                });
            }
        } else {
            initAudio();
        }

        // Start WebSocket
        connect();

        // Make fullscreen on double-click
        document.addEventListener('dblclick', function () {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(function () { /* ignore */ });
            } else {
                document.exitFullscreen().catch(function () { /* ignore */ });
            }
        });

        // Activate audio on first user interaction
        document.addEventListener('click', function () {
            if (!audioReady) initAudio();
        }, { once: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
