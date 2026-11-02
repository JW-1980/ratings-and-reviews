<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRr } from '../context';
import { useAnnouncer } from '../composables/useAnnouncer';
import { errorMessage } from '../composables/errors';
import { formatScore } from '../shared';
import RrScaleInput from './RrScaleInput.vue';
import type { SubjectRef, TopicRatingState } from '../types';

/**
 * Scores per rating topic without writing a review (section `standalone_topic_ratings`):
 * every topic with its average, and for a member a scale per topic and one save button.
 * Renders nothing while the section has it switched off.
 */
const props = defineProps<{ subject: SubjectRef }>();
const emit = defineEmits<{ saved: [state: TopicRatingState] }>();
const rr = useRr();
const { message: live, announce } = useAnnouncer();

const state = ref<TopicRatingState | null>(null);
const draft = ref<Record<string, number | null>>({});
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const uid = `rr-topic-ratings-${Math.random().toString(36).slice(2)}`;

const changed = computed(() => {
    const own = state.value?.own ?? {};

    return Object.entries(draft.value).filter(([topicId, value]) => (own[topicId] ?? null) !== value);
});

async function load() {
    loading.value = true;
    error.value = '';

    try {
        state.value = await rr.api.topicRatings(props.subject);
        draft.value = Object.fromEntries((state.value.topics ?? []).map((topic) => [String(topic.id), state.value?.own?.[String(topic.id)] ?? null]));
    } catch (failure) {
        error.value = errorMessage(failure, rr.t);
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (saving.value || changed.value.length === 0) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        const result = await rr.api.saveTopicRatings(props.subject, Object.fromEntries(changed.value));
        state.value = result.state;
        draft.value = Object.fromEntries(result.state.topics.map((topic) => [String(topic.id), result.state.own?.[String(topic.id)] ?? null]));
        announce(result.message ?? rr.t('topic_ratings.saved'));
        emit('saved', result.state);
    } catch (failure) {
        error.value = errorMessage(failure, rr.t);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
watch(() => [props.subject.type, props.subject.id], load);
</script>

<template>
    <section v-if="state?.enabled && state.topics.length" class="rr rr-topic-ratings" :aria-labelledby="`${uid}-title`" :aria-busy="loading || saving ? 'true' : 'false'">
        <h2 :id="`${uid}-title`" class="rr-section-header__title">{{ rr.t('topic_ratings.title') }}</h2>
        <p class="rr-help">{{ rr.t('topic_ratings.intro') }}</p>
        <p class="rr-visually-hidden" aria-live="polite" role="status">{{ live }}</p>
        <p v-if="error" class="rr-alert rr-alert--error" role="alert">{{ error }}</p>

        <form class="rr-topic-ratings__form" @submit.prevent="save">
            <ul class="rr-topic-ratings__list">
                <li v-for="topic in state.topics" :key="topic.id" class="rr-topic-ratings__item">
                    <RrScaleInput
                        v-if="rr.isAuthenticated && state.acceptsRatings"
                        :model-value="draft[String(topic.id)] ?? null"
                        :max="topic.scaleMax"
                        :name="`${uid}-${topic.id}`"
                        :legend="topic.name"
                        clearable
                        :clear-label="rr.t('topic_ratings.no_score')"
                        :described-by="`${uid}-${topic.id}-average`"
                        @update:model-value="draft[String(topic.id)] = $event"
                    />
                    <span v-else class="rr-label">{{ topic.name }}</span>
                    <span :id="`${uid}-${topic.id}-average`" class="rr-topic-ratings__average">
                        {{ rr.tc('topic_ratings.average', topic.count, { average: topic.average === null ? '' : formatScore(topic.average, rr.locale), max: topic.scaleMax }) }}
                    </span>
                </li>
            </ul>

            <div class="rr-topic-ratings__actions">
                <button v-if="rr.isAuthenticated && state.acceptsRatings" type="submit" class="rr-button rr-button--primary" :disabled="saving || changed.length === 0">
                    {{ saving ? rr.t('topic_ratings.saving') : rr.t('topic_ratings.save') }}
                </button>
                <p v-else-if="!rr.isAuthenticated && state.acceptsRatings" class="rr-help">
                    <a v-if="rr.loginUrl" :href="rr.loginUrl">{{ rr.t('topic_ratings.login') }}</a>
                    <template v-else>{{ rr.t('topic_ratings.login') }}</template>
                </p>
                <p v-else class="rr-help">{{ rr.t('topic_ratings.closed') }}</p>
            </div>
        </form>
    </section>
</template>
