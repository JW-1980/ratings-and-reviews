<script setup lang="ts">
import { ref } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import RrRichText from './RrRichText.vue';
import type { SearchResults, SubjectRef } from '../types';

const props = withDefaults(defineProps<{ subject: SubjectRef; minLength?: number }>(), { minLength: 3 });
const emit = defineEmits<{ open: [hit: { type: string; id: number }] }>();
const rr = useRr();
const uid = `rr-search-${Math.random().toString(36).slice(2)}`;

const query = ref('');
const results = ref<SearchResults | null>(null);
const loading = ref(false);
const error = ref('');
let controller: AbortController | null = null;

async function search() {
    const q = query.value.trim();
    error.value = '';

    if ([...q].length < props.minLength) {
        error.value = rr.t('search.too_short', { min: props.minLength });

        return;
    }

    controller?.abort();
    controller = new AbortController();
    loading.value = true;

    try {
        results.value = (await rr.api.search(props.subject, q, controller.signal)) as SearchResults;
    } catch (failure) {
        if ((failure as Error)?.name !== 'AbortError') {
            error.value = errorMessage(failure, rr.t);
        }
    } finally {
        loading.value = false;
    }
}

function clear() {
    controller?.abort();
    query.value = '';
    results.value = null;
    error.value = '';
}
</script>

<template>
    <div class="rr-search">
        <form role="search" class="rr-search__form" :aria-label="rr.t('search.label')" @submit.prevent="search">
            <label :for="`${uid}-q`" class="rr-visually-hidden">{{ rr.t('search.label') }}</label>
            <input :id="`${uid}-q`" v-model="query" type="search" class="rr-input" :placeholder="rr.t('search.placeholder')" :aria-describedby="error ? `${uid}-error` : undefined" @keydown.esc="clear">
            <button type="submit" class="rr-button" :aria-busy="loading ? 'true' : undefined">{{ rr.t('search.submit') }}</button>
            <button v-if="results" type="button" class="rr-button rr-button--ghost" @click="clear">{{ rr.t('search.clear') }}</button>
        </form>
        <p v-if="error" :id="`${uid}-error`" class="rr-field-error" role="alert">{{ error }}</p>
        <div v-if="results" class="rr-search__results" aria-live="polite">
            <p class="rr-search__summary">{{ rr.tc('search.results', results.total, { query: results.query }) }}</p>
            <ol class="rr-search__list">
                <li v-for="hit in results.items" :key="`${hit.type}-${hit.id}`" class="rr-search__hit">
                    <span class="rr-tag">{{ rr.t(`search.${hit.type === 'review' ? 'review' : 'comment'}`) }}</span>
                    <RrRichText v-if="hit.titleHtml" :html="hit.titleHtml" tag="p" :extra-tags="['mark']" :cards="false" class="rr-search__title" />
                    <RrRichText :html="hit.snippetHtml" tag="p" :extra-tags="['mark']" :cards="false" class="rr-search__snippet" />
                    <a :href="hit.type === 'review' ? `#rr-review-${hit.id}` : `#rr-comment-${hit.id}`" class="rr-button rr-button--link" @click="emit('open', { type: hit.type, id: hit.id })">{{ rr.t('search.open') }}</a>
                </li>
            </ol>
        </div>
    </div>
</template>
