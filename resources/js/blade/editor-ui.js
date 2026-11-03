/**
 * Mounts the shared Tiptap editor (../editor/index.js) on `[data-rr-editor]`: the
 * toolbar is built from the section tools, the textarea stays in the form (hidden) and
 * always holds the current HTML, so a normal form submit and the API submit read the same
 * value. Keyboard: the toolbar is one tab stop (arrow keys, Home, End), Escape closes a
 * panel, Ctrl/Cmd+Enter submits the form.
 */

import { DEFAULT_EMOJIS, createEditor } from '../editor/index.js';
import { formatMoney } from '../format.js';
import { el, uniqueId } from './dom.js';

const CARD_REFERENCE = /^[a-z][a-z0-9_]{0,63}:[A-Za-z0-9-]{1,64}$/;

/**
 * The message for a failed upload: the server's (size, type, quota) when it sent one, else
 * a generic line (network errors carry no translated text).
 */
export function uploadErrorText(failure, t) {
    const field = failure?.errors?.image?.[0] ?? failure?.errors?.file?.[0] ?? null;

    if (field) {
        return field;
    }

    if (failure?.status === 429) {
        // The daily quota sends its own line (Retry-After in hours); the per-minute throttle does not.
        return (failure.retryAfter ?? 0) > 60 && failure.message ? failure.message : t('common.throttled', { seconds: failure.retryAfter ?? 60 });
    }

    if (failure?.status === 401) {
        return t('common.login_required');
    }

    return failure?.status && failure.status !== 0 && failure.message ? failure.message : t('editor.image_failed');
}

/**
 * @param {HTMLElement} wrapper `[data-rr-editor]`
 * @param {{ t: Function, tc: Function, api: object, locale: string, mediaPrefixes: string[], uploadImage?: Function, searchCards?: Function, stickers?: Array, emojis?: string[] }} env
 */
