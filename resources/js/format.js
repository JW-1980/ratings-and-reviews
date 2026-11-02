/**
 * Locale-aware formatting with Intl (no hardcoded separators).
 */

/** @param {number|null} value */
export function formatScore(value, locale, digits = 1) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '–';
    }

    return new Intl.NumberFormat(locale, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
}

export function formatNumber(value, locale) {
    return new Intl.NumberFormat(locale).format(Number(value ?? 0));
}

/** @param {{ cents: number, currency: string }|null} money */
export function formatMoney(money, locale) {
    if (!money) {
        return '';
    }

    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: money.currency || 'EUR' }).format(money.cents / 100);
    } catch {
        return `${(money.cents / 100).toFixed(2)} ${money.currency}`;
    }
}

const UNITS = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

/** "3 days ago" in the locale. */
export function formatRelative(iso, locale, now = Date.now()) {
    if (!iso) {
        return '';
    }

    const time = Date.parse(iso);

    if (Number.isNaN(time)) {
        return '';
    }

    const seconds = Math.round((time - now) / 1000);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return '';
}

export function formatDate(iso, locale, options = { dateStyle: 'medium' }) {
    if (!iso) {
        return '';
    }

    const time = Date.parse(iso);

    return Number.isNaN(time) ? '' : new Intl.DateTimeFormat(locale, options).format(new Date(time));
}

/** "2026-03" as a short month label. */
export function formatMonth(period, locale) {
    const match = /^(\d{4})-(\d{2})/.exec(period ?? '');

    if (!match) {
        return period ?? '';
    }

    return new Intl.DateTimeFormat(locale, { month: 'short', year: 'numeric' }).format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)));
}

/**
 * Sparkline path for the score trend, in a `width` × `height` box, on a 1 … scaleMax axis.
 *
 * @param {Array<{ average: number|null }>} points
 * @returns {{ line: string, dots: Array<{ x: number, y: number }> }}
 */
export function sparklinePath(points, scaleMax, width = 120, height = 32, padding = 3) {
    const usable = points.map((point, index) => ({ index, value: point.average })).filter((point) => point.value !== null && point.value !== undefined);

    if (usable.length === 0) {
        return { line: '', dots: [] };
    }

    const span = Math.max(1, points.length - 1);
    const toX = (index) => padding + (index / span) * (width - padding * 2);
    const toY = (value) => padding + (1 - (value - 1) / Math.max(1, scaleMax - 1)) * (height - padding * 2);
    const dots = usable.map((point) => ({ x: Math.round(toX(point.index) * 100) / 100, y: Math.round(toY(point.value) * 100) / 100 }));

    if (dots.length === 1) {
        dots[0].x = width / 2;
    }

    return { line: dots.map((dot, index) => `${index === 0 ? 'M' : 'L'}${dot.x} ${dot.y}`).join(' '), dots };
}
