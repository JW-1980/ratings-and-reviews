import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../src/shared';
import * as normalize from '../src/shared/normalize.js';
import RrReviewCard from '../src/components/RrReviewCard.vue';
import RrReviews from '../src/components/RrReviews.vue';
import RrTopicRatings from '../src/components/RrTopicRatings.vue';
import { fakeApi, flush, mountWithRr } from './helpers';

const subject = { type: 'product', id: 7 };

function response(body: unknown) {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

const emptyPage = (meta: Record<string, unknown> = {}) => normalize.reviewPage({ data: [], meta: { total: 0, page: 1, per_page: 10, last_page: 1, sorts: [], scale_max: 5, review_form: { max_photos: 4 }, ...meta } });

describe('normalize: official replies, reply counts, filters and topic scores', () => {
    it('maps official_reply, replies_count and can.reply', () => {
        const review = normalize.review({
            id: 1,
            replies_count: 2,
            can: { edit: false, delete: false, reply: true },
            official_reply: { author: { name: 'Oatly', url: '/merken/oatly', avatar_url: null }, label: 'Merk', body: 'Dank je', body_html: '<p>Dank je</p>', replied_at: '2026-10-01T12:00:00+00:00' },
        });

        expect(review.repliesCount).toBe(2);
        expect(review.can.reply).toBe(true);
        expect(review.officialReply).toEqual({ author: { name: 'Oatly', url: '/merken/oatly', avatarUrl: null }, label: 'Merk', body: 'Dank je', bodyHtml: '<p>Dank je</p>', repliedAt: '2026-10-01T12:00:00+00:00' });
        expect(normalize.review({ id: 2, replies_count: null }).repliesCount).toBeNull();
        expect(normalize.review({ id: 3 }).officialReply).toBeNull();
    });

    it('maps the list meta for photos, replies and official replies', () => {
        const page = normalize.reviewPage({ data: [], meta: { filters: { rating: null, facets: {}, with_photos: true }, features: { review_replies: true, official_replies: true }, review_replies_section: 'review_replies' } });

        expect(page.meta.filters.withPhotos).toBe(true);
        expect(page.meta.features.reviewReplies).toBe(true);
        expect(page.meta.features.officialReplies).toBe(true);
        expect(page.meta.reviewRepliesSection).toBe('review_replies');
    });

    it('maps topic score states and builds the PUT body', () => {
        const state = normalize.topicRatingState({ enabled: true, accepts_ratings: true, topics: [{ id: 5, name: 'Smaak', scale_max: 5, count: 2, average: 3.5 }], own: { 5: 4 } });

        expect(state).toEqual({ enabled: true, acceptsRatings: true, topics: [{ id: 5, name: 'Smaak', scaleMax: 5, count: 2, average: 3.5 }], own: { 5: 4 } });
        expect(normalize.topicRatingState({ enabled: false, topics: [], own: null }).own).toBeNull();
        expect(normalize.topicRatingsPayload({ 5: 4, 6: null, 7: '' as never })).toEqual({ ratings: { 5: 4, 6: null, 7: null } });
    });
});

describe('API client', () => {
    it('sends with_photos=1 only when asked and PUTs topic scores', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response({ data: [], meta: {} }))
            .mockResolvedValueOnce(response({ data: [], meta: {} }))
            .mockResolvedValueOnce(response({ data: { enabled: true, accepts_ratings: true, topics: [], own: { 5: 3 } }, message: 'Saved' }));
        const api = createApiClient({ baseUrl: '/rr', fetch });

        await api.reviews(subject, { withPhotos: true });
        await api.reviews(subject, { withPhotos: false });
        const saved = await api.saveTopicRatings(subject, { 5: 3 });

        expect(fetch.mock.calls[0][0]).toBe('/rr/subjects/product/7/reviews?with_photos=1');
        expect(fetch.mock.calls[1][0]).toBe('/rr/subjects/product/7/reviews');
        expect(fetch.mock.calls[2][0]).toBe('/rr/subjects/product/7/topic-ratings');
        expect(fetch.mock.calls[2][1].method).toBe('PUT');
        expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ ratings: { 5: 3 } });
        expect(saved).toEqual({ state: { enabled: true, acceptsRatings: true, topics: [], own: { 5: 3 } }, message: 'Saved' });
        expect(api.reviewRepliesSubject(9)).toEqual({ type: 'rr_review', id: 9 });
    });
});

