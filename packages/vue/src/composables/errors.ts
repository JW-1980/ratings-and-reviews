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
