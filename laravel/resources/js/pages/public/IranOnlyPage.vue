<script setup>
/**
 * The Iran-only access-policy page — the Vue equivalent of
 * `app/templates/vpn-warning.html`.
 *
 * The template is the legacy markup element for element: the same divs, the same
 * class names, the same order and the same Persian labels.  The legacy page is
 * deliberately self contained (its own inline `<style>`, no stylesheet, font,
 * image or script from anywhere else) because the visitor's own connection is
 * exactly what is in the way — so the inline rules are ported here verbatim
 * into the component's scoped style.
 *
 * The page is reachable on purpose from every address: a visitor who is asked
 * to switch a VPN off needs a URL they can keep.  The standing text (title,
 * message, help lines) is the operator-configured policy; the backend's
 * defaults are reproduced here because the only machine contract,
 * `GET /iran-only/check`, answers the verdict for the caller's own address —
 * `{success, allowed, ip, kind, enforcing}` — and does not carry the copy.
 *
 * The page polls the check the moment it opens and keeps polling until the
 * verdict flips; the legacy client reloaded the whole page on «بررسی مجدد»,
 * which is kept.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import api from '@/services/api';

const title = 'دسترسی از این آی‌پی مجاز نیست';

const message =
    'سامانه فقط ورود با آی‌پی ایران را می‌پذیرد. به نظر می‌رسد در حال حاضر از طریق VPN یا پروکسی (یا از خارج از کشور) متصل شده‌اید. لطفاً ابتدا VPN یا فیلترشکن خود را قطع کنید، سپس این صفحه را دوباره بارگذاری کنید.';

const helpLines = [
    'روی ویندوز: آیکون VPN در نوار کنار ساعت را باز کنید و Disconnect را بزنید.',
    'روی گوشی اندروید: تنظیمات ← شبکه و اینترنت ← VPN ← اتصال را قطع کنید.',
    'روی iPhone: تنظیمات ← General ← VPN & Device Management ← اتصال را قطع کنید.',
    'اگر از افزونهٔ مرورگر (فیلترشکن) استفاده می‌کنید، آن را غیرفعال یا حذف کنید.',
    'پس از قطع VPN، این صفحه را دوباره بارگذاری کنید تا وارد شوید.',
];

const RETRY_SECONDS = 30;

const ip = ref('');
const kind = ref('');
const status = ref('پس از قطع VPN، ورود به‌صورت خودکار بررسی می‌شود…');
const checking = ref(false);

let timer = null;

async function check() {
    checking.value = true;

    try {
        const response = await api.get('/iran-only/check', { baseURL: '' });

        if (response.allowed) {
            status.value = 'دسترسی تأیید شد؛ بازگشت به سامانه…';
            window.location.replace('/');
            return;
        }

        if (response.ip) {
            ip.value = response.ip;
        }

        if (response.kind) {
            kind.value = response.kind;
        }

        throw new Error('still-blocked');
    } catch {
        status.value =
            'آخرین بررسی: ' +
            new Date().toLocaleTimeString('fa-IR') +
            ' — هنوز با آی‌پی ایران وصل نیستید (تلاش بعدی: ' +
            RETRY_SECONDS +
            ' ثانیه)';
    } finally {
        checking.value = false;
    }

    timer = setTimeout(check, Math.max(5, RETRY_SECONDS) * 1000);
}

function reloadPage() {
    window.location.reload();
}

function copyIp() {
    const text = ip.value.trim();

    if (!text) {
        return;
    }

    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(
            () => {
                status.value = 'آی‌پی کپی شد: ' + text;
            },
            () => {
                status.value = 'این آی‌پی را دستی یادداشت کنید: ' + text;
            },
        );
        return;
    }

    status.value = 'این آی‌پی را دستی یادداشت کنید: ' + text;
}

onMounted(() => {
    timer = setTimeout(check, Math.min(5, RETRY_SECONDS) * 1000);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
});
</script>

<template>
    <div class="io">
        <div class="card" id="vpnCard" data-retry="30">
            <span class="badge"><i></i>فقط آی‌پی ایران</span>
            <div class="icon">🛡️</div>
            <h1>{{ title }}</h1>
            <p>{{ message }}</p>

            <div v-if="ip" class="reason">
                <span>آی‌پی شناسایی‌شدهٔ شما:</span>
                <code id="vpnIp">{{ ip }}</code>
                <span v-if="kind">— {{ kind }}</span>
            </div>

            <div class="help">
                <div class="help__title">چگونه VPN را قطع کنم و وارد شوم؟</div>
                <ol>
                    <li v-for="line in helpLines" :key="line">{{ line }}</li>
                </ol>
            </div>

            <div class="actions">
                <button type="button" class="primary" id="vpnRetry" @click="reloadPage">بررسی مجدد</button>
                <button type="button" id="vpnCopyIp" @click="copyIp">کپی آی‌پی من</button>
            </div>

            <div class="status" id="vpnStatus">{{ status }}</div>

            <div class="foot">
                این صفحه بدون نیاز به اینترنت نمایش داده می‌شود. اگر مطمئن هستید VPN شما قطع است و باز هم این پیام را می‌بینید،
                این آی‌پی را به مدیر سامانه اطلاع دهید.
            </div>
        </div>
    </div>
</template>

<style scoped>
.io {
    --ink: #0f172a;
    --muted: #64748b;
    --line: #e2e8f0;
    --accent: #6366f1;
    --danger: #e11d48;
    --ok: #10b981;

    min-height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: radial-gradient(1200px 600px at 20% -10%, #ffe4e6 0%, #f8fafc 45%, #f1f5f9 100%);
    color: var(--ink);
    font-family: Vazirmatn, "Segoe UI", Tahoma, sans-serif;
    line-height: 1.9;
    box-sizing: border-box;
}

.io *,
.io *::before,
.io *::after {
    box-sizing: border-box;
}

.card {
    width: 100%;
    max-width: 640px;
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
    background: rgba(225, 29, 72, .12);
    color: #9f1239;
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
    font-size: .93rem;
}

.reason {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: .82rem;
    color: var(--muted);
    background: #f8fafc;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 10px 12px;
    margin: 0 0 18px;
}

.reason b {
    color: var(--ink);
    font-weight: 600;
}

.reason code {
    direction: ltr;
    unicode-bidi: embed;
    font-family: ui-monospace, Consolas, monospace;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 2px 8px;
    color: var(--ink);
}

.help {
    text-align: start;
    margin: 0 0 18px;
    padding: 16px 18px;
    border: 1px dashed rgba(99, 102, 241, .45);
    background: rgba(99, 102, 241, .06);
    border-radius: 14px;
}

.help__title {
    font-size: .86rem;
    font-weight: 700;
    color: #4338ca;
    margin-bottom: 8px;
}

.help ol {
    margin: 0;
    padding-inline-start: 20px;
    font-size: .85rem;
    color: #475569;
}

.help li {
    margin-bottom: 4px;
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

.status {
    margin-top: 12px;
    font-size: .8rem;
    color: var(--muted);
    min-height: 20px;
}

.foot {
    margin-top: 16px;
    font-size: .76rem;
    color: #94a3b8;
}

@media (max-width: 480px) {
    .io {
        padding: 12px;
    }

    .card {
        padding: 22px 16px;
        border-radius: 16px;
    }
}
</style>
