/**
 * Vote toggling, as VoteService does it: per dimension at most one vote per voter; the
 * same value again withdraws it, the other value switches. "Needs a source" only has +1.
 * Used for the optimistic update; the server's answer (value, counts) replaces it.
 */

export const DIMENSION_VALUES = {
    agreement: [1, -1],
    helpfulness: [1, -1],
    needs_source: [1],
};

/**
 * @param {{ value: number, up: number, down: number }} state current viewer value (0 = none) and counts
 * @param {number} pressed 1 or -1
 * @returns {{ value: number, up: number, down: number }}
 */
export function toggleVote(state, pressed) {
    let { value, up, down } = state;

    if (value === 1) {
        up = Math.max(0, up - 1);
    } else if (value === -1) {
        down = Math.max(0, down - 1);
    }

    const next = value === pressed ? 0 : pressed;

    if (next === 1) {
        up += 1;
    } else if (next === -1) {
        down += 1;
    }

    return { value: next, up, down };
}

/** Whether a dimension accepts the value. */
export function allowsValue(dimension, value) {
    return (DIMENSION_VALUES[dimension] ?? []).includes(value);
}
