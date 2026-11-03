/**
 * Interactive pieces of the Blade components that need no form: votes, polls, reports,
 * "want to try", hovercards, revisions and the confirm dialog.
 */

import { ApiError } from '../api.js';
import { formatDate } from '../format.js';
import { applyPollVote, pollPercentages } from '../poll.js';
import { sanitizeHtml } from '../sanitize.js';
import { toggleVote } from '../votes.js';
import { announce, closeDialog, el, openDialog } from './dom.js';

export function errorText(error, t) {
    if (error instanceof ApiError) {
        if (error.isNetwork) {
            return t('common.network_error');
        }

        if (error.isUnauthenticated) {
            return t('common.login_required');
        }

        if (error.isThrottled && error.retryAfter) {
            return t('common.throttled', { seconds: error.retryAfter });
        }

        return Object.values(error.errors ?? {})[0]?.[0] ?? error.message ?? t('common.error');
    }

    return t('common.error');
}

/** Helpful / agree / disagree / needs-source buttons. */
export async function vote(button, env) {
    const group = button.closest('[data-rr-votes]');
    const note = group.querySelector('[data-rr-vote-note]');
    const dimension = button.dataset.dimension;
    const value = Number(button.dataset.value);

    if (group.dataset.own === '1') {
        note.textContent = env.t('votes.own');

        return;
    }

    if (!env.config.authenticated && group.dataset.guestVotes !== '1') {
        note.textContent = env.t('votes.login');

        return;
    }

    if (group.getAttribute('aria-busy') === 'true') {
        return;
    }

    const buttons = [...group.querySelectorAll(`button[data-dimension="${dimension}"]`)];
    const read = () => ({
        value: Number(buttons.find((item) => item.getAttribute('aria-pressed') === 'true')?.dataset.value ?? 0),
        up: Number(buttons.find((item) => item.dataset.value === '1')?.querySelector('.rr-vote__count')?.textContent ?? 0),
        down: Number(buttons.find((item) => item.dataset.value === '-1')?.querySelector('.rr-vote__count')?.textContent ?? 0),
    });
    const write = (state) => buttons.forEach((item) => {
        const count = item.dataset.value === '1' ? state.up : state.down;
        item.setAttribute('aria-pressed', Number(item.dataset.value) === state.value ? 'true' : 'false');
        item.querySelector('.rr-vote__count').textContent = String(count);
        item.setAttribute('aria-label', env.t('votes.count', { label: item.dataset.label, count }));
    });

    const before = read();
    write(toggleVote(before, value));
    group.setAttribute('aria-busy', 'true');
    note.textContent = '';

    try {
        const result = group.dataset.target === 'review'
            ? await env.api.voteReview(group.dataset.id, dimension, value)
            : await env.api.voteComment(group.dataset.id, dimension, value);
        write({ value: result.value, up: result.counts.up, down: result.counts.down });

        if (!result.counted && result.value !== 0) {
            note.textContent = env.t('votes.not_counted');
        }
    } catch (error) {
        write(before);
        note.textContent = errorText(error, env.t);
    } finally {
        group.removeAttribute('aria-busy');
    }
}

