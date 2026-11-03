/**
 * Progressive enhancement of the Blade components (<x-rr::rating-summary>, <x-rr::reviews>,
 * <x-rr::review-form>, <x-rr::discussion>, <x-rr::editor>). The server-rendered markup
 * works without JavaScript; this adds the editor, in-place sorting, filtering and paging,
 * votes, polls, reports, hovercards, quoting, replies, edits, undo and the live quality
 * meter, all through the package JSON API.
 *
 * Usage in the host bundle (Vite):
 *
 *   import { enhanceRatingsAndReviews } from '../../vendor/jw-1980/ratings-and-reviews/resources/js/blade/index.js';
 *   import '../../vendor/jw-1980/ratings-and-reviews/resources/js/css/ratings-and-reviews.css';
 *   enhanceRatingsAndReviews(document, { searchCards });   // uploadImage and stickers default to the package API
 *
 * Strings come from `#rr-messages` (lang/{locale}/ui.php, printed by the components) and
 * settings from `#rr-config`; CSRF from `<meta name="csrf-token">` or the XSRF-TOKEN cookie.
 */

import { createApiClient } from '../api.js';
import { hydrateCards } from '../cards.js';
import { createTranslator } from '../i18n.js';
import { quotableSelection } from '../quote-selection.js';
import { escapeHtml } from '../sanitize.js';
import { announce, focusTarget, readJson } from './dom.js';
import { enhanceComposer, enhanceReviewForm, showUndoToast } from './forms.js';
import { mountEditor } from './editor-ui.js';
import { bindHovercard, confirmAction, errorText, openReport, showRevisions, votePoll, vote, wantToTry } from './widgets.js';

/** Restores of undone comments, applied after the discussion is re-rendered. */
const pendingRestore = new Map();
const cardCache = new Map();

/**
 * Without a host `stickers` list the sticker picker loads the active library from GET
 * stickers the first time it opens (once per page).
 */
function stickerLibrary(api, given) {
    if (given) {
        return { loadStickers: null, getStickers: () => given };
    }

    const loaded = [];
    let pending = null;

    return {
        getStickers: () => loaded,
        loadStickers: () => {
            pending ??= api.stickers().then((items) => {
                loaded.splice(0, loaded.length, ...items);

                return loaded;
            }).catch((failure) => {
                pending = null;
                throw failure;
            });

            return pending;
        },
    };
}

function createEnv(options) {
    const messages = readJson('#rr-messages', {});
    const config = readJson('#rr-config', {});
    const { t, tc } = createTranslator(messages);
    const api = options.api ?? createApiClient({
        baseUrl: config.api ?? '/rr',
        locale: config.locale,
        csrfToken: () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        ...(options.apiOptions ?? {}),
    });
    const toast = (text) => {
        const region = document.querySelector('[data-rr-toasts]');

        if (!region || !text) {
            return;
        }

        const node = document.createElement('div');
        node.className = 'rr-toast';
        node.setAttribute('role', 'status');
        node.textContent = text;
        region.append(node);
        setTimeout(() => node.remove(), 5000);
    };

    return {
        t,
        tc,
        api,
        config,
        toast,
        locale: config.locale ?? document.documentElement.lang ?? 'en',
        mediaPrefixes: config.mediaPrefixes ?? ['/storage/'],
        startedAtField: options.startedAtField ?? 'form_started_at',
        // Default: the package's POST uploads; a host callback still overrides it.
        uploadImage: options.uploadImage ?? ((file) => api.uploadImage(file)),
        searchCards: options.searchCards ?? null,
        stickers: options.stickers ?? [],
        ...stickerLibrary(api, options.stickers),
        emojis: options.emojis ?? null,
    };
}

/**
 * Re-renders the components from the host page at `url` (server rendering is the single
 * renderer); falls back to a full navigation when that fails.
 */
async function refresh(env, url = window.location.href, { push = false, focusRoot = null } = {}) {
    try {
        const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const next = new DOMParser().parseFromString(await response.text(), 'text/html');
        const swapped = [];

        document.querySelectorAll('[data-rr][id]').forEach((current) => {
            const replacement = next.getElementById(current.id);

            if (replacement) {
                current.querySelectorAll('[data-rr-editor]').forEach((editor) => editor.rrEditor?.destroy());
                const imported = document.importNode(replacement, true);
                current.replaceWith(imported);
                swapped.push(imported);
            }
        });

        if (push) {
            history.pushState(history.state, '', url);
        }

        swapped.forEach((root) => enhanceRoot(root, env));

        if (focusRoot) {
            const root = document.getElementById(focusRoot);
            announce(root, root?.querySelector('.rr-section-header__count')?.textContent?.trim() || env.t('discussion.updated'));
        }

        return true;
    } catch {
        window.location.assign(url);

        return false;
    }
}

