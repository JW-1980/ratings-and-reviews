import { ApiError } from '../shared';
import type { Translate } from '../types';

/** A user-facing message for a failed request; validation messages come from the server. */
export function errorMessage(error: unknown, t: Translate): string {
    if (error instanceof ApiError) {
        if (error.isNetwork) {
            return t('common.network_error');
        }

        if (error.isUnauthenticated) {
            return t('common.login_required');
        }

        if (error.isThrottled) {
            return error.retryAfter ? t('common.throttled', { seconds: error.retryAfter }) : error.message;
        }

        if (error.isValidation) {
            const first = Object.values(error.errors)[0]?.[0];

            return first ?? error.message;
        }

        return error.message || t('common.error');
    }

    return t('common.error');
}

/** First validation message per field. */
export function fieldErrors(error: unknown): Record<string, string> {
    if (!(error instanceof ApiError) || !error.isValidation) {
        return {};
    }

    return Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']));
}

/**
 * A failed upload: the server's own line for the daily quota (a 429 whose Retry-After is
 * hours away), otherwise the usual message, or the generic upload failure.
 */
export function uploadErrorMessage(error: unknown, t: Translate): string {
    if (error instanceof ApiError) {
        const server = (error.data as { message?: unknown } | null)?.message;

        // The per-minute throttle answers within a minute (translated by errorMessage); the daily quota names its own limit.
        if (error.isThrottled && (error.retryAfter ?? 0) > 60 && typeof server === 'string' && server !== '') {
            return server;
        }

        return errorMessage(error, t);
    }

    return t('editor.image_failed');
}
