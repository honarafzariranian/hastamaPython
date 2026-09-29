# Hastama — Dead code / dead asset / dependency cleanup audit (2026-09-29)

Scope: the whole repository at `E:\Hastama` (FastAPI + Jinja2 + vanilla JS + SQL Server).
Method: **inventory → reference tracing → classification → quarantine → verify**.
No architectural change was made. Nothing was committed or pushed.

Tools used (kept out of the deployed tree, deleted at the end of the audit):
`_cleanup_audit/audit.py` (inventory + reference tracer + route/symbol/import report),
`_cleanup_audit/assets.py` (HTML → CSS/JS/image map), `_cleanup_audit/js_css.py`
(JS declaration and CSS class analysis), pylint (`W0611` unused imports),
`_cleanup_audit/quarantine_move.py` (mover + manifest),
`_cleanup_audit/runtime_verify.py` (live verification).

---

## A. Cleanup summary

```text
Files analysed (tracked + untracked, excl. .git/offline/arazin/.kilo)   12 697
Files confirmed unused                                                      62
Files removed (permanently deleted after verification)                      62
Python modules removed                                                       3
Python imports removed                                                      35
Python constants removed                                                    9
Routes removed                                                               0
Templates removed                                                            0
JS files removed                                                              0
JS declarations (functions/consts) removed                                   4
CSS files removed                                                              0
CSS selectors removed                                                          0   (392 flagged, see §H)
Images removed                                                                1
Fonts removed                                                                 4
Dependencies removed                                                           0   (see §E / §H)
Scratch/diagnostic files and logs removed                                    49
Static asset bytes removed                                      28 117 746 bytes
```

---

## B. Before / After

| Metric | Before | After | Reduction |
| --- | ---: | ---: | ---: |
| Application files (`app/`) | 2 292 | 2 283 | −9 |
| Python (`app/**/*.py`) | 48 files / 876 846 B | 45 files / 873 965 B | −3 files / −2 881 B (−0.3 %) |
| HTML templates | 20 files / 806 351 B | 20 files / 806 351 B | 0 |
| JavaScript (`app/static`) | 31 files / 1 947 205 B | 31 files / 1 947 205 B | 0 files / −22 lines (dead decls) |
| CSS (`app/static`) | 29 files / 1 393 468 B | 29 files / 1 393 468 B | 0 |
| Images (png/jpg/ico) | 19 files / 35 453 208 B | 18 files / 8 714 158 B | −1 file / −26 739 050 B (−75.4 %) |
| Fonts | 7 files / 314 072 B | 3 files / 170 104 B | −4 files / −143 968 B (−45.8 %) |
| Slides/audio/uploads (must stay) | — | — | 0 |
| **Total `app/static`** | **2 086 files / 101 778 049 B** | **2 081 files / 74 893 984 B** | **−5 files / −26 884 065 B (−26.4 %)** |
| **Total `app/`** | **2 292 files / 107 321 049 B** | **2 283 files / 79 203 303 B** | **−28 117 746 B (−26.2 %)** |
| Repository (excl. `.git`, `offline/`, `arazin/`, `.kilo`) | 12 697 files / 493 871 210 B | 12 626 files / 459 570 114 B | −71 files / −34 301 096 B (−6.9 %) |

Page payload after the cleanup is **identical to before** (no stylesheet or script was removed
from any page), measured from the real template references:

| Page | CSS files | CSS bytes | JS files | JS bytes | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| `admin.html` | 13 | 851 178 | 10 | 504 428 | 1 355 606 B |
| `user-panel.html` | 12 | 539 547 | 9 | 305 720 | 845 267 B |
| `master-admin.html` | 6 | 520 309 | 6 | 189 242 | 709 551 B |
| `final_report_page.html` | 9 | 326 451 | 6 | 135 578 | 462 029 B |
| `call-management.html` | 6 | 184 619 | 10 | 182 788 | 367 407 B |
| `login.html` | 5 | 212 570 | 5 | 58 152 | 270 722 B |
| `register.html` | 4 | 187 752 | 2 | 13 312 | 201 064 B |
| `ticket-kiosk.html` | 2 | 5 223 | 3 | 57 651 | 62 874 B |
| `rules.html` | 2 | 27 859 | 2 | 12 288 | 40 147 B |
| `call-display.html` | 4 | 38 912 | 4 | 44 442 | 83 354 B |

