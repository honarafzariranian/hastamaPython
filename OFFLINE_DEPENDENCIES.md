# OFFLINE_DEPENDENCIES.md

**Project:** Hestama (سامانه هستما) — FastAPI / Jinja2 / SQL Server (pyodbc)
**Scope:** Full offline-capable conversion. Core system must start and run with zero Internet access.

This document catalogues every dependency that matters for runtime, and whether it requires
Internet access.

---

## Legend

- **Local file** = shipped inside the repository, no network required.
- **Runtime offline** = does not contact any external server at runtime.
- **Build/dev only** = only needed during development/installation, not to run the app.
- **Optional** = the core app works without it.

---

## 1. Python Runtime Dependencies (from `pyproject.toml` → `uv.lock`)

All are installed into the local `.venv`/system. A Windows x64 / CPython 3.11
wheelhouse is shipped in `offline/wheels/`; see `offline/README.md` and
`offline/install-offline.ps1` for the no-index installation procedure.
**None of them perform outbound network calls at runtime.**

| Package | Version (lock) | Purpose | Runtime offline |
|---|---|---|---|
| fastapi | ≥0.103.0 | Web framework | Yes |
| uvicorn | 0.23.2 | ASGI server | Yes |
| pydantic | ≥2.0.0 | Validation | Yes |
| requests | ≥2.32.0 | HTTP client (present, **not used for any outbound call**) | Yes |
| loguru | ≥0.7.0 | Logging | Yes |
| joblib | ≥1.2.0 | ML model serialization | Yes |
| scikit-learn | ≥1.5.0 | ML model load/predict | Yes |
| pyodbc | ≥5.1.0 | SQL Server access (local) | Yes |
| jdatetime | — | Persian date conversions | Yes |
| jinja2 | ≥3.0.0 | HTML templates | Yes |
| pdfkit | ≥1.0.0 | PDF report generation | Yes |
| persiantools | ≥2.0.0 | Jalali date helpers | Yes |
| itsdangerous | ≥2.0.0 | Session signing | Yes |
| python-multipart | ≥0.0.5 | Form/file uploads | Yes |

**Note on `requests`:** imported/declared but no code path opens an external connection.
Verified by audit (no `requests.get/post/...`, `urllib`, `httpx`, `aiohttp`, `smtplib`,
`socket` outbound) in `app/`, `core/`, `ml/`.

---

## 2. Offline installation bundle

The repository includes `offline/requirements.txt`,
`offline/requirements-runtime.txt`, the Windows x64 / CPython 3.11 wheels in
`offline/wheels/`, and an installer that uses `pip --no-index`.

The bundle is platform-specific. Regenerate it for Linux, another Python minor
version, or another CPU architecture. Do not run `uv sync` or an unrestricted
`pip install` on the offline server, because those commands may contact a package
index.

## 3. Database

- **SQL Server (local)** via ODBC Driver 17, `localhost\SQLEXPRESS`, database `userDB`.
- Also reads a local MS Access file `E:\Hastama\database\Arazdb.mdb` for attendance entry lookup.
- Fully local. No cloud database. **Offline: Yes.**

---

## 4. Frontend Assets (all local under `app/static/`)

| Asset | Local file | Notes |
|---|---|---|
| CSS | `css/*.css` (admin, user-panel, login, report styles, responsive, tables, ticketing, notification, dark-theme, vazir) | No `@import` of remote, no remote `url()`. All `url()` point to `/static/...`. |
| JavaScript | `js/*.js` (admin, user-panel-script, script, theme, ticketing, notification-system, report scripts, responsive-tables) | All `fetch()` calls use **relative internal API routes** (`/login_user`, `/get_hozoor/...`, etc.). No `axios`, `XMLHttpRequest` to external hosts, no `WebSocket`, no `EventSource`. |
| Fonts | `fonts/Vazir.{woff2,woff,ttf}`, `fonts/Shabnam.ttf`, `fonts/Yekan.{woff2,woff,ttf}` | Declared in `css/vazir.css` with local `url('/static/fonts/...')`. No Google Fonts. |
| Icons | `images/*.png`, `images/exit.svg`, inline `<svg>` | All local. Inline SVGs use `xmlns="http://www.w3.org/2000/svg"` which is a **namespace declaration, not a network request**. |
| Images | `images/*` (logos, backgrounds, sliders, avatars, icons) | All referenced via `/static/images/...`. No remote `<img src="https://...">`. |
| Favicon | `favicon.ico` | Local. |

---

## 5. Vendored Third-Party Libraries (local)

| Library | Version | Purpose | Local file | Runtime offline |
|---|---|---|---|---|
| persian-date | 1.1.0 | Persian date/weekday formatting in admin shift report | `static/vendor/persian-date/persian-date.min.js` | Yes |

- Loaded in `app/templates/admin.html` via:
  `<script src="{{ url_for('static', path='vendor/persian-date/persian-date.min.js') }}"></script>`.
- Previously loaded from `https://cdn.jsdelivr.net/npm/persian-date/...` — now local.
- UMD build; verified to expose `window.persianDate` and produce correct Persian weekday names.

---

## 6. Removed External Dependencies

| Library | Previous CDN | Why removed |
|---|---|---|
| moment | `cdn.jsdelivr.net/npm/moment@2.29.4` | Not referenced by any JS in `final-report-script.js` or elsewhere. Unused. |
| moment-jalaali | `cdn.jsdelivr.net/npm/moment-jalaali@0.9.2` | Not referenced by any JS. Unused. |

Verified: no `moment(`, `jmoment`, `jalaali` usage exists in any project JS. The removed
scripts were dead references.

---

## 7. Notifications

Internal notification centre (admin + user) implemented in `js/notification-system.js` with a
comment header: **"No third-party dependency."**
- Uses relative `fetch(url, config)` calls to internal API routes.
- No external push/SMS/email/WebSocket/Firebase or browser notification. **Offline: Yes.**

---

## 8. Charts

Dashboards use **CSS/JS bar charts** (e.g. `.dashboard-chart .bar-value`) rendered by `admin.js`.
No Chart.js / ApexCharts / ECharts / remote chart library. **Offline: Yes.**

---

## 9. Authentication

- Login (`/login_user`) verifies credentials against the **local SQL Server** (`user-table`).
- Session handled by Starlette `SessionMiddleware` with a local `SESSION_SECRET_KEY`.
- No OAuth, no Google/Microsoft, no external CAPTCHA, no remote token validation. **Offline: Yes.**

---

## 10. Private-LAN Integrations

The application has no public SMS, email, payment, map, CAPTCHA, analytics, telemetry,
or third-party API integration. It does have deployment-local integrations:

- SQL Server through ODBC.
- The local Araz Access database and configured Araz TCP device.
- The optional bridge agent configured in `tools/bridge_config.json`.

These are private-LAN/local dependencies, not Internet dependencies. They must remain
reachable for the associated features, or those features must be disabled.

---

## 11. Build / Dev Dependencies (not needed at runtime)

| Item | Purpose | Runtime offline |
|---|---|---|
| `uv` / pip | Package installation (development only) | N/A (not part of running app) |
| jsdom (`tests/js`) | Frontend DOM tests (development only) | N/A |
| Docker (docker-compose) | Containerized deployment | N/A (runtime deps are local) |

The production app is served local-CDN-free from `app/static`. No build step (no Vite/Webpack)
is required to produce runtime HTML — templates render directly via Jinja2.
