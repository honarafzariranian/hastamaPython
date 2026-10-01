/**
 * Persian numeral conversion.
 *
 * Port of app/static/js/number-format.js (`hastamaToFA`) plus the inverse the
 * Python side already has (`convert_farsi_to_english`).  Numbers are shown to
 * users in Persian digits everywhere in Hastama, but they are stored, sent and
 * parsed as ASCII digits — conflating the two is how quantities and dates get
 * mangled, so both directions live here together.
 */
const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

/**
 * Render a value with Persian digits for display.
 *
 * @param {string|number|null|undefined} value
 * @returns {string}
 */
export function toPersianDigits(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value).replace(/[0-9]/g, (digit) => PERSIAN_DIGITS[Number(digit)]);
}

/**
 * Convert Persian or Arabic digits back to ASCII so a value typed by a user can
 * be parsed and sent to the API.
 *
 * @param {string|number|null|undefined} value
 * @returns {string}
 */
export function toLatinDigits(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value)
        .replace(/[۰-۹]/g, (digit) => String(PERSIAN_DIGITS.indexOf(digit)))
        .replace(/[٠-٩]/g, (digit) => String(ARABIC_DIGITS.indexOf(digit)));
}

/**
 * Format an integer with Persian thousands separators.
 *
 * @param {string|number} value
 * @returns {string}
 */
export function formatNumber(value) {
    const numeric = Number(toLatinDigits(value));

    if (!Number.isFinite(numeric)) {
        return toPersianDigits(value);
    }

    return toPersianDigits(numeric.toLocaleString('en-US'));
}