Honest note: the 26.4 % static saving is **disk / clone / deployment-payload** saving.
`HAI-logo.png` was never requested by any page, so no page got faster. Page-load work is a
separate (riskier) topic — see §H.

---

## C. Removed files (62)

### C1. Static assets — 5 files, 26 884 065 B

| File | Bytes | Why it is unused (proof) |
| --- | ---: | --- |
| `app/static/images/HAI-logo.png` | 26 739 050 | Zero references in the entire repository (`grep -rl HAI-logo .` excluding `.venv/.git/offline/.kilo` → nothing). `git log -S "HAI-logo"` shows commit `bdc6dac` removed the last usage while leaving the file; superseded by `newlogo.png` / `lab-logo.png`. |
| `app/static/fonts/Shabnam.ttf` | 54 276 | No `@font-face` anywhere (only `vazir.css`, `call-management.html`, `ticket-kiosk.html` declare fonts, all for `Vazir`), no `url()`/string reference outside documentation. |
| `app/static/fonts/Yekan.ttf` | 52 232 | The *family name* `'Yekan'` appears in one font-stack in `training.css`, but no `@font-face` maps it to a file and no `url()`/path points at these files, so no browser ever downloads them. |
| `app/static/fonts/Yekan.woff` | 21 500 | Same as above. |
| `app/static/fonts/Yekan.woff2` | 15 960 | Same as above. |

### C2. Python template-scaffold modules — 4 files, 1 233 838 B

| File | Bytes | Why it is unused (proof) |
| --- | ---: | --- |
| `app/core/events.py` | ~500 | Never imported by any module (`grep` for `events`/`core.events` across `app/tests/tools/scripts` finds no import); it calls `from services.predict import MachineLearningModelHandlerScore`, a package path that does not exist, so it could never have run. |
| `app/services/predict.py` | ~1 800 | Never imported (only by the dead `events.py`); imports `from core.errors import ...`; the model it loads does not exist in the deployed app. |
| `app/models/prediction.py` | ~1 000 | `MachineLearningDataInput` / `MachineLearningResponse` / `get_np_array` have zero references repo-wide; sole consumer of `numpy` in the project. |
| `app/models/pregnancy_model_local.joblib` | 1 228 473 | Demo model from the same scaffold; referenced only by the `MODEL_PATH`/`MODEL_NAME` constants of the removed `predict.py`. |

### C3. Scratch / diagnostic / log artefacts at the repository root — 53 files, ~6.2 MB

All verified unreferenced by the application, tests, scripts, tools and deployment files:

* 43 `_tmp_*.ps1|py|html|err` files (`_tmp_add_form*`, `_tmp_diag_*`, `_tmp_print_ticket*`,
  `_tmp_find_edge`, `_tmp_probe_geo/layout*`, `_tmp_print_result.html`, `_tmp_vpn_page.html`,
  `_tmp_uvicorn.err`) — one-off printing/geo/layout probes from debugging sessions.
  42 of them are **tracked in git** (recoverable: `git restore --source=<commit> -- <path>`).
* `server.log` (576 059 B), `_tmp_uvicorn.err` (5 292 350 B), `caddy-error.log`, `caddy-out.log`,
  `uvicorn.err.log`, `uvicorn.out.log` — captured server/tunnel output. The five untracked ones
  are regenerated by the server/watchdog; they were the only non-git-recoverable deletions.
* `probe.py` (a `FakeCursor` harness; only mentioned in a `tests/conftest.py` docstring comment),
  `verify_settings_panel.py` (one-off grep helper), `tmp.js` (mock report rows),
  `numbers_with_9.txt` (scratch number list), `Screenshot 2026-07-20 103814.jpg`.

---

## D. Removed code

