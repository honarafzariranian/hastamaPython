# Hastama — Unified Single-URL Network Architecture

**Status:** implemented and validated on the live host on 2026-09-27.
**Canonical (and only) user-facing URL:** `https://hastama.ir`

Every user — inside the laboratory LAN, at home, on a mobile network — opens the
same address. No private IP, no `localhost`, no second hostname, no separate
internal portal.

---

## 1. Architecture (after)

```text
                    ┌───────────────────────────────────────────┐
   LAN users        │  browser                                  │
   192.168.3.0/24   │  https://hastama.ir  (only address users  │
        │           │  ever type)                               │
        │           └───────────────┬───────────────────────────┘
        │                           │  DNS: Cloudflare anycast (proxied)
        │                           ▼
        │                ┌──────────────────────────┐
        │                │  Cloudflare edge         │
        └───────────────▶│  TLS, WAF, HTTP→HTTPS,   │◀── Internet users
   (same public URL,     │  www→apex 301, HSTS      │    (home / mobile)
    no split DNS)        └────────────┬─────────────┘
                                      │  outbound tunnel (no inbound port)
                                      ▼
                        ┌──────────────────────────────┐
                        │  cloudflared (Windows service│
                        │  "cloudflared", AUTO_START,  │
                        │  LocalSystem, token-based)   │
                        └──────────────┬───────────────┘
                                       │  http://127.0.0.1:5000
                                       ▼
                        ┌──────────────────────────────┐
                        │  uvicorn (Scheduled Task      │
                        │  "HastamaServer" at boot,     │
                        │  --host 127.0.0.1 --port 5000 │
                        │  --proxy-headers)             │
                        │  FastAPI app.main:app         │
                        └──────────────┬───────────────┘
                                       │ loopback only
                                       ▼
                        SQL Server Express 127.0.0.1:1433
```

Three rules keep the model simple:

1. **One name.** `hastama.ir` is the only address communicated to users.
2. **No inbound at the origin.** The tunnel dials out; TCP 5000/80/443 are never
   opened to the LAN or the Internet — with one deliberate, operator-toggled
   exception: *LAN access mode* (§9b), which exists so the laboratory can keep
   working while the internet link is down, and which is off by default. When the
   link really is down, *internet outage mode* (§9c) makes that visible instead of
   leaving users with a browser error page.
3. **The origin trusts only loopback.** `cloudflared` is the only direct client
   of port 5000, so forwarded headers are believed only from `127.0.0.1`.

---

## 2. Domain and DNS

| Item | Value / observed state |
|---|---|
| Public hostname | `hastama.ir` |
| `www` | `www.hastama.ir` → `301` → `https://hastama.ir/` (Cloudflare redirect) |
| Record type | Proxied Cloudflare records (orange cloud) |
| Resolved IPs (2026-09-27) | `188.114.97.3`, `188.114.96.3` (1.1.1.1), `104.21.70.164`, `172.67.137.180` (Shecan), `188.114.99.0`, `188.114.98.0` (LAN gateway) |
| IPv6 | AAAA answers present (`2a06:98c1:312x::`) — Cloudflare serves dual-stack at the edge |
| Origin IP exposure | None: no A/AAAA record points at the laboratory's public IP |

**Split DNS is not needed and is not configured.** Internal clients resolve
`hastama.ir` through the same public DNS chain as everybody else and reach
Cloudflare's edge; the tunnel then carries the request back to the LAN host.
Requirement 5 (one URL, two network contexts) is satisfied without any
per-network DNS behaviour.

*Known local quirk (not user-facing):* the server's own primary DNS
(`5.200.200.200`) does not answer from this host; the secondary
(`85.15.1.14`) and the LAN gateway `192.168.3.1` both resolve `hastama.ir`
correctly. Resolution therefore succeeds, with a short timeout before the
fallback. Fixing the primary resolver is an ISP/router task, not an application
problem.

---

## 3. Cloudflare

Already configured; **no Cloudflare change was made by this work** (no API token
is used by the application and the dashboard is not modified by scripts).

Observed and verified at the edge:

| Setting | Observed behaviour (live probe) |
|---|---|
| Proxy / CDN | `Server: cloudflare`, `CF-RAY`, `cf-cache-status: DYNAMIC` |
| Always Use HTTPS | `http://hastama.ir/` → `301` → `https://hastama.ir/` |
| `www` → apex | `https://www.hastama.ir/` → `301` → `https://hastama.ir/` |
| Minimum TLS | TLS 1.3 and TLS 1.2 accepted; TLS 1.1 refused (client reported *no protocols available*, edge negotiated 1.3) |
| Certificate | Let's Encrypt, `CN=hastama.ir`, SAN `*.hastama.ir, hastama.ir`, valid 2026-09-22 → 2026-12-21, `Verify return code: 0` |
| HTTP/3 | Advertised by the edge (`alt-svc: h3=":443"`) |
| WebSocket | Supported end-to-end (see §7) |

HSTS is emitted by **Hastama itself** (`strict-transport-security:
max-age=31536000; includeSubDomains`), so it survives a Cloudflare setting
change; enabling HSTS in the Cloudflare dashboard as well is optional and safe
(it is the same value).

### Recommended dashboard review (manual, one-off)

These are *recommendations*, deliberately not applied automatically. For each:
why, what changes, who it can affect, how to test.

