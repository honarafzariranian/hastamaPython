<script setup>
/**
 * SUPERSEDED — not routed.  `/offline` is served as the real document by
 * `App\Support\Connectivity\OfflinePage` (routes/public-pages.php), which
 * reproduces `app/templates/offline.html` byte for byte, LAN-address block and
 * all.  This component cannot match it: the legacy page renders the laboratory
 * address into the document from server state (`lan_url`), and no public API
 * carries it, so the copy below is missing that block.  It is kept only until
 * the operator confirms nothing links to it; the router entry was removed in
 * favour of the document.
 *
 * The offline fallback page — the Vue equivalent of `app/templates/offline.html`.
 *
 * The template is the legacy markup element for element: the same divs, the same
 * class names, the same order and the same Persian labels.  The legacy page is
 * deliberately self contained (its own inline `<style>`, no stylesheet, font,
 * image or script from anywhere else) because when this page is needed the
 * network is exactly what is missing — so the inline rules are ported here
 * verbatim into the component's scoped style.
 *
 * The page is shown when the internet link that carries the site is gone
 * (served 503 by the outage gate) or the browser itself cannot reach the
 * server (served from the service-worker cache).  The standing text is the
 * operator-configured outage policy; the backend's defaults are reproduced
 * here because no machine contract carries the copy.  The LAN-address block
 * the legacy rendered is omitted: it is server-rendered context
 * (`lan_url` / `lan_enabled`) with no machine contract behind it, so — exactly
 * as in the legacy template's `{% if lan_url %}` — it simply does not render.
 *
 * The legacy client's recovery behaviour is ported: it watches the browser's
 * online/offline events for the reason line, and it polls `GET /health` —
 * the supervision probe that answers `{"status":"ok"}` — until the link is
 * back, then navigates to the page the visitor asked for.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import api from '@/services/api';

const title = 'ارتباط سامانه با اینترنت قطع شده است';

const message =
    'دسترسی به سامانه از مسیر اینترنت برقرار نیست. اگر در شبکهٔ داخلی آزمایشگاه هستید، سامانه از آدرس زیر در دسترس است. پس از برقراری اینترنت، این صفحه به‌صورت خودکار بسته می‌شود.';

const RETRY_SECONDS = 15;

const reason = ref('در حال بررسی…');
const status = ref('اتصال به‌صورت خودکار بررسی می‌شود…');

let timer = null;

function onOffline() {
    reason.value = 'اتصال اینترنت این رایانه قطع شده است';
}

function onOnline() {
    reason.value = 'سامانه به اینترنت دسترسی ندارد (وضعیت اضطراری)';
}

function reloadPage() {
    window.location.reload();
}

function goBack() {
    // Inside the outage gate's overlay the recovery must reload the page
    // behind the frame, not the frame itself.
    try {
        if (window.self !== window.top) {
            window.parent.postMessage({ hastamaOffline: 'back' }, window.location.origin);
            return;
        }
    } catch {
        /* fall through to a local navigation */
    }

    if (window.location.pathname.indexOf('/offline') === 0) {
        const next = new URLSearchParams(window.location.search).get('next');
        window.location.replace(next && next.charAt(0) === '/' ? next : '/');
        return;
    }

    window.location.reload();
}

async function check() {
    try {
        const response = await api.get('/health', { baseURL: '' });

        if (response.status === 'ok') {
            status.value = 'ارتباط برقرار شد؛ بازگشت به سامانه…';
            goBack();
            return;
        }

        throw new Error('not-ready');
    } catch {
        status.value =
            'آخرین بررسی: ' +
            new Date().toLocaleTimeString('fa-IR') +
            ' — ارتباط هنوز برقرار نیست (تلاش بعدی: ' +
            RETRY_SECONDS +
            ' ثانیه)';
    }

    timer = setTimeout(check, Math.max(5, RETRY_SECONDS) * 1000);
}

onMounted(() => {
    if (navigator.onLine === false) {
        onOffline();
    } else {
        onOnline();
    }

    window.addEventListener('offline', onOffline);
    window.addEventListener('online', onOnline);

    timer = setTimeout(check, Math.min(3, RETRY_SECONDS) * 1000);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('offline', onOffline);
    window.removeEventListener('online', onOnline);
});
</script>

<template>
    <div class="of">
        <div class="card" id="offlineCard" data-retry="15">
            <span class="badge"><i></i>قطع ارتباط</span>
            <div class="icon">📡</div>
            <h1>{{ title }}</h1>
            <p>{{ message }}</p>

            <div class="reason">
                <span>علت احتمالی:</span>
                <b id="offlineReason">{{ reason }}</b>
            </div>

            <div class="actions">
                <button type="button" class="primary" id="offlineRetry" @click="reloadPage">تلاش مجدد</button>
            </div>

            <div class="status" id="offlineStatus">{{ status }}</div>

            <div class="foot">این صفحه بدون نیاز به اینترنت نمایش داده می‌شود و پس از برقراری ارتباط به‌صورت خودکار بسته می‌شود.</div>
        </div>
    </div>
</template>

<style scoped>
.of {
    --ink: #0f172a;
    --muted: #64748b;
    --line: #e2e8f0;
    --accent: #6366f1;
    --warn: #f59e0b;
    --ok: #10b981;

    min-height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: radial-gradient(1200px 600px at 80% -10%, #e0e7ff 0%, #f8fafc 45%, #f1f5f9 100%);
    color: var(--ink);
    font-family: Vazirmatn, "Segoe UI", Tahoma, sans-serif;
    line-height: 1.9;
    box-sizing: border-box;
}

.of *,
.of *::before,
.of *::after {
    box-sizing: border-box;
}

.card {
    width: 100%;
    max-width: 620px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 32px 28px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .12);
    text-align: center;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 999px;
    background: rgba(245, 158, 11, .14);
    color: #92400e;
    font-size: .78rem;
    font-weight: 700;
}

.badge i {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: currentColor;
}

.icon {
    font-size: 3rem;
    line-height: 1;
    margin: 14px 0 4px;
}

h1 {
    font-size: 1.25rem;
    margin: 6px 0 10px;
}

p {
    margin: 0 0 14px;
    color: var(--muted);
    font-size: .92rem;
}

.reason {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: .82rem;
    color: var(--muted);
    background: #f8fafc;
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 6px 12px;
    margin-bottom: 18px;
}

.reason b {
    color: var(--ink);
    font-weight: 600;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
}

button {
    font-family: inherit;
    font-size: .9rem;
    font-weight: 600;
    padding: 10px 18px;
    border-radius: 12px;
    cursor: pointer;
    border: 1px solid var(--line);
    background: #fff;
    color: #334155;
}

button.primary {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}

button:disabled {
    opacity: .55;
    cursor: default;
}

.foot {
    margin-top: 18px;
    font-size: .76rem;
    color: #94a3b8;
}

.status {
    margin-top: 10px;
    font-size: .8rem;
    color: var(--muted);
    min-height: 20px;
}

@media (max-width: 480px) {
    .of {
        padding: 12px;
    }

    .card {
        padding: 22px 16px;
        border-radius: 16px;
    }
}
</style>
