/**
 * Tiptap editor factory, built from a section's settings (`editor_tools`, plus the tools a
 * viewer's trust level still locks). Framework-agnostic: the Blade enhancer and the Vue
 * components both call `createEditor()` and render the toolbar from `toolbarItems()`.
 */

import { Editor } from '@tiptap/core';
import { StarterKit } from '@tiptap/starter-kit';
import { LINK_REL } from '../sanitize.js';
import { ProductCard, QuoteReply, RestrictedImage, Sticker } from './extensions.js';

export { ProductCard, QuoteReply, RestrictedImage, Sticker, isAllowedImageSource } from './extensions.js';

/** Toolbar order. */
export const TOOL_ORDER = ['bold', 'italic', 'underline', 'strike', 'heading', 'bullet_list', 'ordered_list', 'quote', 'code', 'link', 'emoji', 'image', 'sticker', 'product_card'];

/** Keyboard shortcuts Tiptap binds for the toggle tools (shown in tooltips). */
const SHORTCUTS = { bold: 'Mod-B', italic: 'Mod-I', underline: 'Mod-U', strike: 'Mod-Shift-S', code: 'Mod-E', bullet_list: 'Mod-Shift-8', ordered_list: 'Mod-Shift-7', quote: 'Mod-Shift-B' };

/** A small default emoji set; hosts pass their own with `emojis`. */
export const DEFAULT_EMOJIS = ['😀', '😂', '😍', '🤔', '😮', '😢', '😡', '👍', '👎', '👏', '🙏', '💪', '🔥', '⭐', '❤️', '✅', '❌', '🍽️', '🥗', '🍫', '☕', '🧀', '🍓', '🌱'];

/**
 * The tools the editor actually enables: the section's tools minus those locked by trust level.
 *
 * @param {string[]} tools
 * @param {Record<string, number>} [locked] tool => contributions required
 */
export function effectiveTools(tools, locked = {}) {
    return TOOL_ORDER.filter((tool) => tools.includes(tool) && !(tool in locked && locked[tool] > 0));
}

/**
 * Toolbar descriptors for a tool list. `locked` tools are listed (disabled, with the number
 * of contributions still needed) so the viewer knows they exist.
 *
 * @param {string[]} tools section tools
 * @param {Record<string, number>} [locked]
 */
export function toolbarItems(tools, locked = {}) {
    const toggle = {
        bold: { run: (e) => e.chain().focus().toggleBold().run(), active: (e) => e.isActive('bold') },
        italic: { run: (e) => e.chain().focus().toggleItalic().run(), active: (e) => e.isActive('italic') },
        underline: { run: (e) => e.chain().focus().toggleUnderline().run(), active: (e) => e.isActive('underline') },
        strike: { run: (e) => e.chain().focus().toggleStrike().run(), active: (e) => e.isActive('strike') },
        heading: { run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run(), active: (e) => e.isActive('heading') },
        bullet_list: { run: (e) => e.chain().focus().toggleBulletList().run(), active: (e) => e.isActive('bulletList') },
        ordered_list: { run: (e) => e.chain().focus().toggleOrderedList().run(), active: (e) => e.isActive('orderedList') },
        quote: { run: (e) => e.chain().focus().toggleBlockquote().run(), active: (e) => e.isActive('blockquote') },
        code: { run: (e) => e.chain().focus().toggleCode().run(), active: (e) => e.isActive('code') },
    };
    const kinds = { link: 'link', emoji: 'emoji', image: 'image', sticker: 'sticker', product_card: 'card' };

    return TOOL_ORDER.filter((tool) => tools.includes(tool)).map((tool) => ({
        tool,
        label: `editor.tool.${tool}`,
        kind: kinds[tool] ?? 'toggle',
        shortcut: SHORTCUTS[tool] ?? null,
        run: toggle[tool]?.run ?? null,
        active: toggle[tool]?.active ?? (tool === 'link' ? (e) => e.isActive('link') : () => false),
        locked: tool in locked && locked[tool] > 0 ? locked[tool] : null,
    }));
}

/**
 * Finds a `#query` right before the cursor (product card trigger).
 *
 * @param {Editor} editor
 * @returns {{ query: string, from: number, to: number }|null}
 */
