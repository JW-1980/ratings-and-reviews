import { afterEach, describe, expect, it } from 'vitest';
import { createEditor } from '../src/shared';
import * as editorModule from '../src/shared/editor/index.js';

const editors: Array<{ destroy(): void }> = [];

function make(tools: string[], content = '', extra: Record<string, unknown> = {}) {
    const element = document.createElement('div');
    document.body.append(element);
    const handle = createEditor({ element, tools, content, uploadPrefixes: ['/storage/'], stickers: [{ id: 1, name: 'Yum', url: '/storage/stickers/yum.png' }], ...extra });
    editors.push(handle);

    return handle;
}

afterEach(() => {
    editors.splice(0).forEach((editor) => editor.destroy());
    document.body.innerHTML = '';
});

describe('editor built from section settings', () => {
    it('only enables the section tools; markup of other tools is not kept', () => {
        const editor = make(['bold'], '<p><strong>a</strong> <em>b</em> <a href="https://x.y">c</a></p><ul><li>d</li></ul>');

        expect(editor.toolbar.map((item) => item.tool)).toEqual(['bold']);
        expect(editor.getHTML()).toContain('<strong>a</strong>');
        expect(editor.getHTML()).not.toMatch(/<em>|<a |<ul>/);
    });

    it('keeps trust-locked tools out of the editor but lists them in the toolbar', () => {
        const editor = make(['bold', 'link'], '<p><a href="https://x.y">c</a></p>', { lockedTools: { link: 2 } });

        expect(editor.tools).toEqual(['bold']);
        expect(editor.toolbar.find((item) => item.tool === 'link')?.locked).toBe(2);
        expect(editor.getHTML()).not.toContain('<a ');
        expect(editor.setLink('https://example.org')).toBe(false);
    });

    it('links get the forced rel and only safe protocols', () => {
        const editor = make(['link'], '<p><a href="https://example.org">ok</a> <a href="javascript:alert(1)">bad</a></p>');
        const html = editor.getHTML();

        expect(html).toContain('href="https://example.org"');
        expect(html).toContain('rel="nofollow ugc noopener noreferrer"');
        expect(html).not.toContain('javascript:');
    });

    it('images: own uploads only', () => {
        const editor = make(['image'], '<p><img src="https://evil.example/x.png"><img src="/storage/rr/1.jpg" alt="own"></p>');

        expect(editor.getHTML()).toContain('src="/storage/rr/1.jpg"');
        expect(editor.getHTML()).not.toContain('evil.example');
        expect(editor.insertImage({ url: 'https://evil.example/y.png' })).toBe(false);
        expect(editor.insertImage({ url: 'data:image/png;base64,AAAA' })).toBe(false);
        expect(editor.insertImage({ url: '/storage/rr/2.jpg', alt: 'two' })).toBe(true);
        expect(editor.getHTML()).toContain('src="/storage/rr/2.jpg"');
    });

    it('stickers come from the library only', () => {
        const editor = make(['sticker']);

        expect(editor.insertSticker({ url: '/storage/other.png' })).toBe(false);
        expect(editor.insertSticker({ id: 1, url: '/storage/stickers/yum.png' })).toBe(true);
        expect(editor.getHTML()).toMatch(/<img[^>]+src="\/storage\/stickers\/yum.png"/);
    });

    it('product cards are stored as a[data-rr-card="type:id"]', () => {
        const editor = make(['product_card', 'link']);

        expect(editor.insertCard({ type: 'Bad Type', id: 1 })).toBe(false);
        expect(editor.insertCard({ type: 'product', id: 42, title: 'Skyr', url: '/p/42' })).toBe(true);
        expect(editor.getHTML()).toContain('<a data-rr-card="product:42" class="rr-card-ref" href="/p/42">Skyr</a>');
    });

    it('detects a #query before the cursor for the card trigger', () => {
        const editor = make(['product_card']);
        editor.insertText('Probeer #skyr');

        expect(editorModule.cardQueryAt(editor.editor as never)).toMatchObject({ query: 'skyr' });
        editor.insertText(' ');
        expect(editorModule.cardQueryAt(editor.editor as never)).toBeNull();
    });

    it('a quote reply is a blockquote that names the source comment', () => {
        const editor = make(['quote']);

        expect(editor.insertQuote({ commentId: 'x1', text: 'no' })).toBe(false);
        expect(editor.insertQuote({ commentId: 17, text: 'Te zoet voor mij' })).toBe(true);
        expect(editor.getHTML()).toContain('<blockquote data-rr-quote="17" class="rr-quote"><p>Te zoet voor mij</p></blockquote>');

        const reloaded = make(['quote'], editor.getHTML());
        expect(reloaded.getHTML()).toContain('data-rr-quote="17"');
    });

    it('without the quote tool a quote is not inserted (it travels as quoted_comment_id instead)', () => {
        expect(make(['bold']).insertQuote({ commentId: 3, text: 'x' })).toBe(false);
    });

    it('reports empty content as an empty string', () => {
        const editor = make(['bold']);

        expect(editor.isEmpty()).toBe(true);
        expect(editor.getHTML()).toBe('');
    });
});
