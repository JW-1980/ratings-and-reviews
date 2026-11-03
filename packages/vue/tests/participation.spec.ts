import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../src/shared';
import * as normalize from '../src/shared/normalize.js';
import RrDiscussion from '../src/components/RrDiscussion.vue';
import RrRatingSummary from '../src/components/RrRatingSummary.vue';
import RrReviewCard from '../src/components/RrReviewCard.vue';
import RrReviewForm from '../src/components/RrReviewForm.vue';
import { fakeApi, flush, mountWithRr } from './helpers';

const subject = { type: 'product', id: 7 };

async function settle() {
    for (let i = 0; i < 5; i++) {
        await flush();
    }
}

function response(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function commentPage(items: unknown[], meta: Record<string, unknown> = {}) {
    return normalize.commentPage({
        data: items,
        meta: {
            total: items.length, page: 1, per_page: 20, has_more: false, sort: 'helpful', sorts: [], accepts_comments: true,
            settings: { editor_tools: ['bold'], vote_dimensions: ['agreement'], min_length: 2, max_length: 500, undo_window_seconds: 0, tone_check: false, polls: { enabled: false } },
            trust: { locked: [] }, discussion: { locked: false, slow_mode_seconds: 0, seconds_until_next_post: 0 }, new_since: { count: 0 },
            ...meta,
        },
    });
}

const question = (overrides: Record<string, unknown> = {}) => ({ id: 1, key: 'sweet', type: 'scale', label: 'Sweetness', low_label: 'Not sweet', high_label: 'Very sweet', scale_max: 5, required: false, options: [], ...overrides });

describe('normalize: attribution, pins, blocks, questions and follow-ups', () => {
    it('maps the new review fields', () => {
        const review = normalize.review({
            id: 1,
            is_own: true,
            is_blocked: false,
            attribution: { key: 'brand:1', label: 'Oatly', url: null },
            answers: [{ key: 'moments', label: 'When?', type: 'multi', value: ['breakfast'], display: 'Breakfast', scale_max: null }],
            follow_up: { rating: 2, scale_max: 5, initial_rating: 4, initial_scale_max: 5, body: 'Minder', created_at: '2026-11-01T00:00:00+00:00', days_after: 31 },
            follow_up_window: { opens_at: '2026-10-01T00:00:00+00:00', closes_at: '2026-12-01T00:00:00+00:00' },
            can: { edit: true, delete: true, reply: false, vote: false, follow_up: false },
        });

        expect(review.attribution).toEqual({ key: 'brand:1', label: 'Oatly', url: null });
        expect(review.answers).toEqual([{ key: 'moments', label: 'When?', type: 'multi', value: ['breakfast'], display: 'Breakfast', scaleMax: null }]);
        expect(review.followUp).toEqual({ rating: 2, scaleMax: 5, initialRating: 4, initialScaleMax: 5, body: 'Minder', createdAt: '2026-11-01T00:00:00+00:00', daysAfter: 31 });
        expect(review.followUpWindow?.opensAt).toBe('2026-10-01T00:00:00+00:00');
        expect(review.can).toEqual({ edit: true, delete: true, reply: false, vote: false, followUp: false });
        expect(normalize.review({ id: 2 }).attribution).toBeNull();
        expect(normalize.review({ id: 2 }).followUp).toBeNull();
    });

    it('maps the comment and list meta', () => {
        const comment = normalize.comment({ id: 3, is_pinned: true, is_blocked: true, attribution: { key: 'shop:1', label: 'Bakker', url: '/b' }, can: { pin: true, vote: false } });
        expect(comment.isPinned).toBe(true);
        expect(comment.isBlocked).toBe(true);
        expect(comment.can.pin).toBe(true);
        expect(comment.can.vote).toBe(false);
        expect(comment.attribution?.label).toBe('Bakker');

        const page = commentPage([], { pinned_comment_id: 3, can_participate: false, can_pin: true, attributions: [{ key: 'brand:1', label: 'Oatly', url: null }], subject: { type: 'group_post', id: 4, title: 'Post', url: '/p', context_url: '/g' } });
        expect(page.meta.pinnedCommentId).toBe(3);
        expect(page.meta.canParticipate).toBe(false);
        expect(page.meta.attributions).toHaveLength(1);
        expect(page.meta.subject?.contextUrl).toBe('/g');
        expect(commentPage([]).meta.canParticipate).toBeNull();

        const reviews = normalize.reviewPage({ data: [], meta: { reviews_enabled: false, can_participate: true, review_questions: [question()], review_follow_up: { after_days: 30, window_days: 60, max_body_length: 2000 } } });
        expect(reviews.meta.reviewsEnabled).toBe(false);
        expect(reviews.meta.questions[0]).toMatchObject({ key: 'sweet', lowLabel: 'Not sweet', scaleMax: 5 });
        expect(reviews.meta.followUp).toEqual({ afterDays: 30, windowDays: 60, maxBodyLength: 2000 });
    });

    it('maps the summary questions and follow-ups and builds the payloads', () => {
        const summary = normalize.ratingSummary({
            scale_max: 5, count: 3,
            questions: [{ ...question(), count: 2, average: 3.5, distribution: [{ value: 1, count: 0 }] }, { ...question({ key: 'when', type: 'multi', options: [{ value: 'lunch', label: 'Lunch', count: 1, share: 0.5 }] }), count: 2 }],
            follow_ups: { enabled: true, count: 3, min_for_change: 3, average_before: 4, average_after: 3, average_change: -1 },
            min_reviews_for_average: 3,
        });

        expect(summary.questions[0]).toMatchObject({ key: 'sweet', count: 2, average: 3.5 });
        expect(summary.questions[1].options).toEqual([{ value: 'lunch', label: 'Lunch', count: 1, share: 0.5 }]);
        expect(summary.followUps).toMatchObject({ count: 3, averageChange: -1 });
        expect(summary.minReviewsForAverage).toBe(3);

        expect(normalize.answersPayload({ sweet: '4', when: ['lunch'], again: 'yes', empty: null, none: [] })).toEqual({ sweet: 4, when: ['lunch'], again: 'yes' });
        expect(normalize.reviewPayload({ rating: 4, answers: { sweet: 3 }, attribution: '' })).toMatchObject({ answers: { sweet: 3 }, attribution: null });
        expect('answers' in normalize.reviewPayload({ rating: 4 })).toBe(false);
        expect(normalize.commentPayload({ body: '<p>x</p>', attribution: 'brand:1' }).attribution).toBe('brand:1');
        expect(normalize.followUpPayload({ rating: 2, body: '  ' })).toEqual({ rating: 2 });
    });
});

describe('API client', () => {
    it('posts follow-ups and pins', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response({ data: { id: 5, follow_up: { rating: 2 } }, message: 'Saved' }, 201))
            .mockResolvedValueOnce(response({ data: { id: 9, is_pinned: true }, message: 'Pinned' }))
            .mockResolvedValueOnce(response({ data: { id: 9, is_pinned: false }, message: 'Unpinned' }));
        const api = createApiClient({ baseUrl: '/rr', fetch });

        const saved = await api.createFollowUp(5, { rating: 2, body: 'Minder lekker' });
        expect(fetch.mock.calls[0][0]).toBe('/rr/reviews/5/follow-up');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ rating: 2, body: 'Minder lekker' });
        expect(saved.item.followUp?.rating).toBe(2);

        expect(await api.pinComment(9)).toEqual({ isPinned: true, message: 'Pinned' });
        expect(fetch.mock.calls[1][1].method).toBe('POST');
        expect(await api.pinComment(9, false)).toEqual({ isPinned: false, message: 'Unpinned' });
        expect(fetch.mock.calls[2][1].method).toBe('DELETE');
    });
});

