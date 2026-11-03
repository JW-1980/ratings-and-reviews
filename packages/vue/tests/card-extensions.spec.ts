import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, type PropType } from 'vue';
import RrDiscussion from '../src/components/RrDiscussion.vue';
import RrReviewCard from '../src/components/RrReviewCard.vue';
import RrReviews from '../src/components/RrReviews.vue';
import { normalizeCardExtensions } from '../src/context';
import * as normalize from '../src/shared/normalize.js';
import type { Comment, Review } from '../src/types';
import { fakeApi, flush, mountWithRr } from './helpers';

/**
 * Host extension points on the Vue cards: named slots (scoped with the item) on RrReviewCard
 * and RrComment, passed through RrReviews, RrDiscussion and the reply tree, and the plugin
 * registry `cardExtensions` with its once-per-page preload.
 */
const subject = { type: 'product', id: 7 };

async function settle() {
    for (let i = 0; i < 6; i++) {
        await flush();
    }
}

function reviewPage(items: unknown[], meta: Record<string, unknown> = {}) {
    return normalize.reviewPage({ data: items, meta: { total: items.length, page: 1, per_page: 10, last_page: 1, sorts: [], scale_max: 5, ...meta } });
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

const rawReview = (id: number, extra: Record<string, unknown> = {}) => ({ id, rating: 4, scale_max: 5, status: 'approved', title: `Review ${id}`, body_html: '<p>Lekker</p>', helpful_count: 1, ...extra });
const rawComment = (id: number, extra: Record<string, unknown> = {}) => ({ id, status: 'approved', visibility: 'public', depth: 0, body_html: `<p>Comment ${id}</p>`, author: { id: 1, name: 'Anna' }, ...extra });

/** A host reactions bar: reads a store the preload fills (the pattern the README shows). */
function reactionsFor(store: Record<number, number>) {
    return defineComponent({
        name: 'HostReactions',
        props: { item: { type: Object as PropType<Review | Comment>, required: true }, position: { type: String, required: true } },
        setup(props) {
            return () => h('button', { type: 'button', class: 'host-reactions', 'data-position': props.position }, `👍 ${store[props.item.id] ?? '…'}`);
        },
    });
}

describe('card extension slots', () => {
    it('renders the review card meta and footer slots scoped with the review', async () => {
        const review = normalize.review(rawReview(3));
        const { wrapper } = mountWithRr(RrReviewCard, {
            props: { review, subject },
            slots: {
                meta: ({ review: slotted, item }: { review: Review; item: Review }) => h('span', { class: 'host-meta' }, `${slotted.id}/${item.title}`),
                footer: ({ item }: { item: Review }) => h('button', { class: 'host-kudos' }, `Kudos ${item.id}`),
            },
            rr: { api: fakeApi() },
        });

        const meta = wrapper.get('.rr-review__meta [data-rr-extension="review.meta"]');
        expect(meta.element.tagName).toBe('SPAN');
        expect(meta.attributes()).toMatchObject({ class: 'rr-extensions rr-extensions--meta', 'data-rr-item-type': 'review', 'data-rr-item-id': '3' });
        expect(meta.text()).toBe('3/Review 3');
        const footer = wrapper.get('.rr-review__footer [data-rr-extension="review.footer"]');
        expect(footer.element.tagName).toBe('DIV');
        expect(footer.text()).toBe('Kudos 3');
        wrapper.unmount();
    });

    it('renders no wrapper without a slot or a registered extension', () => {
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review: normalize.review(rawReview(3)), subject }, rr: { api: fakeApi() } });

        expect(wrapper.find('[data-rr-extension]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('passes the review slots through RrReviews to every card, the own review included', async () => {
        const api = fakeApi({ reviews: vi.fn(() => Promise.resolve(reviewPage([rawReview(1), rawReview(2)], { own_review: rawReview(9, { is_own: true }) }))) });
        const { wrapper } = mountWithRr(RrReviews, {
            props: { subject },
            slots: { 'review-footer': ({ review }: { review: Review }) => h('span', { class: 'host-kudos' }, `kudos-${review.id}`) },
            rr: { api },
        });
        await settle();

        expect(wrapper.findAll('[data-rr-extension="review.footer"] .host-kudos').map((node) => node.text())).toEqual(['kudos-9', 'kudos-1', 'kudos-2']);
        wrapper.unmount();
    });

    it('passes the comment slots through RrDiscussion to replies at every depth, scoped with the comment', async () => {
        const tree = [rawComment(1, { replies: [rawComment(2, { depth: 1, parent_id: 1, replies: [rawComment(3, { depth: 2, parent_id: 2 })] })] })];
        const api = fakeApi({ comments: vi.fn(() => Promise.resolve(commentPage(tree))), digest: vi.fn(() => Promise.resolve(null)) });
        const { wrapper } = mountWithRr(RrDiscussion, {
            props: { subject, searchable: false },
            slots: {
                'comment-footer': ({ comment }: { comment: Comment }) => h('button', { class: 'host-react' }, `react-${comment.id}`),
                'comment-meta': ({ item }: { item: Comment }) => h('span', { class: 'host-depth' }, `d${item.depth}`),
            },
            rr: { api },
        });
        await settle();

        expect(wrapper.findAll('[data-rr-extension="comment.footer"]').map((node) => [node.attributes('data-rr-item-id'), node.text()])).toEqual([['1', 'react-1'], ['2', 'react-2'], ['3', 'react-3']]);
        expect(wrapper.findAll('.rr-comment__meta .host-depth').map((node) => node.text())).toEqual(['d0', 'd1', 'd2']);
        // The footer extension sits inside the comment's own footer, not the reply's.
        expect(wrapper.get('[data-rr-comment="2"] > .rr-comment__content > .rr-comment__footer [data-rr-extension]').attributes('data-rr-item-id')).toBe('2');
        wrapper.unmount();
    });
});

