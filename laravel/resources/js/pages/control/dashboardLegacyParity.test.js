import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRouter, createWebHistory } from 'vue-router';
import api from '@/services/api';
import ControlCentreLayout from '@/layouts/ControlCentreLayout.vue';
import DashboardPage from '@/pages/control/DashboardPage.vue';

/**
 * Parity against the *source of truth*, not against a copy of it.
 *
 * `DashboardPage.test.js` pins the values, but a hand-copied expectation can
 * drift away from the Python in exactly the way this page already had.  So this
 * suite reads `app/static/js/master-admin.js` and `app/templates/master-admin.html`
 * and asserts the rendered Vue DOM matches what the legacy code specifies.  If
 * the Python changes, this fails and the port has to follow.
 */

/* Vitest runs with `laravel/` as its cwd; the Python reference lives one level
   up, next to the port. `import.meta.url` is an http: URL under the jsdom
   environment, so the cwd is the reliable anchor here. */
const REPO_ROOT = resolve(process.cwd(), '..');
const LEGACY_JS = readFileSync(resolve(REPO_ROOT, 'app/static/js/master-admin.js'), 'utf8');
const LEGACY_HTML = readFileSync(resolve(REPO_ROOT, 'app/templates/master-admin.html'), 'utf8');

/** The `cards` array `loadDashboard()` builds, read straight out of the file. */
function legacyCards() {
    const start = LEGACY_JS.indexOf('const cards = [');
    const end = LEGACY_JS.indexOf('];', start);
    const body = LEGACY_JS.slice(start, end);

    return body
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.startsWith('{ icon:'))
        .map((line) => {
            const pick = (key) => {
                const match = line.match(new RegExp(`${key}: (?:'([^']*)'|(s\\.\\w+))`));

                return match ? (match[1] ?? match[2]) : null;
            };

            return {
                icon: pick('icon'),
                label: pick('label'),
                gradient: pick('gradient'),
                link: pick('link'),
                /* `value: s.total_users` → the `total_users` counter. */
                statKey: (line.match(/value: s\.(\w+)/) ?? [])[1] ?? null,
                delay: Number(line.match(/delay: (\d+)/)?.[1] ?? NaN),
            };
        });
}

/** The `{% if active_section == 'dashboard' %}` branch of the legacy template. */
function legacyDashboardHtml() {
    const start = LEGACY_HTML.indexOf("{% if active_section == 'dashboard' %}");
    const end = LEGACY_HTML.indexOf('{% elif', start);

    return LEGACY_HTML.slice(start, end);
}

