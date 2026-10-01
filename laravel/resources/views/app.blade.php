<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">

    <title>{{ config('app.name', 'هستما') }}</title>

    {{-- Local brand assets only; the application has no CDN dependency. --}}
    <link rel="icon" href="/images/newlogo.png" type="image/png">
    <link rel="preload" href="/fonts/Vazir.woff2" as="font" type="font/woff2" crossorigin>

    {{--
        Apply the persisted theme before the first paint.  This runs inline and
        synchronously in <head> because doing it from the bundle would show a
        white flash on every dark-mode navigation.  The contract is the one the
        existing application already uses (app/static/js/theme.js): the
        'hastama-theme' key, default light, and the legacy classes applied
        alongside the data-theme attribute so ported stylesheets keep working.
    --}}
    <script>
        (function () {
            var SKIN_LIGHT = 'light';
            var SKIN_DARK = 'dark';
            var theme = SKIN_LIGHT;

            try {
                var stored = window.localStorage.getItem('hastama-theme');
                if (stored === SKIN_DARK || stored === SKIN_LIGHT) {
                    theme = stored;
                }
            } catch (error) {
                /* private browsing: stay on the default */
            }

            var root = document.documentElement;
            var isDark = theme === SKIN_DARK;

            root.setAttribute('data-theme', theme);
            root.style.colorScheme = isDark ? 'dark' : 'light';
            root.classList[isDark ? 'add' : 'remove']('dark-mode');
            root.classList[isDark ? 'add' : 'remove']('dark-theme');
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>

    <noscript>
        <p style="padding:1.5rem;text-align:center">
            برای استفاده از سامانهٔ هستما، جاوااسکریپت مرورگر باید فعال باشد.
        </p>
    </noscript>
</body>
</html>
