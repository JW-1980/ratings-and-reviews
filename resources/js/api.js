/**
 * Fetch client for the package JSON API (`routes/api.php`, prefix from
 * `ratings-and-reviews.routes.prefix`, default `/rr`).
 *
 * Every response is passed through `normalize.js`, so components only ever see the
 * normalised shapes; when the server shape changes, only that file changes.
 *
 * CSRF: pass `csrfToken` (the `<meta name="csrf-token">` value, sent as X-CSRF-TOKEN) or
 * leave it out to send Laravel's encrypted XSRF-TOKEN cookie as X-XSRF-TOKEN. Token-based
 * APIs (Sanctum bearer tokens) pass `headers: { Authorization: … }` instead.
 */

import * as normalize from './normalize.js';

const list = (value) => (Array.isArray(value) ? value : []);

export class ApiError extends Error {
    /**
     * @param {string} message
     * @param {{ status: number, errors?: Record<string, string[]>, retryAfter?: number|null, data?: unknown }} details
     */
    constructor(message, details) {
        super(message);
        this.name = 'ApiError';
        this.status = details.status;
        /** @type {Record<string, string[]>} */
        this.errors = details.errors ?? {};
        /** Seconds until a throttled (429) or slow-mode request may be repeated. */
        this.retryAfter = details.retryAfter ?? null;
        this.data = details.data;
    }

    /** First message for a field, for inline errors. */
    first(field) {
        return this.errors[field]?.[0] ?? null;
    }

    get isValidation() {
        return this.status === 422;
    }

    get isUnauthenticated() {
        return this.status === 401;
    }

    get isThrottled() {
        return this.status === 429;
    }

    get isNetwork() {
        return this.status === 0;
    }
}

/**
 * @param {string} name
 * @returns {string}
 */
export function readCookie(name) {
    if (typeof document === 'undefined') {
        return '';
    }

    const prefix = `${name}=`;

    for (const part of document.cookie.split(';')) {
        const cookie = part.trim();

        if (cookie.startsWith(prefix)) {
            try {
                return decodeURIComponent(cookie.slice(prefix.length));
            } catch {
                return '';
            }
        }
    }

    return '';
}

/** @param {{type: string, id: string|number}} subject */
function subjectPath(subject) {
    return `subjects/${encodeURIComponent(subject.type)}/${encodeURIComponent(String(subject.id))}`;
}

/**
 * @param {Record<string, unknown>} query
 */
function queryString(query) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query ?? {})) {
        if (value === undefined || value === null || value === '' || value === false) {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, String(item)));
        } else {
            params.set(key, value === true ? '1' : String(value));
        }
    }

    const text = params.toString();

    return text === '' ? '' : `?${text}`;
}

/**
 * @typedef {object} ApiOptions
 * @property {string} [baseUrl] default '/rr'
 * @property {string|(() => string)} [csrfToken] sent as X-CSRF-TOKEN
 * @property {string} [xsrfCookie] cookie sent as X-XSRF-TOKEN when no csrfToken is given (default 'XSRF-TOKEN')
 * @property {Record<string, string>} [headers]
 * @property {string} [locale] sent as Accept-Language
 * @property {RequestCredentials} [credentials] default 'same-origin'
 * @property {typeof fetch} [fetch]
 */

/**
 * @param {ApiOptions} [options]
 */
