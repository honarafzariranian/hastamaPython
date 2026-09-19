# Deployment Security Checklist — Hastama (offline LAN)

Every item states what to do and how to verify it. Items marked **[MUST]** are
required before the system is exposed to the LAN; **[DBA]**/**[INFRA]** are
operational tasks outside the application code.

## 1. Secrets and configuration **[MUST]**

| # | Action | Verification |
|---|---|---|
| 1.1 | Create `.env` next to the app (never commit it) | `git check-ignore -v .env` → ignored |
| 1.2 | `SESSION_SECRET_KEY=$(python -c "import secrets;print(secrets.token_urlsafe(48))")` | two different hosts have different values |
| 1.3 | `HASTAMA_HMAC_SECRET=$(python -c "import secrets;print(secrets.token_hex(32))")` | without it `/forgot_password` answers "recovery disabled" |
| 1.4 | `ARAZ_BRIDGE_SECRET=$(python -c "import secrets;print(secrets.token_urlsafe(32))")` and the *same* value in `tools/bridge_config.json` | `POST /api/araz/bridge-sync` without the secret returns 401/503, never 200 |
| 1.5 | `ARAZ_ACCESS_PASSWORD` set if the Access database is password protected | app refuses to start with an empty value in production |
| 1.6 | `TRUSTED_PROXY_IPS` = the address Caddy connects from (`127.0.0.1`) | a forged `X-Forwarded-For` from another host is ignored |
| 1.7 | `SESSION_MAX_AGE_SECONDS` set to the policy value (default 28800) | cookie `Max-Age` matches |
| 1.8 | `MASTER_ADMIN_USERNAMES` lists only real master administrators | a normal admin account cannot open `/master-admin/...` |
| 1.9 | `DEBUG=false` and `HASTAMA_ENABLE_DOCS` unset | `GET /docs` returns 404 |
| 1.10 | `TICKETING_PRIVATE_DIR` points outside the web root and is backed up | files are not reachable through `/static/` |

## 2. TLS and reverse proxy **[INFRA] [MUST]**

| # | Action | Verification |
|---|---|---|
| 2.1 | Install Caddy with an internal CA and deploy the provided `Caddyfile` (`tls internal`) | `curl -vk https://hastama.local/login` returns 200 |
| 2.2 | Import the internal CA root into every client machine's trust store | browser shows no certificate warning |
| 2.3 | Bind uvicorn to `127.0.0.1:8000` (not `0.0.0.0`) and let Caddy be the only listener | `netstat -ano | findstr :8000` shows a loopback binding |
| 2.4 | Keep the 12 MB request-body cap; raise only with a documented reason | large upload returns 413 |
| 2.5 | Confirm the access log is written to a protected path with rotation | log file grows and rotates at 20 MiB × 10 |
| 2.6 | Windows Firewall: allow 443 only from the LAN subnet; no port forwarding from outside | `netsh advfirewall firewall show rule name=all` |
| 2.7 | Do **not** expose `/call-display`, `/call-management` or the registration form to the Internet | external port scan shows no open 443 |

## 3. Database **[DBA]**

| # | Action | Verification |
|---|---|---|
| 3.1 | Create a dedicated SQL login for the application (no `sa`, no `sysadmin`) | `SELECT IS_SRVROLEMEMBER('sysadmin', 'hastama_app')` → 0 |
| 3.2 | Grant it `db_datareader` + `db_datawriter` on `userDB`; add `db_ddladmin` only if the startup schema migrations must run | attempt `CREATE TABLE` as the app login fails |
| 3.3 | Enable backup (full daily + log/diff) with an encrypted destination | `RESTORE VERIFYONLY` on the newest file succeeds |
| 3.4 | Test a restore into a scratch database every quarter | restored row counts match |
| 3.5 | Do not expose SQL Server outside the host (TCP/IP disabled or firewalled to loopback) | port 1433 not reachable from another LAN host |
| 3.6 | Run `python -m tools.migrate_passwords --dry-run` first, then without `--dry-run` | after migration `SELECT COUNT(*) FROM user_table WHERE password IS NOT NULL AND LTRIM(RTRIM(password)) <> ''` → 0 |

## 4. Host and runtime **[INFRA]**

| # | Action | Verification |
|---|---|---|
| 4.1 | Run the app under a dedicated low-privilege Windows service account | service logon account is not `LocalSystem` |
| 4.2 | Restrict the installation directory ACL to that account + Administrators | `icacls` output |
| 4.3 | Keep the `.env` readable only by that account | `icacls .env` shows no `Users`/`Everyone` entry |
| 4.4 | Remove `app/tempexport.py` and any `exported_data.sql` from the server | files absent; `git ls-files | findstr tempexport` reviewed |
| 4.5 | Store `Arazdb.mdb` outside the application directory and outside Git | path in `ARAZ_ACCESS_PATH` |
| 4.6 | Enable Windows Defender or the approved AV; add exclusions only for SQL Server data files | exclusions documented and approved |
| 4.7 | Windows Update / patch policy for the host, Caddy, SQL Server, wkhtmltopdf | patch report |
| 4.8 | NTP sync on the host (audit log timestamps) | `w32tm /query /status` |

## 5. Application smoke test after deployment **[MUST]**

| # | Step | Expected |
|---|---|---|
| 5.1 | `GET /login` | 200, no certificate warning |
| 5.2 | Login with a wrong password 16 times from one client | first 15 answered generically, then 429 |
| 5.3 | Login correctly | redirect to the panel; cookie has `HttpOnly`, `SameSite=Lax`, `Secure` |
| 5.4 | `GET /docs` | 404 |
| 5.5 | `GET /api/araz/bridge-sync`-style call without the secret | 401/503, never data |
| 5.6 | Open the admin panel and the four report pages | tables render, no console errors |
| 5.7 | `POST /submit_overtime` with `<script>` in the description | rejected with a validation message |
| 5.8 | Print/export a final report for a user | PDF renders (or the 503 "template missing" message if the template is absent) |
| 5.9 | Check `audit_logs` after the smoke test | login, logout and admin actions recorded |
| 5.10 | Kill the network connection to the Internet | the system keeps working (offline requirement) |
