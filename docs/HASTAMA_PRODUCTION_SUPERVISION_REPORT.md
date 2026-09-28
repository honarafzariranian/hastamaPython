# Hastama — Production Validation & Process Supervision Work Report

**Date:** 2026-09-28
**Host:** `HASTAMA-SERVER` (Windows, `192.168.3.69`, Python 3.11 venv at `E:\Hastama\.venv`)
**Public URL:** `https://hastama.ir`
**Verdict:** **READY WITH DOCUMENTED LIMITATIONS** — the deployment serves correctly, the
production start path is now deterministic and self-repairing, and every unverified item is
listed explicitly in §11 instead of being claimed as passed.

This file is the narrative record of the work described below. It is the "supervision report"
referenced from [`docs/security/RESIDUAL_RISK_REGISTER.md`](security/RESIDUAL_RISK_REGISTER.md)
(RR-28), and it records the measurements behind
[`docs/network/UNIFIED_URL_ARCHITECTURE.md`](network/UNIFIED_URL_ARCHITECTURE.md) §5 and
[`docs/HASTAMA_PRODUCTION_DEPLOYMENT.md`](HASTAMA_PRODUCTION_DEPLOYMENT.md) *Process supervision*.
Those normative documents stay authoritative for the model itself; this report records
*what was done, what was measured, and what is still unproven*.

---

## 1. Scope of the work

| # | Workstream | Outcome |
|---|---|---|
| A | Final production validation of the live deployment | Completed — verdict *ready with documented limitations* |
| B | Make process supervision deterministic (only the supervised instance may own `127.0.0.1:5000`) | Completed, committed, tested live |
| C | Live supervision tests (start, duplicate start, kill, hand-started instance, foreign owner, hung instance, restart storm, probe fault) | 8 scenarios VERIFIED, 1 NOT VERIFIED, 1 NOT APPLICABLE |
| D | Documentation-consistency pass (no implementation change) | Completed — 9 contradictions corrected across 3 documents |

---

## 2. Verified architecture (unchanged)

```text
LAN and Internet users
        |
        |  https://hastama.ir      (the only address users are given)
        v
Cloudflare edge                     TLS 1.2/1.3, WAF, HTTP->HTTPS 301, www->apex 301, HSTS
        |
        |  outbound tunnel (no inbound port, no port-forward)
        v
cloudflared  (Windows service "cloudflared", RUNNING / AUTO_START, LocalSystem)
        |
        |  http://127.0.0.1:5000
        v
uvicorn app.main:app --host 127.0.0.1 --port 5000
                     --proxy-headers --forwarded-allow-ips 127.0.0.1
        ^
        |  launched ONLY by the "\HastamaServer" scheduled task -> cmd /c scripts\run_server.bat
        |
        +-- SQL Server Express 127.0.0.1:1433 (loopback only)
        +-- notification inbox / SSE stream, APScheduler maintenance jobs
        +-- label printer on the server (spooler)
```

Verified live details:

* **One listener, loopback only** — `netstat` shows `TCP 127.0.0.1:5000 LISTENING` and no
  `0.0.0.0:5000` / LAN listener.
* **Production process chain** (verified by walking `ParentProcessId`):

  ```text
  python.exe    <uv python>  -m uvicorn app.main:app --host 127.0.0.1 --port 5000 ...
      ^ python.exe  ".venv\Scripts\python.exe" -m uvicorn app.main:app --host 127.0.0.1 --port 5000 ...
          ^ cmd.exe   "cmd.exe" /c E:\Hastama\scripts\run_server.bat
              ^ svchost.exe -k netsvcs -p -s Schedule      (Task Scheduler)
  ```

