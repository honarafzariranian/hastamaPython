<script setup>
/**
 * Pagination bar — the Vue equivalent of the legacy `renderPagination()` in
 * `master-admin.js`: a « previous, a five-page window around the current page,
 * next » control with Persian digits, shown only when there is more than one
 * page (the legacy renderer returned early for `pages <= 1`).
 */
import { computed } from 'vue';
import { toPersianDigits } from '@/utils/numbers';

const props = defineProps({
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
});

const emit = defineEmits(['change']);

const items = computed(() => {
    if (props.pages <= 1) {
        return [];
    }

    const start = Math.max(1, props.page - 2);
    const end = Math.min(props.pages, props.page + 2);
    const list = [];

    for (let index = start; index <= end; index += 1) {
        list.push(index);
    }

    return list;
});
</script>

<template>
    <nav v-if="pages > 1" class="ma-pagination" aria-label="صفحه‌بندی">
        <button
            type="button"
            class="ma-pagination__btn"
            :disabled="page <= 1"
            aria-label="صفحهٔ قبل"
            @click="emit('change', page - 1)"
        >
            «
        </button>

        <button
            v-for="item in items"
            :key="item"
            type="button"
            class="ma-pagination__btn"
            :class="{ 'is-active': item === page }"
            :aria-current="item === page ? 'page' : undefined"
            @click="emit('change', item)"
        >
            {{ toPersianDigits(item) }}
        </button>

        <button
            type="button"
            class="ma-pagination__btn"
            :disabled="page >= pages"
            aria-label="صفحهٔ بعد"
            @click="emit('change', page + 1)"
        >
            »
        </button>
    </nav>
</template>
