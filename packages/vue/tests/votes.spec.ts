import { describe, expect, it } from 'vitest';
import { toggleVote } from '../src/shared';
import RrVoteButtons from '../src/components/RrVoteButtons.vue';
import { fakeApi, flush, mountWithRr } from './helpers';

describe('toggleVote (mirrors VoteService)', () => {
    it('adds, withdraws and switches within one dimension', () => {
        const none = { value: 0, up: 3, down: 1 };
        const agreed = toggleVote(none, 1);

        expect(agreed).toEqual({ value: 1, up: 4, down: 1 });
        expect(toggleVote(agreed, 1)).toEqual({ value: 0, up: 3, down: 1 });
        expect(toggleVote(agreed, -1)).toEqual({ value: -1, up: 3, down: 2 });
    });

    it('never makes a count negative', () => {
        expect(toggleVote({ value: 1, up: 0, down: 0 }, -1)).toEqual({ value: -1, up: 0, down: 1 });
    });
});

describe('<RrVoteButtons>', () => {
    const props = {
        target: 'comment' as const,
        targetId: 9,
        dimensions: ['agreement', 'needs_source'],
        counts: { agreement: { up: 2, down: 1 }, needs_source: { up: 0, down: 0 } },
        viewerVotes: {},
    };

    const button = (wrapper: ReturnType<typeof mountWithRr>['wrapper'], dimension: string, value: number) => wrapper.find(`button[data-dimension="${dimension}"][data-value="${value}"]`);

    it('shows a button per allowed value (needs_source has no negative)', () => {
        const { wrapper } = mountWithRr(RrVoteButtons, { props, rr: { api: fakeApi() } });

        expect(wrapper.findAll('button').map((b) => `${b.attributes('data-dimension')}:${b.attributes('data-value')}`)).toEqual(['agreement:1', 'agreement:-1', 'needs_source:1']);
        wrapper.unmount();
    });

    it('applies the server result after an optimistic toggle', async () => {
        let resolve: (value: unknown) => void = () => {};
        const api = fakeApi({ voteComment: () => new Promise((done) => (resolve = done)) });
        const { wrapper } = mountWithRr(RrVoteButtons, { props, rr: { api } });

        await button(wrapper, 'agreement', 1).trigger('click');
        expect(button(wrapper, 'agreement', 1).attributes('aria-busy')).toBe('true');
        expect(button(wrapper, 'agreement', 1).attributes('aria-pressed')).toBe('true');
        expect(button(wrapper, 'agreement', 1).find('.rr-vote__count').text()).toBe('3');

        resolve({ dimension: 'agreement', value: 1, counted: true, counts: { up: 7, down: 1 } });
        await flush();
        expect(api.voteComment).toHaveBeenCalledWith(9, 'agreement', 1);
        expect(button(wrapper, 'agreement', 1).find('.rr-vote__count').text()).toBe('7');
        expect(button(wrapper, 'agreement', 1).attributes('aria-label')).toBe('Agree: 7');
        wrapper.unmount();
    });

    it('switches from agree to disagree and withdraws on a second press', async () => {
        const results = [
            { dimension: 'agreement', value: -1, counted: true, counts: { up: 1, down: 2 } },
            { dimension: 'agreement', value: 0, counted: true, counts: { up: 1, down: 1 } },
        ];
        const api = fakeApi({ voteComment: () => Promise.resolve(results.shift()) });
        const { wrapper } = mountWithRr(RrVoteButtons, { props: { ...props, viewerVotes: { agreement: 1 } }, rr: { api } });

        expect(button(wrapper, 'agreement', 1).attributes('aria-pressed')).toBe('true');
        await button(wrapper, 'agreement', -1).trigger('click');
        await flush();
        expect(button(wrapper, 'agreement', 1).attributes('aria-pressed')).toBe('false');
        expect(button(wrapper, 'agreement', -1).attributes('aria-pressed')).toBe('true');

        await button(wrapper, 'agreement', -1).trigger('click');
        await flush();
        expect(button(wrapper, 'agreement', -1).attributes('aria-pressed')).toBe('false');
        expect(button(wrapper, 'agreement', -1).find('.rr-vote__count').text()).toBe('1');
        wrapper.unmount();
    });

    it('rolls back and explains when the vote fails', async () => {
        const api = fakeApi({ voteComment: () => Promise.reject(new Error('boom')) });
        const { wrapper } = mountWithRr(RrVoteButtons, { props, rr: { api } });

        await button(wrapper, 'agreement', 1).trigger('click');
        await flush();

        expect(button(wrapper, 'agreement', 1).attributes('aria-pressed')).toBe('false');
        expect(button(wrapper, 'agreement', 1).find('.rr-vote__count').text()).toBe('2');
        expect(wrapper.find('[role="status"]').text()).toBe('Something went wrong. Please try again.');
        wrapper.unmount();
    });

    it('says when a stored vote does not count', async () => {
        const api = fakeApi({ voteComment: () => Promise.resolve({ dimension: 'agreement', value: 1, counted: false, counts: { up: 2, down: 1 } }) });
        const { wrapper } = mountWithRr(RrVoteButtons, { props, rr: { api } });

        await button(wrapper, 'agreement', 1).trigger('click');
        await flush();

        expect(wrapper.find('[role="status"]').text()).toBe('Your vote was saved, but does not count (yet).');
        wrapper.unmount();
    });

    it('does not call the API on own content or for guests where guests may not vote', async () => {
        const api = fakeApi();
        const own = mountWithRr(RrVoteButtons, { props: { ...props, isOwn: true }, rr: { api } }).wrapper;
        await button(own, 'agreement', 1).trigger('click');
        expect(own.find('[role="status"]').text()).toBe('You cannot vote on your own contribution.');
        own.unmount();

        const guest = mountWithRr(RrVoteButtons, { props, rr: { api, isAuthenticated: false } }).wrapper;
        await button(guest, 'agreement', 1).trigger('click');
        expect(guest.find('[role="status"]').text()).toBe('Log in to vote.');
        expect(api.voteComment).not.toHaveBeenCalled();
        guest.unmount();
    });

    it('lets guests vote where the section allows it', async () => {
        const api = fakeApi({ voteComment: () => Promise.resolve({ dimension: 'agreement', value: 1, counted: true, counts: { up: 3, down: 1 } }) });
        const { wrapper } = mountWithRr(RrVoteButtons, { props: { ...props, guestVotes: true }, rr: { api, isAuthenticated: false } });

        await button(wrapper, 'agreement', 1).trigger('click');
        await flush();
        expect(api.voteComment).toHaveBeenCalledOnce();
        wrapper.unmount();
    });
});
