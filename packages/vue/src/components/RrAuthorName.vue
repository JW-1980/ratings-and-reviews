<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRr } from '../context';
import { formatDate } from '../shared';
import { authorCardCache as cache } from '../composables/cache';
import type { Author, AuthorCard } from '../types';

/**
 * Author name with a profile hovercard (GET authors/{id}/card). Opens on hover after a
 * short delay, on focus and on tap; Escape closes it and returns focus to the name.
 */
const props = defineProps<{ author: Author | null }>();
const rr = useRr();

const open = ref(false);
const card = ref<AuthorCard | null>(null);
const failed = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const id = `rr-author-${Math.random().toString(36).slice(2)}`;
let timer: ReturnType<typeof setTimeout> | null = null;

const name = computed(() => props.author?.name || rr.t('reviews.anonymous'));

async function load() {
    if (!props.author || card.value) {
        return;
    }

    const key = String(props.author.id);

    if (!cache.has(key)) {
        cache.set(key, rr.api.authorCard(props.author.id).catch(() => null));
    }

    card.value = (await cache.get(key)) as AuthorCard | null;
    failed.value = card.value === null;
}

function show(delay = 0) {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        open.value = true;
        load();
    }, delay);
}

function hide(delay = 0) {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        open.value = false;
    }, delay);
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && open.value) {
        event.stopPropagation();
        open.value = false;
        trigger.value?.focus();
    }
}

onBeforeUnmount(() => timer && clearTimeout(timer));
</script>

<template>
    <span class="rr-author" @mouseenter="show(350)" @mouseleave="hide(200)" @keydown="onKeydown">
        <img v-if="author?.avatarUrl" :src="author.avatarUrl" alt="" class="rr-avatar" loading="lazy" width="32" height="32">
        <span v-else class="rr-avatar rr-avatar--initial" aria-hidden="true">{{ name.charAt(0).toUpperCase() }}</span>
        <button
            v-if="author"
            ref="trigger"
            type="button"
            class="rr-author__name"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="id"
            @click="open ? hide() : show()"
            @focus="show(0)"
            @blur="hide(250)"
        >{{ name }}</button>
        <span v-else class="rr-author__name">{{ name }}</span>
        <span v-if="author && open" :id="id" class="rr-hovercard" role="group" :aria-label="rr.t('author.card', { name })" @mouseenter="show()" @mouseleave="hide(200)" @focusin="show()" @focusout="hide(250)">
            <span v-if="!card && !failed" class="rr-hovercard__loading">{{ rr.t('common.loading') }}</span>
            <template v-else>
                <span class="rr-hovercard__name">{{ card?.name ?? name }}</span>
                <span v-if="card?.memberSince" class="rr-hovercard__meta">{{ rr.t('author.member_since', { date: formatDate(card.memberSince, rr.locale, { month: 'long', year: 'numeric' }) }) }}</span>
                <span v-if="card?.reviewsCount !== null && card?.reviewsCount !== undefined" class="rr-hovercard__meta">{{ rr.tc('author.reviews', card.reviewsCount) }}</span>
                <span v-if="card?.commentsCount !== null && card?.commentsCount !== undefined" class="rr-hovercard__meta">{{ rr.tc('author.comments', card.commentsCount) }}</span>
                <span v-if="card?.badges.length" class="rr-hovercard__badges" :aria-label="rr.t('author.badges')">
                    <span v-for="badge in card.badges" :key="badge.label" class="rr-badge">{{ badge.label }}</span>
                </span>
                <span class="rr-hovercard__actions">
                    <a v-if="card?.followUrl" :href="card.followUrl" class="rr-button rr-button--small">{{ rr.t('author.follow') }}</a>
                    <a v-if="card?.profileUrl ?? author.profileUrl" :href="(card?.profileUrl ?? author.profileUrl)!" class="rr-button rr-button--small rr-button--ghost">{{ rr.t('author.profile') }}</a>
                </span>
            </template>
        </span>
    </span>
</template>
