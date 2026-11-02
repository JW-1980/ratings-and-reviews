import { describe, expect, it, vi } from 'vitest';
import RrDiscussion from '../src/components/RrDiscussion.vue';
import RrEditor from '../src/components/RrEditor.vue';
import RrReviewCard from '../src/components/RrReviewCard.vue';
import RrReviewForm from '../src/components/RrReviewForm.vue';
import { lastShownReply, mergeReplies } from '../src/composables/discussion';
import { ApiError, createApiClient } from '../src/shared';
import * as normalize from '../src/shared/normalize.js';
import { fakeApi, flush, mountWithRr } from './helpers';
import type { Comment, ReviewPage } from '../src/types';

const subject = { type: 'product', id: 1 };

function response(status: number, body: unknown, headers: Record<string, string> = {}) {
    return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

async function settle() {
    for (let i = 0; i < 6; i++) {
        await flush();
    }
}

function reviewMeta(meta: Record<string, unknown> = {}): ReviewPage['meta'] {
    return normalize.reviewPage({ data: [], meta: { total: 0, page: 1, per_page: 10, last_page: 1, scale_max: 5, ...meta } }).meta as ReviewPage['meta'];
}

function file(name = 'a.jpg') {
    return new File([new Uint8Array([255, 216, 255])], name, { type: 'image/jpeg' });
}

describe('API client: uploads, replies and stickers', () => {
    it('uploads an image as multipart with the CSRF token and no JSON content type', async () => {
        const fetch = vi.fn().mockResolvedValue(response(201, { data: { id: 9, url: '/storage/rr-uploads/x.jpg', alt: null, width: 80, height: 60 } }));
        const api = createApiClient({ fetch, csrfToken: 'tok' });

        const upload = await api.uploadImage(file('photo.jpg'), 'Havermelk');

        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/rr/uploads');
        expect(init.method).toBe('POST');
        expect(init.body).toBeInstanceOf(FormData);
        expect((init.body as FormData).get('image')).toBeInstanceOf(File);
        expect((init.body as FormData).get('alt')).toBe('Havermelk');
        expect(init.headers['Content-Type']).toBeUndefined();
        expect(init.headers['X-CSRF-TOKEN']).toBe('tok');
        expect(upload).toEqual({ id: 9, url: '/storage/rr-uploads/x.jpg', alt: null, width: 80, height: 60 });
    });

    it('keeps the server message and Retry-After of the daily quota', async () => {
        const fetch = vi.fn().mockResolvedValue(response(429, { message: 'You have reached the limit of 50 images per day.' }, { 'Retry-After': '7200' }));
        const failure = await createApiClient({ fetch }).uploadImage(file()).catch((error) => error);

        expect(failure).toBeInstanceOf(ApiError);
        expect(failure.isThrottled).toBe(true);
        expect(failure.retryAfter).toBe(7200);
        expect(failure.message).toContain('50 images per day');
    });

    it('asks for the rest of a thread after the last reply shown, then by cursor', async () => {
        const fetch = vi.fn((_url: RequestInfo | URL, _init?: RequestInit) => Promise.resolve(response(200, { data: [{ id: 5, parent_id: 1, depth: 1 }], meta: { root_id: 1, total: 4, next_cursor: 'abc', has_more: true } })));
        const api = createApiClient({ fetch });

        const page = await api.replies(1, { after: 3 });
        expect(fetch.mock.calls[0][0]).toBe('/rr/comments/1/replies?after=3');
        expect(page).toMatchObject({ rootId: 1, total: 4, nextCursor: 'abc', hasMore: true });
        expect(page.items[0]).toMatchObject({ id: 5, parentId: 1, depth: 1, replies: [] });

        await api.replies(1, { cursor: 'abc', after: 3, limit: 50 });
        expect(fetch.mock.calls[1][0]).toBe('/rr/comments/1/replies?cursor=abc&limit=50');
    });

    it('reads the sticker library and manages stickers for the admin', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response(200, { data: [{ id: 1, name: 'Blij', url: '/storage/rr-stickers/a.png' }] }))
            .mockResolvedValueOnce(response(201, { data: { id: 2, name: { nl: 'Knipoog', en: 'Wink' }, label: 'Wink', path: 's/w.gif', url: '/storage/s/w.gif', managed_file: false, is_active: true, sort_order: 3 } }))
            .mockResolvedValueOnce(response(200, { data: { id: 2, deleted: true, file_deleted: false } }));
        const api = createApiClient({ fetch });

        expect(await api.stickers()).toEqual([{ id: 1, name: 'Blij', url: '/storage/rr-stickers/a.png' }]);
        const created = await api.createSticker({ path: 's/w.gif', name: { nl: 'Knipoog', en: 'Wink' }, sortOrder: 3 });
        expect(created).toMatchObject({ id: 2, managedFile: false, isActive: true, sortOrder: 3, name: { nl: 'Knipoog', en: 'Wink' } });
        const form = fetch.mock.calls[1][1].body as FormData;
        expect([form.get('path'), form.get('name[nl]'), form.get('name[en]'), form.get('sort_order')]).toEqual(['s/w.gif', 'Knipoog', 'Wink', '3']);
        expect(await api.deleteSticker(2)).toEqual({ deleted: true, fileDeleted: false });
        expect(fetch.mock.calls[2][0]).toBe('/rr/admin/stickers/2');
    });
});

