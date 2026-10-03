<script setup>
import { onMounted } from 'vue';

/*
 * `/call-management` is deliberately rendered by CallPageController from the
 * canonical Python template. This component is only a safety net for an SPA
 * fallback or a route guard regression; never maintain a second UI here.
 */
onMounted(() => {
    if (typeof window === 'undefined') return;

    const suffix = window.location.search + window.location.hash;
    window.location.replace('/call-management' + suffix);
});
</script>

<template>
    <main class="cs-route-redirect" dir="rtl" lang="fa" role="status" aria-live="polite">
        <p>در حال باز کردن نسخهٔ اصلی سامانهٔ فراخوان…</p>
        <a href="/call-management">اگر انتقال خودکار انجام نشد، اینجا را انتخاب کنید.</a>
    </main>
</template>

<style scoped>
.cs-route-redirect {
    display: grid;
    min-height: 100vh;
    min-height: 100dvh;
    align-content: center;
    justify-items: center;
    gap: 0.75rem;
    padding: 2rem;
    color: #0f2438;
    font-family: 'Vazir', Tahoma, Arial, sans-serif;
    text-align: center;
}

.cs-route-redirect p {
    margin: 0;
    font-weight: 700;
}

.cs-route-redirect a {
    color: #0369a1;
    font-weight: 700;
}
</style>
