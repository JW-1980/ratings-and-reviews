<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { toggleVote } from '../shared';
import type { VoteCount, VoteResult } from '../types';

const props = withDefaults(defineProps<{
    target: 'comment' | 'review';
    targetId: number;
    dimensions: string[];
    counts: Record<string, VoteCount>;
    viewerVotes: Record<string, number>;
    isOwn?: boolean;
    guestVotes?: boolean;
    /** Reviews only have the positive helpfulness vote. */
    positiveOnly?: boolean;
    /** The author is in a block relation with the viewer: shown, not votable. */
    blocked?: boolean;
}>(), { isOwn: false, guestVotes: false, positiveOnly: false, blocked: false });

const emit = defineEmits<{ voted: [result: VoteResult]; message: [text: string] }>();
const rr = useRr();

const state = ref<Record<string, { value: number; up: number; down: number }>>({});
const busy = ref<string | null>(null);
const note = ref('');

function reset() {
    state.value = Object.fromEntries(props.dimensions.map((dimension) => [dimension, {
        value: props.viewerVotes[dimension] ?? 0,
        up: props.counts[dimension]?.up ?? 0,
        down: props.counts[dimension]?.down ?? 0,
    }]));
}

watch(() => [props.counts, props.viewerVotes, props.dimensions], reset, { immediate: true, deep: true });

const buttons = computed(() => props.dimensions.flatMap((dimension) => {
    const labels: Record<string, Array<[number, string]>> = {
        agreement: [[1, 'votes.agree'], [-1, 'votes.disagree']],
        helpfulness: props.positiveOnly ? [[1, 'votes.helpful']] : [[1, 'votes.helpful'], [-1, 'votes.unhelpful']],
        needs_source: [[1, 'votes.needs_source']],
    };

    return (labels[dimension] ?? []).map(([value, label]) => ({ dimension, value, label, key: `${dimension}:${value}` }));
}));

const blockedReason = computed(() => {
    if (props.isOwn) {
        return rr.t('votes.own');
    }

    if (props.blocked) {
        return rr.t('votes.blocked');
    }

    if (!rr.isAuthenticated && !props.guestVotes) {
        return rr.t('votes.login');
    }

    return null;
});

function countFor(dimension: string, value: number): number {
    const entry = state.value[dimension];

    return entry ? (value === 1 ? entry.up : entry.down) : 0;
}

async function vote(dimension: string, value: number) {
    if (blockedReason.value) {
        note.value = blockedReason.value;
        emit('message', blockedReason.value);

        return;
    }

    if (busy.value) {
        return;
    }

    const before = { ...state.value[dimension] };
    state.value[dimension] = toggleVote(before, value);
    busy.value = `${dimension}:${value}`;
    note.value = '';

    try {
        const result = props.target === 'comment'
            ? await rr.api.voteComment(props.targetId, dimension, value)
            : await rr.api.voteReview(props.targetId, dimension, value);

        state.value[dimension] = { value: result.value, up: result.counts.up, down: result.counts.down };

        if (!result.counted && result.value !== 0) {
            note.value = rr.t('votes.not_counted');
        }

        emit('voted', result as VoteResult);
    } catch (error) {
        state.value[dimension] = before;
        note.value = errorMessage(error, rr.t);
        emit('message', note.value);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="rr-votes" role="group" :aria-label="rr.t('votes.group')">
        <button
            v-for="button in buttons"
            :key="button.key"
            type="button"
            class="rr-vote"
            :class="[`rr-vote--${button.dimension}`, button.value === 1 ? 'rr-vote--up' : 'rr-vote--down']"
            :aria-pressed="state[button.dimension]?.value === button.value ? 'true' : 'false'"
            :aria-disabled="blockedReason ? 'true' : undefined"
            :aria-busy="busy === button.key ? 'true' : undefined"
            :data-dimension="button.dimension"
            :data-value="button.value"
            :aria-label="rr.t('votes.count', { label: rr.t(button.label), count: countFor(button.dimension, button.value) })"
            @click="vote(button.dimension, button.value)"
        >
            <span class="rr-vote__label" aria-hidden="true">{{ rr.t(button.label) }}</span>
            <span class="rr-vote__count" aria-hidden="true">{{ countFor(button.dimension, button.value) }}</span>
        </button>
        <p v-if="note" class="rr-votes__note" role="status">{{ note }}</p>
    </div>
</template>
