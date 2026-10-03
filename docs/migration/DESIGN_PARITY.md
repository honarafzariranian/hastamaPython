# DESIGN PARITY — the migrated UI against the running application

The brief requires the migrated pages to **look exactly like the running
application**, not like a redesign.  This document records how that was
measured, what already matched, what was broken and is now fixed, and exactly
what is still missing.  Every number here is reproducible with the two tools
listed at the end.

Date: **2026-10-02** · Reference application: `http://127.0.0.1:5000` (FastAPI) ·
Migrated application: `http://127.0.0.1:8000` (Laravel + Vue)

---

## 1. Why class names and stylesheets decide this

The migrated stylesheets under `laravel/resources/css/legacy/` are the running
application's own files, so a migrated page renders identically when — and only
when — it:

1. uses the **same class names** on the same kind of elements, and
2. has the **same stylesheets** in scope (the page's linked files *and* its own
   inline `<style>` block).

The two tools check exactly those two things, and the report below is their
output adjudicated by hand:

| Tool | Question |
|---|---|
| `tools/design_parity.py` | does the Vue page use every class the Python template uses? |
| `tools/css_coverage.py` | is every stylesheet the Python page loaded (linked **and** inline) present on the Laravel side? |

A third check covers the pages that Laravel serves as whole documents rather
than through the SPA: served HTML is compared against the Python server's own
response, tag by tag and text node by text node.

---

## 2. Verdict per page

### 2.1 SPA pages — markup parity

`missing` counts the legacy classes the Vue page does not produce.  `composed`
counts names Vue builds at runtime (`` `x--${modifier}` ``), and `adjudicated`
counts matches proved equivalent by hand (a `<body>` class applied on mount, or
a Jinja loop variable caught by the scanner).

| Python template | Vue page(s) | legacy | missing | composed | adjudicated | Verdict |
|---|---|---:|---:|---:|---:|---|
| `login.html` | `auth/LoginPage.vue` | 87 | **0** | 0 | 0 | identical |
| `register.html` | `public/RegisterPage.vue` | 40 | **0** | 0 | 0 | identical |
| `rules.html` | `public/RulesPage.vue` | 74 | **0** | 4 | 0 | identical |
| `training.html` | `public/TrainingPage.vue` | 47 | **0** | 4 | 2 | identical |
| `training-lesson.html` | `public/TrainingLessonPage.vue` | 37 | **0** | 0 | 1 | identical |
| `vpn-warning.html` | `public/IranOnlyPage.vue` | 10 | **0** | 0 | 0 | identical |
| `offline.html` | *(document — see 2.3)* | 11 | **0** | 0 | 3 | identical |
| `call-management.html` | `call/CallManagementPage.vue` | 116 | **0** | 0 | 0 | identical |
| `call-display.html` | `call/CallDisplayPage.vue` | 64 | **0** | 1 | 0 | identical |
| `ticket-kiosk.html` | `call/TicketKioskPage.vue` | 138 | **0** | 0 | 1 | identical |
| `label_print_document.html` | `call/TicketPrintPage.vue` | 2 | **0** | 0 | 0 | identical |
| `master-admin.html` | `layouts/ControlCentreLayout.vue` + 10 control pages | 152 | **69** | 0 | 0 | **gap** — §4.3 |
| `user-panel.html` | `layouts/UserPanelLayout.vue` + 8 user pages | 327 | **83** | 0 | 0 | **gap** — §4.2 |
| `admin.html` | `layouts/AdminLayout.vue` + 8 admin pages | 654 | **587** | 0 | 0 | **gap** — §4.1 (shell done, bodies pending) |

`extra` classes on the Vue side (`h-card`, `admin-shell`, `report-field`, …) are
**not** parity successes: they are the migrated design talking to Tailwind,
which is precisely what those three gaps are made of.

### 2.2 Document pages — byte/text parity

These URLs are standalone documents in the running application (their own
inline stylesheet, their own script, their own `<title>`).  Laravel serves them
from `App\Support\Legacy\LegacyReportPage` and `App\Support\Connectivity\*`, so
the comparison is against the running server's own response:

| Route | Result |
|---|---|
| `/leave_report_page` | **identical** to the Python rendering |
| `/hourlypass_Report_page` | **identical** |
| `/overtime_report_page` | **identical** |
| `/payroll_report_page` | **identical** |
| `/final_report_page` | identical except the dynamic title — `گزارش مهر ماه 1405 حضور و غیاب`, computed from the current Jalali date |
| `/offline` | **identical visible text** (82 text nodes) — see §3.2 |
| `/iran-only` | **identical visible text** (86 text nodes) — see §3.3 |

### 2.3 Stylesheet coverage

| Python page | linked stylesheets | inline `<style>` rule set | Verdict |
|---|---|---|---|
| every page except the kiosk | all ported into `resources/css/legacy/` and imported by `app.css` | none | complete |
| `ticket-kiosk.html` | `call-tokens.css` | **166 class selectors** | was 4/166 — **fixed**, now 166/166 (§3.1) |
| `offline.html` | none | 12 class selectors | 8/12 in the SPA duplicate; the routed document carries all 12 (§3.2) |

The 28 ported stylesheets are byte-identical to `app/static/css` except for the
rewrites the port documented: `/static/fonts|images` → `/fonts|images` (three
files) and the dropped `@import url('call-tokens.css')` in `call-display.css`
(the import is global in `app.css`).

---

## 3. Defects found and fixed in this pass

### 3.1 The kiosk had no stylesheet at all — **fixed**

`app/templates/ticket-kiosk.html` is a standalone document whose whole
appearance lives in one inline `<style>` block: **1,865 lines, 437 rules, 166
class selectors**.  The Vue rewrite reproduced the markup and the behaviour, but
the stylesheet was never carried across, and no file under
`laravel/resources/css/` contained a single `k-*` rule.

Measured in the browser, before the fix:

```text
document.querySelector('.k-container')            -> exists
[...document.styleSheets] matching 'k-container'  -> 0
getComputedStyle(.k-container).backgroundColor    -> rgba(0, 0, 0, 0)
```

The page rendered as unstyled text — the kiosk is the busiest screen in the
building and it was the largest single visual defect in the migration.

**Fix.** The block was ported verbatim to
`laravel/resources/css/legacy/ticket-kiosk.css` (only the three `/static/fonts/`
URLs rewritten, exactly like the other ported sheets) and is applied by the
component while the route is mounted:

* `TicketKioskPage.vue` imports it with `?inline` and injects a
  `<style data-page="ticket-kiosk">` on mount, removing it on unmount;
* the component adds the legacy `<body class="portrait">` for the same lifetime.

It is deliberately **not** added to `app.css`: the legacy block starts with
`* { margin: 0; padding: 0; box-sizing: border-box }` and a `body` rule — global
selectors that belong to the kiosk document and would break every other page.

Verified after the fix: 376 injected rules with the page's own viewport
(566 px wide → `.k-container` computed width 566 px), `body` carrying
`dark-mode dark-theme portrait`, and the page rendering its dark gradient, queue
badge, clock and service cards.

### 3.2 `/offline` — a missing hamza and a divergent duplicate page — **fixed**

The served document reproduced `app/templates/offline.html` except for one text
node:

```diff
-<button id="offlineCopy">کپی آدرس شبکهٔ داخلی</button>   (Python)
+<button id="offlineCopy">کپی آدرس شبکه داخلی</button>    (Laravel)
```

and the SPA router registered `/offline` — a **second, divergent** rendering of
the same URL that could not be complete: the legacy document's LAN-address block
is server state (`lan_url`, `offline.page_context()`) rendered only when the
operator has switched LAN access on, and no public API carries it.  The Vue page
therefore had `lan`, `lan__title` and `lan__url` missing, and its stylesheet was
8/12.

**Fix.** The missing hamza is restored, and the `/offline` router entry is
removed: a full page load reaches the real document, which is what the legacy
link did too.  `OfflinePage.vue` is left on disk, marked `SUPERSEDED` in its own
docblock, so the deletion decision stays with the maintainer rather than with
this audit.

Verified: the served document's 82 visible text nodes are now **identical** to
the Python server's.

### 3.3 Operator copy was not escaped in the two served documents — **fixed**

FastAPI's `Jinja2Templates` builds its environment with
`autoescape=jinja2.select_autoescape()`, which is **on** for `.html` templates.
The ported documents are nowdoc templates with `{{placeholder}}` markers that
PHP substitutes, and PHP escaped nothing — so the title, message and help lines
(operator-supplied through the master-admin settings and shown to
**unauthenticated** visitors on the outage and access-policy pages) were
rendered as markup.

