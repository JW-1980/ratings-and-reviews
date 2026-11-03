/**
 * The one place where API responses are mapped onto the shapes the components use
 * (camelCase, defaults for absent fields, arrays instead of keyed objects).
 *
 * Mapped from the controllers and resources in src/Http (CommentResource,
 * ReviewResource, the Summary extensions, DigestController, SearchController,
 * PollService::present(), AuthorCardController, CardController, ToneCheckController,
 * ReviewQualityMeter::rules(), UploadController, StickerController). Fields the server does
 * not send (yet) default to empty, so the matching UI stays hidden; those are marked "not
 * sent by the API (yet)". The TypeScript counterparts are in packages/vue/src/types.ts.
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

/** Attribution snapshot (posted "as" a brand or shop): `{ key, label, url }` or null. */
export function attribution(raw) {
    if (!raw || raw.key === undefined || raw.label === undefined) {
        return null;
    }

    return { key: String(raw.key), label: str(raw.label, ''), url: str(raw.url) };
}

/** ReviewQuestion::present(): a question of the review form (and, with counts, of the summary). */
export function reviewQuestion(raw) {
    const data = raw ?? {};

    return {
        id: data.id ?? null,
        key: str(data.key, ''),
        type: str(data.type, 'scale'),
        label: str(data.label, ''),
        lowLabel: str(data.low_label),
        highLabel: str(data.high_label),
        scaleMax: num(data.scale_max),
        required: Boolean(data.required),
        options: list(data.options).map((option) => ({ value: str(option.value, ''), label: str(option.label ?? option.value, '') })),
    };
}

/** ReviewQuestionSummary: a question with `count`, `average` (scale), `distribution` (scale) or option counts. */
export function questionSummary(raw) {
    const data = raw ?? {};

    return {
        ...reviewQuestion(data),
        count: num(data.count, 0),
        average: num(data.average),
        distribution: list(data.distribution).map((entry) => ({ value: num(entry.value, 0), count: num(entry.count, 0) })),
        options: list(data.options).map((option) => ({
            value: str(option.value, ''),
            label: str(option.label ?? option.value, ''),
            count: num(option.count, 0),
            share: num(option.share),
        })),
    };
}

/** ReviewResource `answers`: [{ key, label, type, value, display, scale_max }]. */
export function reviewAnswer(raw) {
    return {
        key: str(raw?.key, ''),
        label: str(raw?.label, ''),
        type: str(raw?.type, 'scale'),
        value: Array.isArray(raw?.value) ? raw.value.map(String) : raw?.value ?? null,
        display: str(raw?.display, ''),
        scaleMax: num(raw?.scale_max),
    };
}

/** ReviewResource `follow_up`: the author's follow-up, or null. */
export function followUp(raw) {
    if (!raw) {
        return null;
    }

    return {
        rating: num(raw.rating, 0),
        scaleMax: num(raw.scale_max, 5),
        initialRating: num(raw.initial_rating, 0),
        initialScaleMax: num(raw.initial_scale_max, 5),
        body: str(raw.body),
        createdAt: str(raw.created_at),
        daysAfter: num(raw.days_after),
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
    // FollowUpSummary: { enabled, count, min_for_change, average_before, average_after, average_change }
    const followUps = data.follow_ups ?? null;

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
        // ReviewQuestionSummary: per review question the answers of the counted reviews.
        questions: list(data.questions).map(questionSummary),
        followUps: followUps === null ? null : {
            enabled: Boolean(followUps.enabled),
            count: num(followUps.count, 0),
            minForChange: num(followUps.min_for_change, 3),
            averageBefore: num(followUps.average_before),
            averageAfter: num(followUps.average_after),
            averageChange: num(followUps.average_change),
        },
        // Averages appear from this many counted ratings (null averages below it).
        minReviewsForAverage: num(data.min_reviews_for_average, 1),
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
        // ReviewResource `photos`: [{ id, url, alt, width, height }] (others see them only on approved reviews).
        photos: list(data.photos).map(photo),
        // ReviewResource `official_reply` (OfficialReplyResolver): null without one; absent when the host has none.
        officialReply: officialReply(data.official_reply),
        // Posted "as" a brand or shop (AttributionResolver).
        attribution: attribution(data.attribution),
        // Answers to the review questions, in question order.
        answers: list(data.answers).map(reviewAnswer),
        followUp: followUp(data.follow_up),
        // Only for the author: when a follow-up can be added.
        followUpWindow: data.follow_up_window ? { opensAt: str(data.follow_up_window.opens_at), closesAt: str(data.follow_up_window.closes_at) } : null,
        // The author is in a block relation with the viewer: no vote or reply.
        isBlocked: Boolean(data.is_blocked),
        helpfulCount: num(data.helpful_count, 0),
        // Public replies to the review (reviews.replies); null when replies are switched off.
        repliesCount: data.replies_count === null || data.replies_count === undefined ? null : num(data.replies_count, 0),
        viewerVotes: { ...(data.viewer_votes ?? {}) },
        isOwn: Boolean(data.is_own),
        can: {
            edit: Boolean(data.can?.edit),
            delete: Boolean(data.can?.delete),
            reply: Boolean(data.can?.reply),
            vote: data.can?.vote === undefined ? !data.is_own : Boolean(data.can.vote),
            followUp: Boolean(data.can?.follow_up),
        },
        createdAt: str(data.created_at),
        editedAt: str(data.edited_at),
        isEdited: data.edited_at !== null && data.edited_at !== undefined,
    };
}

