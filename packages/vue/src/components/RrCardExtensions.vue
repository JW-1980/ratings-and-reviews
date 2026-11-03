<script setup lang="ts">
import { computed, useSlots } from 'vue';
import { useRr } from '../context';
import type { CardExtensionPosition, Comment, Review } from '../types';

/**
 * One host extension point on a review or comment card. The card's slot (default slot here)
 * wins; without it the components registered with `createRatingsAndReviews({ cardExtensions })`
 * render with props `item` and `position`. Nothing is rendered when neither exists. The wrapper
 * has the same class and data attributes as the Blade cards (`data-rr-extension`).
 */
const props = defineProps<{ position: CardExtensionPosition; item: Review | Comment }>();
const slots = useSlots();
const rr = useRr();

const extensions = computed(() => rr.cardExtensions?.[props.position] ?? []);
const kind = computed(() => props.position.split('.')[0]);
const place = computed(() => props.position.split('.')[1]);
const tag = computed(() => (place.value === 'meta' ? 'span' : 'div'));
</script>

<template>
    <component
        :is="tag"
        v-if="slots.default || extensions.length"
        :class="['rr-extensions', `rr-extensions--${place}`]"
        :data-rr-extension="position"
        :data-rr-item-type="kind"
        :data-rr-item-id="item.id"
    >
        <slot v-if="slots.default" />
        <template v-else>
            <component :is="extension.component" v-for="(extension, index) in extensions" :key="index" :item="item" :position="position" />
        </template>
    </component>
</template>
