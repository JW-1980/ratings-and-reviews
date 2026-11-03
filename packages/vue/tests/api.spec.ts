import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, createApiClient } from '../src/shared';
import * as normalize from '../src/shared/normalize.js';

function response(status: number, body: unknown, headers: Record<string, string> = {}) {
    return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

describe('API client', () => {
    afterEach(() => {
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('sends the encrypted XSRF cookie as X-XSRF-TOKEN on writes, nothing on reads', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';
        const fetch = vi.fn().mockResolvedValue(response(200, { data: { dimension: 'helpfulness', value: 1, counted: true, count: { up: 1, down: 0 }, counts_by_dimension: { helpfulness: { up: 1, down: 0 } }, counts: { helpful_count: 1 } } }));
        const api = createApiClient({ baseUrl: '/rr/', fetch });

        await api.voteReview(4, 'helpfulness', 1);
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/rr/reviews/4/votes');
        expect(init.method).toBe('POST');
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc==');
        expect(init.headers.Accept).toBe('application/json');
        expect(JSON.parse(init.body)).toEqual({ dimension: 'helpfulness', value: 1 });

        fetch.mockResolvedValue(response(200, { data: {} }));
        await api.ratingSummary({ type: 'product', id: 1 });
        expect(fetch.mock.calls[1][1].headers['X-XSRF-TOKEN']).toBeUndefined();
    });

    it('prefers an explicit csrf token as X-CSRF-TOKEN', async () => {
        const fetch = vi.fn().mockResolvedValue(response(204, null));
        await createApiClient({ fetch, csrfToken: () => 'meta-token' }).deleteComment(3);

        expect(fetch.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('meta-token');
        expect(fetch.mock.calls[0][1].method).toBe('DELETE');
    });

    it('turns 422, 429 and network failures into ApiError with details', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response(422, { message: 'Invalid', errors: { body: ['Too short'] } }))
            .mockResolvedValueOnce(response(429, { message: 'Too many' }, { 'Retry-After': '30' }))
            .mockRejectedValueOnce(new TypeError('Failed to fetch'));
        const api = createApiClient({ fetch });

        const validation = await api.createComment({ type: 'product', id: 1 }, { body: 'x' }).catch((error) => error);
        expect(validation).toBeInstanceOf(ApiError);
        expect(validation.isValidation).toBe(true);
        expect(validation.first('body')).toBe('Too short');

        const throttled = await api.createComment({ type: 'product', id: 1 }, { body: 'x' }).catch((error) => error);
        expect(throttled.isThrottled).toBe(true);
        expect(throttled.retryAfter).toBe(30);

        const offline = await api.reviews({ type: 'product', id: 1 }).catch((error) => error);
        expect(offline.isNetwork).toBe(true);
    });

    it('encodes subjects and flattens facet filters to facet[group]=value', async () => {
        const fetch = vi.fn().mockResolvedValue(response(200, { data: [], meta: {} }));
        await createApiClient({ fetch }).reviews({ type: 'product', id: 'a/b' }, { sort: 'newest', rating: 4, facets: { purchase: 'first_time', deal: '' }, page: 2 });

        const url = new URL(fetch.mock.calls[0][0], 'http://x');
        expect(url.pathname).toBe('/rr/subjects/product/a%2Fb/reviews');
        expect(url.searchParams.get('sort')).toBe('newest');
        expect(url.searchParams.get('rating')).toBe('4');
        expect(url.searchParams.get('facet[purchase]')).toBe('first_time');
        expect(url.searchParams.has('facet[deal]')).toBe(false);
        expect(url.searchParams.get('page')).toBe('2');
    });
});

describe('normalising server JSON', () => {
    it('orders the histogram highest first whatever the JSON key order', () => {
        // PHP sends {"5":…,"4":…}; JavaScript iterates integer keys ascending.
        expect(normalize.histogram({ 1: 2, 2: 0, 3: 1, 4: 5, 5: 9 }, 5)).toEqual([
            { bucket: 5, count: 9 }, { bucket: 4, count: 5 }, { bucket: 3, count: 1 }, { bucket: 2, count: 0 }, { bucket: 1, count: 2 },
        ]);
        expect(normalize.histogram({ 9: 1 }, 10)).toHaveLength(10);
    });

    it('maps the summary extensions', () => {
        const summary = normalize.ratingSummary({
            scale_max: 10, count: 12, average: 8.1, bayesian_average: 7.9, histogram: {}, topics: [],
            uncertainty: { confidence: 0.95, margin: 0.4, lower: 7.7, upper: 8.5, standard_error: 0.2, few_reviews: false, few_reviews_below: 5 },
            trend: { months: 2, points: [{ month: '2026-10', count: 2, average: 7.5 }, { month: '2026-11', count: 0, average: null }] },
            facets: [{ group: 'purchase', label: 'Purchase', values: [{ value: 'first_time', label: 'First time', count: 3 }] }],
            comparisons: { alternatives: [{ type: 'product', id: 2, title: 'B', url: '/b', better: 1, worse: 3, same: 0, total: 4 }], often_switch_to: { type: 'product', id: 2, title: 'B', url: '/b', count: 3 } },
        });

        expect(summary.uncertainty).toEqual({ confidence: 0.95, margin: 0.4, low: 7.7, high: 8.5, fewReviews: false });
        expect(summary.trend).toEqual([{ period: '2026-10', average: 7.5, count: 2 }, { period: '2026-11', average: null, count: 0 }]);
        expect(summary.facets[0].values[0]).toEqual({ value: 'first_time', label: 'First time', count: 3 });
        expect(summary.switchTo).toEqual({ subject: { type: 'product', id: 2, title: 'B', url: '/b', imageUrl: null }, count: 3 });
    });

    it('maps the comments meta (trust, slow mode, new since, polls)', () => {
        const page = normalize.commentPage({
            data: [{ id: 1, is_new: true, publish_at: '2026-11-02T12:00:10Z', visibility: 'scheduled', can: { undo: true }, quote: { comment_id: 4, root_id: 4, text: 'q', author: { id: 2, name: 'B' }, available: true }, replies: [{ id: 2 }] }],
            meta: {
                total: 1, settings: { editor_tools: ['bold'], polls: { enabled: true, min_options: 2, max_options: 4 }, tone_check: true, max_quote_length: 300 },
                trust: { approved_contributions: 1, locked: [{ key: 'link', required: 3 }], can_create_poll: false },
                discussion: { locked: false, slow_mode_seconds: 120, seconds_until_next_post: 45 },
                new_since: { last_visit_at: '2026-11-01T00:00:00Z', count: 1, first_new_id: 1, first_new_root_id: 1 },
            },
        });

        expect(page.items[0]).toMatchObject({ isNew: true, visibility: 'scheduled', can: { undo: true }, quote: { commentId: 4, text: 'q', available: true } });
        expect(page.items[0].replies[0].id).toBe(2);
        expect(page.meta.trust.locked).toEqual({ link: 3 });
        expect(page.meta.settings.polls.enabled).toBe(false);
        expect(page.meta.settings.maxQuoteLength).toBe(300);
        expect(page.meta.slowModeSeconds).toBe(120);
        expect(page.meta.secondsUntilNextPost).toBe(45);
        expect(page.meta.newSince.firstNewId).toBe(1);
    });

    it('builds write payloads in the request shape', () => {
        expect(normalize.reviewPayload({ rating: 4, title: '  ', pros: ['a', ' '], cons: [], topicRatings: { 1: 3, 2: null }, facets: { purchase: 'regular', deal: null }, comparison: { type: 'product', id: 9, verdict: 'better' }, confirmTone: true, formStartedAt: 100 })).toEqual({
            rating: 4, title: null, body: null, pros: ['a'], cons: [], topic_ratings: { 1: 3 }, facets: { purchase: 'regular' },
            confirm_tone: true, compared_type: 'product', compared_id: 9, comparison: 'better', form_started_at: 100,
        });
        expect(normalize.commentPayload({ body: '<p>x</p>', parentId: 3, quote: { commentId: 4, text: 'q' }, poll: { question: ' Q ', options: ['a', ' ', 'b'] } })).toEqual({
            body: '<p>x</p>', parent_id: 3, quoted_comment_id: 4, quote_text: 'q', poll: { question: 'Q', options: ['a', 'b'] },
        });
    });

    it('returns no digest below the threshold', () => {
        expect(normalize.digest({ available: false, threshold: 30, total_comments: 3 })).toBeNull();
        expect(normalize.digest({ available: true, total_comments: 40, agreement: { agree: 30, disagree: 10, agree_share: 0.75 }, most_agreed: [{ id: 1, excerpt: 'Yes.' }], most_disagreed: [], most_helpful: null })).toMatchObject({ total: 40, agreement: { agreeShare: 0.75 }, agreeing: [{ commentId: 1, excerpt: 'Yes.' }] });
    });
});
