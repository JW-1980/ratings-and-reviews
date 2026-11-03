import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../src/shared';
import * as normalize from '../src/shared/normalize.js';
import RrVoteButtons from '../src/components/RrVoteButtons.vue';
import { flush, mountWithRr } from './helpers';

/**
 * The exact bodies VoteResult::toArray() sends (asserted on the PHP side in
 * VoteResponseShapeTest): `count` for the voted dimension, `counts_by_dimension` for all of
 * them and `counts` with the raw counter columns. The old client read `counts.up`, which the
 * server never sent, and showed 0 after every vote.
 */
const commentVote = {
    dimension: 'agreement',
    value: -1,
    counted: true,
    count: { up: 2, down: 3 },
    counts_by_dimension: {
        agreement: { up: 2, down: 3 },
        helpfulness: { up: 4, down: 1 },
        needs_source: { up: 1, down: 0 },
    },
    counts: { agree_count: 2, disagree_count: 3, helpful_count: 4, unhelpful_count: 1, needs_source_count: 1 },
};

const reviewVote = {
    dimension: 'helpfulness',
    value: 1,
    counted: true,
    count: { up: 6, down: 0 },
    counts_by_dimension: { helpfulness: { up: 6, down: 0 } },
    counts: { helpful_count: 6 },
};

function response(body: unknown) {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

describe('vote responses', () => {
    it('maps the voted dimension and every other dimension of a comment', () => {
        const result = normalize.voteResult(commentVote);

        expect(result).toEqual({
            dimension: 'agreement',
            value: -1,
            counted: true,
            counts: { up: 2, down: 3 },
            allCounts: { agreement: { up: 2, down: 3 }, helpfulness: { up: 4, down: 1 }, needs_source: { up: 1, down: 0 } },
        });
    });

    it('maps a review helpful vote and a withdrawn vote', () => {
        expect(normalize.voteResult(reviewVote).counts).toEqual({ up: 6, down: 0 });
        expect(normalize.voteResult({ ...reviewVote, value: null, count: { up: 5, down: 0 } })).toMatchObject({ value: 0, counts: { up: 5, down: 0 } });
    });

    it.each([
        ['agreement', { up: 2, down: 3 }],
        ['helpfulness', { up: 4, down: 1 }],
        ['needs_source', { up: 1, down: 0 }],
    ])('reads %s from the raw counter columns of a server without `count`', (dimension, expected) => {
        const legacy = { dimension, value: 1, counted: true, counts: commentVote.counts };
        const result = normalize.voteResult(legacy);

        expect(result.counts).toEqual(expected);
        expect(result.allCounts).toEqual(commentVote.counts_by_dimension);
    });

    it('reads a review helpful vote from the raw counter column too', () => {
        expect(normalize.voteResult({ dimension: 'helpfulness', value: 1, counted: true, counts: { helpful_count: 6 } }).counts).toEqual({ up: 6, down: 0 });
    });

    it('keeps an uncounted vote uncounted', () => {
        expect(normalize.voteResult({ ...commentVote, counted: false }).counted).toBe(false);
    });

    it('shows the server count in <RrVoteButtons> through the real API client, for every dimension', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ data: commentVote }));
        const api = createApiClient({ baseUrl: '/rr', fetch });
        const { wrapper } = mountWithRr(RrVoteButtons, {
            props: {
                target: 'comment',
                targetId: 9,
                dimensions: ['agreement', 'helpfulness', 'needs_source'],
                counts: { agreement: { up: 2, down: 2 }, helpfulness: { up: 0, down: 0 }, needs_source: { up: 0, down: 0 } },
                viewerVotes: {},
            },
            rr: { api },
        });
        const count = (dimension: string, value: number) => wrapper.find(`button[data-dimension="${dimension}"][data-value="${value}"] .rr-vote__count`).text();

        await wrapper.find('button[data-dimension="agreement"][data-value="-1"]').trigger('click');
        await flush();
        await flush();

        expect(fetch).toHaveBeenCalledOnce();
        expect([count('agreement', 1), count('agreement', -1)]).toEqual(['2', '3']);
        // Counted by others meanwhile: the other dimensions follow the server too.
        expect([count('helpfulness', 1), count('helpfulness', -1), count('needs_source', 1)]).toEqual(['4', '1', '1']);
        expect(wrapper.find('button[data-dimension="agreement"][data-value="-1"]').attributes('aria-pressed')).toBe('true');
        wrapper.unmount();
    });

    it('shows the server count of a review helpful vote', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ data: reviewVote }));
        const api = createApiClient({ baseUrl: '/rr', fetch });
        const { wrapper } = mountWithRr(RrVoteButtons, {
            props: { target: 'review', targetId: 4, dimensions: ['helpfulness'], counts: { helpfulness: { up: 5, down: 0 } }, viewerVotes: {}, positiveOnly: true },
            rr: { api },
        });

        await wrapper.find('button[data-dimension="helpfulness"][data-value="1"]').trigger('click');
        await flush();
        await flush();

        expect(fetch.mock.calls[0][0]).toBe('/rr/reviews/4/votes');
        expect(wrapper.find('.rr-vote__count').text()).toBe('6');
        wrapper.unmount();
    });
});