| Setting | Why | Effect | Risk to Hastama | How to verify |
|---|---|---|---|---|
| SSL/TLS mode **Full (Strict)** | Edge↔tunnel is already TLS and terminates at Cloudflare; a downgrade to Flexible would be a misconfiguration | No change to a tunnel deployment | None | `curl -sI https://hastama.ir/login` still `200` |
| Always Use HTTPS **on** | One canonical HTTPS URL | `http://` → `301` | None | `curl -sI http://hastama.ir/` → `301` |
| Minimum TLS **1.2** | Drops outdated clients | Older Android 4.x/browsers fail | None for Windows/Chrome/mobile | `openssl s_client -tls1_1` fails, `-tls1_2` succeeds |
| HSTS **on** (dashboard level too) | Prevents downgrade for the whole zone | Browsers refuse plain HTTP | Low; irreversible-ish for subdomains — the app already sends the same header | Response header already present |
| WAF managed rules | Blocks common exploit traffic before it reaches FastAPI | Could block a legitimate admin payload if a custom rule is too broad | Medium — review before enabling | Test login + kiosk + print flows after enabling |
| Rate limiting rules on `/login` and `/api/registration/*` | Adds edge-level brute-force protection on top of the in-app limiter (RR-08) | Extra 429s under load | Low | `for i in $(seq 1 50); do curl -s -o /dev/null -w "%{http_code} " https://hastama.ir/login; done` |
| Cache rules | **Do not cache** dynamic responses | — | High if misconfigured (cached pages/sessions) | `cf-cache-status: DYNAMIC` on `/login` must stay `DYNAMIC` |
| Bot Fight Mode | Optional | May challenge automation/monitoring probes | Low | Uptime probe still `200` |
| Cloudflare Access | **Not recommended** — it would duplicate Hastama's own login | — | High if applied to the whole hostname | — |
| WebSocket support | Required for the TV display | Enabled by default on the free plan | None | `101 Switching Protocols` probe (see §7) |

---

## 4. Cloudflare Tunnel

| Item | Value (observed) |
|---|---|
| Service name | `cloudflared` ("Cloudflared agent") |
| Startup | `START_TYPE: AUTO_START` — starts on boot without a login |
| Account | `LocalSystem` |
| Binary / command | `"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel run --token-file C:\ProgramData\cloudflared\token` |
| Mode | Remotely managed (token) — **ingress and hostname mapping live in the Cloudflare dashboard, not in a local `config.yml`** |
| Origin target | `http://127.0.0.1:5000` (loopback) |
| Inbound ports needed | none |

Because the tunnel is token-managed, the ingress rule cannot be inspected or
edited from this repository. The required dashboard state (manual step):

```text
Cloudflare Zero Trust → Networks → Tunnels → (Hastama tunnel) → Public Hostnames
  hastama.ir       →  http://127.0.0.1:5000
  www.hastama.ir   →  http://127.0.0.1:5000      (optional: Cloudflare already 301s www)
Additional application settings:
  HTTP Settings → No TLS Verify: off   (origin is plain HTTP on loopback)
  Connection → Enable IPv4, Enable IPv6 (Cloudflare-side; origin stays IPv4)
```

**Reconnect behaviour:** `cloudflared` re-establishes its outbound connection
automatically after a Windows restart (AUTO_START service), an Internet
interruption and a Cloudflare-side blip; the origin application restarting does
not require any tunnel change (new requests simply retry against the same
loopback port). This was verified indirectly on 2026-09-27: the service had been
running since the 2026-09-23 boot while the application process was restarted
during the day, and `https://hastama.ir` answered immediately afterwards.

---

## 5. Windows server

Machine: `HASTAMA-SERVER` (`192.168.3.69/24`, gateway `192.168.3.1`, network
profile **Private**).

| Component | State |
|---|---|
| Auto-start of the application | Scheduled Task `\HastamaServer` — trigger **At system startup**, run as `hastama` (`LogonType: Password`, `RunLevel: HighestAvailable`), action `cmd.exe /c E:\Hastama\scripts\run_server.bat`, `MultipleInstancesPolicy: IgnoreNew`, no execution time limit |
| `run_server.bat` | waits for `MSSQL$SQLEXPRESS`, waits 30 s, then starts uvicorn; logs to `logs\hastama-autostart.log` |
| Manual start/stop | `scripts\start_server.bat` (`schtasks /Run`); `scripts\stop_server.bat` runs `scripts\stop_server.ps1`, which identifies the owning process before stopping it (a bare `schtasks /End` orphaned the python child, which kept the port and made the next start fail with `Errno 10048`) |
| Development instance | `scripts\run_dev.bat` — binds `127.0.0.1:5001`; **never** the production port |
| Auto-start toggle | `scripts\enable_autostart.bat` / `disable_autostart.bat` — both toggle `\HastamaServer` **and** `\HastamaWatchdog`, otherwise "auto-start disabled" would last five minutes |

**Mid-day recovery (RR-25, closed 2026-09-27):** the boot task alone would leave
the URL down if the Python process died during the day, so `\HastamaWatchdog`
now runs every 5 minutes and starts `\HastamaServer` again when the supervised
application is gone or no longer serving. It is silent while healthy and writes
one line per event to `logs\hastama-watchdog.log` (rotated above 2 MB).

The canonical statement of the two startup chains (`\HastamaServer` →
`scripts\run_server.bat` → uvicorn on `127.0.0.1:5000` for production, and
`scripts\run_dev.bat` on `127.0.0.1:5001` for development) lives in
[`docs/HASTAMA_PRODUCTION_DEPLOYMENT.md`](../HASTAMA_PRODUCTION_DEPLOYMENT.md)
under *Process supervision (the production start path)*.

**Port listening is not health (RR-28, 2026-09-28).** The first watchdog
implementation asked only "is `127.0.0.1:5000` listening?". A uvicorn process
started by hand from a VS Code terminal took the port over, ran without the
production flags and the UTF-8 environment, and died with its terminal: the
public URL returned `502` while the watchdog kept reporting the state as healthy.
The watchdog now applies four layers before it may call the deployment healthy:

| Layer | Question | Failure status |
|---|---|---|
| 1. Listener identity | Does the process owning the port run `uvicorn app.main:app --port 5000`? | `PORT_FOREIGN_OWNER` (foreign process is logged, **never** killed, no start attempted) |
| 2. Production configuration | Does its command line carry `--host 127.0.0.1 --port 5000 --proxy-headers --forwarded-allow-ips 127.0.0.1`? | `UNEXPECTED_PROCESS` |
| 3. Supervision | Does it descend from `scripts\run_server.bat`, or is it the instance writing `logs\hastama-autostart.log` (refreshed every second)? | `UNEXPECTED_PROCESS` |
| 4. Application health | Does `GET http://127.0.0.1:5000/health` answer `200` within 5 s (no proxy, no database)? | `APPLICATION_UNHEALTHY`, restart after 3 consecutive failures |

