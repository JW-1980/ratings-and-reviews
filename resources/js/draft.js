/**
 * Autosaved drafts in localStorage, per subject and reply target. Storage can be missing
 * or blocked (private mode, quota); every access is guarded so the composer keeps working
 * without it. Drafts older than `maxAgeDays` are discarded.
 */

const PREFIX = 'rr:draft:';

function storage() {
    try {
        return typeof window !== 'undefined' && window.localStorage ? window.localStorage : null;
    } catch {
        return null;
    }
}

/**
 * @param {string} key for example `product:12:comment:root`
 * @param {{ maxAgeDays?: number, debounceMs?: number, onResult?: (saved: boolean) => void }} [options]
 */
export function createDraftStore(key, options = {}) {
    const fullKey = PREFIX + key;
    const maxAge = (options.maxAgeDays ?? 7) * 86400000;
    const debounceMs = options.debounceMs ?? 600;
    let timer = null;

    const write = (value) => {
        const store = storage();

        if (!store) {
            return false;
        }

        try {
            if (value === null || value === undefined || value === '') {
                store.removeItem(fullKey);
            } else {
                store.setItem(fullKey, JSON.stringify({ value, savedAt: Date.now() }));
            }

            return true;
        } catch {
            return false;
        }
    };

    return {
        key: fullKey,
        /** @returns {{ value: unknown, savedAt: number }|null} */
        load() {
            const store = storage();

            if (!store) {
                return null;
            }

            try {
                const raw = store.getItem(fullKey);

                if (raw === null) {
                    return null;
                }

                const parsed = JSON.parse(raw);

                if (!parsed || typeof parsed.savedAt !== 'number' || Date.now() - parsed.savedAt > maxAge) {
                    store.removeItem(fullKey);

                    return null;
                }

                return parsed;
            } catch {
                return null;
            }
        },
        /** Debounced save; `onResult` reports whether the draft could be stored on this device. */
        save(value) {
            clearTimeout(timer);
            timer = setTimeout(() => options.onResult?.(write(value)), debounceMs);
        },
        saveNow: write,
        clear() {
            clearTimeout(timer);
            write(null);
        },
        dispose() {
            clearTimeout(timer);
        },
    };
}
