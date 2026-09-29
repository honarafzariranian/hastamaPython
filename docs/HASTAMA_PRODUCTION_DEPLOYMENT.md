# Hastama Production Deployment

**Supersedes:** the earlier Caddy + `hastama.local` LAN topology described in
version 1 of this document. That design is retired: there is exactly one public
URL today and it is the same for laboratory and Internet users.

Detailed architecture, evidence and troubleshooting:
[`docs/network/UNIFIED_URL_ARCHITECTURE.md`](network/UNIFIED_URL_ARCHITECTURE.md).

## Scope

This document describes the supported Windows deployment: a Cloudflare Tunnel
that publishes `https://hastama.ir` to a loopback-bound FastAPI/Uvicorn process
on the laboratory server. Chrome/mobile acceptance on a second network remains a
manual checklist (§Acceptance) — it is not inferred from unit tests.

## Architecture

```text
LAN and Internet users
        |
        |  https://hastama.ir   (the only address users are given)
        v
Cloudflare edge                     TLS, WAF, HTTP->HTTPS, www->apex 301, HSTS
        |
        |  outbound tunnel (no inbound port, no port-forward)
        v
cloudflared  (Windows service "cloudflared", AUTOSTART, LocalSystem)
        |
        |  http://127.0.0.1:5000
        v
uvicorn app.main:app  <- launched ONLY by the "HastamaServer" task (راه‌اندازی_سرور_تولید.bat)
        |
        +-- SQL Server Express 127.0.0.1:1433 (loopback)
        +-- notification inbox / SSE stream, APScheduler maintenance jobs
        +-- label printer on the server (spooler)
```

FastAPI binds to `127.0.0.1:5000`. Nothing binds to the LAN interface, and no
inbound port is opened for users.

## Operator scripts (Persian file names)