| Where | What | Why it is unused |
| --- | --- | --- |
| 12 Python modules | 35 unused imports (pylint `W0611`, then manually re-verified per name) | Never referenced in the importing module; nothing imports these names *through* those modules (checked for re-export use). Files: `main.py` (3), `api/routes/{araz_api,call_system,master_admin,notifications,registration,ticketing}.py` (3+3+8+2+1), `services/{araz_connector,audit,automation,background_tasks,presence_summary,ticket_print}.py` (2+3+2+1+1+3). |
| `app/core/config.py` | 9 unused constants: `API_PREFIX`, `VERSION`, `MAX_CONNECTIONS_COUNT`, `MIN_CONNECTIONS_COUNT`, `MEMOIZATION_FLAG`, `PROJECT_NAME`, `MODEL_PATH`, `MODEL_NAME`, `INPUT_EXAMPLE` | No reference outside `config.py` (checked `app/tests/tools/scripts`); `MODEL_*`/`INPUT_EXAMPLE` existed only for the removed ML scaffold. `DEBUG`, `SECRET_KEY`, `config`, `LOGGING_LEVEL` are kept — `DEBUG`/`SECRET_KEY` are imported by `app/main.py`, and the module's logging side effect is intentional. |
| `app/static/js/user-panel-script.js` | `blueLength` (line 864), `isSidebarExpanded()` (901-911), `calculateFirstDayOfWeekForNextYear()` + its helper `daysInPersianYear` (2105-2112) | Repo-wide identifier search returned exactly one occurrence each (the declaration). `blueLength`/`blueOffset` siblings are still used by the shift-ring animation; the two functions have no callers and no `window.*` export. |

---

## E. Preserved code (investigated, intentionally kept)

| Item | Classification | Reason for keeping |
| --- | --- | --- |
| `app/core/config.py`, `app/core/logging.py` | ACTIVE_RUNTIME | Imported as `core.config` (see the `sys.path.append` in `app/main.py:15-16`); supplies `DEBUG`, `SECRET_KEY`, `config` and installs the loguru interception. |
| `tools/bridge_agent.py`, `tools/card_mapping.json`, `tools/bridge_config.json`, `tools/migrate_passwords.py` | ACTIVE_INTEGRATION / OPERATIONAL | Araz bridge agent + password migration; the bridge is a separate service, not a web page. |
| `tools/audio_generation/*` | ACTIVE_INTEGRATION | Generates the 2 000 `static/audio/sample_call/fa-IR-DilaraNeural/*.mp3` files that `call-display.js` + `call_system.py` serve. |
| `tools/mobile-preview/*` | DEVELOPMENT_ONLY | Local preview harness; referenced by nothing in production but harmless and developer-facing. |
| `app/static/audio/sample_call/**` (2 000 mp3, 62.7 MB) | ACTIVE_RUNTIME | Served dynamically: `call-display.js` `AUDIO_BASE = '/static/audio/sample_call/fa-IR-DilaraNeural/'` and `call_system.py` `audio_dir`. Numbered files ⇒ unreferenced by name, active in practice. |
| `app/static/slides/*.jpg`, `app/static/uploads/It.jpg` | ACTIVE_RUNTIME | Uploaded at runtime; served via `/static/slides/` + DB rows (`call_system.py` slideshow endpoints); referenced in `database/exports/latest.sql`. |
| `app/static/vendor/persian-date/persian-date.min.js` | ACTIVE_RUNTIME | Loaded by `admin.html`. |
| `app/static/js/html2pdf.bundle.min.js` (906 KB) | ACTIVE_RUNTIME | No `<script>` tag: it is injected at print time by `final-report-print.js`. |
| `app/services/{predict,prediction}` removal → `numpy`, `scikit-learn`, `joblib` | see §H | The three packages have no remaining importer, but the lockfile/edit decision is deferred to the owner (below). |
| `pdfkit` | ACTIVE_RUNTIME | `app/main.py` calls `pdfkit.from_file(...)` for report PDFs (with the documented CVE guard). |
| `websockets` | ACTIVE_RUNTIME (indirect) | Uvicorn's WebSocket protocol implementation for the call-system WS endpoints. |
| `python-multipart` | ACTIVE_RUNTIME (indirect) | Required by FastAPI for form/file uploads (`slides/upload`, attachments). |
| `arazin/` (59 475 files, 296 MB) | ACTIVE_INTEGRATION (vendor) | Araz attendance vendor tree (FoxPro/OCX/MDB). Explicitly out of scope for removal. |
| `offline/` (45 files, 69 MB) + `offline/Offline-run-server.txt` | ACTIVE_PRODUCTION_INFRASTRUCTURE | Offline install bundle (`install-offline.ps1`, wheels). |
| `database/` (48 MB), `scripts/export_db.py`, `scripts/restore_db.py` | OPERATIONAL | DB export/restore tooling and dumps. |
| `docs/guides/` (3 docs, 114 KB; was the misspelled `nessesary files/`) | DOCUMENTATION_ONLY | Persian operational notes (DB export procedure, removing the Windows first-run). |
| `database/sql-code.txt` | LEGACY_BUT_REFERENCED | Documents triggers on `avalpss_table` / `totalpass_table` that still exist in the operational DB — a DBA reference, not code. |
| `logs/` (49 MB, gitignored) | OPERATIONAL | Runtime watchdog/boot logs. |
| `testapp.egg-info/`, `__pycache__/`, `.pytest_cache/` | DEVELOPMENT_ONLY | Build/cache artefacts, gitignored; deleting them would not shrink the deployed app but could disturb the editable install metadata. |
| `.kilo/` (61 854 files, 523 MB) | UNKNOWN — **not touched** | Another tool's git worktree, listed in `.git/info/exclude`. It is the single largest reclaimable item on disk, but it belongs to an external agent and deleting it would destroy that worktree. Owner decision. |
| `docs/`, `*.md` security audits | DOCUMENTATION_ONLY | Kept; two stale font lines were corrected (see below). |

