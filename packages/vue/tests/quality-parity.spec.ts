import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { evaluateQuality } from '../src/shared';
import type { QualityRules } from '../src/types';

/**
 * Parity with the PHP ReviewQualityMeter: tests/fixtures/review-quality.json is written by
 * scripts/quality-fixture.php from the real PHP rules and the PHP assess() results.
 */
const fixture = JSON.parse(readFileSync(resolve(__dirname, 'fixtures/review-quality.json'), 'utf8')) as {
    rules: QualityRules;
    cases: Array<{ name: string; body: string; pros: string[]; cons: string[]; topics_rated: number; topics_available: number; extended_fields: boolean; expected: { score: number; level: string; hints: string[] } }>;
};

describe('quality meter parity with the PHP rules', () => {
    it('has the PHP rules and enough cases to compare', () => {
        expect(fixture.rules.version).toBe(1);
        expect(fixture.rules.rules.map((rule) => rule.type)).toEqual(expect.arrayContaining(['length', 'pros', 'cons', 'words', 'topics', 'concrete']));
        expect(fixture.cases.length).toBeGreaterThanOrEqual(15);
        expect(new Set(fixture.cases.map((item) => item.expected.score)).size).toBeGreaterThan(5);
    });

    it.each(fixture.cases.map((item) => [item.name, item] as const))('%s', (_name, item) => {
        const result = evaluateQuality({
            body: item.body,
            pros: item.pros,
            cons: item.cons,
            topicRatings: item.topics_rated,
            topicCount: item.topics_available,
            extendedFields: item.extended_fields,
        }, fixture.rules);

        expect(result.available).toBe(true);
        expect(result.score).toBe(item.expected.score);
        expect(result.level).toBe(item.expected.level);
        expect(result.hints.map((hint) => hint.key)).toEqual(item.expected.hints);
    });

    it('counts rated topics from a ratings map the way the form holds them', () => {
        const rules = fixture.rules;
        const asMap = evaluateQuality({ body: 'x', topicRatings: { 1: 4, 2: null, 3: 0 }, topicCount: 3 }, rules);
        const asCount = evaluateQuality({ body: 'x', topicRatings: 1, topicCount: 3 }, rules);

        expect(asMap.score).toBe(asCount.score);
    });

    it('uses the server-translated hint and level labels', () => {
        const result = evaluateQuality({ body: '' }, fixture.rules);

        expect(result.hints[0].label).toBe(fixture.rules.rules[0].hint);
        expect(result.levelLabel).toBe(fixture.rules.levels.find((level) => level.level === 'weak')?.label);
    });

    it('is unavailable without rules instead of inventing a score', () => {
        expect(evaluateQuality({ body: 'anything' }, null)).toEqual({ score: 0, level: null, levelLabel: null, hints: [], available: false });
    });
});