Every launcher and helper under `scripts\` is named in Persian so that the file
name says what the file does.  Nothing else moved: `scripts\` is the single
folder for the operator and maintenance scripts (`.bat`, `.ps1`, `.py`), and the
repository root holds only the project's conventional entry files (`README.md`,
`AGENTS.md`, `pyproject.toml`, `Dockerfile`, `Makefile`, `docker-compose.yml`,
`Caddyfile`, `uv.lock`).

| File | What it does |
| --- | --- |
| `scripts\راه‌اندازی_سرور_تولید.bat` | **The production start path.** Invoked by the `\HastamaServer` task: waits for SQL Server, rotates the boot log, sets the UTF-8 environment and the client-asset switch, starts uvicorn on `127.0.0.1:5000` with the proxy flags. Do not start production by hand |
| `scripts\راه‌اندازی_سرور_توسعه.bat` | Development instance on `127.0.0.1:5001`, never published, never port 5000 |
| `scripts\نشست_توسعه_یک‌کلیکی.bat` | Development session: closes every instance, parks both autostart tasks for the session, runs the code on 5000 with `--reload` and restores everything on exit (`--check` for a dry run) |
| `scripts\شروع_سرور.bat` | Starts a stopped production instance (triggers `\HastamaServer`) |
| `scripts\توقف_سرور.bat` | Stops production after identifying the owning process |
| `scripts\توقف_همه_نمونه‌ها.bat` | Closes every Hastama instance and starts nothing |
| `scripts\آزادسازی_پورت_۵۰۰۰.bat` | Manual recovery: frees port 5000 by killing whatever owns it (warns first) |
| `scripts\فعال‌سازی_اجرای_خودکار.bat` | Enables both scheduled tasks (`\HastamaServer` + `\HastamaWatchdog`) |
| `scripts\غیرفعال‌سازی_اجرای_خودکار.bat` | Disables both tasks for a maintenance window |
| `scripts\بازکردن_پورت_شبکه‌محلی.bat` | Administrator, once: opens inbound TCP 5000 to the local subnet only |
| `scripts\بستن_پورت_شبکه‌محلی.bat` | Administrator: the exact undo of the previous row |
| `scripts\راه‌اندازی_سرور_شبکه‌محلی.bat` | Laboratory LAN deployment (FastAPI on 8000 behind Caddy HTTPS) |
| `scripts\نصب_کلاینت_شبکه‌محلی.bat` | Sets up a Windows client for that laboratory LAN (root certificate) |
| `scripts\اجرای_فریفاف.bat` | Starts the Freebuff desktop application through the local proxy |

The `.ps1` helpers keep their ASCII names on purpose: Windows PowerShell 5.1
reads a BOM-less script with the ANSI code page, so the watchdog and session
helpers stay ASCII and identify the launchers by their path under `scripts\`
instead of by file name.

## Process supervision (the production start path)

There is exactly one production start chain and one development start chain:

```text
Production:
    \HastamaServer                       (scheduled task, at boot, MultipleInstancesPolicy=IgnoreNew)
        -> cmd.exe /c scripts\راه‌اندازی_سرور_تولید.bat
        -> .venv\Scripts\python.exe -m uvicorn
               --host 127.0.0.1 --port 5000
               --proxy-headers --forwarded-allow-ips 127.0.0.1
        -> 127.0.0.1:5000                (published as https://hastama.ir)

Development:
    scripts\راه‌اندازی_سرور_توسعه.bat
        -> 127.0.0.1:5001                (never published, never the production port)
```

`scripts\راه‌اندازی_سرور_تولید.bat` waits for SQL Server, rotates the boot log, sets
`PYTHONUTF8=1` and `PYTHONIOENCODING=utf-8`, and then executes the uvicorn
command above. Nothing else may own `127.0.0.1:5000`.

| Intent | Correct command |
|---|---|
| Start a stopped production instance | `scripts\شروع_سرور.bat` (triggers the task) |
| Stop the production instance | `scripts\توقف_سرور.bat` (identifies the process, then stops it) |
| Development / debugging | `scripts\راه‌اندازی_سرور_توسعه.bat` — binds **127.0.0.1:5001**, never published |
| Work on the code with logs, on the normal address | `scripts\نشست_توسعه_یک‌کلیکی.bat` (double-click it, or run it from a terminal) — closes every instance, parks both tasks, runs on 5000 with `--reload`, hands the machine back on exit |
| Close every running instance, start nothing | `scripts\توقف_همه_نمونه‌ها.bat` |
| Disable restart behaviour for a maintenance window | `scripts\غیرفعال‌سازی_اجرای_خودکار.bat` (disables the server **and** the watchdog task) |

**Never start production by hand** (`python -m uvicorn app.main:app --port 5000`
from a terminal). Such a process takes the listening socket away from the
supervised instance and is invisible to it: it runs without the flags and the
UTF-8 environment above, and it dies when its terminal closes. This happened for
real on 2026-09-28 (started from a VS Code terminal; the terminal was closed at
08:27:45 and `https://hastama.ir` returned `502` until the watchdog reclaimed the
port). The `\HastamaWatchdog` task now identifies the process that owns the port
and repairs this condition — see
[`docs/network/UNIFIED_URL_ARCHITECTURE.md`](network/UNIFIED_URL_ARCHITECTURE.md)
§5.

## Working on the code (development sessions)

A development session is the answer to: *"I want to run the code I am editing,
with logs in front of me, and I do not want a second instance of the system left
running somewhere on the machine."* The entry point is one double-clickable file
in `scripts\`:

```bat
scripts\نشست_توسعه_یک‌کلیکی.bat            :: the session (double-click or run from a terminal)
scripts\نشست_توسعه_یک‌کلیکی.bat --check    :: what would be stopped, and current status; nothing is touched
scripts\توقف_همه_نمونه‌ها.bat   :: close every instance, start nothing
```

What a session does, in order:

1. **Closes every Hastama instance.** The process is identified before it is
   stopped: its command line must match the application signature
   (`-m uvicorn app.main:app`, **on any port**, not only 5000), or it must be the
   `cmd.exe` wrapper of `scripts\راه‌اندازی_سرور_تولید.bat`, or it must be an orphaned
   reload worker of this repository's virtual environment (recognised through
   `.venv\pyvenv.cfg`). The `\HastamaServer` task instance is cleared as well, so
   a later `schtasks /Run` is not refused by `MultipleInstancesPolicy=IgnoreNew`.
   A **foreign** owner of 5000 is reported and left alone; the launcher aborts
   instead of starting a second instance on top of it.
2. **Parks the two autostart tasks for the length of the session.**
   `\HastamaServer` and `\HastamaWatchdog` are disabled (a single UAC prompt, and
   only for this bookkeeping) so that the watchdog cannot restart the supervised
   instance five minutes after it was closed. Their definitions, triggers and
   the files behind them are never edited — only the enabled/disabled state.
3. **Starts the application on `127.0.0.1:5000`** with the production proxy
   flags, `PYTHONUTF8=1`, `PYTHONIOENCODING=utf-8`, `--reload --reload-dir app`
   and `--log-config scripts\dev-logging.json`. The Cloudflare Tunnel points at
   5000, so `https://hastama.ir` serves **the code under test** while the session
   runs; the console shows the logs live and they are appended to
   `logs\hastama-dev.log` (UTF-8, 50 MB + one rotation) as well.
