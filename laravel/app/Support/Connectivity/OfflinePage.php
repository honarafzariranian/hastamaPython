<?php

namespace App\Support\Connectivity;

use App\Support\Legacy\LegacyHtml;

/**
 * The outage guide document — ``outage.page_context()`` and `app/templates/offline.html`
 * from the Python application, ported as one unit because they are one contract.
 *
 * The page is served with ``200`` on purpose: the service worker caches it (the
 * ``cache`` APIs reject error responses) and shows it when the browser itself
 * cannot reach the server.  Blocked page requests are answered with the same
 * template plus ``503`` by the outage gate, which is a different route.
 *
 * The template is kept as a nowdoc with ``{{placeholder}}`` markers that mirror
 * the Jinja expressions one-for-one, so the port can be diffed against
 * `app/templates/offline.html` line by line.  The two conditional sections
 * (``{% if lan_url %}`` and ``{% if lan_enabled %}``) are rendered by PHP first
 * and substituted as whole blocks, exactly as the Jinja did.
 *
 * The context is built from {@see OutageService} (the outage settings) and
 * {@see LanAccessService} (the laboratory address), matching
 * ``outage.page_context()`` key for key:
 *
 * .. code-block:: python
 *
 *     lan = lan_access.status()
 *     address = lan.get("expected_url") or ""
 *     return {
 *         "outage_title": _state.title or DEFAULT_TITLE,
 *         "outage_message": _state.message or DEFAULT_MESSAGE,
 *         "outage_active": bool(_state.active),
 *         "outage_manual": bool(_state.manual),
 *         "lan_url": address if _state.show_lan_address else "",
 *         "lan_enabled": bool(lan.get("running")),
 *         "retry_seconds": max(5, min(_state.interval, 60)),
 *     }
 */
