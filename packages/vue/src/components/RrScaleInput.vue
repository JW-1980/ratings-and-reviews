<script setup lang="ts">
import { useRr } from '../context';

/**
 * A score on a 1…max scale as a native radio group (arrow keys move, works in forms).
 * Five-point scales show stars, ten-point scales numbers.
 */
const props = withDefaults(defineProps<{ modelValue: number | null; max: number; name: string; legend: string; required?: boolean; clearable?: boolean; clearLabel?: string; invalid?: boolean; describedBy?: string }>(), { required: false, clearable: false, clearLabel: '', invalid: false, describedBy: undefined });
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();
const rr = useRr();
</script>

<template>
    <fieldset class="rr-scale" :class="[`rr-scale--${max}`, { 'is-invalid': invalid }]" :aria-describedby="describedBy" :aria-invalid="invalid ? 'true' : undefined">
        <legend class="rr-label">{{ legend }}</legend>
        <div class="rr-scale__options">
            <label v-if="clearable" class="rr-scale__option rr-scale__option--clear">
                <input type="radio" :name="name" value="" :checked="modelValue === null" @change="emit('update:modelValue', null)">
                <span class="rr-scale__mark" aria-hidden="true">–</span>
                <span class="rr-visually-hidden">{{ clearLabel }}</span>
            </label>
            <label v-for="value in max" :key="value" class="rr-scale__option" :class="{ 'is-filled': modelValue !== null && value <= modelValue }">
                <input type="radio" :name="name" :value="value" :checked="modelValue === value" :required="required && value === 1" @change="emit('update:modelValue', value)">
                <span class="rr-scale__mark" aria-hidden="true">{{ max <= 5 ? '★' : value }}</span>
                <span class="rr-visually-hidden">{{ rr.t('form.rating_option', { value, max: props.max }) }}</span>
            </label>
        </div>
    </fieldset>
</template>