describe('<RrReviewCard>', () => {
    it('shows the attribution, the answers, the follow-up and the blocked state', async () => {
        const review = normalize.review({
            id: 4, status: 'approved', rating: 4, scale_max: 5, is_blocked: true,
            attribution: { key: 'brand:1', label: 'Oatly', url: null },
            answers: [{ key: 'sweet', label: 'Sweetness', type: 'scale', value: 4, display: '4', scale_max: 5 }],
            follow_up: { rating: 2, scale_max: 5, initial_rating: 4, initial_scale_max: 5, body: 'Minder lekker', created_at: null, days_after: 31 },
            can: { vote: false },
        });
        const api = fakeApi();
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api } });

        expect(wrapper.find('.rr-tag--attribution').text()).toContain('Oatly');
        expect(wrapper.find('.rr-review__answers').text()).toContain('Sweetness');
        expect(wrapper.find('.rr-review__answers').text()).toContain('4 out of 5');
        expect(wrapper.find('.rr-follow-up').text()).toContain('After 31 days');
        expect(wrapper.find('.rr-follow-up').text()).toContain('Was 4 out of 5, now 2 out of 5');
        expect(wrapper.text()).toContain('You and this member blocked each other');

        await wrapper.find('.rr-vote').trigger('click');
        expect(api.voteReview).not.toHaveBeenCalled();
        expect(wrapper.find('.rr-votes__note').text()).toBe("You can't vote on this member's contributions.");
        wrapper.unmount();
    });

    it('lets the author add a follow-up while the window is open', async () => {
        const review = normalize.review({ id: 6, status: 'approved', rating: 4, scale_max: 5, is_own: true, can: { follow_up: true } });
        const updated = normalize.review({ id: 6, status: 'approved', rating: 4, scale_max: 5, is_own: true, follow_up: { rating: 3, scale_max: 5, initial_rating: 4, initial_scale_max: 5, body: null, days_after: 30 } });
        const api = fakeApi({ createFollowUp: () => Promise.resolve({ item: updated, message: 'Thanks', quality: null }) });
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api } });

        await wrapper.find('.rr-follow-up--form button').trigger('click');
        await wrapper.find('.rr-follow-up--form form').trigger('submit');
        expect(api.createFollowUp).not.toHaveBeenCalled();
        expect(wrapper.find('.rr-follow-up--form .rr-field-error').text()).toBe('Choose a score.');

        await wrapper.findAll('.rr-follow-up--form input[type="radio"]')[2].setValue(true);
        await wrapper.find('.rr-follow-up--form textarea').setValue('Iets minder');
        await wrapper.find('.rr-follow-up--form form').trigger('submit');
        await settle();

        expect(api.createFollowUp).toHaveBeenCalledWith(6, { rating: 3, body: 'Iets minder' });
        expect(wrapper.emitted('updated')?.[0]?.[0]).toMatchObject({ id: 6, followUp: { rating: 3 } });
        wrapper.unmount();
    });
});