/** OfficialReply::toArray(): { author: { name, url, avatar_url }, label, body, body_html (escaped by the server), replied_at }. */
export function officialReply(raw) {
    if (!raw) {
        return null;
    }

    return {
        author: { name: str(raw.author?.name, ''), url: str(raw.author?.url), avatarUrl: str(raw.author?.avatar_url) },
        label: str(raw.label),
        body: str(raw.body, ''),
        bodyHtml: str(raw.body_html, ''),
        repliedAt: str(raw.replied_at),
    };
}

/**
 * GET|PUT subjects/{type}/{id}/topic-ratings: the topics with their averages and the
 * viewer's own scores (null for a guest).
 */
export function topicRatingState(raw) {
    const data = raw ?? {};
    const own = data.own === null || data.own === undefined ? null : Object.fromEntries(Object.entries(data.own).map(([topicId, value]) => [String(topicId), num(value, 0)]));

    return {
        enabled: Boolean(data.enabled),
        acceptsRatings: Boolean(data.accepts_ratings),
        topics: list(data.topics).map((topic) => ({
            id: topic.id,
            name: str(topic.name, ''),
            scaleMax: num(topic.scale_max, 5),
            count: num(topic.count, 0),
            average: num(topic.average),
        })),
        own,
    };
}

/**
 * PUT topic-ratings body: { ratings: { [topicId]: value | null } } (null removes a score).
 *
 * @param {Record<string|number, number|null>} changes
 */
export function topicRatingsPayload(changes) {
    return {
        ratings: Object.fromEntries(Object.entries(changes ?? {}).map(([topicId, value]) => [String(topicId), value === null || value === undefined || value === '' ? null : Number(value)])),
    };
}

/** A review photo or an upload (UploadController::present()). */
export function photo(raw) {
    return {
        id: raw?.id ?? null,
        url: str(raw?.url, ''),
        alt: str(raw?.alt ?? raw?.alt_text, ''),
        width: num(raw?.width),
        height: num(raw?.height),
    };
}

/** POST uploads: `{ id, url, alt, width, height }`. */
export function upload(raw) {
    const data = photo(raw);

    return { ...data, alt: raw?.alt ?? null };
}

/** GET stickers: `{ id, name, url }` (name in the current locale). */
export function sticker(raw) {
    return { id: raw?.id, name: str(raw?.name, ''), url: str(raw?.url, '') };
}

