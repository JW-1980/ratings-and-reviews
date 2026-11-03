import './shared/css/ratings-and-reviews.css';

export { createRatingsAndReviews, createContext, useRr, RR_CONTEXT, CARD_EXTENSION_POSITIONS, normalizeCardExtensions } from './context';
export type { RatingsAndReviewsOptions, RatingsAndReviewsContext, CardExtension, CardExtensionDefinition, CardExtensionOptions, CardExtensionRegistry } from './context';
export * from './types';

export { default as RrRatingSummary } from './components/RrRatingSummary.vue';
export { default as RrReviews } from './components/RrReviews.vue';
export { default as RrReviewCard } from './components/RrReviewCard.vue';
export { default as RrReviewForm } from './components/RrReviewForm.vue';
export { default as RrDiscussion } from './components/RrDiscussion.vue';
export { default as RrComment } from './components/RrComment.vue';
export { default as RrComposer } from './components/RrComposer.vue';
export { default as RrEditor } from './components/RrEditor.vue';
export { default as RrPoll } from './components/RrPoll.vue';
export { default as RrPollBuilder } from './components/RrPollBuilder.vue';
export { default as RrVoteButtons } from './components/RrVoteButtons.vue';
export { default as RrQualityMeter } from './components/RrQualityMeter.vue';
export { default as RrReportDialog } from './components/RrReportDialog.vue';
export { default as RrAuthorName } from './components/RrAuthorName.vue';
export { default as RrUndoToast } from './components/RrUndoToast.vue';
export { default as RrDigest } from './components/RrDigest.vue';
export { default as RrSearch } from './components/RrSearch.vue';
export { default as RrSparkline } from './components/RrSparkline.vue';
export { default as RrScaleInput } from './components/RrScaleInput.vue';
export { default as RrRichText } from './components/RrRichText.vue';
export { default as RrTopicRatings } from './components/RrTopicRatings.vue';
export { default as RrFollowUp } from './components/RrFollowUp.vue';
export { default as RrAttributionBadge } from './components/RrAttributionBadge.vue';
export { default as RrCardExtensions } from './components/RrCardExtensions.vue';

export {
    ApiError,
    createApiClient,
    createSanitizer,
    createTranslator,
    evaluateQuality,
    sanitizeHtml,
    validatePoll,
    toggleVote,
    createUndoCountdown,
} from './shared';
export type { ApiClient, ApiOptions, ApiErrorShape, EditorHandle, EditorOptions, Saved } from './shared';
