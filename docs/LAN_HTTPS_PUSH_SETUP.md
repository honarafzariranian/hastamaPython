# Hastama LAN HTTPS and Web Push

## Architecture

Clients open `https://hastama.local`. Caddy terminates HTTPS and proxies to FastAPI on
`127.0.0.1:8000`. The browser, Service Worker, Push API, and notification inbox remain
same-origin; SQL Server and Access DB stay behind the application.

Web Push is optional at the backend level, but HTTPS is required for non-localhost LAN
origins. A browser notification is not an in-page toast: Chrome's Push service wakes
`app/static/js/hastama-sw.js`, which displays the Windows notification.

## Requirements (Windows server)

- Python and the project environment installed.
- Caddy installed and available as `caddy` on `PATH`.
- A stable LAN address for the server.
- Administrator access only for the documented certificate trust/firewall steps.
- A VAPID key pair and an email/URL subject.

FastAPI should listen only on loopback. Do not expose port 8000 directly to clients.

## VAPID configuration

Generate/store the keys using your approved Web Push tooling. Put them in the server's
ignored `.env` file; never commit it:

```env
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@example.com
SESSION_SECRET_KEY=use-a-long-random-secret
```

The public key is returned only by authenticated `GET /api/push/config`. The private
key never reaches JavaScript or logs. Install project dependencies with:

```powershell
uv sync --dev
```

## Hostname resolution

Preferred: create a DNS record in the LAN DNS server:

```text
hastama.local  A  <server-LAN-IP>
```

Without internal DNS, on every client computer edit
`C:\Windows\System32\drivers\etc\hosts` as Administrator and add (replace the IP):

```text
192.168.1.100 hastama.local
```

Do not hard-code that address in the application. Confirm resolution:

```powershell
Resolve-DnsName hastama.local
```

## HTTPS certificate with Caddy internal CA

The server hostname must resolve before Caddy can be tested. Run the included client
helper from an elevated prompt on a workstation (replace the address):

```powershell
.\setup_hastama_client.bat 192.168.1.100 C:\path\to\root.crt
```

The helper updates only the `hastama.local` line, flushes DNS, optionally imports the
provided root certificate, and checks HTTPS. It does not change the application or
open firewall ports.


The included `Caddyfile` uses `tls internal` for the hostname. Start Caddy once, then
copy Caddy's root certificate to each client and trust it in Windows:

```powershell
caddy run --config .\Caddyfile --adapter caddyfile
caddy trust
```

`caddy trust` must be run with the required Windows approval on the server/client where
Caddy's root certificate is installed. For a client that does not run Caddy, export or
copy the matching root certificate from Caddy's data directory, then import it into
**Local Computer > Trusted Root Certification Authorities** using the Windows
certificate manager. Use the same Caddy installation/data directory and protect the CA
private material.

For larger deployments, use an organization-controlled internal CA instead. The server
certificate must include `hastama.local`; clients must trust that CA. Hostname access is
preferred over IP access because the certificate must also contain an IP SAN to support
an IP URL.

## Start and stop

Install Caddy using the official Windows package or the official Caddy MSI/Chocolatey
package approved by your organization. Verify it before startup:

```powershell
caddy version
caddy validate --config .\Caddyfile --adapter caddyfile
```

If automatic installation is desired and Chocolatey is already approved and installed:

```powershell
choco install caddy -y
```

Do not use an untrusted binary source. The startup script intentionally fails rather
than silently downloading or executing software.


From the project directory:

```powershell
uv sync --dev
.\start_hastama.bat
```

The script starts one FastAPI process on `127.0.0.1:8000` and one Caddy process, then
prints `https://hastama.local`. Close the two launched consoles to stop them. If using
PowerShell manually:

```powershell
$env:PYTHONPATH = "app"
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8000 --proxy-headers --forwarded-allow-ips 127.0.0.1
caddy run --config .\Caddyfile --adapter caddyfile
```

Do not run the script while another FastAPI/Caddy instance owns the same ports.

## Firewall

