/**
 * Mini poll in a comment: a question with 2–4 options, one vote per voter (enforced by the
 * server, unique poll + voter_key). Validation here mirrors the server rules so the
 * composer can explain problems before posting.
 */

export const POLL_MIN_OPTIONS = 2;
export const POLL_MAX_OPTIONS = 4;
/** Defaults of config `polls`; the discussion meta overrides the option counts. */
export const POLL_MAX_QUESTION = 255;
export const POLL_MAX_OPTION = 120;

/**
 * @param {{ question: string, options: string[] }} poll
 * @param {{ maxQuestion?: number, maxOption?: number, minOptions?: number, maxOptions?: number }} [limits]
 * @returns {{ valid: boolean, errors: Array<{ field: string, key: string, params?: Record<string, number> }> , options: string[] }}
 */
export function validatePoll(poll, limits = {}) {
    const maxQuestion = limits.maxQuestion ?? POLL_MAX_QUESTION;
    const maxOption = limits.maxOption ?? POLL_MAX_OPTION;
    const minOptions = limits.minOptions ?? POLL_MIN_OPTIONS;
    const maxOptions = limits.maxOptions ?? POLL_MAX_OPTIONS;
    const question = (poll?.question ?? '').trim();
    const options = (poll?.options ?? []).map((option) => String(option ?? '').trim()).filter((option) => option !== '');
    const errors = [];

    if (question === '') {
        errors.push({ field: 'question', key: 'poll.error.question_required' });
    } else if ([...question].length > maxQuestion) {
        errors.push({ field: 'question', key: 'poll.error.question_too_long', params: { max: maxQuestion } });
    }

    if (options.length < minOptions) {
        errors.push({ field: 'options', key: 'poll.error.too_few', params: { min: minOptions } });
    }

    if (options.length > maxOptions) {
        errors.push({ field: 'options', key: 'poll.error.too_many', params: { max: maxOptions } });
    }

    const lower = options.map((option) => option.toLocaleLowerCase());

    if (new Set(lower).size !== lower.length) {
        errors.push({ field: 'options', key: 'poll.error.duplicate' });
    }

    options.forEach((option, index) => {
        if ([...option].length > maxOption) {
            errors.push({ field: `options.${index}`, key: 'poll.error.option_too_long', params: { max: maxOption } });
        }
    });

    return { valid: errors.length === 0, errors, options };
}

/**
 * Percentages that add up to 100 (largest remainder), for the result bars.
 *
 * @param {Array<{ votes: number }>} options
 * @returns {number[]}
 */
export function pollPercentages(options) {
    const total = options.reduce((sum, option) => sum + Math.max(0, option.votes), 0);

    if (total === 0) {
        return options.map(() => 0);
    }

    const exact = options.map((option) => (Math.max(0, option.votes) / total) * 100);
    const floors = exact.map(Math.floor);
    let remaining = 100 - floors.reduce((sum, value) => sum + value, 0);
    const order = exact.map((value, index) => ({ index, remainder: value - floors[index] })).sort((a, b) => b.remainder - a.remainder);

    for (const entry of order) {
        if (remaining <= 0) {
            break;
        }

        floors[entry.index]++;
        remaining--;
    }

    return floors;
}

/**
 * Applies a vote locally (optimistic update), mirroring the server: voting again on the
 * same option keeps the vote (polls do not toggle), another option moves it.
 */
export function applyPollVote(poll, optionId) {
    if (poll.isClosed || poll.viewerOptionId === optionId) {
        return poll;
    }

    const options = poll.options.map((option) => {
        if (option.id === optionId) {
            return { ...option, votes: option.votes + 1 };
        }

        if (option.id === poll.viewerOptionId) {
            return { ...option, votes: Math.max(0, option.votes - 1) };
        }

        return option;
    });

    return { ...poll, options, viewerOptionId: optionId, totalVotes: poll.viewerOptionId === null ? poll.totalVotes + 1 : poll.totalVotes };
}