function renderPoll(fieldset, poll, env) {
    const percentages = pollPercentages(poll.options);
    const showResults = poll.isClosed || poll.viewerOptionId !== null;

    poll.options.forEach((option, index) => {
        const button = fieldset.querySelector(`[data-rr-poll-option="${option.id}"]`);

        if (!button) {
            return;
        }

        const chosen = poll.viewerOptionId === option.id;
        button.setAttribute('aria-pressed', chosen ? 'true' : 'false');
        button.closest('.rr-poll__option')?.classList.toggle('is-chosen', chosen);
        button.setAttribute('aria-label', showResults ? env.t('poll.result', { label: option.label, percent: percentages[index], count: option.votes }) : option.label);
        button.querySelectorAll('.rr-bar, .rr-poll__percent, .rr-tag').forEach((node) => node.remove());

        if (showResults) {
            button.append(
                el('span', { class: 'rr-bar', 'aria-hidden': 'true' }, [el('span', { class: 'rr-bar__fill', style: `inline-size: ${percentages[index]}%` })]),
                el('span', { class: 'rr-poll__percent', 'aria-hidden': 'true', text: `${percentages[index]}%` }),
            );
        }

        if (chosen) {
            button.append(el('span', { class: 'rr-tag', text: env.t('poll.your_vote') }));
        }
    });

    const total = fieldset.querySelector('.rr-poll__total');

    if (total) {
        total.textContent = env.tc('poll.votes', poll.totalVotes) + (poll.isClosed ? ` · ${env.t('poll.closed')}` : '');
    }
}

function readPoll(fieldset) {
    const options = [...fieldset.querySelectorAll('[data-rr-poll-option]')].map((button) => ({
        id: Number(button.dataset.rrPollOption),
        label: button.querySelector('.rr-poll__label')?.textContent ?? '',
        votes: 0,
    }));
    const chosen = fieldset.querySelector('[data-rr-poll-option][aria-pressed="true"]');

    return { id: Number(fieldset.dataset.rrPoll), options, totalVotes: 0, viewerOptionId: chosen ? Number(chosen.dataset.rrPollOption) : null, isClosed: false };
}

export async function votePoll(button, env, root) {
    const fieldset = button.closest('[data-rr-poll]');

    if (fieldset.getAttribute('aria-busy') === 'true') {
        return;
    }

    const optionId = Number(button.dataset.rrPollOption);
    fieldset.setAttribute('aria-busy', 'true');

    try {
        // The server is the source of the counts; the optimistic step only marks the choice.
        const optimistic = applyPollVote(readPoll(fieldset), optionId);
        fieldset.querySelectorAll('[data-rr-poll-option]').forEach((item) => item.setAttribute('aria-pressed', Number(item.dataset.rrPollOption) === optimistic.viewerOptionId ? 'true' : 'false'));
        const result = await env.api.votePoll(fieldset.dataset.rrPoll, optionId);

        if (result.poll) {
            renderPoll(fieldset, result.poll, env);
        }

        if (!result.counted) {
            announce(root, env.t('votes.not_counted'));
        }
    } catch (error) {
        fieldset.querySelectorAll('[data-rr-poll-option]').forEach((item) => item.setAttribute('aria-pressed', 'false'));
        announce(root, errorText(error, env.t));
        const fresh = await env.api.poll(fieldset.dataset.rrPoll).catch(() => null);

        if (fresh) {
            renderPoll(fieldset, fresh, env);
        }
    } finally {
        fieldset.removeAttribute('aria-busy');
    }
}

/** The report dialog of a component, for one review or comment. */
export function openReport(button, env, root) {
    const dialog = root.querySelector('[data-rr-report-dialog]');

    if (!dialog) {
        return;
    }

    const form = dialog.querySelector('[data-rr-report-form]');
    const error = dialog.querySelector('[data-rr-error]');
    form.reset();
    error.hidden = true;
    dialog.dataset.type = button.dataset.rrReport;
    dialog.dataset.id = button.dataset.id;

    if (!dialog.dataset.rrBound) {
        dialog.dataset.rrBound = '1';
        dialog.querySelectorAll('[data-rr-dialog-close]').forEach((close) => close.addEventListener('click', () => closeDialog(dialog)));
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const data = new FormData(form);
            const reason = String(data.get('reason') ?? '');
            const details = String(data.get('details') ?? '').trim();

            if (!reason) {
                error.textContent = env.t('report.reason');
                error.hidden = false;

                return;
            }

            if ((env.config.reportDetailsRequiredFor ?? []).includes(reason) && details === '') {
                error.textContent = env.t('report.details_required');
                error.hidden = false;
                form.querySelector('textarea')?.focus();

                return;
            }

            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;

            try {
                const result = await env.api.report({ type: dialog.dataset.type, id: dialog.dataset.id, reason, details });
                closeDialog(dialog);
                env.toast(result.message ?? env.t('report.success'));
            } catch (failure) {
                error.textContent = errorText(failure, env.t);
                error.hidden = false;
            } finally {
                submit.disabled = false;
            }
        });
    }

    openDialog(dialog);
}

