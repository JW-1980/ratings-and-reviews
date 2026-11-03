/**
 * @vitest-environment-options {"url": "http://localhost/page"}
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { enhanceRatingsAndReviews } from '../src/shared/blade/index.js';

/**
 * Pins and follow-ups in the Blade enhancement, against fixtures/blade-page-participation.html
 * (written by scripts/blade-fixture/BladeComponentsRenderTest.php: the author's review card with
 * an open follow-up window and a moderator's discussion with a pinned, attributed comment).
 */
const page = readFileSync(resolve(__dirname, 'fixtures/blade-page-participation.html'), 'utf8');

type Call = { url: string; method: string; body: unknown };

function json(status: number, body: unknown) {
    return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function setup(routes: Record<string, (call: Call) => Response> = {}) {
    document.body.innerHTML = page;
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    const calls: Call[] = [];
    vi.stubGlobal('fetch', vi.fn(async (input: string, init: RequestInit = {}) => {
        const url = new URL(input, window.location.href);
        const call = { url: url.pathname, method: init.method ?? 'GET', body: init.body ? JSON.parse(String(init.body)) : null };
        calls.push(call);

        for (const [pattern, handler] of Object.entries(routes)) {
            const [method, path] = pattern.split(' ');

            if (call.method === method && new RegExp(`^${path}$`).test(url.pathname)) {
                return handler(call);
            }
        }

        return call.method === 'GET' && !url.pathname.startsWith('/rr/')
            ? new Response(page, { status: 200, headers: { 'Content-Type': 'text/html' } })
            : json(404, { message: 'Not found' });
    }));
    enhanceRatingsAndReviews(document);

    return calls;
}

async function until(check: () => boolean, attempts = 60) {
    for (let attempt = 0; attempt < attempts && !check(); attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
}

describe('Blade: pins and follow-ups', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('renders the pinned comment first with its badge, and unpins through the API', async () => {
        const calls = setup({ 'DELETE /rr/comments/\\d+/pin': () => json(200, { data: { is_pinned: false }, message: 'Unpinned' }) });
        const comments = [...document.querySelectorAll('[data-rr="discussion"] .rr-comment')];

        expect(comments[0].classList.contains('is-pinned')).toBe(true);
        expect(comments[0].querySelector('.rr-tag--attribution')?.textContent).toContain('Oatly');

        const unpin = comments[0].querySelector<HTMLButtonElement>('[data-rr-pin][data-pinned="1"]')!;
        unpin.click();
        await until(() => calls.some((call) => call.method === 'DELETE'));

        const id = unpin.dataset.rrPin;
        expect(calls.find((call) => call.method === 'DELETE')?.url).toBe(`/rr/comments/${id}/pin`);
    });

    it('validates and posts the follow-up through the API', async () => {
        const calls = setup({ 'POST /rr/reviews/\\d+/follow-up': () => json(201, { data: { id: 1 }, message: 'Thanks' }) });
        const form = document.querySelector<HTMLFormElement>('form[data-rr-follow-up-form]')!;

        form.requestSubmit();
        await until(() => form.querySelector('[data-rr-field="rating"] [data-rr-field-error]')?.textContent !== '');
        expect(form.querySelector('[data-rr-field="rating"] [data-rr-field-error]')?.textContent).toBe('Choose a score.');
        expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0);

        form.querySelector<HTMLInputElement>('input[name="rating"][value="2"]')!.checked = true;
        form.querySelector<HTMLTextAreaElement>('textarea[name="body"]')!.value = 'Na een maand minder';
        form.requestSubmit();
        await until(() => calls.some((call) => call.method === 'POST'));

        const post = calls.find((call) => call.method === 'POST')!;
        expect(post.url).toBe(`/rr/reviews/${form.dataset.rrFollowUpForm}/follow-up`);
        expect(post.body).toEqual({ rating: 2, body: 'Na een maand minder' });
    });
});