---

## F. Routes

243 routes registered. All were inventoried with method, path, handler, guards and callers.
**0 routes removed.** Sixteen routes have no literal caller string in any file
(`/api/calls/queue/*`, `/api/calls/{conversation_id}/*`, `/api/automation/...`,
`/master-admin/api/subscriptions/{id}`, `/ticket-print`); each one is reached through
dynamically built URLs (`/queue/${id}`, template literals) or is a page/kiosk entry point, so they
are classified ACTIVE and were left untouched.

---

## G. Tests and runtime verification

```text
Targeted tests (production_supervision, network_url_policy, security_regressions,
                security_hardening, user_panel_context)               224 passed
Full pytest                                                          13 failed, 790 passed, 4 skipped
Failures before                                                       13 (identical set)
Failures after                                                        13 (identical set)
New failures                                                           0
tests/js (node --test)                                                not runnable: jsdom not installed
                                                                      (node_modules/ is gitignored) — pre-existing
pylint W0611 after cleanup                                             0 (was 35)
python -m py_compile app/**/*.py                                       OK
node --check app/static/js/user-panel-script.js                        OK
git diff --check                                                       clean
```

The 13 pre-existing failures are the known set (`test_dark_theme.py` ×8,
`test_final_report_print.py`, `test_responsive_tables.py`, `test_ticketing_service.py` ×2,
`test_user_panel_theme.py`) and are unrelated to this cleanup.

Live verification on a temporary instance (`127.0.0.1:5099`, `SESSION_SECRET_KEY` overridden),
**48/48 checks passed**:

* pages 200: `/login`, `/register`, `/rules`, `/ticket-kiosk`, `/offline`, `/iran-only`;
  guarded pages (`/admin`, `/user-panel`, `/final-report`, `/master-admin`) do not 5xx;
* `/health`, `/sw.js` (served from the origin root, still caches), `/api/system-config`
  (still carries the login-experience keys), `/captcha`, `/captcha/status`;