* **Scheduled tasks**

  | Task | Identity | Trigger | Policy | Action |
  |---|---|---|---|---|
  | `\HastamaServer` | user `hastama` (RID `…-500`), LogonType Password, RunLevel HighestAvailable | At system startup | `IgnoreNew`, `ExecutionTimeLimit=PT0S` | `cmd.exe /c E:\Hastama\scripts\run_server.bat` |
  | `\HastamaWatchdog` | `SYSTEM` | every 5 minutes (`PT5M`) | `IgnoreNew` | `powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "E:\Hastama\scripts\watchdog_server.ps1" -Port 5000 -TaskName HastamaServer` (WorkingDirectory `E:\Hastama`) |

* **Firewall** — `Hastama - Block Uvicorn 5000 / SQL Server 1433 / SMB 445 / RDP 3389 (TCP+UDP)`,
  all `Enabled`, `Action=Block`, `Profile=Any`. Port 5000 is loopback-only; the deployment needs
  no inbound allow rule because the tunnel dials outbound.
* **Edge posture** — TLS 1.0/1.1 refused, 1.2/1.3 accepted; HTTP→HTTPS 301; `www`→apex 301;
  `Strict-Transport-Security: max-age=31536000; includeSubDomains`; full CSP / X-Frame-Options /
  `X-Content-Type-Options` / Referrer-Policy / Permissions-Policy present; `GET /health` returns
  `{"status":"ok"}` and does not touch the database.

---

## 3. The 2026-09-28 incident — proven vs inferred

The whole supervision rewrite exists because of one real outage. The evidence is separated here
because conflating the proven cause with the guessed one was the single biggest diagnostic risk
during the investigation.

### PROVEN

1. The **then-current watchdog treated "port 5000 is listening" as health**. It only ran a
   loopback-listener test (`Test-LoopbackListener`) and never inspected *which* process owned the
   port.
2. A **hand-started `uvicorn app.main:app --host 127.0.0.1 --port 5000`** — started by the user
   from a VS Code integrated PowerShell — owned that port. It answered HTTP 200 both locally and
   through the public URL while running **without** the production flags
   (`--proxy-headers --forwarded-allow-ips 127.0.0.1`) and **without** the UTF-8 environment
   (`PYTHONUTF8=1`, `PYTHONIOENCODING=utf-8`).
3. **VS Code `Application Hang`** — `dllhost.exe` was closed and Windows Error Reporting recorded
   `MoAppHang` at **08:27:45**. The public `502` begins at 08:27:45.
4. The **supervised production log stopped** at `2026-09-27 15:14:11` with **no exception, no WER
   crash record and no reboot** for the supervised process.
5. A **second start at 08:29:16 died** with `[Errno 10048] bind 127.0.0.1:5000 already in use` —
   direct proof that a foreign owner held the port.

### INFERRED (not proven)

* That an unsupervised instance served traffic from `2026-09-27 15:14:11` until
  `2026-09-28 08:27:45`. Supporting but not conclusive evidence: the watchdog ran every 5 minutes
  with `Last Result 0` and wrote **no** log lines, and `TIME_WAIT` sockets existed on port 5000 at
  `08:28:12`.

### NOT VERIFIED

* **Why** the supervised instance stopped at `15:14:11`. No crash artifact exists. With the current
  watchdog this condition would now be detected and repaired within one interval; the historical
  cause itself is unrecoverable from the available logs.

### A misdiagnosis that was disproved

My first hypothesis was a broken loopback probe inside the watchdog. That was **disproved** — the
probe worked. `scripts/watchdog_server.ps1` was reverted to `HEAD` at that point and then fully
rewritten on the correct diagnosis.

---

## 4. The supervision model (what replaced the port check)

`scripts\watchdog_server.ps1` now runs **four layers**. A listening port on its own is never
treated as health.

