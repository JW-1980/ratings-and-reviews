/**
 * The one place where API responses are mapped onto the shapes the components use
 * (camelCase, defaults for absent fields, arrays instead of keyed objects).
 *
 * Mapped from the controllers and resources in src/Http (CommentResource,
 * ReviewResource, the Summary extensions, DigestController, SearchController,
 * PollService::present(), AuthorCardController, CardController, ToneCheckController,
 * ReviewQualityMeter::rules()). Fields the server does not send (yet) default to empty, so
 * the matching UI stays hidden; those are marked "not sent by the API (yet)". The
 * TypeScript counterparts are in packages/vue/src/types.ts.
 */

const num = (value, fallback = null) => (value === null || value === undefined || value === '' || Number.isNaN(Number(value)) ? fallback : Number(value));
const str = (value, fallback = null) => (value === null || value === undefined ? fallback : String(value));
const list = (value) => (Array.isArray(value) ? value : []);

/**
 * @typedef {{ id: number|string, name: string, avatarUrl: string|null, profileUrl: string|null, extra: Record<string, unknown> }} Author
 */

/** @returns {Author|null} */
export function author(raw) {
    if (!raw) {
        return null;
    }

    return {
        id: raw.id,
        name: str(raw.name, ''),
        avatarUrl: str(raw.avatar_url),
        profileUrl: str(raw.profile_url),
        extra: raw.extra && typeof raw.extra === 'object' ? raw.extra : {},
    };
}

/** Histogram as `[{ bucket, count }]`, highest bucket first, whatever the JSON key order. */
export function histogram(raw, scaleMax) {
    const counts = new Map();

    if (Array.isArray(raw)) {
        raw.forEach((entry, index) => {
            if (entry && typeof entry === 'object') {
                counts.set(num(entry.bucket ?? entry.rating, 0), num(entry.count, 0));
            } else {
                counts.set(index + 1, num(entry, 0));
            }
        });
    } else if (raw && typeof raw === 'object') {
        Object.entries(raw).forEach(([bucket, count]) => counts.set(Number(bucket), num(count, 0)));
    }

    const buckets = [];

    for (let bucket = scaleMax; bucket >= 1; bucket--) {
        buckets.push({ bucket, count: counts.get(bucket) ?? 0 });
    }

    return buckets;
}

export function ratingSummary(raw) {
    const data = raw ?? {};
    const scaleMax = num(data.scale_max, 5);
    // UncertaintyBand: { confidence, margin, lower, upper, standard_error, few_reviews, few_reviews_below }
    const band = data.uncertainty ?? null;
    // ScoreTrend: { months, points: [{ month: 'YYYY-MM', count, average|null }] }
    const trend = list(data.trend?.points ?? data.trend);
    // FacetSummary: [{ group, label, values: [{ value, label, count }] }]
    const facets = list(data.facets);
    // ComparisonSummary: { alternatives: [{ type, id, title, url, better, worse, same, total }], often_switch_to }
    const comparisons = data.comparisons ?? {};

    return {
        scaleMax,
        count: num(data.count, 0),
        average: num(data.average),
        bayesianAverage: num(data.bayesian_average),
        histogram: histogram(data.histogram, scaleMax),
        topics: list(data.topics).map((topic) => ({
            id: topic.id,
            name: str(topic.name ?? topic.label, ''),
            scaleMax: num(topic.scale_max, 5),
            count: num(topic.count, 0),
            average: num(topic.average),
        })),
        uncertainty: band === null ? null : {
            confidence: num(band.confidence, 0.95),
            margin: num(band.margin),
            low: num(band.lower ?? band.low),
            high: num(band.upper ?? band.high),
            fewReviews: Boolean(band.few_reviews),
        },
        trend: trend.map((point) => ({
            period: str(point.month ?? point.period, ''),
            average: num(point.average),
            count: num(point.count, 0),
        })),
        facets: facets.map((group) => ({
            group: str(group.group, ''),
            label: str(group.label ?? group.group, ''),
            values: list(group.values).map((value) => ({ value: str(value.value, ''), label: str(value.label ?? value.value, ''), count: num(value.count, 0) })),
        })),
        alternatives: list(comparisons.alternatives).map((entry) => ({
            subject: productRef(entry),
            better: num(entry.better, 0),
            worse: num(entry.worse, 0),
            same: num(entry.same, 0),
            total: num(entry.total, 0),
        })).filter((entry) => entry.subject !== null),
        switchTo: comparisons.often_switch_to ? { subject: productRef(comparisons.often_switch_to), count: num(comparisons.often_switch_to.count, 0) } : null,
        computedAt: str(data.computed_at),
    };
}

