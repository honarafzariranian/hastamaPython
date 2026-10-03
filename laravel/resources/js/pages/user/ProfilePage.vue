<script setup>
/**
 * The profile page — the Vue equivalent of the legacy profile panel
 * (`#profilePanel`) and the hidden `#userInfoBox` the script filled from
 * `GET /get_user_info`.
 *
 * Reads `GET /get_user_info` for the profile block, and reproduces the two
 * image writes: `POST /upload-profile-image` (multipart `file`) and
 * `POST /delete-profile-image`.  Both answer with a 303 redirect to
 * `/user_panel`, which the browser's XHR follows transparently — so a resolved
 * promise is the success signal, exactly as the legacy form submit relied on
 * the redirect.
 *
 * The stored filename is server-generated (`{sanitized_username}{extension}`),
 * so after an upload the image URL is reconstructed from the chosen file's
 * extension; there is no "get image url" endpoint in the ported backend.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { toPersianDigits } from '@/utils/numbers';

const auth = useAuthStore();

const loading = ref(true);
const error = ref('');
const notice = ref('');

const userInfo = ref(null);
const fileInput = ref(null);
const uploading = ref(false);
const deleting = ref(false);

const DEFAULT_AVATAR = '/images/user.png';

const avatarUrl = ref(DEFAULT_AVATAR);
const avatarFailed = ref(false);

const displayName = computed(() => {
    const name = userInfo.value?.name;
    const lastName = userInfo.value?.last_name;

    return name || lastName ? `${name || ''} ${lastName || ''}`.trim() : (auth.user?.username || '—');
});

/**
 * The server reduces the username to `[A-Za-z0-9_.-]` before storing the file.
 * Reproduced here so the post-upload image URL matches the stored name.
 */
function sanitizeUsername(username) {
    const safe = String(username).replace(/[^A-Za-z0-9_.-]/g, '_').slice(0, 64);

    return safe === '' ? 'user' : safe;
}

function extensionOf(filename) {
    const base = String(filename).split('/').pop();
    const dot = base.lastIndexOf('.');

    return dot === -1 ? '' : base.slice(dot).toLowerCase();
}

function showNotice(message) {
    notice.value = message;

    window.setTimeout(() => {
        notice.value = '';
    }, 4000);
}

async function loadProfile() {
    const response = await api.get('/get_user_info', { baseURL: '' });
    userInfo.value = response?.data ?? null;
}

function openFileInput() {
    fileInput.value?.click();
}

async function uploadImage(event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;
    error.value = '';

    const formData = new FormData();
    formData.append('file', file);

    try {
        await api.post('/upload-profile-image', formData, { baseURL: '' });
        avatarFailed.value = false;
        avatarUrl.value = `/uploads/${sanitizeUsername(auth.username)}${extensionOf(file.name)}`;
        showNotice('تصویر پروفایل با موفقیت بارگذاری شد.');
        await loadProfile();
    } catch (failure) {
        error.value = failure?.message || 'بارگذاری تصویر انجام نشد.';
    } finally {
        uploading.value = false;

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
}

async function deleteImage() {
    if (!window.confirm('آیا از حذف عکس پروفایل مطمئن هستید؟')) {
        return;
    }

    deleting.value = true;
    error.value = '';

    try {
        await api.post('/delete-profile-image', {}, { baseURL: '' });
        avatarUrl.value = DEFAULT_AVATAR;
        showNotice('تصویر پروفایل حذف شد.');
    } catch (failure) {
        error.value = failure?.message || 'حذف تصویر انجام نشد.';
    } finally {
        deleting.value = false;
    }
}

function onAvatarError() {
    avatarFailed.value = true;
    avatarUrl.value = DEFAULT_AVATAR;
}

onMounted(async () => {
    try {
        await loadProfile();
    } catch (failure) {
        error.value = failure?.message || 'خطا در دریافت اطلاعات پروفایل.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="profile-panel-page" aria-label="پروفایل من">
        <div class="profile-panel-body">
            <div class="profile-panel-avatar-block">
                <img
                    class="profile-panel-avatar"
                    id="profilePanelAvatar"
                    :src="avatarFailed ? DEFAULT_AVATAR : avatarUrl"
                    alt="تصویر پروفایل"
                    @error="onAvatarError"
                >
                <div class="profile-panel-avatar-actions">
                    <button
                        type="button"
                        class="profile-upload-btn"
                        data-action="open-file-input"
                        :disabled="uploading"
                        @click="openFileInput"
                    >
                        {{ uploading ? 'در حال بارگذاری…' : 'انتخاب عکس جدید' }}
                    </button>
                    <button
                        type="button"
                        class="profile-delete-btn"
                        data-action="confirm-delete"
                        :disabled="deleting"
                        @click="deleteImage"
                    >
                        {{ deleting ? 'در حال حذف…' : 'حذف عکس' }}
                    </button>
                </div>
            </div>

            <div class="profile-panel-grid">
                <section class="profile-panel-card">
                    <h3>📋 اطلاعات شخصی</h3>
                    <ul class="profile-field-list">
                        <li class="profile-field-row">
                            <span class="profile-field-value">در بالای این بخش قابل تغییر است.</span>
                            <span class="profile-field-label">تصویر پروفایل</span>
                        </li>
                        <li class="profile-field-row">
                            <span class="profile-field-value">{{ displayName }}</span>
                            <span class="profile-field-label">نام و نام خانوادگی</span>
                        </li>
                        <li class="profile-field-row">
                            <span class="profile-field-value">{{ auth.user?.username || '—' }}</span>
                            <span class="profile-field-label">نام کاربری</span>
                        </li>
                        <li class="profile-field-row">
                            <span class="profile-field-value">{{ auth.user?.role || 'کارشناس فناوری اطلاعات' }}</span>
                            <span class="profile-field-label">سمت</span>
                        </li>
                        <li class="profile-field-row">
                            <span class="profile-field-value">{{ userInfo?.department || 'واحد فناوری اطلاعات' }}</span>
                            <span class="profile-field-label">واحد سازمانی</span>
                        </li>
                        <li class="profile-field-row">
                            <span class="profile-field-value">{{ userInfo?.work_hours ? toPersianDigits(userInfo.work_hours) : '—' }}</span>
                            <span class="profile-field-label">شماره تماس</span>
                        </li>
                        <li class="profile-field-row">
                            <span class="profile-field-value">{{ userInfo?.substitute || '—' }}</span>
                            <span class="profile-field-label">ایمیل</span>
                        </li>
                    </ul>
                </section>

                <section class="profile-panel-card">
                    <h3>🔐 حساب کاربری</h3>
                    <ul>
                        <li>تغییر رمز عبور</li>
                        <li>فعال کردن احراز هویت دو مرحله‌ای</li>
                        <li>مشاهده دستگاه‌های متصل</li>
                        <li>خروج از همه دستگاه‌ها</li>
                    </ul>
                </section>

                <section class="profile-panel-card">
                    <h3>📈 فعالیت‌ها</h3>
                    <ul>
                        <li>آخرین ورودها</li>
                        <li>تاریخ آخرین تغییر رمز</li>
                        <li>لاگ فعالیت‌های خود کاربر</li>
                    </ul>
                </section>
            </div>
        </div>

        <input
            ref="fileInput"
            type="file"
            id="fileInput"
            name="file"
            accept="image/*"
            style="display: none;"
            @change="uploadImage"
        >
    </section>
</template>
