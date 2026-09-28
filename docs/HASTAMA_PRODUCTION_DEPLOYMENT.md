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
uvicorn app.main:app  (Scheduled Task "HastamaServer" at boot)
        |
        +-- SQL Server Express 127.0.0.1:1433 (loopback)
        +-- notification inbox / SSE stream, APScheduler maintenance jobs
        +-- label printer on the server (spooler)
```

FastAPI binds to `127.0.0.1:5000`. Nothing binds to the LAN interface, and no
inbound port is opened for users.

## Process supervision (the production start path)

**The production application is started only by the `\HastamaServer` scheduled
task**, which runs `cmd.exe /c E:\Hastama\scripts\run_server.bat`. That script
waits for SQL Server, rotates the boot log, sets `PYTHONUTF8=1` and
`PYTHONIOENCODING=utf-8`, and starts uvicorn with
`--host 127.0.0.1 --port 5000 --proxy-headers --forwarded-allow-ips 127.0.0.1`.

| Intent | Correct command |
|---|---|
| Start a stopped production instance | `scripts\start_server.bat` (triggers the task) |
| Stop the production instance | `scripts\stop_server.bat` (identifies the process, then stops it) |
| Development / debugging | `scripts\run_dev.bat` — binds **127.0.0.1:5001**, never published |
| Disable restart behaviour for a maintenance window | `scripts\disable_autostart.bat` (disables the server **and** the watchdog task) |

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
| Hastama app | Scheduled Task `\HastamaServer`, trigger **At system startup**, runs `scripts\run_server.bat` as the service account | Waits for `MSSQL$SQLEXPRESS`, then starts uvicorn on `127.0.0.1:5000` |
| Watchdog (optional) | Scheduled Task `\HastamaWatchdog`, every 5 minutes, SYSTEM | Only triggers `\HastamaServer` when `127.0.0.1:5000` is not listening |

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
  `scripts\run_server.bat` and that no code hard-codes an `http://` canonical URL.
- Scheduled notification delayed: inspect scheduler logs and `scheduled_at`.