/** A reference to another subject (comparison, switch, card). */
export function productRef(raw) {
    if (!raw || raw.type === undefined || raw.id === undefined) {
        return null;
    }

    return {
        type: String(raw.type),
        id: raw.id,
        title: str(raw.title, ''),
        url: str(raw.url),
        imageUrl: str(raw.image_url),
    };
}

export function money(raw) {
    if (!raw || raw.cents === undefined || raw.cents === null) {
        return null;
    }

    return { cents: Number(raw.cents), currency: str(raw.currency, 'EUR') };
}

export function review(raw) {
    const data = raw ?? {};
    // ReviewResource `compared`: { type, id, title, url, comparison, comparison_label }
    const compared = data.compared ?? null;

    return {
        id: data.id,
        subject: data.subject ?? null,
        author: author(data.author),
        status: str(data.status, 'approved'),
        statusLabel: str(data.status_label, ''),
        rating: num(data.rating, 0),
        scaleMax: num(data.scale_max, 5),
        title: str(data.title),
        bodyHtml: str(data.body_html, ''),
        pros: list(data.pros).map(String),
        cons: list(data.cons).map(String),
        topicRatings: list(data.topic_ratings).map((rating) => ({ topicId: rating.topic_id, value: num(rating.value, 0), scaleMax: num(rating.scale_max, 5) })),
        price: money(data.price),
        // [{ group, value, label }]
        facets: list(data.facets).map((facet) => ({ group: str(facet.group, ''), value: str(facet.value, ''), label: str(facet.label ?? facet.value, '') })),
        comparison: compared && compared.type !== undefined
            ? { verdict: str(compared.comparison), verdictLabel: str(compared.comparison_label), subject: productRef(compared) }
            : null,
        qualityScore: num(data.quality_score),
        // Not sent by the API (yet): photos and a brand reply; the UI hides them when empty.
        photos: list(data.photos).map((photo) => ({ url: str(photo.url, ''), alt: str(photo.alt ?? photo.alt_text, '') })),
        brandReply: data.brand_reply ? { author: author(data.brand_reply.author), bodyHtml: str(data.brand_reply.body_html, ''), createdAt: str(data.brand_reply.created_at) } : null,
        helpfulCount: num(data.helpful_count, 0),
        viewerVotes: { ...(data.viewer_votes ?? {}) },
        isOwn: Boolean(data.is_own),
        can: { edit: Boolean(data.can?.edit), delete: Boolean(data.can?.delete) },
        createdAt: str(data.created_at),
        editedAt: str(data.edited_at),
        isEdited: data.edited_at !== null && data.edited_at !== undefined,
    };
}

/** TrustProfile::toArray(): what the viewer may use. */
export function trust(raw) {
    const data = raw ?? {};

    return {
        contributions: num(data.approved_contributions),
        exempt: Boolean(data.exempt),
        editorTools: data.editor_tools ? list(data.editor_tools).map(String) : null,
        maxDepth: num(data.max_depth),
        locked: Object.fromEntries(list(data.locked).map((entry) => [String(entry.key), num(entry.required, 0)])),
        canCreatePoll: data.can_create_poll === undefined ? true : Boolean(data.can_create_poll),
    };
}

/** ReviewFacets::options(): [{ group, label, values: [{ value, label }] }] */
export function facetOptions(raw) {
    return list(raw).map((group) => ({
        group: str(group.group, ''),
        label: str(group.label ?? group.group, ''),
        values: list(group.values).map((value) => ({ value: str(value.value, ''), label: str(value.label ?? value.value, '') })),
    }));
}

function pageMeta(meta) {
    return {
        total: num(meta?.total, 0),
        page: num(meta?.page ?? meta?.current_page, 1),
        perPage: num(meta?.per_page, 10),
        lastPage: num(meta?.last_page, 1),
    };
}

