<script setup lang="ts">
import { computed } from 'vue';
import { useRr } from '../context';
import type { Digest, DigestEntry } from '../types';

const props = defineProps<{ digest: Digest }>();
const emit = defineEmits<{ jump: [commentId: number] }>();
const rr = useRr();
const uid = `rr-digest-${Math.random().toString(36).slice(2)}`;

const votes = computed(() => (props.digest.agreement ? props.digest.agreement.agree + props.digest.agreement.disagree : 0));
const agreePercent = computed(() => (props.digest.agreement?.agreeShare === null || props.digest.agreement?.agreeShare === undefined ? 0 : Math.round(props.digest.agreement.agreeShare * 100)));
const groups = computed(() => [
    { key: 'agreeing', title: rr.t('digest.agreeing'), entries: props.digest.agreeing },
    { key: 'disagreeing', title: rr.t('digest.disagreeing'), entries: props.digest.disagreeing },
].filter((group) => group.entries.length));

function jump(entry: DigestEntry) {
    emit('jump', entry.commentId);
}
</script>

<template>
    <aside class="rr-digest" :aria-labelledby="`${uid}-title`">
        <h3 :id="`${uid}-title`" class="rr-digest__title">{{ rr.t('digest.title') }}</h3>
        <div v-if="votes > 0" class="rr-digest__ratio">
            <span class="rr-split" role="img" :aria-label="`${rr.t('digest.ratio_label', { total: votes })}: ${rr.t('digest.ratio', { agree: agreePercent, disagree: 100 - agreePercent })}`">
                <span class="rr-split__agree" :style="{ inlineSize: `${agreePercent}%` }" />
            </span>
            <span class="rr-digest__ratio-text" aria-hidden="true">{{ rr.t('digest.ratio', { agree: agreePercent, disagree: 100 - agreePercent }) }}</span>
        </div>
        <div class="rr-digest__groups">
            <section v-for="group in groups" :key="group.key" class="rr-digest__group" :data-stance="group.key">
                <h4 class="rr-digest__group-title">{{ group.title }}</h4>
                <ul>
                    <li v-for="entry in group.entries" :key="entry.commentId">
                        <q>{{ entry.excerpt }}</q>
                        <a :href="`#rr-comment-${entry.commentId}`" class="rr-digest__jump" @click.prevent="jump(entry)">{{ rr.t('digest.jump') }}</a>
                    </li>
                </ul>
            </section>
            <section v-if="digest.mostHelpful" class="rr-digest__group" data-stance="helpful">
                <h4 class="rr-digest__group-title">{{ rr.t('digest.most_helpful') }}</h4>
                <p>
                    <q>{{ digest.mostHelpful.excerpt }}</q>
                    <a :href="`#rr-comment-${digest.mostHelpful.commentId}`" class="rr-digest__jump" @click.prevent="jump(digest.mostHelpful)">{{ rr.t('digest.jump') }}</a>
                </p>
            </section>
        </div>
        <p class="rr-help">{{ rr.t('digest.based_on', { count: digest.total }) }}</p>
    </aside>
</template>