describe('<RrDiscussion>', () => {
    it('shows the pinned comment, lets a moderator unpin it and offers the attributions', async () => {
        const pinned = { id: 1, status: 'approved', body_html: '<p>Officieel</p>', is_pinned: true, attribution: { key: 'brand:1', label: 'Oatly', url: null }, can: { pin: true, reply: true }, counts: { agreement: { up: 0, down: 0 } } };
        const api = fakeApi({
            comments: vi.fn()
                .mockResolvedValueOnce(commentPage([pinned], { attributions: [{ key: 'brand:1', label: 'Oatly', url: null }] }))
                .mockResolvedValueOnce(commentPage([{ ...pinned, is_pinned: false }])),
            digest: () => Promise.resolve(null),
            pinComment: () => Promise.resolve({ isPinned: false, message: null }),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        expect(wrapper.find('.rr-comment.is-pinned .rr-tag--pinned').text()).toBe('Pinned');
        expect(wrapper.find('.rr-tag--attribution').text()).toContain('Oatly');
        expect(wrapper.find('.rr-composer__attribution select').findAll('option').map((option) => option.text())).toEqual(['Myself', 'Oatly']);

        const unpin = wrapper.findAll('button').find((button) => button.text() === 'Unpin');
        await unpin?.trigger('click');
        await settle();

        expect(api.pinComment).toHaveBeenCalledWith(1, false);
        expect(api.comments).toHaveBeenCalledTimes(2);
        expect(wrapper.find('.rr-comment.is-pinned').exists()).toBe(false);
        wrapper.unmount();
    });

    it('tells a member the participation rules keep out instead of offering the composer', async () => {
        const api = fakeApi({ comments: () => Promise.resolve(commentPage([], { accepts_comments: false, can_participate: false })), digest: () => Promise.resolve(null) });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        expect(wrapper.text()).toContain('Only participants can comment here.');
        expect(wrapper.find('form.rr-composer').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('<RrReviewForm>', () => {
    it('asks the review questions and sends the answers and the attribution', async () => {
        const meta = normalize.reviewPage({ data: [], meta: {
            review_questions: [question(), question({ id: 2, key: 'when', type: 'multi', label: 'When?', low_label: null, high_label: null, scale_max: null, options: [{ value: 'breakfast', label: 'Breakfast' }, { value: 'lunch', label: 'Lunch' }] })],
            attributions: [{ key: 'shop:2', label: 'Bakkerij', url: null }],
        } }).meta;
        const api = fakeApi({ qualityRules: () => Promise.resolve(null), createReview: () => Promise.resolve({ item: normalize.review({ id: 1 }), message: null, quality: null }) });
        const { wrapper } = mountWithRr(RrReviewForm, { props: { subject, meta }, rr: { api } });
        await settle();

        expect(wrapper.find('.rr-questions').text()).toContain('Very sweet');
        await wrapper.findAll('.rr-scale input[type="radio"]')[3].setValue(true);
        const sweet = wrapper.findAll('.rr-question--scale input[type="radio"]');
        await sweet[5].setValue(true);
        await wrapper.findAll('.rr-question--multi input[type="checkbox"]')[1].setValue(true);
        await wrapper.find('select').setValue('shop:2');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(api.createReview).toHaveBeenCalledOnce();
        const payload = api.createReview.mock.calls[0][1];
        expect(payload.answers).toEqual({ sweet: 5, when: ['lunch'] });
        expect(payload.attribution).toBe('shop:2');
        wrapper.unmount();
    });

    it('shows a notice instead of the form when reviews are off', async () => {
        const meta = normalize.reviewPage({ data: [], meta: { reviews_enabled: false } }).meta;
        const { wrapper } = mountWithRr(RrReviewForm, { props: { subject, meta }, rr: { api: fakeApi({ qualityRules: () => Promise.resolve(null) }) } });
        await settle();

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.text()).toContain('Written reviews are not used here.');
        wrapper.unmount();
    });
});

describe('<RrRatingSummary>', () => {
    it('shows the answers per question and the change after follow-ups', async () => {
        const summary = normalize.ratingSummary({
            scale_max: 5, count: 3, average: 4, histogram: {}, topics: [],
            questions: [{ ...question(), count: 3, average: 3.7, distribution: [] }, { ...question({ key: 'when', type: 'multi', label: 'When?', options: [{ value: 'lunch', label: 'Lunch', count: 2, share: 0.6667 }] }), count: 3 }],
            follow_ups: { enabled: true, count: 3, min_for_change: 3, average_before: 4, average_after: 3, average_change: -1 },
        });
        const { wrapper } = mountWithRr(RrRatingSummary, { props: { subject }, rr: { api: fakeApi({ ratingSummary: () => Promise.resolve(summary) }) } });
        await settle();

        expect(wrapper.find('.rr-summary__questions').text()).toContain('Sweetness');
        expect(wrapper.find('.rr-summary__questions').text()).toContain('3.7 out of 5');
        expect(wrapper.find('.rr-summary__questions').text()).toContain('Lunch: 67% (2)');
        expect(wrapper.find('.rr-summary__follow-up-change').attributes('data-direction')).toBe('down');
        wrapper.unmount();
    });
});