export function mountEditor(wrapper, env) {
    if (wrapper.rrEditor) {
        return wrapper.rrEditor;
    }

    const textarea = wrapper.querySelector('textarea');
    const parse = (value, fallback) => {
        try {
            return JSON.parse(value ?? '') ?? fallback;
        } catch {
            return fallback;
        }
    };
    const tools = parse(wrapper.dataset.tools, []);
    const locked = parse(wrapper.dataset.locked, {});
    const label = wrapper.dataset.label || env.t('editor.label');
    const id = uniqueId('rr-editor');
    const surface = el('div', { class: 'rr-editor__surface', id: `${id}-content`, 'data-placeholder': wrapper.dataset.placeholder || null });
    const status = el('p', { class: 'rr-field-error', role: 'alert', hidden: true });
    const cardList = el('div', { class: 'rr-editor__cards', id: `${id}-cards`, role: 'listbox', 'aria-label': env.t('editor.card_results'), hidden: true });
    let panel = null;
    let cardQuery = null;
    let cardResults = [];
    let cardIndex = 0;
    let cardTimer = null;
    let cardRequest = 0;

    const showStatus = (text) => {
        status.textContent = text || '';
        status.hidden = !text;
    };

    const closePanel = () => {
        panel?.remove();
        panel = null;
        toolbar?.querySelectorAll('[aria-expanded]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    };

    const syncCombobox = () => {
        const dom = handle.editor.view.dom;

        if (cardQuery) {
            dom.setAttribute('aria-expanded', 'true');
            dom.setAttribute('aria-controls', cardList.id);
            dom.setAttribute('aria-autocomplete', 'list');
            cardResults[cardIndex] ? dom.setAttribute('aria-activedescendant', `${id}-card-${cardIndex}`) : dom.removeAttribute('aria-activedescendant');
        } else {
            ['aria-expanded', 'aria-controls', 'aria-autocomplete', 'aria-activedescendant'].forEach((name) => dom.removeAttribute(name));
        }
    };

    const renderCards = (loading = false) => {
        cardList.replaceChildren();
        cardList.hidden = !cardQuery;

        if (!cardQuery) {
            syncCombobox();

            return;
        }

        if (loading && !cardResults.length) {
            cardList.append(el('p', { class: 'rr-help', text: env.t('editor.card_loading') }));
        } else if (!cardResults.length) {
            cardList.append(el('p', { class: 'rr-help', text: env.searchCards ? env.t('editor.card_empty') : env.t('editor.card_hint') }));
        }

        cardResults.forEach((card, index) => {
            const option = el('div', {
                id: `${id}-card-${index}`,
                role: 'option',
                class: `rr-editor__card-option${index === cardIndex ? ' is-active' : ''}`,
                'aria-selected': index === cardIndex ? 'true' : 'false',
            }, [
                card.imageUrl ? el('img', { src: card.imageUrl, alt: '', class: 'rr-card__image', loading: 'lazy' }) : null,
                el('span', { class: 'rr-card__title', text: card.title }),
                card.price ? el('span', { class: 'rr-card__meta', text: formatMoney(card.price, env.locale) }) : null,
            ]);
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                chooseCard(card);
            });
            cardList.append(option);
        });

        syncCombobox();
    };

    const chooseCard = (card) => {
        handle.insertCard(card, cardQuery);
        cardQuery = null;
        cardResults = [];
        renderCards();
    };

    const searchCards = async (query) => {
        const request = ++cardRequest;
        renderCards(true);

        try {
            let results = [];

            if (env.searchCards) {
                results = await env.searchCards(query);
            } else if (CARD_REFERENCE.test(query)) {
                const [type, cardId] = query.split(':');
                const card = await env.api.card(type, cardId);
                results = card ? [card] : [];
            }

            if (request === cardRequest) {
                cardResults = (results ?? []).slice(0, 8);
                cardIndex = 0;
            }
        } catch {
            if (request === cardRequest) {
                cardResults = [];
            }
        }

        if (request === cardRequest) {
            renderCards();
        }
    };

    const onKeyDown = (event) => {
        if (!cardQuery) {
            return false;
        }

        if (event.key === 'Escape') {
            cardQuery = null;
            renderCards();

            return true;
        }

        if (!cardResults.length) {
            return false;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            cardIndex = (cardIndex + (event.key === 'ArrowDown' ? 1 : -1) + cardResults.length) % cardResults.length;
            renderCards();

            return true;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            chooseCard(cardResults[cardIndex]);

            return true;
        }

        return false;
    };

    textarea.hidden = true;
    textarea.setAttribute('aria-hidden', 'true');
    textarea.tabIndex = -1;
    wrapper.append(surface, cardList, status);

    const handle = createEditor({
        element: surface,
        tools,
        lockedTools: locked,
        content: textarea.value,
        label,
        describedBy: wrapper.dataset.describedBy || textarea.getAttribute('aria-describedby') || undefined,
        uploadPrefixes: env.mediaPrefixes,
        stickers: env.stickers ?? [],
        getStickers: env.getStickers,
        onUpdate: ({ html, text }) => {
            textarea.value = html;
            wrapper.dispatchEvent(new CustomEvent('rr:input', { bubbles: true, detail: { html, text } }));
            refreshToolbar();
        },
        onCardQuery: (query) => {
            cardQuery = query;
            clearTimeout(cardTimer);

            if (query) {
                cardTimer = setTimeout(() => searchCards(query.query), 200);
            } else {
                cardResults = [];
            }

            renderCards(Boolean(query));
        },
        onKeyDown,
        onSubmitShortcut: () => wrapper.closest('form')?.requestSubmit?.(),
    });

    if (textarea.getAttribute('aria-invalid') === 'true') {
        handle.editor.view.dom.setAttribute('aria-invalid', 'true');
    }

    // ---- toolbar ----
    const toolbar = handle.toolbar.length ? el('div', { class: 'rr-editor__toolbar', role: 'toolbar', 'aria-label': env.t('editor.toolbar'), 'aria-controls': surface.id }) : null;

    const press = (item, button) => {
        if (item.locked !== null) {
            showStatus(env.tc('editor.locked', item.locked, { tool: env.t(item.label) }));

            return;
        }

        showStatus('');

        if (item.kind === 'toggle') {
            item.run(handle.editor);
        } else if (item.kind === 'card') {
            handle.insertText('#');
        } else if (item.kind === 'image') {
            if (!env.uploadImage) {
                showStatus(env.t('editor.image_unavailable'));

                return;
            }

            fileInput.click();
        } else {
            const open = panel?.dataset.kind === item.kind;
            closePanel();

            if (!open) {
                panel = buildPanel(item.kind);
                panel.dataset.kind = item.kind;
                (toolbar ?? wrapper).after(panel);
                button.setAttribute('aria-expanded', 'true');
                panel.querySelector('input, button')?.focus();
            }
        }

        refreshToolbar();
    };

    const buildPanel = (kind) => {
        if (kind === 'link') {
            const inputId = uniqueId('rr-link');
            const input = el('input', { id: inputId, type: 'url', inputmode: 'url', class: 'rr-input', placeholder: 'https://', value: handle.currentLink() });
            const error = el('p', { class: 'rr-field-error', hidden: true, id: `${inputId}-error` });
            const apply = () => {
                const url = input.value.trim();

                if (url !== '' && !/^(https?:\/\/|mailto:|\/(?!\/))/i.test(url)) {
                    error.textContent = env.t('editor.link_invalid');
                    error.hidden = false;
                    input.setAttribute('aria-invalid', 'true');
                    input.setAttribute('aria-describedby', error.id);

                    return;
                }

                handle.setLink(url);
                closePanel();
            };
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    apply();
                }
            });

            return el('div', { class: 'rr-editor__panel', role: 'group', 'aria-label': env.t('editor.tool.link') }, [
                el('label', { for: inputId, class: 'rr-label', text: env.t('editor.link_url') }),
                el('div', { class: 'rr-input-row' }, [
                    input,
                    el('button', { type: 'button', class: 'rr-button rr-button--small rr-button--primary', text: env.t('editor.link_apply'), onclick: apply }),
                    handle.currentLink() ? el('button', { type: 'button', class: 'rr-button rr-button--small rr-button--ghost', text: env.t('editor.link_remove'), onclick: () => { handle.setLink(''); closePanel(); } }) : null,
                ]),
                error,
            ]);
        }

        if (kind === 'emoji') {
            return el('div', { class: 'rr-editor__panel rr-picker', role: 'group', 'aria-label': env.t('editor.emojis') }, (env.emojis ?? DEFAULT_EMOJIS).map((emoji) =>
                el('button', { type: 'button', class: 'rr-picker__item', 'aria-label': emoji, text: emoji, onclick: () => { handle.insertText(emoji); closePanel(); } })));
        }

        const stickerButtons = (stickers) => stickers.map((sticker) =>
            el('button', { type: 'button', class: 'rr-picker__item', 'aria-label': sticker.name, onclick: () => { handle.insertSticker(sticker); closePanel(); } }, [el('img', { src: sticker.url, alt: '', loading: 'lazy' })]));
        const known = env.stickers?.length ? env.stickers : (env.getStickers?.() ?? []);
        const picker = el('div', { class: 'rr-editor__panel rr-picker rr-picker--stickers', role: 'group', 'aria-label': env.t('editor.stickers'), 'aria-busy': null }, stickerButtons(known));

        if (known.length === 0 && env.loadStickers) {
            const note = el('p', { class: 'rr-help', role: 'status', text: env.t('editor.stickers_loading') });
            picker.setAttribute('aria-busy', 'true');
            picker.append(note);
            env.loadStickers().then((stickers) => {
                picker.removeAttribute('aria-busy');
                note.textContent = stickers.length ? '' : env.t('editor.stickers_empty');
                note.hidden = stickers.length > 0;
                picker.prepend(...stickerButtons(stickers));

                if (picker.isConnected) {
                    picker.querySelector('button')?.focus();
                }
            }, () => {
                picker.removeAttribute('aria-busy');
                note.textContent = env.t('editor.stickers_failed');
            });
        } else if (known.length === 0) {
            picker.append(el('p', { class: 'rr-help', text: env.t('editor.stickers_empty') }));
        }

        return picker;
    };

    const fileInput = el('input', { type: 'file', accept: 'image/*', class: 'rr-visually-hidden', tabindex: '-1', 'aria-hidden': 'true' });
    fileInput.addEventListener('change', async () => {
        const file = fileInput.files?.[0];
        fileInput.value = '';

        if (!file || !env.uploadImage) {
            return;
        }

        showStatus(env.t('editor.image_uploading'));

        try {
            const uploaded = await env.uploadImage(file);
            showStatus(handle.insertImage({ url: uploaded.url, alt: uploaded.alt ?? '' }) ? '' : env.t('editor.image_failed'));
        } catch (failure) {
            showStatus(uploadErrorText(failure, env.t));
        }
    });

    const buttons = handle.toolbar.map((item, index) => {
        const name = item.locked !== null ? env.tc('editor.locked', item.locked, { tool: env.t(item.label) }) : env.t(item.label);
        const button = el('button', {
            type: 'button',
            class: `rr-toolbar-button rr-toolbar-button--${item.tool}${item.locked !== null ? ' is-locked' : ''}`,
            tabindex: index === 0 ? '0' : '-1',
            'aria-label': name,
            title: name,
            'aria-disabled': item.locked !== null ? 'true' : null,
            'aria-pressed': item.kind === 'toggle' ? 'false' : null,
            'aria-expanded': ['link', 'emoji', 'sticker'].includes(item.kind) ? 'false' : null,
            'aria-keyshortcuts': item.shortcut ? item.shortcut.replace('Mod', 'Control') : null,
            'data-tool': item.tool,
        }, [el('span', { class: 'rr-toolbar-button__icon', 'aria-hidden': 'true', 'data-icon': item.tool })]);
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => press(item, button));

        return { item, button };
    });

    function refreshToolbar() {
        buttons.forEach(({ item, button }) => {
            const active = Boolean(item.active(handle.editor));
            button.classList.toggle('is-active', active);

            if (item.kind === 'toggle') {
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            }
        });
    }

    if (toolbar) {
        toolbar.append(...buttons.map(({ button }) => button));
        toolbar.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            const list = buttons.map(({ button }) => button);
            const index = list.indexOf(document.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? list.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + list.length) % list.length;
            event.preventDefault();
            list.forEach((button, position) => button.setAttribute('tabindex', position === next ? '0' : '-1'));
            list[next].focus();
        });
        wrapper.prepend(toolbar);
    }

    if (tools.includes('image')) {
        wrapper.append(fileInput);
    }

    if (tools.includes('product_card') && !(locked.product_card > 0)) {
        wrapper.append(el('p', { class: 'rr-help rr-editor__hint', text: env.t('editor.card_hint') }));
    }

    handle.editor.on('selectionUpdate', refreshToolbar);
    wrapper.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel) {
            event.stopPropagation();
            closePanel();
            handle.focus();
        }
    });

    wrapper.rrEditor = {
        handle,
        textarea,
        getText: () => handle.getText(),
        getHTML: () => handle.getHTML(),
        setContent: (html) => {
            handle.setContent(html);
            textarea.value = handle.getHTML();
        },
        clear: () => {
            handle.clear();
            textarea.value = '';
        },
        focus: () => handle.focus(),
        insertQuote: (quote) => handle.insertQuote(quote),
        setInvalid: (invalid) => (invalid ? handle.editor.view.dom.setAttribute('aria-invalid', 'true') : handle.editor.view.dom.removeAttribute('aria-invalid')),
        setDisabled: (disabled) => handle.editor.setEditable(!disabled, false),
        destroy: () => {
            clearTimeout(cardTimer);
            handle.destroy();
        },
    };

    return wrapper.rrEditor;
}
