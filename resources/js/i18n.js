/**
 * Translations for the browser side.
 *
 * The strings live in the package's `lang/{nl,en}/ui.php` (the same source the Blade
 * components read through `__('ratings-and-reviews::ui.…')`). The host injects that array:
 * Blade components print it as JSON next to their markup, a Vue host passes
 * `trans('ratings-and-reviews::ui')` as `messages` to the plugin. There is no built-in
 * fallback language: a missing key renders as the key itself and is reported once on the
 * console, so a gap is visible instead of silently showing another language.
 *
 * Syntax follows Laravel: `:name` placeholders (`:Name` capitalises, `:NAME` upper-cases)
 * and `trans_choice` style plurals (`{0} none|{1} one|[2,*] :count items` or `one|many`).
 */

const warned = new Set();

/**
 * @param {Record<string, unknown>} messages nested object, as `trans('ratings-and-reviews::ui')` returns
 * @param {string} key dot notation
 * @returns {string|undefined}
 */
function lookup(messages, key) {
    let node = /** @type {unknown} */ (messages);

    for (const part of key.split('.')) {
        if (node === null || typeof node !== 'object' || !(part in /** @type {object} */ (node))) {
            return undefined;
        }

        node = /** @type {Record<string, unknown>} */ (node)[part];
    }

    return typeof node === 'string' ? node : undefined;
}

/**
 * @param {string} line
 * @param {Record<string, string|number>} replace
 */
export function replacePlaceholders(line, replace) {
    const keys = Object.keys(replace).sort((a, b) => b.length - a.length);

    return keys.reduce((text, name) => {
        const value = String(replace[name]);

        return text
            .replaceAll(`:${name.toUpperCase()}`, value.toUpperCase())
            .replaceAll(`:${name.charAt(0).toUpperCase()}${name.slice(1)}`, value.charAt(0).toUpperCase() + value.slice(1))
            .replaceAll(`:${name}`, value);
    }, line);
}

/**
 * Picks the plural segment of a Laravel choice line for `count`.
 *
 * @param {string} line
 * @param {number} count
 */
export function choose(line, count) {
    const segments = line.split('|');

    for (const segment of segments) {
        const exact = segment.match(/^\s*\{(-?\d+)\}\s?([\s\S]*)$/);

        if (exact) {
            if (Number(exact[1]) === count) {
                return exact[2];
            }

            continue;
        }

        const range = segment.match(/^\s*\[(-?\d+|\*),\s*(-?\d+|\*)\]\s?([\s\S]*)$/);

        if (range) {
            const low = range[1] === '*' ? -Infinity : Number(range[1]);
            const high = range[2] === '*' ? Infinity : Number(range[2]);

            if (count >= low && count <= high) {
                return range[3];
            }
        }
    }

    const plain = segments.filter((segment) => !/^\s*(\{-?\d+\}|\[(-?\d+|\*),\s*(-?\d+|\*)\])/.test(segment));

    if (plain.length === 0) {
        return segments[segments.length - 1];
    }

    return plain.length === 1 || count === 1 ? plain[0] : plain[1];
}

/**
 * @typedef {(key: string, replace?: Record<string, string|number>) => string} TranslateFn
 * @typedef {(key: string, count: number, replace?: Record<string, string|number>) => string} ChoiceFn
 */

/**
 * @param {Record<string, unknown>} messages
 * @param {{ onMissing?: (key: string) => void }} [options]
 * @returns {{ t: TranslateFn, tc: ChoiceFn, has: (key: string) => boolean }}
 */
export function createTranslator(messages, options = {}) {
    const onMissing = options.onMissing ?? ((key) => {
        if (!warned.has(key)) {
            warned.add(key);
            console.warn(`[ratings-and-reviews] Missing translation: ui.${key}`);
        }
    });

    /** @type {TranslateFn} */
    const t = (key, replace = {}) => {
        const line = lookup(messages ?? {}, key);

        if (line === undefined) {
            onMissing(key);

            return key;
        }

        return replacePlaceholders(line, replace);
    };

    /** @type {ChoiceFn} */
    const tc = (key, count, replace = {}) => {
        const line = lookup(messages ?? {}, key);

        if (line === undefined) {
            onMissing(key);

            return key;
        }

        return replacePlaceholders(choose(line, count), { count, ...replace });
    };

    return { t, tc, has: (key) => lookup(messages ?? {}, key) !== undefined };
}
