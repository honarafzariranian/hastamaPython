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
    var autoResumedRemote = false;   /* true when page reloaded by a remote refresh command */

    var AUDIO_BASE = '/static/audio/sample_call/fa-IR-DilaraNeural/';
    var realtimeConfig = window.HastamaRealtime || {};
    var realtimeMode = realtimeConfig.mode || 'legacy';
    var audioActivationAnnounced = false;

    function isRealtimeReverb() {
        return realtimeMode === 'reverb' && !!realtimeConfig.appKey;
    }

    function realtimeWsUrl() {
        if (!isRealtimeReverb()) {
            var legacyProto = location.protocol === 'https:' ? 'wss:' : 'ws:';
            return legacyProto + '//' + location.host + '/api/ws/call-display';
        }

        var scheme = realtimeConfig.scheme || (location.protocol === 'https:' ? 'https' : 'http');
        var wsProto = scheme === 'https' ? 'wss:' : 'ws:';
        var host = realtimeConfig.host || location.hostname || location.host;
        var port = realtimeConfig.port;
        var path = realtimeConfig.path || '';

        if (port === null || typeof port === 'undefined' || port === '') {
            port = location.port || (wsProto === 'wss:' ? 443 : 80);
        }
        if (path && path.charAt(0) !== '/') path = '/' + path;
        path = path.replace(/\/$/, '');

        var authority = host;
        var hasPort = String(host).indexOf(':') !== -1;
        var isDefaultPort = (wsProto === 'wss:' && Number(port) === 443) || (wsProto === 'ws:' && Number(port) === 80);
        if (!hasPort && port && !isDefaultPort) authority += ':' + port;

        return wsProto + '//' + authority + path + '/app/' + encodeURIComponent(realtimeConfig.appKey) + '?protocol=7&client=hastama-legacy&version=1.0&flash=false';
    }

    function realtimeChannel(isPreview) {
        return isPreview
            ? (realtimeConfig.previewChannel || 'call-display.preview')
            : (realtimeConfig.displayChannel || 'call-display');
    }

    function realtimeEventName() {
        return realtimeConfig.eventName || 'display.message';
    }

    function notifyAudioActivated() {
        if (audioActivationAnnounced) return true;

        if (isRealtimeReverb() && realtimeConfig.audioActivatedUrl) {
            audioActivationAnnounced = true;
            fetch(realtimeConfig.audioActivatedUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).catch(function () { audioActivationAnnounced = false; });
            return true;
        }

        if (ws && ws.readyState === WebSocket.OPEN) {
            try {
                ws.send(JSON.stringify({ type: 'audio_activated' }));
                audioActivationAnnounced = true;
                return true;
            } catch (e) { /* ignore */ }
        }

        return false;
    }

    function handleRealtimePayload(msg) {
        if (!msg || msg.type === 'pong') return;
        if (msg.type === 'reception_call' && msg.data) displayCall(msg.data);
        if (msg.type === 'remove_call' && msg.data) removeCall(msg.data.number);
        if (msg.type === 'reset_display') resetDisplay();
        if (msg.type === 'refresh_display') { handleRemoteRefresh(); return; }
        if (msg.type === 'audio_activated') {
            if (activateOverlay) activateOverlay.hidden = true;
            audioReady = true;
        }
    }

    function handleRealtimeFrame(rawFrame, isPreview) {
        try {
            var msg = JSON.parse(rawFrame);

            if (!isRealtimeReverb()) {
                handleRealtimePayload(msg);
                return;
            }

            if (msg.event === 'pusher:connection_established') {
                reconnectDelay = 1000;
                setStatus('connected');
                try {
                    ws.send(JSON.stringify({
                        event: 'pusher:subscribe',
                        data: { channel: realtimeChannel(isPreview) }
                    }));
                } catch (e) { /* ignore */ }
                if (autoResumedRemote) notifyAudioActivated();
                return;
            }

            if (msg.event === 'pusher:ping') {
                try { ws.send(JSON.stringify({ event: 'pusher:pong', data: {} })); } catch (e) { /* ignore */ }
                return;
            }

            if (msg.event !== realtimeEventName()) return;

            var payload = msg.data;
            if (typeof payload === 'string') {
                payload = JSON.parse(payload);
            }
            handleRealtimePayload(payload);
        } catch (e) { /* ignore */ }
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
                }).catch(function () {
                    audioReady = false;
                    /* autoplay مسدود بود — اگر این تلاش بعد از رفرش از راه دور بوده،
                       overlay فعال‌سازی رو برگردان تا با یک کلیک روی تلویزیون صدا برگردد */
                    if (autoResumedRemote) {
                        autoResumedRemote = false;
                        if (activateOverlay) activateOverlay.hidden = false;
                        if (activateBtn) activateBtn.addEventListener('click', initAudio);
                    }
                });
            }
        } catch (e) { /* ignore */ }
        audioReady = true;
        setAudioStatus('active');
        if (activateOverlay) activateOverlay.hidden = true;
        /* اطلاع‌رسانی فعال‌سازی صدا به سرور (برای پیش‌نمایش) */
        notifyAudioActivated();
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
        heroMessage.textContent = '\u0641\u0627\u06cc\u0644 \u0635\u0648\u062a\u06cc \u0634\u0645\u0627\u0631\u0647 ' + window.hastamaToFA(String(number)) + ' \u0645\u0648\u062c\u0648\u062f \u0646\u06cc\u0633\u062a.';
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

        // Pause slideshow during call
        if (typeof onCallInterrupt === 'function') onCallInterrupt();

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

    /* ── Remote refresh (command from management panel) ── */
    function handleRemoteRefresh() {
        /* قبل از رفرش فلگ می‌گذاریم تا بعد از لود مجدد، صدا بدون نیاز به
           کلیک روی تلویزیون دوباره فعال شود */
        try { sessionStorage.setItem('cd-auto-resume', '1'); } catch (e) { /* ignore */ }
        setTimeout(function () { window.location.reload(); }, 400);
    }

    /* ── Reset all displays ── */
    function resetDisplay() {
        heroData = null;
        for (var i = 0; i < MAX_PREV; i++) prevData[i] = null;
        renderHero();
        renderPrev();
        clearTimeout(callPauseTimer);
        resumeSlideshow();
    }

    /* ── Remove a call from display ── */
    function removeCall(number) {
        var num = String(number).replace(/[^\d]/g, '');
        // Check hero
        if (heroData && String(heroData.number).replace(/[^\d]/g, '') === num) {
            heroData = null;
            renderHero();
        }
        // Check previous slots
        for (var i = 0; i < MAX_PREV; i++) {
            if (prevData[i] && String(prevData[i].number).replace(/[^\d]/g, '') === num) {
                prevData[i] = null;
            }
        }
        // Compact: fill gaps
        var compacted = [];
        for (var j = 0; j < MAX_PREV; j++) {
            if (prevData[j]) compacted.push(prevData[j]);
        }
        for (var k = 0; k < MAX_PREV; k++) {
            prevData[k] = k < compacted.length ? compacted[k] : null;
        }
        renderPrev();
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

    /* ── WebSocket / Reverb ── */
    function connect() {
        var url = realtimeWsUrl();
        var isPreview = (window.location !== window.parent.location);

        try { ws = new WebSocket(url); } catch (e) { scheduleReconnect(); return; }

        setStatus('connecting');

        ws.onopen = function () {
            reconnectDelay = 1000;

            if (!isRealtimeReverb()) {
                setStatus('connected');
                try {
                    ws.send(JSON.stringify({ tag: isPreview ? 'preview' : 'display' }));
                } catch (e) { /* ignore */ }
                if (autoResumedRemote) notifyAudioActivated();
                ws._pingInterval = setInterval(function () {
                    if (ws && ws.readyState === WebSocket.OPEN) {
                        try { ws.send('ping'); } catch (e) { /* ignore */ }
                    }
                }, 25000);
                return;
            }

            ws._pingInterval = null;
        };

        ws.onmessage = function (evt) {
            handleRealtimeFrame(evt.data, isPreview);
        };

        ws.onclose = function () {
            clearInterval(ws && ws._pingInterval);
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

    /* ====================================================================
       SLIDESHOW
       ==================================================================== */
    var slideshowEl = document.getElementById('cdSlideshow');
    var slideImages = [];
    var currentSlideIndex = -1;
    var slideTimer = null;
    var callPauseTimer = null;
    var SLIDE_INTERVAL = 20000; // 20 seconds per slide
    var CALL_PAUSE_DURATION = 30000; // 30 seconds to show call

    function loadActiveSlides() {
        fetch('/api/calls/slides/active')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.slides || res.slides.length === 0) {
                if (slideshowEl) slideshowEl.classList.remove('is-active');
                return;
            }
            // Clear existing
            if (slideshowEl) {
                slideshowEl.innerHTML = '';
                slideImages = [];
            }
            res.slides.forEach(function (slide) {
                var img = document.createElement('img');
                img.className = 'cd-slideshow-img';
                img.src = slide.url;
                img.alt = '';
                img.loading = 'lazy';
                slideshowEl.appendChild(img);
                slideImages.push(img);
            });
            if (slideImages.length > 0) {
                slideshowEl.classList.add('is-active');
                currentSlideIndex = 0;
                showSlide(0);
                startSlideshow();
                hideChrome();
            }
        })
        .catch(function () {});
    }

    function showSlide(index) {
        slideImages.forEach(function (img, i) {
            if (i === index) {
                img.classList.add('is-visible');
            } else {
                img.classList.remove('is-visible');
            }
        });
    }

    function nextSlide() {
        if (slideImages.length === 0) return;
        currentSlideIndex = (currentSlideIndex + 1) % slideImages.length;
        showSlide(currentSlideIndex);
    }

    function startSlideshow() {
        stopSlideshow();
        slideTimer = setInterval(nextSlide, SLIDE_INTERVAL);
    }

    function stopSlideshow() {
        clearInterval(slideTimer);
        slideTimer = null;
    }

    function hideChrome() {
        var header = document.querySelector('.cd-topbar');
        var footer = document.querySelector('.cd-footer');
        var statusCard = document.getElementById('statusCard');
        var content = document.querySelector('.cd-content');
        if (header) header.style.opacity = '0';
        if (footer) footer.style.opacity = '0';
        if (statusCard) statusCard.style.opacity = '0';
        if (content) content.style.opacity = '0';
    }

    function showChrome() {
        var header = document.querySelector('.cd-topbar');
        var footer = document.querySelector('.cd-footer');
        var statusCard = document.getElementById('statusCard');
        var content = document.querySelector('.cd-content');
        if (header) header.style.opacity = '';
        if (footer) footer.style.opacity = '';
        if (statusCard) statusCard.style.opacity = '';
        if (content) content.style.opacity = '';
    }

    function pauseSlideshow() {
        if (slideshowEl) {
            slideshowEl.classList.add('is-paused');
            slideshowEl.style.opacity = '0';
        }
        stopSlideshow();
    }

    function resumeSlideshow() {
        if (slideshowEl) {
            slideshowEl.classList.remove('is-paused');
            slideshowEl.style.opacity = '';
        }
        if (slideImages.length > 0) startSlideshow();
        // Hide chrome again after call ends
        if (slideImages.length > 0) setTimeout(hideChrome, 1500);
    }

    function onCallInterrupt() {
        showChrome(); // Show UI during call
        pauseSlideshow();
        clearTimeout(callPauseTimer);
        callPauseTimer = setTimeout(resumeSlideshow, CALL_PAUSE_DURATION);
    }

    /* ── Init ── */
    /* --- Load display queue from server on init --- */
    function loadDisplayQueue() {
        fetch('/api/calls/display-queue')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.queue || res.queue.length === 0) return;
            // Queue is ordered by position: 0=hero, 1-4=previous
            heroData = res.queue[0] || null;
            for (var i = 1; i <= MAX_PREV; i++) {
                prevData[i - 1] = res.queue[i] || null;
            }
            renderHero();
            renderPrev();
        })
        .catch(function () {});
    }

    /* ── Queue tickets (نوبت‌دهی) ── */
    var cdQueueGrid = document.getElementById('cdQueueGrid');
    var cdQueueCount = document.getElementById('cdQueueCount');
    var cdQueueTickets = document.getElementById('cdQueueTickets');

    function loadQueueTickets() {
        fetch('/api/queue/list?status=waiting')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var tickets = data.tickets || [];
            if (!tickets.length) {
                if (cdQueueGrid) cdQueueGrid.innerHTML = '<div class="cd-queue-empty">هنوز نوبتی صادر نشده است</div>';
                if (cdQueueCount) cdQueueCount.textContent = '0 نفر در صف';
                return;
            }
            if (cdQueueCount) cdQueueCount.textContent = tickets.length + ' نفر در صف';
            if (!cdQueueGrid) return;
            cdQueueGrid.innerHTML = '';
            tickets.forEach(function (t) {
                var div = document.createElement('div');
                div.className = 'cd-queue-item';
                // همان کلیدهایی که /api/queue/list برمی‌گرداند (number وجود ندارد)
                div.innerHTML = '<span class="cd-queue-item-num">' + (t.persian_number || t.ticket_number || '') + '</span>' +
                    '<span class="cd-queue-item-service">' + (t.service || '') + '</span>';
                cdQueueGrid.appendChild(div);
            });
        })
        .catch(function () {});
    }

    function init() {
        var isPreview = (window.location !== window.parent.location);
        var autoResume = false;
        try {
            autoResume = sessionStorage.getItem('cd-auto-resume') === '1';
            sessionStorage.removeItem('cd-auto-resume');
        } catch (e) { /* ignore */ }

        if (autoResume && !isPreview) {
            /* رفرش از راه دور: بدون کلیک روی تلویزیون، صدا رو خودکار فعال کن.
               (در مرورگر کیوسک بدون محدودیت autoplay صدا فعال می‌شود؛ در غیر
               این صورت overlay فعال‌سازی نمایش داده می‌شود) */
            autoResumedRemote = true;
            if (activateOverlay) activateOverlay.hidden = true;
            initAudio();
        } else if (isPreview) {
            /* در حالت پیش‌نمایش، overlay رو باز نگه‌دار تا وقتی تلویزیون صدا رو فعال کرد */
            if (activateOverlay) activateOverlay.hidden = false;
        } else if (activateOverlay) {
            activateOverlay.hidden = false;
            if (activateBtn) activateBtn.addEventListener('click', initAudio);
        } else {
            initAudio();
        }

        loadDisplayQueue();
        loadQueueTickets();
        loadActiveSlides();
        connect();
        setInterval(loadQueueTickets, 5000);

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

    /* ── Back-Forward Cache: reconnect WebSocket on pageshow ── */
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            /* Page restored from bfcache — force reconnect */
            if (typeof ws !== 'undefined' && ws) {
                try { ws.close(); } catch (err) { /* ignore */ }
                ws = null;
            }
            connect();
        }
    });
})();
