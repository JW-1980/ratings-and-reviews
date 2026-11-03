import { describe, expect, it } from 'vitest';
import * as normalize from '../src/shared/normalize.js';
import RrReviewCard from '../src/components/RrReviewCard.vue';
import { fakeApi, mountWithRr } from './helpers';

const subject = { type: 'product', id: 7 };
const base = { id: 4, status: 'approved', rating: 4, scale_max: 5, can: { vote: false } };

describe('uncounted reviews', () => {
    it('maps the ReviewResource uncounted fields, and nothing when the viewer may not see them', () => {
        expect(normalize.review({ ...base, uncounted_reason: 'sample_campaign', uncounted_label: 'Received from the brand', uncounted_public: true }).uncounted)
            .toEqual({ reason: 'sample_campaign', label: 'Received from the brand', isPublic: true });
        expect(normalize.review({ ...base, uncounted_reason: null, uncounted_label: null, uncounted_public: false }).uncounted).toBeNull();
        expect(normalize.review(base).uncounted).toBeNull();
    });

    it('shows a public reason as a tag on the card', () => {
        const review = normalize.review({ ...base, uncounted_reason: 'sample_campaign', uncounted_label: 'Received from the brand', uncounted_public: true });
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api: fakeApi() } });

        const tag = wrapper.find('.rr-tag--uncounted');
        expect(tag.attributes('data-reason')).toBe('sample_campaign');
        expect(tag.text()).toContain('Received from the brand');
        expect(tag.text()).toContain('Not counted in the score');
        expect(wrapper.find('[data-rr-uncounted]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('tells the author, and a moderator, why a review does not count', () => {
        const raw = { ...base, uncounted_reason: 'device_cluster', uncounted_label: 'No reason given', uncounted_public: false };
        const own = mountWithRr(RrReviewCard, { props: { review: normalize.review({ ...raw, is_own: true }), subject }, rr: { api: fakeApi() } }).wrapper;
        expect(own.find('[data-rr-uncounted]').text()).toBe('Your review is not counted in the score: No reason given.');
        expect(own.find('.rr-tag--uncounted').exists()).toBe(false);
        own.unmount();

        const moderator = mountWithRr(RrReviewCard, { props: { review: normalize.review(raw), subject }, rr: { api: fakeApi() } }).wrapper;
        expect(moderator.find('[data-rr-uncounted]').text()).toBe('Not counted in the score: No reason given.');
        moderator.unmount();
    });
});
