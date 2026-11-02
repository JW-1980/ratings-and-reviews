/**
 * Client for the deterministic tone nudge (`POST tone-check`, ToneNudge on the server,
 * a nl/en word list, no AI). The check never blocks posting: when the server suggests a
 * rewording the composer asks once, and posting with `confirm_tone: true` always goes
 * through.
 */

/**
 * @param {import('./api.js').ApiClient} api
 */
export function createToneChecker(api) {
    let controller = null;
    let lastText = null;
    let lastResult = null;

    return {
        /**
         * @param {string} text the draft text
         * @returns {Promise<{ suggestion: string|null, matches: string[], unavailable?: boolean }>}
         */
        async check(text) {
            const trimmed = (text ?? '').trim();

            if (trimmed === '') {
                return { suggestion: null, matches: [] };
            }

            if (trimmed === lastText && lastResult) {
                return lastResult;
            }

            controller?.abort();
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

            try {
                const result = await api.toneCheck(trimmed, controller?.signal);
                lastText = trimmed;
                lastResult = result;

                return result;
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return { suggestion: null, matches: [] };
                }

                // The nudge is advisory: when the check is unavailable, posting continues
                // and the server applies its own check (confirm_tone flow) if it has one.
                return { suggestion: null, matches: [], unavailable: true };
            }
        },
        reset() {
            controller?.abort();
            lastText = null;
            lastResult = null;
        },
    };
}

/**
 * The suggestion of a post the server refused with the nudge (ToneNudgeRequired: 422 with
 * `tone_nudge: { message, matches }` and an error on `confirm_tone`). Resending with
 * `confirm_tone: true` posts it.
 *
 * @param {import('./api.js').ApiError} error
 * @returns {string|null}
 */
export function toneSuggestionFromError(error) {
    if (!error || error.status !== 422) {
        return null;
    }

    return error.data?.tone_nudge?.message ?? error.errors?.confirm_tone?.[0] ?? null;
}