An identified Hastama process running outside supervision is logged with its
PID, executable, command line, ancestry and the missing evidence, then reclaimed:
the identified process tree is stopped and `\HastamaServer` is started again.
Restarts are capped at 3 per rolling 30 minutes (`RESTART_SUPPRESSED` afterwards)
so a crash loop cannot become a restart storm. Statuses written to the log:
`HEALTHY`, `APPLICATION_DOWN`, `PORT_FOREIGN_OWNER`, `UNEXPECTED_PROCESS`,
`UNEXPECTED_PROCESS_STOPPED`, `APPLICATION_UNHEALTHY`, `HEALTH_PROBE_ERROR`,
`RESTART_REQUESTED`, `RESTARTED`, `RECOVERY_CONFIRMED`, `RESTART_FAILED`,
`RESTART_SUPPRESSED`, `PROCESS_LOOKUP_FAILED`, `PUBLIC_HEALTH_OK|FAILED|NOT_VERIFIED`.

Two limits are part of the design and are not claimed away. **Detection is
periodic, not instantaneous:** an unsupervised instance or a hung application can
serve for up to one watchdog interval (5 minutes) before the next run reclaims
it, and the recovery itself then takes roughly 30–60 s (observed 31–36 s). **A
deliberate masquerade is possible:** a process started with all four production
flags *and* whose output is redirected into `logs\hastama-autostart.log` is
classified as the production instance; that requires administrative intent on the
server and is accepted as a residual exposure (RR-28).

The probe is deliberately dependency-free: `System.Net.Http` is not loaded in
Windows PowerShell 5.1, so `/health` is fetched with `HttpWebRequest` and an
explicit `Proxy = $null`. A failure of the *probe* itself (as opposed to the
application) is reported as `HEALTH_PROBE_ERROR` and never counts towards the
restart threshold, because the first version of this watchdog reported a healthy
application as unhealthy for exactly that reason.

---

## 6. Uvicorn and FastAPI

| Item | Value |
|---|---|
| Command | The command **executed by the production launcher** `scripts\run_server.bat` (invoked by the `\HastamaServer` task): `python -m uvicorn app.main:app --host 127.0.0.1 --port 5000 --proxy-headers --forwarded-allow-ips 127.0.0.1`. This is a description of what the launcher runs, **not** a start command to type. Production must **never** be started by hand on port 5000: start it with `scripts\start_server.bat` (which triggers `\HastamaServer`), and use `scripts\run_dev.bat` (`127.0.0.1:5001`) for development |
| Bind | **loopback only** (`127.0.0.1:5000`) — verified with `netstat -ano`: `TCP 127.0.0.1:5000 LISTENING`; no `0.0.0.0:5000` / LAN listener exists |
| Versions | uvicorn 0.23.2, FastAPI 0.141.1, Starlette 1.6.0 |
| Proxy trust | `--proxy-headers` with `--forwarded-allow-ips 127.0.0.1`; application-side `TRUSTED_PROXY_IPS` defaults to `127.0.0.1,::1` (`app/core/net.py`) |
| Host allow-list | `TrustedHostMiddleware` with `HASTAMA_ALLOWED_HOSTS` (default `hastama.ir,www.hastama.ir,localhost,127.0.0.1,::1,[::1]`) |
| HEAD requests | supported by `_HeadMethodMiddleware` (GET handler, body suppressed) so uptime monitors work |
| Sessions | Starlette `SessionMiddleware`: `SameSite=Lax`, `https_only=True` (Secure), HttpOnly |
| CSRF cookie | `csrf_token`: `Path=/`, `SameSite=Lax`, `Secure`, `Max-Age=28800` (readable by JS by design — double-submit) |
| Security headers | CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS on HTTPS responses |
| API docs | disabled in production (`/docs` → `404`) |

**Verified proxy behaviour:** a request to `127.0.0.1:5000/login` with
`X-Forwarded-Proto: https` (as `cloudflared` sends it) produced a `Secure`
cookie; the same request without the header did not. The application therefore
knows it is behind HTTPS and builds `https://` absolute URLs; `cloudflared`
preserves the original `Host`, which is why the WebSocket origin check accepts
`Origin: https://hastama.ir` (§7) and the allow-list matches.

`X-Forwarded-Host` is deliberately *not* honoured: the tunnel preserves the real
`Host`, and the allow-list validates it, so trusting a client-supplied host
header would only add attack surface.

---

## 7. WebSocket

Endpoint: `wss://hastama.ir/api/ws/call-display` (TV display and the
call-management preview; URL built in the browser as
`(https → wss) + location.host + /api/ws/call-display`).

Live probe (executed 2026-09-27, from inside the LAN through the public name):

```text
TLS + WebSocket upgrade with Origin: https://hastama.ir
  → HTTP/1.1 101 Switching Protocols
  → client frame "ping" → server frame {"type": "pong"}     PASS
Same handshake with Origin: https://evil.example
  → HTTP/1.1 403 Forbidden                                  PASS (CSWSH blocked)
Same handshake with Host: evil.example (loopback, after this change)
  → HTTP/1.1 400 Invalid host header                        PASS
```

---

## 8. Server-Sent Events

Endpoint: `/api/notifications/stream` (used by `app/static/js/notification-system.js`
via a relative `EventSource` URL — same origin, no hard-coded host).

| Probe | Result |
|---|---|
| `curl -N https://hastama.ir/api/notifications/stream` (no session) | `401 Unauthorized` **from the application** (`content-type: application/json`, security headers present) — the edge routes and streams the request correctly |
| Response headers set by the app for the authenticated stream | `text/event-stream`, `Cache-Control: no-cache, no-store, must-revalidate`, `X-Accel-Buffering: no` |
| Local endpoint behaviour | covered by `tests/test_notification_system.py` |

The authenticated, logged-in round trip (login → dashboard → notification
arrives over SSE) is on the manual checklist in §13 — it needs real user
credentials, which this work deliberately did not create or use. What *was*
proved from outside the browser is the transport: the `wss://` upgrade returns
`101` through Cloudflare and the SSE endpoint answers the app's `401` rather
than a Cloudflare error, so the edge is not blocking either protocol.

---

## 9. Firewall and origin exposure

All three Windows Firewall profiles are **ON** with `BlockInbound, AllowOutbound`.

