<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { applyPollVote, pollPercentages } from '../shared';
import type { Poll } from '../types';

const props = defineProps<{ poll: Poll }>();
const emit = defineEmits<{ message: [text: string] }>();
const rr = useRr();
const state = ref<Poll>(props.poll);
const busy = ref(false);
const uid = `rr-poll-${props.poll.id}`;

watch(() => props.poll, (poll) => (state.value = poll));

const showResults = computed(() => state.value.isClosed || state.value.viewerOptionId !== null);
const percentages = computed(() => pollPercentages(state.value.options));

async function vote(optionId: number) {
    if (busy.value || state.value.isClosed) {
        return;
    }

    if (!rr.isAuthenticated) {
        emit('message', rr.t('votes.login'));
    }

    const before = state.value;
    state.value = applyPollVote(before, optionId) as Poll;
    busy.value = true;

    try {
        const result = await rr.api.votePoll(state.value.id, optionId);

        if (result.poll) {
            state.value = result.poll as Poll;
        }

        if (!result.counted) {
            emit('message', rr.t('votes.not_counted'));
        }
    } catch (failure) {
        state.value = before;
        emit('message', errorMessage(failure, rr.t));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <fieldset class="rr-poll" :aria-describedby="`${uid}-total`" :aria-busy="busy ? 'true' : undefined">
        <legend class="rr-poll__question">{{ state.question }}</legend>
        <ul class="rr-poll__options">
            <li v-for="(option, index) in state.options" :key="option.id" class="rr-poll__option" :class="{ 'is-chosen': state.viewerOptionId === option.id }">
                <button
                    type="button"
                    class="rr-poll__button"
                    :aria-pressed="state.viewerOptionId === option.id ? 'true' : 'false'"
                    :disabled="state.isClosed"
                    :aria-label="showResults ? rr.t('poll.result', { label: option.label, percent: percentages[index], count: option.votes }) : option.label"
                    @click="vote(option.id)"
                >
                    <span class="rr-poll__label" aria-hidden="true">{{ option.label }}</span>
                    <template v-if="showResults">
                        <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" :style="{ inlineSize: `${percentages[index]}%` }" /></span>
                        <span class="rr-poll__percent" aria-hidden="true">{{ percentages[index] }}%</span>
                    </template>
                    <span v-if="state.viewerOptionId === option.id" class="rr-tag">{{ rr.t('poll.your_vote') }}</span>
                </button>
            </li>
        </ul>
        <p :id="`${uid}-total`" class="rr-poll__total" aria-live="polite">
            {{ rr.tc('poll.votes', state.totalVotes) }}<template v-if="state.isClosed"> · {{ rr.t('poll.closed') }}</template>
        </p>
    </fieldset>
</template>