describe('cardExtensions plugin registry', () => {
    it('normalises components and definitions per position and rejects an unknown position', () => {
        const Bar = defineComponent({ name: 'Bar', render: () => h('i') });
        const preload = () => undefined;
        const registry = normalizeCardExtensions({ 'review.footer': Bar, 'comment.footer': [{ component: Bar, preload }, Bar] });

        expect(registry['review.footer']).toHaveLength(1);
        expect(registry['review.footer'][0].component).toBe(Bar);
        expect(registry['review.footer'][0].preload).toBeUndefined();
        expect(registry['comment.footer'].map((entry) => entry.preload)).toEqual([preload, undefined]);
        expect(registry['review.meta']).toEqual([]);
        expect(() => normalizeCardExtensions({ 'review.sidebar': Bar } as never)).toThrow(/Unknown card extension position "review.sidebar"/);
    });

    it('renders registered components on review cards and preloads once per loaded page', async () => {
        const store = reactive<Record<number, number>>({});
        const preload = vi.fn((reviews: Review[], _position?: string) => {
            reviews.forEach((review) => (store[review.id] = review.id * 10));
        });
        const pages = [
            reviewPage([rawReview(1), rawReview(2)], { last_page: 2, total: 3, own_review: rawReview(9, { is_own: true }) }),
            reviewPage([rawReview(2), rawReview(3)], { page: 2, last_page: 2, total: 3, own_review: rawReview(9, { is_own: true }) }),
        ];
        const api = fakeApi({ reviews: vi.fn(() => Promise.resolve(pages.shift())) });
        const { wrapper } = mountWithRr(RrReviews, { props: { subject }, rr: { api, cardExtensions: { 'review.footer': { component: reactionsFor(store), preload } } } });
        await settle();

        expect(preload).toHaveBeenCalledTimes(1);
        expect(preload.mock.calls[0][0].map((review: Review) => review.id)).toEqual([9, 1, 2]);
        expect(preload.mock.calls[0][1]).toBe('review.footer');
        expect(wrapper.findAll('.host-reactions').map((node) => node.text())).toEqual(['👍 90', '👍 10', '👍 20']);
        expect(wrapper.get('.host-reactions').attributes('data-position')).toBe('review.footer');

        await wrapper.get('.rr-more button').trigger('click');
        await settle();

        // The second page: once more, only with the review that was not loaded yet.
        expect(preload).toHaveBeenCalledTimes(2);
        expect(preload.mock.calls[1][0].map((review: Review) => review.id)).toEqual([3]);
        expect(wrapper.findAll('.host-reactions').map((node) => node.text())).toEqual(['👍 90', '👍 10', '👍 20', '👍 30']);
        wrapper.unmount();
    });

    it('lets a card slot replace the registered components on that card', async () => {
        const Registered = defineComponent({ name: 'Registered', render: () => h('span', { class: 'registered' }, 'registry') });
        const { wrapper } = mountWithRr(RrReviewCard, {
            props: { review: normalize.review(rawReview(4)), subject },
            slots: { footer: () => h('span', { class: 'slotted' }, 'slot') },
            rr: { api: fakeApi(), cardExtensions: { 'review.footer': Registered, 'review.meta': Registered } },
        });

        expect(wrapper.get('[data-rr-extension="review.footer"]').text()).toBe('slot');
        expect(wrapper.get('[data-rr-extension="review.meta"]').text()).toBe('registry');
        wrapper.unmount();
    });

    it('preloads comments once per batch: the first page with replies, more replies, and a new comment; placeholders left out', async () => {
        const store = reactive<Record<number, number>>({});
        const preload = vi.fn((comments: Comment[]) => comments.forEach((comment) => (store[comment.id] = comment.id)));
        const tree = [
            rawComment(1, { replies: [rawComment(2, { depth: 1, parent_id: 1, root_id: 1 })] }),
            rawComment(5, { is_removed: true, body_html: '', author: null, replies: [rawComment(6, { depth: 1, parent_id: 5, root_id: 5 })] }),
        ];
        const api = fakeApi({
            comments: vi.fn(() => Promise.resolve(commentPage(tree, { truncated_threads: { 1: 3 } }))),
            digest: vi.fn(() => Promise.resolve(null)),
            replies: vi.fn(() => Promise.resolve(normalize.replyPage({ data: [rawComment(2, { depth: 1, parent_id: 1, root_id: 1 }), rawComment(4, { depth: 1, parent_id: 1, root_id: 1 })], meta: { root_id: 1, total: 3, has_more: false } }))),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api, cardExtensions: { 'comment.footer': [{ component: reactionsFor(store), preload }] } } });
        await settle();

        expect(preload).toHaveBeenCalledTimes(1);
        expect(preload.mock.calls[0][0].map((comment: Comment) => comment.id)).toEqual([1, 2, 6]);
        expect(wrapper.find('[data-rr-comment="5"] [data-rr-extension]').exists()).toBe(false);
        expect(wrapper.get('[data-rr-comment="6"] [data-rr-extension="comment.footer"]').text()).toBe('👍 6');

        await wrapper.get('.rr-thread__truncated button').trigger('click');
        await settle();

        expect(preload).toHaveBeenCalledTimes(2);
        expect(preload.mock.calls[1][0].map((comment: Comment) => comment.id)).toEqual([4]);
        expect(wrapper.get('[data-rr-comment="4"] [data-rr-extension="comment.footer"]').text()).toBe('👍 4');
        wrapper.unmount();
    });

    it('preloads and renders a comment posted in the composer (a client-rendered item)', async () => {
        const store = reactive<Record<number, number>>({});
        const preload = vi.fn((comments: Comment[]) => comments.forEach((comment) => (store[comment.id] = 42)));
        const api = fakeApi({
            comments: vi.fn(() => Promise.resolve(commentPage([rawComment(1)]))),
            digest: vi.fn(() => Promise.resolve(null)),
            createComment: vi.fn(() => Promise.resolve({ item: normalize.comment(rawComment(8, { is_own: true })), message: 'Posted', quality: null })),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api, cardExtensions: { 'comment.footer': { component: reactionsFor(store), preload } } } });
        await settle();

        const editor = wrapper.findComponent({ name: 'RrEditor' });
        (editor.vm as unknown as { setContent(html: string): void }).setContent('<p>Nieuwe reactie</p>');
        await settle();
        await wrapper.find('form.rr-composer').trigger('submit');
        await settle();

        expect(api.createComment).toHaveBeenCalledOnce();
        expect(preload).toHaveBeenCalledTimes(2);
        expect(preload.mock.calls[1][0].map((comment: Comment) => comment.id)).toEqual([8]);
        expect(wrapper.get('[data-rr-comment="8"] [data-rr-extension="comment.footer"]').text()).toBe('👍 42');
        wrapper.unmount();
    });

    it('keeps the list working when a preload throws or rejects', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const Bar = defineComponent({ name: 'Bar', props: { item: { type: Object, required: true } }, render: () => h('span', { class: 'bar' }, 'bar') });
        const api = fakeApi({ reviews: vi.fn(() => Promise.resolve(reviewPage([rawReview(1)]))) });
        const { wrapper } = mountWithRr(RrReviews, {
            props: { subject },
            rr: { api, cardExtensions: { 'review.footer': [{ component: Bar, preload: () => { throw new Error('sync'); } }], 'review.meta': [{ component: Bar, preload: () => Promise.reject(new Error('async')) }] } },
        });
        await settle();

        expect(wrapper.findAll('.bar')).toHaveLength(2);
        expect(error).toHaveBeenCalledTimes(2);
        error.mockRestore();
        wrapper.unmount();
    });

    it('passes the comment slots of RrReviews on to the replies of a review', async () => {
        const api = fakeApi({
            reviews: vi.fn(() => Promise.resolve(reviewPage([rawReview(1, { replies_count: 1, can: { reply: true } })]))),
            comments: vi.fn(() => Promise.resolve(commentPage([rawComment(11)]))),
            digest: vi.fn(() => Promise.resolve(null)),
        });
        const { wrapper } = mountWithRr(RrReviews, {
            props: { subject },
            slots: { 'comment-footer': ({ comment }: { comment: Comment }) => h('span', { class: 'reply-kudos' }, `reply-${comment.id}`) },
            rr: { api },
        });
        await settle();

        await wrapper.get('.rr-review__actions button[aria-expanded]').trigger('click');
        await settle();

        expect(api.comments).toHaveBeenCalledWith({ type: 'rr_review', id: 1 }, expect.anything(), expect.anything());
        expect(wrapper.get('.rr-review__replies .reply-kudos').text()).toBe('reply-11');
        wrapper.unmount();
    });
});