| Rule | Action | Profile | Scope |
|---|---|---|---|
| `Hastama - Block Uvicorn 5000 (Inbound)` | Block | Any | any → TCP 5000 |
| `Hastama - Block SQL Server 1433 (Inbound)` | Block | Any | any → TCP 1433 |
| `Hastama - Block SMB 445 (Internet)` | Block | Any | Internet → TCP 445 |
| `Hastama - Block RDP 3389 (Internet)` / `… UDP` | Block | Any | Internet → 3389 |
| `Hastama HTTPS LAN` | **Allow** | Private | `192.168.3.0/24` → **TCP 443** |

When LAN access mode is in use (§9b) the block rule for port 5000 is disabled and
one allow rule is added — `Hastama - Allow Uvicorn 5000 (LAN)`, `RemoteAddress`
restricted to the local subnet, `Domain,Private` only. Both changes are made, and
undone, by `scripts\lan_access_firewall.ps1`.

Findings:

* **No port forwarding / NAT rule exists on the host** (`netsh interface portproxy
  show all` → empty). Nothing in Windows forwards public traffic to 5000.
* Port 5000 is loopback-bound *and* block-listed: a LAN client cannot reach it
  (verified: the listener is `127.0.0.1:5000`, not `192.168.3.69:5000`).
* **`Hastama HTTPS LAN` is dead weight:** no process listens on TCP 443 on this
  host (left over from the retired Caddy LAN-HTTPS plan). Recommended manual
  action: delete it so the host publishes no allow-rule for a closed port.
* Third-party installer rules (`python.exe`, Teams, VS Code, …) with
  `RemoteAddress=Any` widen the host posture; they do not expose the app port
  (it is loopback-bound and block-listed). Cleaning them is an ops task — RR-24.
* The router's public reachability of 5000/3389 cannot be inspected from the
  host. The architecture does not rely on the router blocking anything: the
  origin publishes nothing, and public traffic arrives only through the tunnel.
  If an administrator wants proof from outside, run a port scan of the
  laboratory's public IP from an off-site network (see §13).

---

## 9b. LAN access mode — the toggled offline fallback

**Default state: off.** With it off, this document describes the system exactly
as it was: nothing listens on the LAN, and TCP 5000 is block-listed.

When the internet link (and therefore Cloudflare and the tunnel) is down, an
operator can open a **second, in-process listener** on this machine's laboratory
address so the workstations keep using the system:

```text
LAN workstation
   http://<lan-address>:5000      <- app/services/lan_access.py (toggle)
        |
        v
   127.0.0.1:5000                 <- uvicorn, unchanged
```

### What the listener is

* **A byte transparent TCP relay**, not a second application server. uvicorn
  keeps binding loopback; Server-Sent Events (`/api/notifications/stream`) and
  the call-display WebSocket (`/api/ws/call-display`) pass through untouched,
  which is exactly what the laboratory screens need.
* **Plain HTTP on purpose.** No public CA issues a certificate for a private
  address, so the LAN origin has no TLS. The session cookie therefore loses its
  `Secure` flag **for LAN requests only** (`_LanCookieMiddleware`); every request
  over `https://hastama.ir` and over loopback keeps it. See RR-21 / RR-29.
* **The client address is proved, not trusted.** The relay strips every
  forwarding header (`X-Forwarded-For` / `-Proto` / `-Host` / `Forwarded` /
  `X-Real-IP` …) and adds one `X-Forwarded-For` with the real socket peer;
  uvicorn `--proxy-headers` (trusting `127.0.0.1`) then reports the real LAN
  address to rate limiting and the audit log. A client can neither forge its
  address nor claim `X-Forwarded-Proto: https`.
* **One request per connection.** The relay answers the head it sanitised and
  asks the origin for `Connection: close`; a keep-alive request would reach the
  application without that sanitising. WebSocket upgrades keep `Upgrade`.
  Requests that cannot be parsed are answered `400`, never forwarded.
* **The `Host` allow-list grows only while it runs.** The LAN address is added to
  the live `TrustedHostMiddleware` list on start and removed on stop; a wildcard
  never appears.
* **No automatic time-out.** The mode stays on until an operator switches it off.
  That is an explicit decision (RR-29).
* **Audited.** Enabling, disabling and the built-in self test are written to
  `dbo.admin_actions` (`enable_lan_access` / `disable_lan_access` /
  `lan_access_selftest`) with the administrator name and address.

### Turning it on (once)

| # | Step | Command / place |
|---|---|---|
| 1 | Open the loopback *only to the local subnet* on the firewall (administrator, once) | `scripts\enable_lan_firewall.bat` (or `scripts\lan_access_firewall.ps1 -Action enable`) |
| 2 | Switch the listener on | `master-admin` → **system settings** → *دسترسی از شبکه داخلی* |
| 3 | Read the address shown on the card (for example `http://192.168.3.69:5000`) and open it from a workstation | a second machine on the same subnet |

Step 1 disables the Hastama block rule for port 5000 and adds
`Hastama - Allow Uvicorn 5000 (LAN)` with `RemoteAddress=LocalSubnet` and the
`Domain,Private` profiles; nothing is exposed to the Internet and no rule that
does not belong to Hastama is changed. The rule names it disabled are remembered
in `logs\lan-access-firewall.json`, so `-Action disable` is an exact undo
(and `-Action status` prints a read-only report without administrator rights).

Step 1 is what makes the port reachable; step 2 is what makes anything listen.
While the switch is off, an allowed packet finds no listener and is answered with
a reset — the in-app switch is the effective control, and the firewall is opened
once rather than on every toggle.

### Verifying it

* **Relay path** (from the server): the card's *تست مسیر* button, or
  `POST /master-admin/api/lan-access/selftest`. It fetches `/health` through the
  LAN listener from this machine, which proves the listener, the rewritten head,
  the host allow-list and the application — but **not** the firewall, because
  host-local traffic never traverses it.
* **Real path** (from a workstation on the same subnet): open the address printed
  on the card and log in. This is the only test that covers the firewall rule.
* **Machine state:** `GET /master-admin/api/lan-access` returns `enabled`,
  `running`, `address`, `url`, `expected_url`, `connections`, `total_connections`,
  `started_at` and `last_error`.

### Turning it off

1. Switch the listener off in the same card (immediate, no restart).
2. Optionally run `scripts\disable_lan_firewall.bat` to remove the allow rule and
   restore the original block rule.

### Persistence and recovery

