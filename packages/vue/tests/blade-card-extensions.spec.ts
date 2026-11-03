/**
 * @vitest-environment-options {"url": "http://localhost/page"}
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CARD_EXTENSION_POSITIONS, enhanceRatingsAndReviews, registerCardExtension } from '../src/shared/blade/index.js';

/**
 * The browser hook of the Blade card extensions against real server output:
 * fixtures/blade-page-extensions.html and -2.html (written by
 * scripts/blade-fixture/BladeComponentsRenderTest.php with a host reactions bar registered
 * through Support\CardExtensions on reviews and comments). The cards are always rendered by
 * the server; the hook binds host behaviour to them on the first render, after every
 * re-render (posting, sorting, paging) and after "load more".
 */
const page = readFileSync(resolve(__dirname, 'fixtures/blade-page-extensions.html'), 'utf8');
const pageTwo = readFileSync(resolve(__dirname, 'fixtures/blade-page-extensions-2.html'), 'utf8');

type Item = { position: string; type: string; id: number; subject: { type: string | null; id: string | null } | null; card: Element | null };

const unregister: Array<() => void> = [];

function setup(options: Record<string, unknown> = {}) {
    document.body.innerHTML = page;
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    const fetch = vi.fn(async (input: string) => {
        const url = new URL(input, window.location.href);

        return new Response(url.searchParams.get('rr_cpage') === '2' ? pageTwo : page, { status: 200, headers: { 'Content-Type': 'text/html' } });
    });
    vi.stubGlobal('fetch', fetch);
    enhanceRatingsAndReviews(document, options);

    return fetch;
}

/** A host reactions bar: binds a click handler to the server-rendered button. */
function reactionsHook(seen: Array<[Item, HTMLElement]>) {
    return (item: Item, element: HTMLElement) => {
        seen.push([item, element]);
        const button = element.querySelector<HTMLButtonElement>('.host-reactions');

        if (button) {
            button.dataset.bound = '1';
            button.addEventListener('click', () => (button.dataset.clicked = String(Number(button.dataset.clicked ?? 0) + 1)));
        }
    };
}

async function until(check: () => boolean, attempts = 60) {
    for (let attempt = 0; attempt < attempts && !check(); attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
}

const extensions = (position: string) => [...document.querySelectorAll<HTMLElement>(`[data-rr-extension="${position}"]`)];

describe('Blade: card extensions', () => {
    afterEach(() => {
        unregister.splice(0).forEach((off) => off());
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('runs the hooks from the options for every server-rendered extension with the item and the element', () => {
        const reviews: Array<[Item, HTMLElement]> = [];
        const comments: Array<[Item, HTMLElement]> = [];
        const reviewHook = reactionsHook(reviews);
        const commentHook = reactionsHook(comments);
        setup({ cardExtensions: { 'review.footer': reviewHook, 'comment.footer': [commentHook] } });
        unregister.push(registerCardExtension('review.footer', reviewHook), registerCardExtension('comment.footer', commentHook));

        // Registering the same callback again (as above) does not run it twice per element.
        expect(reviews).toHaveLength(1);
        const [item, element] = reviews[0];
        expect(item).toMatchObject({ position: 'review.footer', type: 'review', subject: { type: 'product' } });
        expect(item.id).toBe(Number(element.dataset.rrItemId));
        expect(item.card).toBe(element.closest('[data-rr-review]'));
        expect(element.closest('.rr-review__footer')).not.toBeNull();
        expect(element.querySelector('.host-reactions')?.textContent).toBe('👍 3');

        expect(comments.map(([entry]) => entry.id)).toEqual(extensions('comment.footer').map((node) => Number(node.dataset.rrItemId)));
        expect(comments).toHaveLength(3);
        comments.forEach(([entry, node]) => expect(entry.card).toBe(node.closest('[data-rr-comment]')));

        const button = extensions('comment.footer')[0].querySelector<HTMLButtonElement>('.host-reactions')!;
        button.click();
        expect(button.dataset.clicked).toBe('1');
    });

    it('applies a hook registered after the enhancement to the cards already on the page', () => {
        setup();
        const seen: Array<[Item, HTMLElement]> = [];
        unregister.push(registerCardExtension('review.meta', reactionsHook(seen)));

        expect(seen).toHaveLength(1);
        expect(seen[0][1].textContent).toBe('Kudos <3');
        expect(seen[0][1].tagName).toBe('SPAN');
    });

    it('binds the hook again to the cards the server renders after a re-render (sort, filter, post)', async () => {
        const seen: Array<[Item, HTMLElement]> = [];
        const fetch = setup();
        unregister.push(registerCardExtension('comment.footer', reactionsHook(seen)));
        const first = extensions('comment.footer');
        expect(seen).toHaveLength(3);

        const form = document.querySelector<HTMLFormElement>('[data-rr="discussion"] form.rr-toolbar')!;
        form.requestSubmit();
        await until(() => seen.length === 6);

        expect(fetch).toHaveBeenCalled();
        const fresh = extensions('comment.footer');
        expect(fresh).toHaveLength(3);
        fresh.forEach((node) => {
            expect(first).not.toContain(node);
            expect(node.querySelector<HTMLElement>('.host-reactions')?.dataset.bound).toBe('1');
        });
        expect(seen.slice(3).map(([, node]) => node)).toEqual(fresh);
    });

    it('binds the hook to the comments that "load more" brings in', async () => {
        const seen: Array<[Item, HTMLElement]> = [];
        setup();
        unregister.push(registerCardExtension('comment.footer', reactionsHook(seen)));
        expect(seen).toHaveLength(3);

        document.querySelector<HTMLAnchorElement>('[data-rr-more]')!.click();
        await until(() => seen.length === 4);

        expect(seen).toHaveLength(4);
        const [item, element] = seen[3];
        expect(item.type).toBe('comment');
        expect(element.isConnected).toBe(true);
        expect(element.closest('[data-rr-comment]')?.getAttribute('data-rr-comment')).toBe(String(item.id));
        expect(element.querySelector<HTMLElement>('.host-reactions')?.dataset.bound).toBe('1');
        expect(extensions('comment.footer')).toHaveLength(4);
    });

    it('logs a failing hook and still runs the others; an unknown position is an error', () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const seen: Array<[Item, HTMLElement]> = [];
        setup();
        unregister.push(registerCardExtension('comment.footer', () => {
            throw new Error('host bug');
        }));
        unregister.push(registerCardExtension('comment.footer', reactionsHook(seen)));

        expect(error).toHaveBeenCalledTimes(3);
        expect(seen).toHaveLength(3);
        expect(() => registerCardExtension('review.sidebar', () => undefined)).toThrow(/Unknown card extension position "review.sidebar"/);
        expect(() => registerCardExtension('review.footer', 'nope' as never)).toThrow(TypeError);
        expect(CARD_EXTENSION_POSITIONS).toEqual(['review.meta', 'review.footer', 'comment.meta', 'comment.footer']);
    });

    it('stops calling a hook after it was unregistered', async () => {
        const seen: Array<[Item, HTMLElement]> = [];
        setup();
        const off = registerCardExtension('comment.footer', reactionsHook(seen));
        expect(seen).toHaveLength(3);
        off();

        document.querySelector<HTMLAnchorElement>('[data-rr-more]')!.click();
        await until(() => extensions('comment.footer').length === 4);

        expect(extensions('comment.footer')).toHaveLength(4);
        expect(seen).toHaveLength(3);
    });
});
