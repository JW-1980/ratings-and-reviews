/**
 * Types of the normalised API shapes produced by `resources/js/normalize.js` (the single
 * mapping from server JSON). Change them here and in normalize.js together.
 */

export interface SubjectRef {
    type: string;
    id: string | number;
}

export interface Author {
    id: number | string;
    name: string;
    avatarUrl: string | null;
    profileUrl: string | null;
    extra: Record<string, unknown>;
}

export interface Money {
    cents: number;
    currency: string;
}

export interface ProductRef {
    type: string;
    id: string | number;
    title: string;
    url: string | null;
    imageUrl: string | null;
}

export interface HistogramBucket {
    bucket: number;
    count: number;
}

export interface TopicSummary {
    id: number;
    name: string;
    scaleMax: number;
    count: number;
    average: number | null;
}

/** UncertaintyBand summary extension (95% interval in scale units). */
export interface Uncertainty {
    confidence: number;
    margin: number | null;
    low: number | null;
    high: number | null;
    fewReviews: boolean;
}

/** ScoreTrend summary extension, one point per month. */
export interface TrendPoint {
    period: string;
    average: number | null;
    count: number;
}

export interface FacetGroupCount {
    group: string;
    label: string;
    values: Array<{ value: string; label: string; count: number }>;
}

export interface FacetGroup {
    group: string;
    label: string;
    values: Array<{ value: string; label: string }>;
}

export interface Alternative {
    subject: ProductRef;
    better: number;
    worse: number;
    same: number;
    total: number;
}

/** Posted "as" a brand or shop (AttributionResolver snapshot). */
export interface Attribution {
    key: string;
    label: string;
    url: string | null;
}

/** A review question (ReviewQuestion::present()). */
export interface ReviewQuestion {
    id: number | null;
    key: string;
    type: 'scale' | 'choice' | 'multi' | string;
    label: string;
    /** Words at both ends of a scale. */
    lowLabel: string | null;
    highLabel: string | null;
    scaleMax: number | null;
    required: boolean;
    options: Option[];
}

/** ReviewQuestionSummary: the answers of the counted reviews to one question. */
export interface QuestionSummary extends Omit<ReviewQuestion, 'options'> {
    count: number;
    /** Scale only, from `minReviewsForAverage` answers. */
    average: number | null;
    distribution: Array<{ value: number; count: number }>;
    options: Array<{ value: string; label: string; count: number; share: number | null }>;
}

/** ReviewResource `answers[]`. */
export interface ReviewAnswer {
    key: string;
    label: string;
    type: string;
    value: number | string | string[] | null;
    /** The value as text (option labels joined). */
    display: string;
    scaleMax: number | null;
}

/** ReviewResource `follow_up`: the author's follow-up after N days. */
export interface FollowUp {
    rating: number;
    scaleMax: number;
    initialRating: number;
    initialScaleMax: number;
    body: string | null;
    createdAt: string | null;
    daysAfter: number | null;
}

export interface FollowUpSummary {
    enabled: boolean;
    count: number;
    minForChange: number;
    averageBefore: number | null;
    averageAfter: number | null;
    averageChange: number | null;
}

export interface RatingSummary {
    scaleMax: number;
    count: number;
    average: number | null;
    bayesianAverage: number | null;
    histogram: HistogramBucket[];
    topics: TopicSummary[];
    uncertainty: Uncertainty | null;
    trend: TrendPoint[];
    facets: FacetGroupCount[];
    alternatives: Alternative[];
    switchTo: { subject: ProductRef; count: number } | null;
    questions: QuestionSummary[];
    followUps: FollowUpSummary | null;
    minReviewsForAverage: number;
    computedAt: string | null;
}

export interface TopicRating {
    topicId: number;
    value: number;
    scaleMax: number;
}

export interface Comparison {
    verdict: 'better' | 'worse' | 'same' | string | null;
    verdictLabel: string | null;
    subject: ProductRef;
}

/** ReviewResource `photos[]` (also the shape of an upload). */
export interface Photo {
    id: number | null;
    url: string;
    alt: string;
    width: number | null;
    height: number | null;
}

/** POST uploads. */
export interface Upload {
    id: number;
    url: string;
    alt: string | null;
    width: number | null;
    height: number | null;
}

/** ReviewResource `official_reply` (the host's OfficialReplyResolver). */
export interface OfficialReply {
    author: { name: string; url: string | null; avatarUrl: string | null };
    /** e.g. "Brand" (host text). */
    label: string | null;
    /** Plain text. */
    body: string;
    /** The body escaped by the server, with <p> and <br>. */
    bodyHtml: string;
    repliedAt: string | null;
}

/** GET|PUT subjects/{type}/{id}/topic-ratings (scores without a review). */
export interface TopicRatingState {
    /** The section's `standalone_topic_ratings`. */
    enabled: boolean;
    acceptsRatings: boolean;
    topics: Array<{ id: number; name: string; scaleMax: number; count: number; average: number | null }>;
    /** topic id => the viewer's score; null for a guest. */
    own: Record<string, number> | null;
}