4. **Hands the machine back** when the console ends (Ctrl+C, closing the window,
   or a crash): the tasks are enabled again and, if production was serving
   before the session, `\HastamaServer` is triggered once 5000 is free. If the
   previous session was killed before it could restore (reboot), the next
   session repairs that first.

Operational notes:

- The watchdog and the server log their verdicts as usual; while a session holds
  the port, `\HastamaWatchdog` is disabled, so expect no `HEALTHY` lines and no
  restart attempts in that window.
- If the UAC prompt is declined, the session still runs on 5000 — but the
  watchdog is left enabled and can bring production back within five minutes, in
  which case the next start of the application fails with `[Errno 10048]`.
  Park them by hand with `scripts\غیرفعال‌سازی_اجرای_خودکار.bat` in that case.
- For an isolated instance that never touches the published port, keep using
  `scripts\راه‌اندازی_سرور_توسعه.bat` (5001).
- In VS Code, the same three actions are available as tasks (`.vscode/tasks.json`:
  *Hastama: نشست کار روی کد*, *Hastama: فقط uvicorn*, *Hastama: بستن همهٔ نمونه‌ها*).
- The bare command, if you prefer to paste it into a terminal (it assumes nothing
  else is listening on 5000 yet — run `scripts\نشست_توسعه_یک‌کلیکی.bat` or
  `scripts\توقف_همه_نمونه‌ها.bat` first):

  ```bat
  cd /d E:\Hastama
  set PYTHONUTF8=1
  set PYTHONIOENCODING=utf-8
  ".venv\Scripts\python.exe" -m uvicorn app.main:app ^
      --host 127.0.0.1 --port 5000 --reload --reload-dir app ^
      --proxy-headers --forwarded-allow-ips 127.0.0.1 ^
      --log-config scripts\dev-logging.json
  ```

## Prerequisites

- Python 3.11+ and the project `.venv` (already provisioned on the server).
- SQL Server Express + ODBC Driver 17.
- Cloudflare account with the `hastama.ir` zone and the tunnel already created.
- `cloudflared` installed as a Windows service (token-based, remotely managed).
- No Caddy, no internal CA, no internal DNS record, and no NSSM service are part
  of this deployment.

## Install and configure

```powershell
# 1. Application auto-start (creates the \HastamaServer task; asks for the
#    Windows password of the service account once, stores it in the task)
powershell -ExecutionPolicy Bypass -File .\scripts\install_autostart.ps1

# 2. Reliability add-on: restart the app if it dies between boots (RR-25).
#    Already installed on this server as \HastamaWatchdog (every 5 minutes,
#    SYSTEM) - re-run only on a fresh host.
powershell -ExecutionPolicy Bypass -File .\scripts\install_watchdog.ps1   # elevated

# 3. Server-only configuration
#    .env holds SECRET_KEY, HASTAMA_HMAC_SECRET, ARAZ_ACCESS_PASSWORD and the
#    canonical-host settings.  Never commit it.
```

Cloudflare side (dashboard, one-off): the tunnel's **Public Hostname** must map
`hastama.ir` to `http://127.0.0.1:5000`. See the network document §4.

## DNS and certificate

**No DNS or certificate work is required on the server.**

- `hastama.ir` is a proxied (orange-cloud) Cloudflare record; its public
  addresses are Cloudflare anycast and are never a laboratory IP.
- TLS is terminated at the Cloudflare edge (Let's Encrypt certificate, SAN
  `*.hastama.ir, hastama.ir`).
- `www.hastama.ir` redirects to the apex domain.
- Do **not** create a local A record pointing the public name at
  `192.168.3.69`: it would break TLS validation, split the user experience and
  reintroduce a second, insecure address.

## Windows services and tasks