The choice is stored as the `system_config` key `lan_access_enabled` and is
re-applied by the application's startup hook, so a reboot during an outage keeps
the fallback available. If the address could not be bound (the machine changed
network), the card shows the error and nothing is listening — it never fails
open.

---

## 9c. Internet outage mode — the page users see when the link is gone

**Default state: armed** (`outage_page_enabled` is on when the row is missing);
it only ever acts when the probes report a real outage. Managed in
`master-admin` → **system settings** → *صفحهٔ قطعی اینترنت*.

Users cannot be told anything by a server they cannot reach, so the mode has two
halves:

| Case | What happens |
|---|---|
| **The server lost the internet** (and with it the tunnel) | The outage monitor declares the outage, the active sessions are cut, and every page request that did not come from the laboratory network is answered with the outage page (`503` + `Retry-After: 30`). API callers get JSON (`503`). |
| **The user's own link died** | The browser cannot reach the server at all — the service worker (`/sw.js`, registered by `/static/js/offline-guard.js`) answers the failed page load from its cache with the same outage page, and an overlay shows it immediately while a session is open. |

```text
                 outage monitor (app/services/outage.py)
                 probes every N seconds: TCP 443 to
                 hastama.ir / 1.1.1.1 / 8.8.8.8
                          |
        N consecutive failures           2 consecutive successes
                          v                          ^
                 OUTAGE MODE ON  ----------------->  ON -> OFF
                 - sessions cut (master admins kept)
                 - pages -> offline.html (503)
                 - audited: internet_outage_detected / _recovered
```

### What the outage page is

`app/templates/offline.html`, served at `/offline` (always `200`, so the worker
can cache it) and rendered as the body of every blocked page request (`503`).  It
is **self contained on purpose** — no stylesheet, font, image or script from
anywhere else — because when it is needed the network is exactly what is missing.
It carries:

* the probe/connection reason, decided in the browser (`navigator.onLine` plus a
  quiet `/health` poll);
* the **laboratory address** of this server when LAN access mode is available
  (§9b), injected by the server so no address is ever hard coded;
* a *copy address* button, and an automatic return to the system as soon as
  `/health` answers again.

### What is never blocked (the mode stays manageable)

* requests that arrived on the **laboratory listener** — the internal network
  keeps working through an outage, which is the point of §9b;
* **direct local** requests: loopback *without* forwarding headers, i.e. the
  watchdog's `GET /health`, the printer side, an administrator on the server.
  A public user always arrives through `cloudflared` on loopback **with**
  `X-Forwarded-For`/`-Proto`, so "local" cannot be claimed by rewriting a header;
* an authenticated **master administrator** session (and its session is not cut),
  so the mode can always be switched off again;
* `/static/`, `/offline`, `/sw.js`, `/health`, `/robots.txt`, `/sitemap.xml`,
  `/favicon.ico`;
* WebSocket upgrades are refused with a clean `503` (not left hanging).

### Settings (all editable at runtime, no restart)

| Key | Meaning | Default |
|---|---|---|
| `outage_page_enabled` | master switch for the whole mode | **on** |
| `outage_probe_interval_seconds` | seconds between probes (5–3600) | 30 |
| `outage_probe_failures` | consecutive failures that declare an outage (1–20) | 3 |
| `outage_probe_targets` | `host:port` list; **the first reachable target means online** | `hastama.ir:443, 1.1.1.1:443, 8.8.8.8:443` |
| `outage_terminate_sessions` | cut the users' sessions when the outage starts | on |
| `outage_show_lan_address` | show the laboratory address on the page | on |
| `outage_title`, `outage_message` | the text the users read | see the code constants |
| `outage_manual` | declare the outage by hand (planned maintenance) | off |

The probe is **TCP only** (raw `socket`, in a worker thread), so the machine's
`HTTPS_PROXY` settings cannot influence the verdict and a DNS failure counts as an
outage.  Recovery needs two consecutive successes so a flapping link does not
open and close the system repeatedly.

### Verifying / operating it

* Card buttons: **پایش همین حالا** probes immediately; **اعلام دستی قطعی** forces
  the mode regardless of the probes (and cuts sessions if that switch is on).
* `GET /master-admin/api/outage` returns `enabled`, `active`, `manual`,
  `monitoring`, `probe_online`, `checked_at`, `detail`, `failures`, `threshold`,
  `interval_seconds`, `targets`, `since`, `terminated_sessions`.
* Both transitions and every settings change are audited (`audit_logs`:
  `internet_outage_detected` / `internet_outage_recovered`; `admin_actions`:
  `update_outage_settings`, `outage_probe_test`, `enable_outage_manual`,
  `disable_outage_manual`).
* The service worker only touches **top level navigations**, and only when the
  network fails — while the connection is up nothing is served from a cache.

---

## 9d. Iran-only access — a VPN is not a way in

The public URL accepts **Iranian addresses only**. A client whose public address
is not inside a registered Iranian range is answered with `403` and the warning
page `app/templates/vpn-warning.html`, which tells the user to switch the VPN
(proxy / filter-breaker) off and come back — the log-in form would be useless to
them. Internal addresses are never affected, so the server, the laboratory LAN
and the LAN fallback of §9b keep working exactly as before.

### Where the verdict comes from

The Iranian address space ships **with the application** as
`app/data/iran_ip_ranges.txt` — 1599 IPv4 + 571 IPv6 ranges, ~42 KB, generated
from the RIPE and APNIC *delegated statistics* (only records whose country is
`IR` and whose status is `allocated` or `assigned`). It is parsed once into
sorted `(start, end)` integers and searched with a binary search, so a verdict
costs no network round trip, no external service and no per-request latency:

* the answer is identical while the internet is down — which is precisely when
  it must still be correct (§9c);
* no visitor address is ever handed to a third party (no geo-IP API);
* there is no rate limit to hit and no dependency to fail.

| Client address | Verdict | What happens |
|---|---|---|
| Inside an Iranian range | `iran` | allowed |
| Public, outside every range | `foreign` | `403` + the VPN warning page (JSON for API paths, refused WebSocket upgrade) |
| Loopback / laboratory LAN / CGNAT / link-local | `internal` | allowed — never filtered |
| Not an address at all | `unknown` | allowed, and logged; a broken peer value is an infrastructure bug, not a user signal |

