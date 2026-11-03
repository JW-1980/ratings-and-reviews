import { describe, expect, it } from 'vitest';
import RrComposer from '../src/components/RrComposer.vue';
import { ApiError } from '../src/shared';
import { fakeApi, flush, mountWithRr } from './helpers';
import type { DiscussionSettings } from '../src/types';

const settings: DiscussionSettings = { maxDepth: 3, editorTools: ['bold'], voteDimensions: ['agreement'], guestVotes: false, minLength: 5, maxLength: 200, editWindowMinutes: 30, undoWindowSeconds: 10, maxQuoteLength: 500, toneCheck: true, polls: { enabled: true, minOptions: 2, maxOptions: 4 } };
const subject = { type: 'product', id: 1 };
const posted = { item: { id: 50, replies: [], publishAt: null }, message: 'Your comment was posted.', quality: null };

async function type(wrapper: ReturnType<typeof mountWithRr>['wrapper'], text: string) {
    const editor = wrapper.findComponent({ name: 'RrEditor' });
    (editor.vm as unknown as { setContent(html: string): void }).setContent(`<p>${text}</p>`);
    await flush();
}

describe('<RrComposer>', () => {
    it('refuses text below the section minimum without calling the API', async () => {
        const api = fakeApi();
        const { wrapper } = mountWithRr(RrComposer, { props: { subject, settings }, rr: { api } });
        await flush();
        await type(wrapper, 'abc');

        await wrapper.find('form').trigger('submit');
        await flush();
        expect(wrapper.find('[role="alert"]').text()).toBe('Write at least 5 characters.');
        expect(api.createComment).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('asks once when the tone check suggests rephrasing, and posts with confirm_tone on "Post anyway"', async () => {
        const api = fakeApi({
            toneCheck: () => Promise.resolve({ enabled: true, suggestion: 'That sounds harsh.', matches: ['idiot'] }),
            createComment: () => Promise.resolve(posted),
        });
        const { wrapper } = mountWithRr(RrComposer, { props: { subject, settings }, rr: { api } });
        await flush();
        await type(wrapper, 'What an idiot idea');

        await wrapper.find('form').trigger('submit');
        await flush();
        const dialog = wrapper.find('[role="alertdialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.text()).toContain('That sounds harsh.');
        expect(api.createComment).not.toHaveBeenCalled();

        await dialog.findAll('button').find((button) => button.text() === 'Post anyway')!.trigger('click');
        await flush();
        expect(api.createComment).toHaveBeenCalledOnce();
        expect(api.createComment.mock.calls[0][1]).toMatchObject({ body: '<p>What an idiot idea</p>', confirmTone: true, parentId: null });
        expect(wrapper.emitted('posted')![0][0]).toMatchObject({ item: { id: 50 }, bodyHtml: '<p>What an idiot idea</p>' });
        wrapper.unmount();
    });

    it('shows the server nudge (422 tone_nudge) when the pre-check was unavailable', async () => {
        const api = fakeApi({
            toneCheck: () => Promise.reject(new Error('offline')),
            createComment: () => Promise.reject(new ApiError('Rephrase?', { status: 422, errors: { confirm_tone: ['Rephrase?'] }, data: { tone_nudge: { message: 'Rephrase?', matches: [] } } })),
        });
        const { wrapper } = mountWithRr(RrComposer, { props: { subject, settings }, rr: { api } });
        await flush();
        await type(wrapper, 'Something rude here');

        await wrapper.find('form').trigger('submit');
        await flush();
        await flush();
        expect(wrapper.find('[role="alertdialog"]').text()).toContain('Rephrase?');
        wrapper.unmount();
    });

    it('validates a poll before posting and sends it with the comment', async () => {
        const api = fakeApi({ createComment: () => Promise.resolve(posted) });
        const { wrapper } = mountWithRr(RrComposer, { props: { subject, settings: { ...settings, toneCheck: false } }, rr: { api } });
        await flush();
        await type(wrapper, 'Which one do you prefer?');

        await wrapper.findAll('button').find((button) => button.text() === 'Add a poll')!.trigger('click');
        await wrapper.find('form').trigger('submit');
        await flush();
        expect(wrapper.text()).toContain('Enter a question.');
        expect(api.createComment).not.toHaveBeenCalled();

        const inputs = wrapper.findAll('.rr-poll-builder input');
        await inputs[0].setValue('Warm or cold?');
        await inputs[1].setValue('Warm');
        await inputs[2].setValue('Cold');
        await wrapper.find('form').trigger('submit');
        await flush();
        expect(api.createComment.mock.calls[0][1].poll).toEqual({ question: 'Warm or cold?', options: ['Warm', 'Cold'] });
        wrapper.unmount();
    });

    it('blocks posting while slow mode is counting down', async () => {
        const api = fakeApi();
        const { wrapper } = mountWithRr(RrComposer, { props: { subject, settings, waitSeconds: 30 }, rr: { api } });
        await flush();

        expect(wrapper.text()).toContain('You can comment again in 30 seconds.');
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });
});