export function reviewPage(raw) {
    const meta = raw?.meta ?? {};

    return {
        items: list(raw?.data).map(review),
        meta: {
            ...pageMeta(meta),
            sort: str(meta.sort, 'helpful'),
            sorts: list(meta.sorts).map((sort) => ({ value: String(sort.value), label: str(sort.label, String(sort.value)) })),
            scaleMax: num(meta.scale_max, 5),
            ownReview: meta.own_review ? review(meta.own_review) : null,
            filters: { rating: num(meta.filters?.rating), facets: { ...(meta.filters?.facets ?? {}) } },
            facets: facetOptions(meta.facets),
            comparisons: list(meta.comparisons).map((option) => ({ value: String(option.value), label: str(option.label, String(option.value)) })),
            editor: trust(meta.editor),
            features: {
                priceAtReview: Boolean(meta.features?.price_at_review),
                wantToTry: Boolean(meta.features?.want_to_try),
                productCards: Boolean(meta.features?.product_cards),
                toneCheck: Boolean(meta.features?.tone_check),
            },
            acceptsReviews: meta.accepts_reviews === undefined ? true : Boolean(meta.accepts_reviews),
        },
    };
}

/** PollService::present() */
export function poll(raw) {
    if (!raw) {
        return null;
    }

    const options = list(raw.options).map((option) => ({ id: option.id, label: str(option.label, ''), votes: num(option.votes ?? option.votes_count, 0) }));

    return {
        id: raw.id,
        question: str(raw.question, ''),
        options,
        totalVotes: num(raw.total_votes, options.reduce((sum, option) => sum + option.votes, 0)),
        viewerOptionId: raw.viewer_option_id ?? null,
        closesAt: str(raw.closes_at),
        isClosed: Boolean(raw.is_closed),
    };
}

/**
 * @param {object} raw a CommentResource array
 */
export function comment(raw) {
    const data = raw ?? {};
    const counts = data.counts ?? {};
    // { comment_id, root_id, text, author, available }
    const quote = data.quote ?? null;

    return {
        id: data.id,
        parentId: data.parent_id ?? null,
        rootId: data.root_id ?? null,
        inReplyToId: data.in_reply_to_id ?? null,
        inReplyToAuthor: author(data.in_reply_to_author),
        depth: num(data.depth, 0),
        status: str(data.status, 'approved'),
        // 'public' | 'scheduled' (undo window) | 'pending' | …
        visibility: str(data.visibility, 'public'),
        author: author(data.author),
        bodyHtml: str(data.body_html, ''),
        isRemoved: Boolean(data.is_removed),
        removedLabel: str(data.removed_label),
        isEdited: Boolean(data.is_edited),
        isTop: Boolean(data.is_top),
        isCollapsed: Boolean(data.is_collapsed),
        counts: {
            agreement: { up: num(counts.agreement?.up, 0), down: num(counts.agreement?.down, 0) },
            helpfulness: { up: num(counts.helpfulness?.up, 0), down: num(counts.helpfulness?.down, 0) },
            needs_source: { up: num(counts.needs_source?.up, 0), down: 0 },
        },
        dimensions: Object.keys(counts),
        viewerVotes: { ...(data.viewer_votes ?? {}) },
        isOwn: Boolean(data.is_own),
        can: { edit: Boolean(data.can?.edit), delete: Boolean(data.can?.delete), reply: Boolean(data.can?.reply ?? true), undo: Boolean(data.can?.undo) },
        quote: quote ? { commentId: quote.comment_id, rootId: quote.root_id ?? null, text: str(quote.text, ''), author: author(quote.author), available: Boolean(quote.available) } : null,
        poll: poll(data.poll),
        publishAt: str(data.publish_at),
        publishedAt: str(data.published_at),
        isNew: Boolean(data.is_new),
        createdAt: str(data.created_at),
        editedAt: str(data.edited_at),
        replies: list(data.replies).map((reply) => comment(reply)),
    };
}