| Component | How it starts | Notes |
|---|---|---|
| `cloudflared` | Windows service, `AUTO_START` | Token file `C:\ProgramData\cloudflared\token` (secret, never copy into the repo) |
| Hastama app | Scheduled Task `\HastamaServer`, trigger **At system startup**, runs `scripts\راه‌اندازی_سرور_تولید.bat` as the service account | Waits for `MSSQL$SQLEXPRESS`, then starts uvicorn on `127.0.0.1:5000` |
| Watchdog | Scheduled Task `\HastamaWatchdog`, every 5 minutes, SYSTEM | **Not a port check.** It identifies the process that owns `127.0.0.1:5000`, requires the Hastama/Uvicorn application signature (`uvicorn app.main:app --port 5000` together with `--host 127.0.0.1 --port 5000 --proxy-headers --forwarded-allow-ips 127.0.0.1`), requires supervision evidence (`راه‌اندازی_سرور_تولید.bat` ancestry **or** the instance writing `logs\hastama-autostart.log`), then probes `GET http://127.0.0.1:5000/health` for `200`. A non-Hastama owner is reported as `PORT_FOREIGN_OWNER` and is **never** killed; a process that cannot be inspected is `PROCESS_LOOKUP_FAILED`; an identified Hastama process running outside supervision is `UNEXPECTED_PROCESS` and is reclaimed by stopping only that identified process and re-triggering `\HastamaServer`. A silent port is `APPLICATION_DOWN`; a failing `/health` becomes `APPLICATION_UNHEALTHY` and restarts only after the configured consecutive-failure threshold; a fault in the probe itself is `HEALTH_PROBE_ERROR` and can never trigger a restart; restarts are capped per rolling window (`RESTART_SUPPRESSED`). Full model: `docs/network/UNIFIED_URL_ARCHITECTURE.md` §5 |

The NSSM-based `scripts\install_services.ps1` / `remove_services.ps1` pair belongs
to the retired Caddy topology and is kept only for historical reference; do not
use it for new installations.

## Firewall

Default posture is `BlockInbound` on every profile. The deployment needs **no
inbound allow rule**: the tunnel dials outbound. Keep the explicit denies:

```powershell
Get-NetFirewallRule -DisplayName 'Hastama*' | Select-Object DisplayName,Action,Enabled
```

Expected: `Hastama - Block Uvicorn 5000`, `… SQL Server 1433`, `… SMB 445`, `…
RDP 3389` (TCP/UDP). If `Hastama HTTPS LAN` (allow TCP 443 from the LAN subnet)
still exists, remove it — nothing listens on 443 (RR-26).

The only legitimate exception is the optional LAN access mode (next section):
`Hastama - Allow Uvicorn 5000 (LAN)` (`RemoteAddress=LocalSubnet`) with the
matching block rule disabled. Both are managed by
`scripts\lan_access_firewall.ps1`; `-Action status` prints the current state and
needs no administrator rights.

## LAN access mode (internet outage fallback)

Hastama is normally reached only through `https://hastama.ir`. When the internet
link (and with it Cloudflare) is down, the laboratory can still work through a
toggled listener on the server's own LAN address:

```text
workstation  ->  http://<lan-address>:5000  ->  127.0.0.1:5000 (uvicorn)
```

The listener is part of the application process (`app/services/lan_access.py`) and
is switched in **master-admin → system settings → دسترسی از شبکه داخلی**.
It is stored as `system_config.lan_access_enabled`, survives a reboot and is
re-opened by the startup hook. There is **no automatic time-out**: it stays on
until an administrator turns it off.

Setup, once per machine (administrator):

```text
scripts\بازکردن_پورت_شبکه‌محلی.bat          open inbound TCP 5000 for the local subnet only
                                         (disables the Hastama block rule and remembers it)
master-admin -> system settings          switch "دسترسی از شبکه داخلی" on
                                         the card then shows the address to hand out
```

Roll back:

```text
master-admin -> system settings          switch it off (effective immediately, no restart)
scripts\بستن_پورت_شبکه‌محلی.bat         remove the allow rule, restore the block rule
scripts\lan_access_firewall.ps1 -Action status    read-only report (no administrator needed)
```

Operational notes:

* The LAN origin is **plain HTTP** (no certificate exists for a private address).
  The session cookie drops its `Secure` flag for LAN requests only; the public
  HTTPS origin is untouched. See RR-21 and RR-29.
