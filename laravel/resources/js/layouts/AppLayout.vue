<script setup>
/**
 * The application shell.
 *
 * Branding is taken from what the product actually shows today:
 *   * the logo files are `lab-logo.png` / `newlogo.png` — an earlier brief
 *     referred to `samanehlogo.png`, which does not exist in the repository;
 *   * «آزمایشگاه دکتر امینی» is used as the header subtitle, exactly as the
 *     existing call-management header does;
 *   * the slogan «همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز» is NOT shown
 *     here. The running application prints it only on labels and receipts
 *     (clinic_slogan in label_render.py / ticket_print.py), so it will be
 *     carried into the printing layer during that phase instead of being
 *     invented into the web chrome.
 */
import { computed } from 'vue';
import { RouterLink, RouterView, useRoute } from 'vue-router';
import { useTheme } from '@/composables/useTheme';

const { isDark, toggleTheme } = useTheme();

/*
 * Two kinds of route render without this shell:
 *
 *   * `meta.public` — the login page and the other full-screen documents.  The
 *     Python `/login` renders edge to edge with no application header, so the
 *     shell's bar must not appear on it.
 *   * `meta.ownChrome` — a panel that was ported with the running
 *     application's own header, sidebar and background (`admin.html`'s
 *     `.page-shell` + `.topbar` + `.navarha`).  Wrapping those in `h-header`
 *     and a max-width `h-main` put a second, invented bar above the ported one
 *     and narrowed a layout the legacy stylesheet sizes itself.
 *
 * Every other route keeps the shell.
 */
const route = useRoute();
const isBare = computed(() => route.meta?.public === true || route.meta?.ownChrome === true);

/*
 * The logo lives in public/ because it is a same-origin local asset, not a
 * bundled module.  It is bound rather than written as a literal src so that
 * Vue's template asset rewriting does not try to resolve it through the
 * bundler at build time.
 */
const logoUrl = '/images/lab-logo.png';
</script>

<template>
    <!--
        A full-screen document renders bare: no shell, no header, no constrained
        main.  The login page owns the whole viewport (the Python `/login` is a
        full-screen document with its own background scene) and the ported
        panels bring their own chrome, so wrapping either in the shell would put
        a bar and a max-width column above a layout that already has both.
    -->
    <RouterView v-if="isBare" />

    <div v-else class="h-shell">
        <a class="h-skip" href="#main">پرش به محتوای اصلی</a>

        <header class="h-header">
            <div class="h-header__inner">
                <RouterLink to="/" class="h-brand" aria-label="هستما — صفحهٔ اصلی">
                    <img
                        class="h-brand__logo"
                        :src="logoUrl"
                        alt=""
                        width="44"
                        height="44"
                        decoding="async"
                    >
                    <span class="h-brand__text">
                        <span class="h-brand__name">سامانه هستما</span>
                        <span class="h-brand__sub">آزمایشگاه دکتر امینی</span>
                    </span>
                </RouterLink>

                <button
                    type="button"
                    class="h-btn h-btn-ghost h-theme-toggle"
                    :aria-pressed="isDark"
                    :aria-label="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                    :title="isDark ? 'روشن کردن تم' : 'تاریک کردن تم'"
                    @click="toggleTheme"
                >
                    <span aria-hidden="true">{{ isDark ? '☀' : '☾' }}</span>
                    <span class="h-theme-toggle__label">{{ isDark ? 'روشن' : 'تاریک' }}</span>
                </button>
            </div>
        </header>

        <main id="main" class="h-main">
            <RouterView />
        </main>
    </div>
</template>

<style scoped>
.h-shell {
    display: flex;
    min-height: 100dvh;
    flex-direction: column;
}

.h-skip {
    position: absolute;
    inset-inline-start: -9999px;
    top: 0;
    z-index: 50;
    padding: 0.75rem 1rem;
    background: var(--c-primary);
    color: #fff;
    border-radius: 0 0 var(--radius-token-md) var(--radius-token-md);
}

.h-skip:focus {
    inset-inline-start: 0;
}

.h-header {
    position: sticky;
    top: 0;
    z-index: 20;
    border-bottom: 1px solid rgb(15 23 42 / 0.08);
    background: rgb(255 255 255 / 0.85);
    backdrop-filter: blur(10px);
}

[data-theme='dark'] .h-header {
    border-bottom-color: var(--dk-border);
    background: rgb(24 34 51 / 0.85);
}

.h-header__inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    max-width: 1180px;
    margin: 0 auto;
    padding: 0.7rem 1.1rem;
}

.h-brand {
    display: inline-flex;
    align-items: center;
    gap: 0.7rem;
    text-decoration: none;
    color: inherit;
}

.h-brand__logo {
    width: 44px;
    height: 44px;
    object-fit: contain;
}

.h-brand__text {
    display: flex;
    flex-direction: column;
    line-height: 1.35;
}

.h-brand__name {
    font-size: 1.05rem;
    font-weight: 800;
}

.h-brand__sub {
    font-size: 0.75rem;
    color: #64748b;
}

[data-theme='dark'] .h-brand__sub {
    color: var(--dk-text-2);
}

.h-theme-toggle__label {
    font-size: 0.8rem;
}

.h-main {
    flex: 1;
    width: 100%;
    max-width: 1180px;
    margin: 0 auto;
    padding: 1.6rem 1.1rem 2.4rem;
}

@media (max-width: 480px) {
    .h-theme-toggle__label {
        display: none;
    }
}
</style>
