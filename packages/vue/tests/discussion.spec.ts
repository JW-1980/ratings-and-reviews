import { describe, expect, it } from 'vitest';
import RrDiscussion from '../src/components/RrDiscussion.vue';
import * as normalize from '../src/shared/normalize.js';
import { fakeApi, flush, mountWithRr } from './helpers';

const subject = { type: 'product', id: 1 };

function page(items: unknown[] = [], meta: Record<string, unknown> = {}) {
    return normalize.commentPage({
        data: items,
        meta: {
            total: items.length, page: 1, per_page: 20, has_more: false, sort: 'helpful',
            sorts: [{ value: 'helpful', label: 'Most helpful' }, { value: 'controversial', label: 'Controversial' }],
            accepts_comments: true,
            settings: { editor_tools: ['bold'], vote_dimensions: ['agreement'], min_length: 2, max_length: 500, undo_window_seconds: 10, tone_check: false, polls: { enabled: false } },
            trust: { locked: [] }, discussion: { locked: false, slow_mode_seconds: 0, seconds_until_next_post: 0 },
            new_since: { count: 0 },
            ...meta,
        },
    });
}

async function settle() {
    for (let i = 0; i < 5; i++) {
        await flush();
    }
}

describe('<RrDiscussion>', () => {
    it('posts, offers undo while the comment is scheduled, and puts the text back after undo', async () => {
        const publishAt = new Date(Date.now() + 10_000).toISOString();
        const api = fakeApi({
            comments: () => Promise.resolve(page()),
            digest: () => Promise.resolve(null),
            createComment: () => Promise.resolve({ item: normalize.comment({ id: 7, status: 'approved', visibility: 'scheduled', publish_at: publishAt, body_html: '<p>Hallo daar</p>', is_own: true, can: { undo: true } }), message: 'Scheduled', quality: null }),
            undoComment: () => Promise.resolve(),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        const editor = wrapper.findComponent({ name: 'RrEditor' });
        (editor.vm as unknown as { setContent(html: string): void }).setContent('<p>Hallo daar</p>');
        await settle();
        await wrapper.find('form.rr-composer').trigger('submit');
        await settle();

        expect(api.createComment).toHaveBeenCalledOnce();
        expect(wrapper.find('[data-rr-comment="7"]').exists()).toBe(true);
        const toast = wrapper.find('.rr-toast');
        expect(toast.exists()).toBe(true);
        expect(toast.text()).toMatch(/Your comment will be posted in (9|10) seconds\./);
        expect(document.activeElement).toBe(toast.find('button').element);

        await toast.find('button').trigger('click');
        await settle();

        expect(api.undoComment).toHaveBeenCalledWith(7);
        expect(wrapper.find('[data-rr-comment="7"]').exists()).toBe(false);
        expect(wrapper.find('.rr-toast').exists()).toBe(false);
        const html = (wrapper.findComponent({ name: 'RrEditor' }).vm as unknown as { editor(): { getHTML(): string } }).editor().getHTML();
        expect(html).toBe('<p>Hallo daar</p>');
        wrapper.unmount();
    });

    it('shows slow mode, the lock and the "new since" jump from the meta', async () => {
        const api = fakeApi({
            comments: () => Promise.resolve(page([{ id: 3, is_new: true, body_html: '<p>Nieuw</p>', status: 'approved', author: { id: 1, name: 'A' } }], {
                accepts_comments: false,
                discussion: { locked: true, slow_mode_seconds: 120, seconds_until_next_post: 0 },
                new_since: { count: 1, first_new_id: 3 },
            })),
            digest: () => Promise.resolve(null),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        expect(wrapper.text()).toContain('This discussion is closed.');
        expect(wrapper.text()).toContain('Slow mode: you can comment here once per 2 minutes.');
        expect(wrapper.find('form.rr-composer').exists()).toBe(false);
        const jump = wrapper.find('.rr-new-since button');
        expect(jump.text()).toBe('1 new comment, jump to it');
        await jump.trigger('click');
        await settle();
        expect(document.activeElement?.getAttribute('data-rr-comment')).toBe('3');
        wrapper.unmount();
    });

    it('puts a selected quote into the reply composer', async () => {
        const api = fakeApi({
            comments: () => Promise.resolve(page([{ id: 3, body_html: '<p>Romig en niet te zoet.</p>', body_text: 'Romig en niet te zoet.', status: 'approved', author: { id: 1, name: 'A' }, can: { reply: true } }], {
                settings: { editor_tools: ['bold', 'quote'], vote_dimensions: ['agreement'], min_length: 2, max_length: 500, undo_window_seconds: 10, tone_check: false, polls: { enabled: false } },
            })),
            digest: () => Promise.resolve(null),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        const body = wrapper.find('[data-rr-comment="3"] [data-rr-quote-source]').element;
        const text = body.querySelector('p')!.firstChild!;
        const range = document.createRange();
        range.selectNodeContents(text);
        const selection = window.getSelection()!;
        selection.removeAllRanges();
        selection.addRange(range);

        await wrapper.find('.rr-discussion__thread').trigger('mouseup');
        const offer = wrapper.find('.rr-quote-offer');
        expect(offer.exists()).toBe(true);

        await offer.trigger('click');
        await settle();

        const editors = wrapper.findAllComponents({ name: 'RrEditor' });
        const html = editors.map((editor) => (editor.vm as unknown as { editor(): { getHTML(): string } }).editor().getHTML());
        expect(html.some((value) => value.includes('<blockquote'))).toBe(true);
        expect(html.some((value) => value.includes('Romig en niet te zoet.'))).toBe(true);
        wrapper.unmount();
    });

    it('shows the digest only when the server says it is available', async () => {
        const api = fakeApi({
            comments: () => Promise.resolve(page()),
            digest: () => Promise.resolve(normalize.digest({ available: true, total_comments: 31, agreement: { agree: 30, disagree: 10, agree_share: 0.75 }, most_agreed: [{ id: 1, excerpt: 'Te zoet.' }], most_disagreed: [], most_helpful: null })),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        expect(wrapper.find('.rr-digest').text()).toContain('75% agree, 25% disagree');
        expect(wrapper.find('.rr-digest').text()).toContain('Te zoet.');
        wrapper.unmount();
    });
});
