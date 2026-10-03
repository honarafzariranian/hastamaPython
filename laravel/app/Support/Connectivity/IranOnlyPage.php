<?php

namespace App\Support\Connectivity;

use App\Support\Legacy\LegacyHtml;

/**
 * The Iran-only access-policy guide — ``iran_access.page_context()`` and
 * `app/templates/vpn-warning.html` from the Python application.
 *
 * Reachable on purpose, with ``200``, from every address: a user who is asked
 * to switch a VPN off needs a URL they can keep, and the warning page itself
 * polls ``/iran-only/check`` to come back on its own.
 *
 * The template is a nowdoc with ``{{placeholder}}`` markers mirroring the Jinja
 * expressions, exactly as {@see OfflinePage} does; the two conditional sections
 * are rendered by PHP first and substituted as whole blocks.
 *
 * The context is ``iran_access.page_context(ip=...)`` key for key:
 *
 * .. code-block:: python
 *
 *     verdict = classify(address)
 *     return {
 *         "vpn_title": _state.title or DEFAULT_TITLE,
 *         "vpn_message": _state.message or DEFAULT_MESSAGE,
 *         "vpn_help": _state.help_text or DEFAULT_HELP,
 *         "vpn_help_lines": [line for line in (_state.help_text or DEFAULT_HELP).splitlines() if line.strip()],
 *         "vpn_ip": verdict.get("ip") or "",
 *         "vpn_reason": verdict.get("label") or "",
 *         "vpn_filter_on": bool(_state.enabled),
 *         "retry_seconds": 30,
 *     }
 */
