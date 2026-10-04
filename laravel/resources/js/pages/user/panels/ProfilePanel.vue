<script setup>
/**
 * The profile panel overlay — the Vue equivalent of `#profilePanel` in
 * `user-panel.html`.  The body is `ProfilePage.vue`, which carries the legacy
 * `.profile-panel-body` markup and its two image writes.
 */
import ProfilePage from '@/pages/user/ProfilePage.vue';

const emit = defineEmits(['close']);
</script>

<template>
    <div id="profilePanel" class="profile-panel">
        <div class="profile-panel-backdrop" id="profilePanelBackdrop" @click="emit('close')"></div>
        <div class="profile-panel-dialog" role="dialog" aria-modal="true" aria-labelledby="profilePanelTitle">
            <div class="profile-panel-header">
                <div>
                    <h2 id="profilePanelTitle">ویرایش پروفایل</h2>
                </div>
                <button type="button" class="profile-panel-close" data-action="close-profile-panel" aria-label="بستن پنل پروفایل" @click="emit('close')">×</button>
            </div>
            <ProfilePage />
        </div>
    </div>
</template>
