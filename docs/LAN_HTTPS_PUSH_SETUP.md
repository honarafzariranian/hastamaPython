# (OBSOLETE — kept for history) اعلان‌های داخلی Hastama

> **این سند بازنشسته شده است.** نشانی رسمی و یکتای سامانه اکنون
> `https://hastama.ir` است (Cloudflare → Cloudflare Tunnel → 
> `127.0.0.1:5000`) و هیچ نام داخلی یا گواهی داخلی لازم نیست.
> سند جاری: `docs/network/UNIFIED_URL_ARCHITECTURE.md`.
Hastama از اعلان‌های داخلی پنل و SSE استفاده می‌کند. هیچ Service Worker، Push Subscription، مجوز اعلان مرورگر یا اعلان سیستم‌عامل استفاده نمی‌شود.

## بررسی عملکرد

1. وارد پنل ادمین شوید و اتصال `/api/notifications/stream` را بررسی کنید.
2. یک درخواست جدید ایجاد کنید.
3. toast داخلی، شمارنده و صفحه اعلان‌ها را بدون refresh بررسی کنید.
4. وضعیت خوانده‌شده/خوانده‌نشده را بررسی کنید.