/** GET admin/stickers (StickerResource). */
export function adminSticker(raw) {
    const data = raw ?? {};

    return {
        id: data.id,
        name: { nl: str(data.name?.nl, ''), en: str(data.name?.en, '') },
        label: str(data.label, ''),
        path: str(data.path, ''),
        url: str(data.url, ''),
        managedFile: Boolean(data.managed_file),
        isActive: Boolean(data.is_active),
        sortOrder: num(data.sort_order, 0),
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
            filters: { rating: num(meta.filters?.rating), facets: { ...(meta.filters?.facets ?? {}) }, withPhotos: Boolean(meta.filters?.with_photos) },
            facets: facetOptions(meta.facets),
            comparisons: list(meta.comparisons).map((option) => ({ value: String(option.value), label: str(option.label, String(option.value)) })),
            editor: trust(meta.editor),
            // The section's review form: scale, extended fields (title, pros, cons), minimum text length and limits.
            reviewScale: num(meta.review_scale ?? meta.scale_max, 5),
            extendedFields: meta.review_extended_fields === undefined ? true : Boolean(meta.review_extended_fields),
            minLength: num(meta.review_min_length, 0),
            form: {
                maxBodyLength: num(meta.review_form?.max_body_length, 10000),
                maxTitleLength: num(meta.review_form?.max_title_length, 150),
                maxProsCons: num(meta.review_form?.max_pros_cons, 5),
                maxProConLength: num(meta.review_form?.max_pro_con_length, 200),
                maxPhotos: num(meta.review_form?.max_photos, 0),
                photosRequireApproval: Boolean(meta.review_form?.photos_require_approval),
                requiresApproval: Boolean(meta.review_form?.requires_approval),
            },
            features: {
                priceAtReview: Boolean(meta.features?.price_at_review),
                wantToTry: Boolean(meta.features?.want_to_try),
                productCards: Boolean(meta.features?.product_cards),
                toneCheck: Boolean(meta.features?.tone_check),
                reviewReplies: Boolean(meta.features?.review_replies),
                officialReplies: Boolean(meta.features?.official_replies),
                followUps: Boolean(meta.features?.follow_ups),
                attributions: Boolean(meta.features?.attributions),
            },
            // Section `reviews_enabled`: false means topic ratings only, no written reviews.
            reviewsEnabled: meta.reviews_enabled === undefined ? true : Boolean(meta.reviews_enabled),
            // null for a guest; false: the subject's participation rules keep the viewer out.
            canParticipate: meta.can_participate === undefined || meta.can_participate === null ? null : Boolean(meta.can_participate),
            attributions: list(meta.attributions).map(attribution).filter((entry) => entry !== null),
            questions: list(meta.review_questions).map(reviewQuestion),
            followUp: meta.review_follow_up ? {
                afterDays: num(meta.review_follow_up.after_days, 0),
                windowDays: num(meta.review_follow_up.window_days, 0),
                maxBodyLength: num(meta.review_follow_up.max_body_length, 2000),
            } : null,
            // The section of replies to reviews (`subjects/rr_review/{id}/comments`), null when off.
            reviewRepliesSection: str(meta.review_replies_section),
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
        can: {
            edit: Boolean(data.can?.edit),
            delete: Boolean(data.can?.delete),
            reply: Boolean(data.can?.reply ?? true),
            undo: Boolean(data.can?.undo),
            vote: data.can?.vote === undefined ? !data.is_own : Boolean(data.can.vote),
            pin: Boolean(data.can?.pin),
        },
        // Posted "as" a brand or shop (AttributionResolver).
        attribution: attribution(data.attribution),
        isPinned: Boolean(data.is_pinned),
        // The author is in a block relation with the viewer: no reply, quote or vote.
        isBlocked: Boolean(data.is_blocked),
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
            pinnedCommentId: meta.pinned_comment_id ?? null,
            // null for a guest; false: the subject's participation rules keep the viewer out.
            canParticipate: meta.can_participate === undefined || meta.can_participate === null ? null : Boolean(meta.can_participate),
            canPin: Boolean(meta.can_pin),
            attributions: list(meta.attributions).map(attribution).filter((entry) => entry !== null),
            subject: meta.subject ? {
                type: str(meta.subject.type, ''),
                id: meta.subject.id,
                title: str(meta.subject.title, ''),
                url: str(meta.subject.url),
                contextUrl: str(meta.subject.context_url),
            } : null,
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

/** GET comments/{comment}/replies: flat replies in tree order (place them by `parentId`). */
export function replyPage(raw) {
    const meta = raw?.meta ?? {};

    return {
        items: list(raw?.data).map((item) => comment(item)),
        rootId: meta.root_id ?? null,
        total: num(meta.total, 0),
        nextCursor: str(meta.next_cursor),
        hasMore: Boolean(meta.has_more),
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
 * @property {Array<number|string>} [photos] upload ids in display order; leave out to keep the current photos
 * @property {Record<string, number|string|string[]|null>} [answers] question key => value; leave out to keep the stored answers
 * @property {string|null} [attribution] post "as" this key (meta.attributions); leave out of an edit to keep it, null clears it
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

    if (draft.photos !== undefined) {
        payload.photos = list(draft.photos).map(Number).filter((id) => Number.isInteger(id) && id > 0);
    }

    if (draft.answers !== undefined) {
        payload.answers = answersPayload(draft.answers);
    }

    if (draft.attribution !== undefined) {
        payload.attribution = draft.attribution === '' ? null : draft.attribution;
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
 * @property {string|null} [attribution] post "as" this key (meta.attributions)
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

    if (draft.attribution) {
        payload.attribution = draft.attribution;
    }

    if (draft.confirmTone) {
        payload.confirm_tone = true;
    }

    if (draft.formStartedAt) {
        payload.form_started_at = draft.formStartedAt;
    }

    return payload;
}

/**
 * Review answers for the API: unanswered questions (null, '', []) are left out; a scale
 * becomes a number, a multi question a list of option values.
 *
 * @param {Record<string, number|string|string[]|null|undefined>} answers
 */
export function answersPayload(answers) {
    const payload = {};

    Object.entries(answers ?? {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
            return;
        }

        payload[key] = Array.isArray(value) ? value.map(String) : typeof value === 'number' || /^\d+$/.test(String(value)) ? Number(value) : String(value);
    });

    return payload;
}

/**
 * POST reviews/{review}/follow-up body.
 *
 * @param {{ rating: number, body?: string|null, confirmTone?: boolean }} draft
 */
export function followUpPayload(draft) {
    const payload = { rating: Number(draft.rating) };
    const body = (draft.body ?? '').trim();

    if (body !== '') {
        payload.body = body;
    }

    if (draft.confirmTone) {
        payload.confirm_tone = true;
    }

    return payload;
}