That is both a security regression against a hardened implementation and the
cause of the only remaining difference in `/iran-only`, where the default help
text contains an ampersand:

```diff
-روی iPhone: … VPN &amp; Device Management …   (Python)
+روی iPhone: … VPN & Device Management …     (Laravel)
```

**Fix.** `App\Support\Legacy\LegacyHtml::escape()` reproduces
`markupsafe.escape` exactly (`&`→`&amp;`, `<`→`&lt;`, `>`→`&gt;`,
`'`→`&#39;`, `"`→`&#34;` — note that PHP's `htmlspecialchars` emits
`&quot;`/`&#039;` and is *not* a drop-in), and is applied to every
operator-supplied placeholder in `IranOnlyPage` and `OfflinePage`.

Verified: both served documents' visible text is identical to the Python
server's.

### 3.4 The dark-theme layer was imported in the wrong position — **fixed**

This one no tool in §1 could see: it is a **cascade** defect, so every class
name and every stylesheet was present, but the wrong rule won.

The running application loads each page's own stylesheet and then
`dark-theme.css` after it:

```text
admin.html         admin.css … dark-theme.css
user-panel.html    user-panel-style.css … dark-theme.css
master-admin.html  admin.css, master-admin.css, … dark-theme.css (last)
```

`app.css` imported `dark-theme.css` **third** (it is still listed that way in
`bootstrap` history), and that order decides a real winner: `admin.css` carries
an old `.dark-theme body { color: #f8fafc }` block and `dark-theme.css` carries
`body.dark-mode { color: var(--dk-text) }` — same specificity, so the later file
wins.  Measured on `/login`:

| property | running application | Laravel (before) | Laravel (after) |
|---|---|---|---|
| `body` colour | `rgb(232, 237, 245)` | `rgb(248, 250, 252)` | `rgb(232, 237, 245)` |
| `.login-card` colour | `rgb(232, 237, 245)` | `rgb(248, 250, 252)` | `rgb(232, 237, 245)` |
| `body` background colour | `rgb(15, 23, 37)` | teal wash from `admin-mobile-redesign.css` at mobile widths | `rgb(15, 23, 37)` |

**Fix.** `dark-theme.css` is imported **last** in `app.css`, which is what the
panels actually did; the comment in `app.css` records the evidence.  Verified at
1200 px and 600 px: `body` colour, `body` background colour, `body` gradient
layers, `.login-card` colour and background and the `h1` colour are now identical
to the running server's.

**What this does not fix.**  One global import list cannot reproduce *per-page*
load order, and two pages never loaded `dark-theme.css` at all
(`rules.html`: `vazir.css` + `rules.css` only; `call-display.html`:
`vazir.css` + `call-display.css`).  In the running application those two pages
are therefore **light even when the theme is dark**, while the migrated SPA keeps
theming them.  Reproducing that exactly means carrying a per-route stylesheet
set (Vite route-level CSS + lazy routes) instead of one global `app.css`; it is
recorded here as the known remainder of the cascade question rather than
implemented half-way, because a partial version would leave other pages
inconsistent.  See §7.

### 3.5 Two test data providers could not run under PHPUnit 12 — **fixed**

`MasterAdminControlTest::paginatedLists()` and
`PublicPagesTest::publicShells()` returned flat strings; PHPUnit 12 requires each
dataset to be an array of arguments, so both provider-driven tests reported
`The data provider … is invalid` and the suite could not be trusted as evidence.

**Fix.** Each dataset is wrapped.  The suite is now **1138 passed, 59 skipped,
0 failures** (`php artisan test`).

---

### 3.6 The login screen rendered like the admin panel — **fixed**

Reported as *“the username and password area does not look exactly like
Python”*.  The markup was already element-for-element identical
(`app/templates/login.html` vs `pages/auth/LoginPage.vue`), so every visible
difference was cascade.

`login.html` loads five stylesheets — `vazir`, `dark-theme`, `login-style`,
`hastama-ux`, `toast` — and nothing else.  The SPA loads one bundle, so the
panel and report sheets ran on `/login` too.  Measured at 1200×800 in dark
theme, `:5000` vs `:8000`, the leaks were:

| leaked declaration | source | what it did on `/login` |
|---|---|---|
| `input[type="text"]` + its ≤768px twin | `admin.css:1693`, `:8035` | `#username` 150px wide, radius 10px, weight 700 |
| `form button` (+ `:hover`, `:active`) | `admin.css:1704`, `:1717`, `:3837` | `#loginBtn` padding `12px 7px`, background `#7f92fa` |
| report-button gradient list | `admin.css:3777` | `#loginBtn` green→teal→sky gradient, radius 20px |
| `.btn` | `final-report-style.css:579`, `:1044` | `#loginBtn` 128px wide, `#4CAF50`, radius 10px |
| `body { line-height: 1.6 }` | `master-admin.css:110` | every login line inherited 1.6 |
| `html { line-height: 1.5 }` | Tailwind preflight | the same, once the rule above was scoped |
| `body { line-height: 1.8 }` | `rules.css:74` | card 5px taller than the original |
| `:root { --accent:#00B894 … }`, `body.dark-mode { … }` | `user-panel-style.css:13`, `:47` | forgot-password, focus border and label in the user panel's green |

**Fix — scoped at the source, not patched on the login page.**  Each of those
sheets is loaded by exactly one template in the running application, and each
target page has a root marker, so the rules were *narrowed to their own page*
instead of being re-stated or deleted:

| sheet | scope added | marker |
|---|---|---|
| `admin.css` (date field, report button, gradient list) | `:where(.page-shell, .ma-page-shell, .admin-shell)` | `admin.html` `.page-shell`, `master-admin.html` `.ma-page-shell` |
| `final-report-style.css` `.btn` | `:where(.final-report-page)` | `final_report_page.html` `body.final-report-page` |
| `rules.css` body | `body:has(.rl-progress)` | `rules.html` (its only page) |
| `user-panel-style.css` tokens + body | `body:has(.app-shell)` | `user-panel.html` `.app-shell` |
| `master-admin.css` body | `body:has(.ma-page-shell)` | `master-admin.html` `.ma-page-shell` |

`:where()` contributes no specificity, so every scoped rule still outranks (and
still applies to) the pages it belongs to — verified by injecting the markers
into a live page: inside `.admin-shell` a bare `input[type="text"]` is still
`150px/8px/10px` bold, `form button` still takes the gradient, a
`.ma-page-shell` still yields `line-height: 25.6px`, `.app-shell` still yields
`--accent: #00b894`, and `.final-report-page .btn` still renders `#4CAF50` at
128px — while with no marker present the same elements fall back to the plain
input/button defaults the Python login page shows.

**Two declarations are order, not scope.**  On `/login` the original loaded
`login-style.css` *after* `dark-theme.css` and `hastama-ux.css`, and one bundle
cannot have both that order and the panels' (`§3.4`).  `app.css` therefore ends
with a short, commented bridge scoped to
`body:has(.login-card)` — the marker only this screen has (register has no
`.login-card` and no `.h-ux-btn`, so it is untouched) — which restores exactly
three declarations: `--shadow-card` (the card's dark shadow), the
`.input-wrapper input::placeholder` colour, and `.h-ux-btn { font-weight: 700 }`.

The Tailwind preflight `line-height` was fixed in the base layer instead:
the original ships no reset at all, so a page that never declared a body
line-height inherited `normal`, while preflight forced 1.5.  `@layer base` now
sets `body { line-height: normal }`; unlayered legacy body rules (`admin.css`
mobile, `rules.css`, `master-admin.css`) still win where they exist.

**Result.**  Every measured value now matches `:5000` exactly:

| element | Python | Laravel before | Laravel now |
|---|---|---|---|
| `#username` | 374×48.39, radius 16, w400, lh `normal` | 150×53, radius 10, w700, lh 28.22 | **374×48.39, radius 16, w400, lh `normal`** |
| `#loginBtn` | 374×49, radius 16, gradient blue, ring shadow | 128×53, radius 10, `#4CAF50` | **374×49, radius 16, gradient blue, ring shadow** |
| `.login-card` | 555.34px tall, dark shadow `0 30px 70px -12px` | 572.59px, `--dk-shadow-sm` | **555.34px, `0 30px 70px -12px …`** |
| `.forgot-password` | `rgb(94,167,255)` | `rgb(0,184,148)` | **`rgb(94,167,255)`** |
| `.focus-border` | gradient from `#5ea7ff` | gradient from `#00B894` | **gradient from `#5ea7ff`** |
| body line-height | `normal` | `28.8px` | **`normal`** |

**Known remainder.**  `/admin` is still the Vue redesign of `§4.1`, so
`.admin-shell` is deliberately in the scope list: its `h-input` fields are
styled by nothing else yet, and narrowing that rule away would *change* that
screen rather than restore anything.  Register shares `login-style.css` but not
the bridge markers, so it keeps the bundle's order effects; it has not been
measured against `register.html` yet.  The real fix for this whole class of
leak remains the per-route stylesheet set in `§7`.

---

## 4. Remaining gaps (not fixed in this pass)

These three are the same defect class — the panel was rebuilt as a Vue design
instead of being ported — and each needs its own pass.  Nothing below is
guessed: the inventories come from `tools/design_parity.py`.

### 4.1 Admin panel — 587 of 654 legacy classes missing

`AdminLayout.vue` carries its own `admin-*` vocabulary (`admin-shell`,
`admin-header`, `admin-nav__item`, …) which appears in **no** ported stylesheet;
the legacy panel's vocabulary (`dashboard-card`, `attendance-panel`, `dash-ring`,
`helpdesk-*`, `karaneh-*`, `form-row`, `topbar`, `sidebar`, `modal`, …) is absent
from the Vue side.  Grouped by the legacy prefixes that are missing:

```text
attendance 41 · reg 39 · payroll 38 · nu 35 · dash 34 · shift 32 ·
notification 26 · overtime 22 · pd 19 · dashboard 18 · internal 17 ·
ticket 15 · vacation 13 · topbar 13 · hourlyPass 11 · sidebar 10 ·
sabt 10 · karaneh 10 · hozoor 9 · helpdesk 9 · day 9 · profile 8 ·
modal 8 · form 8 · confirm 7 · coworker 6 · checkout 6 · …
```

This is a rebuild of the panel body against the legacy markup, with the data the
eight existing `pages/admin/*.vue` components already fetch.  **It is the
largest remaining item in the migration.**

#### 4.1.1 Shell and URL space — **done** (2026-10-03)

The frame and the addresses now match the running application; the section
*bodies* above are what remains.

| What the Python serves | Before | Now |
|---|---|---|
| `/admin` → `303 /admin/dashboard`, anonymous → `303 /login` | ported route existed (`PublicPages\AdminController`) but the Vue router also owned `/admin` as a page | server route is the only handler; a duplicated `Route::redirect` was removed from `web.php` |
| `/admin/dashboard`, `/admin/{section}` | one `/admin` route with internal tab state; the URL never changed | `/admin/:section` in the router, section taken from `route.params.section`, rail pushes the legacy URLs (`SECTION_URLS` in `admin.js`) |
| `admin.html` chrome: `.page-shell`, `.topbar admin-topbar-modern ma-header-box`, `.navarha`, `.sidebar-right`, `.icon-container[data-accent]` tiles, `.management-box` per section | Vue redesign (`admin-shell`, `admin-header`, `admin-nav__item`) matching no stylesheet | legacy markup with the legacy classes and the ten rail tiles, id'd `dashboardBox`, `coworkerBox`, … so the `#id` rules (including the hover compression) apply |
| Full-screen document with its own background wash | wrapped in the Vue application shell (`h-header` bar + max-width `h-main`) | `meta.ownChrome` on the route skips that shell |

Deliberate differences, recorded rather than hidden:

* `reports` is an **extra** rail tile (`/admin/reports`, `ReportsPage.vue`) — the
  legacy panel linked its five report documents from inside the sections, so the
  port needs a surface for them until those sections carry their own buttons.
* `tickets`, `attendance` and `internal-automation` have **no Vue page**: the
  tiles render the legacy ids and the panel's own message saying so, instead of
  an empty box.  Their endpoints are already ported (ticketing, internal
  automation), so only the pages are missing.
* The skip link is added chrome (the legacy document has none) and is clipped
  off-canvas: an `inset-inline-start: -9999px` version widened the RTL document
  by 10,000px and left the panel scrolled sideways.

### 4.2 User panel — 83 of 327 legacy classes missing

The shell, the sidebar and the eight section pages match.  What is missing are
whole **modal surfaces** the legacy page carried and the Vue panel drops:

| Surface | Missing classes |
|---|---|
| support / ticketing centre (`user-support-*`) | 27 |
| internal automation (`internal-automation-*`) | 17 |
| notification centre + detail | 4 |
| profile panel | 7 |
| legacy modals & popups (`modal`, `popup-overlay`, `modal-hazf`, `popupHozoor-*`, `ticket-popup-Req`) | 10 |
| small pieces (`morakhaci-sabt`, `pass-title/pass-time/pass-more`, `table-container`, `close`, `edit-btn`, `trash-btn`, `tooltip-text-table-*`, `radif-hozoorTime`, …) | 18 |

### 4.3 Control centre — 69 of 152 legacy classes missing

| Surface | Missing classes |
|---|---|
| profile dropdown + profile panel (`pd-*`, `profile-dropdown`, `profile-panel*`) | 25 |
| label-printer studio (`ma-label-*`, `ma-printer-*`, `ma-preview-dot*`) | 20 |
| pagination, eyebrow, ticket stats, `admin-actions`, `audit-logs`, `ticket-detail` | 9 |
| Jinja artifacts (`active_section`, `if`, `or`, `dashboard`, `users`, …) | 15 — **false positives**, the scanner reads a Jinja expression inside a `class` attribute |

The label-printer studio is a whole missing section, not a styling difference.

---

## 5. One functional note the design review surfaced

The five report documents are fed by the legacy admin panel through
**`localStorage`** (`selectedUsername`, `overtimeReportData`, `leaveReportData`,
`hourlyPassReportData`, `totalOvertime`, `totalPresenceTime`, …) — the panel
stores the rows, then opens the report page which reads them back.  The Laravel
side reproduces the documents exactly, which is what this pass verified, but the
*producer* of those keys is the legacy `admin.js`; the Vue admin pages do not
write them.  Whichever way the remaining admin work goes, the report pages need
their data from the ported API instead — this is a functional gap, recorded here
because the design pass is where it was noticed.

---

## 6. Re-running the checks

```bash
# markup parity (per page pair; --summary for the table in §2.1)
python tools/design_parity.py --summary
python tools/design_parity.py admin.html

# stylesheet coverage (linked + inline), per template
python tools/css_coverage.py

# served documents against the running Python server
curl -s http://127.0.0.1:5000/offline   > /tmp/py.html
curl -s http://127.0.0.1:8000/offline   > /tmp/lv.html   # drop the Boost dev-logger script first
```

Exit code is the number of missing classes, so the check can be wired into CI
once the three panel gaps reach zero.

---

## 7. The next parity step, in order

1. **Per-route stylesheets.**  Move each page's own sheet out of `app.css` into
the component/chunk that needs it (lazy routes + `import '…css'`), keeping only
the genuinely global ones (`vazir`, `call-tokens`, `hastama-ux`, `toast`,
`tables`, `responsive-*`, `dark-theme`) global.  This is what closes §3.4's
remainder and removes the class of defect that produced it.
2. **The control centre** (69 classes: profile dropdown + profile panel, label
   studio) — the smallest of the three panel gaps and self-contained.
3. **The user panel's modal surfaces** (83 classes: support centre, internal
   automation, notification centre, profile panel).
4. **The admin panel** (650 classes) — a rebuild of the panel body against the
   legacy markup.  The shell and the URL space are done (§4.1.1); the bodies are
   not, and one of them needs a server addition first:

   | Section | Legacy markup | Blocker |
   |---|---|---|
   | `dashboard` | `dashboardBox` (330 lines: `dash-head`, `dash-bento`, `dash-row--podium`, `--insight`, `--charts`, `--tables`) | the numbers it prints (total overtime/pass time, per-user averages, top user and top department, the two percentages, the two five-row charts) were **template context** in `_render_admin_page`, not an endpoint — the port needs one, computed from `ezafe_total_table`, `totalpass_table`, `leave_report` and `user_table` exactly as the Python summed them |
   | `coworkers` | `coworkerBox` + `newUserBox` + `regRequestsBox` | `regRequestsBox` has no ported endpoint |
   | `vacation`, `overtime`, `hourly-pass`, `shifts`, `payroll` | the corresponding boxes | none — the endpoints are ported and the Vue bodies already call them |
   | `tickets`, `attendance`, `internal-automation` | `ticketBox`, `hozoorbox`, `internalAutomationAdminBox` | no Vue page at all |

   The report pages' `localStorage` dependency (§5) should be resolved in the
   same pass.

Each step is verifiable with the two tools plus a served-document diff, so
progress is measurable rather than asserted.