| Layer | Check | Failure status |
|---|---|---|
| 1. Listener identity | Owning process command line matches the application signature `(?i)(-m\s+uvicorn\s+app\.main:app\|uvicorn(\.exe)?\s+app\.main:app)` **and** carries `--port 5000` | `PORT_FOREIGN_OWNER`, `PROCESS_LOOKUP_FAILED` |
| 2. Production flags | All four flags present: `--host 127.0.0.1`, `--port 5000`, `--proxy-headers`, `--forwarded-allow-ips 127.0.0.1` | `UNEXPECTED_PROCESS` |
| 3. Supervision evidence | Ancestor command line matches `run_server.bat` **OR** the instance is writing `logs\hastama-autostart.log` (fresh ≤ `ProductionLogFreshSeconds` = 120 s) | `UNEXPECTED_PROCESS` |
| 4. Application health | `GET http://127.0.0.1:5000/health` returns `200` within 5 s | `APPLICATION_UNHEALTHY` (after the streak threshold), `HEALTH_PROBE_ERROR` |

**Safety rules baked in:**

* A process that is *not* the Hastama application (`PORT_FOREIGN_OWNER`) is **never killed**.
* A process that cannot be inspected (`PROCESS_LOOKUP_FAILED`) **never** causes a restart.
* A fault inside the probe itself (`HEALTH_PROBE_ERROR`) is **isolated** and **can never** trigger
  a restart — this distinction was added after the test suite exposed it as a defect.
* Before re-triggering the task the port is freed properly: only *identified* processes are stopped
  and `schtasks /End` is used, then `schtasks /Run` — so `MultipleInstancesPolicy=IgnoreNew` cannot
  silently swallow the restart.
* Restarts are rate-limited: `MaxRestartsPerWindow=3` inside `RestartWindowMinutes=30`
  (`RESTART_SUPPRESSED`) so a crash loop cannot become a restart storm.
* The watchdog log is bounded (`MaxLogBytes=2097152`) and contains **no secrets**.

**Full status vocabulary written to `logs\hastama-watchdog.log`:**

```text
HEALTHY                     APPLICATION_DOWN            PORT_FOREIGN_OWNER
PROCESS_LOOKUP_FAILED       UNEXPECTED_PROCESS          UNEXPECTED_PROCESS_STOPPED
APPLICATION_UNHEALTHY       HEALTH_PROBE_ERROR          RESTART_REQUESTED
RESTARTED                   RECOVERY_CONFIRMED          RESTART_FAILED
RESTART_SUPPRESSED          PUBLIC_HEALTH_OK | PUBLIC_HEALTH_FAILED | PUBLIC_HEALTH_NOT_VERIFIED
```

**Parameters:** `UnhealthyThreshold=3`, `MaxRestartsPerWindow=3`, `RestartWindowMinutes=30`,
`StartupWaitSeconds=75`, `MaxLogBytes=2097152`, `ProductionLogFreshSeconds=120`, `SkipPublicCheck`.

**State file:** `logs\hastama-watchdog-state.json`
(`unhealthy_streak`, `last_status`, `restart_times`) — reset to `HEALTHY` at the end of this work.

**Implementation note (a real trap):** the health probe deliberately avoids `System.Net.Http`,
because that assembly is **not loaded in Windows PowerShell 5.1** — the probe uses
`[System.Net.WebRequest]::Create` with `Proxy = $null`. Using `System.Net.Http` would have thrown
at runtime under the SYSTEM task.

---

## 5. Making the production port deterministic

The watchdog is *detection and reclamation*. The complementary half is making the correct path
obvious and the wrong path hard:

| File | Role |
|---|---|
| `scripts\watchdog_server.ps1` | The 4-layer supervisor described in §4 |
| `scripts\stop_server.ps1` **(new)** | Identity-gated stop: identifies the owner with `Test-IsHastamaApp`, stops **only** identified processes, **refuses** a foreign owner, clears the task, waits for port release. Uses the same signature regex as the watchdog (enforced by a test) |
| `scripts\stop_server.bat` | Now calls the helper. Its old `WINDOWTITLE` filter **never matched**, so the script had been silently ineffective |
| `scripts\run_dev.bat` **(new)** | **DEVELOPMENT ONLY** — `--host 127.0.0.1 --port 5001`, UTF-8 env, never port 5000 |
| `scripts\run_server.bat` | Added a `title` and a "PRODUCTION START PATH — do not bypass" header. The start line, flags and UTF-8 environment are **unchanged** |
| `scripts\start_server.bat` | Triggers the task (the correct way to start production) |
| `scripts\stop every 5000.bat` | Warns and prints the current port owner via `tasklist` |
| `scripts\disable_autostart.bat` / `enable_autostart.bat` | Now toggle **both** `\HastamaServer` and `\HastamaWatchdog`, so a maintenance window is not silently re-opened by the watchdog |

The single sentence that ties it together, also present in the deployment doc:

> **Never start production by hand.** `python -m uvicorn app.main:app --port 5000` from a terminal
> steals the listening socket from the supervised instance, runs without the flags and the UTF-8
> environment, and dies with its terminal. Use `scripts\start_server.bat` (production) or
> `scripts\run_dev.bat` (development, port 5001).

---

## 6. Live supervision tests

Every scenario below was executed against the real machine, not simulated.

| # | Scenario | Statuses observed | Result |
|---|---|---|---|
| **A** | Normal supervised start | — | VERIFIED — one listener, correct flags, `/health` 200 |
| **B** | Duplicate `schtasks /Run` ×2 | both refused ("task is currently running") | VERIFIED — `IgnoreNew` held; exactly **one** listener |
| **C** | Kill the supervised process | `APPLICATION_DOWN` → `RESTART_REQUESTED` → `RESTARTED` → `RECOVERY_CONFIRMED` → public 200 | VERIFIED — **31 s** end to end |
| **D** | Hand-started unsupervised instance (pid 20648, uv python, no production flags) | `UNEXPECTED_PROCESS` (logged pid, exe, cmdline, **ancestry** and the **missing evidence**) → stop identified process → `RESTARTED` → `RECOVERY_CONFIRMED` (09:10:39) → `PUBLIC_HEALTH_OK` | VERIFIED — repaired **unattended**, running as SYSTEM |
| **D2** | Deliberate foreign owner on `:5099` | `PORT_FOREIGN_OWNER` (owner name/exe/cmdline logged) | VERIFIED — **not killed** |
| **E** | Hung instance: accepts TCP, never answers | `APPLICATION_UNHEALTHY … UNREACHABLE Timeout` `1/2`, no restart; then `2/2` → `RESTART_REQUESTED` → restart | VERIFIED — threshold respected (test used `-UnhealthyThreshold 2`; default 3) |
| **E2** | Force repeated restarts | `RESTART_SUPPRESSED` — "3 restarts in the last 30 minutes (limit 3)" | VERIFIED — storm protection works |
| **E3** | Fault injected into the probe path itself | `HEALTH_PROBE_ERROR`, isolated, **no** restart | VERIFIED — and this exercise **found a real defect**, which was fixed before the run finished |
| **F** | Windows reboot recovery | — | **NOT VERIFIED** (would disrupt the live laboratory) |
| **G** | Cloudflare / firewall change behaviour | — | **NOT APPLICABLE** (no change made) |

Test artifacts used during this phase were removed afterwards; test ports `5098`/`5099` are free
and no temporary file was left behind.

---

## 7. Changes

### Committed

`HEAD = 1986c60` — `feat(infra): harden production supervision and resolve encoding issues`
(28 files, 1942 insertions, 147 deletions):

