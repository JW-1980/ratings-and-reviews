import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { createTranslator } from '../src/shared';
import { loadMessages } from './helpers';

function keys(value: unknown, prefix = ''): string[] {
    return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) => (typeof child === 'object' && child !== null ? keys(child, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('translations', () => {
    const en = loadMessages('en');
    const nl = loadMessages('nl');

    it('lang/nl/ui.php and lang/en/ui.php have the same, non-empty keys', () => {
        expect(keys(nl)).toEqual(keys(en));
        for (const messages of [en, nl]) {
            for (const key of keys(messages)) {
                const line = key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown>)[part], messages);
                expect(String(line).trim(), key).not.toBe('');
            }
        }
    });

    it('every literal key used by the components and shared modules exists', () => {
        const sources = [
            ...readdirSync(resolve(__dirname, '../src/components')).map((file) => resolve(__dirname, '../src/components', file)),
            ...['../src/composables/errors.ts', '../src/composables/discussion.ts', '../../../resources/js/poll.js', '../../../resources/js/editor/index.js'].map((file) => resolve(__dirname, file)),
            ...readdirSync(resolve(__dirname, '../../../resources/js/blade')).map((file) => resolve(__dirname, '../../../resources/js/blade', file)),
        ];
        const available = new Set(keys(en));
        const used = new Set<string>();

        for (const file of sources) {
            const text = readFileSync(file, 'utf8');

            for (const match of text.matchAll(/\b(?:t|tc)\(\s*'([a-z_]+(?:\.[a-z_]+)+)'/g)) {
                used.add(match[1]);
            }

            for (const match of text.matchAll(/key: '([a-z_]+(?:\.[a-z_]+)+)'/g)) {
                used.add(match[1]);
            }
        }

        expect(used.size).toBeGreaterThan(120);
        expect([...used].filter((key) => !available.has(key))).toEqual([]);
    });

    it('applies Laravel placeholders and plural choices', () => {
        const { t, tc } = createTranslator(nl);

        expect(t('summary.score_out_of', { score: '8,1', max: 10 })).toBe('8,1 van 10');
        expect(tc('summary.based_on', 0)).toBe('Nog geen reviews');
        expect(tc('summary.based_on', 1)).toBe('Op basis van 1 review');
        expect(tc('summary.based_on', 12)).toBe('Op basis van 12 reviews');
        expect(tc('editor.locked', 3, { tool: 'Link' })).toBe('Link komt vrij na 3 goedgekeurde bijdragen');
        expect(tc('undo.message', 0)).toBe('Je reactie wordt over 0 seconden geplaatst.');
    });

    it('shows a missing key as the key, reports it, and never falls back to another language', () => {
        const onMissing = vi.fn();
        const { t } = createTranslator({ common: {} }, { onMissing });

        expect(t('common.close')).toBe('common.close');
        expect(onMissing).toHaveBeenCalledWith('common.close');
    });
});