export async function wantToTry(button, env, root) {
    if (button.getAttribute('aria-pressed') === 'true' || button.disabled) {
        return;
    }

    button.disabled = true;

    try {
        const result = await env.api.wantToTry({ type: root.dataset.rrSubjectType, id: root.dataset.rrSubjectId }, Number(button.dataset.rrWantToTry));
        button.setAttribute('aria-pressed', 'true');
        button.textContent = button.dataset.addedLabel;
        announce(root, result.message ?? button.dataset.addedLabel);
    } catch (error) {
        announce(root, errorText(error, env.t));
    } finally {
        button.disabled = false;
    }
}

/** A confirm dialog built once per page. Resolves true when confirmed. */
export function confirmAction(env, message, confirmLabel) {
    return new Promise((resolve) => {
        const titleId = 'rr-confirm-title';
        const messageId = 'rr-confirm-message';
        const dialog = el('dialog', { class: 'rr rr-dialog', 'aria-labelledby': titleId, 'aria-describedby': messageId }, [
            el('div', { class: 'rr-dialog__panel' }, [
                el('h2', { id: titleId, class: 'rr-dialog__title', text: confirmLabel }),
                el('p', { id: messageId, class: 'rr-help', text: message }),
                el('div', { class: 'rr-actions' }, [
                    el('button', { type: 'button', class: 'rr-button rr-button--ghost', 'data-answer': 'no', text: env.t('common.cancel') }),
                    el('button', { type: 'button', class: 'rr-button rr-button--danger', 'data-answer': 'yes', text: confirmLabel }),
                ]),
            ]),
        ]);
        let answer = false;
        dialog.addEventListener('click', (event) => {
            const choice = event.target.closest?.('[data-answer]');

            if (choice) {
                answer = choice.dataset.answer === 'yes';
                closeDialog(dialog);
            }
        });
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(answer);
        });
        document.body.append(dialog);
        openDialog(dialog);
        dialog.querySelector('[data-answer="no"]').focus();
    });
}

export async function showRevisions(button, env, root) {
    const dialog = root.querySelector('[data-rr-revisions-dialog]');
    const body = dialog.querySelector('[data-rr-revisions-body]');

    if (!dialog.dataset.rrBound) {
        dialog.dataset.rrBound = '1';
        dialog.querySelectorAll('[data-rr-dialog-close]').forEach((close) => close.addEventListener('click', () => closeDialog(dialog)));
    }

    body.replaceChildren(el('p', { class: 'rr-help', role: 'status', text: env.t('common.loading') }));
    openDialog(dialog);

    try {
        const revisions = await env.api.revisions(button.dataset.rrRevisions);

        if (!revisions.length) {
            body.replaceChildren(el('p', { class: 'rr-help', text: env.t('discussion.revisions_empty') }));

            return;
        }

        body.replaceChildren(el('ol', { class: 'rr-revisions' }, revisions.map((revision) => {
            const content = el('div', { class: 'rr-prose' });
            content.innerHTML = sanitizeHtml(revision.bodyHtml, { mediaPrefixes: env.mediaPrefixes });

            return el('li', { class: 'rr-revision' }, [
                el('p', { class: 'rr-revision__title', text: env.t('discussion.revision', { number: revision.revision, date: formatDate(revision.editedAt, env.locale, { dateStyle: 'medium', timeStyle: 'short' }) }) }),
                content,
            ]);
        })));
    } catch (error) {
        body.replaceChildren(el('p', { class: 'rr-alert rr-alert--error', role: 'alert', text: errorText(error, env.t) }));
    }
}