describe('normalize: photos and the review form meta', () => {
    it('maps photos and the section form settings', () => {
        const review = normalize.review({ id: 1, photos: [{ id: 4, url: '/storage/p.jpg', alt: 'Photo 1 with the review "Romig"', width: 80, height: 60 }] });
        expect(review.photos).toEqual([{ id: 4, url: '/storage/p.jpg', alt: 'Photo 1 with the review "Romig"', width: 80, height: 60 }]);

        const meta = reviewMeta({ review_scale: 10, review_extended_fields: false, review_min_length: 20, review_form: { max_photos: 3, max_title_length: 90, max_pros_cons: 4, max_pro_con_length: 100, photos_require_approval: true } });
        expect(meta).toMatchObject({ reviewScale: 10, extendedFields: false, minLength: 20, form: { maxPhotos: 3, maxTitleLength: 90, maxProsCons: 4, maxProConLength: 100, photosRequireApproval: true } });

        // An older server without these keys: extended fields on, no photos.
        expect(reviewMeta()).toMatchObject({ extendedFields: true, minLength: 0, form: { maxPhotos: 0 } });
    });

    it('sends photos only when the draft has them, as positive integers', () => {
        expect(normalize.reviewPayload({ rating: 4 })).not.toHaveProperty('photos');
        expect(normalize.reviewPayload({ rating: 4, photos: [3, '7', 'x', 0] }).photos).toEqual([3, 7]);
        expect(normalize.reviewPayload({ rating: 4, photos: [] }).photos).toEqual([]);
    });
});

describe('thread merging', () => {
    const reply = (id: number, parentId: number, depth: number, minute: number): Comment => normalize.comment({ id, parent_id: parentId, root_id: 1, depth, created_at: `2026-11-02T10:0${minute}:00+00:00` }) as Comment;

    it('places flat replies under their parents, oldest first, and skips orphans and duplicates', () => {
        const root = { ...(normalize.comment({ id: 1, depth: 0 }) as Comment), replies: [{ ...reply(2, 1, 1, 1), replies: [reply(4, 2, 2, 3)] }] };

        const merged = mergeReplies(root, [reply(3, 1, 1, 2), reply(5, 2, 2, 4), reply(4, 2, 2, 3), reply(9, 99, 3, 5)]);

        expect(merged.replies.map((item) => item.id)).toEqual([2, 3]);
        expect(merged.replies[0].replies.map((item) => item.id)).toEqual([4, 5]);
        expect(JSON.stringify(merged)).not.toContain('"id":9');
        expect(lastShownReply(merged)?.id).toBe(5);
        expect(lastShownReply(root)?.id).toBe(4);
    });
});