```text
 _tmp_topbar_harness.html                 |  53 ----
 app/__init__.py                          |  10 +
 app/core/console.py                      |  69 ++++
 app/main.py                              |  15 +-
 app/static/css/admin-mobile-redesign.css | 200 ++++++++++---
 app/static/css/user-panel-style.css      |   6 +-
 app/static/js/admin-mobile.js            |  50 +++-
 app/static/js/admin.js                   |  10 +
 app/templates/register.html              |  13 +-
 docs/HASTAMA_PRODUCTION_DEPLOYMENT.md    |  26 ++
 docs/network/UNIFIED_URL_ARCHITECTURE.md |  42 ++-
 docs/security/RESIDUAL_RISK_REGISTER.md  |   6 +-
 scripts/disable_autostart.bat            |   7 +-
 scripts/enable_autostart.bat             |   4 +-
 scripts/run_dev.bat                      |  19 ++
 scripts/run_server.bat                   |  17 ++
 scripts/start_server.bat                 |   6 +
 scripts/stop every 5000.bat              |  18 +-
 scripts/stop_server.bat                  |   9 +-
 scripts/stop_server.ps1                  |  95 +++++++
 scripts/watchdog_server.ps1              | 473 +++++++++++++++++++++++++++++--
 tests/test_console_encoding.py           | 232 ++++++++++++++++
 tests/test_mobile_admin_drawer_motion.py | 109 +++++++
 tests/test_mobile_admin_profile_menu.py  |  55 +++
 tests/test_mobile_admin_scroll.py        |  97 ++++
 tests/test_mobile_tabbar_icons.py        |  69 ++++
 tests/test_production_supervision.py     | 263 +++++++++++++++++
 tests/test_register_layout.py            | 116 ++++++++
```

### Uncommitted at the time of writing (documentation only)

Working tree contains **only three modified documentation files** — no code, no scripts:

```text
 docs/HASTAMA_PRODUCTION_DEPLOYMENT.md    | 28 ++++++----
 docs/network/UNIFIED_URL_ARCHITECTURE.md | 38 +++++++----
 docs/security/RESIDUAL_RISK_REGISTER.md  |  4 ++--
 3 files changed, 53 insertions(+), 17 deletions(-)
```

Nothing is staged, committed or pushed for those three files.

---

## 8. Documentation-consistency pass

The docs still described the *retired port-only* watchdog in several places. Nine contradictions
were corrected:

1. Deployment table, watchdog row — "Only triggers … when not listening" replaced with the layered
   model and its full status list.
2. URL doc, roadmap item 7 — marked done 2026-09-27 **with the health model replaced 2026-09-28**.
3. URL doc §11 *Measured recovery* — the two port-only watchdog rows preserved **verbatim** and
   annotated *(port-only implementation, historical)*, with a block note explaining that they no
   longer describe the repository.
4. URL doc §6 *Command* row — relabelled as the command **executed by the production launcher**
   `scripts\run_server.bat`, explicitly "not a start command to type. Production must **never** be
   started by hand on port 5000".
5. **RR-25** — kept as closed, but the condition it acts on is now stated as widened on 2026-09-28
   and redirected to RR-28; evidence row extended with the re-verification sequence.
6. **RR-28** — new risk entry: the incident, the current mitigation, and the *remaining exposures*
   that are deliberately **not** claimed away.
7. `PROCESS_LOOKUP_FAILED` added to the status list in the URL doc.
8. Added the two documented design limits (detection window; deliberate masquerade).
9. Deployment architecture diagram line marked
   `<- launched ONLY by the "HastamaServer" task (run_server.bat)`.

Also added: the canonical **two-chain block** (Production `\HastamaServer` → `run_server.bat` →
`127.0.0.1:5000`; Development `scripts\run_dev.bat` → `127.0.0.1:5001`) in the deployment doc, plus
a cross-reference from the URL doc §5 so there is exactly one normative statement of the start path.

**Search proof for the fix:** the sweep

```bash
grep -rn -iE "only triggers|only checks|just checks|port listening = healthy|when the (loopback )?port stops listening|when .*is not listening" --include=*.md .
```

(excluding the separate `.kilo/` worktree) returns **0 hits**; the only remaining "not listening"
string is the row explicitly labelled historical.