export function commentPage(raw) {
    const meta = raw?.meta ?? {};
    const settings = meta.settings ?? {};
    const discussion = meta.discussion ?? {};
    const newSince = meta.new_since ?? {};
    const viewer = trust(meta.trust);

    return {
        items: list(raw?.data).map((item) => comment(item)),
        meta: {
            total: num(meta.total, 0),
            page: num(meta.page, 1),
            perPage: num(meta.per_page, 20),
            hasMore: Boolean(meta.has_more),
            sort: str(meta.sort, 'helpful'),
            sorts: list(meta.sorts).map((sort) => ({ value: String(sort.value), label: str(sort.label, String(sort.value)) })),
            topCommentId: meta.top_comment_id ?? null,
            truncatedThreads: { ...(meta.truncated_threads ?? {}) },
            acceptsComments: Boolean(meta.accepts_comments ?? true),
            isLocked: Boolean(discussion.locked),
            slowModeSeconds: num(discussion.slow_mode_seconds, 0),
            secondsUntilNextPost: num(discussion.seconds_until_next_post, 0),
            newSince: {
                lastVisitAt: str(newSince.last_visit_at),
                count: num(newSince.count, 0),
                firstNewId: newSince.first_new_id ?? null,
                firstNewRootId: newSince.first_new_root_id ?? null,
            },
            trust: viewer,
            settings: {
                maxDepth: num(settings.max_depth, 5),
                editorTools: list(settings.editor_tools).map(String),
                voteDimensions: list(settings.vote_dimensions).map(String),
                guestVotes: Boolean(settings.guest_votes),
                minLength: num(settings.min_length, 1),
                maxLength: num(settings.max_length, 10000),
                editWindowMinutes: num(settings.edit_window_minutes),
                undoWindowSeconds: num(settings.undo_window_seconds, 0),
                maxQuoteLength: num(settings.max_quote_length, 500),
                toneCheck: Boolean(settings.tone_check),
                polls: {
                    enabled: Boolean(settings.polls?.enabled ?? false) && viewer.canCreatePoll,
                    minOptions: num(settings.polls?.min_options, 2),
                    maxOptions: num(settings.polls?.max_options, 4),
                },
            },
        },
    };
}

export function revision(raw) {
    return {
        revision: num(raw?.revision, 0),
        bodyHtml: str(raw?.body_html, ''),
        editedBy: raw?.edited_by ?? null,
        editedAt: str(raw?.edited_at),
    };
}

export function voteResult(raw) {
    return {
        dimension: str(raw?.dimension, ''),
        value: num(raw?.value, 0),
        counted: Boolean(raw?.counted ?? true),
        counts: { up: num(raw?.counts?.up ?? raw?.counts?.['1'], 0), down: num(raw?.counts?.down ?? raw?.counts?.['-1'], 0) },
    };
}

/** The `{ data, message, quality? }` envelope of a write. */
export function saved(raw, mapper) {
    return {
        item: mapper(raw?.data),
        message: str(raw?.message),
        quality: raw?.quality ? { score: num(raw.quality.score, 0), level: str(raw.quality.level), levelLabel: str(raw.quality.level_label) } : null,
    };
}

/** DiscussionDigest::for() */
export function digest(raw) {
    if (!raw || !raw.available) {
        return null;
    }

    const entry = (item) => ({
        commentId: item.id,
        rootId: item.root_id ?? item.id,
        excerpt: str(item.excerpt, ''),
        author: author(item.author),
        agree: num(item.agree_count, 0),
        disagree: num(item.disagree_count, 0),
        helpful: num(item.helpful_count, 0),
    });

    return {
        total: num(raw.total_comments, 0),
        participants: num(raw.participants),
        agreement: raw.agreement ? { agree: num(raw.agreement.agree, 0), disagree: num(raw.agreement.disagree, 0), agreeShare: num(raw.agreement.agree_share) } : null,
        agreeing: list(raw.most_agreed).map(entry),
        disagreeing: list(raw.most_disagreed).map(entry),
        mostHelpful: raw.most_helpful ? entry(raw.most_helpful) : null,
    };
}

/** SearchController: { data: { reviews: [...], comments: [...] }, meta } */
export function searchResults(raw) {
    const meta = raw?.meta ?? {};
    const hit = (type) => (item) => ({
        type,
        id: item.id,
        rootId: item.root_id ?? null,
        titleHtml: str(item.title_html),
        snippetHtml: str(item.snippet_html, ''),
        rating: num(item.rating),
        scaleMax: num(item.scale_max),
        author: author(item.author),
        createdAt: str(item.created_at),
    });
    const reviews = list(raw?.data?.reviews).map(hit('review'));
    const comments = list(raw?.data?.comments).map(hit('comment'));

    return {
        query: str(meta.query, ''),
        totalReviews: num(meta.total_reviews, reviews.length),
        totalComments: num(meta.total_comments, comments.length),
        total: num(meta.total_reviews, reviews.length) + num(meta.total_comments, comments.length),
        reviews,
        comments,
        items: [...reviews, ...comments],
    };
}

