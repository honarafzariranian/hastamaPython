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

## Hostname resolution (centralized)

Create one DNS record in the organization's internal DNS server; do not use `hosts`
files on clients:

```text
hastama.corp.example.com  A  <server-LAN-IP>
```

Use the organization's real internal domain instead of `corp.example.com`. A `.local`
name is not recommended for production LAN DNS because it is reserved for mDNS.

The DHCP/domain policy must advertise this DNS server to all clients. Without internal
DNS, the no-manual-client requirement cannot be met.

Do not hard-code the address in the application. Confirm resolution:

```powershell
Resolve-DnsName hastama.corp.example.com
```

## HTTPS certificate

The Caddyfile uses `tls internal`, which auto-generates a certificate signed by
Caddy's local ECC CA. On the server, Caddy's root CA is trusted automatically.
Each new LAN client must trust the same root CA once:

**Quick approach:** Run the included helper on every client (elevated prompt):

```powershell
.\setup_hastama_client.bat <server-IP> C:\path\to\root.crt
```

The root certificate location on the server is:

```text
%AppData%\Caddy\pki\authorities\local\root.crt
```

Copy that file to the client, then run the helper or import it manually into
`Cert:\LocalMachine\Root`. After that, Chrome trusts `https://hastama.local` without
warnings.

**Production approach:** Replace `tls internal` with an organization CA certificate.
Have the organization's internal PKI issue a server certificate with DNS SAN
`hastama.corp.example.com`, install it on the server, and set:

```text
HASTAMA_HOST=hastama.corp.example.com
HASTAMA_CERT_FILE=C:\ProgramData\Hastama\certs\hastama.crt
HASTAMA_KEY_FILE=C:\ProgramData\Hastama\certs\hastama.key
```

Then change the Caddyfile to use `tls {$HASTAMA_CERT_FILE} {$HASTAMA_KEY_FILE}`
instead of `tls internal`. With an organization CA, clients receive the root through
Group Policy, and no per-client setup is needed.

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
prints the configured HTTPS URL. Close the two launched consoles to stop them. If using
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
curl.exe -I https://hastama.corp.example.com/static/js/hastama-sw.js
curl.exe -I https://hastama.corp.example.com/docs
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
resolve `hastama.corp.example.com`, trust the organization CA through policy, login,
subscribe, and then receive a notification
while Hastama is in another tab or Chrome is minimized. Test notification click focus
and navigation as well.

## Troubleshooting

- **Certificate warning:** verify that the URL exactly matches a DNS SAN on the
  server certificate and that the organization's CA chain is deployed to the client via
  Group Policy/Intune. Do not use `--ignore-certificate-errors`.
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
- **Hostname fails:** check the centralized DNS record, `Resolve-DnsName`, and Windows
  Firewall TCP 443 access.

## Adding or changing clients and certificates

New clients only need to resolve `hastama.local` to the server and trust the Caddy
root CA. The `setup_hastama_client.bat` helper automates this: it adds the hosts entry,
imports the root certificate, flushes DNS, and verifies HTTPS. No application secret,
VAPID private key, or server certificate belongs on clients.

If the hostname changes, update the hosts/DNS entry, update the Caddyfile, and restart
Caddy. Existing Push subscriptions are origin-bound; changing the origin may require
users to grant permission and subscribe again.