function subjectOf(root) {
    return { type: root.dataset.rrSubjectType, id: root.dataset.rrSubjectId };
}

function onPosted(env, root, { item, message, bodyHtml, parentId }) {
    const finish = () => refresh(env, window.location.href).then(() => {
        const fresh = document.getElementById(root.id);
        announce(fresh, message ?? env.t('composer.posted'));
        focusTarget(document.getElementById(`rr-comment-${item.id}`));
    });

    if (item.publishAt && item.visibility === 'scheduled') {
        refresh(env, window.location.href).then(() => {
            showUndoToast(env, item, async () => {
                try {
                    await env.api.undoComment(item.id);
                    pendingRestore.set(`${root.id}:${parentId ?? 'root'}`, bodyHtml);
                    await refresh(env, window.location.href);
                    const fresh = document.getElementById(root.id);
                    announce(fresh, env.t('undo.undone'));

                    if (parentId) {
                        fresh?.querySelector(`[data-rr-reply="${parentId}"]`)?.click();
                    } else {
                        fresh?.querySelector('[data-rr-composer]')?.rrComposer?.focus();
                    }
                } catch {
                    announce(document.getElementById(root.id), env.t('undo.failed'));
                }
            }, () => announce(document.getElementById(root.id), env.t('undo.published')));
        });

        return;
    }

    finish();
}

function openReply(env, root, trigger, quote = null) {
    const id = trigger.dataset.rrReply;
    const slot = root.querySelector(`[data-rr-reply-slot="${id}"]`);
    const template = root.querySelector('[data-rr-reply-template]');

    if (!slot || !template) {
        return null;
    }

    if (slot.firstElementChild) {
        const existing = slot.querySelector('[data-rr-composer]');

        if (quote) {
            existing.rrComposer?.setQuote(quote);
        }

        existing.rrComposer?.focus();

        return existing;
    }

    const html = template.innerHTML.replaceAll('__PARENT__', String(Number(id))).replaceAll('__NAME__', escapeHtml(trigger.dataset.rrReplyName ?? '')).replaceAll(`${root.id}-reply`, `${root.id}-reply-${Number(id)}`);
    slot.innerHTML = html;
    const form = slot.querySelector('[data-rr-composer]');
    const restoreKey = `${root.id}:${id}`;
    const restoreHtml = pendingRestore.get(restoreKey) ?? null;
    pendingRestore.delete(restoreKey);
    trigger.setAttribute('aria-expanded', 'true');
    const composer = enhanceComposer(form, env, root, { onPosted: (payload) => onPosted(env, root, payload), restoreHtml, quote });
    form.querySelector('[data-rr-cancel-reply]')?.addEventListener('click', (event) => {
        event.preventDefault();
        composer.dispose();
        slot.replaceChildren();
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
    });
    composer.focus();

    return form;
}

async function editComment(env, root, button) {
    const article = root.querySelector(`[data-rr-comment="${button.dataset.rrEdit}"]`);
    const body = article?.querySelector('[data-rr-body]');
    const template = root.querySelector('[data-rr-composer] [data-rr-editor]') ?? root.querySelector('[data-rr-reply-template]')?.content.querySelector('[data-rr-editor]');

    if (!body || !template || article.querySelector('.rr-comment__edit')) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'rr-comment__edit';
    const editorNode = template.cloneNode(true);
    editorNode.rrEditor = undefined;
    editorNode.querySelectorAll('.rr-editor__toolbar, .rr-editor__surface, .rr-editor__cards, .rr-field-error, .rr-editor__hint, input[type="file"]').forEach((node) => node.remove());
    const textarea = editorNode.querySelector('textarea');
    textarea.hidden = false;
    textarea.value = body.innerHTML;
    textarea.id = `${textarea.id}-edit-${button.dataset.rrEdit}`;
    textarea.removeAttribute('name');
    editorNode.dataset.label = env.t('common.edit');
    const error = Object.assign(document.createElement('p'), { className: 'rr-field-error' });
    error.setAttribute('role', 'alert');
    const actions = document.createElement('div');
    actions.className = 'rr-actions';
    const cancel = Object.assign(document.createElement('button'), { type: 'button', className: 'rr-button rr-button--ghost', textContent: env.t('common.cancel') });
    const save = Object.assign(document.createElement('button'), { type: 'button', className: 'rr-button rr-button--primary', textContent: env.t('common.save') });
    actions.append(cancel, save);
    wrapper.append(editorNode, error, actions);
    body.hidden = true;
    body.after(wrapper);
    const editor = mountEditor(editorNode, env);
    editor.focus();

    cancel.addEventListener('click', () => {
        editor.destroy();
        wrapper.remove();
        body.hidden = false;
        button.focus();
    });
    save.addEventListener('click', async () => {
        save.disabled = true;

        try {
            const result = await env.api.updateComment(Number(button.dataset.rrEdit), editor.getHTML());
            await refresh(env, window.location.href);
            announce(document.getElementById(root.id), result.message ?? env.t('composer.posted'));
            focusTarget(document.getElementById(`rr-comment-${button.dataset.rrEdit}`));
        } catch (failure) {
            error.textContent = errorText(failure, env.t);
            save.disabled = false;
        }
    });
}