export function cardQueryAt(editor) {
    const { state } = editor;
    const { $from, empty } = state.selection;

    if (!empty || !$from.parent.isTextblock) {
        return null;
    }

    const before = $from.parent.textBetween(0, $from.parentOffset, undefined, '￼');
    const match = before.match(/(?:^|\s)#([^\s#￼]{1,60})$/u);

    if (!match) {
        return null;
    }

    const length = match[1].length + 1;

    return { query: match[1], from: $from.pos - length, to: $from.pos };
}

/**
 * Builds the extension list for a tool set.
 *
 * @param {string[]} tools enabled tools (already without locked ones)
 * @param {{ uploadPrefixes?: string[], stickers?: Array<{id: string|number, name: string, url: string}>, getStickers?: () => Array<{id: string|number, name: string, url: string}> }} [media]
 */
export function extensionsFor(tools, media = {}) {
    const on = (tool) => tools.includes(tool);
    const extensions = [
        StarterKit.configure({
            bold: on('bold') ? {} : false,
            italic: on('italic') ? {} : false,
            underline: on('underline') ? {} : false,
            strike: on('strike') ? {} : false,
            heading: on('heading') ? { levels: [3, 4] } : false,
            bulletList: on('bullet_list') ? {} : false,
            orderedList: on('ordered_list') ? {} : false,
            listItem: on('bullet_list') || on('ordered_list') ? {} : false,
            listKeymap: on('bullet_list') || on('ordered_list') ? {} : false,
            blockquote: on('quote') ? {} : false,
            code: on('code') ? {} : false,
            codeBlock: on('code') ? {} : false,
            horizontalRule: false,
            link: on('link') ? {
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                defaultProtocol: 'https',
                protocols: ['http', 'https', 'mailto'],
                isAllowedUri: (url, ctx) => /^(https?:|mailto:|\/(?!\/)|#)/i.test(url) && ctx.defaultValidate(url),
                HTMLAttributes: { rel: LINK_REL, target: null, class: null },
            } : false,
        }),
    ];

    if (on('image')) {
        extensions.push(RestrictedImage.configure({ allowedPrefixes: media.uploadPrefixes ?? ['/storage/'] }));
    }

    if (on('sticker')) {
        extensions.push(Sticker.configure({ stickers: media.stickers ?? [], getStickers: media.getStickers ?? null }));
    }

    if (on('product_card')) {
        extensions.push(ProductCard.configure({ withHref: on('link') }));
    }

    if (on('quote')) {
        extensions.push(QuoteReply);
    }

    return extensions;
}

/**
 * @typedef {object} EditorOptions
 * @property {HTMLElement} element mount point
 * @property {string[]} tools the section's `editor_tools`
 * @property {Record<string, number>} [lockedTools] tool => contributions required (trust levels)
 * @property {string} [content] initial HTML
 * @property {string} [label] accessible name of the editing area
 * @property {string} [describedBy] id of a help or error element
 * @property {string[]} [uploadPrefixes]
 * @property {Array<{id: string|number, name: string, url: string}>} [stickers]
 * @property {() => Array<{id: string|number, name: string, url: string}>} [getStickers] the library loaded later (GET stickers)
 * @property {boolean} [editable]
 * @property {(state: { html: string, text: string, isEmpty: boolean }) => void} [onUpdate]
 * @property {(query: { query: string, from: number, to: number }|null) => void} [onCardQuery]
 * @property {(event: KeyboardEvent) => boolean} [onKeyDown] return true when handled (card listbox)
 * @property {() => void} [onSubmitShortcut] Mod-Enter
 * @property {(state: { focused: boolean }) => void} [onFocusChange]
 */

/**
 * @param {EditorOptions} options
 */
export function createEditor(options) {
    const tools = effectiveTools(options.tools ?? [], options.lockedTools ?? {});
    let lastQuery = null;

    const emitQuery = (editor) => {
        if (!options.onCardQuery || !tools.includes('product_card')) {
            return;
        }

        const query = cardQueryAt(editor);
        const key = query ? `${query.from}:${query.query}` : null;

        if (key !== lastQuery) {
            lastQuery = key;
            options.onCardQuery(query);
        }
    };

    const editor = new Editor({
        element: options.element,
        extensions: extensionsFor(tools, { uploadPrefixes: options.uploadPrefixes, stickers: options.stickers, getStickers: options.getStickers }),
        content: options.content ?? '',
        editable: options.editable ?? true,
        editorProps: {
            attributes: {
                class: 'rr-editor__content',
                role: 'textbox',
                'aria-multiline': 'true',
                ...(options.label ? { 'aria-label': options.label } : {}),
                ...(options.describedBy ? { 'aria-describedby': options.describedBy } : {}),
            },
            handleKeyDown: (_view, event) => {
                if (options.onKeyDown?.(event)) {
                    return true;
                }

                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && options.onSubmitShortcut) {
                    options.onSubmitShortcut();

                    return true;
                }

                return false;
            },
        },
        onUpdate: ({ editor: current }) => {
            options.onUpdate?.({ html: current.isEmpty ? '' : current.getHTML(), text: current.getText(), isEmpty: current.isEmpty });
            emitQuery(current);
        },
        onSelectionUpdate: ({ editor: current }) => emitQuery(current),
        onFocus: () => options.onFocusChange?.({ focused: true }),
        onBlur: () => options.onFocusChange?.({ focused: false }),
    });

    return {
        editor,
        tools,
        toolbar: toolbarItems(options.tools ?? [], options.lockedTools ?? {}),
        getHTML: () => (editor.isEmpty ? '' : editor.getHTML()),
        getText: () => editor.getText(),
        isEmpty: () => editor.isEmpty,
        focus: () => editor.commands.focus(),
        clear: () => editor.commands.clearContent(true),
        setContent: (html) => editor.commands.setContent(html ?? '', { emitUpdate: true }),
        /** @param {{ commentId: string|number, text: string }} quote */
        insertQuote: (quote) => (tools.includes('quote') ? editor.chain().focus().insertQuoteReply(quote).run() : false),
        /** @param {{ type: string, id: string|number, title?: string, url?: string|null }} card */
        insertCard: (card, range = null) => {
            if (!tools.includes('product_card')) {
                return false;
            }

            const chain = editor.chain().focus();

            if (range) {
                chain.deleteRange({ from: range.from, to: range.to });
            }

            lastQuery = null;

            return chain.insertProductCard(card).run();
        },
        insertSticker: (sticker) => (tools.includes('sticker') ? editor.chain().focus().insertSticker(sticker).run() : false),
        /** @param {{ url: string, alt?: string }} image */
        insertImage: (image) => (tools.includes('image') ? editor.chain().focus().setImage({ src: image.url, alt: image.alt ?? '' }).run() : false),
        insertText: (text) => editor.chain().focus().insertContent(text).run(),
        /** Sets or removes (empty url) a link on the selection. */
        setLink: (url) => {
            if (!tools.includes('link')) {
                return false;
            }

            const chain = editor.chain().focus().extendMarkRange('link');

            return url ? chain.setLink({ href: url }).run() : chain.unsetLink().run();
        },
        currentLink: () => editor.getAttributes('link').href ?? '',
        destroy: () => editor.destroy(),
    };
}

/** @typedef {ReturnType<typeof createEditor>} RrEditor */
