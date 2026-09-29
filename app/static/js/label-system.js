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

   مارک‌آپ لیبل و سند چاپ فقط روی سرور ساخته می‌شوند تا خروجی استودیو و
   کیوسک دقیقاً یکی باشد.
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
  /* سقف بزرگ‌نمایی پیش‌نمایش استودیو نسبت به اندازهٔ واقعی لیبل: لیبل روی
     نمایشگر تقریباً به اندازهٔ فیزیکی‌اش دیده می‌شود و برای خوانده‌شدن متن
     تا این نسبت بزرگ می‌شود. سند چاپ و کیوسک از این مقدار استفاده نمی‌کنند؛
     مقیاس چاپ همان ZOOM_MAX است. */
  var PREVIEW_MAX_SCALE = 1.5;
  /* حاشیهٔ تنفس پیش‌نمایش داخل قاب (px، از هر طرف) */
  var PREVIEW_GUTTER = 12;
  var LAYOUT_VERSION = 6;           // با هر تغییر مهم در چیدمان لیبل بالا می‌رود

  var TEMPLATE_IDS = ['queue', 'compact', 'result', 'sampling', 'blank'];
  /* بازهٔ مجاز اندازهٔ لیبل (میلی‌متر). همین اعداد در سه لایه تکرار می‌شوند و
     باید یکی بمانند: ورودی‌های استودیو در master-admin.html، این‌جا، و
     ticket_print.LABEL_*_MM_RANGE در سرور. اگر محدودیتی این‌جا باشد ولی در
     input نباشد، کاربر عدد بزرگ‌تر می‌زند و «اعمال نمی‌شود». */
  var WIDTH_RANGE = [30, 150];
  var HEIGHT_RANGE = [20, 150];
  /* قالب‌های حداقلی (جوابدهی/نمونه‌گیری/نوبت آزاد) خودشان یعنی «سرویس»؛
     پس با تغییر قالب، متن روی چیپ سرویس لیبل هم باید عوض شود وگرنه
     پیش‌نمایش «پذیرش» می‌ماند. همین جفت‌ها در سرور هم هستند
     (label_render.TEMPLATE_SERVICE_LABELS) و باید یکی بمانند. */
  var TEMPLATE_SERVICE_LABELS = {
    result: 'جوابدهی',
    sampling: 'نمونه‌گیری',
    blank: 'نوبت آزاد',
  };
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

  /* تاریخ/ساعت لیبل: دقیقاً همان قالبی که سرور می‌سازد
     (label_render.format_label_datetime → «۱۴۰۵/۰۷/۰۵ - ۰۹:۴۱»). ماه و روز
     دورقمي گرفته می‌شوند تا صفرِ ابتدایی مثل چاپ حفظ شود، و یک‌بار دیگر
     ارقام فارسی می‌شوند (بعضی سیستم‌ها با fa-IR هم رقم لاتین می‌دهند). */
  function labelDateTimeText(now) {
    now = now || new Date();
    var date, time;
    try {
      date = new Intl.DateTimeFormat('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
      time = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
    } catch (_) {
      date = now.toLocaleDateString('fa-IR');
      time = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    }
    return faDigits(date + ' - ' + time);
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
  /* همان کلیدهایی که نرمال‌کنندهٔ سمت سرور می‌پذیرد
     (شکل قدیمی localStorage هم پشتیبانی می‌شود). */
  function normalizeSettings(raw) {
    raw = raw || {};
    var template = String(pickNumber(raw, ['template', 'maLabelTemplate'], 'queue')).toLowerCase();
    if (TEMPLATE_IDS.indexOf(template) < 0) template = 'queue';
    return {
      width_mm: clampMm(pickNumber(raw, ['width_mm', 'maLabelWidth'], DEFAULT_SETTINGS.width_mm), WIDTH_RANGE[0], WIDTH_RANGE[1], DEFAULT_SETTINGS.width_mm),
      height_mm: clampMm(pickNumber(raw, ['height_mm', 'maLabelHeight'], DEFAULT_SETTINGS.height_mm), HEIGHT_RANGE[0], HEIGHT_RANGE[1], DEFAULT_SETTINGS.height_mm),
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
      // ارقام لاتین روی لیبل فارسی نمی‌آیند؛ سرور هم همین کار را می‌کند
      admission: faDigits(admission),
      name: String(patient.name || '—'),
      age: faDigits(patient.age || '') || '—',
      national_id: faDigits(patient.national_id || '') || '—',
      phone: faDigits(patient.phone || '') || '—',
      insurance_track: faDigits(track) || '—',
      insurance_base: String(patient.insurance_base || '—'),
      insurance_extra: String(patient.insurance_extra || '—'),
      datetime: labelDateTimeText(),
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

  /* متن چیپ سرویس: اول قالب انتخاب‌شدهٔ استودیو، بعد سرویس خودِ نوبت. */
  function serviceLabel(data, settings) {
    var tpl = String((settings && settings.template) || '').toLowerCase();
    var preset = TEMPLATE_SERVICE_LABELS[tpl];
    if (preset) return preset;
    return (data && data.service) || 'پذیرش';
  }

  function applyLabelData(labelEl, data, settings) {
    setField(labelEl, 'service', serviceLabel(data, settings));
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
  }

  /* ── مقیاس طرح: همین تابع در سند چاپ هم اجرا می‌شود (fitDocument) ──
     options.zoomMax مقیاس را برای پیش‌نمایش بزرگ‌تر از چاپ باز می‌کند؛ سند
     چاپ بدون options صدا زده می‌شود و همان ZOOM_MAX را دارد. */
  function fitLabel(labelEl, options) {
    if (!labelEl) return 1;
    options = options || {};
    var zoomMin = options.zoomMin === undefined ? ZOOM_MIN : options.zoomMin;
    var zoomMax = options.zoomMax === undefined ? ZOOM_MAX : options.zoomMax;
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
    zoom = clamp(zoom, zoomMin, zoomMax);
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
    var refit = function () { fitLabel(labelEl); };
    refit();
    // فونت و لوگو ممکن است دیرتر از اندازه‌گیری اول بیایند و با آن اندازهٔ
    // نادرست، مقیاس طرح بزرگ‌تر از لیبل شود و پایین لیبل بریده شود. پس چند
    // بار دیگر هم مقیاس حساب می‌شود (چاپ بی‌صدا هم بعد از بارگذاری کامل
    // اسکرین‌شات می‌گیرد).
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(refit);
    if (document.readyState === 'complete') refit();
    else global.addEventListener('load', refit);
    global.addEventListener('resize', refit);
    setTimeout(refit, 120);
    setTimeout(refit, 700);
  }

  /* ── پیش‌نمایش زنده (استودیو) ──────────────────────────────────────────── */
  function mountPreview(hostEl, options) {
    if (!hostEl) return null;
    options = options || {};
    var state = {
      data: options.data || labelData(SAMPLE.ticket, SAMPLE.patient),
      settings: normalizeSettings(options.settings || readCache() || DEFAULT_SETTINGS),
      scale: 1,
    };
    var labelEl = null;
    var observer = null;
    var maxScale = options.maxScale === undefined ? PREVIEW_MAX_SCALE : options.maxScale;
    /* محتوا هم مثل قاب بزرگ می‌شود، پس سقف zoom متناسب با همان مقیاس بالا
       می‌رود (fitLabel خودش min() عرض/ارتفاع می‌گیرد و سرریز نمی‌کند). */
    var fitOptions = { zoomMin: ZOOM_MIN, zoomMax: ZOOM_MAX * maxScale };

    function sizeToHost() {
      if (!labelEl) return;
      var width = state.settings.width_mm;
      var height = state.settings.height_mm;
      // قاب واقعی در دسترس (hostEl با CSS اندازهٔ کامل استیج را دارد)
      var roomW = Math.max(180, hostEl.clientWidth - PREVIEW_GUTTER * 2);
      var roomH = Math.max(150, hostEl.clientHeight - PREVIEW_GUTTER * 2);
      // سقف: ۱.۵ برابر اندازهٔ واقعی روی نمایشگر ۹۶dpi — اگر فضا کم بود،
      // همان‌قدر که جا می‌شود (هیچ‌وقت بزرگ‌تر از سقف).
      var pxPerMm = Math.min(PX_PER_MM * maxScale, roomW / width, roomH / height);
      var boxW = Math.max(120, Math.round(width * pxPerMm));
      var boxH = Math.max(80, Math.round(height * pxPerMm));
      labelEl.style.setProperty('width', boxW + 'px', 'important');
      labelEl.style.setProperty('height', boxH + 'px', 'important');
      labelEl.style.setProperty('aspect-ratio', width + ' / ' + height);
      fitLabel(labelEl, fitOptions);
      // مقیاس واقعی صفحه (px بر میلی‌متر ÷ ۹۶dpi) برای نمایش در استودیو
      state.scale = (boxW / width) / PX_PER_MM;
      if (typeof options.onScale === 'function') options.onScale(state.scale);
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
      scale: function () { return state.scale; },
      fit: function () { fitLabel(labelEl, fitOptions); },
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

  /* چاپ نمونهٔ استودیو = همان دادهٔ نمونهٔ پیش‌نمایش؛ قالب انتخاب‌شده تعیین
     می‌کند چیپ سرویس چه بنویسد، تا برگهٔ چاپ‌شده با پیش‌نمایش یکی باشد. */
  function printSample(currentSettings) {
    var settings = normalizeSettings(currentSettings || readCache() || DEFAULT_SETTINGS);
    var ticket = {};
    for (var key in SAMPLE.ticket) {
      if (Object.prototype.hasOwnProperty.call(SAMPLE.ticket, key)) ticket[key] = SAMPLE.ticket[key];
    }
    ticket.service = serviceLabel(SAMPLE.ticket, settings);
    return print(ticket, SAMPLE.patient);
  }

  global.HastamaLabel = {
    REF_WIDTH_MM: REF_WIDTH_MM,
    ZOOM_MIN: ZOOM_MIN,
    ZOOM_MAX: ZOOM_MAX,
    LAYOUT_VERSION: LAYOUT_VERSION,
    DEFAULT_SETTINGS: DEFAULT_SETTINGS,
    TEMPLATE_SERVICE_LABELS: TEMPLATE_SERVICE_LABELS,
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
    serviceLabel: serviceLabel,
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