* Only the local subnet may connect (`RemoteAddress=LocalSubnet`, `Domain` and
  `Private` profiles); nothing is exposed to the Internet.
* The in-app switch is the effective control: while it is off nothing is bound on
  the LAN address, so an allowed packet is answered with a reset.
* Enabling, disabling and the built-in self test are audited in
  `dbo.admin_actions` (`enable_lan_access`, `disable_lan_access`,
  `lan_access_selftest`).
* The firewall state this script changed is kept in
  `logs\lan-access-firewall.json` so that the disable step is an exact undo.
* A firewall change needs **no** restart of the application or of the tunnel, and
  the Cloudflare Tunnel, SQL Server and every other rule are never touched.
* Full technical description: `docs/network/UNIFIED_URL_ARCHITECTURE.md` §9b.

## Internet outage mode (the page users see)

`master-admin` → **system settings** → *صفحهٔ قطعی اینترنت*.  The mode watches the
internet link from this host and, when it is gone:

1. cuts the active sessions (master administrators are kept logged in),
2. answers every page request that did **not** come from the laboratory network
   with the outage page (`503` + `Retry-After: 30`); API callers get JSON,
3. returns to normal automatically after the link is back (two good probes),

and it writes both transitions to the audit trail (`internet_outage_detected` /
`internet_outage_recovered`).

For a workstation that cannot reach the server at all (its own internet is down),
the service worker installed by `/static/js/offline-guard.js` shows the same page
from its cache — over HTTPS the first page load after the outage keeps working,
instead of the browser's error page.

Operating notes:

* **Never blocked**, so the system stays manageable: the laboratory listener,
  direct local requests (the watchdog's `/health`, the printer side, an
  administrator on the server), an authenticated master administrator, and
  `/static/`, `/offline`, `/sw.js`, `/health`, `robots.txt`, `sitemap.xml`.
