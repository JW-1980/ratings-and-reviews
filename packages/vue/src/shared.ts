/**
 * Typed entry to the shared browser modules (resources/js, copied to ./shared). The JS
 * modules carry JSDoc only; this file states their TypeScript signatures once, in terms of
 * ./types, so the components and the package's public types are checked.
 */
import * as js from './shared/index.js';
import type {
    AdminSticker,
    AuthorCard,
    CommentPage,
    Digest,
    Comment,
    Money,
    Poll,
    ProductCard,
    QualityResult,
    QualityRules,
    RatingSummary,
    ReplyPage,
    Review,
    ReviewPage,
    Revision,
    SearchResults,
    Sticker,
    SubjectRef,
    ToneResult,
    TopicRatingState,
    TrendPoint,
    Upload,
    VoteResult,
} from './types';

export interface Saved<T> {
    item: T;
    message: string | null;
    quality: { score: number; level: string | null; levelLabel: string | null } | null;
}

export interface ReviewDraftPayload {
    rating: number;
    title?: string | null;
    body?: string | null;
    pros?: string[];
    cons?: string[];
    topicRatings?: Record<string, number | null>;
    facets?: Record<string, string | null>;
    comparison?: { type: string; id: string | number; verdict: string } | null;
    /** Upload ids in display order; leave out to keep the current photos. */
    photos?: Array<number | string>;
    /** Question key => value (a number for a scale, an option value, or a list for several); leave out to keep the stored answers. */
    answers?: Record<string, number | string | string[] | null>;
    /** Post "as" this key (meta.attributions); leave out of an edit to keep it, null clears it. */
    attribution?: string | null;
    confirmTone?: boolean;
    formStartedAt?: number;
}

export interface CommentDraftPayload {
    body: string;
    parentId?: number | null;
    quote?: { commentId: number | string; text: string } | null;
    poll?: { question: string; options: string[]; closesAt?: string | null } | null;
    /** Post "as" this key (meta.attributions). */
    attribution?: string | null;
    confirmTone?: boolean;
    formStartedAt?: number;
}

export interface ApiOptions {
    baseUrl?: string;
    csrfToken?: string | (() => string);
    xsrfCookie?: string;
    headers?: Record<string, string>;
    locale?: string;
    credentials?: RequestCredentials;
    fetch?: typeof fetch;
}

export interface ApiClient {
    request(method: string, path: string, init?: { query?: Record<string, unknown>; body?: unknown; form?: FormData; signal?: AbortSignal }): Promise<unknown>;
    ratingSummary(subject: SubjectRef, signal?: AbortSignal): Promise<RatingSummary>;
    reviews(subject: SubjectRef, query?: { sort?: string; rating?: number | null; facets?: Record<string, string>; withPhotos?: boolean; page?: number }, signal?: AbortSignal): Promise<ReviewPage>;
    topicRatings(subject: SubjectRef, signal?: AbortSignal): Promise<TopicRatingState>;
    saveTopicRatings(subject: SubjectRef, changes: Record<string, number | null>): Promise<{ state: TopicRatingState; message: string | null }>;
    reviewRepliesSubject(reviewId: number): SubjectRef;
    createReview(subject: SubjectRef, payload: ReviewDraftPayload): Promise<Saved<Review>>;
    updateReview(id: number, payload: ReviewDraftPayload): Promise<Saved<Review>>;
    deleteReview(id: number): Promise<void>;
    voteReview(id: number, dimension: string, value: number): Promise<VoteResult>;
    createFollowUp(reviewId: number, draft: { rating: number; body?: string | null; confirmTone?: boolean }): Promise<Saved<Review>>;
    deleteFollowUp(reviewId: number): Promise<void>;
    pinComment(id: number, pinned?: boolean): Promise<{ isPinned: boolean; message: string | null }>;
    comments(subject: SubjectRef, query?: { sort?: string; page?: number; mark_read?: 0 | 1 }, signal?: AbortSignal): Promise<CommentPage>;
    createComment(subject: SubjectRef, payload: CommentDraftPayload): Promise<Saved<Comment>>;
    updateComment(id: number, body: string, confirmTone?: boolean): Promise<Saved<Comment>>;
    deleteComment(id: number): Promise<void>;
    undoComment(id: number): Promise<void>;
    replies(commentId: number | string, query?: { cursor?: string | null; after?: number | null; limit?: number }, signal?: AbortSignal): Promise<ReplyPage>;
    revisions(id: number, signal?: AbortSignal): Promise<Revision[]>;
    voteComment(id: number, dimension: string, value: number): Promise<VoteResult>;
    votePoll(pollId: number, optionId: number): Promise<{ poll: Poll | null; counted: boolean; changed: boolean }>;
    poll(pollId: number, signal?: AbortSignal): Promise<Poll | null>;
    digest(subject: SubjectRef, signal?: AbortSignal): Promise<Digest | null>;
    search(subject: SubjectRef, q: string, signal?: AbortSignal, scope?: 'all' | 'reviews' | 'comments'): Promise<SearchResults>;
    report(report: { type: string; id: number; reason: string; details?: string | null }): Promise<{ id: number | null; message: string | null }>;
    authorCard(id: number | string, signal?: AbortSignal): Promise<AuthorCard | null>;
    card(type: string, id: string | number, signal?: AbortSignal): Promise<ProductCard | null>;
    wantToTry(subject: SubjectRef, reviewId?: number | null): Promise<{ added: boolean; message: string | null }>;
    toneCheck(text: string, signal?: AbortSignal): Promise<ToneResult>;
    qualityRules(signal?: AbortSignal): Promise<QualityRules | null>;
    uploadImage(file: Blob, alt?: string | null): Promise<Upload>;
    stickers(signal?: AbortSignal): Promise<Sticker[]>;
    adminStickers(signal?: AbortSignal): Promise<AdminSticker[]>;
    createSticker(sticker: { image?: Blob; path?: string; name: { nl: string; en: string }; isActive?: boolean; sortOrder?: number }): Promise<AdminSticker>;
    updateSticker(id: number, changes: { name?: { nl: string; en: string }; isActive?: boolean; sortOrder?: number }): Promise<AdminSticker>;
    deleteSticker(id: number, options?: { deleteFile?: boolean }): Promise<{ deleted: boolean; fileDeleted: boolean }>;
    adminReviewQuestions(query?: { scope?: string; category_key?: string }, signal?: AbortSignal): Promise<Array<Record<string, unknown>>>;
    createReviewQuestion(question: Record<string, unknown>): Promise<Record<string, unknown> | null>;
    updateReviewQuestion(id: number, changes: Record<string, unknown>): Promise<Record<string, unknown> | null>;
    deleteReviewQuestion(id: number): Promise<{ answered_reviews: number } | null>;
}

