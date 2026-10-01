<script setup>
/**
 * System status.
 *
 * This is the migrated counterpart of the existing `/health` and
 * `/health/database` probes, and it is what proves the Phase 2 chain end to
 * end: Vue renders, the API service reaches Laravel, and Laravel reaches the
 * real SQL Server instance and the existing `user_table`.
 *
 * It reports only whether each dependency answers — never a connection string,
 * a credential or a driver message.
 */
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useSystemStore } from '@/stores/system';
import { toPersianDigits } from '@/utils/numbers';

const store = useSystemStore();
const { health, database, loading, error, checkedAt } = storeToRefs(store);

const databaseReachable = computed(() => database.value !== null);
const appReachable = computed(() => health.value !== null);

const checkedAtLabel = computed(() =>
    checkedAt.value ? toPersianDigits(checkedAt.value.toLocaleTimeString('en-GB')) : null,
);

onMounted(() => {
    store.refresh();
});
</script>

<template>
    <section class="status">
        <header class="status__head">
            <h1 class="status__title">وضعیت سامانه</h1>
            <p class="status__lead">
                بررسی زندهٔ ارتباط سامانه با بانک اطلاعاتی. این صفحه بخشی از فرایند مهاجرت است و پس از
                تکمیل مراحل بعدی جای خود را به داشبورد اصلی می‌دهد.
            </p>
        </header>

        <div class="status__grid">
            <article class="h-card status__card">
                <h2 class="status__card-title">سامانهٔ وب (Laravel)</h2>
                <p class="status__badge" :class="appReachable ? 'is-ok' : (loading ? 'is-wait' : 'is-bad')">
                    {{ loading ? 'در حال بررسی…' : (appReachable ? 'در دسترس' : 'بدون پاسخ') }}
                </p>
                <dl v-if="health" class="status__list">
                    <div>
                        <dt>نسخهٔ PHP</dt>
                        <dd>{{ health.php }}</dd>
                    </div>
                    <div>
                        <dt>نسخهٔ Laravel</dt>
                        <dd>{{ health.laravel }}</dd>
                    </div>
                    <div>
                        <dt>زبان</dt>
                        <dd>{{ health.locale }}</dd>
                    </div>
                </dl>
            </article>

            <article class="h-card status__card">
                <h2 class="status__card-title">بانک اطلاعاتی (SQL Server)</h2>
                <p
                    class="status__badge"
                    :class="databaseReachable ? 'is-ok' : (loading ? 'is-wait' : 'is-bad')"
                >
                    {{ loading ? 'در حال بررسی…' : (databaseReachable ? 'در دسترس' : 'بدون پاسخ') }}
                </p>
                <dl v-if="database" class="status__list">
                    <div>
                        <dt>نمونه (instance)</dt>
                        <dd>{{ database.instance }}</dd>
                    </div>
                    <div>
                        <dt>پایگاه داده</dt>
                        <dd>{{ database.database }}</dd>
                    </div>
                    <div>
                        <dt>تعداد جداول</dt>
                        <dd>{{ toPersianDigits(database.tables) }}</dd>
                    </div>
                    <div>
                        <dt>کاربران جدول user_table</dt>
                        <dd>{{ toPersianDigits(database.user_table_rows) }}</dd>
                    </div>
                    <div>
                        <dt>زمان پاسخ</dt>
                        <dd>{{ toPersianDigits(database.latency_ms) }} میلی‌ثانیه</dd>
                    </div>
                </dl>
            </article>
        </div>

        <p v-if="error" class="status__error" role="alert">{{ error.message }}</p>

        <div class="status__actions">
            <button type="button" class="h-btn h-btn-primary" :disabled="loading" @click="store.refresh()">
                {{ loading ? 'در حال بررسی…' : 'بررسی دوباره' }}
            </button>
            <span v-if="checkedAtLabel" class="status__stamp">آخرین بررسی: {{ checkedAtLabel }}</span>
        </div>
    </section>
</template>

<style scoped>
.status {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
}

.status__title {
    margin: 0 0 0.4rem;
    font-size: 1.5rem;
    font-weight: 800;
}

.status__lead {
    margin: 0;
    max-width: 62ch;
    font-size: 0.9rem;
    line-height: 1.9;
    color: #475569;
}

[data-theme='dark'] .status__lead {
    color: var(--dk-text-2);
}

.status__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}

.status__card {
    padding: 1.15rem 1.25rem;
}

.status__card-title {
    margin: 0 0 0.7rem;
    font-size: 1rem;
    font-weight: 700;
}

.status__badge {
    display: inline-block;
    margin: 0 0 0.9rem;
    padding: 0.28rem 0.75rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
}

.status__badge.is-ok {
    background: rgb(34 197 94 / 0.14);
    color: #15803d;
}

.status__badge.is-bad {
    background: rgb(239 68 68 / 0.14);
    color: #b91c1c;
}

.status__badge.is-wait {
    background: rgb(245 158 11 / 0.16);
    color: #b45309;
}

[data-theme='dark'] .status__badge.is-ok {
    color: #4ade80;
}

[data-theme='dark'] .status__badge.is-bad {
    color: var(--dk-danger);
}

.status__list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin: 0;
}

.status__list > div {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
    border-bottom: 1px dashed rgb(15 23 42 / 0.08);
    padding-bottom: 0.4rem;
}

[data-theme='dark'] .status__list > div {
    border-bottom-color: var(--dk-line);
}

.status__list dt {
    font-size: 0.82rem;
    color: #64748b;
}

[data-theme='dark'] .status__list dt {
    color: var(--dk-text-2);
}

.status__list dd {
    margin: 0;
    font-size: 0.88rem;
    font-weight: 600;
}

.status__error {
    margin: 0;
    padding: 0.85rem 1rem;
    border-radius: var(--radius-token-md);
    background: rgb(239 68 68 / 0.1);
    color: #b91c1c;
    font-size: 0.88rem;
}

[data-theme='dark'] .status__error {
    color: var(--dk-danger);
}

.status__actions {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    flex-wrap: wrap;
}

.status__stamp {
    font-size: 0.78rem;
    color: #64748b;
}

[data-theme='dark'] .status__stamp {
    color: var(--dk-text-3);
}
</style>