const authorCards = new Map();

/** Profile hovercard on `[data-rr-author]` (hover with a delay, focus, Escape closes). */
export function bindHovercard(trigger, env) {
    if (trigger.dataset.rrHover) {
        return;
    }

    trigger.dataset.rrHover = '1';
    const host = trigger.closest('.rr-author');
    let card = null;
    let timer = null;

    const close = () => {
        clearTimeout(timer);
        card?.remove();
        card = null;
        trigger.setAttribute('aria-expanded', 'false');
    };

    const open = async () => {
        if (card) {
            return;
        }

        const id = trigger.dataset.rrAuthor;
        card = el('span', { class: 'rr-hovercard', role: 'group', 'aria-label': env.t('author.card', { name: trigger.textContent.trim() }) }, [el('span', { class: 'rr-hovercard__loading', text: env.t('common.loading') })]);
        card.id = `rr-hovercard-${id}-${Math.random().toString(36).slice(2, 7)}`;
        trigger.setAttribute('aria-controls', card.id);
        trigger.setAttribute('aria-expanded', 'true');
        host.append(card);
        card.addEventListener('mouseenter', () => clearTimeout(timer));
        card.addEventListener('mouseleave', () => (timer = setTimeout(close, 200)));

        if (!authorCards.has(id)) {
            authorCards.set(id, env.api.authorCard(id).catch(() => null));
        }

        const data = await authorCards.get(id);

        if (!card) {
            return;
        }

        const lines = [el('span', { class: 'rr-hovercard__name', text: data?.name ?? trigger.textContent.trim() })];

        if (data?.memberSince) {
            lines.push(el('span', { class: 'rr-hovercard__meta', text: env.t('author.member_since', { date: formatDate(data.memberSince, env.locale, { month: 'long', year: 'numeric' }) }) }));
        }

        if (data?.reviewsCount !== null && data?.reviewsCount !== undefined) {
            lines.push(el('span', { class: 'rr-hovercard__meta', text: env.tc('author.reviews', data.reviewsCount) }));
        }

        if (data?.commentsCount !== null && data?.commentsCount !== undefined) {
            lines.push(el('span', { class: 'rr-hovercard__meta', text: env.tc('author.comments', data.commentsCount) }));
        }

        if (data?.badges?.length) {
            lines.push(el('span', { class: 'rr-hovercard__badges', 'aria-label': env.t('author.badges') }, data.badges.map((badge) => el('span', { class: 'rr-badge', text: badge.label }))));
        }

        const actions = [];

        if (data?.followUrl) {
            actions.push(el('a', { href: data.followUrl, class: 'rr-button rr-button--small', text: env.t('author.follow') }));
        }

        if (data?.profileUrl) {
            actions.push(el('a', { href: data.profileUrl, class: 'rr-button rr-button--small rr-button--ghost', text: env.t('author.profile') }));
        }

        if (actions.length) {
            lines.push(el('span', { class: 'rr-hovercard__actions' }, actions));
        }

        card.replaceChildren(...lines);
    };

    host.addEventListener('mouseenter', () => {
        clearTimeout(timer);
        timer = setTimeout(open, 350);
    });
    host.addEventListener('mouseleave', () => {
        clearTimeout(timer);
        timer = setTimeout(close, 200);
    });
    trigger.addEventListener('focus', open);
    host.addEventListener('focusout', (event) => {
        if (!host.contains(event.relatedTarget)) {
            timer = setTimeout(close, 150);
        }
    });
    host.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && card) {
            event.stopPropagation();
            close();
            trigger.focus();
        }
    });

    if (trigger.tagName === 'BUTTON') {
        trigger.addEventListener('click', () => (card ? close() : open()));
    }
}
