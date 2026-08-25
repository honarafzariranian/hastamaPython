# Hastama Production Deployment

## Scope

This document describes the supported Windows LAN topology. It does not claim that
Chrome or a second LAN client has been tested automatically; those remain manual
acceptance tests.

## Architecture

```text
LAN Chrome clients
        |
        | HTTPS 443 / internal DNS name
        v
Caddy Windows service
        |
        | HTTP loopback
        v
FastAPI/Uvicorn Windows service
        |
        +-- SQL Server / existing databases
        +-- notification inbox and push subscriptions
        +-- APScheduler maintenance jobs
```

FastAPI must bind to `127.0.0.1:8000`. Only Caddy binds to LAN TCP 443.

## Prerequisites

- Python 3.11+ and the project `.venv`.
- SQL Server and ODBC Driver 17.
- Caddy installed and available to the service account.
- NSSM approved and installed if using the provided service scripts.
- VAPID values in the server-only `.env` file.
- Internal DNS and an internal CA certificate for a no-client-touch deployment.

## Install and configure

```powershell
uv sync --dev
caddy validate --config .\Caddyfile --adapter caddyfile
```

For the current development/LAN setup, `Caddyfile` uses `tls internal`. Each unmanaged
client must trust Caddy's root CA once. For enterprise deployment, use an internal CA
certificate whose SAN exactly matches the DNS name, and deploy the CA trust through
Active Directory Group Policy, Intune, or equivalent endpoint management.

## DNS and certificate

Preferred production name:

```text
hastama.apps.example.internal  A  <server-LAN-IP>
```

Configure DHCP/domain policy so clients use the internal DNS server. Issue a certificate
with a SAN for exactly that hostname. Do not use an IP address in the browser URL and do
not use `--ignore-certificate-errors`.

## Windows services

The scripts use NSSM and must be run from an elevated PowerShell prompt after reviewing
the paths and service account:

```powershell
.\scripts\install_services.ps1
```

Services:

- `HastamaApi`: Uvicorn on `127.0.0.1:8000`
- `HastamaHttps`: Caddy on TCP 443

Remove them with:

```powershell
.\scripts\remove_services.ps1
```

NSSM is intentionally not downloaded by the scripts. Obtain it from an approved internal
software source. Configure service recovery and log rotation according to company policy.

## Firewall

Allow inbound TCP 443 only from the required LAN subnet. Do not open TCP 8000 to clients.
Verify with:

```powershell
Get-NetTCPConnection -State Listen -LocalPort 8000,443
```

## Background jobs

The application starts APScheduler with the FastAPI lifecycle. It runs:

- scheduled notification publication every 30 seconds
- push-subscription maintenance every hour

Do not run multiple application scheduler instances against the same database unless the
jobs are made leader-elected; use one Uvicorn process for this deployment or move jobs to
a dedicated worker later.

## Backup and recovery

Back up:

1. SQL Server database, including notification tables.
2. The server-only `.env` file through the organization's secret-management process.
3. Caddy configuration and the internal CA/certificate material according to PKI policy.
4. Private application uploads and any Access database files.

Recovery order:

1. Restore SQL Server and verify `user_table`.
2. Restore protected configuration and VAPID keys.
3. Restore application files and `.venv` dependencies.
4. Validate Caddy and start the API service.
5. Start Caddy and verify HTTPS/SAN/DNS.
6. Existing Push subscriptions are origin-bound; if the origin or VAPID key changes,
   clients must subscribe again.

## Chrome acceptance checklist

On a real client:

1. Open the HTTPS DNS name.
2. Confirm `window.isSecureContext === true`.
3. Confirm `navigator.serviceWorker` and `window.PushManager` exist.
4. Confirm `/hastama-sw.js` is active with scope `/`.
5. Login and allow Notifications.
6. Confirm `registration.pushManager.getSubscription()` returns a subscription.
7. Confirm `/api/push/status` reports an active subscription.
8. Publish a controlled admin notification.
9. Verify the inbox, SSE event, Chrome notification, minimized Chrome behavior, and click.
10. Repeat on a second LAN computer.

A real Windows popup, minimized-browser test, and second-client test require manual
execution and are not inferred from Python or Caddy checks.

## Troubleshooting

- 401: session cookie is missing or the user is not logged in.
- 403: admin role or origin policy failed.
- No subscription: inspect Chrome Application > Service Workers and permission settings.
- No push: inspect VAPID configuration, subscription row, and server push logs.
- Certificate warning: verify DNS, SAN, and enterprise CA trust.
- SSE stalls: verify Caddy flush settings and the active `/api/notifications/stream` request.
- Scheduled notification delayed: inspect scheduler logs and database `scheduled_at`.
