<script setup>
/**
 * Confirmation dialog — the Vue equivalent of the legacy `maConfirm()` helper
 * in `master-admin.js`.
 *
 * The parent owns the flow: it sets `state` to a descriptor
 * (`{ title, msg, confirmText, cancelText, danger }`) and awaits the promise
 * the dialog resolves.  Rendering is conditional, so with no pending
 * confirmation the component emits nothing and the page stays quiet.
 */
defineProps({
    state: { type: Object, default: null },
});

const emit = defineEmits(['resolve']);
</script>

<template>
    <div v-if="state" class="ma-confirm-overlay" role="dialog" aria-modal="true" @click.self="emit('resolve', false)">
        <div class="ma-confirm-box">
            <div class="ma-confirm-box__icon" :class="state.danger === false ? 'ma-confirm-box__icon--warning' : 'ma-confirm-box__icon--danger'">
                <svg v-if="state.danger === false" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <svg v-else width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div class="ma-confirm-box__title">{{ state.title || 'تأیید عملیات' }}</div>
            <div class="ma-confirm-box__msg">{{ state.msg || 'آیا مطمئن هستید؟' }}</div>
            <div class="ma-confirm-box__actions">
                <button
                    type="button"
                    class="ma-btn ma-btn--sm"
                    :class="state.danger === false ? 'ma-btn--primary' : 'ma-btn--danger'"
                    @click="emit('resolve', true)"
                >
                    {{ state.confirmText || 'تأیید' }}
                </button>
                <button
                    type="button"
                    class="ma-btn ma-btn--ghost ma-btn--sm"
                    @click="emit('resolve', false)"
                >
                    {{ state.cancelText || 'انصراف' }}
                </button>
            </div>
        </div>
    </div>
</template>
