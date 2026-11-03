import { describe, expect, it } from 'vitest';
import { applyPollVote, pollPercentages, validatePoll } from '../src/shared';
import RrPoll from '../src/components/RrPoll.vue';
import RrPollBuilder from '../src/components/RrPollBuilder.vue';
import { fakeApi, flush, mountWithRr } from './helpers';
import type { Poll } from '../src/types';

describe('poll validation', () => {
    it('accepts a question with 2–4 distinct options', () => {
        expect(validatePoll({ question: 'Warm of koud?', options: ['Warm', 'Koud'] }).valid).toBe(true);
        expect(validatePoll({ question: 'Q', options: ['a', 'b', 'c', 'd'] }).valid).toBe(true);
    });

    it('rejects a missing question, too few, too many, duplicate and overlong options', () => {
        const keys = (poll: { question: string; options: string[] }, limits = {}) => validatePoll(poll, limits).errors.map((error) => error.key);

        expect(keys({ question: '  ', options: ['a', 'b'] })).toEqual(['poll.error.question_required']);
        expect(keys({ question: 'Q', options: ['a', '  '] })).toEqual(['poll.error.too_few']);
        expect(keys({ question: 'Q', options: ['a', 'b', 'c', 'd', 'e'] })).toEqual(['poll.error.too_many']);
        expect(keys({ question: 'Q', options: ['Ja', 'ja '] })).toEqual(['poll.error.duplicate']);
        expect(keys({ question: 'Q'.repeat(256), options: ['a', 'b'] })).toEqual(['poll.error.question_too_long']);
        expect(keys({ question: 'Q', options: ['a', 'b'.repeat(121)] })).toEqual(['poll.error.option_too_long']);
        expect(keys({ question: 'Q', options: ['a', 'b', 'c'] }, { maxOptions: 2 })).toEqual(['poll.error.too_many']);
    });

    it('trims options and drops empty ones', () => {
        expect(validatePoll({ question: 'Q', options: [' a ', '', 'b'] }).options).toEqual(['a', 'b']);
    });

    it('rounds percentages so they add up to 100', () => {
        expect(pollPercentages([{ votes: 1 }, { votes: 1 }, { votes: 1 }])).toEqual([34, 33, 33]);
        expect(pollPercentages([{ votes: 0 }, { votes: 0 }])).toEqual([0, 0]);
        expect(pollPercentages([{ votes: 2 }, { votes: 1 }]).reduce((a, b) => a + b)).toBe(100);
    });

    it('moves a vote between options without double counting', () => {
        const poll: Poll = { id: 1, question: 'Q', options: [{ id: 1, label: 'a', votes: 2 }, { id: 2, label: 'b', votes: 0 }], totalVotes: 2, viewerOptionId: null, closesAt: null, isClosed: false };
        const first = applyPollVote(poll, 2);
        const moved = applyPollVote(first, 1);

        expect(first.totalVotes).toBe(3);
        expect(moved.options.map((option) => option.votes)).toEqual([3, 0]);
        expect(moved.totalVotes).toBe(3);
        expect(applyPollVote({ ...poll, isClosed: true }, 1)).toEqual({ ...poll, isClosed: true });
    });
});

describe('<RrPollBuilder> and <RrPoll>', () => {
    it('shows translated validation errors only when asked to', async () => {
        const { wrapper } = mountWithRr(RrPollBuilder, { props: { modelValue: { question: '', options: ['', ''] }, showErrors: false }, rr: { api: fakeApi() } });

        expect(wrapper.find('.rr-field-error').exists()).toBe(false);
        await wrapper.setProps({ showErrors: true });
        expect(wrapper.text()).toContain('Enter a question.');
        expect(wrapper.text()).toContain('Give at least 2 options.');
        expect(wrapper.find('input[aria-invalid="true"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('adds options up to the maximum and never removes below the minimum', async () => {
        const { wrapper } = mountWithRr(RrPollBuilder, { props: { modelValue: { question: 'Q', options: ['a', 'b'] }, maxOptions: 3 }, rr: { api: fakeApi() } });

        expect(wrapper.findAll('button').some((b) => b.text() === 'Remove option 1')).toBe(false);
        await wrapper.findAll('button').find((b) => b.text() === 'Add option')!.trigger('click');
        const value = wrapper.emitted('update:modelValue')![0][0] as { options: string[] };
        expect(value.options).toEqual(['a', 'b', '']);
        await wrapper.setProps({ modelValue: value });
        expect(wrapper.findAll('button').some((b) => b.text() === 'Add option')).toBe(false);
        wrapper.unmount();
    });

    it('votes, shows results and reverts when the vote fails', async () => {
        const poll: Poll = { id: 5, question: 'Warm?', options: [{ id: 1, label: 'Ja', votes: 1 }, { id: 2, label: 'Nee', votes: 1 }], totalVotes: 2, viewerOptionId: null, closesAt: null, isClosed: false };
        const server: Poll = { ...poll, options: [{ id: 1, label: 'Ja', votes: 2 }, { id: 2, label: 'Nee', votes: 1 }], totalVotes: 3, viewerOptionId: 1 };
        const api = fakeApi({ votePoll: () => Promise.resolve({ poll: server, counted: true, changed: true }) });
        const { wrapper } = mountWithRr(RrPoll, { props: { poll }, rr: { api } });

        expect(wrapper.find('.rr-poll__percent').exists()).toBe(false);
        await wrapper.findAll('button')[0].trigger('click');
        await flush();
        expect(api.votePoll).toHaveBeenCalledWith(5, 1);
        expect(wrapper.findAll('button')[0].attributes('aria-pressed')).toBe('true');
        expect(wrapper.findAll('.rr-poll__percent').map((p) => p.text())).toEqual(['67%', '33%']);
        expect(wrapper.text()).toContain('3 votes');
        wrapper.unmount();

        const failing = fakeApi({ votePoll: () => Promise.reject(new Error('x')) });
        const second = mountWithRr(RrPoll, { props: { poll }, rr: { api: failing } }).wrapper;
        await second.findAll('button')[1].trigger('click');
        await flush();
        expect(second.findAll('button')[1].attributes('aria-pressed')).toBe('false');
        expect(second.emitted('message')![0]).toEqual(['Something went wrong. Please try again.']);
        second.unmount();
    });

    it('does not vote as a guest and leaves the poll unchanged', async () => {
        const poll: Poll = { id: 5, question: 'Warm?', options: [{ id: 1, label: 'Ja', votes: 1 }, { id: 2, label: 'Nee', votes: 1 }], totalVotes: 2, viewerOptionId: null, closesAt: null, isClosed: false };
        const api = fakeApi();
        const { wrapper } = mountWithRr(RrPoll, { props: { poll }, rr: { api, isAuthenticated: false } });

        await wrapper.findAll('button')[0].trigger('click');
        await flush();

        expect(api.votePoll).not.toHaveBeenCalled();
        expect(wrapper.findAll('button')[0].attributes('aria-pressed')).toBe('false');
        expect(wrapper.emitted('message')![0]).toEqual(['Log in to vote.']);
        wrapper.unmount();
    });
});
