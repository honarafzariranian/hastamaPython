<script setup>
/**
 * Session administration — the Vue equivalent of the legacy `loadSessions()`,
 * `ma_terminateSession()`, `ma_terminateAllSessions()` and
 * `ma_deleteAllSessions()` in `master-admin.js`.
 *
 *   * `GET /master-admin/api/sessions?active_only=true` — the registry list;
 *   * `POST /master-admin/api/sessions/{session_key}/terminate` — forced logout
 *     (the history row survives);
 *   * `POST /master-admin/api/sessions/terminate-all` — every active session
 *     except the master-administrator accounts, whose names come back in
 *     `kept_usernames`;
 *   * `DELETE /master-admin/api/sessions/{session_key}` — remove one record;
 *   * `DELETE /master-admin/api/sessions` — remove every record.
 *
 * Terminate and delete are different operations on purpose (the registry is what
 * makes a signed cookie revocable), so the two actions stay separate and both
 * are confirmed through the shared dialog.
 */
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { toPersianDigits } from '@/utils/numbers';
import StatusBadge from '@/pages/control/StatusBadge.vue';
import PaginationBar from '@/pages/control/PaginationBar.vue';
import ConfirmDialog from '@/pages/control/ConfirmDialog.vue';

const PER_PAGE = 25;

const loading = ref(true);
const error = ref('');

const sessions = ref([]);
const total = ref(0);
const page = ref(1);
const pages = ref(1);

const confirmState = ref(null);
let confirmResolver = null;

const busy = ref('');

const activeCountText = computed(() =>
    total.value > 0
        ? `${toPersianDigits(total.value)} نشست فعال`
        : 'نشست فعالی وجود ندارد'
);

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const raw = String(value);
    const date = new Date(raw.includes(' ') && !raw.includes('T') ? raw.replace(' ', 'T') : raw);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fa-IR');
}

function confirmAction({ title, msg, confirmText = 'تأیید', cancelText = 'انصراف', danger = true }) {
    confirmState.value = { title, msg, confirmText, cancelText, danger };

    return new Promise((resolve) => {
        confirmResolver = resolve;
    });
}

function resolveConfirm(result) {
    confirmState.value = null;

    if (confirmResolver) {
        confirmResolver(result);
        confirmResolver = null;
    }
}

async function loadSessions() {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/master-admin/api/sessions', {
            baseURL: '',
            params: {
                page: page.value,
                per_page: PER_PAGE,
                active_only: true,
            },
        });

        sessions.value = Array.isArray(response.data) ? response.data : [];
        total.value = response.total ?? sessions.value.length;
        pages.value = response.pages ?? 1;
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خطا در بارگذاری نشست‌ها';
        sessions.value = [];
        total.value = 0;
        pages.value = 1;
    } finally {
        loading.value = false;
    }
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pages.value || nextPage === page.value) {
        return;
    }

    page.value = nextPage;
    loadSessions();
}

async function terminateSession(session) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'خاتمه نشست',
        msg: `آیا از خاتمه نشست «${session.username}» اطمینان دارید؟`,
        confirmText: 'خاتمه یابد',
    });

    if (!accepted) {
        return;
    }

    busy.value = session.session_key;

    try {
        const response = await api.post(
            `/master-admin/api/sessions/${encodeURIComponent(session.session_key)}/terminate`,
            {},
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'نشست پیدا نشد یا قبلاً خاتمه یافته است.';
            return;
        }

        await loadSessions();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خاتمه نشست انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function deleteSession(session) {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف رکورد نشست',
        msg: 'آیا از حذف دائمی رکورد این نشست اطمینان دارید؟ این عملیات قابل بازگشت نیست.',
        confirmText: 'حذف شود',
    });

    if (!accepted) {
        return;
    }

    busy.value = session.session_key;

    try {
        const response = await api.delete(
            `/master-admin/api/sessions/${encodeURIComponent(session.session_key)}`,
            { baseURL: '' },
        );

        if (response.data?.success === false) {
            error.value = 'رکورد نشست پیدا نشد یا حذف نشد.';
            return;
        }

        await loadSessions();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف رکورد نشست انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function terminateAllSessions() {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'خاتمه همه نشست‌ها',
        msg: 'همه نشست‌های فعال کاربران خاتمه می‌یابند و کاربران باید دوباره وارد شوند. نشست مدیران ارشد حفظ می‌شود تا دسترسی شما به این صفحه قطع نشود.',
        confirmText: 'همه خاتمه یابند',
    });

    if (!accepted) {
        return;
    }

    busy.value = '*';

    try {
        await api.post('/master-admin/api/sessions/terminate-all', {}, { baseURL: '' });
        error.value = '';
        await loadSessions();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'خاتمه گروهی نشست‌ها انجام نشد';
    } finally {
        busy.value = '';
    }
}