export interface Review {
    id: number;
    subject: SubjectRef | null;
    author: Author | null;
    status: 'pending' | 'approved' | 'rejected' | 'spam' | string;
    statusLabel: string;
    rating: number;
    scaleMax: number;
    title: string | null;
    bodyHtml: string;
    pros: string[];
    cons: string[];
    topicRatings: TopicRating[];
    price: Money | null;
    facets: Array<{ group: string; value: string; label: string }>;
    comparison: Comparison | null;
    qualityScore: number | null;
    /** Up to `reviews.max_photos`; on a review that is not approved only its author gets them. */
    photos: Photo[];
    /** Null without one, or when the host has no OfficialReplyResolver (the `brand-reply` slot overrides it). */
    officialReply: OfficialReply | null;
    helpfulCount: number;
    /** Public replies (`reviews.replies`); null when replies to reviews are off. */
    repliesCount: number | null;
    attribution: Attribution | null;
    answers: ReviewAnswer[];
    followUp: FollowUp | null;
    /** Only for the author. */
    followUpWindow: { opensAt: string | null; closesAt: string | null } | null;
    /** The author is in a block relation with the viewer. */
    isBlocked: boolean;
    viewerVotes: Record<string, number>;
    isOwn: boolean;
    can: { edit: boolean; delete: boolean; reply: boolean; vote: boolean; followUp: boolean };
    createdAt: string | null;
    editedAt: string | null;
    isEdited: boolean;
}

export interface Option {
    value: string;
    label: string;
}

export interface ReviewPage {
    items: Review[];
    meta: {
        total: number;
        page: number;
        perPage: number;
        lastPage: number;
        sort: string;
        sorts: Option[];
        scaleMax: number;
        ownReview: Review | null;
        filters: { rating: number | null; facets: Record<string, string>; withPhotos: boolean };
        facets: FacetGroup[];
        comparisons: Option[];
        editor: Trust;
        /** `review_scale` of the section (same as scaleMax). */
        reviewScale: number;
        /** `review_extended_fields`: title, pros and cons are part of the form. */
        extendedFields: boolean;
        /** `review_min_length`: minimum characters of the review text. */
        minLength: number;
        form: ReviewFormLimits;
        features: { priceAtReview: boolean; wantToTry: boolean; productCards: boolean; toneCheck: boolean; reviewReplies: boolean; officialReplies: boolean; followUps: boolean; attributions: boolean };
        /** Section of the replies to reviews; null when they are off. */
        reviewRepliesSection: string | null;
        acceptsReviews: boolean;
        /** Section `reviews_enabled`: false means topic ratings only. */
        reviewsEnabled: boolean;
        /** null for a guest; false: the subject's participation rules keep the viewer out. */
        canParticipate: boolean | null;
        /** Identities the viewer may post as. */
        attributions: Attribution[];
        questions: ReviewQuestion[];
        followUp: { afterDays: number; windowDays: number; maxBodyLength: number } | null;
    };
}

/** Reviews meta `review_form`. */
export interface ReviewFormLimits {
    maxBodyLength: number;
    maxTitleLength: number;
    maxProsCons: number;
    maxProConLength: number;
    /** 0: photos are off. */
    maxPhotos: number;
    photosRequireApproval: boolean;
    requiresApproval: boolean;
}

/** TrustProfile: the tools and depth the viewer has unlocked. */
export interface Trust {
    contributions: number | null;
    exempt: boolean;
    editorTools: string[] | null;
    maxDepth: number | null;
    /** key (tool, `deep_reply`, `poll`) => approved contributions required */
    locked: Record<string, number>;
    canCreatePoll: boolean;
}

export interface VoteCount {
    up: number;
    down: number;
}

export type VoteDimension = 'agreement' | 'helpfulness' | 'needs_source';

export interface PollOption {
    id: number;
    label: string;
    votes: number;
}

export interface Poll {
    id: number;
    question: string;
    options: PollOption[];
    totalVotes: number;
    viewerOptionId: number | null;
    closesAt: string | null;
    isClosed: boolean;
}

export interface Comment {
    id: number;
    parentId: number | null;
    rootId: number | null;
    inReplyToId: number | null;
    inReplyToAuthor: Author | null;
    depth: number;
    status: string;
    /** 'public' | 'scheduled' (inside the undo window) | 'pending' … */
    visibility: string;
    author: Author | null;
    bodyHtml: string;
    isRemoved: boolean;
    removedLabel: string | null;
    isEdited: boolean;
    isTop: boolean;
    isCollapsed: boolean;
    counts: Record<VoteDimension, VoteCount>;
    dimensions: string[];
    viewerVotes: Record<string, number>;
    isOwn: boolean;
    can: { edit: boolean; delete: boolean; reply: boolean; undo: boolean; vote: boolean; pin: boolean };
    attribution: Attribution | null;
    isPinned: boolean;
    /** The author is in a block relation with the viewer: no reply, quote or vote. */
    isBlocked: boolean;
    quote: { commentId: number | string; rootId: number | null; text: string; author: Author | null; available: boolean } | null;
    poll: Poll | null;
    publishAt: string | null;
    publishedAt: string | null;
    isNew: boolean;
    createdAt: string | null;
    editedAt: string | null;
    replies: Comment[];
}