final class OfflinePage
{
    /**
     * The template, verbatim from `app/templates/offline.html`.
     *
     * Placeholders: ``{{outage_title}}`` (twice — ``<title>`` and ``<h1>``),
     * ``{{outage_message}}``, ``{{outage_manual_badge}}``, ``{{retry_seconds}}``,
     * ``{{lan_block}}`` and ``{{copy_button}}``.
     */
    private const TEMPLATE = <<<'OFFLINE_HTML'
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{outage_title}}</title>
<style>
  :root {
    --ink: #0f172a; --muted: #64748b; --line: #e2e8f0;
    --accent: #6366f1; --warn: #f59e0b; --ok: #10b981;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    margin: 0; padding: 24px;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(1200px 600px at 80% -10%, #e0e7ff 0%, #f8fafc 45%, #f1f5f9 100%);
    color: var(--ink);
    font-family: Vazirmatn, "Segoe UI", Tahoma, sans-serif;
    line-height: 1.9;
  }
  .card {
    width: 100%; max-width: 620px;
    background: #fff; border: 1px solid var(--line); border-radius: 20px;
    padding: 32px 28px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .12);
    text-align: center;
  }
  .badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 14px; border-radius: 999px;
    background: rgba(245, 158, 11, .14); color: #92400e;
    font-size: .78rem; font-weight: 700;
  }
  .badge i { width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
  .icon { font-size: 3rem; line-height: 1; margin: 14px 0 4px; }
  h1 { font-size: 1.25rem; margin: 6px 0 10px; }
  p { margin: 0 0 14px; color: var(--muted); font-size: .92rem; }
  .reason {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: .82rem; color: var(--muted);
    background: #f8fafc; border: 1px solid var(--line);
    border-radius: 10px; padding: 6px 12px; margin-bottom: 18px;
  }
  .reason b { color: var(--ink); font-weight: 600; }
  .lan {
    margin: 6px 0 18px; padding: 16px;
    border: 1px dashed rgba(99, 102, 241, .45);
    background: rgba(99, 102, 241, .06);
    border-radius: 14px;
  }
  .lan__title { font-size: .85rem; font-weight: 700; color: #4338ca; margin-bottom: 8px; }
  .lan__url {
    direction: ltr; unicode-bidi: embed;
    font-family: ui-monospace, Consolas, monospace;
    font-size: 1.05rem; font-weight: 700; color: var(--ink);
    background: #fff; border: 1px solid var(--line);
    border-radius: 10px; padding: 10px 12px; display: inline-block;
  }
  .lan__off { font-size: .82rem; color: var(--muted); }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
  button {
    font-family: inherit; font-size: .9rem; font-weight: 600;
    padding: 10px 18px; border-radius: 12px; cursor: pointer;
    border: 1px solid var(--line); background: #fff; color: #334155;
  }
  button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  button:disabled { opacity: .55; cursor: default; }
  .foot { margin-top: 18px; font-size: .76rem; color: #94a3b8; }
  .status { margin-top: 10px; font-size: .8rem; color: var(--muted); min-height: 20px; }
  @media (max-width: 480px) {
    body { padding: 12px; }
    .card { padding: 22px 16px; border-radius: 16px; }
    .lan__url { font-size: .95rem; }
  }
</style>
</head>
<body>
  <div class="card" id="offlineCard" data-retry="{{retry_seconds}}">
    <span class="badge"><i></i>{{outage_manual_badge}}</span>
    <div class="icon">📡</div>
    <h1>{{outage_title}}</h1>
    <p>{{outage_message}}</p>

    <div class="reason">
      <span>علت احتمالی:</span>
      <b id="offlineReason">در حال بررسی…</b>
    </div>

    {{lan_block}}

    <div class="actions">
      <button type="button" class="primary" id="offlineRetry">تلاش مجدد</button>
      {{copy_button}}
    </div>

    <div class="status" id="offlineStatus">اتصال به‌صورت خودکار بررسی می‌شود…</div>
    <div class="foot">این صفحه بدون نیاز به اینترنت نمایش داده می‌شود و پس از برقراری ارتباط به‌صورت خودکار بسته می‌شود.</div>
  </div>

  <script>
  (function () {
    var card = document.getElementById('offlineCard');
    if (!card) return;
    var retry = parseInt(card.getAttribute('data-retry'), 10);
    if (!(retry > 0)) retry = 15;

    var reasonEl = document.getElementById('offlineReason');
    var statusEl = document.getElementById('offlineStatus');
    var retryBtn = document.getElementById('offlineRetry');
    var copyBtn = document.getElementById('offlineCopy');
    var lanEl = document.getElementById('offlineLanUrl');

    function onOffline() { reasonEl.textContent = 'اتصال اینترنت این رایانه قطع شده است'; }
    function onOnline() { reasonEl.textContent = 'سامانه به اینترنت دسترسی ندارد (وضعیت اضطراری)'; }

    window.addEventListener('offline', onOffline);
    window.addEventListener('online', onOnline);
    if (navigator.onLine === false) { onOffline(); } else { onOnline(); }

    // This page is also shown inside the guard's overlay (an iframe): there the
    // recovery must reload the page behind the overlay, not the frame.
    var embedded = false;
    try { embedded = window.self !== window.top; } catch (e) { embedded = true; }

    // Coming back: reload the page the user asked for (or the entry point if the
    // browser is already showing the outage page itself).
    function goBack() {
      if (embedded) {
        try {
          window.parent.postMessage({ hastamaOffline: 'back' }, window.location.origin);
          return;
        } catch (e) { /* fall through to a local navigation */ }
      }
      if (window.location.pathname.indexOf('/offline') === 0) {
        var next = new URLSearchParams(window.location.search).get('next');
        window.location.replace(next && next.charAt(0) === '/' ? next : '/');
      } else {
        window.location.reload();
      }
    }

    if (retryBtn) retryBtn.addEventListener('click', function () { window.location.reload(); });
    if (copyBtn && lanEl) {
      copyBtn.addEventListener('click', function () {
        var text = lanEl.textContent.trim();
        try {
          navigator.clipboard.writeText(text);
          statusEl.textContent = 'آدرس کپی شد: ' + text;
        } catch (e) {
          statusEl.textContent = 'این آدرس را دستی یادداشت کنید: ' + text;
        }
      });
    }

    // Re-check the connection quietly; the page closes itself when it is back.
    function check() {
      fetch('/health', { cache: 'no-store' })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (data && data.status === 'ok') {
            statusEl.textContent = 'ارتباط برقرار شد؛ بازگشت به سامانه…';
            goBack();
            return;
          }
          throw new Error('not-ready');
        })
        .catch(function () {
          statusEl.textContent = 'آخرین بررسی: ' +
            new Date().toLocaleTimeString('fa-IR') +
            ' — ارتباط هنوز برقرار نیست (تلاش بعدی: ' + retry + ' ثانیه)';
        })
        .then(function () { window.setTimeout(check, Math.max(5, retry) * 1000); });
    }
    setTimeout(check, Math.min(3, retry) * 1000);
  })();
  </script>
</body>
</html>
OFFLINE_HTML;

    /**
     * ``outage.page_context()`` — the context `offline.html` is rendered with.
     *
     * @return array{outage_title: string, outage_message: string, outage_active: bool, outage_manual: bool, lan_url: string, lan_enabled: bool, retry_seconds: int}
     */
    public static function context(): array
    {
        $outage = OutageService::status();
        $lan = LanAccessService::status();

        $lanAddress = (string) ($lan['expected_url'] ?? '');

        return [
            'outage_title' => ($outage['title'] ?? '') !== '' ? $outage['title'] : OutageService::DEFAULT_TITLE,
            'outage_message' => ($outage['message'] ?? '') !== '' ? $outage['message'] : OutageService::DEFAULT_MESSAGE,
            'outage_active' => (bool) ($outage['active'] ?? false),
            'outage_manual' => (bool) ($outage['manual'] ?? false),
            'lan_url' => ($outage['show_lan_address'] ?? false) ? $lanAddress : '',
            'lan_enabled' => (bool) ($lan['running'] ?? false),
            'retry_seconds' => max(5, min((int) ($outage['interval_seconds'] ?? OutageService::DEFAULT_INTERVAL_SECONDS), 60)),
        ];
    }

    /**
     * The rendered document.
     *
     * The Jinja's ``or`` defaults in ``<title>``/``<h1>`` and ``data-retry`` are
     * unreachable here — {@see context()} already guarantees a non-empty title
     * and a retry of at least 5 — so the placeholders receive final values.
     */
    public static function render(array $context): string
    {
        $lanUrl = (string) $context['lan_url'];
        $lanEnabled = (bool) $context['lan_enabled'];

        $lanBlock = '';

        if ($lanUrl !== '') {
            $lanStatus = $lanEnabled
                ? 'دسترسی از شبکهٔ داخلی روی سرور فعال است.'
                : 'توجه: حالت «دسترسی از شبکهٔ داخلی» روی سرور فعال نیست؛ آن را به مدیر سامانه اطلاع دهید.';

            $lanBlock = <<<LAN_BLOCK
      <div class="lan">
        <div class="lan__title">اگر در شبکهٔ داخلی آزمایشگاه هستید، سامانه از این آدرس در دسترس است:</div>
        <div class="lan__url" id="offlineLanUrl">{$lanUrl}</div>
        <div class="status">{$lanStatus}</div>
      </div>
LAN_BLOCK;
        }

        $copyButton = $lanUrl !== ''
            ? '<button type="button" id="offlineCopy">کپی آدرس شبکهٔ داخلی</button>'
            : '';

        // The Jinja template printed the operator's title and message through
        // `{{ … }}`, so the autoescaper applied to them; see {@see LegacyHtml}.
        return strtr(self::TEMPLATE, [
            '{{outage_title}}' => LegacyHtml::escape($context['outage_title']),
            '{{outage_message}}' => LegacyHtml::escape($context['outage_message']),
            '{{outage_manual_badge}}' => (bool) $context['outage_manual'] ? 'اعلام دستی قطعی' : 'قطع ارتباط',
            '{{retry_seconds}}' => (string) (int) $context['retry_seconds'],
            '{{lan_block}}' => $lanBlock,
            '{{copy_button}}' => $copyButton,
        ]);
    }
}
