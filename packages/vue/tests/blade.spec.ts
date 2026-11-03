/**
 * @vitest-environment-options {"url": "http://localhost/page?rr_rating=4&rr_cq=zoet"}
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enhanceRatingsAndReviews } from '../src/shared/blade/index.js';
import { flush } from './helpers';

/**
 * The Blade enhancement (resources/js/blade) against real server output: fixtures written
 * by scripts/blade-fixture/BladeComponentsRenderTest.php (the four components rendered by
 * Laravel from MySQL data). The network is a fetch double that records every request.
 */
const page = readFileSync(resolve(__dirname, 'fixtures/blade-page.html'), 'utf8');

type Call = { url: string; method: string; body: unknown; headers: Record<string, string> };

function json(status: number, body: unknown) {
    return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function setup(routes: Record<string, (call: Call) => Response | Promise<Response>> = {}) {
    document.body.innerHTML = page;
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    const calls: Call[] = [];
    const fetch = vi.fn(async (input: string, init: RequestInit = {}) => {
        const url = new URL(input, window.location.href);
        const call = { url: url.pathname + url.search, method: init.method ?? 'GET', body: init.body ? JSON.parse(String(init.body)) : null, headers: (init.headers ?? {}) as Record<string, string> };
        calls.push(call);

        for (const [pattern, handler] of Object.entries(routes)) {
            const [method, path] = pattern.split(' ');

            if (call.method === method && new RegExp(`^${path}$`).test(url.pathname)) {
                return handler(call);
            }
        }

        if (call.method === 'GET' && !url.pathname.startsWith('/rr/')) {
            return new Response(page, { status: 200, headers: { 'Content-Type': 'text/html' } });
        }

        return json(404, { message: 'Not found' });
    });
    vi.stubGlobal('fetch', fetch);
    enhanceRatingsAndReviews(document);

    return { calls, fetch };
}

async function until(check: () => boolean, attempts = 50) {
    for (let attempt = 0; attempt < attempts && !check(); attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
}

const discussion = () => document.querySelector<HTMLElement>('[data-rr="discussion"]')!;
const composer = () => discussion().querySelector<HTMLFormElement>(':scope > [data-rr-composer]')!;
const editorOf = (form: Element) => (form.querySelector('[data-rr-editor]') as HTMLElement & { rrEditor: { setContent(html: string): void } }).rrEditor;

describe('Blade components, enhanced', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('switches every component to the enhanced mode and mounts the editor with the section tools', () => {
        setup();

        document.querySelectorAll('[data-rr]').forEach((root) => {
            expect(root.classList.contains('rr-js')).toBe(true);
            expect(root.classList.contains('rr-nojs')).toBe(false);
        });

        const form = composer();
        expect(form.querySelector('textarea')!.hidden).toBe(true);
        expect(form.querySelector('.ProseMirror[role="textbox"]')).not.toBeNull();
        // The tools the server sent for this viewer (section tools minus trust-locked or unavailable ones).
        const tools = JSON.parse(form.querySelector<HTMLElement>('[data-rr-editor]')!.dataset.tools!);
        expect(tools).toEqual(expect.arrayContaining(['bold', 'italic', 'quote', 'link']));
        expect([...form.querySelectorAll('.rr-toolbar-button')].map((button) => button.getAttribute('data-tool')).sort()).toEqual([...tools].sort());
        expect(form.querySelector('[role="toolbar"]')!.getAttribute('aria-label')).toBe('Formatting');
    });

    it('votes through the API with the CSRF header and shows the server counts', async () => {
        const { calls } = setup({ 'POST /rr/comments/\\d+/votes': () => json(200, { data: { dimension: 'helpfulness', value: 1, counted: true, count: { up: 5, down: 0 }, counts_by_dimension: { agreement: { up: 0, down: 0 }, helpfulness: { up: 5, down: 0 }, needs_source: { up: 0, down: 0 } }, counts: { agree_count: 0, disagree_count: 0, helpful_count: 5, unhelpful_count: 0, needs_source_count: 0 } } }) });
        const button = discussion().querySelector<HTMLButtonElement>('[data-rr-votes] button[data-dimension="helpfulness"][data-value="1"]')!;

        button.click();
        await until(() => button.querySelector('.rr-vote__count')!.textContent === '5');

        const call = calls.find((item) => item.method === 'POST')!;
        expect(call.url).toMatch(/^\/rr\/comments\/\d+\/votes$/);
        expect(call.body).toEqual({ dimension: 'helpfulness', value: 1 });
        expect(call.headers['X-CSRF-TOKEN']).toBe('test-token');
        expect(button.getAttribute('aria-pressed')).toBe('true');
        expect(button.querySelector('.rr-vote__count')!.textContent).toBe('5');
    });

    it('shows the server count after a review helpful vote (VoteResult `count`, not the raw columns)', async () => {
        setup({ 'POST /rr/reviews/\\d+/votes': () => json(200, { data: { dimension: 'helpfulness', value: 1, counted: true, count: { up: 8, down: 0 }, counts_by_dimension: { helpfulness: { up: 8, down: 0 } }, counts: { helpful_count: 8 } } }) });
        const button = document.querySelector<HTMLButtonElement>('[data-rr-votes][data-target="review"] button[data-dimension="helpfulness"][data-value="1"]')!;

        button.click();
        await until(() => button.querySelector('.rr-vote__count')!.textContent === '8');
        expect(button.getAttribute('aria-pressed')).toBe('true');
        expect(button.getAttribute('aria-label')).toContain('8');
    });

    it('updates the other dimensions of a comment from `counts_by_dimension`', async () => {
        setup({ 'POST /rr/comments/\\d+/votes': () => json(200, { data: { dimension: 'helpfulness', value: 1, counted: true, count: { up: 3, down: 0 }, counts_by_dimension: { agreement: { up: 4, down: 2 }, helpfulness: { up: 3, down: 0 }, needs_source: { up: 0, down: 0 } }, counts: { agree_count: 4, disagree_count: 2, helpful_count: 3, unhelpful_count: 0, needs_source_count: 0 } } }) });
        const group = discussion().querySelector<HTMLElement>('[data-rr-votes][data-target="comment"][data-id="1"]')!;
        const agree = group.querySelector<HTMLButtonElement>('button[data-dimension="agreement"][data-value="1"]')!;
        const pressed = agree.getAttribute('aria-pressed');

        group.querySelector<HTMLButtonElement>('button[data-dimension="helpfulness"][data-value="1"]')!.click();
        await until(() => group.querySelector('button[data-dimension="helpfulness"][data-value="1"] .rr-vote__count')!.textContent === '3');

        expect(agree.querySelector('.rr-vote__count')!.textContent).toBe('4');
        expect(group.querySelector('button[data-dimension="agreement"][data-value="-1"] .rr-vote__count')!.textContent).toBe('2');
        // The viewer's own choice on another dimension is left as it was.
        expect(agree.getAttribute('aria-pressed')).toBe(pressed);
    });

    it('refuses a too short comment without a request', async () => {
        const { calls } = setup();
        editorOf(composer()).setContent('<p>x</p>');

        composer().requestSubmit();
        await flush();

        expect(composer().querySelector('[data-rr-error]')!.textContent).toBe('Write at least 2 characters.');
        expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0);
    });

    it('asks about the tone first, then posts with confirm_tone and re-renders the discussion', async () => {
        const { calls } = setup({
            'POST /rr/tone-check': () => json(200, { data: { enabled: true, nudge: { message: 'Maybe rephrase?', matches: ['dom'] } } }),
            'POST /rr/subjects/product/\\d+/comments': () => json(201, { data: { id: 99, visibility: 'public', status: 'approved', replies: [] }, message: 'Your comment was posted.' }),
        });
        composer().dataset.toneCheck = '1';
        editorOf(composer()).setContent('<p>Wat een domme vraag</p>');

        composer().requestSubmit();
        const tone = composer().querySelector<HTMLElement>('[data-rr-tone]')!;
        await until(() => !tone.hidden);
        expect(tone.hidden).toBe(false);
        expect(tone.textContent).toContain('Maybe rephrase?');
        expect(calls.some((call) => call.url.endsWith('/comments') && call.method === 'POST')).toBe(false);

        tone.querySelector<HTMLButtonElement>('[data-rr-tone-confirm]')!.click();
        await until(() => calls.some((call) => call.url.endsWith('/comments') && call.method === 'POST'));
        const post = calls.find((call) => call.url.endsWith('/comments') && call.method === 'POST')!;
        expect(post.body).toMatchObject({ body: '<p>Wat een domme vraag</p>', parent_id: null, confirm_tone: true });
        expect(typeof (post.body as { form_started_at: number }).form_started_at).toBe('number');
        await until(() => calls.some((call) => call.method === 'GET' && !call.url.startsWith('/rr/')));
        expect(calls.some((call) => call.method === 'GET' && !call.url.startsWith('/rr/'))).toBe(true);
    });

    it('opens an inline reply composer for the comment', () => {
        setup();
        const reply = discussion().querySelector<HTMLAnchorElement>('[data-rr-reply]')!;
        const id = reply.dataset.rrReply!;

        reply.click();

        const slot = discussion().querySelector(`[data-rr-reply-slot="${id}"]`)!;
        const form = slot.querySelector('[data-rr-composer]')!;
        expect(form.querySelector<HTMLInputElement>('[data-rr-parent]')!.value).toBe(id);
        expect(form.innerHTML).not.toContain('__PARENT__');
        expect(form.innerHTML).not.toContain('__NAME__');
        expect(reply.getAttribute('aria-expanded')).toBe('true');
        expect(form.querySelector('.ProseMirror')).not.toBeNull();
    });

    it('reports through the dialog and requires a reason', async () => {
        const { calls } = setup({ 'POST /rr/reports': () => json(201, { data: { id: 1, status: 'open' }, message: 'Thanks.' }) });
        const report = discussion().querySelector<HTMLButtonElement>('[data-rr-report]')!;
        expect(report).not.toBeNull();

        report.click();
        const dialog = report.closest('[data-rr]')!.querySelector<HTMLDialogElement>('[data-rr-report-dialog]')!;
        const form = dialog.querySelector<HTMLFormElement>('form')!;
        form.requestSubmit();
        await flush();
        expect(dialog.querySelector('[data-rr-error]')!.textContent).toBe('Reason');
        expect(calls.some((call) => call.url === '/rr/reports')).toBe(false);

        form.querySelector<HTMLInputElement>('input[value="spam"]')!.checked = true;
        form.requestSubmit();
        await until(() => calls.some((call) => call.url === '/rr/reports'));
        expect(calls.find((call) => call.url === '/rr/reports')!.body).toMatchObject({ reason: 'spam', reportable_type: report.dataset.rrReport, reportable_id: Number(report.dataset.id) });
    });

    it('filters from the histogram in place: fetches the page and swaps the components', async () => {
        const { calls } = setup();
        const link = document.querySelector<HTMLAnchorElement>('a.rr-histogram__row[data-rr-nav]')!;
        const before = document.querySelector('[data-rr="reviews"]');

        const target = new URL(link.href);

        link.click();
        await until(() => document.querySelector('[data-rr="reviews"]') !== before);

        expect(calls.some((call) => call.method === 'GET' && call.url === target.pathname + target.search)).toBe(true);
        const after = document.querySelector('[data-rr="reviews"]');
        expect(after).not.toBe(before);
        expect(after!.classList.contains('rr-js')).toBe(true);
        expect(window.location.href).toBe(target.href);
    });

    it('runs the live quality meter on the review form with the embedded server rules', () => {
        setup();
        const form = document.querySelector<HTMLFormElement>('[data-rr-review-form]')!;
        const meter = form.querySelector<HTMLElement>('[data-rr-quality]')!;
        expect(meter.hidden).toBe(false);
        const start = Number(meter.querySelector('[data-rr-quality-meter]')!.getAttribute('aria-valuenow'));

        form.querySelector<HTMLInputElement>('input[name="pros[]"]')!.value = 'Romig';
        form.querySelector<HTMLInputElement>('input[name="cons[]"]')!.value = 'Duur';
        form.dispatchEvent(new Event('input', { bubbles: true }));

        const after = Number(meter.querySelector('[data-rr-quality-meter]')!.getAttribute('aria-valuenow'));
        expect(after).toBeGreaterThan(start);
        expect(meter.querySelector('[data-rr-quality-hints]')!.textContent).not.toContain('Add a pro.');
    });
});
