/**
 * @vitest-environment-options {"url": "http://localhost/page"}
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { enhanceRatingsAndReviews } from '../src/shared/blade/index.js';

/**
 * The Blade enhancement on top of the no-JavaScript forms (package form routes on): fixture
 * written by scripts/blade-fixture/BladeFormRoutesRenderTest.php. With JavaScript the same
 * markup talks to the JSON API instead of submitting the forms.
 */
const page = readFileSync(resolve(__dirname, 'fixtures/blade-page-forms.html'), 'utf8');

type Call = { url: string; method: string; body: unknown };

function json(status: number, body: unknown) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function setup(routes: Record<string, (call: Call) => Response> = {}) {
    document.body.innerHTML = page;
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    const calls: Call[] = [];
    vi.stubGlobal('fetch', vi.fn(async (input: string, init: RequestInit = {}) => {
        const url = new URL(input, window.location.href);
        const body = init.body instanceof FormData ? init.body : (init.body ? JSON.parse(String(init.body)) : null);
        const call = { url: url.pathname + url.search, method: init.method ?? 'GET', body };
        calls.push(call);

        for (const [pattern, handler] of Object.entries(routes)) {
            const [method, path] = pattern.split(' ');

            if (call.method === method && new RegExp(`^${path}$`).test(url.pathname)) {
                return handler(call);
            }
        }

        return json(404, { message: 'Not found' });
    }));
    const submitted: string[] = [];
    document.addEventListener('submit', (event) => {
        submitted.push((event.target as HTMLFormElement).action);
        event.preventDefault();
    });
    enhanceRatingsAndReviews(document);

    return { calls, submitted };
}

async function until(check: () => boolean, attempts = 60) {
    for (let attempt = 0; attempt < attempts && !check(); attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
}

function file(name = 'a.jpg') {
    return new File([new Uint8Array([255, 216, 255])], name, { type: 'image/jpeg' });
}

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('Blade forms with JavaScript', () => {
    it('a vote button inside its no-JS form votes through the API and does not submit the form', async () => {
        const { calls, submitted } = setup({ 'POST /rr/comments/\\d+/votes': () => json(200, { data: { dimension: 'agreement', value: 1, counted: true, count: { up: 1, down: 0 }, counts_by_dimension: { agreement: { up: 1, down: 0 } }, counts: { agree_count: 1, disagree_count: 0 } } }) });
        const button = document.querySelector<HTMLButtonElement>('[data-rr="discussion"] form.rr-inline-form .rr-vote--agreement.rr-vote--up')!;
        expect(button.type).toBe('submit');

        button.click();
        await until(() => button.getAttribute('aria-pressed') === 'true');

        expect(calls.filter((call) => call.method === 'POST').map((call) => call.url)).toEqual([expect.stringMatching(/^\/rr\/comments\/\d+\/votes$/)]);
        expect(submitted).toEqual([]);
        expect(button.querySelector('.rr-vote__count')?.textContent).toBe('1');
    });

    it('a poll option submits through the API, not the form route', async () => {
        const { calls, submitted } = setup({ 'POST /rr/polls/\\d+/votes': () => json(200, { data: { id: 1, question: 'Warm of koud?', options: [{ id: 1, label: 'Warm', votes: 1 }, { id: 2, label: 'Koud', votes: 0 }], total_votes: 1, viewer_option_id: 1 }, meta: { counted: true, changed: true } }) });
        document.querySelector<HTMLButtonElement>('[data-rr-poll-option]')!.click();
        await until(() => calls.some((call) => call.url.includes('/polls/')));

        expect(calls.find((call) => call.url.includes('/polls/'))?.body).toEqual({ option_id: expect.any(Number) });
        expect(submitted).toEqual([]);
    });

    it('review photos are uploaded at once and sent as ids with the review', async () => {
        let next = 40;
        const { calls } = setup({
            'POST /rr/uploads': () => json(201, { data: { id: ++next, url: `/storage/rr-uploads/${next}.jpg`, alt: null, width: 60, height: 60 } }),
            'POST /rr/subjects/product/\\d+/reviews': () => json(201, { data: { id: 9, status: 'approved' }, message: 'Thanks', quality: null }),
        });
        const form = document.querySelector<HTMLFormElement>('[data-rr-review-form]')!;
        const input = form.querySelector<HTMLInputElement>('[data-rr-photo-files]')!;
        Object.defineProperty(input, 'files', { value: [file('a.jpg'), file('b.jpg')], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await until(() => form.querySelectorAll('input[name="photos[]"]').length === 2);

        const uploads = calls.filter((call) => call.url === '/rr/uploads');
        expect(uploads).toHaveLength(2);
        expect((uploads[0].body as FormData).get('image')).toBeInstanceOf(File);
        expect(form.querySelector('[data-rr-photo-status]')?.textContent).toBe('Photo added.');

        // Untick the first photo, choose a score and submit: only the second id is sent.
        form.querySelector<HTMLInputElement>('input[name="photos[]"]')!.checked = false;
        form.querySelector<HTMLInputElement>('input[name="rating"][value="5"]')!.checked = true;
        form.requestSubmit();
        await until(() => calls.some((call) => call.method === 'POST' && call.url.endsWith('/reviews')));

        const review = calls.find((call) => call.method === 'POST' && call.url.endsWith('/reviews'))!;
        expect((review.body as { photos: number[] }).photos).toEqual([42]);
    });

    it('refuses more photos than the section allows, with the server message for a refused file', async () => {
        const { calls } = setup({ 'POST /rr/uploads': () => json(422, { message: 'Invalid', errors: { image: ['The image must be a file of type: image/jpeg.'] } }) });
        const box = document.querySelector<HTMLElement>('[data-rr-photos]')!;
        const input = box.querySelector<HTMLInputElement>('[data-rr-photo-files]')!;
        Object.defineProperty(input, 'files', { value: [file()], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await until(() => box.querySelector('[data-rr-field-error]')?.textContent !== '');

        expect(box.querySelector('[data-rr-field-error]')?.textContent).toBe('The image must be a file of type: image/jpeg.');
        expect(box.querySelectorAll('input[name="photos[]"]')).toHaveLength(0);
        expect(calls).toHaveLength(1);
    });

    it('the editor loads stickers from the API and uploads images to POST uploads by default', async () => {
        const { calls } = setup({
            'GET /rr/stickers': () => json(200, { data: [{ id: 1, name: 'Blij', url: '/storage/rr-stickers/blij.png' }] }),
            'POST /rr/uploads': () => json(201, { data: { id: 5, url: '/storage/rr-uploads/5.jpg', alt: null, width: 60, height: 60 } }),
        });
        const editor = document.querySelector<HTMLElement>('[data-rr="discussion"] [data-rr-composer] [data-rr-editor]')!;

        editor.querySelector<HTMLButtonElement>('.rr-toolbar-button--sticker')!.click();
        await until(() => editor.parentElement!.querySelector('.rr-picker--stickers button[aria-label="Blij"]') !== null);
        editor.parentElement!.querySelector<HTMLButtonElement>('.rr-picker--stickers button[aria-label="Blij"]')!.click();
        expect(editor.querySelector('textarea')!.value).toContain('src="/storage/rr-stickers/blij.png"');

        const fileInput = editor.querySelector<HTMLInputElement>('input[type="file"]')!;
        Object.defineProperty(fileInput, 'files', { value: [file()], configurable: true });
        fileInput.dispatchEvent(new Event('change'));
        await until(() => editor.querySelector('textarea')!.value.includes('/storage/rr-uploads/5.jpg'));

        expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['GET /rr/stickers', 'POST /rr/uploads']);
    });
});
