/* ═══════════════════════════════════════════════════════════════════════════
   HASTAMA — سیستم واحد لیبل نوبت (single source of truth, client side)
   ───────────────────────────────────────────────────────────────────────────
   این فایل تنها موتور لیبل است و هم استودیو لیبل (master-admin →
   label-printer) و هم کیوسک (ticket-kiosk) از آن استفاده می‌کنند:

     * خواندن/ذخیرهٔ تنظیمات  → system_config.label_print_settings (سرور)
     * ساخت پیش‌نمایش لیبل از قالب سرور (`#hastamaLabelTemplate`)
     * مقیاس و جاگیری طرح (`--lbl-zoom`) — همان فرمولی که سند چاپ اجرا می‌کند
     * چاپ: اول چاپ بی‌صدای سرور (`POST /api/queue/print`)، در صورت خطا همان
       سند چاپ سرور (`POST /api/label/print-document`) در مرورگر چاپ می‌شود

   مارک‌آپ لیبل و سند چاپ فقط روی سرور ساخته می‌شوند
   (app/templates/partials/label_queue.html و app/templates/label_print_document.html)
   تا خروجی استودیو و کیوسک دقیقاً یکی باشد.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (global) {
  'use strict';

  /* ── ثابت‌های طراحی ───────────────────────────────────────────────────── */
  var REF_WIDTH_MM = 50;            // طرح برای این عرض نوشته شده (label-print.css)
  var PX_PER_MM = 3.7795;           // ۹۶ نقطه بر اینچ
  var REF_WIDTH_PX = REF_WIDTH_MM * PX_PER_MM;   // ۱۸۸٫۹۸
  var ZOOM_MIN = 0.8;
  var ZOOM_MAX = 1.8;
  var FIT_SAFETY = 0.97;            // کمی جا برای خطای گردکردن چاپ
  var MAX_PX_PER_MM = 4;            // سقف بزرگ‌نمایی پیش‌نمایش روی صفحه
  var LAYOUT_VERSION = 6;           // با هر تغییر مهم در چیدمان لیبل بالا می‌رود

  var TEMPLATE_IDS = ['queue', 'compact', 'result', 'sampling', 'blank'];
  var DEFAULT_SETTINGS = {
    width_mm: 75,
    height_mm: 81,
    template: 'queue',
    rotate: false,
    show_name: true,
    show_time: true,
    show_hint: true,
    layout_version: LAYOUT_VERSION,
  };
  var STORAGE_KEY = 'hastama-label-settings';
  var TARGET_STORAGE_KEY = 'hastama-label-target-printer';
  var LABEL_TEMPLATE_ID = 'hastamaLabelTemplate';

  /* نمونهٔ ثابت استودیو: پیش‌نمایش و «چاپ نمونه» از همین یک داده استفاده
     می‌کنند تا چیزی که می‌بیند با چیزی که چاپ می‌شود یکی باشد. */
  var SAMPLE = {
    ticket: { service: 'پذیرش', number: 12, persian_number: '۱۲' },
    patient: {
      admission_number: '12345',
      name: 'تقی',
      age: '26',
      national_id: '0890519684',
      phone: '09332905840',
      insurance_tracking: '12345',
      insurance_base: 'تأمین اجتماعی',
      insurance_extra: 'آسیا',
    },
  };

  /* ── کمکی‌ها ──────────────────────────────────────────────────────────── */
  function clamp(value, low, high) {
    return Math.min(high, Math.max(low, value));
  }

  function clampMm(value, low, high, fallback) {
    var n = Number(value);
    if (!isFinite(n)) return fallback;
    return Math.round(clamp(n, low, high));
  }

  function asBool(value, fallback) {
    if (value === undefined || value === null || value === '') return fallback;
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    var text = String(value).trim().toLowerCase();
    if (text === 'false' || text === '0' || text === 'no' || text === 'off') return false;
    return true;
  }

  function faDigits(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  }

  function pickNumber(raw, keys, fallback) {
    for (var i = 0; i < keys.length; i += 1) {
      if (raw && raw[keys[i]] !== undefined && raw[keys[i]] !== null && raw[keys[i]] !== '') {
        return raw[keys[i]];
      }
    }
    return fallback;
  }

  function pickBool(raw, keys, fallback) {
    for (var i = 0; i < keys.length; i += 1) {
      if (raw && raw[keys[i]] !== undefined && raw[keys[i]] !== null && raw[keys[i]] !== '') {
        return asBool(raw[keys[i]], fallback);
      }
    }
    return fallback;
  }

  /* ── تنظیمات: شکل واحد ────────────────────────────────────────────────── */
  /* همان کلیدهایی که app/services/ticket_print.py:normalize_label_settings
     می‌پذیرد (شکل قدیمی localStorage هم پشتیبانی می‌شود). */
  function normalizeSettings(raw) {
    raw = raw || {};
    var template = String(pickNumber(raw, ['template', 'maLabelTemplate'], 'queue')).toLowerCase();
    if (TEMPLATE_IDS.indexOf(template) < 0) template = 'queue';
    return {
      width_mm: clampMm(pickNumber(raw, ['width_mm', 'maLabelWidth'], DEFAULT_SETTINGS.width_mm), 30, 150, DEFAULT_SETTINGS.width_mm),
      height_mm: clampMm(pickNumber(raw, ['height_mm', 'maLabelHeight'], DEFAULT_SETTINGS.height_mm), 20, 100, DEFAULT_SETTINGS.height_mm),
      template: template,
      rotate: pickBool(raw, ['rotate', 'maPrintRotate'], false),
      show_name: pickBool(raw, ['show_name', 'maShowName'], true),
      show_time: pickBool(raw, ['show_time', 'maShowTime'], true),
      show_hint: pickBool(raw, ['show_hint', 'maShowHint'], true),
      layout_version: LAYOUT_VERSION,
    };
  }

  function readCache() {
    try {
      var raw = JSON.parse(global.localStorage.getItem(STORAGE_KEY) || 'null');
      if (!raw || typeof raw !== 'object') return null;
      return normalizeSettings(raw);
    } catch (_) { return null; }
  }

  function writeCache(settings) {
    try { global.localStorage.setItem(STORAGE_KEY, JSON.stringify(settings)); } catch (_) {}
  }

  function readTargetPrinter() {
    try { return global.localStorage.getItem(TARGET_STORAGE_KEY) || ''; } catch (_) { return ''; }
  }

  function writeTargetPrinter(name) {
    try { global.localStorage.setItem(TARGET_STORAGE_KEY, String(name || '')); } catch (_) {}
  }

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {}),
    }).then(function (resp) {
      return resp.json().catch(function () { return null; }).then(function (data) {
        if (!resp.ok) throw new Error((data && (data.detail || data.error)) || ('HTTP ' + resp.status));
        return data;
      });
    });
  }

  /* ── سرور: تنها منبع تنظیمات ──────────────────────────────────────────── */
  function loadConfig() {
    return fetch('/api/label/config', { credentials: 'same-origin' })
      .then(function (resp) { return resp.ok ? resp.json() : null; })
      .then(function (data) {
        if (!data) return null;
        var settings = normalizeSettings(data.settings);
        writeCache(settings);
        return {
          settings: settings,
          printer: String(data.printer || ''),
          printers: data.printers || null,
          printer_source: data.printer_source || '',
        };
      })
      .catch(function () { return null; });
  }

  /* تنها نویسندهٔ تنظیمات: استودیو (master-admin). کیوسک فقط می‌خواند. */
  function saveSettings(settings) {
    var canonical = normalizeSettings(settings);
    writeCache(canonical);
    return postJSON('/master-admin/api/config', {
      key: 'label_print_settings',
      value: JSON.stringify(canonical),
    }).then(function () { return canonical; });
  }

  function saveTargetPrinter(name) {
    writeTargetPrinter(name);
    return postJSON('/master-admin/api/config', {
      key: 'label_target_printer',
      value: String(name || ''),
    });
  }

  /* ── دادهٔ لیبل: همان شکل label_context در سرور ───────────────────────── */
  function labelData(ticket, patient) {
    ticket = ticket || {};
    patient = patient || {};
    var admission = patient.admission_number_persian || patient.admission_number || '';
    var track = patient.insurance_tracking || patient.tracking_code || patient.insurance_track || '';
    var number = ticket.persian_number || faDigits(ticket.number || '') || '';
    return {
      service: String(ticket.service || 'پذیرش'),
      number: String(number),
      admission: String(admission || ''),
      name: String(patient.name || '—'),
      age: faDigits(patient.age || '') || '—',
      national_id: faDigits(patient.national_id || '') || '—',
      phone: faDigits(patient.phone || '') || '—',
      insurance_track: faDigits(track) || '—',
      insurance_base: String(patient.insurance_base || '—'),
      insurance_extra: String(patient.insurance_extra || '—'),
      datetime: new Date().toLocaleDateString('fa-IR') + ' - '
        + new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }),
    };
  }

  /* ── مارک‌آپ مشترک: قالب سرور، فقط پر کردن فیلدها ─────────────────────── */
  function templateMarkup() {
    var tpl = document.getElementById(LABEL_TEMPLATE_ID);
    return tpl ? tpl.innerHTML : '';
  }

  function createLabelElement() {
    var markup = templateMarkup();
    if (!markup) return null;
    var host = document.createElement('div');
    host.innerHTML = markup;
    return host.firstElementChild;
  }

  function setField(root, name, text) {
    var el = root.querySelector('[data-field="' + name + '"]');
    if (el) el.textContent = text === undefined || text === null ? '' : String(text);
  }

  function showField(root, name, visible) {
    var el = root.querySelector('[data-field="' + name + '"]');
    if (el) el.hidden = !visible;
  }

  function applyLabelData(labelEl, data, settings) {
    setField(labelEl, 'service', data.service || 'پذیرش');
    setField(labelEl, 'number', data.number || '');
    setField(labelEl, 'datetime', data.datetime || '');
    setField(labelEl, 'admission-value', data.admission || '');
    setField(labelEl, 'name-value', data.name || '—');
    setField(labelEl, 'age-value', data.age || '—');
    setField(labelEl, 'national-value', data.national_id || '—');
    setField(labelEl, 'phone-value', data.phone || '—');
    setField(labelEl, 'insurance-track', data.insurance_track || '—');
    setField(labelEl, 'insurance-base', data.insurance_base || '—');
    setField(labelEl, 'insurance-extra', data.insurance_extra || '—');
    showField(labelEl, 'admission', !!data.admission);
    showField(labelEl, 'name', settings.show_name !== false);
    showField(labelEl, 'time', settings.show_time !== false);
    showField(labelEl, 'hint', settings.show_hint !== false);
    labelEl.setAttribute('data-template', settings.template || 'queue');
    var serviceEl = labelEl.querySelector('[data-field="service"]');
    if (serviceEl && !data.service) serviceEl.textContent = 'پذیرش';
  }

  /* ── مقیاس طرح: همین تابع در سند چاپ هم اجرا می‌شود (fitDocument) ── */
  function fitLabel(labelEl) {
    if (!labelEl) return 1;
    var content = labelEl.querySelector('.lbl__content');
    if (!content) return 1;
    labelEl.style.setProperty('--lbl-zoom', '1');
    var previous = { height: content.style.height, flex: content.style.flex, transform: content.style.transform };
    content.style.transform = 'none';
    content.style.height = 'auto';
    content.style.flex = 'none';
    var naturalHeight = content.scrollHeight;
    content.style.height = previous.height;
    content.style.flex = previous.flex;
    content.style.transform = previous.transform;

    var zoom = Math.min(
      labelEl.clientWidth / REF_WIDTH_PX,
      labelEl.clientHeight / Math.max(1, naturalHeight)
    ) * FIT_SAFETY;
    zoom = clamp(zoom, ZOOM_MIN, ZOOM_MAX);
    labelEl.style.setProperty('--lbl-zoom', zoom.toFixed(3));

    // اگر لیبل باریک است، رقمِ شمارهٔ نوبت نباید بریده شود
    var numberEl = labelEl.querySelector('.lbl__queue-number');
    var boxEl = labelEl.querySelector('.lbl__number-box');
    if (numberEl && boxEl) {
      numberEl.style.fontSize = '';
      var base = parseFloat(global.getComputedStyle(numberEl).fontSize) || 20;
      var available = boxEl.clientWidth - 24;
      if (numberEl.scrollWidth > available) {
        numberEl.style.fontSize = Math.max(10, base * available / numberEl.scrollWidth) + 'px';
      }
    }
    return zoom;
  }

  /* در سند چاپ (پنجرهٔ مرورگر / Edge headless) صدا زده می‌شود */
  function fitDocument() {
    var labelEl = document.querySelector('.lbl');
    if (!labelEl) return;
    fitLabel(labelEl);
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () { fitLabel(labelEl); });
    }
  }

  /* ── پیش‌نمایش زنده (استودیو) ──────────────────────────────────────────── */
  function mountPreview(hostEl, options) {
    if (!hostEl) return null;
    options = options || {};
    var state = {
      data: options.data || labelData(SAMPLE.ticket, SAMPLE.patient),
      settings: normalizeSettings(options.settings || readCache() || DEFAULT_SETTINGS),
    };
    var labelEl = null;
    var observer = null;

    function sizeToHost() {
      if (!labelEl) return;
      var width = state.settings.width_mm;
      var height = state.settings.height_mm;
      var roomW = Math.max(180, hostEl.clientWidth - 40);
      var roomH = Math.max(150, hostEl.clientHeight - 40);
      var pxPerMm = Math.min(MAX_PX_PER_MM, roomW / width, roomH / height);
      labelEl.style.setProperty('width', Math.max(120, Math.round(width * pxPerMm)) + 'px', 'important');
      labelEl.style.setProperty('height', Math.max(80, Math.round(height * pxPerMm)) + 'px', 'important');
      labelEl.style.setProperty('aspect-ratio', width + ' / ' + height);
      fitLabel(labelEl);
    }

    function render() {
      var fresh = createLabelElement();
      if (!fresh) return;
      applyLabelData(fresh, state.data, state.settings);
      labelEl = fresh;
      hostEl.replaceChildren(fresh);
      sizeToHost();
    }

    render();
    if (global.ResizeObserver) {
      observer = new ResizeObserver(function () { sizeToHost(); });
      observer.observe(hostEl);
    }
    return {
      element: function () { return labelEl; },
      settings: function () { return state.settings; },
      data: function () { return state.data; },
      setSettings: function (next) {
        state.settings = normalizeSettings(next);
        render();
        return state.settings;
      },
      setData: function (next) {
        state.data = next || state.data;
        render();
      },
      refresh: render,
      fit: function () { fitLabel(labelEl); },
      destroy: function () { if (observer) observer.disconnect(); },
    };
  }

  /* ── چاپ: یک مسیر برای همه ────────────────────────────────────────────── */
  function fetchPrintDocument(ticket, patient) {
    return postJSON('/api/label/print-document', {
      ticket: ticket || {},
      patient: patient || {},
    }).then(function (resp) {
      return resp && resp.html ? resp.html : '';
    });
  }

  function writeAndPrint(html) {
    var win = global.open('', '_blank', 'width=560,height=700');
    if (!win || !win.document) {
      // پاپ‌آپ مسدود شد: چاپ از iframe مخفی
      var frame = document.createElement('iframe');
      frame.setAttribute('aria-hidden', 'true');
      frame.style.cssText = 'position:fixed;top:0;left:-10000px;width:700px;height:900px;border:0;';
      document.body.appendChild(frame);
      var frameDoc = frame.contentWindow.document;
      frameDoc.open(); frameDoc.write(html); frameDoc.close();
      setTimeout(function () {
        try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (_) {}
        setTimeout(function () { frame.remove(); }, 60000);
      }, 500);
      return true;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
    var done = false;
    var doPrint = function () {
      if (done) return;
      done = true;
      try { if (win.HastamaLabel) win.HastamaLabel.fitDocument(); } catch (_) {}
      try { win.focus(); win.print(); } catch (_) {}
    };
    setTimeout(doPrint, 600);
    setTimeout(doPrint, 2500);
    return true;
  }

  function printViaBrowser(ticket, patient) {
    return fetchPrintDocument(ticket, patient).then(function (html) {
      if (!html) return { ok: false, via: 'browser', message: 'سند چاپ ساخته نشد.' };
      writeAndPrint(html);
      return { ok: true, via: 'browser' };
    }).catch(function (err) {
      return { ok: false, via: 'browser', message: String(err && err.message || err) };
    });
  }

  /* چاپ با یک رفتار برای استودیو و کیوسک:
     ۱) چاپ بی‌صدای سرور با همان چاپگر و همان تنظیماتِ ذخیره‌شده
     ۲) اگر سرور نتوانست (یا چاپگر انتخاب نشده بود) همان سند چاپ در مرورگر */
  function print(ticket, patient) {
    return postJSON('/api/queue/print', { ticket: ticket || {}, patient: patient || {} })
      .then(function (data) {
        if (data && data.success) {
          return {
            ok: true,
            via: 'server',
            printer: data.printer || '',
            method: data.method || '',
            settings: data.settings ? normalizeSettings(data.settings) : null,
          };
        }
        var reason = (data && data.message) || '';
        return printViaBrowser(ticket, patient).then(function (result) {
          return { ok: result.ok, via: result.via, message: reason || result.message || '', fallback: true, settings: data && data.settings ? normalizeSettings(data.settings) : null };
        });
      })
      .catch(function () {
        return printViaBrowser(ticket, patient);
      });
  }

  /* چاپ نمونهٔ استودیو = همان دادهٔ نمونهٔ پیش‌نمایش */
  function printSample() {
    return print(SAMPLE.ticket, SAMPLE.patient);
  }

  global.HastamaLabel = {
    REF_WIDTH_MM: REF_WIDTH_MM,
    ZOOM_MIN: ZOOM_MIN,
    ZOOM_MAX: ZOOM_MAX,
    LAYOUT_VERSION: LAYOUT_VERSION,
    DEFAULT_SETTINGS: DEFAULT_SETTINGS,
    SAMPLE: SAMPLE,
    normalizeSettings: normalizeSettings,
    readCache: readCache,
    writeCache: writeCache,
    readTargetPrinter: readTargetPrinter,
    writeTargetPrinter: writeTargetPrinter,
    loadConfig: loadConfig,
    saveSettings: saveSettings,
    saveTargetPrinter: saveTargetPrinter,
    labelData: labelData,
    applyLabelData: applyLabelData,
    createLabelElement: createLabelElement,
    fitLabel: fitLabel,
    fitDocument: fitDocument,
    mountPreview: mountPreview,
    fetchPrintDocument: fetchPrintDocument,
    printViaBrowser: printViaBrowser,
    print: print,
    printSample: printSample,
  };
})(window);