* static assets that must keep working: `admin.css`, `login-style.css`, `master-admin.css`,
  `user-panel-style.css`, `call-tokens.css`, `admin.js`, `master-admin.js`,
  `user-panel-script.js`, `script.js`, `label-system.js`, `html2pdf.bundle.min.js`,
  `newlogo.png`, `lab-logo.png`, `user.png`, `Vazir.woff2/.ttf`, `persian-date.min.js`,
  sample-call mp3 #1 and #2000, `favicon.ico`, an uploaded slide and `uploads/It.jpg` → all 200;
* the five removed assets → 404;
* security surface intact: CSP header, `X-Frame-Options`/`frame-ancestors`, `httponly` session
  cookie, `csrf_token` cookie, `/master-admin/api/*` still guarded.

---

## H. Remaining unknowns / deferred decisions (nothing here was deleted)

1. **392 CSS class selectors** are never spelled out outside their own stylesheet
   (`admin.css` 71, `dark-theme.css` 69, `user-panel-style.css` 61, `ticketing.css` 33,
   `hastama-ux.css` 29, …). They were **not** removed, because the codebase builds class names
   dynamically and the detector demonstrably produces false positives:
   `toast.className = 'toast toast--' + type`, `` `ticket-workspace-badge--${kind}` ``,
   `wrap.classList.add('rt-badge--' + p[0])`, `h-ux-skeleton--*`, `tr-cat-card__icon--*`.
   Proven-safe removal needs a per-family review (≈40–60 KB of the 1.39 MB CSS).
   This is the best remaining *page-load* candidate.
2. **Page payloads are large** (`admin.html` 1.36 MB, `user-panel.html` 845 KB, uncompressed).
   Every stylesheet/script on those pages is referenced by the page's own markup or JS, and
   `tests/test_dark_theme.py` asserts `dark-theme.css` is the last stylesheet, so trimming
   requires a deliberate, tested refactor rather than a dead-code pass.
3. **Dependencies**: after removing the ML scaffold nothing imports `scikit-learn`, `joblib` or
   `numpy` (they were only used by the deleted demo model); `mangum` is an unused optional extra.
   They were left in `pyproject.toml` / `uv.lock` on purpose: editing one without regenerating the
   other produces a stale lock that can break the next offline `uv sync`. Removing them is a
   two-minute change **with** `uv lock` available:
   `scikit-learn`, `joblib` (dependencies) and the `aws = ["mangum"]` extra.
   Note `.env` still defines `MODEL_PATH`, `MODEL_NAME`, `MEMOIZATION_FLAG` — now inert.
4. **`.kilo/`** — 523 MB, 61 854 files, another agent's worktree (excluded via `.git/info/exclude`).
   Not part of Hastama; owner should delete it manually if the tool no longer needs it.
5. **`logs/`** — 49 MB of runtime logs (gitignored), incl. `_tmp_uvicorn.err` style captures.
   Rotate/trim at the operator's discretion.
6. **Untracked scratch drafts** — `_tmp_vpn_page.html` (an earlier draft of the committed
   `app/templates/vpn-warning.html`) was deleted and is *not* recoverable from git.
   All other deleted files exist in git history:
   `git restore --source=<commit> -- <path>` (see §C for the commits).

---

## I. Git state (nothing committed, nothing pushed)

```text
git status --short   116 entries: 71 deletions (D), the audit's code edits (M),
                     the pre-existing uncommitted work from the LAN-access /
                     outage-page / login-experience threads, plus untracked
                     docs and script files
git diff --stat      90 files changed, 2959 insertions(+), 33876 deletions(-)
                     (the insertions and most modifications are the earlier
                      uncommitted sessions; this cleanup contributes the
                      deletions in §C plus the small code edits in §D)
git diff --check     clean (no whitespace errors)
```

Attribution warning: the working tree already contained substantial uncommitted work when this
audit started (`app/services/{lan_access,outage,login_experience,iran_access,system_config}.py`,
`app/static/{sw.js,js/offline-guard.js}`, the login/MFA… UI changes, several new tests and docs).
This audit did not touch those; it only added the removals listed in §C/§D and the two
documentation corrections (`docs/offline/OFFLINE_DEPENDENCIES.md`, `docs/ALL_PROJECT_DOCS.md`).