Refreshing the list is the **only** network call in the feature, and it is never
automatic: the card's **به‌روزرسانی فهرست آی‌پی** button (`POST
/master-admin/api/iran-access/refresh`) or, on the server, `python
scripts\refresh_iran_ip_ranges.py` (equals ~30 MB from `ftp.ripe.net` and
`ftp.apnic.net`, downloaded **without** the machine's proxy variables so a VPN
on the server cannot decide what we ship). If the range file cannot be read the
filter reports *armed but blind* on the card and stops rejecting anything — it
must never turn into a silent lockout.

### What is never blocked (the filter stays manageable)

* **internal** addresses — the watchdog's `/health`, the printer side, an
  administrator on the server, every client of the LAN listener (§9b) and CGNAT
  subscribers (several Iranian ISPs use `100.64.0.0/10`);
* an authenticated **master administrator** session, so the switch can be turned
  off from a machine whose own VPN is on;
* `/iran-only` (the guide itself) and `/iran-only/check` (the caller's own
  verdict, used by the page to return on its own), plus `/health`, `/static/`,
  `/offline`, `/sw.js`, `robots.txt`, `sitemap.xml`, `favicon.ico`.

A client cannot buy an entry with a header: the address comes from
`app.core.net.client_ip_from_scope`, the same single implementation the rest of
the application uses, which reads `X-Forwarded-For` **only** from a trusted peer
and then only its last parsable entry. The gate is therefore added *inside* the
outage gate and outside the host check, the session registry and every database
layer.

### Settings (all editable at runtime, no restart)

`master-admin → system settings → فقط آی‌پی ایران`:

| Setting | Key | Default |
|---|---|---|
| The filter itself | `iran_only_enabled` | **on** (a missing row means on) |
| Warning title / message / VPN-off steps | `iran_only_title` / `iran_only_message` / `iran_only_help` | the shipped Persian text |
| Write a block to the audit log | `iran_only_log_blocked` | on |

### Verifying / operating it

* **تست یک آی‌پی** on the card: type any address (or leave it empty to test the
  caller's own) and the answer names the classification, the matching range and
  whether that address would be refused right now.
* `GET /master-admin/api/iran-access` returns `enabled`, `enforcing`,
  `list_loaded`, the range counts, the list version and path, `blocked_count`,
  `allowed_count`, `last_blocked_ip`, and the editable texts.
* Blocks are counted, and audited (`iran_only_blocked`) **at most once per
  address per 5 minutes** so a hostile loop cannot flood `audit_logs`; the switch,
  the texts, the refresh and the counter reset are audited as admin actions.
* A list refresh that fails leaves the previous file untouched and reports the
  reason on the card — the filter keeps working from the old list.

---

## 9e. The login page — a loader instead of a busy button, and a CAPTCHA that says when it died

``https://hastama.ir/login`` is the first thing every user sees, so its feedback
is a setting rather than a hard-coded behaviour
(`app/services/login_experience.py`, edited in *master-admin → system settings*).

### Pressing ورود

The button keeps one label. There is no *"در حال ورود…"* caption and no green
success state any more — that whole sequence was removed from
``app/templates/login.html`` and ``app/static/js/script.js``. Instead:

* the page covers itself with a branded loader (`#loginLoader`) — a spinning
  brand ring, a title, the visitor's name, a progress bar that fills over the
  configured duration and a rotating status line;
* the request to ``/login_user`` leaves **immediately**; the loader is a
  *minimum display time*, not a delay in front of the server call;
* once the answer is in **and** the minimum has passed, the browser goes to the
  page that was requested. A failure lowers the loader exactly the same way and
  then shows the real message (the card shakes, the hint under the button fills);
* keyboard entry (Enter) goes through the same path, so the behaviour cannot
  differ between the mouse and the keyboard.

| Setting | Key | Default | Bounds |
|---|---|---|---|
| Loader on/off | `login_loader_enabled` | on | — |
| Loader duration | `login_loader_seconds` | 3 s | 1–15 s |
| Loader title / message | `login_loader_title` / `login_loader_message` | the shipped Persian text | 80 / 200 chars |

### The CAPTCHA lifetime

A code lives for a configurable number of seconds and the page now behaves as if
it knows that:

* it polls ``GET /captcha/status`` (every 5 s, no database work) and shows a
  countdown in the last 60 seconds;
* when the code dies it prints *«کد امنیتی منقضی شده است؛ لطفاً روی «کد جدید»
  بزنید و دوباره وارد شوید.»*, marks the field and pulses the refresh button;
* pressing ورود with a known-dead code is refused **without** a request (and
  without the loader), and the state clears itself within one poll interval once
  a new code exists — including a refresh done in another tab;
* a submission the server rejects is answered with `captcha_error`, and now also
  `captcha_expired` and `captcha_reason` (`expired` / `mismatch` / `missing` /
  `missing_input`). An expired code refreshes the image **and keeps the message on
  screen** — the old helper erased it at the end, which is why an expired code
  used to look like nothing had happened at all.

| Setting | Key | Default | Bounds |
|---|---|---|---|
| Code lifetime | `login_captcha_ttl_seconds` | 180 s | 30–1800 s |
| Early warning + expiry message | `login_captcha_notice` | on | — |

Both the loader and the notice respect ``prefers-reduced-motion``. Changing any
of these values takes effect on the next login-page load (no restart); the page
reads them from the public ``/api/system-config`` endpoint, which only ever
publishes these non-secret keys.

---

## 10. HTTPS, redirects and canonical URLs

| Request | Live result |
|---|---|
| `GET https://hastama.ir/` | `301`, `Location: /login` (host-relative — cannot leak `http://`) |
| `GET https://hastama.ir/login` | `200` |
| `GET http://hastama.ir/` | `301` → `https://hastama.ir/` → `/login` |
| `GET https://www.hastama.ir/` | `301` → apex |
| `HEAD https://hastama.ir/health` | was `405`, now `200` — verified live on the running process after the 2026-09-27 restart |
| `/docs`, `/openapi.json` | `404` in production |
| `/robots.txt`, `/sitemap.xml` | `200`, advertise only `https://hastama.ir` |

