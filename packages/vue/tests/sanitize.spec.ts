import { describe, expect, it } from 'vitest';
import { createSanitizer, sanitizeHtml } from '../src/shared';
import RrRichText from '../src/components/RrRichText.vue';
import { fakeApi, flush, mountWithRr } from './helpers';

describe('sanitizing user HTML before it reaches the page', () => {
    const clean = createSanitizer({ mediaPrefixes: ['/storage/'] });

    it('drops scripts, event handlers and styles', () => {
        const html = clean('<p onclick="alert(1)" style="color:red">Hi<script>alert(1)</script></p><img src="/storage/a.jpg" onerror="alert(2)">');

        expect(html).not.toMatch(/script|onclick|onerror|style=/i);
        expect(html).toContain('<p>Hi</p>');
    });

    it('refuses javascript: and data: links but keeps http(s), mailto and relative ones with a forced rel', () => {
        const html = clean('<a href="javascript:alert(1)">x</a><a href="data:text/html,1">y</a><a href="https://example.org" rel="opener">ok</a><a href="/p/1">rel</a><a href="mailto:a@b.c">m</a>');
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const anchors = [...doc.querySelectorAll('a')];

        expect(anchors.filter((a) => a.hasAttribute('href')).map((a) => a.getAttribute('href'))).toEqual(['https://example.org', '/p/1', 'mailto:a@b.c']);
        anchors.filter((a) => a.hasAttribute('href')).forEach((a) => expect(a.getAttribute('rel')).toBe('nofollow ugc noopener noreferrer'));
    });

    it('keeps images only from the upload and sticker prefixes', () => {
        const html = clean('<img src="https://tracker.example/pixel.gif"><img src="/storage/rr/own.jpg" alt="mine"><img src="//evil.example/x.png">');

        expect(html).toContain('src="/storage/rr/own.jpg"');
        expect(html).toContain('loading="lazy"');
        expect(html).not.toContain('tracker.example');
        expect(html).not.toContain('evil.example');
    });

    it('validates the package attributes data-rr-card and data-rr-quote', () => {
        const html = clean('<a data-rr-card="product:12">ok</a><a data-rr-card="Product:../x">bad</a><blockquote data-rr-quote="5">q</blockquote><blockquote data-rr-quote="5;x">b</blockquote>');

        expect(html).toContain('data-rr-card="product:12"');
        expect(html).not.toContain('Product:../x');
        expect(html).toContain('data-rr-quote="5"');
        expect(html).not.toContain('5;x');
    });

    it('unwraps markup of tools that are switched off, keeping the text', () => {
        const html = createSanitizer({ tools: ['bold'] })('<p><strong>a</strong> <em>b</em> <a href="https://x.y">c</a></p><ul><li>d</li></ul>');

        expect(html).toContain('<strong>a</strong>');
        expect(html).not.toMatch(/<em>|<a |<ul>|<li>/);
        expect(html).toContain('b');
        expect(html).toContain('c');
        expect(html).toContain('d');
    });

    it('allows <mark> only where asked (search snippets)', () => {
        expect(sanitizeHtml('<mark>hit</mark>')).toBe('hit');
        expect(sanitizeHtml('<mark>hit</mark>', { extraTags: ['mark'] })).toBe('<mark>hit</mark>');
    });

    it('RrRichText renders the sanitised HTML, never the raw string', async () => {
        const { wrapper } = mountWithRr(RrRichText, { props: { html: '<p>Hello <img src=x onerror=alert(1)><script>bad()</script></p>', cards: false }, rr: { api: fakeApi() } });
        await flush();

        expect(wrapper.html()).toContain('Hello');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        wrapper.unmount();
    });

    it('RrRichText turns a card reference into a card from GET cards/{type}/{id}', async () => {
        const api = fakeApi({ card: () => Promise.resolve({ type: 'product', id: 7, title: 'Yoghurt', url: '/p/7', imageUrl: null, price: { cents: 189, currency: 'EUR' }, score: 4.2, scaleMax: 5 }) });
        const { wrapper } = mountWithRr(RrRichText, { props: { html: '<p><a data-rr-card="product:7">#yog</a></p>' }, rr: { api } });
        await flush();
        await flush();

        expect(api.card).toHaveBeenCalledWith('product', '7');
        const card = wrapper.find('a[data-rr-card]');
        expect(card.classes()).toContain('rr-card');
        expect(card.text()).toContain('Yoghurt');
        expect(card.text()).toContain('1.89');
        wrapper.unmount();
    });
});