Create the firewall rule once in an elevated PowerShell prompt; the command is
intentionally not run automatically by the application:

```powershell
if (-not (Get-NetFirewallRule -DisplayName 'Hastama HTTPS LAN' -ErrorAction SilentlyContinue)) {
  New-NetFirewallRule -DisplayName 'Hastama HTTPS LAN' -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow -RemoteAddress 192.168.1.0/24
}
```


Allow only HTTPS from the LAN. Run an appropriately scoped inbound rule in an elevated
PowerShell prompt, after confirming the local network profile and subnet:

```powershell
New-NetFirewallRule -DisplayName "Hastama HTTPS LAN" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow -RemoteAddress 192.168.1.0/24
```

Adjust the subnet to the real LAN. Do not open port 8000 to the LAN. Caddy may need
permission to bind to port 443. Do not modify firewall policy automatically from the
application.

## Chrome setup and push subscription

1. Open `https://hastama.local` (not the HTTP IP address).
2. Login normally.
3. Click the notification bell and choose **Allow** in Chrome.
4. Confirm the Service Worker at `https://hastama.local/static/js/hastama-sw.js`.
5. Chrome creates a subscription and sends it to the authenticated
   `POST /api/push/subscribe` endpoint.
6. Check `GET /api/push/status` from the logged-in browser or the notification UI.

The existing durable notification inbox remains the source of truth. Publishing a
notification stores it first, then attempts best-effort Web Push to that user's active
subscriptions. Expired 404/410 subscriptions are disabled without failing the source
operation. The existing SSE/polling paths remain available for in-page updates.

If permission is `denied`, Chrome will not show a second prompt. Open the site settings
for `hastama.local`, set Notifications to Allow, reload, and click the bell again.

## Verification

```powershell
curl.exe -I https://hastama.local/static/js/hastama-sw.js
curl.exe -I https://hastama.local/docs
python -c "from app.main import app; s=app.openapi(); print('APP OK', len(s['paths'])); print([p for p in s['paths'] if '/push/' in p])"
```

In Chrome DevTools Console:

```javascript
window.isSecureContext
navigator.serviceWorker
window.PushManager
Notification.permission
```

The first three should be truthy and permission should be `default`, `granted`, or
`denied`. In Application > Service Workers, registration should be active with scope
covering `/`.

Test from both the server and a second LAN Windows computer. The second computer must
resolve `hastama.local`, trust the CA, login, subscribe, and then receive a notification
while Hastama is in another tab or Chrome is minimized. Test notification click focus
and navigation as well.

## Troubleshooting

- **Certificate warning:** the client does not trust the exact CA that issued the
  certificate, or the hostname is not in the certificate SAN. Do not use
  `--ignore-certificate-errors`.
- **Not secure / Push unavailable:** the page is still HTTP, the certificate is invalid,
  or the Service Worker is not same-origin. Use the HTTPS hostname.
- **No push subscription:** inspect Chrome Application > Service Workers and Network;
  verify VAPID values and authenticated session cookies. A missing `pywebpush` package
  disables delivery but does not remove inbox notifications.
- **No Windows popup:** verify Chrome site Notifications permission and Windows Focus
  Assist/Do Not Disturb. Chrome must be running for normal browser push delivery; a
  completely exited browser/OS cannot be guaranteed to display a notification.
- **SSE does not update:** check that the request is to `/api/notifications/stream` and
  that Caddy is running the included no-buffering, long-lived proxy configuration.
- **Hostname fails:** check the hosts/DNS entry, `Resolve-DnsName`, and Windows Firewall
  TCP 443 access.

## Adding or changing clients and certificates

For every new client, add DNS/hosts resolution and install/trust the organization or
Caddy root CA. No application secret or VAPID private key belongs on clients. If the
hostname or certificate changes, issue a certificate containing the new hostname,
update DNS/hosts, restart Caddy, and trust the new CA chain where necessary. Existing
Push subscriptions are origin-bound; changing the origin may require users to grant
permission and subscribe again.
