/**
 * The package's own Tiptap nodes. Each renders exactly the markup the server sanitizer
 * keeps (see HtmlSanitizer and PackageAttributeSanitizer), so what the editor produces
 * survives saving unchanged.
 */

import { Node, mergeAttributes } from '@tiptap/core';
import { Image } from '@tiptap/extension-image';
import { CARD_PATTERN, QUOTE_PATTERN } from '../sanitize.js';

/**
 * Whether an image URL is one of the allowed sources: relative or same-origin and under
 * one of the prefixes. The server re-checks ownership; this keeps foreign images (pasted
 * or dropped) out of the editor so the author sees what will be saved.
 *
 * @param {string|null|undefined} src
 * @param {string[]} prefixes
 */
export function isAllowedImageSource(src, prefixes) {
    if (!src || prefixes.length === 0) {
        return false;
    }

    if (/^\s*(javascript|data|vbscript|blob):/i.test(src)) {
        return false;
    }

    if (/^https?:\/\//i.test(src) || src.startsWith('//')) {
        if (typeof window === 'undefined') {
            return prefixes.some((prefix) => src.startsWith(prefix));
        }

        try {
            const url = new URL(src, window.location.href);

            if (url.origin !== window.location.origin) {
                return prefixes.some((prefix) => /^https?:\/\//i.test(prefix) && src.startsWith(prefix));
            }

            return prefixes.some((prefix) => url.pathname.startsWith(prefix) || src.startsWith(prefix));
        } catch {
            return false;
        }
    }

    return prefixes.some((prefix) => src.startsWith(prefix));
}

/**
 * Image limited to the author's own uploads (`uploads.url_prefix`). Pasted or dropped
 * images from elsewhere are not parsed; `setImage` with a foreign URL does nothing.
 */
export const RestrictedImage = Image.extend({
    name: 'image',

    addOptions() {
        return {
            ...this.parent?.(),
            inline: true,
            allowBase64: false,
            allowedPrefixes: ['/storage/'],
            HTMLAttributes: {},
        };
    },

    parseHTML() {
        return [{
            tag: 'img[src]',
            getAttrs: (element) => (isAllowedImageSource(element.getAttribute('src'), this.options.allowedPrefixes) && !element.hasAttribute('data-rr-sticker') ? null : false),
        }];
    },

    addCommands() {
        return {
            setImage: (options) => ({ commands }) => {
                if (!isAllowedImageSource(options?.src, this.options.allowedPrefixes)) {
                    return false;
                }

                return commands.insertContent({ type: this.name, attrs: { src: options.src, alt: options.alt ?? null, title: null } });
            },
        };
    },

    addInputRules() {
        return [];
    },
});

/**
 * A sticker from the sticker library: an inline image whose `src` is one of the known
 * sticker URLs (`stickers` option: `[{ id, name, url }]`).
 */
export const Sticker = Node.create({
    name: 'sticker',
    group: 'inline',
    inline: true,
    atom: true,
    draggable: false,
    priority: 1100,

    addOptions() {
        return { stickers: [] };
    },

    addAttributes() {
        return {
            src: { default: null },
            alt: { default: '' },
        };
    },

    parseHTML() {
        const urls = new Set(this.options.stickers.map((sticker) => sticker.url));

        return [{
            tag: 'img[src]',
            getAttrs: (element) => (element.hasAttribute('data-rr-sticker') || urls.has(element.getAttribute('src')) ? { src: element.getAttribute('src'), alt: element.getAttribute('alt') ?? '' } : false),
        }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['img', mergeAttributes({ class: 'rr-sticker', 'data-rr-sticker': '' }, HTMLAttributes)];
    },

    addCommands() {
        return {
            insertSticker: (sticker) => ({ commands }) => {
                const known = this.options.stickers.find((item) => item.url === sticker?.url || item.id === sticker?.id);

                if (!known) {
                    return false;
                }

                return commands.insertContent({ type: this.name, attrs: { src: known.url, alt: known.name ?? '' } });
            },
        };
    },
});

/**
 * An internal product card, stored as `<a data-rr-card="type:id" href="url">title</a>`.
 * Rendered pages turn it into a card (see `hydrateCards`).
 */
export const ProductCard = Node.create({
    name: 'productCard',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,
    priority: 1000,

    addOptions() {
        return { withHref: true };
    },

    addAttributes() {
        return {
            card: { default: null },
            title: { default: '' },
            url: { default: null },
        };
    },

    parseHTML() {
        return [{
            tag: 'a[data-rr-card]',
            getAttrs: (element) => {
                const card = element.getAttribute('data-rr-card');

                return card && CARD_PATTERN.test(card) ? { card, title: element.textContent ?? '', url: element.getAttribute('href') } : false;
            },
        }];
    },

    renderHTML({ node }) {
        const attributes = { 'data-rr-card': node.attrs.card, class: 'rr-card-ref' };

        if (this.options.withHref && node.attrs.url) {
            attributes.href = node.attrs.url;
        }

        return ['a', attributes, node.attrs.title || node.attrs.card];
    },

    renderText({ node }) {
        return node.attrs.title || node.attrs.card;
    },

    addCommands() {
        return {
            insertProductCard: (card) => ({ commands }) => {
                const reference = `${card?.type}:${card?.id}`;

                if (!CARD_PATTERN.test(reference)) {
                    return false;
                }

                return commands.insertContent([
                    { type: this.name, attrs: { card: reference, title: card.title ?? reference, url: card.url ?? null } },
                    { type: 'text', text: ' ' },
                ]);
            },
        };
    },
});

/**
 * A quote of another comment: `<blockquote data-rr-quote="id"><p>text</p></blockquote>`.
 * Takes precedence over the plain blockquote.
 */
export const QuoteReply = Node.create({
    name: 'quoteReply',
    group: 'block',
    content: 'paragraph+',
    defining: true,
    priority: 1000,

    addAttributes() {
        return { commentId: { default: null } };
    },

    parseHTML() {
        return [{
            tag: 'blockquote[data-rr-quote]',
            getAttrs: (element) => {
                const id = element.getAttribute('data-rr-quote');

                return id && QUOTE_PATTERN.test(id) ? { commentId: id } : false;
            },
        }];
    },

    renderHTML({ node }) {
        return ['blockquote', { 'data-rr-quote': String(node.attrs.commentId), class: 'rr-quote' }, 0];
    },

    addCommands() {
        return {
            insertQuoteReply: (quote) => ({ commands }) => {
                const id = String(quote?.commentId ?? '');
                const text = String(quote?.text ?? '').trim();

                if (!QUOTE_PATTERN.test(id) || text === '') {
                    return false;
                }

                return commands.insertContentAt(0, [
                    { type: this.name, attrs: { commentId: id }, content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] },
                    { type: 'paragraph' },
                ]);
            },
        };
    },
});