export interface ApiErrorShape extends Error {
    status: number;
    errors: Record<string, string[]>;
    retryAfter: number | null;
    data: unknown;
    first(field: string): string | null;
    readonly isValidation: boolean;
    readonly isUnauthenticated: boolean;
    readonly isThrottled: boolean;
    readonly isNetwork: boolean;
}

export interface EditorToolbarItem {
    tool: string;
    label: string;
    kind: 'toggle' | 'link' | 'emoji' | 'image' | 'sticker' | 'card';
    shortcut: string | null;
    run: ((editor: unknown) => boolean) | null;
    active: (editor: unknown) => boolean;
    locked: number | null;
}

export interface EditorHandle {
    editor: {
        isEmpty: boolean;
        view: { dom: HTMLElement };
        on(event: string, callback: () => void): void;
        setEditable(editable: boolean, emitUpdate?: boolean): void;
    };
    tools: string[];
    toolbar: EditorToolbarItem[];
    getHTML(): string;
    getText(): string;
    isEmpty(): boolean;
    focus(): boolean;
    clear(): boolean;
    setContent(html: string): boolean;
    insertQuote(quote: { commentId: string | number; text: string }): boolean;
    insertCard(card: { type: string; id: string | number; title?: string; url?: string | null }, range?: { from: number; to: number } | null): boolean;
    insertSticker(sticker: { id?: string | number; name?: string; url: string }): boolean;
    insertImage(image: { url: string; alt?: string }): boolean;
    insertText(text: string): boolean;
    setLink(url: string): boolean;
    currentLink(): string;
    destroy(): void;
}

export interface EditorOptions {
    element: HTMLElement;
    tools: string[];
    lockedTools?: Record<string, number>;
    content?: string;
    label?: string;
    describedBy?: string;
    uploadPrefixes?: string[];
    stickers?: Array<{ id: string | number; name: string; url: string }>;
    getStickers?: () => Array<{ id: string | number; name: string; url: string }>;
    editable?: boolean;
    onUpdate?: (state: { html: string; text: string; isEmpty: boolean }) => void;
    onCardQuery?: (query: { query: string; from: number; to: number } | null) => void;
    onKeyDown?: (event: KeyboardEvent) => boolean;
    onSubmitShortcut?: () => void;
    onFocusChange?: (state: { focused: boolean }) => void;
}

export interface PollValidation {
    valid: boolean;
    errors: Array<{ field: string; key: string; params?: Record<string, number> }>;
    options: string[];
}

