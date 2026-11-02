/**
 * The two forms of the Blade components, enhanced: the discussion composer (length,
 * draft, poll, quote, tone nudge, slow mode, undo) and the review form (quality meter,
 * pros/cons rows, tone nudge). Both post to the package API; without JavaScript the same
 * forms post to the host route in their `action`.
 */

import { ApiError } from '../api.js';
import { createDraftStore } from '../draft.js';
import { codePointLength, evaluateQuality } from '../quality-meter.js';
import { validatePoll } from '../poll.js';
import { toneSuggestionFromError, createToneChecker } from '../tone-check.js';
import { secondsUntil } from '../undo.js';
import { readJson, setBusy } from './dom.js';
import { mountEditor, uploadErrorText } from './editor-ui.js';
import { errorText } from './widgets.js';

const cssEscape = (value) => (globalThis.CSS?.escape ? globalThis.CSS.escape(value) : String(value).replace(/["\\\]\[]/g, "\\$&"));

function subjectOf(root) {
    return { type: root.dataset.rrSubjectType, id: root.dataset.rrSubjectId };
}

function showTone(form, text) {
    const box = form.querySelector('[data-rr-tone]');

    if (!box) {
        return false;
    }

    box.querySelector('[data-rr-tone-text]').textContent = text;
    box.hidden = false;
    box.querySelector('[data-rr-tone-rephrase]')?.focus();

    return true;
}

/**
 * @param {HTMLFormElement} form `[data-rr-composer]`
 * @param {object} env
 * @param {HTMLElement} root the discussion root
 * @param {{ onPosted: Function, restoreHtml?: string|null, quote?: object|null }} hooks
 */
export function enhanceComposer(form, env, root, hooks) {
    if (form.rrComposer) {
        return form.rrComposer;
    }

    const editorWrapper = form.querySelector('[data-rr-editor]');
    const editor = mountEditor(editorWrapper, env);
    const error = form.querySelector('[data-rr-error]');
    const lengthLabel = form.querySelector('[data-rr-length]');
    const draftLabel = form.querySelector('[data-rr-draft]');
    const discard = form.querySelector('[data-rr-discard-draft]');
    const waitBox = form.querySelector('[data-rr-wait]');
    const submit = form.querySelector('[type="submit"]');
    const parentInput = form.querySelector('[data-rr-parent]');
    const quoteId = form.querySelector('[data-rr-quote-id]');
    const quoteText = form.querySelector('[data-rr-quote-text]');
    const quoteChip = form.querySelector('[data-rr-quote-chip]');
    const pollBox = form.querySelector('[data-rr-poll-builder]');
    const pollToggle = form.querySelector('[data-rr-poll-toggle]');
    const min = Number(form.dataset.minLength || 1);
    const max = Number(form.dataset.maxLength || 10000);
    const limits = { minOptions: Number(form.dataset.minOptions || 2), maxOptions: Number(form.dataset.maxOptions || 4) };
    const subject = subjectOf(root);
    const draft = createDraftStore(`${subject.type}:${subject.id}:${parentInput.value || 'root'}`, {
        onResult: (saved) => (draftLabel.textContent = saved ? env.t('composer.draft_saved') : env.t('composer.draft_failed')),
    });
    const tone = createToneChecker(env.api);
    let wait = Number(form.dataset.wait || 0);
    let waitTimer = null;
    let quote = null;

    const setError = (text) => {
        error.textContent = text || '';
        editor.setInvalid(Boolean(text));
    };

    const updateLength = () => {
        const length = codePointLength(editor.getText().trim());
        lengthLabel.textContent = env.t('composer.length', { count: length, max });
        lengthLabel.classList.toggle('is-over', length > max);

        return length;
    };

    const startWait = (seconds) => {
        wait = Math.max(0, Math.ceil(seconds));
        clearInterval(waitTimer);
        const render = () => {
            waitBox.hidden = wait <= 0;
            waitBox.textContent = env.t('discussion.slow_mode_wait', { seconds: wait });
            submit.disabled = wait > 0;
        };
        render();

        if (wait > 0) {
            waitTimer = setInterval(() => {
                wait -= 1;
                render();

                if (wait <= 0) {
                    clearInterval(waitTimer);
                }
            }, 1000);
        }
    };

    const setQuote = (value) => {
        quote = value;
        const inserted = value ? editor.insertQuote(value) : false;
        quoteId.disabled = !value;
        quoteText.disabled = !value;
        quoteId.value = value?.commentId ?? '';
        quoteText.value = value?.text ?? '';

        if (quoteChip) {
            quoteChip.hidden = !value || inserted;
            quoteChip.querySelector('[data-rr-quote-preview]').textContent = value ? `${value.authorName ? env.t('discussion.quoting', { name: value.authorName }) : ''} “${value.text}”` : '';
        }
    };

    quoteChip?.querySelector('[data-rr-quote-remove]')?.addEventListener('click', () => setQuote(null));

    // Poll builder: hidden and disabled (not submitted) until added.
    const pollRows = () => [...(pollBox?.querySelectorAll('.rr-input-row') ?? [])];
    const setPollOpen = (open) => {
        if (!pollBox) {
            return;
        }

        pollBox.hidden = !open;
        pollBox.disabled = !open;
        pollToggle.hidden = open;
        pollToggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            pollRows().forEach((row) => (row.hidden = row.hasAttribute('data-rr-spare')));
            pollBox.querySelector('[data-rr-poll-question]').focus();
        } else {
            pollBox.querySelectorAll('input').forEach((input) => (input.value = ''));
            pollBox.querySelector('[data-rr-poll-error]').textContent = '';
        }
    };

    pollToggle?.addEventListener('click', () => setPollOpen(true));
    pollBox?.querySelector('[data-rr-poll-remove]')?.addEventListener('click', () => setPollOpen(false));
    pollBox?.querySelector('[data-rr-poll-add]')?.addEventListener('click', () => {
        const next = pollRows().find((row) => row.hidden);

        if (next) {
            next.hidden = false;
            next.querySelector('input').focus();
        }
    });

    const pollValue = () => {
        if (!pollBox || pollBox.hidden) {
            return null;
        }

        return {
            question: pollBox.querySelector('[data-rr-poll-question]').value,
            options: [...pollBox.querySelectorAll('[data-rr-poll-option-input]')].filter((input) => !input.closest('.rr-input-row').hidden).map((input) => input.value),
        };
    };

    // Draft
    const saved = hooks.restoreHtml ? null : draft.load();

    if (hooks.restoreHtml) {
        editor.setContent(hooks.restoreHtml);
    } else if (saved && typeof saved.value === 'string' && saved.value !== '') {
        editor.setContent(saved.value);
        draftLabel.textContent = env.t('composer.draft_restored');
        discard.hidden = false;
    }

    discard?.addEventListener('click', () => {
        draft.clear();
        editor.clear();
        draftLabel.textContent = '';
        discard.hidden = true;
        updateLength();
    });

    editorWrapper.addEventListener('rr:input', (event) => {
        draft.save(event.detail.html);
        form.querySelector('[data-rr-tone]').hidden = true;
        setError('');
        updateLength();
    });

    const send = async (confirmTone) => {
        setError('');

        if (wait > 0) {
            return;
        }

        const length = updateLength();

        if (length < min) {
            setError(env.t('composer.too_short', { min }));
            editor.focus();

            return;
        }

        if (length > max) {
            setError(env.t('composer.too_long', { max }));

            return;
        }

        const poll = pollValue();

        if (poll) {
            const check = validatePoll(poll, limits);
            const pollError = pollBox.querySelector('[data-rr-poll-error]');
            pollError.textContent = check.valid ? '' : env.t(check.errors[0].key, check.errors[0].params);

            if (!check.valid) {
                return;
            }
        }

        setBusy(submit, true);
        editor.setDisabled(true);

        try {
            if (!confirmTone && form.dataset.toneCheck === '1') {
                const result = await tone.check(editor.getHTML());

                if (result.suggestion && showTone(form, result.suggestion)) {
                    return;
                }
            }

            const body = editor.getHTML();
            const result = await env.api.createComment(subject, {
                body,
                parentId: parentInput.value ? Number(parentInput.value) : null,
                quote: quote ? { commentId: quote.commentId, text: quote.text } : null,
                poll,
                confirmTone,
                formStartedAt: Number(form.querySelector(`[name="${env.startedAtField}"]`)?.value || 0) || undefined,
            });

            draft.clear();
            editor.clear();
            setQuote(null);
            setPollOpen(false);
            draftLabel.textContent = '';
            discard.hidden = true;

            if (Number(root.dataset.slowMode || 0) > 0) {
                startWait(Number(root.dataset.slowMode));
            }

            hooks.onPosted({ item: result.item, message: result.message, bodyHtml: body, parentId: parentInput.value ? Number(parentInput.value) : null, form });
        } catch (failure) {
            const suggestion = toneSuggestionFromError(failure);

            if (suggestion && showTone(form, suggestion)) {
                return;
            }

            if (failure instanceof ApiError && failure.isThrottled && failure.retryAfter) {
                startWait(failure.retryAfter);
            }

            setError(errorText(failure, env.t));
        } finally {
            setBusy(submit, false);
            submit.disabled = wait > 0;
            editor.setDisabled(false);
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send(false);
    });
    form.querySelector('[data-rr-tone-confirm]')?.addEventListener('click', () => {
        form.querySelector('[data-rr-tone]').hidden = true;
        send(true);
    });
    form.querySelector('[data-rr-tone-rephrase]')?.addEventListener('click', () => {
        form.querySelector('[data-rr-tone]').hidden = true;
        editor.focus();
    });

    updateLength();
    startWait(wait);

    if (hooks.quote) {
        setQuote(hooks.quote);
    }

    form.rrComposer = { editor, setQuote, focus: () => editor.focus(), startWait, dispose: () => { draft.dispose(); clearInterval(waitTimer); editor.destroy(); } };

    return form.rrComposer;
}

/** Undo toast with a countdown in the page's toast region. */
export function showUndoToast(env, comment, onUndo, onExpire) {
    const region = document.querySelector('[data-rr-toasts]');
    const seconds = secondsUntil(comment.publishAt);

    if (!region || seconds <= 0) {
        onExpire();

        return;
    }

    const toast = document.createElement('div');
    toast.className = 'rr-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-atomic', 'true');
    const text = document.createElement('span');
    text.className = 'rr-toast__text';
    const progress = document.createElement('span');
    progress.className = 'rr-toast__progress';
    progress.setAttribute('aria-hidden', 'true');
    progress.style.setProperty('--rr-undo-duration', `${seconds}s`);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'rr-button rr-button--small rr-button--inverse';
    button.textContent = env.t('undo.action');
    toast.append(text, progress, button);
    region.append(toast);

    let left = seconds;
    let done = false;
    const render = () => (text.textContent = env.tc('undo.message', left));
    render();
    const timer = setInterval(() => {
        left = secondsUntil(comment.publishAt);
        render();

        if (left <= 0 && !done) {
            done = true;
            clearInterval(timer);
            toast.remove();
            onExpire();
        }
    }, 250);

    button.addEventListener('click', () => {
        if (done) {
            return;
        }

        done = true;
        clearInterval(timer);
        toast.remove();
        onUndo();
    });
    button.focus({ preventScroll: true });
}

/**
 * Review photos with JavaScript: chosen files are uploaded at once (POST uploads, always the
 * package endpoint: a photo needs the upload id) and appear as ticked `photos[]` choices,
 * the same markup the server renders for kept photos.
 */
function enhancePhotos(box, env) {
    const input = box.querySelector('[data-rr-photo-files]');
    const list = box.querySelector('[data-rr-photo-list]');
    const status = box.querySelector('[data-rr-photo-status]');
    const error = box.querySelector('[data-rr-field-error]');
    const max = Number(box.dataset.max || 0);
    const ticked = () => box.querySelectorAll('input[name="photos[]"]:checked').length;

    input?.closest('.rr-field')?.classList.remove('rr-js-only');
    input?.addEventListener('change', async () => {
        const files = [...(input.files ?? [])];
        input.value = '';
        error.textContent = '';

        for (const file of files) {
            if (ticked() >= max) {
                error.textContent = env.tc('form.photos_limit', max);
                break;
            }

            status.textContent = env.t('form.photo_uploading');

            try {
                const uploaded = await env.api.uploadImage(file);
                const item = document.createElement('li');
                const label = document.createElement('label');
                label.className = 'rr-photo-choice';
                const checkbox = Object.assign(document.createElement('input'), { type: 'checkbox', name: 'photos[]', value: String(uploaded.id), checked: true });
                const image = Object.assign(document.createElement('img'), { src: uploaded.url, alt: uploaded.alt ?? '', loading: 'lazy' });
                const text = Object.assign(document.createElement('span'), { className: 'rr-photo-choice__label', textContent: env.t('form.photo_keep') });
                label.append(checkbox, image, text);
                item.append(label);
                list.append(item);
                status.textContent = env.t('form.photo_uploaded');
                closestForm(box)?.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (failure) {
                status.textContent = '';
                error.textContent = uploadErrorText(failure, env.t);
            }
        }
    });
}

const closestForm = (box) => box.closest('form');

/** The review form: editor, quality meter, pros/cons rows, photos, API submit. */
export function enhanceReviewForm(form, env, root, hooks) {
    if (form.rrReviewForm) {
        return;
    }

    form.rrReviewForm = true;
    const editor = mountEditor(form.querySelector('[data-rr-editor]'), env);
    const rules = readJson('[data-rr-quality-rules]', null, root);
    const meterBox = form.querySelector('[data-rr-quality]');
    const error = form.querySelector('[data-rr-error]');
    const submit = form.querySelector('[type="submit"]');
    const reviewId = root.dataset.rrReviewId ? Number(root.dataset.rrReviewId) : null;
    const startedAt = Number(form.querySelector(`[name="${env.startedAtField}"]`)?.value || 0);

    const photoBox = form.querySelector('[data-rr-photos]');

    if (photoBox) {
        enhancePhotos(photoBox, env);
    }

    form.querySelectorAll('[data-rr-points]').forEach((fieldset) => {
        const rows = [...fieldset.querySelectorAll('.rr-input-row')];
        const add = fieldset.querySelector('[data-rr-add-point]');
        rows.forEach((row) => (row.hidden = row.hasAttribute('data-rr-spare')));
        const sync = () => (add.hidden = !rows.some((row) => row.hidden));
        sync();
        add.addEventListener('click', () => {
            const next = rows.find((row) => row.hidden);

            if (next) {
                next.hidden = false;
                next.querySelector('input').focus();
            }

            sync();
        });
    });

    const values = () => {
        const data = new FormData(form);
        const topicRatings = {};
        const facets = {};

        for (const [name, value] of data.entries()) {
            const topic = /^topic_ratings\[(\d+)\]$/.exec(name);
            const facet = /^facets\[([^\]]+)\]$/.exec(name);

            if (topic) {
                topicRatings[topic[1]] = value === '' ? null : Number(value);
            }

            if (facet) {
                facets[facet[1]] = value === '' ? null : String(value);
            }
        }

        const comparedType = String(data.get('compared_type') ?? '');
        const comparedId = String(data.get('compared_id') ?? '');
        const verdict = String(data.get('comparison') ?? '');

        const extended = form.dataset.extended !== '0';

        return {
            rating: Number(data.get('rating') || 0),
            title: extended ? String(data.get('title') ?? '') : '',
            body: editor.getHTML(),
            pros: extended ? data.getAll('pros[]').map(String) : [],
            cons: extended ? data.getAll('cons[]').map(String) : [],
            topicRatings,
            facets,
            comparison: comparedType && comparedId && verdict ? { type: comparedType, id: Number(comparedId), verdict } : null,
            // Ticked photos (kept and newly uploaded), in their order; left out without the photo field.
            ...(photoBox ? { photos: data.getAll('photos[]').map(Number) } : {}),
        };
    };

    const renderQuality = () => {
        if (!rules || !meterBox) {
            return;
        }

        const current = values();
        const result = evaluateQuality({
            body: current.body,
            pros: current.pros,
            cons: current.cons,
            topicRatings: current.topicRatings,
            topicCount: Number(form.dataset.topicCount || 0),
            extendedFields: form.dataset.extended !== '0',
        }, rules);
        const level = result.levelLabel ?? (result.level ? env.t(`quality.level.${result.level}`) : '');
        const meter = meterBox.querySelector('[data-rr-quality-meter]');
        meterBox.hidden = false;
        meterBox.dataset.level = result.level ?? '';
        meterBox.querySelector('[data-rr-quality-level]').textContent = level;
        meter.setAttribute('aria-valuenow', String(result.score));
        meter.setAttribute('aria-valuetext', `${env.t('quality.score', { score: result.score })}, ${level}`);
        meter.querySelector('.rr-meter__fill').style.inlineSize = `${result.score}%`;
        const hints = meterBox.querySelector('[data-rr-quality-hints]');
        hints.replaceChildren(...(result.hints.length
            ? result.hints.map((hint) => Object.assign(document.createElement('li'), { textContent: hint.label ?? env.t(`quality.hint.${hint.hint}`) }))
            : [Object.assign(document.createElement('li'), { textContent: env.t('quality.complete') })]));
    };

    const syncScales = () => form.querySelectorAll('.rr-scale').forEach((scale) => {
        const checked = Number(scale.querySelector('input:checked')?.value || 0);
        scale.querySelectorAll('.rr-scale__option').forEach((option) => {
            const value = Number(option.querySelector('input').value || 0);
            option.classList.toggle('is-filled', value > 0 && value <= checked);
        });
    });

    form.addEventListener('input', renderQuality);
    form.addEventListener('change', () => {
        syncScales();
        renderQuality();
    });
    form.querySelector('[data-rr-editor]').addEventListener('rr:input', renderQuality);
    renderQuality();

    const showErrors = (errors) => {
        form.querySelectorAll('[data-rr-field-error]').forEach((node) => (node.textContent = ''));

        for (const [field, messages] of Object.entries(errors)) {
            const target = form.querySelector(`[data-rr-field-error="${cssEscape(field)}"]`)
                ?? form.querySelector(`[data-rr-field="${cssEscape(field.split('.')[0])}"] [data-rr-field-error]`)
                ?? form.querySelector(`[data-rr-points="${cssEscape(field.split('.')[0])}"] [data-rr-field-error]`);

            if (target) {
                target.textContent = messages[0];
            }
        }
    };

    const send = async (confirmTone) => {
        error.hidden = true;
        form.querySelector('[data-rr-tone]').hidden = true;
        const payload = values();

        if (!payload.rating) {
            showErrors({ rating: [env.t('form.rating_required')] });
            form.querySelector('input[name="rating"]')?.focus();

            return;
        }

        setBusy(submit, true);

        try {
            const request = { ...payload, confirmTone, formStartedAt: reviewId ? undefined : startedAt || undefined };
            const result = reviewId ? await env.api.updateReview(reviewId, request) : await env.api.createReview(subjectOf(root), request);
            showErrors({});
            hooks.onSaved(result);
        } catch (failure) {
            const suggestion = toneSuggestionFromError(failure);

            if (suggestion && showTone(form, suggestion)) {
                return;
            }

            if (failure instanceof ApiError && failure.isValidation) {
                showErrors(failure.errors);
            }

            error.textContent = failure instanceof ApiError && failure.isValidation ? env.t('form.errors') : errorText(failure, env.t);
            error.hidden = false;
            error.focus?.();
        } finally {
            setBusy(submit, false);
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send(false);
    });
    form.querySelector('[data-rr-tone-confirm]')?.addEventListener('click', () => send(true));
    form.querySelector('[data-rr-tone-rephrase]')?.addEventListener('click', () => {
        form.querySelector('[data-rr-tone]').hidden = true;
        editor.focus();
    });
}