/** The seven `.ma-qcard` anchors in the legacy dashboard branch. */
function legacyQuickLinks() {
    const pattern = /<a href="([^"]+)" class="ma-qcard" style="--accent:([^;]+);--accent-bg:([^"]+)">[\s\S]*?<span class="ma-qcard__label">([^<]+)<\/span>/g;

    return [...legacyDashboardHtml().matchAll(pattern)].map((match) => ({
        href: match[1],
        accent: match[2],
        accentBg: match[3],
        label: match[4],
    }));
}

/** The seven quick-access icon bodies, as written in the legacy template. */
function legacyQuickIcons() {
    return [...legacyDashboardHtml().matchAll(/<svg width="22"[\s\S]*?<\/svg>/g)].map((match) =>
        shape(match[0].replace(/^<svg[^>]*>/, '').replace(/<\/svg>$/, '')));
}

/**
 * Reduce icon markup to a comparable shape: element name plus sorted
 * attributes.  Comparing the two serialisations directly is useless because
 * jsdom writes `<path/>` as `<path></path>` — the drawing is identical, the
 * serialiser is not.
 */
function shape(markup) {
    return [...markup.matchAll(/<([\w-]+)([^>]*?)\/?>/g)].map(([, tag, rawAttrs]) => {
        const attributes = [...rawAttrs.matchAll(/([\w:-]+)="([^"]*)"/g)]
            .map(([, name, value]) => `${name}="${value}"`)
            .sort()
            .join(' ');

        return `${tag} ${attributes}`;
    });
}

/** The same reduction, taken from a live DOM node. */
function domShape(element) {
    return [...element.children].map((child) => {
        const attributes = [...child.attributes]
            .map((attribute) => `${attribute.name}="${attribute.value}"`)
            .sort()
            .join(' ');

        return `${child.tagName} ${attributes}`;
    });
}

const STATS = {
    total_users: 120,
    active_users: 118,
    online_sessions: 7,
    logins_today: 42,
    failed_logins_today: 3,
    pending_password_resets: 2,
    open_security_events: 0,
    open_errors: 5,
    open_tickets: 9,
    admin_count: 4,
    events_today: 61,
};

beforeEach(() => {
    vi.spyOn(api, 'get').mockImplementation((url) => (url.includes('/dashboard/stats')
        ? Promise.resolve({ success: true, data: STATS })
        : Promise.resolve({ success: true, data: [] })));
});

afterEach(() => {
    vi.restoreAllMocks();
});

async function mountDashboard() {
    const wrapper = mount(DashboardPage);
    await flushPromises();

    return wrapper;
}

describe('dashboard parity with app/static/js/master-admin.js', () => {
    it('finds the legacy card list the parser is anchored to', () => {
        /* If this fails the parsers below are silently matching nothing. */
        expect(legacyCards()).toHaveLength(10);
        expect(legacyQuickLinks()).toHaveLength(7);
    });

    it('renders one card per legacy card, in the legacy order', async () => {
        const wrapper = await mountDashboard();
        const rendered = wrapper.findAll('.ma-top-cards-row .ma-glow-card');

        expect(rendered).toHaveLength(legacyCards().length);

        rendered.forEach((card, index) => {
            const legacy = legacyCards()[index];

            expect(card.find('.ma-glow-card__icon').text()).toBe(legacy.icon);
            expect(card.find('.ma-glow-card__label').text()).toBe(legacy.label);
            expect(card.attributes('style')).toContain(legacy.gradient);
            expect(card.attributes('style')).toContain(`animation-delay: ${legacy.delay}ms`);
        });
    });

    it('links exactly the cards the legacy renderer linked', async () => {
        const wrapper = await mountDashboard();
        const rendered = wrapper.findAll('.ma-top-cards-row .ma-glow-card__content');

        rendered.forEach((content, index) => {
            const legacy = legacyCards()[index];

            if (legacy.link) {
                expect(content.element.tagName, legacy.label).toBe('A');
                expect(content.attributes('href'), legacy.label).toBe(legacy.link);
            } else {
                expect(content.element.tagName, legacy.label).toBe('DIV');
            }
        });
    });

    it('reads each card from the stat key the legacy renderer read', async () => {
        const legacy = legacyCards();
        const wrapper = await mountDashboard();
        const rendered = wrapper.findAll('.ma-top-cards-row .ma-glow-card__value');

        rendered.forEach((value, index) => {
            const statKey = legacy[index].statKey;

            expect(statKey, legacy[index].label).toBeTruthy();
            expect(value.attributes('data-count'), legacy[index].label).toBe(String(STATS[statKey]));
        });
    });
});

describe('quick-access parity with app/templates/master-admin.html', () => {
    it('renders the seven legacy links with the same hrefs, accents and labels', async () => {
        const wrapper = await mountDashboard();
        const rendered = wrapper.findAll('.ma-quick-grid .ma-qcard');

        expect(rendered).toHaveLength(legacyQuickLinks().length);

        rendered.forEach((link, index) => {
            const legacy = legacyQuickLinks()[index];

            expect(link.attributes('href'), legacy.label).toBe(legacy.href);
            expect(link.find('.ma-qcard__label').text(), legacy.label).toBe(legacy.label);
            expect(link.attributes('style'), legacy.label).toContain(`--accent: ${legacy.accent}`);
            expect(link.attributes('style'), legacy.label).toContain(`--accent-bg: ${legacy.accentBg}`);
        });
    });

    it('draws the same elements with the same attributes for each quick-access icon', async () => {
        const wrapper = await mountDashboard();
        const rendered = wrapper.findAll('.ma-quick-grid .ma-qcard__icon svg');
        const legacyIcons = legacyQuickIcons();

        expect(rendered).toHaveLength(legacyIcons.length);

        rendered.forEach((svg, index) => {
            expect(domShape(svg.element), legacyQuickLinks()[index].label).toEqual(legacyIcons[index]);
        });
    });
});

describe('control-centre rail parity with app/templates/master-admin.html', () => {
    it('offers the same rail entries, with the same accent hooks and labels', async () => {
        setActivePinia(createPinia());

        const router = createRouter({
            history: createWebHistory(),
            routes: [
                { path: '/master-admin/:section', name: 'master-admin-section', component: ControlCentreLayout },
            ],
        });

        await router.push({ name: 'master-admin-section', params: { section: 'dashboard' } });

        const wrapper = mount(ControlCentreLayout, { global: { plugins: [router] } });
        await flushPromises();

        /* `master-admin.html`'s right rail, read in document order. */
        const branchStart = LEGACY_HTML.indexOf('ma-sidebar-right');
        const branchEnd = LEGACY_HTML.indexOf('<!-- سایدبار چپ', branchStart);
        const rail = LEGACY_HTML.slice(branchStart, branchEnd);
        const legacyEntries = [...rail.matchAll(
            /<a href="[^"]*" class="icon-container[^"]*" data-accent="([^"]+)"[\s\S]*?<span class="icon-label">([^<]+)<\/span>/g,
        )].map((match) => ({ accent: match[1], label: match[2] }));

        const rendered = wrapper.findAll('.ma-sidebar-right .icon-container')
            .map((item) => ({ accent: item.attributes('data-accent'), label: item.find('.icon-label').text() }));

        expect(rendered.length).toBeGreaterThan(0);
        expect(rendered).toEqual(legacyEntries);
    });

    it('preserves the Python right-sidebar fixed spatial coordinates (top: 8rem, right: 0, bottom: 12px) on .ma-page-shell', () => {
        const legacyAdminCss = readFileSync(resolve(REPO_ROOT, 'app/static/css/admin.css'), 'utf8');
        const portedAdminCss = readFileSync(resolve(REPO_ROOT, 'laravel/resources/css/legacy/admin.css'), 'utf8');
        const portedMasterAdminCss = readFileSync(resolve(REPO_ROOT, 'laravel/resources/css/legacy/master-admin.css'), 'utf8');
        const portedUserPanelCss = readFileSync(resolve(REPO_ROOT, 'laravel/resources/css/legacy/user-panel-style.css'), 'utf8');

        /* Python's admin.css anchors `.rightSidebar` to `top: 8rem; right: 0; bottom: 12px;`. */
        expect(legacyAdminCss).toMatch(/\.rightSidebar\s*\{[^}]*position:\s*fixed;[^}]*top:\s*8rem;[^}]*right:\s*0;[^}]*bottom:\s*12px;/s);

        /* Ported admin.css and master-admin.css must resolve to the exact same `8rem` / `12px` offsets on `.ma-page-shell`. */
        expect(portedAdminCss).toMatch(/\.rightSidebar\s*\{[^}]*position:\s*fixed;[^}]*top:\s*var\(--admin-sidebar-top,\s*8rem\);[^}]*right:\s*0;[^}]*bottom:\s*var\(--admin-sidebar-bottom,\s*12px\);/s);
        expect(portedMasterAdminCss).toMatch(/\.ma-page-shell\s*\{[^}]*--admin-sidebar-top:\s*8rem;[^}]*--admin-sidebar-bottom:\s*12px;/s);

        /* `user-panel-style.css` is never loaded by `master-admin.html` in Python; its `.sidebar-right` rules must be scoped to `.app-shell`. */
        expect(portedUserPanelCss).not.toMatch(/(?:^|\n)\s*\.sidebar-right\s*\{/);
    });
});
