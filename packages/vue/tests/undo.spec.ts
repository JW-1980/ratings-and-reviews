import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createUndoCountdown, secondsUntil } from '../src/shared';
import RrUndoToast from '../src/components/RrUndoToast.vue';
import { fakeApi, mountWithRr } from './helpers';

describe('undo countdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('ticks down and expires exactly once', () => {
        const ticks: number[] = [];
        const onExpire = vi.fn();
        createUndoCountdown({ seconds: 3, onTick: (left) => ticks.push(left), onExpire });

        vi.advanceTimersByTime(2900);
        expect(onExpire).not.toHaveBeenCalled();
        vi.advanceTimersByTime(200);
        expect(onExpire).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(5000);
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(ticks[0]).toBe(3);
        expect(ticks).toContain(1);
        expect(ticks[ticks.length - 1]).toBe(0);
    });

    it('cannot be cancelled after it expired, and cancelling stops expiry', () => {
        const onExpire = vi.fn();
        const late = createUndoCountdown({ seconds: 1, onExpire });
        vi.advanceTimersByTime(1100);
        expect(late.cancel()).toBe(false);

        const early = createUndoCountdown({ seconds: 5, onExpire });
        vi.advanceTimersByTime(1000);
        expect(early.cancel()).toBe(true);
        expect(early.state).toBe('cancelled');
        vi.advanceTimersByTime(10000);
        expect(onExpire).toHaveBeenCalledTimes(1);
    });

    it('reads the remaining window from publish_at', () => {
        const now = Date.parse('2026-11-02T12:00:00Z');

        expect(secondsUntil('2026-11-02T12:00:09.200Z', now)).toBe(10);
        expect(secondsUntil('2026-11-02T11:59:00Z', now)).toBe(0);
        expect(secondsUntil(null, now)).toBe(0);
    });

    it('<RrUndoToast> counts down, emits undo when pressed in time and expire otherwise', async () => {
        const { wrapper } = mountWithRr(RrUndoToast, { props: { seconds: 10 }, rr: { api: fakeApi() } });

        expect(wrapper.text()).toContain('Your comment will be posted in 10 seconds.');
        vi.advanceTimersByTime(9000);
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Your comment will be posted in 1 second.');

        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('undo')).toHaveLength(1);
        vi.advanceTimersByTime(5000);
        expect(wrapper.emitted('expire')).toBeUndefined();
        wrapper.unmount();

        const second = mountWithRr(RrUndoToast, { props: { seconds: 2 }, rr: { api: fakeApi() } }).wrapper;
        vi.advanceTimersByTime(2500);
        expect(second.emitted('expire')).toHaveLength(1);
        await second.find('button').trigger('click');
        expect(second.emitted('undo')).toBeUndefined();
        expect(second.find('[role="status"]').attributes('aria-live')).toBe('polite');
        second.unmount();
    });
});