final class IranOnlyPage
{
    /**
     * The template, verbatim from `app/templates/vpn-warning.html`.
     */
    private const TEMPLATE = <<<'VPN_WARNING_HTML'
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{vpn_title}}</title>
<style>
  :root {
    --ink: #0f172a; --muted: #64748b; --line: #e2e8f0;
    --accent: #6366f1; --danger: #e11d48; --ok: #10b981;
  }
  * { box-sizing: border-box; }
  html, body { min-height: 100%; }
  body {
    margin: 0; padding: 24px;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(1200px 600px at 20% -10%, #ffe4e6 0%, #f8fafc 45%, #f1f5f9 100%);
    color: var(--ink);
    font-family: Vazirmatn, "Segoe UI", Tahoma, sans-serif;
    line-height: 1.9;
  }
  .card {
    width: 100%; max-width: 640px;
    background: #fff; border: 1px solid var(--line); border-radius: 20px;
    padding: 32px 28px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .12);
    text-align: center;
  }
  .badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 14px; border-radius: 999px;
    background: rgba(225, 29, 72, .12); color: #9f1239;
    font-size: .78rem; font-weight: 700;
  }
  .badge i { width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
  .icon { font-size: 3rem; line-height: 1; margin: 14px 0 4px; }
  h1 { font-size: 1.25rem; margin: 6px 0 10px; }
  p { margin: 0 0 14px; color: var(--muted); font-size: .93rem; }
  .reason {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px;
    font-size: .82rem; color: var(--muted);
    background: #f8fafc; border: 1px solid var(--line);
    border-radius: 12px; padding: 10px 12px; margin: 0 0 18px;
  }
  .reason b { color: var(--ink); font-weight: 600; }
  .reason code {
    direction: ltr; unicode-bidi: embed;
    font-family: ui-monospace, Consolas, monospace;
    background: #fff; border: 1px solid var(--line);
    border-radius: 8px; padding: 2px 8px; color: var(--ink);
  }
  .help {
    text-align: start; margin: 0 0 18px; padding: 16px 18px;
    border: 1px dashed rgba(99, 102, 241, .45);
    background: rgba(99, 102, 241, .06);
    border-radius: 14px;
  }
  .help__title { font-size: .86rem; font-weight: 700; color: #4338ca; margin-bottom: 8px; }
  .help ol { margin: 0; padding-inline-start: 20px; font-size: .85rem; color: #475569; }
  .help li { margin-bottom: 4px; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
  button {
    font-family: inherit; font-size: .9rem; font-weight: 600;
    padding: 10px 18px; border-radius: 12px; cursor: pointer;
    border: 1px solid var(--line); background: #fff; color: #334155;
  }
  button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  button:disabled { opacity: .55; cursor: default; }
  .status { margin-top: 12px; font-size: .8rem; color: var(--muted); min-height: 20px; }
  .foot { margin-top: 16px; font-size: .76rem; color: #94a3b8; }
  @media (max-width: 480px) {
    body { padding: 12px; }
    .card { padding: 22px 16px; border-radius: 16px; }
  }
</style>
</head>
<body>
  <div class="card" id="vpnCard" data-retry="{{retry_seconds}}">
    <span class="badge"><i></i>فقط آی‌پی ایران</span>
    <div class="icon">🛡️</div>
    <h1>{{vpn_title}}</h1>
    <p>{{vpn_message}}</p>

    {{vpn_ip_block}}

    {{vpn_help_block}}

    <div class="actions">
      <button type="button" class="primary" id="vpnRetry">بررسی مجدد</button>
      <button type="button" id="vpnCopyIp">کپی آی‌پی من</button>
    </div>

    <div class="status" id="vpnStatus">پس از قطع VPN، ورود به‌صورت خودکار بررسی می‌شود…</div>
    <div class="foot">
      این صفحه بدون نیاز به اینترنت نمایش داده می‌شود. اگر مطمئن هستید VPN شما قطع است و باز هم این پیام را می‌بینید،
      این آی‌پی را به مدیر سامانه اطلاع دهید.
    </div>
  </div>

  <script>
  (function () {
    var card = document.getElementById('vpnCard');
    if (!card) return;
    var retry = parseInt(card.getAttribute('data-retry'), 10);
    if (!(retry > 0)) retry = 30;

    var statusEl = document.getElementById('vpnStatus');
    var ipEl = document.getElementById('vpnIp');
    var retryBtn = document.getElementById('vpnRetry');
    var copyBtn = document.getElementById('vpnCopyIp');

    if (retryBtn) retryBtn.addEventListener('click', function () { window.location.reload(); });

    if (copyBtn) copyBtn.addEventListener('click', function () {
      var text = ipEl ? ipEl.textContent.trim() : '';
      if (!text) return;
      try {
        navigator.clipboard.writeText(text);
        statusEl.textContent = 'آی‌پی کپی شد: ' + text;
      } catch (e) {
        statusEl.textContent = 'این آی‌پی را دستی یادداشت کنید: ' + text;
      }
    });

    // The verdict is made per request against the fresh address, so simply ask
    // again: the moment the VPN is off the answer flips and we go back to the
    // page the user wanted.
    function check() {
      fetch('/iran-only/check', { cache: 'no-store' })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (data && data.allowed) {
            statusEl.textContent = 'دسترسی تأیید شد؛ بازگشت به سامانه…';
            window.location.replace('/');
            return;
          }
          if (data && data.ip && ipEl) { ipEl.textContent = data.ip; }
          throw new Error('still-blocked');
        })
        .catch(function () {
          statusEl.textContent = 'آخرین بررسی: ' +
            new Date().toLocaleTimeString('fa-IR') +
            ' — هنوز با آی‌پی ایران وصل نیستید (تلاش بعدی: ' + retry + ' ثانیه)';
        })
        .then(function () { window.setTimeout(check, Math.max(5, retry) * 1000); });
    }
    setTimeout(check, Math.min(5, retry) * 1000);
  })();
  </script>
</body>
</html>
VPN_WARNING_HTML;

    /**
     * ``iran_access.page_context(ip=...)`` — the context `vpn-warning.html` renders with.
     *
     * @return array{vpn_title: string, vpn_message: string, vpn_help: string, vpn_help_lines: array<int, string>, vpn_ip: string, vpn_reason: string, vpn_filter_on: bool, retry_seconds: int}
     */
    public static function context(string $ip): array
    {
        // status() loads the settings (title/message/help) and the range list,
        // which is what the Python's startup hook did before the first request.
        $status = IranAccessService::status();
        $verdict = IranAccessService::classify($ip);

        $help = ($status['help_text'] ?? '') !== '' ? $status['help_text'] : IranAccessService::DEFAULT_HELP;

        return [
            'vpn_title' => ($status['title'] ?? '') !== '' ? $status['title'] : IranAccessService::DEFAULT_TITLE,
            'vpn_message' => ($status['message'] ?? '') !== '' ? $status['message'] : IranAccessService::DEFAULT_MESSAGE,
            'vpn_help' => $help,
            'vpn_help_lines' => self::helpLines($help),
            'vpn_ip' => ($verdict['ip'] ?? '') !== '' ? $verdict['ip'] : '',
            'vpn_reason' => ($verdict['label'] ?? '') !== '' ? $verdict['label'] : '',
            'vpn_filter_on' => (bool) ($status['enabled'] ?? true),
            'retry_seconds' => 30,
        ];
    }

    /**
     * The rendered document.
     */
    public static function render(array $context): string
    {
        $vpnIp = (string) $context['vpn_ip'];
        $vpnReason = (string) $context['vpn_reason'];

        $ipBlock = '';

        if ($vpnIp !== '') {
            // The Jinja template printed these through `{{ … }}`, so the
            // autoescaper applied to them; see {@see LegacyHtml}.
            $ipBlock = '<div class="reason"><span>آی‌پی شناسایی‌شدهٔ شما:</span>'
                .'<code id="vpnIp">'.LegacyHtml::escape($vpnIp).'</code>'
                .($vpnReason !== '' ? '<span>— '.LegacyHtml::escape($vpnReason).'</span>' : '')
                .'</div>';
        }

        $helpBlock = '';

        if ($context['vpn_help_lines'] !== []) {
            $items = '';

            foreach ($context['vpn_help_lines'] as $line) {
                $items .= '<li>'.LegacyHtml::escape($line).'</li>';
            }

            $helpBlock = '<div class="help"><div class="help__title">چگونه VPN را قطع کنم و وارد شوم؟</div>'
                .'<ol>'.$items.'</ol></div>';
        }

        return strtr(self::TEMPLATE, [
            '{{vpn_title}}' => LegacyHtml::escape($context['vpn_title']),
            '{{vpn_message}}' => LegacyHtml::escape($context['vpn_message']),
            '{{retry_seconds}}' => (string) (int) $context['retry_seconds'],
            '{{vpn_ip_block}}' => $ipBlock,
            '{{vpn_help_block}}' => $helpBlock,
        ]);
    }

    /**
     * ``[line for line in help_text.splitlines() if line.strip()]``.
     *
     * ``\R`` is the PCRE spelling of Python's ``str.splitlines()`` — it covers
     * the same set of line boundaries (``\n``, ``\r\n``, ``\r``, ``\v``, ``\f``,
     * NEL, U+2028, U+2029), which a plain ``explode("\n", ...)`` would not.
     *
     * @return array<int, string>
     */
    private static function helpLines(string $help): array
    {
        $lines = preg_split('/\R/u', $help);

        if ($lines === false) {
            return [];
        }

        $nonBlank = [];

        foreach ($lines as $line) {
            if (preg_replace('/\s+/u', '', $line) !== '') {
                $nonBlank[] = $line;
            }
        }

        return $nonBlank;
    }
}
