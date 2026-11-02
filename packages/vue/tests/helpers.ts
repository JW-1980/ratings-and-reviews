import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { mount, type VueWrapper } from '@vue/test-utils';
import { vi } from 'vitest';
import { createContext, RR_CONTEXT, type RatingsAndReviewsOptions } from '../src/context';
import type { ApiClient } from '../src/shared';

const packageRoot = resolve(__dirname, '../../..');

/** The real translations of lang/{locale}/ui.php, read through PHP (the single source). */
export function loadMessages(locale: 'nl' | 'en' = 'en'): Record<string, unknown> {
    const file = resolve(packageRoot, `lang/${locale}/ui.php`);

    return JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', file], { encoding: 'utf8' }));
}

const messages = { en: loadMessages('en'), nl: loadMessages('nl') };

/** An API double: every method is a vi.fn() that rejects unless a test gives it a result. */
type Fake = { [K in keyof ApiClient]: ReturnType<typeof vi.fn> & ApiClient[K] };

export function fakeApi(overrides: Partial<Record<keyof ApiClient, unknown>> = {}): Fake {
    const names: Array<keyof ApiClient> = [
        'request', 'ratingSummary', 'reviews', 'createReview', 'updateReview', 'deleteReview', 'voteReview', 'comments', 'createComment',
        'updateComment', 'deleteComment', 'undoComment', 'revisions', 'voteComment', 'votePoll', 'poll', 'digest', 'search', 'report',
        'authorCard', 'card', 'wantToTry', 'toneCheck', 'qualityRules', 'replies', 'uploadImage', 'stickers', 'adminStickers',
        'createSticker', 'updateSticker', 'deleteSticker', 'topicRatings', 'saveTopicRatings',
    ];
    const api: Record<string, unknown> = {};

    for (const name of names) {
        api[name] = vi.fn(overrides[name] as never ?? (() => Promise.reject(new Error(`unexpected call: ${String(name)}`))));
    }

    api.reviewRepliesSubject = vi.fn(overrides.reviewRepliesSubject as never ?? ((id: number) => ({ type: 'rr_review', id })));

    return api as never;
}

export function context(options: Partial<RatingsAndReviewsOptions> & { api: ApiClient }) {
    return createContext({ messages: messages[(options.locale as 'nl' | 'en') ?? 'en'], locale: 'en', isAuthenticated: true, ...options });
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function mountWithRr(component: unknown, options: Record<string, any> & { rr: Partial<RatingsAndReviewsOptions> & { api: ApiClient } }): { ctx: ReturnType<typeof context>; wrapper: VueWrapper<any> } {
    const { rr, ...rest } = options;
    const ctx = context(rr);

    return {
        ctx,
        wrapper: mount(component as never, {
            attachTo: document.body,
            ...rest,
            global: { ...(rest.global ?? {}), provide: { ...(rest.global?.provide ?? {}), [RR_CONTEXT as symbol]: ctx } },
        } as never),
    };
}

export const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
