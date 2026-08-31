/**
 * سامانه فراخوان نمونه‌گیری — TV Display Page Logic
 *
 * Connects via WebSocket, displays calls in real time,
 * plays pre-generated local MP3 audio files,
 * handles browser audio restrictions,
 * and manages automatic reconnection.
 *
 * Audio architecture:
 *   /static/audio/sample_call/fa-IR-DilaraNeural/XXXX.mp3
 *   where XXXX is zero-padded reception number (1-2000)
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
    var audioStatus = document.getElementById('audioStatus');

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

    /* ── Audio queue ── */
    var audioQueue = [];
    var audioPlaying = false;

    /* ── Audio directory path ── */
    var AUDIO_BASE = '/static/audio/sample_call/fa-IR-DilaraNeural/';

    /* ── Persian digits ── */
    var FA = '\u06f0\u06f1\u06f2\u06f3\u06f4\u06f5\u06f6\u06f7\u06f8\u06f9';
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
            connected: '\u0645\u062a\u0635\u0644',
            disconnected: '\u0627\u062a\u0635\u0627\u0644 \u0642\u0637\u0639 \u0627\u0633\u062a',
            connecting: '\u062f\u0631 \u062d\u0627\u0644 \u0627\u062a\u0635\u0627\u0644...'
        };
        if (statusText) statusText.textContent = labels[state] || state;
    }

    /* ── Audio status indicator ── */
    function setAudioStatus(state) {
        if (!audioStatus) return;
        if (state === 'active') {
            audioStatus.textContent = '\u0635\u062f\u0627 \u0641\u0639\u0627\u0644 \u0627\u0633\u062a';
            audioStatus.className = 'call-display-audio-status call-display-audio-status--active';
        } else if (state === 'inactive') {
            audioStatus.textContent = '\u0628\u0631\u0627\u06cc \u0641\u0639\u0627\u0644\u0633\u0627\u0632\u06cc \u0635\u062f\u0627 \u06a9\u0644\u06cc\u06a9 \u06a9\u0646\u06cc\u062f';
            audioStatus.className = 'call-display-audio-status call-display-audio-status--inactive';
        }
    }

    /* ── Audio activation (browser autoplay policy) ── */
    function initAudio() {
        if (audioReady) return;
        try {
            if (audioEl) {
                audioEl.volume = 0.01;
                audioEl.play().then(function () {
                    audioEl.pause();
                    audioEl.currentTime = 0;
                    audioEl.volume = 1;
                    audioReady = true;
                    setAudioStatus('active');
                }).catch(function () {
                    audioReady = false;
                });
            }
        } catch (e) { /* ignore */ }
        audioReady = true;
        setAudioStatus('active');
        if (activateOverlay) activateOverlay.hidden = true;
    }

    /* ── Audio file URL from reception number ── */
    function getAudioUrl(number) {
        // Parse the number to get a numeric value
        var num = parseInt(String(number).replace(/[^\d]/g, ''), 10);
        if (isNaN(num) || num < 1 || num > 2000) {
            return null;
        }
        // Zero-pad to 4 digits
        var padded = String(num);
        while (padded.length < 4) padded = '0' + padded;
        return AUDIO_BASE + padded + '.mp3';
    }

    /* ── Audio queue management ── */
    function queueAudio(number) {
        var url = getAudioUrl(number);
        if (!url) {
            // Missing or invalid number — skip audio
            console.warn('Invalid reception number for audio:', number);
            return;
        }
        audioQueue.push({ url: url, number: number });
        processAudioQueue();
    }

    function processAudioQueue() {
        if (audioPlaying || audioQueue.length === 0) return;
        if (!audioReady) return;

        audioPlaying = true;
        var item = audioQueue.shift();

        // Update status
        setAudioStatus('loading');

        // Create a new Audio element for each playback to avoid conflicts
        var audio = new Audio();
        audio.preload = 'auto';
        audio.src = item.url;

        audio.oncanplaythrough = function () {
            setAudioStatus('active');
            audio.play().catch(function (err) {
                console.warn('Audio playback failed:', err);
                setAudioStatus('error');
                audioPlaying = false;
                setTimeout(processAudioQueue, 500);
            });
        };

        audio.onended = function () {
            audioPlaying = false;
            setAudioStatus('active');
            // Small delay before next queued audio
            setTimeout(processAudioQueue, 300);
        };

        audio.onerror = function () {
            console.warn('Audio file not found or error:', item.url);
            audioPlaying = false;
            setAudioStatus('error');
            // Show non-blocking error message
            showAudioError(item.number);
            // Continue queue after delay
            setTimeout(processAudioQueue, 500);
        };

        // Start loading
        audio.load();
    }

    /* ── Show audio error (non-blocking) ── */
    function showAudioError(number) {
        if (!callMessage) return;
        var persianNum = toFA(String(number));
        callMessage.textContent = '\u0641\u0627\u06cc\u0644 \u0635\u0648\u062a\u06cc \u0634\u0645\u0627\u0631\u0647 ' + persianNum + ' \u0645\u0648\u062c\u0648\u062f \u0646\u06cc\u0633\u062a.';
        if (currentCall) currentCall.classList.add('is-active');
        // Clear after 5 seconds
        setTimeout(function () {
            if (callMessage && callMessage.textContent.indexOf(persianNum) !== -1) {
                callMessage.textContent = '';
                if (currentCall) currentCall.classList.remove('is-active');
            }
        }, 5000);
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
            for (var j = MAX_SLOTS - 1; j > 0; j--) {
                slotData[j] = slotData[j - 1];
            }
            slotData[0] = data;
            renderSlots();
        }

        // Play local MP3 audio
        if (data.number && audioReady) {
            queueAudio(data.number);
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
