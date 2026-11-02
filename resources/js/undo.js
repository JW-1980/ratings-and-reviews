/**
 * Countdown for "undo after posting": the comment is stored with `publish_at` and only
 * published (and notified) when the window closes; until then `POST comments/{id}/undo`
 * withdraws it.
 */

/**
 * @param {{ seconds: number, onTick?: (remaining: number) => void, onExpire?: () => void, now?: () => number, setInterval?: typeof setInterval, clearInterval?: typeof clearInterval }} options
 */
export function createUndoCountdown(options) {
    const now = options.now ?? (() => Date.now());
    const set = options.setInterval ?? ((fn, ms) => setInterval(fn, ms));
    const clear = options.clearInterval ?? ((id) => clearInterval(id));
    const endsAt = now() + Math.max(0, options.seconds) * 1000;
    let timer = null;
    let state = 'running';

    const remaining = () => Math.max(0, Math.ceil((endsAt - now()) / 1000));

    const tick = () => {
        if (state !== 'running') {
            return;
        }

        const left = remaining();
        options.onTick?.(left);

        if (left <= 0) {
            state = 'expired';
            clear(timer);
            options.onExpire?.();
        }
    };

    if (options.seconds <= 0) {
        state = 'expired';
        queueMicrotask(() => options.onExpire?.());
    } else {
        options.onTick?.(remaining());
        timer = set(tick, 250);
    }

    return {
        remaining,
        get state() {
            return state;
        },
        /** Stops the countdown (the caller then calls the undo endpoint). Returns false when it already expired. */
        cancel() {
            if (state !== 'running') {
                return false;
            }

            state = 'cancelled';
            clear(timer);

            return true;
        },
        dispose() {
            if (state === 'running') {
                state = 'disposed';
            }

            clear(timer);
        },
    };
}

/** Seconds left of an undo window from the server's `publish_at`. */
export function secondsUntil(iso, now = Date.now()) {
    if (!iso) {
        return 0;
    }

    const time = Date.parse(iso);

    return Number.isNaN(time) ? 0 : Math.max(0, Math.ceil((time - now) / 1000));
}
