/**
 * Client-side HTML cleaning with DOMPurify, before any user HTML is put into the page
 * (`v-html`, `innerHTML`). The server (`HtmlSanitizer`, symfony/html-sanitizer) stays the
 * authority; this is the second line of defence and mirrors its allowlist: the elements of
 * the enabled editor tools, `<a data-rr-card="type:id">`, `<blockquote data-rr-quote="id">`,
 * links with `rel="nofollow ugc noopener noreferrer"` and http(s)/mailto/relative URLs only,
 * and images only from the configured upload and sticker URL prefixes.
 */

import DOMPurify from 'dompurify';

export const LINK_REL = 'nofollow ugc noopener noreferrer';
export const CARD_PATTERN = /^[a-z][a-z0-9_]{0,63}:[A-Za-z0-9-]{1,64}$/;
export const QUOTE_PATTERN = /^[1-9][0-9]{0,19}$/;

/** Elements per editor tool, as `EditorTool::elements()` in PHP. */
export const TOOL_ELEMENTS = {
    bold: ['strong', 'b'],
    italic: ['em', 'i'],
    underline: ['u'],
    strike: ['s', 'del'],
    bullet_list: ['ul', 'li'],
    ordered_list: ['ol', 'li'],
    quote: ['blockquote'],
    heading: ['h3', 'h4'],
    code: ['code', 'pre'],
    link: ['a'],
    product_card: ['a'],
    emoji: [],
    image: ['img'],
    sticker: ['img'],
};

export const ALL_TOOLS = Object.keys(TOOL_ELEMENTS);

/**
 * @param {string} url
 * @param {string[]} prefixes
 */
function startsWithPrefix(url, prefixes) {
    // A network-path reference (`//host/…`, also with backslashes) resolves to another origin.
    if (/^(?:\/\/|\\\\|\/\\|\\)/.test(url)) {
        return false;
    }

    let parsed;

    try {
        const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
        parsed = new URL(url, base);
    } catch {
        return false;
    }

    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return false;
    }

    const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
    const sameOrigin = parsed.origin === base;
    const location = parsed.pathname + parsed.search + parsed.hash;

    // No prefixes configured: only own, same-origin images (never a remote tracker).
    if (prefixes.length === 0) {
        return sameOrigin;
    }

    return prefixes.some((prefix) => {
        if (/^https?:\/\//i.test(prefix)) {
            return parsed.href.startsWith(prefix);
        }

        return sameOrigin && prefix.startsWith('/') && location.startsWith(prefix);
    });
}

/**
 * @typedef {object} SanitizerOptions
 * @property {string[]} [tools] enabled editor tools (default: all package tools)
 * @property {string[]} [mediaPrefixes] URL prefixes images may come from (uploads and stickers; default: any relative or same-origin URL)
 * @property {string[]} [extraTags] for example ['mark'] for search snippets
 * @property {Window} [window] for non-browser environments
 */

/**
 * @param {SanitizerOptions} [options]
 * @returns {(html: string) => string}
 */
export function createSanitizer(options = {}) {
    const tools = new Set(options.tools ?? ALL_TOOLS);
    const prefixes = options.mediaPrefixes ?? [];
    const purifier = DOMPurify(options.window ?? (typeof window !== 'undefined' ? window : undefined));
    const tags = new Set(['p', 'br', ...(options.extraTags ?? [])]);

    tools.forEach((tool) => (TOOL_ELEMENTS[tool] ?? []).forEach((tag) => tags.add(tag)));

    const attributes = [];

    if (tools.has('link')) {
        attributes.push('href', 'title', 'rel');
    }

    if (tools.has('product_card')) {
        attributes.push('data-rr-card');
    }

    if (tools.has('quote')) {
        attributes.push('data-rr-quote');
    }

    if (tools.has('image') || tools.has('sticker')) {
        attributes.push('src', 'alt', 'loading', 'decoding');
    }

    purifier.addHook('afterSanitizeAttributes', (node) => {
        const name = node.nodeName.toLowerCase();

        if (name === 'a') {
            const card = node.getAttribute('data-rr-card');

            if (card !== null && !CARD_PATTERN.test(card)) {
                node.removeAttribute('data-rr-card');
            }

            if (node.hasAttribute('href')) {
                node.setAttribute('rel', LINK_REL);
            } else {
                node.removeAttribute('rel');
            }
        }

        if (name === 'blockquote') {
            const quote = node.getAttribute('data-rr-quote');

            if (quote !== null && !QUOTE_PATTERN.test(quote)) {
                node.removeAttribute('data-rr-quote');
            }
        }

        if (name === 'img') {
            const src = node.getAttribute('src') ?? '';

            if (src === '' || !startsWithPrefix(src, prefixes)) {
                node.remove();

                return;
            }

            node.setAttribute('loading', 'lazy');
            node.setAttribute('decoding', 'async');
        }
    });

    const config = {
        ALLOWED_TAGS: [...tags],
        ALLOWED_ATTR: attributes,
        ALLOW_DATA_ATTR: false,
        // Validated by the hook above; without this DOMPurify reads "product:12" as a URL scheme.
        ADD_URI_SAFE_ATTR: ['data-rr-card', 'data-rr-quote'],
        ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
        FORBID_TAGS: ['style', 'script', 'iframe', 'form', 'input', 'svg', 'math'],
        KEEP_CONTENT: true,
    };

    return (html) => (typeof html === 'string' && html !== '' ? String(purifier.sanitize(html, config)) : '');
}

const defaultSanitizers = new Map();

/**
 * Sanitises with a cached sanitizer per tool set.
 *
 * @param {string} html
 * @param {SanitizerOptions} [options]
 */
export function sanitizeHtml(html, options = {}) {
    const key = JSON.stringify([options.tools ?? null, options.mediaPrefixes ?? null, options.extraTags ?? null]);

    if (!defaultSanitizers.has(key)) {
        defaultSanitizers.set(key, createSanitizer(options));
    }

    return defaultSanitizers.get(key)(html);
}

/**
 * Puts sanitised HTML into an element.
 *
 * @param {Element} element
 * @param {string} html
 * @param {SanitizerOptions} [options]
 */
export function renderSafeHtml(element, html, options = {}) {
    element.innerHTML = sanitizeHtml(html, options);

    return element;
}

/** Escapes text for an HTML context. */
export function escapeHtml(text) {
    return String(text ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/** Plain text of HTML, whitespace collapsed (mirrors HtmlSanitizer::plainText()). */
export function plainText(html) {
    if (!html) {
        return '';
    }

    const spaced = String(html)
        .replace(/<(br|\/p|\/li|\/blockquote|\/h3|\/h4|\/pre)\b[^>]*>/gi, ' $&')
        .replace(/<img\b[^>]*\balt="([^"]*)"[^>]*>/gi, ' $1 ');

    let text;

    if (typeof document !== 'undefined') {
        const template = document.createElement('template');
        template.innerHTML = sanitizeHtml(spaced);
        text = template.content.textContent ?? '';
    } else {
        text = spaced.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
    }

    return text.replace(/\s+/gu, ' ').trim();
}
