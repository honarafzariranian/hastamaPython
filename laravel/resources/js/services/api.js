import axios from 'axios';

/**
 * The one place the Vue application talks to Laravel.
 *
 * Components never call `fetch` or `axios` directly: the brief requires a
 * consistent API layer, and centralising it is what makes CSRF, timeouts,
 * session expiry and validation errors behave the same everywhere.
 *
 * Responsibilities
 *   * same-origin cookies (session + XSRF-TOKEN) so Laravel's session guard and
 *     CSRF middleware work without a token round-trip;
 *   * JSON negotiation, so an error never arrives as an HTML page;
 *   * unwrapping the server envelope {success, data, message};
 *   * turning every failure into one predictable shape the UI can render,
 *     in Persian, without ever surfacing a stack trace.
 *
 * Note on CSRF: Laravel writes a readable `XSRF-TOKEN` cookie and expects it
 * back in the `X-XSRF-TOKEN` header.  axios does this automatically for
 * same-origin requests, which is why no manual token plumbing appears here —
 * it replaces the `csrf-bootstrap.js` fetch patch in the old client.
 *
 * Note on `baseURL`: only `/api/*` is prefixed.  Most of the ported handlers
 * keep the Python application's root-level paths — `POST /login_user`,
 * `/get_users`, `/change_hourly_pass_status`, `/master-admin/api/users` —
 * because the running front-end is still a client of those URLs during the
 * side-by-side period.  Any call to one of them must pass `{ baseURL: '' }`;
 * without it the request goes to `/api/…`, which either 404s, is answered by the
 * SPA shell, or hits a same-named route that rejects the verb.  The check is
 * `python tools/api_paths.py`, which resolves every call in the app against the
 * live route table.
 */
const client = axios.create({
    baseURL: '/api',
    withCredentials: true,
    timeout: 30000,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * A failure the UI can always rely on.
 *
 * @typedef {object} ApiFailure
 * @property {false} success
 * @property {string} message  Persian, safe to display verbatim
 * @property {number} status   HTTP status, or 0 when the request never landed
 * @property {Record<string, string[]>|null} errors  field errors on 422 only
 * @property {boolean} offline true when the browser could not reach the server
 */

/**
 * @param {import('axios').AxiosError} error
 * @returns {ApiFailure}
 */
function normalizeError(error) {
    const status = error.response?.status ?? 0;
    const body = error.response?.data;

    let message = typeof body?.message === 'string' && body.message !== '' ? body.message : null;

    if (!message) {
        if (status === 0) {
            message = 'ارتباط با سرور برقرار نشد. اتصال شبکه یا اینترنت را بررسی کنید.';
        } else if (status === 401 || status === 419) {
            message = 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.';
        } else if (status === 403) {
            message = 'شما به این بخش دسترسی ندارید.';
        } else if (status === 404) {
            message = 'درخواست مورد نظر یافت نشد.';
        } else if (status === 422) {
            message = 'اطلاعات ارسالی معتبر نیست.';
        } else if (status === 429) {
            message = 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی بعد تلاش کنید.';
        } else if (status >= 500) {
            message = 'خطای غیرمنتظره‌ای در سرور رخ داد. لطفاً دوباره تلاش کنید.';
        } else {
            message = 'درخواست ناموفق بود.';
        }
    }

    return {
        success: false,
        message,
        status,
        errors: body?.errors ?? null,
        offline: status === 0,
    };
}

/**
 * Requests that succeeded at the HTTP level but reported failure in the
 * envelope still have to become rejections, otherwise every caller would need
 * to re-check `success` by hand.
 *
 * @template T
 * @param {import('axios').AxiosResponse<T>} response
 * @returns {T}
 */
function unwrap(response) {
    const body = response.data;

    if (body && typeof body === 'object' && body.success === false) {
        const error = new Error(body.message || 'درخواست ناموفق بود.');
        error.apiFailure = {
            success: false,
            message: body.message || 'درخواست ناموفق بود.',
            status: response.status,
            errors: body.errors ?? null,
            offline: false,
        };

        throw error;
    }

    return body;
}

client.interceptors.response.use(unwrap, (error) => Promise.reject(normalizeError(error)));

/**
 * @param {string} url
 * @param {object} [config]
 * @returns {Promise<any>}
 */
async function get(url, config = {}) {
    return client.get(url, config);
}

/**
 * @param {string} url
 * @param {unknown} [payload]
 * @param {object} [config]
 * @returns {Promise<any>}
 */
async function post(url, payload = {}, config = {}) {
    return client.post(url, payload, config);
}

/**
 * @param {string} url
 * @param {unknown} [payload]
 * @param {object} [config]
 * @returns {Promise<any>}
 */
async function put(url, payload = {}, config = {}) {
    return client.put(url, payload, config);
}

/**
 * @param {string} url
 * @param {unknown} [payload]
 * @param {object} [config]
 * @returns {Promise<any>}
 */
async function patch(url, payload = {}, config = {}) {
    return client.patch(url, payload, config);
}

/**
 * @param {string} url
 * @param {object} [config]
 * @returns {Promise<any>}
 */
async function destroy(url, config = {}) {
    return client.delete(url, config);
}

export const api = { get, post, put, patch, delete: destroy, client };

export default api;