* **Defaults to armed** (a missing `outage_page_enabled` row means "on") with a
  conservative trigger: 3 failed probes 30 seconds apart.  The probe uses raw TCP
  (never the machine's HTTP proxy) against `hastama.ir:443`, `1.1.1.1:443` and
  `8.8.8.8:443` — the first one that answers means "online".  Adjust the targets,
  interval and threshold in the same card if this network blocks one of them.
* **Manual switch:** *اعلام دستی قطعی* forces the mode during planned maintenance
  (and cuts sessions if that switch is on); *لغو حالت دستی* clears it.
* **Settings live in `system_config`** (`outage_*` keys) and are re-applied at
  runtime; nothing here needs a restart.
* The Cloudflare Tunnel, SQL Server and the firewall are never touched by this
  mode.  Full description: `docs/network/UNIFIED_URL_ARCHITECTURE.md` §9c.

## Iran-only access (VPN users must switch it off first)

`master-admin` → **system settings** → *فقط آی‌پی ایران*.  The public URL accepts
Iranian addresses only: a client whose public address is outside a registered
Iranian range is answered with `403` and
`app/templates/vpn-warning.html`, which asks the user to switch the VPN (proxy /
filter-breaker) off and come back.

* **Where the verdict comes from:** the Iranian address space ships with the
  application (`app/data/iran_ip_ranges.txt` — 1599 IPv4 + 571 IPv6 ranges,
  generated from the RIPE and APNIC delegated statistics).  The check is
  offline, so it still works while the internet is down, and no visitor address
  is ever sent to a third party.
* **Defaults to armed** (a missing `iran_only_enabled` row means "on").  A
  missing or unreadable range file makes the filter report *armed but blind* on
  the card and stop rejecting anyone, so a data-file problem can never lock
  every Iranian user out.
* **Never blocked**, so the switch is always reachable: internal addresses (the
  server, the laboratory LAN, CGNAT, link-local), an authenticated master
  administrator, and `/iran-only`, `/iran-only/check`, `/health`, `/static/`,
  `/offline`, `/sw.js`, `robots.txt`, `sitemap.xml`, `favicon.ico`.
* **Test before you walk away:** type an address into *تست یک آی‌پی* on the card
  (leave it empty to test the address you are connecting from).  It reports the
  classification, the matching range and whether that address would be refused
  right now.
* **Keeping the list current** (the only network call in the feature, and it is
  never automatic): the card's **به‌روزرسانی فهرست آی‌پی** button, or on the
  server
  ```
  .venv\Scripts\python.exe scripts\refresh_iran_ip_ranges.py
  ```
  Both download ~30 MB from `ftp.ripe.net` and `ftp.apnic.net` **without** the
  machine's proxy variables.  If a legitimate Iranian client is ever refused,
  refresh the list first — a range allocated after the bundled snapshot is the
  expected cause.
* **Settings live in `system_config`** (`iran_only_*` keys) and are re-applied at
  runtime; nothing here needs a restart.  Blocks are audited
  (`iran_only_blocked`, throttled to one entry per address per 5 minutes).  Full
  description: `docs/network/UNIFIED_URL_ARCHITECTURE.md` §9d.

## Login page (loader + CAPTCHA lifetime)

`master-admin` → **system settings** → *تجربهٔ ورود (لودر و کد امنیتی)*.  These
values live in `system_config` (`login_loader_*`, `login_captcha_*`) and are
re-applied without a restart; the login page reads them from the public
`/api/system-config` endpoint.

* **Pressing ورود** shows a full-screen loader for `login_loader_seconds` (three
  by default, 1–15) and then goes to the page the user asked for.  The old
  *"در حال ورود…"* caption and the green success state on the button are gone —
  the request still leaves for the server immediately, the loader is only a
  minimum display time.  Turning the loader off makes the login instant.
* **CAPTCHA lifetime** (`login_captcha_ttl_seconds`, 30–1800 s, default 180) is
  the real control over how long a code can be used.  With the notice switch on,
  the login page counts down the last minute and, once the code is dead, says so
  and keeps saying it until the user takes a new code; an expired submission is
  refused with `captcha_expired` and the image is refreshed while the message
  stays on screen.
* After changing these, a browser that still holds the old page only needs a
  refresh (`js/script.js` is versioned with a `?v=` query).
* Full description: `docs/network/UNIFIED_URL_ARCHITECTURE.md` §9e.

## Client-side delivery (comment-free assets)

Production serves JavaScript, CSS and rendered pages **without developer
comments**, so internal module names, operational file paths and implementation
notes no longer travel to the browser (RR-33).  Nothing else about the delivery
changes: the same files, the same URLs and the same cache-busting `?v=` values.

* The switch is the environment variable `HASTAMA_MINIFY_CLIENT_ASSETS=1`,
  already set in `scripts\راه‌اندازی_سرور_تولید.bat`.  It is deliberately **not** set in
  `scripts\راه‌اندازی_سرور_توسعه.bat`, so development on `127.0.0.1:5001` (and every LAN tool
  or temporary instance) keeps the readable sources.  Unset or `0` reproduces
  the old behaviour exactly.
* Implementation: `app/services/client_assets.py` plus
  `_ClientAssetMinifierMiddleware` in `app/main.py`.  Only comments and redundant
  whitespace are removed — newlines are kept (automatic semicolon insertion is
  never risked), string/template/regex literals are byte-identical, and every
  JavaScript result is re-tokenised and compared token-for-token with its input.
  If anything is uncertain (changed token stream, unterminated literal,
  unbalanced CSS, non-UTF-8 body) the original bytes are served instead, so a
  mistake can degrade readability but never break a page.  Comments inside
  `<script>`/`<style>`, quoted attributes and IE conditional comments are left
  alone; already-minified vendor bundles above 512 KB are not re-tokenised and
  only lose a dangling `sourceMappingURL`.
* Verification and optional release snapshot:

```bat
.venv\Scripts\python.exe scripts\minify_client_assets.py --check   :: offline, no network
.venv\Scripts\python.exe scripts\minify_client_assets.py --write   :: build\client-assets\
```

  `--check` minifies all 60 shipped files, refuses any file whose meaning would
  change and runs `node --check` over every result when Node is present
  (currently −689,854 B/−21.0 %); it is the same code path production uses, so a
  green run is the guarantee production relies on.
* Production also runs uvicorn with `--no-server-header`, so responses no longer
  advertise the ASGI server, and `Server`/`X-Powered-By` disclosure is gone.
* What this is **not**: a security boundary.  The browser always receives
  readable, modifiable code, and CSP still needs `'unsafe-inline'` for the inline
  `on…=` handlers, inline `<script>` blocks and inline styles that the legacy
  pages use.  Every authorisation, authentication, CSRF and validation decision
  stays on the server (`tests/test_client_hardening.py` pins the exposure rules).

## Session registry (bulk terminate / bulk delete)

`/master-admin/sessions` carries the per-row actions and two panel-wide buttons
that operate on the whole `dbo.user_sessions` registry.  Both are answered only
for a session with `is_master_admin` (`_master_admin` in
`app/api/routes/master_admin.py`), both require the usual double-submit CSRF token,
and both write an entry to the admin audit log with the affected row count, so the
person who pressed the button is always recorded.

| Button | Endpoint | Effect |
| --- | --- | --- |
| «خاتمه همه نشست‌ها» | `POST /master-admin/api/sessions/terminate-all` | Sets `is_active = 0` for every active row (the login history stays). Accounts in `MASTER_ADMIN_USERNAMES` are kept, exactly as in outage mode, so the operator keeps the control plane needed to undo the action |
| «حذف همه رکوردها» | `DELETE /master-admin/api/sessions` | `DELETE FROM dbo.user_sessions` — active rows **and** login history. Irreversible, no account is exempted, and because the registry is what makes a signed cookie revocable every open browser is logged out at its next request |

The row actions stay on `POST /master-admin/api/sessions/{session_key}/terminate`
and `DELETE /master-admin/api/sessions/{session_key}`; the panel buttons are bound
in `app/static/js/master-admin.js` with `addEventListener` (no inline handler) and
both ask for confirmation before the request is sent.  Behaviour is pinned by
`tests/test_session_bulk_actions.py`.

> Deleting the whole registry also deletes the login/audit evidence of who was
> signed in.  Export or back up `dbo.user_sessions` before using the delete button
> on a system where that history matters.

## Background jobs

The application starts APScheduler with the FastAPI lifecycle:

- scheduled notification publication every 30 seconds.

Do not run multiple application scheduler instances against the same database
unless the jobs are made leader-elected; use one Uvicorn process (as deployed) or
move jobs to a dedicated worker later. The watchdog never starts a second
instance: it triggers the existing task, whose `MultipleInstancesPolicy` is
`IgnoreNew`.

## Backup and recovery

Back up:

1. SQL Server database, including notification tables.
2. The server-only `.env` file through the organization's secret-management
   process.
3. `C:\ProgramData\cloudflared\token` **only** through that same secret process
   (never to the repository or a ticket).
4. Private application uploads and any Access database files.

Recovery order:

1. Restore SQL Server and verify `user_table`.
2. Restore `.env` and the tunnel token.
3. Restore application files and `.venv` dependencies.
4. Start the `cloudflared` service, then `schtasks /Run /TN HastamaServer`.
5. Verify `https://hastama.ir/login` returns `200` and a WebSocket probe reaches
   `101` (network document §7).

## Acceptance

On a real client (and again from an off-LAN connection):

1. Open `https://hastama.ir` — the only URL, inside and outside the laboratory.
2. Confirm `window.isSecureContext === true`.
3. Confirm the in-panel SSE connection stays open (`/api/notifications/stream`).
4. Log in and allow browser notifications.
5. Publish a controlled admin notification and verify inbox, toast, counter and
   read/unread behaviour.
6. Open the call-management page and confirm the TV display WebSocket connects.
7. Repeat from a mobile network. The URL does not change.

A real Windows popup, a minimized-browser test and a second-network test require
manual execution and are not inferred from Python checks.

## Troubleshooting

- `502`/`1033` from Cloudflare: check `sc query cloudflared`, then
  `netstat -ano | findstr :5000` and `logs\hastama-autostart.log` (rotated to
  `.log.1` above 50 MB) plus `logs\hastama-watchdog.log` for restart attempts.
- `400 Invalid host header`: the request used a hostname outside
  `HASTAMA_ALLOWED_HOSTS` (`hastama.ir` is the production value).
- 401: session cookie missing / not logged in.
- 403: admin role or Origin policy failed (including cross-site WebSocket and
  CSRF-exempt writes).
- SSE stalls: confirm the response stays `200` with `text/event-stream` and that
  `cf-cache-status` is `DYNAMIC` (never cache dynamic responses at the edge).
- Redirect points at `http://…`: verify the uvicorn proxy flags in
  `scripts\راه‌اندازی_سرور_تولید.bat` and that no code hard-codes an `http://` canonical URL.
- Scheduled notification delayed: inspect scheduler logs and `scheduled_at`.