function bindQuoteSelection(env, root) {
    const thread = root.querySelector('[data-rr-thread]');
    const offer = root.querySelector('[data-rr-quote-offer]');

    if (!thread || !offer || root.dataset.accepts !== '1') {
        return;
    }

    let current = null;
    const update = () => {
        const selected = quotableSelection(window.getSelection(), Number(root.dataset.maxQuote || 500));

        if (!selected || !root.querySelector(`[data-rr-reply="${selected.commentId}"]`)) {
            offer.hidden = true;
            current = null;

            return;
        }

        const box = thread.getBoundingClientRect();
        current = selected;
        offer.hidden = false;
        offer.style.insetBlockStart = `${selected.rect ? selected.rect.bottom - box.top + 6 : 0}px`;
        offer.style.insetInlineStart = `${selected.rect ? Math.max(0, selected.rect.left - box.left) : 0}px`;
    };

    thread.addEventListener('mouseup', () => setTimeout(update, 0));
    thread.addEventListener('keyup', (event) => (event.shiftKey || event.key === 'Shift') && update());
    offer.addEventListener('mousedown', (event) => event.preventDefault());
    offer.addEventListener('click', () => {
        if (!current) {
            return;
        }

        const trigger = root.querySelector(`[data-rr-reply="${current.commentId}"]`);
        const authorName = trigger?.dataset.rrReplyName;
        openReply(env, root, trigger, { commentId: current.commentId, text: current.text, authorName });
        offer.hidden = true;
        window.getSelection()?.removeAllRanges();
    });
    document.addEventListener('keydown', (event) => event.key === 'Escape' && (offer.hidden = true));
}