export function createApiClient(options = {}) {
    const baseUrl = (options.baseUrl ?? '/rr').replace(/\/+$/, '');
    const fetchImpl = options.fetch ?? ((...args) => globalThis.fetch(...args));

    function csrfHeaders() {
        const token = typeof options.csrfToken === 'function' ? options.csrfToken() : options.csrfToken;

        if (token) {
            return { 'X-CSRF-TOKEN': token };
        }

        const cookie = readCookie(options.xsrfCookie ?? 'XSRF-TOKEN');

        return cookie ? { 'X-XSRF-TOKEN': cookie } : {};
    }

    /**
     * @param {string} method
     * @param {string} path
     * @param {{ query?: Record<string, unknown>, body?: unknown, form?: FormData, signal?: AbortSignal }} [init]
     *   `form` sends multipart/form-data (the browser sets the boundary) instead of JSON.
     */
    async function request(method, path, init = {}) {
        const multipart = init.form !== undefined;
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.locale ? { 'Accept-Language': options.locale } : {}),
            ...(method === 'GET' ? {} : { ...(multipart ? {} : { 'Content-Type': 'application/json' }), ...csrfHeaders() }),
            ...(options.headers ?? {}),
        };

        let response;

        try {
            response = await fetchImpl(`${baseUrl}/${path}${queryString(init.query ?? {})}`, {
                method,
                headers,
                credentials: options.credentials ?? 'same-origin',
                body: multipart ? init.form : (init.body === undefined ? undefined : JSON.stringify(init.body)),
                signal: init.signal,
            });
        } catch (error) {
            if (error?.name === 'AbortError') {
                throw error;
            }

            // The UI translates a network failure from status 0; keep the technical message empty.
            throw new ApiError(error?.message ?? '', { status: 0 });
        }

        if (response.status === 204) {
            return null;
        }

        const text = await response.text();
        let payload = null;

        if (text !== '') {
            try {
                payload = JSON.parse(text);
            } catch {
                payload = null;
            }
        }

        if (!response.ok) {
            const retryHeader = response.headers.get('Retry-After');
            const retryAfter = payload?.retry_after ?? (retryHeader !== null && retryHeader !== '' ? Number(retryHeader) : null);

            throw new ApiError(payload?.message || response.statusText || `HTTP ${response.status}`, {
                status: response.status,
                errors: payload?.errors ?? {},
                retryAfter: Number.isFinite(retryAfter) ? Number(retryAfter) : null,
                data: payload,
            });
        }

        return payload;
    }

    const get = (path, query, signal) => request('GET', path, { query, signal });
    const post = (path, body) => request('POST', path, { body: body ?? {} });

    return {
        request,

        /** @param {{type: string, id: string|number}} subject */
        async ratingSummary(subject, signal) {
            return normalize.ratingSummary((await get(`${subjectPath(subject)}/rating-summary`, {}, signal))?.data);
        },

        /**
         * @param {{type: string, id: string|number}} subject
         * @param {{ sort?: string, rating?: number|null, facets?: Record<string, string>, withPhotos?: boolean, page?: number }} [query]
         */
        async reviews(subject, query = {}, signal) {
            const { facets, withPhotos, ...rest } = query;
            const flat = { ...rest };

            if (withPhotos) {
                flat.with_photos = 1;
            }

            Object.entries(facets ?? {}).forEach(([group, value]) => {
                if (value) {
                    flat[`facet[${group}]`] = value;
                }
            });

            return normalize.reviewPage(await get(`${subjectPath(subject)}/reviews`, flat, signal));
        },

        /** Topic scores without a review: the topics, their averages and the viewer's own scores. */
        async topicRatings(subject, signal) {
            return normalize.topicRatingState((await get(`${subjectPath(subject)}/topic-ratings`, {}, signal))?.data);
        },

        /** @param {Record<string|number, number|null>} changes topic id => value (null removes the score) */
        async saveTopicRatings(subject, changes) {
            const payload = await request('PUT', `${subjectPath(subject)}/topic-ratings`, { body: normalize.topicRatingsPayload(changes) });

            return { state: normalize.topicRatingState(payload?.data), message: payload?.message ?? null };
        },

        /** The subject of the replies to a review: pass it to comments(), createComment(), RrDiscussion, … */
        reviewRepliesSubject(reviewId) {
            return { type: 'rr_review', id: reviewId };
        },

        async createReview(subject, payload) {
            return normalize.saved(await post(`${subjectPath(subject)}/reviews`, normalize.reviewPayload(payload)), normalize.review);
        },

        async updateReview(id, payload) {
            return normalize.saved(await request('PATCH', `reviews/${encodeURIComponent(id)}`, { body: normalize.reviewPayload(payload) }), normalize.review);
        },

        async deleteReview(id) {
            await request('DELETE', `reviews/${encodeURIComponent(id)}`);
        },

        async voteReview(id, dimension, value) {
            return normalize.voteResult((await post(`reviews/${encodeURIComponent(id)}/votes`, { dimension, value }))?.data);
        },

        /**
         * @param {{type: string, id: string|number}} subject
         * @param {{ sort?: string, page?: number, mark_read?: 0|1 }} [query]
         */
        async comments(subject, query = {}, signal) {
            // `mark_read: 0` reads without moving the viewer's "new since" marker.
            return normalize.commentPage(await get(`${subjectPath(subject)}/comments`, query, signal));
        },

        /**
         * @param {{type: string, id: string|number}} subject
         * @param {import('./normalize.js').CommentDraft} payload
         */
        async createComment(subject, payload) {
            return normalize.saved(await post(`${subjectPath(subject)}/comments`, normalize.commentPayload(payload)), normalize.comment);
        },

        async updateComment(id, body, confirmTone = false) {
            return normalize.saved(await request('PATCH', `comments/${encodeURIComponent(id)}`, { body: confirmTone ? { body, confirm_tone: true } : { body } }), normalize.comment);
        },

        async deleteComment(id) {
            await request('DELETE', `comments/${encodeURIComponent(id)}`);
        },

        /** Withdraws a comment inside its undo window. */
        async undoComment(id) {
            await post(`comments/${encodeURIComponent(id)}/undo`);
        },

        /**
         * The rest of a thread the tree cut at the reply cap (`meta.truncated_threads`):
         * flat replies in tree order; page with `cursor` (the previous page's `nextCursor`).
         *
         * The first request passes `after` (the last reply already shown: deepest, then newest).
         *
         * @param {number|string} commentId the thread's root (or any reply in it)
         * @param {{ cursor?: string|null, after?: number|null, limit?: number }} [query]
         */
        async replies(commentId, query = {}, signal) {
            const params = query.cursor ? { cursor: query.cursor } : { after: query.after ?? undefined };

            return normalize.replyPage(await get(`comments/${encodeURIComponent(commentId)}/replies`, { ...params, limit: query.limit ?? undefined }, signal));
        },

        async revisions(id, signal) {
            return ((await get(`comments/${encodeURIComponent(id)}/revisions`, {}, signal))?.data ?? []).map(normalize.revision);
        },

        async voteComment(id, dimension, value) {
            return normalize.voteResult((await post(`comments/${encodeURIComponent(id)}/votes`, { dimension, value }))?.data);
        },

        async votePoll(pollId, optionId) {
            const payload = await post(`polls/${encodeURIComponent(pollId)}/votes`, { option_id: optionId });

            return { poll: normalize.poll(payload?.data), counted: Boolean(payload?.meta?.counted ?? true), changed: Boolean(payload?.meta?.changed ?? true) };
        },

        async poll(pollId, signal) {
            return normalize.poll((await get(`polls/${encodeURIComponent(pollId)}`, {}, signal))?.data);
        },

        async digest(subject, signal) {
            return normalize.digest((await get(`${subjectPath(subject)}/digest`, {}, signal))?.data);
        },

        /** @param {'all'|'reviews'|'comments'} [scope] */
        async search(subject, q, signal, scope = 'all') {
            return normalize.searchResults(await get(`${subjectPath(subject)}/search`, { q, in: scope === 'all' ? undefined : scope }, signal));
        },

        /**
         * @param {{ type: string, id: string|number, reason: string, details?: string|null }} report
         */
        async report(report) {
            const payload = await post('reports', {
                reportable_type: report.type,
                reportable_id: Number(report.id),
                reason: report.reason,
                details: report.details || null,
            });

            return { id: payload?.data?.id ?? null, message: payload?.message ?? null };
        },

        async authorCard(id, signal) {
            return normalize.authorCard((await get(`authors/${encodeURIComponent(id)}/card`, {}, signal))?.data);
        },

        async card(type, id, signal) {
            return normalize.productCard((await get(`cards/${encodeURIComponent(type)}/${encodeURIComponent(id)}`, {}, signal))?.data);
        },

        async wantToTry(subject, reviewId = null) {
            const payload = await post(`${subjectPath(subject)}/want-to-try`, reviewId === null ? {} : { source_review_id: reviewId });

            return { added: payload?.data?.added ?? true, message: payload?.message ?? null };
        },

        /**
         * @param {string} text the draft (editor HTML or plain text)
         */
        async toneCheck(text, signal) {
            const payload = await request('POST', 'tone-check', { body: { text }, signal });

            return normalize.toneResult(payload?.data);
        },

        async qualityRules(signal) {
            return normalize.qualityRules((await get('review-quality-rules', {}, signal))?.data);
        },

        /**
         * Uploads an image (editor image or review photo) to POST uploads.
         *
         * @param {Blob} file
         * @param {string|null} [alt]
         * @returns {Promise<{ id: number, url: string, alt: string|null, width: number|null, height: number|null }>}
         */
        async uploadImage(file, alt = null) {
            const form = new FormData();
            form.append('image', file, typeof file?.name === 'string' ? file.name : 'image');

            if (alt) {
                form.append('alt', alt);
            }

            return normalize.upload((await request('POST', 'uploads', { form }))?.data);
        },

        /** The active sticker library for the editor. */
        async stickers(signal) {
            return list((await get('stickers', {}, signal))?.data).map(normalize.sticker);
        },

        /** Admin (rr.manage-settings): every sticker with both names. */
        async adminStickers(signal) {
            return list((await get('admin/stickers', {}, signal))?.data).map(normalize.adminSticker);
        },

        /**
         * @param {{ image?: Blob, path?: string, name: { nl: string, en: string }, isActive?: boolean, sortOrder?: number }} sticker
         */
        async createSticker(sticker) {
            const form = new FormData();

            if (sticker.image) {
                form.append('image', sticker.image, typeof sticker.image?.name === 'string' ? sticker.image.name : 'sticker');
            } else if (sticker.path) {
                form.append('path', sticker.path);
            }

            form.append('name[nl]', sticker.name.nl);
            form.append('name[en]', sticker.name.en);

            if (sticker.isActive !== undefined) {
                form.append('is_active', sticker.isActive ? '1' : '0');
            }

            if (sticker.sortOrder !== undefined) {
                form.append('sort_order', String(sticker.sortOrder));
            }

            return normalize.adminSticker((await request('POST', 'admin/stickers', { form }))?.data);
        },

        /** @param {{ name?: { nl: string, en: string }, isActive?: boolean, sortOrder?: number }} changes */
        async updateSticker(id, changes) {
            const body = {};

            if (changes.name) {
                body.name = changes.name;
            }

            if (changes.isActive !== undefined) {
                body.is_active = changes.isActive;
            }

            if (changes.sortOrder !== undefined) {
                body.sort_order = changes.sortOrder;
            }

            return normalize.adminSticker((await request('PATCH', `admin/stickers/${encodeURIComponent(id)}`, { body }))?.data);
        },

        /** Deletes a sticker; its file stays (old posts keep showing it) unless `deleteFile`. */
        async deleteSticker(id, { deleteFile = false } = {}) {
            const payload = await request('DELETE', `admin/stickers/${encodeURIComponent(id)}`, { body: deleteFile ? { delete_file: true } : {} });

            return { deleted: Boolean(payload?.data?.deleted), fileDeleted: Boolean(payload?.data?.file_deleted) };
        },
    };
}

/** @typedef {ReturnType<typeof createApiClient>} ApiClient */
