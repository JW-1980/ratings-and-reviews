/**
 * Review quality meter (deterministic, no AI): the JavaScript mirror of the server's
 * ReviewQualityMeter::assess(). It computes with the rules served by
 * `GET review-quality-rules` (ReviewQualityMeter::rules()), so the live meter shows the
 * score the server stores as `quality_score`.
 *
 * Rules (version 1), as served:
 *
 *   { version: 1,
 *     rules: [
 *       { key, type: 'length',   steps: [[minCodePoints, points], …], max_points, hint },
 *       { key, type: 'pros'|'cons', points, max_points, hint },        // only with extended fields
 *       { key, type: 'words',    points, words: [...], max_points, hint },
 *       { key, type: 'topics',   points, max_points, hint },            // only when the subject has topics
 *       { key, type: 'concrete', points, units: [...], max_points, hint },
 *     ],
 *     levels: [{ level: 'weak', min: 0, label }, { level: 'fair', min: 40, label }, …] }
 *
 * Semantics, identical to PHP:
 * - text: the plain text of the body, whitespace collapsed, trimmed, lower-cased;
 *   lengths in Unicode code points (mb_strlen);
 * - a word or phrase matches when not directly preceded or followed by a letter or digit:
 *   (?<![\p{L}\p{N}])phrase(?![\p{L}\p{N}]) with the u flag;
 * - length: the points of the highest step reached; pros/cons: all points with at least
 *   one non-empty point; words: all points when a word matches; topics:
 *   floor(points × rated / available); concrete: a decimal digit (\p{Nd}) or a unit word;
 * - a rule that cannot apply (no topics, no extended fields) counts for neither side;
 * - score = floor(earned × 100 / applicable + 0.5), 0 when nothing applies;
 * - level: the highest level whose `min` the score reaches; hints: the keys of rules whose
 *   points were not (fully) earned, in rule order.
 */

import { plainText } from './sanitize.js';

/** @param {string} text */
export function codePointLength(text) {
    return [...(text ?? '')].length;
}

/** WordMatcher::normalize(): collapse whitespace, trim, lower-case. */
export function normalizeText(text) {
    return String(text ?? '').replace(/\s+/gu, ' ').trim().toLowerCase();
}

function escapeRegex(text) {
    return text.replace(/[.*+?^${}()|[\]\\/-]/g, '\\$&');
}

/** WordMatcher::contains() on already normalised text. */
export function containsWord(normalizedText, phrase) {
    const needle = normalizeText(phrase);

    if (needle === '' || normalizedText === '') {
        return false;
    }

    return new RegExp(`(?<![\\p{L}\\p{N}])${escapeRegex(needle)}(?![\\p{L}\\p{N}])`, 'u').test(normalizedText);
}

function maxPoints(rule) {
    if (rule.type === 'length') {
        return (rule.steps ?? []).reduce((max, step) => Math.max(max, Number(step[1]) || 0), 0);
    }

    return Number(rule.points ?? 0);
}

function countFilled(items) {
    if (typeof items === 'number') {
        return items;
    }

    return (Array.isArray(items) ? items : []).filter((item) => String(item ?? '').trim() !== '').length;
}

/**
 * @typedef {object} QualityDraft
 * @property {string} [body] editor HTML or plain text
 * @property {string[]|number} [pros]
 * @property {string[]|number} [cons]
 * @property {Record<string, number|null>|number} [topicRatings] ratings by topic id, or the number rated
 * @property {number} [topicCount] topics the subject has
 * @property {boolean} [extendedFields] the section's review_extended_fields (default true)
 */

/**
 * @param {QualityDraft} draft
 * @param {object|null} rules as served by GET review-quality-rules
 * @returns {{ score: number, level: string|null, levelLabel: string|null, hints: Array<{ key: string, hint: string, label: string|null }>, available: boolean }}
 */
export function evaluateQuality(draft, rules) {
    if (!rules || !Array.isArray(rules.rules)) {
        return { score: 0, level: null, levelLabel: null, hints: [], available: false };
    }

    const input = draft ?? {};
    const body = String(input.body ?? '');
    const text = normalizeText(/<[a-z][\s\S]*>/i.test(body) ? plainText(body) : body);
    const length = codePointLength(text);
    const extended = input.extendedFields ?? true;
    const available = Math.max(0, Number(input.topicCount ?? 0));
    const ratedRaw = typeof input.topicRatings === 'number'
        ? input.topicRatings
        : Object.values(input.topicRatings ?? {}).filter((value) => Number(value) > 0).length;
    const rated = Math.max(0, Math.min(available, ratedRaw));

    let earnedTotal = 0;
    let applicable = 0;
    const hints = [];

    for (const rule of rules.rules) {
        const points = maxPoints(rule);
        let earned = 0;

        switch (rule.type) {
            case 'length':
                for (const [min, stepPoints] of rule.steps ?? []) {
                    if (length >= min) {
                        earned = Math.max(earned, stepPoints);
                    }
                }

                break;
            case 'pros':
            case 'cons':
                if (!extended) {
                    continue;
                }

                earned = countFilled(input[rule.type]) > 0 ? points : 0;
                break;
            case 'words':
                earned = (rule.words ?? []).some((word) => containsWord(text, word)) ? points : 0;
                break;
            case 'topics':
                if (available === 0) {
                    continue;
                }

                earned = Math.floor((points * rated) / available);
                break;
            case 'concrete':
                earned = /\p{Nd}/u.test(text) || (rule.units ?? []).some((unit) => containsWord(text, unit)) ? points : 0;
                break;
            default:
                continue;
        }

        earnedTotal += earned;
        applicable += points;

        if (earned < points) {
            hints.push({ key: rule.key, hint: rule.key, label: rule.hint ?? null });
        }
    }

    const score = applicable === 0 ? 0 : Math.floor((earnedTotal * 100) / applicable + 0.5);
    const levels = [...(rules.levels ?? [])].sort((a, b) => a.min - b.min);
    const reached = levels.filter((entry) => score >= entry.min).pop() ?? null;

    return { score, level: reached?.level ?? null, levelLabel: reached?.label ?? null, hints, available: true };
}