Application-generated URLs: `robots.txt`/`sitemap.xml` carry the canonical
`https://hastama.ir`; redirects are host-relative; static assets, API calls,
WebSocket and SSE URLs are all same-origin. There are **no** hard-coded LAN
addresses, no `ws://` literals and no `http://` canonical links in `app/`
(enforced by `tests/test_network_url_policy.py`).

---

## 11. Recovery after restart

| Scenario | Behaviour |
|---|---|
| Windows restart | `cloudflared` service (AUTO_START) + `HastamaServer` task (boot trigger) start automatically; SQL wait loop in `run_server.bat` avoids racing SQL Server |
| Internet interruption | Tunnel reconnects on its own; users keep the same URL and simply retry |
| `cloudflared` restart | Loopback origin is unaffected; requests resume as soon as the tunnel is back |
| Application restart | No DNS, no firewall and no Cloudflare change needed — the tunnel dials `127.0.0.1:5000` again |
| Browser refresh / session expiry | Session cookie is 8 h; on expiry the app redirects to `/login` on the same hostname |
| WebSocket drop | Clients reconnect with backoff (`call-display.js`, `call-system-standalone.js`) and re-register their tag |
| SSE drop | `notification-system.js` re-opens the stream; the app replays events after `Last-Event-ID` |
| DNS cache | TTL is Cloudflare-controlled; the answer is anycast, so a stale entry still points at Cloudflare |

### Measured recovery (2026-09-27)

> **Historical observation.** The rows below record what was measured on
> 2026-09-27 with the **first, port-only** watchdog implementation. The two
> watchdog rows no longer describe the current behaviour: since 2026-09-28 the
> layered model in §5 applies and the log statuses have changed, so those rows
> are kept as the record of that implementation only, not as a description of
> the watchdog in the repository today.

| Action | Observed result |
|---|---|
| `taskkill` the running uvicorn, then `schtasks /Run /TN HastamaServer` | `127.0.0.1:5000` accepted connections again in ~25 s (SQL wait loop + start), and `https://hastama.ir/health` returned `200` on the next probe; no DNS, firewall or Cloudflare change was needed |
| Watchdog, healthy host (`-Port 5000`) *(port-only implementation, historical)* | `exit 0`, **no** log line written (stays quiet when nothing is wrong) |
| Watchdog, dead port (`-Port 5999`, dummy task) *(port-only implementation, historical)* | Wrote `127.0.0.1:5999 is not listening - starting scheduled task …`, invoked `schtasks /Run`, re-probed, logged `still down after …s` and returned `1` |
| Boot log rotation | The 271 MB `logs\hastama-autostart.log` was moved to `.log.1` on the restart and a fresh 9 KB log was opened |

---

## 12. Verification executed on 2026-09-27

Unattended, from the laboratory server itself (inside the LAN, so this is the
internal path; the external path is item 2 of the manual checklist below). Every
row was actually run — results are reported as observed, not as expected.

| # | Test | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | `GET https://hastama.ir/health` | `200` | `200` | PASS |
| 2 | `GET https://hastama.ir/login` | `200` | `200` | PASS |
| 3 | `GET https://hastama.ir/` | `301` → `/login` | `301`, `Location: /login` | PASS |
| 4 | `GET http://hastama.ir/health` | redirect to HTTPS | `301`, `Location: https://hastama.ir/health` | PASS |
| 5 | `GET https://www.hastama.ir/health` | permanent redirect to apex | `301` → apex | PASS |
| 6 | TLS certificate | valid for `hastama.ir`/`www` | verified (`ssl_verify_result=0`) | PASS |
| 7 | Response headers | HSTS + security headers | `strict-transport-security: max-age=31536000; includeSubDomains`, `x-content-type-options`, `x-frame-options`, `content-security-policy` | PASS |
| 8 | Redirect target policy | no private host, no `http://` | target is host-relative (`/master-admin/dashboard`) | PASS |
| 9 | WebSocket through Cloudflare | `101 Switching Protocols` | `HTTP/1.1 101` on `wss://hastama.ir/api/ws/call-display` (raw TLS upgrade, no JS library in the path); a masked client frame was accepted and the origin answered with a text frame on the same socket | PASS |
| 10 | SSE endpoint through Cloudflare | reachable, `401` without a session | `401` (`application/json`) — the stream itself is session-guarded | PASS |
| 11 | CSRF on the public login POST | token-less POST rejected | `403`, no cookie issued | PASS |
| 12 | Host-header policy on the origin | unknown `Host` refused | `Host: evil.example.com` → `400`; `Host: hastama.ir` → `200` | PASS |
| 13 | `HEAD` support | `200` (was `405`) | `HEAD /health` → `200` | PASS |
| 14 | Origin binding | loopback only | `netstat`: `127.0.0.1:5000 LISTENING` (nothing on `0.0.0.0`) | PASS |
| 15 | Inbound 5000 from the LAN | blocked | one rule on port 5000 and it is `Block`; app is unreachable as `192.168.3.69:5000` | PASS |
| 16 | DNS from the LAN resolver (`5.200.200.200`) | Cloudflare IPs | `hastama.ir` → `188.114.9x.x` (Cloudflare anycast, not the origin) | PASS |
| 17 | Tunnel + service | running, automatic | `cloudflared` service `RUNNING`, `START_TYPE: AUTO_START` | PASS |
| 18 | Application restart recovery | URL recovers with no admin action | back in ~25 s, `https://hastama.ir/health` `200` | PASS |
| 19 | Watchdog (up branch) | silent no-op | `exit 0`, no log line | PASS |
| 20 | Watchdog (down branch) | triggers the boot task | logged the intervention and invoked `schtasks /Run` | PASS |
| 21 | Boot log bound | rotate + keep one file | 271 MB → `.log.1`, new log 9 KB | PASS |
| 22 | Test suite | no new failures | `511 passed, 13 failed, 4 skipped` — the same 13 pre-existing failures as before this work | PASS |

Not executed here (needs a human, a second network or credentials) — see §13.

---

## 13. Manual verification checklist (by the administrator)

Everything below needs a human, a second network or credentials — it is *not*
claimed as passed by this work.

1. **Authenticated SSE round trip.** Sign in at `https://hastama.ir`, open the
   dashboard with DevTools → Network: confirm `GET /api/notifications/stream`
   stays `pending` (200, `text/event-stream`) and receives `: heartbeat` lines;
   publish a test notification from the admin console and watch the event
   arrive without a reload.
