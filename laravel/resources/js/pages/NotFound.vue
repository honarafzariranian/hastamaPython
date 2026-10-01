<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <section class="nf">
        <p class="nf__code">۴۰۴</p>
        <h1 class="nf__title">این صفحه پیدا نشد</h1>
        <p class="nf__lead">
            نشانی درخواستی وجود ندارد یا جابه‌جا شده است. اگر روی پیوندی در سامانه کلیک کرده‌اید،
            لطفاً موضوع را به پشتیبانی اطلاع دهید.
        </p>
        <RouterLink to="/" class="h-btn h-btn-primary">بازگشت به صفحهٔ اصلی</RouterLink>
    </section>
</template>

<style scoped>
.nf {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    padding: 4rem 1rem;
    text-align: center;
}

.nf__code {
    margin: 0;
    font-size: 3.4rem;
    font-weight: 900;
    color: var(--c-primary);
    line-height: 1;
}

.nf__title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 800;
}

.nf__lead {
    margin: 0 0 0.6rem;
    max-width: 46ch;
    font-size: 0.9rem;
    line-height: 1.9;
    color: #475569;
}

[data-theme='dark'] .nf__lead {
    color: var(--dk-text-2);
}
</style>
