# AGENTS.md

## Project Notes

- This is a FastAPI/Jinja application. App code lives under `app/`; tests live under `tests/`.
- Static frontend assets are in `app/static/` and templates are in `app/templates/`.
- The main UI surfaces are `user-panel.html` with `user-panel-style.css`/`user-panel-script.js`, and `admin.html` with `admin.css`/`admin.js`.

## Working Rules

- Preserve existing desktop layout and behavior unless a task explicitly asks to change it.
- Prefer scoped CSS/JS changes over broad rewrites.
- Keep RTL behavior intact for Persian UI.
- Avoid introducing horizontal page overflow; wide tables should scroll inside their own containers.
- Be careful with existing uncommitted changes. Do not revert user work.

## Useful Commands

- Run tests: `pytest`
- Run app locally: `make run`

## Theme (Light/Dark) Layer

- `app/static/css/dark-theme.css` + `app/static/js/theme.js` are the shared theme
  layer, loaded LAST in the `<head>` of every template.
- `theme.js` is the single source of truth: it sets BOTH legacy class names
  (`dark-mode` and `dark-theme`) on `<html>` and `<body>`, persists the choice in
  `localStorage` (`hastama-theme`), falls back to the OS preference, and injects a
  floating toggle on pages with no toggle of their own (the report pages).
- Any element with `data-action="toggle-theme"` (or `#themeToggleBtn` /
  `#themeToggleButton` / `.theme-toggle`) toggles the theme — no inline `onclick`.
- All dark rules live in `dark-theme.css` and are scoped under `body.dark-mode`;
  do not add dark colors to the per-page stylesheets.
- Never set colors via inline `style` in JS — inline styles beat the theme layer.
  Use a class (see the calendar `.holiday` / `.red-day` classes).
- Print always renders light; keep the `@media print` block at the end intact.
- Tests: `tests/test_dark_theme.py` (coverage) and `tests/test_dark_theme_dom.py`
  → `tests/js/theme.dom.test.js` (behavior).

## Dashboard Layer (Admin) — «بوم داده» (نسخهٔ ۲)

- `app/static/css/dashboard-modern.css` + `app/static/js/dashboard-modern.js` own the
  ENTIRE look and motion of `#dashboardBox.hx-dashboard`; the legacy dashboard card
  rules were removed from `admin.css`. Do not re-add dashboard visuals there.
- **قاعدهٔ اصلی طراحی: هیچ باکس/کارتی وجود ندارد.** هیچ بلوکی نباید `background:`
  یا `box-shadow:` یا گوشهٔ گردِ کارتی بگیرد؛ عمق فقط با لایهٔ جوی (`.hx-atmos`)،
  خط‌های مو (`border-inline-start` / `border-block-start` با `--hx-line`) و هاله
  ساخته می‌شود. تنها استثنا «نوار قهرمان» (`.hx-hero`) است که یک باند گرادیانی
  تمام‌عرض با سایهٔ داخلی است، نه کارت.
  این قاعده با `tests/test_dashboard_modern.py::test_no_card_chrome_on_dashboard_blocks`
  و `test_hero_is_a_band_not_a_card` تضمین می‌شود.
- Load order matters: `dashboard-modern.css` comes AFTER `admin.css` and BEFORE
  `dark-theme.css` (dark must keep the last word). Dark rules for the new surfaces
  live in `dark-theme.css` under `body.dark-mode #dashboardBox …`.
- `#dashboardBox` must never set `display` in CSS — `toggleBox()` shows/hides every
  section through inline `style.display` (`display: flex` for the dashboard).
- **هیچ‌چیز در حالت پایه پنهان نمی‌شود**: انیمیشن‌ها فقط با کلاس `is-in` (که
  IntersectionObserver اضافه می‌کند) شروع می‌شوند؛ پس بدون JS هم داشبورد کامل است.
  قلاب‌های مقدار: `data-hx-count` (شمارش عدد)، `data-hx-bar` + `data-percent`
  (رشد ستون)، `data-hx-ring` (جاروی حلقه)، `data-hx-meter` (نام قدیمی `data-hx-share` هم پذیرفته می‌شود)
  (نوار سهم)، `data-hx-split` (نوار توزیع)، `data-hx-fa` (ارقام فارسی)،
  `data-hx-spot` (هالهٔ نشانگر).
- متغیرهای انیمیت‌شدنی با `@property` ثبت شده‌اند (`--hx-p`, `--hx-h`, `--hx-w`،
  همه با `inherits: true`). JS فقط «مقدار نهایی» را می‌نویسد و خود CSS با easing
  فنری باز می‌کند؛ رنگ هرگز با style اینلاین ست نمی‌شود (AGENTS.md).
- Server contract (`app/main.py` → `admin.html`): the dashboard reads existing keys
  plus `subscription_days_left` / `subscription_end_date` /
  `subscription_remaining_percent`; the area-chart geometry (`polyline`/`path` and
  point coordinates) is computed in the Jinja template so the chart is complete
  without JS. Adding a card means updating both sides.
- Legacy hook classes (`.dashboard-card`, `.dashboard-card--highlight`,
  `.dashboard-chart-card`, `.dashboard-table-card`, `.dashboard-quick-card`,
  `.dashboard-table`, `.card-title/.card-value/.card-meta`, `.table-title`,
  `.quick-card-btn`, `.chart-bar/.bar-*`) are kept on the new markup so the
  dark-theme and responsive-table suites stay green — but they are neutralised:
  `dark-theme.css` sets `background: none; box-shadow: none` for them.
- Docs & preview: `docs/dashboard/design-system.md`; `python preview/render.py`
  (offline render with mock data) and `python preview/serve.py` → real browser
  preview at `/admin/dashboard` without SQL Server.
- Tests: `tests/test_dashboard_modern.py` (static contract) and
  `tests/test_dashboard_modern_dom.py` → `tests/js/dashboard-modern.dom.test.js`
  (jsdom behaviour).

## Responsive Tables Layer

- `app/static/js/responsive-tables.js` + `app/static/css/responsive-tables.css` are the
  shared mobile presentation layer for ALL tables (patterns: cards / list / scroll / keep).
- Mobile views are derived from the same `<table>` DOM (no duplicated API/business logic);
  interactive controls are moved into cards and restored on desktop/print.
- To add a table, register it in `CONFIGS` inside responsive-tables.js (see docs/mobile-tables.md).
- Keep `tests/test_responsive_tables.py` green; the jsdom suite lives in tests/js.
