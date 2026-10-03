<script setup>
/**
 * Ticket badge — the Vue equivalent of `ticketBadge()` in `master-admin.js`.
 *
 * `ticketBadge()` took one of the two ticket maps (`TICKET_STATUS_MAP`,
 * `TICKET_PRIORITY_MAP`) rather than the general `STATUS_MAP` that
 * `StatusBadge.vue` serves, because ticket statuses and priorities are a
 * different vocabulary: `in_progress`, `waiting_for_user` and
 * `waiting_for_support` have no entry in the general map, and `normal` /
 * `urgent` mean something else there.  Both maps are reproduced verbatim, and
 * an unknown value still renders verbatim in the neutral style rather than
 * disappearing.
 */
import { computed } from 'vue';

const props = defineProps({
    value: { type: [String, Number, Boolean], default: '' },
    map: { type: String, default: 'status' },
});

const TICKET_STATUS_MAP = {
    new: { label: 'جدید', cls: 'info' },
    open: { label: 'باز', cls: 'warning' },
    in_progress: { label: 'در حال بررسی', cls: 'info' },
    waiting_for_user: { label: 'در انتظار کاربر', cls: 'purple' },
    waiting_for_support: { label: 'در انتظار پشتیبانی', cls: 'warning' },
    resolved: { label: 'حل‌شده', cls: 'success' },
    closed: { label: 'بسته‌شده', cls: 'neutral' },
};

const TICKET_PRIORITY_MAP = {
    low: { label: 'کم', cls: 'neutral' },
    normal: { label: 'عادی', cls: 'info' },
    high: { label: 'زیاد', cls: 'warning' },
    urgent: { label: 'فوری', cls: 'critical' },
};

const resolved = computed(() => {
    const table = props.map === 'priority' ? TICKET_PRIORITY_MAP : TICKET_STATUS_MAP;
    const raw = props.value;
    const key = String(raw ?? '').trim().toLowerCase();

    if (raw === null || raw === undefined || raw === '') {
        return { label: '—', cls: 'neutral' };
    }

    return table[key] ?? { label: String(raw), cls: 'neutral' };
});
</script>

<template>
    <span class="ma-badge" :class="`ma-badge--${resolved.cls}`">{{ resolved.label }}</span>
</template>
