import { computed, readonly, ref } from 'vue';

/**
 * Theme control — a faithful port of app/static/js/theme.js.
 *
 * The contract is deliberately identical to the running application, because
 * users' stored preference must survive the migration:
 *
 *   1. The choice lives in localStorage under 'hastama-theme'.
 *   2. Only 'dark' or 'light' are accepted, and the default is LIGHT — the
 *      existing application never follows prefers-color-scheme, so neither do
 *      we (changing that would silently turn the whole product dark for users
 *      who never asked).
 *   3. Both legacy classes ('dark-mode' and 'dark-theme') are applied to
 *      <html> and <body> alongside data-theme, so any stylesheet ported from
 *      the old build keeps matching without a rewrite.
 *   4. documentElement.style.colorScheme follows the theme so native controls
 *      (scrollbars, selects, date pickers) match.
 *   5. A 'hastama:themechange' event is dispatched for non-Vue listeners.
 */
const STORAGE_KEY = 'hastama-theme';
const LIGHT = 'light';
const DARK = 'dark';
const LEGACY_CLASSES = ['dark-mode', 'dark-theme'];

const current = ref(LIGHT);

function readStored() {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return stored === DARK || stored === LIGHT ? stored : null;
    } catch {
        // Private browsing / storage disabled: fall back to the default.
        return null;
    }
}

function persist(theme) {
    try {
        window.localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        /* nothing we can do; the in-memory state still applies */
    }
}

function paint(theme) {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = theme === DARK;

    for (const element of [document.documentElement, document.body]) {
        if (!element) {
            continue;
        }

        for (const name of LEGACY_CLASSES) {
            element.classList.toggle(name, isDark);
        }
    }

    const root = document.documentElement;
    root.setAttribute('data-theme', theme);
    root.style.colorScheme = isDark ? 'dark' : 'light';
}

/**
 * Apply the stored preference.  The inline script in app.blade.php has already
 * done this before first paint; calling it here keeps the Vue state in step.
 *
 * @returns {'light'|'dark'} the applied theme
 */
export function initTheme() {
    current.value = readStored() ?? LIGHT;
    paint(current.value);

    return current.value;
}

export function setTheme(theme) {
    const next = theme === DARK ? DARK : LIGHT;

    current.value = next;
    persist(next);
    paint(next);

    try {
        window.dispatchEvent(new CustomEvent('hastama:themechange', { detail: { theme: next } }));
    } catch {
        /* older browsers: the event is a convenience, not a requirement */
    }

    return next;
}

export function toggleTheme() {
    return setTheme(current.value === DARK ? LIGHT : DARK);
}

export function useTheme() {
    return {
        theme: readonly(current),
        isDark: computed(() => current.value === DARK),
        setTheme,
        toggleTheme,
    };
}

export { STORAGE_KEY, LIGHT, DARK };
