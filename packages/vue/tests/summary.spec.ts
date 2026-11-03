import { describe, expect, it } from 'vitest';
import RrRatingSummary from '../src/components/RrRatingSummary.vue';
import * as normalize from '../src/shared/normalize.js';
import { fakeApi, flush, mountWithRr } from './helpers';
import type { RatingSummary } from '../src/types';

const summary = normalize.ratingSummary({
    scale_max: 5, count: 10, average: 4.2, bayesian_average: 4.0, histogram: { 5: 6, 4: 2, 3: 1, 2: 0, 1: 1 },
    topics: [{ id: 1, name: 'Taste', scale_max: 5, count: 8, average: 4.5 }],
    uncertainty: { margin: 0.3, lower: 3.9, upper: 4.5, few_reviews: false },
    trend: { points: [{ month: '2026-09', count: 3, average: 4 }, { month: '2026-10', count: 7, average: 4.3 }] },
    facets: [{ group: 'purchase', label: 'Purchase', values: [{ value: 'first_time', label: 'First time', count: 4 }, { value: 'regular', label: 'Regular buyer', count: 0 }] }],
    comparisons: { alternatives: [], often_switch_to: { type: 'product', id: 3, title: 'Other yoghurt', url: '/p/3', count: 2 } },
}) as RatingSummary;

describe('<RrRatingSummary>', () => {
    it('shows score, uncertainty band, trend and "switch to"', () => {
        const { wrapper } = mountWithRr(RrRatingSummary, { props: { subject: { type: 'product', id: 1 }, summary }, rr: { api: fakeApi() } });

        expect(wrapper.find('.rr-summary__value').text()).toBe('4.2');
        expect(wrapper.find('.rr-summary__band').text()).toBe('± 0.3');
        expect(wrapper.text()).toContain('Based on 10 reviews');
        expect(wrapper.text()).toContain('With 95% confidence the true score lies between 3.9 and 4.5.');
        expect(wrapper.find('svg.rr-sparkline').attributes('aria-label')).toContain('4.0');
        expect(wrapper.find('.rr-switch--highlight').text()).toContain('Other yoghurt');
        wrapper.unmount();
    });

    it('histogram rows are toggle buttons that set and clear the rating filter', async () => {
        const { wrapper } = mountWithRr(RrRatingSummary, { props: { subject: { type: 'product', id: 1 }, summary, rating: null }, rr: { api: fakeApi() } });
        const rows = wrapper.findAll('.rr-histogram__row');

        expect(rows.map((row) => row.find('.rr-histogram__bucket').text())).toEqual(['5', '4', '3', '2', '1']);
        expect(rows[3].attributes('disabled')).toBeDefined();
        expect(rows[0].attributes('aria-label')).toBe('5 out of 5: 6 reviews (60%). Show only reviews with 5 out of 5');

        await rows[0].trigger('click');
        expect(wrapper.emitted('update:rating')![0]).toEqual([5]);
        await wrapper.setProps({ rating: 5 });
        expect(wrapper.findAll('.rr-histogram__row')[0].attributes('aria-pressed')).toBe('true');
        await wrapper.findAll('.rr-histogram__row')[0].trigger('click');
        expect(wrapper.emitted('update:rating')![1]).toEqual([null]);
        wrapper.unmount();
    });

    it('facet chips toggle a group => value filter', async () => {
        const { wrapper } = mountWithRr(RrRatingSummary, { props: { subject: { type: 'product', id: 1 }, summary, facets: {} }, rr: { api: fakeApi() } });

        await wrapper.find('.rr-chip').trigger('click');
        expect(wrapper.emitted('update:facets')![0]).toEqual([{ purchase: 'first_time' }]);
        await wrapper.setProps({ facets: { purchase: 'first_time' } });
        await wrapper.find('.rr-chip').trigger('click');
        expect(wrapper.emitted('update:facets')![1]).toEqual([{}]);
        wrapper.unmount();
    });

    it('fetches the summary when none is given and offers a retry on failure', async () => {
        const api = fakeApi({ ratingSummary: () => Promise.reject(new Error('down')) });
        const { wrapper } = mountWithRr(RrRatingSummary, { props: { subject: { type: 'product', id: 1 } }, rr: { api } });
        await flush();

        expect(wrapper.find('[role="alert"]').text()).toContain('Something went wrong');
        api.ratingSummary.mockResolvedValue(summary);
        await wrapper.find('[role="alert"] button').trigger('click');
        await flush();
        expect(wrapper.find('.rr-summary__value').text()).toBe('4.2');
        wrapper.unmount();
    });
});