2. **Off-LAN test.** From a home/mobile connection (mobile data is ideal, since
   it bypasses the laboratory entirely) open `https://hastama.ir`, log in,
   upload/download a file, open the call-management page and confirm the TV
   preview connects (`101` in DevTools → WS).
3. **Printer path from off-LAN.** Server-side printing still works (the
   print spooler is on the server), and the browser fallback print window opens
   from the same hostname.
4. **Public port scan.** From an off-site network, scan the laboratory's public
   IP for TCP `5000`, `80`, `443`, `3389`: all must be closed/filtered. This is
   the only way to prove the router has no port-forward.
5. **Failover drill (repeat after any infrastructure change).** `schtasks /End /TN HastamaServer` then `schtasks /Run /TN HastamaServer`; `https://hastama.ir` must recover within ~60 s (SQL wait + startup) without any user action. The 2026-09-27 run is recorded in §12; the deliberate *kill-and-wait-for-the-watchdog* drill is still worth doing once during a maintenance window.
6. ~~Delete the dead LAN-443 firewall rule~~ **Done 2026-09-27**: `Hastama HTTPS LAN` no longer exists; the only remaining rules for this application are their `Block` counterparts.
7. ~~Consider the watchdog task~~ **Done 2026-09-27; health model replaced 2026-09-28.** `\HastamaWatchdog` runs every 5 minutes as `SYSTEM` (`scripts\watchdog_server.ps1`, `scripts\install_watchdog.ps1`). It originally re-triggered `\HastamaServer` when the loopback port stopped listening; it now identifies the process owning the port, requires the production flags and supervision evidence, probes `GET /health`, and reclaims the port only from an *identified* Hastama process (§5). Development runs on `scripts\run_dev.bat` (`127.0.0.1:5001`).

---

## 14. Troubleshooting

| Symptom | First checks |
|---|---|
| `502 / 1033` from Cloudflare | `sc query cloudflared`; `netstat -ano \| findstr :5000` (must show `127.0.0.1:5000 LISTENING`); `logs\hastama-autostart.log` |
| Site reachable outside but not inside | LAN client DNS: `nslookup hastama.ir` must return Cloudflare IPs; gateway DNS `192.168.3.1` is known-good; do **not** add a local A record to the private IP |
| `400 Invalid host header` | The request used a hostname outside `HASTAMA_ALLOWED_HOSTS`. Add it deliberately to `.env` if a staging name is needed, then restart |
| `301` loops between `www` and apex | Cloudflare redirect rule plus `HASTAMA_ALLOWED_HOSTS` must agree on the canonical apex |
| Login reloads to `/login` | Cookie rejected: check the request is HTTPS and the app sees `X-Forwarded-Proto: https` (uvicorn `--proxy-headers`, `TRUSTED_PROXY_IPS`) |
| WebSocket stuck "connecting" | `Origin` must match the hostname (browser same-origin); Cloudflare WebSockets must be enabled; `MAX_WS_CONNECTIONS` not exhausted |
| SSE not updating | `/api/notifications/stream` must return `200` and stay open; check `X-Accel-Buffering`/no-store headers survive the edge; `cf-cache-status` must be `DYNAMIC` |
| Redirects point at `http://…` | Something generates absolute URLs from the scheme: verify the proxy flags (`scripts\run_server.bat`) and that no code hard-codes `http://hastama.ir` (`tests/test_network_url_policy.py` fails if it does) |
| Slow first response | Server-side DNS: primary `5.200.200.200` times out before the secondary answers |
| Tunnel up but nothing served | Tunnel ingress in the dashboard (public hostname → `http://127.0.0.1:5000`) |

Logs to inspect (no secrets are logged): `logs\hastama-autostart.log`
(rotated to `.log.1` above 50 MB, so a fresh restart always starts a small
file), `logs\hastama-watchdog.log` (one line per intervention, silent while
healthy), `logs\` application logs, `eventvwr.msc` → Windows Logs →
Application → `cloudflared`, `C:\ProgramData\cloudflared\` (service token file —
never copy it into the repository or a ticket).

---

## 15. Secrets

| Secret | Where it lives | Rule |
|---|---|---|
| Cloudflare tunnel token | `C:\ProgramData\cloudflared\token`, read by the `LocalSystem` service | Never commit, never paste into chat or tickets |
| `SECRET_KEY` / `HASTAMA_HMAC_SECRET` / `ARAZ_*` | server-only `.env` (git-ignored) | Never commit; rotate through the secret-management process |

No Cloudflare credential is read by the application and no Cloudflare API token
is required for the architecture described here.

---

## 16. خلاصهٔ فارسی

- تنها نشانی رسمی سامانه `https://hastama.ir` است و برای همه — داخل آزمایشگاه
  و خارج از آن — همین یک نشانی کار می‌کند؛ هیچ IP داخلی یا نام دوم به کاربر
  گفته نمی‌شود.
- مسیر: مرورگر ← Cloudflare ← تونل Cloudflare (`cloudflared` روی همین سرور) ←
  `127.0.0.1:5000` ← FastAPI. پورت ۵۰۰۰ فقط روی loopback گوش می‌دهد و در
  فایروال هم مسدود است.
- تونل به‌صورت سرویس ویندوز با شروع خودکار بالا می‌آید و برنامه با
  Scheduled Task «HastamaServer» در بوت اجرا می‌شود.
- اگر برنامه وسط روز از کار بیفتد، تسک نگهبان (`\HastamaWatchdog`، هر ۵ دقیقه،
  SYSTEM) خودش آن را بالا می‌آورد (RR-25 بسته شد).
- قانون فایروال ۴۴۳ بی‌استفاده حذف شد (RR-26) و لاگ راه‌اندازی هم محدود شد
  (RR-27: بالای ۵۰ مگابایت به `.log.1` می‌چرخد).
- آنچه هنوز آزمایش دستی لازم دارد: یک‌بار ورود و باز کردن داشبورد از بیرون
  شبکه (SSE با نشست واقعی)، اسکن پورت‌های عمومی از اینترنت، و یک‌بار تمرین
  «کشتن برنامه و انتظار برای نگهبان». (فهرست کامل در §۱۳.)
