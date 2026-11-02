<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRr } from '../context';
import { errorMessage, fieldErrors } from '../composables/errors';
import RrDialog from './RrDialog.vue';
import type { Option } from '../types';

const props = defineProps<{
    open: boolean;
    /** Morph alias of the reported item: 'rr_review' or 'rr_comment'. */
    reportableType: string;
    reportableId: number | null;
    reasons?: Option[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean]; reported: [message: string] }>();
const rr = useRr();

const reason = ref('');
const details = ref('');
const sending = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});
const reasons = computed(() => (props.reasons?.length ? props.reasons : rr.reportReasons));
const detailsRequired = computed(() => rr.reportDetailsRequiredFor.includes(reason.value));
const uid = `rr-report-${Math.random().toString(36).slice(2)}`;

watch(() => props.open, (open) => {
    if (open) {
        reason.value = '';
        details.value = '';
        error.value = '';
        errors.value = {};
    }
});

async function submit() {
    errors.value = {};
    error.value = '';

    if (!reason.value) {
        errors.value = { reason: rr.t('report.reason') };

        return;
    }

    if (detailsRequired.value && details.value.trim() === '') {
        errors.value = { details: rr.t('report.details_required') };

        return;
    }

    if (props.reportableId === null) {
        return;
    }

    sending.value = true;

    try {
        const result = await rr.api.report({ type: props.reportableType, id: props.reportableId, reason: reason.value, details: details.value.trim() || null });
        emit('reported', result.message ?? rr.t('report.success'));
        emit('update:open', false);
    } catch (failure) {
        errors.value = fieldErrors(failure);
        error.value = errorMessage(failure, rr.t);
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <RrDialog :open="open" :title="rr.t('report.title')" :described-by="`${uid}-intro`" @update:open="emit('update:open', $event)">
        <form class="rr-form rr-report" novalidate @submit.prevent="submit">
            <p :id="`${uid}-intro`" class="rr-help">{{ rr.t('report.intro') }}</p>
            <fieldset class="rr-fieldset" :aria-describedby="errors.reason ? `${uid}-reason-error` : undefined">
                <legend class="rr-label">{{ rr.t('report.reason') }}</legend>
                <label v-for="option in reasons" :key="option.value" class="rr-choice">
                    <input v-model="reason" type="radio" name="reason" :value="option.value" required>
                    <span>{{ option.label }}</span>
                </label>
                <p v-if="errors.reason" :id="`${uid}-reason-error`" class="rr-field-error">{{ errors.reason }}</p>
            </fieldset>
            <div class="rr-field">
                <label :for="`${uid}-details`" class="rr-label">
                    {{ rr.t('report.details') }}
                    <span v-if="!detailsRequired" class="rr-label__hint">{{ rr.t('report.details_optional') }}</span>
                </label>
                <textarea :id="`${uid}-details`" v-model="details" class="rr-textarea" rows="3" maxlength="1000" :required="detailsRequired" :aria-invalid="errors.details ? 'true' : undefined" :aria-describedby="errors.details ? `${uid}-details-error` : undefined" />
                <p v-if="errors.details" :id="`${uid}-details-error`" class="rr-field-error">{{ errors.details }}</p>
            </div>
            <p v-if="error" class="rr-alert rr-alert--error" role="alert">{{ error }}</p>
            <div class="rr-actions">
                <button type="button" class="rr-button rr-button--ghost" @click="emit('update:open', false)">{{ rr.t('common.cancel') }}</button>
                <button type="submit" class="rr-button rr-button--primary" :disabled="sending" :aria-busy="sending ? 'true' : undefined">{{ rr.t('report.submit') }}</button>
            </div>
        </form>
    </RrDialog>
</template>
