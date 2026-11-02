import { describe, expect, it } from 'vitest';
import RrAuthorName from '../src/components/RrAuthorName.vue';
import RrEditor from '../src/components/RrEditor.vue';
import RrScaleInput from '../src/components/RrScaleInput.vue';
import { fakeApi, flush, mountWithRr } from './helpers';

describe('keyboard use', () => {
    it('editor toolbar is one tab stop with arrow, Home and End navigation', async () => {
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '', tools: ['bold', 'italic', 'link'], label: 'Text' }, rr: { api: fakeApi() } });
        await flush();

        const buttons = () => wrapper.findAll('.rr-toolbar-button');
        expect(buttons().map((b) => b.attributes('tabindex'))).toEqual(['0', '-1', '-1']);
        expect(wrapper.find('[role="toolbar"]').attributes('aria-label')).toBe('Formatting');

        (buttons()[0].element as HTMLElement).focus();
        await wrapper.find('[role="toolbar"]').trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement).toBe(buttons()[1].element);
        expect(buttons().map((b) => b.attributes('tabindex'))).toEqual(['-1', '0', '-1']);

        await wrapper.find('[role="toolbar"]').trigger('keydown', { key: 'End' });
        expect(document.activeElement).toBe(buttons()[2].element);
        await wrapper.find('[role="toolbar"]').trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement).toBe(buttons()[0].element);
        await wrapper.find('[role="toolbar"]').trigger('keydown', { key: 'ArrowLeft' });
        expect(document.activeElement).toBe(buttons()[2].element);
        wrapper.unmount();
    });

    it('opens the link panel from the toolbar and closes it with Escape', async () => {
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '', tools: ['link'], label: 'Text' }, rr: { api: fakeApi() } });
        await flush();

        await wrapper.find('[data-tool="link"]').trigger('click');
        expect(wrapper.find('[data-tool="link"]').attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('input[type="url"]').exists()).toBe(true);

        await wrapper.find('.rr-editor').trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('input[type="url"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('rejects an unsafe link address with an inline error', async () => {
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '<p>x</p>', tools: ['link'], label: 'Text' }, rr: { api: fakeApi() } });
        await flush();

        await wrapper.find('[data-tool="link"]').trigger('click');
        await wrapper.find('input[type="url"]').setValue('javascript:alert(1)');
        await wrapper.find('input[type="url"]').trigger('keydown', { key: 'Enter' });
        expect(wrapper.find('.rr-field-error').text()).toBe('Use an address that starts with https://, http:// or mailto:.');
        expect(wrapper.find('input[type="url"]').attributes('aria-invalid')).toBe('true');
        wrapper.unmount();
    });

    it('locked tools stay discoverable but do nothing, with the unlock threshold as name', async () => {
        const { wrapper } = mountWithRr(RrEditor, { props: { modelValue: '', tools: ['bold', 'link'], lockedTools: { link: 3 }, label: 'Text' }, rr: { api: fakeApi() } });
        await flush();

        const link = wrapper.find('[data-tool="link"]');
        expect(link.attributes('aria-disabled')).toBe('true');
        expect(link.attributes('aria-label')).toBe('Link unlocks after 3 approved contributions');
        await link.trigger('click');
        expect(wrapper.find('input[type="url"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('the score input is a native radio group with labelled options', () => {
        const { wrapper } = mountWithRr(RrScaleInput, { props: { modelValue: null, max: 5, name: 'r', legend: 'Your score' }, rr: { api: fakeApi() } });

        const radios = wrapper.findAll('input[type="radio"]');
        expect(radios).toHaveLength(5);
        expect(radios.every((radio) => radio.attributes('name') === 'r')).toBe(true);
        expect(wrapper.find('legend').text()).toBe('Your score');
        expect(wrapper.findAll('.rr-visually-hidden').map((label) => label.text())).toEqual(['1 out of 5', '2 out of 5', '3 out of 5', '4 out of 5', '5 out of 5']);
        wrapper.unmount();
    });

    it('the author hovercard opens on focus, closes on Escape and returns focus', async () => {
        const api = fakeApi({ authorCard: () => Promise.resolve({ id: 3, name: 'Anna', avatarUrl: null, profileUrl: '/u/3', extra: {}, reviewsCount: 4, commentsCount: 9, memberSince: null, badges: [], followUrl: null }) });
        const { wrapper } = mountWithRr(RrAuthorName, { props: { author: { id: 3, name: 'Anna', avatarUrl: null, profileUrl: '/u/3', extra: {} } }, rr: { api } });

        const trigger = wrapper.find('button.rr-author__name');
        (trigger.element as HTMLElement).focus();
        await trigger.trigger('focus');
        await new Promise((resolve) => setTimeout(resolve, 10));
        await flush();
        expect(trigger.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('.rr-hovercard').text()).toContain('4 reviews');
        expect(wrapper.find('.rr-hovercard').text()).toContain('9 comments');

        await wrapper.find('.rr-author').trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('.rr-hovercard').exists()).toBe(false);
        expect(document.activeElement).toBe(trigger.element);
        wrapper.unmount();
    });
});
