# Manual Verification Checklist (DBA / Infra / Authorized Assessor)

These items **cannot** be verified from the repository or from this sandbox.
Each one states the exact command or click-path and the expected result, so a
reviewer records evidence rather than an opinion.

## DBA tasks

| ID | Question | How to verify | Expected |
|---|---|---|---|
| D-1 | Which SQL login does the application use, and what can it do? | `SELECT SUSER_SNAME(), IS_SRVROLEMEMBER('sysadmin');` from the app connection, then `SELECT * FROM fn_my_permissions(NULL,'DATABASE');` | not `sysadmin`; only read/write (+ DDL if migrations must run) |
| D-2 | Is data at rest protected? | Check TDE (`SELECT * FROM sys.dm_database_encryption_keys`) or BitLocker (`manage-bde -status`) for the data and backup volumes | encryption enabled, or an accepted risk entry |
| D-3 | How many credentials are still legacy? | `SELECT COUNT(*) AS total, SUM(CASE WHEN password_hash IS NOT NULL THEN 1 ELSE 0 END) AS hashed, SUM(CASE WHEN password IS NOT NULL AND LTRIM(RTRIM(password))<>'' THEN 1 ELSE 0 END) AS legacy FROM user_table;` | `legacy = 0` after `tools/migrate_passwords.py` |
| D-4 | Which rows still hold a SHA-512 digest (not bcrypt)? | `SELECT COUNT(*) FROM user_table WHERE password_hash IS NOT NULL AND CAST(password_hash AS VARBINARY(4)) NOT IN (0x24326124,0x24326224,0x24327924);` | 0 (they re-hash on next password change) |
| D-5 | Are audit tables growing and complete? | `SELECT TOP 5 event_type, action, created_at FROM audit_logs ORDER BY created_at DESC;` | recent login/logout/security rows |
| D-6 | Is the export/quarantined file absent from the server? | `dir /s exported_data.sql` on the host and its backups | not found |
| D-7 | Do the audit tables have retention/archive rules? | inspect indexes/size; `sp_spaceused` | a documented retention decision |
| D-8 | Are DB backups encrypted and stored off-host? | inspect backup jobs and `msdb.dbo.backupset` | encrypted, off-host, restore tested |

## Infrastructure tasks

| ID | Question | How to verify | Expected |
|---|---|---|---|
| I-1 | Is the app port reachable from outside the LAN? | port scan from another subnet, `curl -k https://<public-ip>/login` | refused |
| I-2 | Does the TLS certificate chain validate on a client machine? | open the site in a browser on a workstation | no warning |
| I-3 | Is `/docs` closed in production? | `curl -k https://hastama.ir/docs` | 404 (**verified 2026-09-23**) |
| I-4 | Are the security headers present? | `curl -kI https://hastama.ir/login` | HSTS, `X-Content-Type-Options`, `Content-Security-Policy`, `X-Frame-Options`, no app `Server` header (**verified 2026-09-23**; edge `Server: cloudflare` only) |
| I-5 | Is the ACT/backup of the `.env` stored safely? | inspect the password vault / sealed envelope | stored separately from the backup of the DB |
| I-6 | Which accounts can read `Arazdb.mdb`? | `icacls <path>` | only the service account + Administrators |
| I-7 | Are the Araz vendor installers still needed on the server? | list `arazin/` and installed programs | only what the device integration needs |
| I-8 | Does `/` redirect to `/login` without a loop? | `curl -sSI https://hastama.ir/` then follow once | 301 → `/login`, end 200 (**verified 2026-09-23 as 302; code switched to 301 after confirmation — confirm live shows 301 after redeploy**) |
| I-9 | Does HTTP redirect to HTTPS and `www` to apex? | `curl -sSI http://hastama.ir/` and `https://www.hastama.ir/` | 301 chains to apex HTTPS (**verified 2026-09-23**) |
| I-10 | Is the origin port reachable from outside the tunnel? | port scan / `curl` to origin IP:8000 | refused |

## Application tasks needing a browser

| ID | Question | How to verify | Expected |
|---|---|---|---|
| B-1 | Do report pages still render after the escaping change? | open the four report pages as admin, with a user selected | tables and totals look identical to the previous version |
| B-2 | Does the leave/overtime form reject markup? | submit `<img src=x onerror=alert(1)>` as the description | validation error in Persian, nothing stored |
| B-3 | Is a stored payload rendered as text? | insert a description containing `<b>x</b>` **directly in the DB**, open the admin table | the text is shown literally, no bold, no dialog |
| B-4 | Do kiosk pages work without a login? | open `/call-display` on the TV browser | queue displays and refreshes |
| B-5 | Does a cross-site page fail to drive the kiosk? | from another origin, POST to `/api/calls/...` | 403 |
| B-6 | Do notifications mark read/unread from the bell menu? | click a notification | state changes, 200 |
| B-7 | Does a ticket attachment upload/download still work? | upload a PDF as a user, download as admin | both succeed |
| B-8 | Does the profile image upload work with a Persian filename? | upload `عکس.png` | stored under a sanitised name, image displays |

## Iranian regulatory / authorized-assessor items

| ID | Question | Note |
|---|---|---|
| R-1 | Does the organisation fall under AFTA obligations (critical infrastructure, enterprise software provider)? | Requires the authority's own classification — **NOT VERIFIED** here |
| R-2 | Are the exact AFTA requirement documents applicable, and in which version? | Primary documents were not retrievable from this environment — obtain from مرکز مدیریت راهبردی افتا |
| R-3 | Does the National Data & Information Management Law (1401) impose registration/reporting duties on this system? | Legal interpretation required |
| R-4 | Is the personal-data-protection bill now enacted? | Bill stage per public reporting (2024–2025); re-check the Official Gazette before claiming compliance |
| R-5 | Reporting duties in case of a personal-data breach | Define internally; the system itself has no breach-notification function |