/** AuthorCardController: AuthorProfile + counts; badges, member since and follow URL come from the host's `extra`. */
export function authorCard(raw) {
    if (!raw) {
        return null;
    }

    const extra = raw.extra ?? {};

    return {
        ...author(raw),
        reviewsCount: num(raw.counts?.reviews),
        commentsCount: num(raw.counts?.comments),
        memberSince: str(extra.member_since),
        badges: list(extra.badges).map((badge) => (typeof badge === 'object' ? { label: str(badge.label, ''), icon: str(badge.icon) } : { label: String(badge), icon: null })),
        followUrl: str(extra.follow_url),
    };
}

export function productCard(raw) {
    if (!raw) {
        return null;
    }

    return {
        type: String(raw.type),
        id: raw.id,
        title: str(raw.title, ''),
        url: str(raw.url),
        imageUrl: str(raw.image_url),
        price: money(raw.price),
        score: num(raw.score),
        scaleMax: num(raw.scale_max),
    };
}

/** ToneCheckController: { enabled, nudge: { message, matches } | null } */
export function toneResult(raw) {
    return { enabled: Boolean(raw?.enabled ?? true), suggestion: str(raw?.nudge?.message), matches: list(raw?.nudge?.matches).map(String) };
}

/**
 * Quality rules as served by GET review-quality-rules. The evaluator in quality-meter.js
 * reads exactly this object; see that file for the format.
 */
export function qualityRules(raw) {
    return raw ?? null;
}

/**
 * @typedef {object} ReviewDraft
 * @property {number} rating
 * @property {string} [title]
 * @property {string} [body] HTML from the editor
 * @property {string[]} [pros]
 * @property {string[]} [cons]
 * @property {Record<string|number, number|null>} [topicRatings]
 * @property {Record<string, string|null>} [facets] group => value
 * @property {{ type: string, id: string|number, verdict: string }|null} [comparison]
 * @property {boolean} [confirmTone]
 * @property {number} [formStartedAt] unix seconds, for the SpamGuard time trap
 */

/** @param {ReviewDraft} draft */
export function reviewPayload(draft) {
    const clean = (items) => list(items).map((item) => String(item).trim()).filter((item) => item !== '');
    const topics = Object.fromEntries(Object.entries(draft.topicRatings ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== 0));

    const payload = {
        rating: draft.rating,
        title: draft.title?.trim() || null,
        body: draft.body ?? null,
        pros: clean(draft.pros),
        cons: clean(draft.cons),
        topic_ratings: topics,
    };

    if (draft.facets !== undefined) {
        payload.facets = Object.fromEntries(Object.entries(draft.facets ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== ''));
    }

    if (draft.confirmTone) {
        payload.confirm_tone = true;
    }

    if (draft.comparison) {
        payload.compared_type = draft.comparison.type;
        payload.compared_id = draft.comparison.id;
        payload.comparison = draft.comparison.verdict;
    }

    if (draft.formStartedAt) {
        payload.form_started_at = draft.formStartedAt;
    }

    return payload;
}

/**
 * @typedef {object} CommentDraft
 * @property {string} body HTML from the editor
 * @property {number|null} [parentId]
 * @property {{ commentId: number|string, text: string }|null} [quote]
 * @property {{ question: string, options: string[], closesAt?: string|null }|null} [poll]
 * @property {boolean} [confirmTone]
 * @property {number} [formStartedAt]
 */

/** @param {CommentDraft} draft */
export function commentPayload(draft) {
    const payload = { body: draft.body, parent_id: draft.parentId ?? null };

    if (draft.quote) {
        payload.quoted_comment_id = draft.quote.commentId;
        payload.quote_text = draft.quote.text;
    }

    if (draft.poll) {
        payload.poll = { question: draft.poll.question.trim(), options: draft.poll.options.map((option) => option.trim()).filter((option) => option !== '') };

        if (draft.poll.closesAt) {
            payload.poll.closes_at = draft.poll.closesAt;
        }
    }

    if (draft.confirmTone) {
        payload.confirm_tone = true;
    }

    if (draft.formStartedAt) {
        payload.form_started_at = draft.formStartedAt;
    }

    return payload;
}