describe('<RrDiscussion> show more replies', () => {
    it('loads the rest of a cut thread page by page and drops the button when it is complete', async () => {
        const replyRaw = (id: number, parent: number, depth: number, minute: number) => ({ id, parent_id: parent, root_id: 1, depth, status: 'approved', body_html: `<p>Antwoord ${id}</p>`, created_at: `2026-11-02T10:0${minute}:00+00:00`, can: { reply: true } });
        const api = fakeApi({
            comments: () => Promise.resolve(normalize.commentPage({
                data: [{ id: 1, depth: 0, status: 'approved', body_html: '<p>Start</p>', replies: [replyRaw(2, 1, 1, 1)], can: { reply: true } }],
                meta: { total: 1, page: 1, per_page: 20, truncated_threads: { 1: 3 }, accepts_comments: true, settings: { editor_tools: ['bold'], vote_dimensions: [] }, trust: { locked: [] }, discussion: {}, new_since: {} },
            })),
            digest: () => Promise.resolve(null),
            replies: vi.fn()
                .mockResolvedValueOnce(normalize.replyPage({ data: [replyRaw(3, 1, 1, 2)], meta: { root_id: 1, total: 3, next_cursor: 'next', has_more: true } }))
                .mockResolvedValueOnce(normalize.replyPage({ data: [replyRaw(4, 3, 2, 3)], meta: { root_id: 1, total: 3, next_cursor: null, has_more: false } })),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();

        expect(wrapper.find('.rr-thread__truncated').text()).toContain('1 of 3 replies shown');
        await wrapper.find('.rr-thread__truncated button').trigger('click');
        await settle();

        expect(api.replies).toHaveBeenNthCalledWith(1, 1, { after: 2 });
        expect(wrapper.find('[data-rr-comment="3"]').exists()).toBe(true);
        expect(wrapper.find('.rr-thread__truncated').text()).toContain('2 of 3 replies shown');

        await wrapper.find('.rr-thread__truncated button').trigger('click');
        await settle();

        expect(api.replies).toHaveBeenNthCalledWith(2, 1, { cursor: 'next' });
        // Reply 4 sits under reply 3, not under the root.
        expect(wrapper.find('[data-rr-comment="3"]').element.closest('li')?.querySelector('[data-rr-comment="4"]')).not.toBeNull();
        expect(wrapper.find('.rr-thread__truncated').exists()).toBe(false);
        wrapper.unmount();
    });

    it('shows an error and keeps the button when loading fails', async () => {
        const api = fakeApi({
            comments: () => Promise.resolve(normalize.commentPage({ data: [{ id: 1, depth: 0, status: 'approved', replies: [] }], meta: { total: 1, truncated_threads: { 1: 5 }, settings: {}, trust: { locked: [] }, discussion: {}, new_since: {} } })),
            digest: () => Promise.resolve(null),
            replies: () => Promise.reject(new ApiError('Gone', { status: 0 })),
        });
        const { wrapper } = mountWithRr(RrDiscussion, { props: { subject, searchable: false }, rr: { api } });
        await settle();
        await wrapper.find('.rr-thread__truncated button').trigger('click');
        await settle();

        expect(wrapper.find('.rr-thread__truncated [role="alert"]').text()).toBe('No connection. Check your internet and try again.');
        expect(wrapper.find('.rr-thread__truncated button').exists()).toBe(true);
        wrapper.unmount();
    });
});

describe('<RrReviewForm> follows the section', () => {
    it('hides title, pros and cons without extended fields and shows the minimum length', async () => {
        const api = fakeApi({ qualityRules: () => Promise.resolve(null) });
        const { wrapper } = mountWithRr(RrReviewForm, { props: { subject, meta: reviewMeta({ review_extended_fields: false, review_min_length: 30 }) }, rr: { api } });
        await settle();

        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
        expect(wrapper.find('.rr-points-inputs').exists()).toBe(false);
        expect(wrapper.text()).toContain('Write at least 30 characters.');
        expect(wrapper.find('.rr-photo-input').exists()).toBe(false);
        wrapper.unmount();
    });

    it('an explicit prop still wins over the meta', async () => {
        const api = fakeApi({ qualityRules: () => Promise.resolve(null) });
        const { wrapper } = mountWithRr(RrReviewForm, { props: { subject, scale: 10, extendedFields: true, meta: reviewMeta({ review_extended_fields: false }) }, rr: { api } });
        await settle();

        expect(wrapper.find('.rr-points-inputs').exists()).toBe(true);
        expect(wrapper.findAll('input[type="radio"]').length).toBe(10);
        wrapper.unmount();
    });

    it('uploads photos at once, lets the author remove one and sends the ids in order', async () => {
        let id = 20;
        const api = fakeApi({
            qualityRules: () => Promise.resolve(null),
            uploadImage: vi.fn(() => Promise.resolve({ id: ++id, url: `/storage/p${id}.jpg`, alt: null, width: 10, height: 10 })),
            createReview: vi.fn(() => Promise.resolve({ item: normalize.review({ id: 1 }), message: 'Thanks', quality: null })),
        });
        const { wrapper } = mountWithRr(RrReviewForm, { props: { subject, meta: reviewMeta({ review_form: { max_photos: 2 } }) }, rr: { api } });
        await settle();

        const input = wrapper.find('.rr-photo-input input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file('a.jpg'), file('b.jpg'), file('c.jpg')], configurable: true });
        await input.trigger('change');
        await settle();

        expect(api.uploadImage).toHaveBeenCalledTimes(2);
        expect(wrapper.findAll('.rr-photo-input img').map((img) => img.attributes('src'))).toEqual(['/storage/p21.jpg', '/storage/p22.jpg']);
        expect(wrapper.find('.rr-photo-input [role="alert"]').text()).toBe('You can add up to 2 photos.');
        expect(wrapper.find('.rr-photo-input input[type="file"]').exists()).toBe(false);

        await wrapper.find('.rr-photo-input button[aria-label="Remove photo 1"]').trigger('click');
        await wrapper.find('.rr-scale input[value="4"]').setValue(true);
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(api.createReview).toHaveBeenCalledOnce();
        expect((api.createReview as ReturnType<typeof vi.fn>).mock.calls[0][1].photos).toEqual([22]);
        wrapper.unmount();
    });

    it('shows the server message when an upload is refused', async () => {
        const api = fakeApi({
            qualityRules: () => Promise.resolve(null),
            uploadImage: () => Promise.reject(new ApiError('The given data was invalid.', { status: 422, errors: { image: ['The image must be at most 5120 kilobytes.'] } })),
        });
        const { wrapper } = mountWithRr(RrReviewForm, { props: { subject, meta: reviewMeta({ review_form: { max_photos: 2 } }) }, rr: { api } });
        await settle();
        const input = wrapper.find('.rr-photo-input input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file()], configurable: true });
        await input.trigger('change');
        await settle();

        expect(wrapper.find('.rr-photo-input [role="alert"]').text()).toBe('The image must be at most 5120 kilobytes.');
        expect(wrapper.findAll('.rr-photo-input img')).toHaveLength(0);
        wrapper.unmount();
    });
});

describe('<RrReviewCard> photos', () => {
    it('renders accessible thumbnails with the alt text from the API', () => {
        const review = normalize.review({ id: 3, rating: 4, status: 'approved', photos: [{ id: 1, url: '/storage/a.jpg', alt: 'Photo 1 with the review "Romig"', width: 80, height: 60 }] });
        const { wrapper } = mountWithRr(RrReviewCard, { props: { review, subject }, rr: { api: fakeApi() } });

        const img = wrapper.find('.rr-photos img');
        expect(wrapper.find('.rr-photos').attributes('aria-label')).toBe('Photos');
        expect(img.attributes('alt')).toBe('Photo 1 with the review "Romig"');
        expect([img.attributes('width'), img.attributes('height')]).toEqual(['80', '60']);
        expect(wrapper.find('.rr-photos a').attributes('href')).toBe('/storage/a.jpg');
        wrapper.unmount();
    });
});

describe('<RrEditor> defaults to the package API', () => {
    it('uploads editor images through POST uploads when the host gives no uploadImage', async () => {
        const api = fakeApi({ uploadImage: vi.fn(() => Promise.resolve({ id: 1, url: '/storage/rr-uploads/x.jpg', alt: null, width: 1, height: 1 })) });
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '', tools: ['image'], label: 'Text' }, rr: { api } });
        await settle();

        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file()], configurable: true });
        await input.trigger('change');
        await settle();

        expect(api.uploadImage).toHaveBeenCalledOnce();
        expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toContain('src="/storage/rr-uploads/x.jpg"');
        wrapper.unmount();
    });

    it('a host uploadImage still overrides the default', async () => {
        const uploadImage = vi.fn(() => Promise.resolve({ url: '/storage/host/y.jpg' }));
        const api = fakeApi();
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '', tools: ['image'], label: 'Text' }, rr: { api, uploadImage } });
        await settle();
        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file()], configurable: true });
        await input.trigger('change');
        await settle();

        expect(uploadImage).toHaveBeenCalledOnce();
        expect(api.uploadImage).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('loads the sticker library from the API when the picker opens and inserts a sticker', async () => {
        const api = fakeApi({ stickers: vi.fn(() => Promise.resolve([{ id: 1, name: 'Blij', url: '/storage/rr-stickers/blij.png' }])) });
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '', tools: ['sticker'], label: 'Text' }, rr: { api } });
        await settle();

        await wrapper.find('.rr-toolbar-button--sticker').trigger('click');
        await settle();
        expect(api.stickers).toHaveBeenCalledOnce();
        const choice = wrapper.find('.rr-picker--stickers button[aria-label="Blij"]');
        expect(choice.exists()).toBe(true);
        await choice.trigger('click');
        await settle();

        expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toContain('src="/storage/rr-stickers/blij.png"');

        // Opening again does not fetch again.
        await wrapper.find('.rr-toolbar-button--sticker').trigger('click');
        await settle();
        expect(api.stickers).toHaveBeenCalledOnce();
        wrapper.unmount();
    });
});
