<script setup lang="ts">
import { computed, nextTick, useId } from 'vue';
import { useRr } from '../context';
import { POLL_MAX_OPTIONS, POLL_MAX_OPTION, POLL_MAX_QUESTION, POLL_MIN_OPTIONS, validatePoll } from '../shared';

const props = withDefaults(defineProps<{ modelValue: { question: string; options: string[] }; showErrors?: boolean; minOptions?: number; maxOptions?: number }>(), { showErrors: false, minOptions: POLL_MIN_OPTIONS, maxOptions: POLL_MAX_OPTIONS });
const emit = defineEmits<{ 'update:modelValue': [value: { question: string; options: string[] }]; remove: [] }>();
const rr = useRr();
const uid = `rr-poll-builder-${useId()}`;

const validation = computed(() => validatePoll(props.modelValue, { minOptions: props.minOptions, maxOptions: props.maxOptions }));
const errorsFor = (field: string) => validation.value.errors.filter((error: { field: string }) => error.field === field || (field === 'options' && error.field.startsWith('options.')));

function update(patch: Partial<{ question: string; options: string[] }>) {
    emit('update:modelValue', { ...props.modelValue, ...patch });
}

function setOption(index: number, value: string) {
    const options = [...props.modelValue.options];
    options[index] = value;
    update({ options });
}

function addOption() {
    if (props.modelValue.options.length < props.maxOptions) {
        const index = props.modelValue.options.length;
        update({ options: [...props.modelValue.options, ''] });
        nextTick(() => document.getElementById(`${uid}-option-${index}`)?.focus());
    }
}

function removeOption(index: number) {
    if (props.modelValue.options.length > props.minOptions) {
        update({ options: props.modelValue.options.filter((_, position) => position !== index) });
    }
}

defineExpose({ validation });
</script>

<template>
    <fieldset class="rr-poll-builder">
        <legend class="rr-label">{{ rr.t('poll.title') }}</legend>
        <div class="rr-field">
            <label :for="`${uid}-question`" class="rr-label">{{ rr.t('poll.question') }}</label>
            <input
                :id="`${uid}-question`"
                type="text"
                class="rr-input"
                :maxlength="POLL_MAX_QUESTION"
                :value="modelValue.question"
                :aria-invalid="showErrors && errorsFor('question').length ? 'true' : undefined"
                :aria-describedby="showErrors && errorsFor('question').length ? `${uid}-question-error` : undefined"
                @input="update({ question: ($event.target as HTMLInputElement).value })"
            >
            <p v-if="showErrors && errorsFor('question').length" :id="`${uid}-question-error`" class="rr-field-error">{{ rr.t(errorsFor('question')[0].key, errorsFor('question')[0].params) }}</p>
        </div>
        <div v-for="(option, index) in modelValue.options" :key="index" class="rr-input-row">
            <label :for="`${uid}-option-${index}`" class="rr-visually-hidden">{{ rr.t('poll.option', { number: index + 1 }) }}</label>
            <input :id="`${uid}-option-${index}`" type="text" class="rr-input" :maxlength="POLL_MAX_OPTION" :placeholder="rr.t('poll.option', { number: index + 1 })" :value="option" @input="setOption(index, ($event.target as HTMLInputElement).value)">
            <button v-if="modelValue.options.length > minOptions" type="button" class="rr-icon-button" :aria-label="rr.t('poll.remove_option', { number: index + 1 })" @click="removeOption(index)">×</button>
        </div>
        <p v-if="showErrors && errorsFor('options').length" class="rr-field-error" role="alert">{{ rr.t(errorsFor('options')[0].key, errorsFor('options')[0].params) }}</p>
        <div class="rr-actions rr-actions--start">
            <button v-if="modelValue.options.length < maxOptions" type="button" class="rr-button rr-button--small rr-button--ghost" @click="addOption">{{ rr.t('poll.add_option') }}</button>
            <button type="button" class="rr-button rr-button--small rr-button--ghost" @click="emit('remove')">{{ rr.t('composer.remove_poll') }}</button>
        </div>
    </fieldset>
</template>
