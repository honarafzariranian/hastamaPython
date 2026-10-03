import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import api from '@/services/api';

/**
 * Authentication state — the migrated session model.
 *
 * The legacy application kept the signed-in identity in the server session and
 * read it back through `/api/me`; this store is the client half of that.  It is
 * deliberately small: the store holds only what the UI needs to render, and
 * every privileged action is authorised again by the Laravel backend, so a
 * tampered `is_admin` flag here grants nothing.
 *
 * `is_admin` / `is_master_admin` mirror the two session flags the Python set
 * (`_require_admin` / `_master_admin`).  A master admin also satisfies the
 * admin check, exactly as the Python's `get_is_admin_from_session` did.
 */
export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const ready = ref(false);

    const isAuthenticated = computed(() => user.value !== null);
    const isAdmin = computed(() => user.value?.is_admin === true);
    const isMasterAdmin = computed(() => user.value?.is_master_admin === true);
    const username = computed(() => user.value?.username ?? '');

    /**
     * Ask the backend whether the current session is good, and who it belongs to.
     *
     * `GET /api/me` answers `{"success": true, "username": …, "role": …,
     * "is_admin": …, "is_master_admin": …}`.  A 401 means there is no session,
     * which is the ordinary anonymous state rather than an error.
     */
    async function fetchMe() {
        try {
            const response = await api.get('/me');
            user.value = response.data ?? null;
        } catch (failure) {
            user.value = null;
            // 401 is the expected anonymous answer; anything else is surfaced.
            if (failure.status && failure.status !== 401) {
                throw failure;
            }
        } finally {
            ready.value = true;
        }

        return user.value;
    }

    /**
     * Submit credentials to `POST /login_user`.
     *
     * The endpoint answers the legacy envelope: `{"success": true}` on success
     * (the session cookie is set by the response) and
     * `{"success": false, "message": …}` on failure.  The CAPTCHA is sent only
     * when the operator enabled it — the backend validates it before any
     * credential lookup, so an empty string is simply "not supplied".
     *
     * `baseURL: ''` because this endpoint lives at the application root, like
     * every other ported handler route.  `/login_user` is the path the Python
     * front-end called (`static/js/script.js`) and the one `routes/web.php`
     * registers; without the override the shared client's `/api` prefix turned
     * the request into `POST /api/login_user`, which does not exist.
     */
    async function login({ username: usernameValue, password, captcha = '' }) {
        const response = await api.post(
            '/login_user',
            {
                username: usernameValue,
                password,
                captcha,
            },
            { baseURL: '' },
        );

        if (response.success === false) {
            const error = new Error(response.message || 'ورود ناموفق بود.');
            error.apiFailure = {
                success: false,
                message: response.message || 'ورود ناموفق بود.',
                status: 200,
                errors: null,
                offline: false,
            };
            throw error;
        }

        // The session now exists; pull the identity the guards and the router
        // need.  A failure here leaves the session set but the store empty,
        // which the router treats as signed out.
        await fetchMe();

        return user.value;
    }

    /**
     * Sign out through `GET /logout` (the verb every existing logout link uses),
     * then clear the local state.  Root path, so it needs the same `baseURL`
     * override as the login call.
     */
    async function logout() {
        try {
            await api.get('/logout', { baseURL: '' });
        } finally {
            user.value = null;
        }
    }

    return {
        user,
        ready,
        isAuthenticated,
        isAdmin,
        isMasterAdmin,
        username,
        fetchMe,
        login,
        logout,
    };
});
