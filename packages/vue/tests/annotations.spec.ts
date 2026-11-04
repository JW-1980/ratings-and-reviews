import { describe, expect, it } from 'vitest';
import RrAnnotations from '../src/components/RrAnnotations.vue';
import RrAttributionBadge from '../src/components/RrAttributionBadge.vue';
import RrDiscussion from '../src/components/RrDiscussion.vue';
import RrReviewCard from '../src/components/RrReviewCard.vue';
import * as normalize from '../src/shared/normalize.js';
import { fakeApi, flush, mountWithRr } from './helpers';

/**
 * Host marks in the card header: ContentAnnotator `annotations` and the author's
 * `extra.badges` (normalisation, the badge row, tone cues beyond colour, safe links).
 */
const subject = { type: 'product', id: 7 };
const claim = { key: 'health_claim', label: 'Gezondheidsclaim', tone: 'warning', url: 'https://example.test/claims' };
const rawReview = (extra: Record<string, unknown> = {}) => ({ id: 3, rating: 4, scale_max: 5, status: 'approved', title: 'Lekker', body_html: '<p>x</p>', helpful_count: 0, ...extra });

describe('annotation normalisation', () => {
    it('renders an attribution link without hiding it from assistive tech', () => {
        const { wrapper } = mountWithRr(RrAttributionBadge, {
            props: { attribution: { key: 'brand:1', label: 'Bakkerij Jansen', url: 'https://example.test/brand' } },
            rr: { api: fakeApi() },
        });
        const link = wrapper.find('a');

        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('https://example.test/brand');
        expect(link.element.closest('[aria-hidden="true"]')).toBeNull();
        expect(wrapper.text()).toContain('Bakkerij Jansen');
        wrapper.unmount();
    });

    it('keeps a valid annotation and defaults the tone', () => {
        expect(normalize.annotation(claim)).toEqual(claim);
        expect(normalize.annotation({ label: 'Hi', tone: 'danger' })).toEqual({ key: 'badge', label: 'Hi', tone: 'info', url: null });
        expect(normalize.annotation('Betrouwbaar')).toEqual({ key: 'badge', label: 'Betrouwbaar', tone: 'info', url: null });
    });

    it('drops entries without a label and links that are not http(s) or site-relative', () => {
        expect(normalize.annotation({ label: '  ' })).toBeNull();
        expect(normalize.annotation(null)).toBeNull();
        expect(normalize.annotation(42)).toBeNull();
        expect(normalize.annotation({ label: 'x', url: 'javascript:alert(1)' })?.url).toBeNull();
        expect(normalize.annotation({ label: 'x', url: '//evil.test/x' })?.url).toBeNull();
        expect(normalize.annotation({ label: 'x', url: '/claims' })?.url).toBe('/claims');
    });

    it('reads annotations on reviews and comments and badges on authors', () => {
        expect(normalize.review(rawReview({ annotations: [claim, { label: '' }] })).annotations).toEqual([claim]);
        expect(normalize.review(rawReview()).annotations).toEqual([]);
        expect(normalize.comment({ id: 1, status: 'approved', annotations: [claim] }).annotations).toEqual([claim]);
        expect(normalize.author({ id: 1, name: 'Anna', extra: { badges: ['Top', { label: 'Betrouwbaar', tone: 'success' }] } })?.cardBadges.map((badge: { label: string }) => badge.label)).toEqual(['Top', 'Betrouwbaar']);
        expect(normalize.author({ id: 1, name: 'Anna' })?.cardBadges).toEqual([]);
    });
});

describe('RrAnnotations', () => {
    it('renders author badges before annotations with tone cues that do not rely on colour', () => {
        const author = normalize.author({ id: 1, name: 'Anna', extra: { badges: [{ key: 'reliable', label: 'Betrouwbare reviewer', tone: 'success' }] } });
        const { wrapper } = mountWithRr(RrAnnotations, { props: { annotations: [normalize.annotation(claim)], author }, rr: { api: fakeApi() } });

        const marks = wrapper.findAll('[data-rr-annotation]');
        expect(marks.map((mark) => mark.attributes('data-rr-annotation'))).toEqual(['reliable', 'health_claim']);
        expect(marks.map((mark) => mark.attributes('data-rr-annotation-source'))).toEqual(['author', 'content']);
        expect(marks[1].attributes('data-tone')).toBe('warning');
        expect(marks[1].find('.rr-visually-hidden').text()).toBe('Warning:');
        expect(marks[1].find('.rr-tag__icon').attributes('aria-hidden')).toBe('true');
        expect(marks[1].find('a').attributes('href')).toBe('https://example.test/claims');
        expect(marks[0].find('a').exists()).toBe(false);
        expect(wrapper.find('ul').attributes('aria-label')).toBe('Notes');
    });

    it('renders nothing without marks', () => {
        const { wrapper } = mountWithRr(RrAnnotations, { props: { annotations: [], author: null }, rr: { api: fakeApi() } });

        expect(wrapper.find('.rr-annotations').exists()).toBe(false);
    });

    it('uses the Dutch words in a Dutch page', () => {
        const { wrapper } = mountWithRr(RrAnnotations, { props: { annotations: [normalize.annotation(claim)] }, rr: { api: fakeApi(), locale: 'nl' } });

        expect(wrapper.find('.rr-visually-hidden').text()).toBe('Waarschuwing:');
        expect(wrapper.find('ul').attributes('aria-label')).toBe('Notities');
    });
});

describe('cards', () => {
    it('shows the badge row in the review card header', () => {
        const review = normalize.review(rawReview({ author: { id: 1, name: 'Anna', extra: { badges: ['Top'] } }, annotations: [claim] }));
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api: fakeApi() } });

        expect(wrapper.find('.rr-review__header .rr-annotations').findAll('li')).toHaveLength(2);
    });

    it('shows the badge row on a comment and on its reply', async () => {
        const api = fakeApi({
            comments: () => Promise.resolve(normalize.commentPage({
                data: [{ id: 1, status: 'approved', visibility: 'public', depth: 0, body_html: '<p>a</p>', author: { id: 1, name: 'Anna' }, annotations: [claim], replies: [
                    { id: 2, status: 'approved', visibility: 'public', depth: 1, body_html: '<p>b</p>', author: { id: 2, name: 'Bram', extra: { badges: ['Fan'] } }, annotations: [] },
                ] }],
                meta: {
                    total: 1, page: 1, per_page: 20, has_more: false, sort: 'helpful', sorts: [], accepts_comments: true,
                    settings: { editor_tools: ['bold'], vote_dimensions: ['agreement'], min_length: 2, max_length: 500, undo_window_seconds: 0, tone_check: false, polls: { enabled: false } },
                    trust: { locked: [] }, discussion: { locked: false, slow_mode_seconds: 0, seconds_until_next_post: 0 }, new_since: { count: 0 },
                },
            })),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject }, rr: { api } });
        for (let i = 0; i < 6; i++) {
            await flush();
        }

        expect(wrapper.find('[data-rr-comment="1"] .rr-comment__header [data-rr-annotation="health_claim"]').exists()).toBe(true);
        expect(wrapper.find('[data-rr-comment="2"] .rr-comment__header [data-rr-annotation-source="author"]').text()).toContain('Fan');
    });
});