export interface DiscussionSettings {
    maxDepth: number;
    editorTools: string[];
    voteDimensions: string[];
    guestVotes: boolean;
    minLength: number;
    maxLength: number;
    editWindowMinutes: number | null;
    undoWindowSeconds: number;
    maxQuoteLength: number;
    toneCheck: boolean;
    polls: { enabled: boolean; minOptions: number; maxOptions: number };
}

export interface CommentPage {
    items: Comment[];
    meta: {
        total: number;
        page: number;
        perPage: number;
        hasMore: boolean;
        sort: string;
        sorts: Option[];
        topCommentId: number | null;
        pinnedCommentId: number | null;
        canParticipate: boolean | null;
        canPin: boolean;
        attributions: Attribution[];
        subject: { type: string; id: string | number; title: string; url: string | null; contextUrl: string | null } | null;
        truncatedThreads: Record<string, number>;
        acceptsComments: boolean;
        isLocked: boolean;
        slowModeSeconds: number;
        secondsUntilNextPost: number;
        newSince: { lastVisitAt: string | null; count: number; firstNewId: number | null; firstNewRootId: number | null };
        trust: Trust;
        settings: DiscussionSettings;
    };
}

/** GET comments/{comment}/replies: flat replies (place them by parentId), in tree order. */
export interface ReplyPage {
    items: Comment[];
    rootId: number | null;
    total: number;
    nextCursor: string | null;
    hasMore: boolean;
}

export interface Revision {
    revision: number;
    bodyHtml: string;
    editedBy: number | null;
    editedAt: string | null;
}

/**
 * The answer of a vote (VoteResult::toArray() on the server). `counts` is the voted
 * dimension's `{ up, down }`; `allCounts` every dimension of the item.
 */
export interface VoteResult {
    dimension: string;
    /** The viewer's value now: 1, -1 or 0 (withdrawn). */
    value: number;
    counted: boolean;
    counts: VoteCount;
    allCounts: Record<string, VoteCount>;
}

export interface DigestEntry {
    commentId: number;
    rootId: number;
    excerpt: string;
    author: Author | null;
    agree: number;
    disagree: number;
    helpful: number;
}

/** DiscussionDigest (null when below the threshold). */
export interface Digest {
    total: number;
    participants: number | null;
    agreement: { agree: number; disagree: number; agreeShare: number | null } | null;
    agreeing: DigestEntry[];
    disagreeing: DigestEntry[];
    mostHelpful: DigestEntry | null;
}

export interface SearchHit {
    type: 'review' | 'comment';
    id: number;
    rootId: number | null;
    titleHtml: string | null;
    snippetHtml: string;
    rating: number | null;
    scaleMax: number | null;
    author: Author | null;
    createdAt: string | null;
}

export interface SearchResults {
    query: string;
    total: number;
    totalReviews: number;
    totalComments: number;
    reviews: SearchHit[];
    comments: SearchHit[];
    items: SearchHit[];
}

/** AuthorProfile + counts; member since, badges and follow URL from the host's `extra`. */
export interface AuthorCard extends Author {
    reviewsCount: number | null;
    commentsCount: number | null;
    memberSince: string | null;
    badges: Array<{ label: string; icon: string | null }>;
    followUrl: string | null;
}

export interface ProductCard {
    type: string;
    id: string | number;
    title: string;
    url: string | null;
    imageUrl: string | null;
    price: Money | null;
    score: number | null;
    scaleMax: number | null;
}

export interface ToneResult {
    enabled?: boolean;
    suggestion: string | null;
    matches: string[];
    unavailable?: boolean;
}

/** ReviewQualityMeter::rules() */
export interface QualityRule {
    key: string;
    type: 'length' | 'pros' | 'cons' | 'words' | 'topics' | 'concrete';
    points?: number;
    steps?: Array<[number, number]>;
    words?: string[];
    units?: string[];
    max_points?: number;
    hint?: string;
}

export interface QualityRules {
    version: number;
    rules: QualityRule[];
    levels: Array<{ level: string; min: number; label?: string }>;
}

export interface QualityResult {
    score: number;
    level: string | null;
    levelLabel: string | null;
    hints: Array<{ key: string; hint: string; label: string | null }>;
    available: boolean;
}

export interface Sticker {
    id: string | number;
    name: string;
    url: string;
}

/** GET admin/stickers. */
export interface AdminSticker {
    id: number;
    name: { nl: string; en: string };
    label: string;
    path: string;
    url: string;
    managedFile: boolean;
    isActive: boolean;
    sortOrder: number;
}

export interface ReviewTopic {
    id: number;
    name: string;
    scaleMax: number;
}

export type Translate = (key: string, replace?: Record<string, string | number>) => string;
export type TranslateChoice = (key: string, count: number, replace?: Record<string, string | number>) => string;
