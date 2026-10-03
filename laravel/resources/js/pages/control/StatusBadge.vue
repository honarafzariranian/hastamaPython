<script setup>
/**
 * Status badge — the Vue equivalent of the legacy `badge()` helper in
 * `master-admin.js`.  Every status the control-centre feeds can produce is
 * mapped to its Persian label and colour class; an unknown value renders
 * verbatim with the neutral style so a new backend enum is visible rather
 * than silently blank.
 */
import { computed } from 'vue';

const props = defineProps({
    value: { type: [String, Number, Boolean], default: '' },
});

const STATUS_MAP = {
    active: { label: 'فعال', cls: 'success' },
    disabled: { label: 'غیرفعال', cls: 'danger' },
    admin: { label: 'مدیر', cls: 'purple' },
    user: { label: 'کاربر', cls: 'info' },
    success: { label: 'موفق', cls: 'success' },
    failure: { label: 'ناموفق', cls: 'danger' },
    error: { label: 'خطا', cls: 'danger' },
    info: { label: 'اطلاعات', cls: 'info' },
    pending: { label: 'انتظار', cls: 'warning' },
    approved: { label: 'تأیید شده', cls: 'success' },
    rejected: { label: 'رد شده', cls: 'danger' },
    completed: { label: 'تکمیل شده', cls: 'info' },
    expired: { label: 'منقضی شده', cls: 'neutral' },
    cancelled: { label: 'لغو شده', cls: 'neutral' },
    open: { label: 'باز', cls: 'warning' },
    investigating: { label: 'در حال بررسی', cls: 'info' },
    resolved: { label: 'حل‌شده', cls: 'success' },
    ignored: { label: 'نادیده', cls: 'neutral' },
    false_positive: { label: 'مثبت کاذب', cls: 'neutral' },
    low: { label: 'کم', cls: 'neutral' },
    medium: { label: 'متوسط', cls: 'warning' },
    high: { label: 'زیاد', cls: 'danger' },
    critical: { label: 'بحرانی', cls: 'critical' },
    new: { label: 'جدید', cls: 'info' },
};

const resolved = computed(() => {
    const raw = props.value;
    const isEmpty = raw === null || raw === undefined || raw === '';
    const key = String(raw ?? '').trim().toLowerCase();

    if (isEmpty) {
        return { label: '—', cls: 'neutral' };
    }

    return STATUS_MAP[key] ?? { label: String(raw), cls: 'neutral' };
});
</script>

<template>
    <span class="ma-badge" :class="`ma-badge--${resolved.cls}`">{{ resolved.label }}</span>
</template>