export interface QualityDraft {
    body?: string;
    pros?: string[] | number;
    cons?: string[] | number;
    topicRatings?: Record<string, number | null> | number;
    topicCount?: number;
    extendedFields?: boolean;
}

export const ApiError = js.ApiError as unknown as { new (message: string, details: { status: number; errors?: Record<string, string[]>; retryAfter?: number | null; data?: unknown }): ApiErrorShape; prototype: ApiErrorShape };
export const createApiClient = js.createApiClient as unknown as (options?: ApiOptions) => ApiClient;
export const createTranslator = js.createTranslator as unknown as (messages: Record<string, unknown>, options?: { onMissing?: (key: string) => void }) => {
    t: (key: string, replace?: Record<string, string | number>) => string;
    tc: (key: string, count: number, replace?: Record<string, string | number>) => string;
    has: (key: string) => boolean;
};
export const sanitizeHtml = js.sanitizeHtml as unknown as (html: string, options?: { tools?: string[]; mediaPrefixes?: string[]; extraTags?: string[] }) => string;
export const createSanitizer = js.createSanitizer as unknown as (options?: { tools?: string[]; mediaPrefixes?: string[]; extraTags?: string[] }) => (html: string) => string;
export const createEditor = js.createEditor as unknown as (options: EditorOptions) => EditorHandle;
export const DEFAULT_EMOJIS = js.DEFAULT_EMOJIS as string[];
export const evaluateQuality = js.evaluateQuality as unknown as (draft: QualityDraft, rules: QualityRules | null) => QualityResult;
export const createToneChecker = js.createToneChecker as unknown as (api: ApiClient) => { check(text: string): Promise<ToneResult>; reset(): void };
export const toneSuggestionFromError = js.toneSuggestionFromError as unknown as (error: unknown) => string | null;
export const validatePoll = js.validatePoll as unknown as (poll: { question: string; options: string[] }, limits?: { maxQuestion?: number; maxOption?: number; minOptions?: number; maxOptions?: number }) => PollValidation;
export const pollPercentages = js.pollPercentages as unknown as (options: Array<{ votes: number }>) => number[];
export const applyPollVote = js.applyPollVote as unknown as (poll: Poll, optionId: number) => Poll;
export const POLL_MIN_OPTIONS = js.POLL_MIN_OPTIONS as number;
export const POLL_MAX_OPTIONS = js.POLL_MAX_OPTIONS as number;
export const POLL_MAX_QUESTION = js.POLL_MAX_QUESTION as number;
export const POLL_MAX_OPTION = js.POLL_MAX_OPTION as number;
export const createUndoCountdown = js.createUndoCountdown as unknown as (options: { seconds: number; onTick?: (remaining: number) => void; onExpire?: () => void; now?: () => number; setInterval?: (fn: () => void, ms: number) => unknown; clearInterval?: (id: unknown) => void }) => { remaining(): number; readonly state: string; cancel(): boolean; dispose(): void };
export const secondsUntil = js.secondsUntil as unknown as (iso: string | null, now?: number) => number;
export const createDraftStore = js.createDraftStore as unknown as (key: string, options?: { maxAgeDays?: number; debounceMs?: number; onResult?: (saved: boolean) => void }) => { key: string; load(): { value: unknown; savedAt: number } | null; save(value: unknown): void; saveNow(value: unknown): boolean; clear(): void; dispose(): void };
export const quotableSelection = js.quotableSelection as unknown as (selection: Selection | null, maxLength?: number) => { commentId: string; text: string; rect: DOMRect | null } | null;
export const codePointLength = js.codePointLength as unknown as (text: string) => number;
export const formatScore = js.formatScore as unknown as (value: number | null | undefined, locale: string, digits?: number) => string;
export const formatMoney = js.formatMoney as unknown as (money: Money | null, locale: string) => string;
export const formatRelative = js.formatRelative as unknown as (iso: string | null, locale: string, now?: number) => string;
export const formatDate = js.formatDate as unknown as (iso: string | null, locale: string, options?: Intl.DateTimeFormatOptions) => string;
export const formatMonth = js.formatMonth as unknown as (period: string, locale: string) => string;
export const sparklinePath = js.sparklinePath as unknown as (points: TrendPoint[], scaleMax: number, width?: number, height?: number, padding?: number) => { line: string; dots: Array<{ x: number; y: number }> };
export const toggleVote = js.toggleVote as unknown as (state: { value: number; up: number; down: number }, pressed: number) => { value: number; up: number; down: number };
export const hydrateCards = js.hydrateCards as unknown as (container: ParentNode, api: ApiClient, options?: { locale?: string; t?: (key: string, replace?: Record<string, string | number>) => string; cache?: Map<string, Promise<unknown>> }) => Promise<void>;