**Known inconsistencies left in place on purpose:**

* The deployment watchdog row intentionally omits the five recovery-flow statuses and points to the
  URL doc instead of duplicating the model.
* `scripts\install_services.ps1:33` still contains a raw `-m uvicorn … --port 8000` command. It
  belongs to the retired Caddy/NSSM topology and can never take port 5000; the deployment doc
  already marks that pair as historical.
* `app/core/console.py` mentions the uvicorn output redirect in a code comment — it is not a startup
  instruction.
* `.kilo/worktrees/kaput-switch/` is a **separate git worktree** (detached `9235d3c`, excluded via
  `.git/info/exclude`) that holds stale port-only docs. Left untouched.

---

## 9. Test results

| Suite | Result |
|---|---|
| `tests/test_production_supervision.py` | 26 tests — all pass |
| `tests/test_network_url_policy.py` | 21 tests — all pass |
| `tests/test_console_encoding.py` | 11 tests — all pass |
| `tests/test_register_layout.py` | 7 tests — all pass |
| Those four files collected together | **65 tests** |
| **Whole repository** | **578 passed, 13 failed, 4 skipped** |
| Baseline before this work | 552 passed, 13 failed, 4 skipped |

The 13 failures are **known and pre-existing** — dark-theme CSS, responsive tables, final-report
print, ticketing service and user-panel theming. None of them is related to supervision, URLs,
console encoding or the register layout: the net change is **+26 passing tests, 0 new failures**.

---

## 10. Production validation results

Verified over the public path (all through Cloudflare, IPv4 pinned on this host because the machine
has no working IPv6 while Cloudflare publishes AAAA records — browsers are unaffected by this):

* **Public routing/auth/security surface:** 18 of 19 planned checks VERIFIED
  (TLS versions, redirects, HSTS, security headers, `/health` without DB, `/docs` 404,
  `400 Invalid host header` for foreign hosts, auth routes `GET /captcha`, `POST /login_user`,
  `GET /login`, `GET /logout`, CSRF middleware, session cookie
  `httponly; samesite=lax; secure`).
* **WebSocket `/api/ws/call-display`:** **5/5 VERIFIED**, including the Origin check closing with
  `1008` before `accept` (→ `403` for a foreign origin). Verified with a **stdlib RFC6455 client**;
  the `websockets` 17.1 client crashes with
  `AttributeError: 'ClientConnection' object has no attribute 'recv_messages'`, so it was abandoned
  for this test.
* **Secret/URL hygiene:** `.env` untracked and gitignored, `SECRET_KEY` is a 96-character random
  value; no `ws://` literals and no hard-coded `http://` canonical links in `app/`.