function enhanceRoot(root, env) {
    if (root.dataset.rrEnhanced) {
        return;
    }

    root.dataset.rrEnhanced = '1';
    root.classList.remove('rr-nojs');
    root.classList.add('rr-js');

    root.querySelectorAll('[data-rr-author]').forEach((trigger) => bindHovercard(trigger, env));
    root.querySelectorAll('.rr-prose').forEach((prose) => prose.querySelector('a[data-rr-card]') && hydrateCards(prose, env.api, { locale: env.locale, t: env.t, cache: cardCache }));
    root.querySelectorAll('form[data-rr-autosubmit] select').forEach((select) => select.addEventListener('change', () => select.form.requestSubmit()));

    root.addEventListener('click', async (event) => {
        const target = event.target.closest?.('a, button');

        if (!target || !root.contains(target)) {
            return;
        }

        if (target.matches('a[data-rr-nav]')) {
            event.preventDefault();
            await refresh(env, target.href, { push: true, focusRoot: root.id });
        } else if (target.matches('a[data-rr-more]')) {
            event.preventDefault();
            await loadMore(env, root, target);
        } else if (target.matches('.rr-vote')) {
            // Inside a no-JavaScript vote form (form routes): vote through the API instead.
            event.preventDefault();
            await vote(target, env);
        } else if (target.matches('[data-rr-poll-option]')) {
            event.preventDefault();
            await votePoll(target, env, root);
        } else if (target.matches('[data-rr-report]')) {
            openReport(target, env, root);
        } else if (target.matches('[data-rr-want-to-try]')) {
            await wantToTry(target, env, root);
        } else if (target.matches('[data-rr-revisions]')) {
            await showRevisions(target, env, root);
        } else if (target.matches('[data-rr-reply]')) {
            event.preventDefault();
            const slot = root.querySelector(`[data-rr-reply-slot="${target.dataset.rrReply}"]`);

            if (slot?.firstElementChild && target.getAttribute('aria-expanded') === 'true') {
                slot.querySelector('[data-rr-composer]')?.rrComposer?.dispose();
                slot.replaceChildren();
                target.setAttribute('aria-expanded', 'false');
            } else {
                openReply(env, root, target);
            }
        } else if (target.matches('[data-rr-edit]')) {
            await editComment(env, root, target);
        } else if (target.matches('[data-rr-delete-comment], [data-rr-delete-review]')) {
            const isReview = target.matches('[data-rr-delete-review]');
            const ok = await confirmAction(env, env.t(isReview ? 'reviews.delete_confirm' : 'discussion.delete_confirm'), env.t('common.delete'));

            if (ok) {
                try {
                    isReview ? await env.api.deleteReview(Number(target.dataset.rrDeleteReview)) : await env.api.deleteComment(Number(target.dataset.rrDeleteComment));
                    await refresh(env, window.location.href);
                    announce(document.getElementById(root.id), isReview ? env.t('reviews.deleted') : env.t('common.deleted'));
                } catch (failure) {
                    announce(root, errorText(failure, env.t));
                }
            }
        } else if (target.matches('[data-rr-undo]')) {
            try {
                await env.api.undoComment(Number(target.dataset.rrUndo));
                await refresh(env, window.location.href);
                announce(document.getElementById(root.id), env.t('undo.undone'));
            } catch {
                announce(root, env.t('undo.failed'));
            }
        } else if (target.matches('[data-rr-jump]')) {
            event.preventDefault();
            focusTarget(document.getElementById(`rr-comment-${target.dataset.rrJump}`));
        }
    });

    root.addEventListener('submit', async (event) => {
        const form = event.target;

        if (form.matches('form[data-rr-nav]') && (form.method || 'get').toLowerCase() === 'get') {
            event.preventDefault();
            const url = new URL(form.action || window.location.href, window.location.href);
            url.search = new URLSearchParams(new FormData(form)).toString();
            await refresh(env, url.toString(), { push: true, focusRoot: root.id });
        }
    });

    if (root.dataset.rr === 'discussion') {
        root.querySelectorAll('[data-rr-composer]').forEach((form) => {
            const parent = form.querySelector('[data-rr-parent]')?.value || 'root';
            const key = `${root.id}:${parent}`;
            const restoreHtml = pendingRestore.get(key) ?? null;
            pendingRestore.delete(key);
            enhanceComposer(form, env, root, { onPosted: (payload) => onPosted(env, root, payload), restoreHtml });
        });
        bindQuoteSelection(env, root);
    }

    if (root.dataset.rr === 'review-form') {
        root.querySelectorAll('[data-rr-review-form]').forEach((form) => enhanceReviewForm(form, env, root, {
            onSaved: async (result) => {
                await refresh(env, window.location.href);
                const fresh = document.getElementById(root.id);
                announce(fresh, result.message);
                env.toast(result.message);
                focusTarget(document.getElementById(`rr-review-${result.item.id}`));
            },
        }));
    }

    // Stand-alone <x-rr::editor> outside the package forms.
    root.querySelectorAll('[data-rr-editor]').forEach((wrapper) => wrapper.rrEditor || mountEditor(wrapper, env));
}

async function loadMore(env, root, link) {
    link.setAttribute('aria-busy', 'true');

    try {
        const response = await fetch(link.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        const next = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById(root.id);
        const list = root.querySelector('[data-rr-thread-list]');
        const incoming = next?.querySelector('[data-rr-thread-list]');

        if (!list || !incoming) {
            throw new Error('missing thread');
        }

        const first = incoming.firstElementChild;
        [...incoming.children].forEach((item) => {
            const id = item.querySelector('[data-rr-comment]')?.dataset.rrComment;

            if (!id || !list.querySelector(`[data-rr-comment="${id}"]`)) {
                list.append(document.importNode(item, true));
            }
        });
        const nextMore = next.querySelector('[data-rr-more]');
        nextMore ? link.setAttribute('href', nextMore.getAttribute('href')) : link.closest('.rr-more')?.remove();
        list.querySelectorAll('[data-rr-author]').forEach((trigger) => bindHovercard(trigger, env));
        announce(root, env.t('discussion.updated'));
        const firstId = first?.querySelector('[data-rr-comment]')?.dataset.rrComment;

        if (firstId) {
            focusTarget(document.getElementById(`rr-comment-${firstId}`));
        }
    } catch {
        window.location.assign(link.href);
    } finally {
        link.removeAttribute('aria-busy');
    }
}

/**
 * @param {ParentNode} [scope]
 * @param {{ uploadImage?: (file: File) => Promise<{url: string, alt?: string}>, searchCards?: (query: string) => Promise<Array>, stickers?: Array<{id: string|number, name: string, url: string}>, emojis?: string[], api?: object, apiOptions?: object }} [options]
 */
export function enhanceRatingsAndReviews(scope = document, options = {}) {
    const env = createEnv(options);
    scope.querySelectorAll('[data-rr]').forEach((root) => enhanceRoot(root, env));

    return { env, refresh: (url) => refresh(env, url) };
}

export { mountEditor } from './editor-ui.js';