describe('<RrReviewCard>', () => {
    it('shows the official reply, and the brand-reply slot still overrides it', () => {
        const review = normalize.review({ id: 3, status: 'approved', official_reply: { author: { name: 'Oatly', url: null }, label: null, body: 'Dank je', body_html: '<p>Dank je</p>', replied_at: null } });
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api: fakeApi() } });

        expect(wrapper.find('.rr-official-reply').attributes('aria-label')).toBe('Official reply from Oatly');
        expect(wrapper.find('.rr-official-reply').text()).toContain('Dank je');
        wrapper.unmount();

        const slotted = mountWithRr(RrReviewCard, { props: { review, subject }, slots: { 'brand-reply': '<p class="host-reply">Eigen</p>' }, rr: { api: fakeApi() } }).wrapper;
        expect(slotted.find('.rr-official-reply').exists()).toBe(false);
        expect(slotted.find('.host-reply').exists()).toBe(true);
        slotted.unmount();
    });

    it('opens the replies of a review as a discussion of rr_review', async () => {
        const api = fakeApi({ comments: vi.fn(() => Promise.resolve(normalize.commentPage({ data: [{ id: 11, body_html: '<p>Eens</p>', replies: [] }], meta: { total: 1 } }))), digest: vi.fn(() => Promise.resolve(null)) });
        const review = normalize.review({ id: 3, status: 'approved', replies_count: 1, can: { reply: true } });
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api } });

        const toggle = wrapper.findAll('button').find((button) => button.text() === 'Show replies (1)');
        expect(toggle?.attributes('aria-expanded')).toBe('false');
        await toggle!.trigger('click');
        await flush();
        await flush();

        expect(api.comments).toHaveBeenCalledWith({ type: 'rr_review', id: 3 }, expect.anything(), expect.anything());
        expect(wrapper.find('.rr-review__replies').text()).toContain('Eens');
        expect(toggle!.attributes('aria-expanded')).toBe('true');
        wrapper.unmount();
    });

    it('offers no replies when they are switched off', () => {
        const review = normalize.review({ id: 3, status: 'approved', replies_count: null });
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api: fakeApi() } });

        expect(wrapper.findAll('button').some((button) => button.text().includes('repl'))).toBe(false);
        wrapper.unmount();
    });
});

describe('<RrReviews> photo filter', () => {
    it('reloads with withPhotos when the box is ticked', async () => {
        const api = fakeApi({ reviews: vi.fn(() => Promise.resolve(emptyPage())) });
        const { wrapper } = mountWithRr(RrReviews, { props: { subject }, rr: { api } });
        await flush();

        await wrapper.find('input[type="checkbox"]').setValue(true);
        await flush();

        expect(api.reviews).toHaveBeenLastCalledWith(subject, expect.objectContaining({ withPhotos: true }), expect.anything());
        wrapper.unmount();
    });
});

describe('<RrTopicRatings>', () => {
    const state = { enabled: true, acceptsRatings: true, topics: [{ id: 5, name: 'Smaak', scaleMax: 5, count: 1, average: 3 }], own: {} as Record<string, number> };

    it('saves only the changed scores', async () => {
        const api = fakeApi({
            topicRatings: vi.fn(() => Promise.resolve(state)),
            saveTopicRatings: vi.fn(() => Promise.resolve({ state: { ...state, own: { 5: 4 } }, message: 'Your scores have been saved.' })),
        });
        const { wrapper } = mountWithRr(RrTopicRatings, { props: { subject }, rr: { api } });
        await flush();

        expect(wrapper.text()).toContain('3.0 out of 5 (1 score)');
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper.find('input[value="4"]').setValue(true);
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(api.saveTopicRatings).toHaveBeenCalledWith(subject, { 5: 4 });
        expect(wrapper.emitted('saved')?.[0]?.[0]).toMatchObject({ own: { 5: 4 } });
        wrapper.unmount();
    });

    it('renders nothing while the section has topic scores off, and a login link for guests', async () => {
        const off = mountWithRr(RrTopicRatings, { props: { subject }, rr: { api: fakeApi({ topicRatings: vi.fn(() => Promise.resolve({ ...state, enabled: false })) }) } }).wrapper;
        await flush();
        expect(off.find('.rr-topic-ratings').exists()).toBe(false);
        off.unmount();

        const guest = mountWithRr(RrTopicRatings, { props: { subject }, rr: { api: fakeApi({ topicRatings: vi.fn(() => Promise.resolve({ ...state, own: null })) }), isAuthenticated: false, loginUrl: '/login' } }).wrapper;
        await flush();
        expect(guest.find('a[href="/login"]').text()).toBe('Log in to give scores.');
        expect(guest.find('input[type="radio"]').exists()).toBe(false);
        guest.unmount();
    });
});