* **Log hygiene:** the 271 MB `logs\hastama-autostart.log.log.1` was deleted (`logs\` is back to
  ~1.4 MB) and `run_server.bat` rotates above 50 MB.
* **Cleanup:** the temporary validation account `_validation` was deleted
  (`deleted rows=1, remaining=0`), and every `_tmp_*` harness created during this work was removed.
  `_tmp_uvicorn.err` is git-tracked and was restored with `git checkout --` rather than deleted.

---

## 11. Final runtime state and remaining limits

### State at the end of the work

```text
listener       127.0.0.1:5000 LISTENING (pid 13776), 1 instance
chain          python <- .venv\Scripts\python.exe <- cmd.exe /c run_server.bat <- svchost (Schedule)
tasks          \HastamaServer  Running      \HastamaWatchdog  Ready (next run 09:55)
cloudflared    STATE 4 RUNNING
health         local  http://127.0.0.1:5000/health = 200
               public https://hastama.ir/health      = 200
watchdog state {"last_status":"HEALTHY","unhealthy_streak":0,"restart_times":[]}
ports 5098/5099 free; no manual uvicorn instance left running
```

### Documented limitations (deliberately not claimed away)

1. **Detection is periodic, not instantaneous.** An unsupervised or hung instance can serve for up
   to one watchdog interval (**5 minutes**) before the next run reclaims it; the recovery itself then
   takes roughly **30–60 s** (observed 31–36 s).
2. **A deliberate masquerade is possible.** A process started with all four production flags *and*
   whose stdout is redirected into `logs\hastama-autostart.log` is classified as production. That
   requires administrative intent on the server, and is accepted as a residual exposure (RR-28).
3. **A hang that still answers `/health`** with 200 is not detectable by this model.
4. **Worst-case unverified scenario:** a Windows reboot (test F) — the boot trigger and the
   watchdog are configured for it, but it was not executed because it disrupts the live laboratory.

### NOT VERIFIED (no access / out of scope)

* External port scan and router NAT inspection from outside the LAN.
* Cloudflare dashboard configuration (tunnel public hostname, WAF rules).
* External-network user test — the test client was on the same LAN.
* Live login-throttling behaviour (`LOGIN_FAILURE_WINDOW=600`, `LOGIN_MAX_FAILURES_PER_IP=15`,
  `LOGIN_MAX_FAILURES_PER_USER=30`, → `429`). The code exists and is wired, but triggering it would
  lock the laboratory's shared public IP out of the login page for 10 minutes.
* The historical cause of the `2026-09-27 15:14:11` stop (§3).

---

## 12. What was deliberately rejected

**A production lock file / mutex was considered and rejected.** Any of its variants would either
create a *new* single point of failure (a stale lock blocks the supervisor) or prevent nothing at
all (fail-open). The guarantee wanted here — "only the supervised instance serves the public URL" —
is already delivered by *detect-and-reclaim* plus *documented start path* plus *a separate
development port*, and it was proven by tests C, D and E. Adding a lock would have added moving
parts without adding a verified guarantee.

---

## 13. Operator quick reference

| Intent | Command |
|---|---|
| Start production | `scripts\start_server.bat` (triggers `\HastamaServer`) |
| Stop production | `scripts\stop_server.bat` (identity-gated) |
| Develop / debug | `scripts\run_dev.bat` → `127.0.0.1:5001` (**never** 5000) |
| Maintenance window | `scripts\disable_autostart.bat` (disables **both** tasks) |
| Who owns port 5000? | `netstat -ano \| findstr :5000` then `tasklist \| findstr <pid>`, or `scripts\stop every 5000.bat` |
| Did the watchdog act? | `logs\hastama-watchdog.log` (bounded) and `logs\hastama-watchdog-state.json` |
| Is the app alive? | `logs\hastama-autostart.log` (refreshed ~1×/s) and `GET /health` |

**Diagnosing a `502`/`1033` from Cloudflare:** `sc query cloudflared` → `netstat -ano | findstr :5000`
→ `logs\hastama-watchdog.log` → `logs\hastama-autostart.log`.

---

## 14. Evidence index

| Artifact | What it proves |
|---|---|
| `logs\hastama-watchdog.log` | Every intervention of 2026-09-28: `UNEXPECTED_PROCESS_STOPPED`, `RESTARTED`, `RECOVERY_CONFIRMED`, `PUBLIC_HEALTH_OK`, `APPLICATION_UNHEALTHY`, `RESTART_FAILED`, `PORT_FOREIGN_OWNER`, `RESTART_SUPPRESSED` — with pid, exe, command line and reason |
| `logs\hastama-autostart.log` | The supervised instance's own output; also the freshness signal used as supervision evidence |
| `logs\hastama-watchdog-state.json` | Current streak, last status and restart timestamps |
| `tests\test_production_supervision.py` | 26 regression tests that keep the model from rotting back into a port check |
| `docs\network\UNIFIED_URL_ARCHITECTURE.md` §5 | The normative description of the layered model |
| `docs\security\RESIDUAL_RISK_REGISTER.md` RR-25 / RR-28 | The accepted residual exposure and its owner |
