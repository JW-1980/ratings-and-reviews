/**
 * Shared, framework-agnostic browser modules of jw-1980/ratings-and-reviews. Plain ES
 * modules: the host bundles them (Vite, esbuild, …) with its own copies of the peer
 * libraries @tiptap/core, @tiptap/pm, @tiptap/starter-kit, @tiptap/extension-image and
 * dompurify.
 */

export { ApiError, createApiClient, readCookie } from './api.js';
export * as normalize from './normalize.js';
export { choose, createTranslator, replacePlaceholders } from './i18n.js';
export { ALL_TOOLS, CARD_PATTERN, LINK_REL, QUOTE_PATTERN, TOOL_ELEMENTS, createSanitizer, escapeHtml, plainText, renderSafeHtml, sanitizeHtml } from './sanitize.js';
export { DEFAULT_EMOJIS, TOOL_ORDER, cardQueryAt, createEditor, effectiveTools, extensionsFor, isAllowedImageSource, toolbarItems, ProductCard, QuoteReply, RestrictedImage, Sticker } from './editor/index.js';
export { codePointLength, containsWord, evaluateQuality, normalizeText } from './quality-meter.js';
export { createToneChecker, toneSuggestionFromError } from './tone-check.js';
export { POLL_MAX_OPTION, POLL_MAX_OPTIONS, POLL_MAX_QUESTION, POLL_MIN_OPTIONS, applyPollVote, pollPercentages, validatePoll } from './poll.js';
export { createUndoCountdown, secondsUntil } from './undo.js';
export { createDraftStore } from './draft.js';
export { MAX_QUOTE_LENGTH, quotableSelection } from './quote-selection.js';
export { formatDate, formatMoney, formatMonth, formatNumber, formatRelative, formatScore, sparklinePath } from './format.js';
export { DIMENSION_VALUES, allowsValue, toggleVote } from './votes.js';
export { hydrateCards } from './cards.js';