async function deleteAllSessions() {
    if (busy.value) {
        return;
    }

    const accepted = await confirmAction({
        title: 'حذف همه رکوردهای نشست',
        msg: 'تمام رکوردهای نشست (فعال و تاریخچهٔ ورود) برای همیشه حذف می‌شوند. این عملیات قابل بازگشت نیست و همهٔ کاربران — از جمله نشست‌های مدیران — در درخواست بعدی خود باید دوباره وارد شوند.',
        confirmText: 'همه حذف شوند',
    });

    if (!accepted) {
        return;
    }

    busy.value = '*';

    try {
        await api.delete('/master-admin/api/sessions', { baseURL: '' });
        error.value = '';
        await loadSessions();
    } catch (failure) {
        error.value = failure?.apiFailure?.message || failure?.message || 'حذف رکوردهای نشست انجام نشد';
    } finally {
        busy.value = '';
    }
}

onMounted(loadSessions);
</script>

<template>
    <div>
        <p v-if="error" class="h-alert" role="alert">{{ error }}</p>

        <ConfirmDialog :state="confirmState" @resolve="resolveConfirm" />

        <div class="ma-filters">
            <span class="ma-empty__text" style="color:#64748b">{{ activeCountText }}</span>
            <button
                type="button"
                class="ma-btn ma-btn--danger"
                :disabled="busy === '*'"
                @click="terminateAllSessions"
            >
                خاتمه همه نشست‌ها
            </button>
            <button
                type="button"
                class="ma-btn ma-btn--danger"
                :disabled="busy === '*'"
                @click="deleteAllSessions"
            >
                حذف همه رکوردها
            </button>
        </div>

        <div class="ma-panel-card">
            <div class="ma-panel-card__body--flush">
                <div v-if="loading" class="ma-empty">
                    <div class="ma-empty__text">در حال بارگذاری نشست‌ها…</div>
                </div>

                <div v-else-if="!sessions.length" class="ma-empty">
                    <div class="ma-empty__icon">📭</div>
                    <div class="ma-empty__text">نشست فعالی موجود نیست</div>
                </div>

                <div v-else class="ma-table__scroll">
                    <table class="ma-table">
                        <thead>
                            <tr>
                                <th>کاربر</th>
                                <th>IP</th>
                                <th>زمان ورود</th>
                                <th>آخرین فعالیت</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="session in sessions" :key="session.session_key">
                                <td>{{ session.username }}</td>
                                <td>{{ session.ip_address || '—' }}</td>
                                <td>{{ formatDateTime(session.login_at) }}</td>
                                <td>{{ formatDateTime(session.last_activity) }}</td>
                                <td><StatusBadge :value="session.is_active ? 'active' : 'disabled'" /></td>
                                <td>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === session.session_key"
                                        @click="terminateSession(session)"
                                    >
                                        خاتمه
                                    </button>
                                    <button
                                        type="button"
                                        class="ma-btn ma-btn--danger ma-btn--sm"
                                        :disabled="busy === session.session_key"
                                        @click="deleteSession(session)"
                                    >
                                        حذف رکورد
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PaginationBar :page="page" :pages="pages" @change="goToPage" />
    </div>
</template>
