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

## Dashboard Layer (Admin)

- `app/static/css/dashboard-modern.css` + `app/static/js/dashboard-modern.js` own the
  ENTIRE look and motion of `#dashboardBox.hx-dashboard`; the legacy dashboard card
  rules were removed from `admin.css`. Do not re-add dashboard visuals there.
- Load order matters: `dashboard-modern.css` comes AFTER `admin.css` and BEFORE
  `dark-theme.css` (dark must keep the last word). Dark rules for the new surfaces
  live in `dark-theme.css` under `body.dark-mode #dashboardBox …`.
- `#dashboardBox` must never set `display` in CSS — `toggleBox()` shows/hides every
  section through inline `style.display`.
- JS only supplies motion (counters, bar/ring/share widths, reveal, pointer halo);
  every value is already rendered server-side, so the dashboard stays complete
  without JS. Respect `prefers-reduced-motion`.
- Server contract (`app/main.py` → `admin.html`): the dashboard reads existing keys
  plus `subscription_days_left` / `subscription_end_date` /
  `subscription_remaining_percent`; adding a card means updating both sides.
- Legacy hook classes (`.dashboard-card`, `.dashboard-chart-card`,
  `.dashboard-table-card`, `.dashboard-quick-card`, `.dashboard-table`, `.bar-*`,
  `.card-title/.card-value/.card-meta`) are kept on the new markup so the dark-theme
  and responsive-table suites stay green.
- Docs & preview: `docs/dashboard/design-system.md`, `python preview/render.py`
  (offline render with mock data, no SQL Server needed).
- Tests: `tests/test_dashboard_modern.py` (static contract) and
  `tests/test_dashboard_modern_dom.py` → `tests/js/dashboard-modern.dom.test.js`.

## Responsive Tables Layer

- `app/static/js/responsive-tables.js` + `app/static/css/responsive-tables.css` are the
  shared mobile presentation layer for ALL tables (patterns: cards / list / scroll / keep).
- Mobile views are derived from the same `<table>` DOM (no duplicated API/business logic);
  interactive controls are moved into cards and restored on desktop/print.
- To add a table, register it in `CONFIGS` inside responsive-tables.js (see docs/mobile-tables.md).
- Keep `tests/test_responsive_tables.py` green; the jsdom suite lives in tests/js.
