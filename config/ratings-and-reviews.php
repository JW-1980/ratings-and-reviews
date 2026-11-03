<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | The JSON API is registered under `prefix` with `middleware`. The admin
    | routes additionally get `admin_middleware`. `guard` is the auth guard the
    | controllers read the current user from (null: the default guard), so an
    | API that authenticates optionally (for example `sanctum`) still lets
    | guests read and, where a section allows it, vote.
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'rr',
        'name' => 'rr.',
        'middleware' => ['api'],
        'admin_middleware' => [],
        'guard' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (named limiters)
    |--------------------------------------------------------------------------
    |
    | Every write route is throttled by one of these named limiters, keyed by
    | the user id or, for guests, the client IP. Values: attempts per minute.
    |
    */
    'rate_limits' => [
        'rr-write' => 10,
        'rr-vote' => 60,
        'rr-report' => 10,
        'rr-admin' => 60,
        // Lookups that cost a query per call and can be scripted: search, cards, hovercards, tone check.
        'rr-lookup' => 60,
        // Image uploads (POST uploads) per member per minute; the daily cap is uploads.daily_quota.
        'rr-upload' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Web routes for posting without JavaScript (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled the package registers form routes under `prefix` with the
    | `web` middleware (session, CSRF) plus `middleware`: posting, editing and
    | deleting reviews and comments, votes, poll votes, undo and reports from
    | plain HTML forms. They answer with a redirect back (`rr_status` flashed,
    | validation errors in `$errors`), and the Blade components point their forms
    | at them automatically. The JSON API is unchanged.
    |
    */
    'web_routes' => [
        'enabled' => false,
        'prefix' => 'rr-forms',
        'name' => 'rr.web.',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled commands
    |--------------------------------------------------------------------------
    |
    | When enabled the package adds to the host's scheduler: rr:publish-due every
    | minute (comments whose undo window ended), rr:send-review-invitations hourly
    | and rr:prune-uploads hourly (unattached uploads past their grace period).
    |
    */
    'schedule' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Section settings
    |--------------------------------------------------------------------------
    |
    | Every section (the string a Commentable or Reviewable returns) starts from
    | `section_defaults`, overlaid with `sections.<name>`, overlaid with what an
    | admin saved in rr_section_settings. Only sections listed under `sections`
    | appear in the admin API; an unlisted section works with the defaults.
    |
    | trust_levels: the number of approved contributions (ContributionCounter) a
    |   member needs before a tool unlocks. Keys: any editor tool (`link`, `image`,
    |   `sticker`, `product_card`, …), `deep_reply` (replying below the first reply
    |   level; below the threshold replies are flattened onto level 1) and `poll`.
    |   0 or a missing key: open to everyone. Moderators are exempt.
    | slow_mode_seconds: minimum seconds between two comments of one member in one
    |   discussion (0: off); a moderator overrides it per discussion.
    | digest_threshold: the TL;DR appears from this many public comments (0: never).
    | undo_window_seconds: a new comment stays private to its author this long and
    |   can be undone; CommentPosted fires when it is published (0: at once).
    |
    */
    'section_defaults' => [
        'enabled' => true,
        'max_depth' => 5,
        'editor_tools' => ['bold', 'italic', 'underline', 'strike', 'bullet_list', 'ordered_list', 'quote', 'link'],
        'vote_dimensions' => ['agreement', 'helpfulness'],
        'guest_votes' => true,
        'default_sort' => 'helpful',
        'collapse_low_quality' => true,
        'collapse_threshold' => 3,
        'highlight_top_comment' => true,
        'top_comment_min_votes' => 3,
        'min_length' => 2,
        'max_length' => 10000,
        'edit_window_minutes' => 30,
        'trust_levels' => [
            'link' => 0,
            'image' => 0,
            'deep_reply' => 0,
        ],
        'slow_mode_seconds' => 0,
        'digest_threshold' => 30,
        'undo_window_seconds' => 0,
        'review_scale' => 5,
        'review_extended_fields' => true,
        'review_min_length' => 0,
        'review_requires_approval' => false,
        // Members may rate the subject's rating topics without writing a review
        // (PUT subjects/{type}/{id}/topic-ratings); counted in the topic averages.
        'standalone_topic_ratings' => false,
        // false: the subject takes no written reviews (POST/PATCH reviews answer 422, the list is
        // empty), only standalone topic ratings (with standalone_topic_ratings).
        'reviews_enabled' => true,
        // Averages (overall, per topic, per review question) are shown from this many counted
        // ratings; below it they are null and only the counts show. 0: from the first one.
        'min_reviews_for_average' => 0,
        // Follow-ups on a review: the author may add one from this many days after the review was
        // approved (0: off), for review_follow_up_window_days; ReviewFollowUpDue fires when it opens.
        'review_follow_up_after_days' => 0,
        'review_follow_up_window_days' => 150,
    ],

    'sections' => [
        // 'product' => ['max_depth' => 3, 'vote_dimensions' => ['agreement', 'helpfulness', 'needs_source']],

        // Replies to reviews (reviews.replies): short threads, one reply level.
        'review_replies' => ['max_depth' => 1, 'vote_dimensions' => ['helpfulness'], 'digest_threshold' => 0, 'highlight_top_comment' => false],
    ],

    /** Seconds the merged settings of a section stay cached. */
    'settings_cache_seconds' => 3600,

    /** The hard upper bound of `max_depth` an admin can set. */
    'max_depth_limit' => 10,

    'discussions' => [
        'roots_per_page' => 20,
        'max_replies_per_page' => 400,
        /** Replies per request of GET comments/{comment}/replies (the rest of a truncated thread). */
        'replies_per_request' => 100,
        /** Maximum characters of a quoted selection ("quote and reply"). */
        'max_quote_length' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mini polls in comments
    |--------------------------------------------------------------------------
    */
    'polls' => [
        'enabled' => true,
        'min_options' => 2,
        'max_options' => 4,
        'max_question_length' => 255,
        'max_option_length' => 120,
        /** Longest a poll may stay open, in days (null: no limit). */
        'max_open_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | TL;DR of long discussions (deterministic, no AI)
    |--------------------------------------------------------------------------
    |
    | From the section's digest_threshold of public comments: the comments with the
    | highest net agreement and the highest net disagreement (per_side each), the
    | most helpful comment, totals and the agree/disagree ratio. Each comment is
    | shown by its first sentence, cut at excerpt_length characters.
    |
    */
    'digest' => [
        'per_side' => 3,
        'excerpt_length' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search within one subject (MySQL FULLTEXT, boolean mode)
    |--------------------------------------------------------------------------
    |
    | The query is reduced to words of letters and digits (and "quoted phrases"),
    | each required and prefix-matched; MySQL operators typed by the user are never
    | passed through. min_term_length should match innodb_ft_min_token_size (3).
    |
    */
    'search' => [
        'min_term_length' => 3,
        'max_terms' => 8,
        'max_query_length' => 200,
        'results_per_type' => 20,
        'snippet_length' => 180,
    ],

    'reviews' => [
        'per_page' => 10,
        'allowed_scales' => [5, 10],
        'max_pros_cons' => 5,
        'max_pro_con_length' => 200,
        'max_title_length' => 150,
        'max_body_length' => 10000,
        'priors_cache_seconds' => 21600,

        /*
        | Replies to reviews: a review is a Commentable subject (morph alias rr_review)
        | whose discussion uses the comment endpoints (subjects/rr_review/{id}/comments)
        | and the settings of `section`. Off by default; the review resource then has
        | `replies_count` null. Replies are possible on public reviews of open subjects.
        */
        'replies' => [
            'enabled' => false,
            'section' => 'review_replies',
        ],

        /** Photos a review can carry (uploads of the author, POST uploads); 0 switches photos off. */
        'max_photos' => 4,

        /** When true, a review that gains a photo waits for a moderator (status pending): images are not checked by the SpamGuard. */
        'photos_require_approval' => false,

        /*
        | Context facets a reviewer can tick, per group: group => allowed values.
        | Stored as {group: value}, filterable with ?facet[group]=value and counted in
        | the summary. Labels: ratings-and-reviews::enums.facet.<group>.<value>.
        */
        'facets' => [
            'purchase' => ['first_time', 'regular'],
            'deal' => ['on_offer', 'regular_price'],
        ],

        /*
        | Why a review does not count in the averages (`rr_reviews.uncounted_reason`, from a
        | RiskSignals verdict or ReviewService::setCounted()): reason key => labels per locale.
        | `public` true shows the label on the review card to everyone (a disclosure such as
        | a free sample); otherwise only the author and moderators see it. `keep_on_edit`
        | true keeps the review uncounted when the author edits it, whatever RiskSignals
        | answers then (a host decision, not a check). A reason without an entry is shown to
        | the author and moderators with ratings-and-reviews::enums.uncounted_reason.<key>,
        | or the generic label.
        */
        'uncounted_reasons' => [
            'sample_campaign' => ['nl' => 'Gekregen van het merk', 'en' => 'Received from the brand', 'public' => true, 'keep_on_edit' => true],
        ],

        /** Below this many counted reviews the summary flags "few reviews". */
        'few_reviews_below' => 5,

        /** Months shown in the score trend (ending with the current month). */
        'trend_months' => 12,

        /*
        | Follow-ups ("what do you think after a month?"): the timing is per section
        | (review_follow_up_after_days / _window_days); the "average change" in the summary is
        | shown from min_for_change follow-ups.
        */
        'follow_ups' => [
            'max_body_length' => 2000,
            'min_for_change' => 3,
        ],

        /** "Compared with…": alternatives listed in the summary, and the minimum
         * number of reviews that found the subject worse before "reviewers often
         * switch to X" is shown. */
        'comparisons_listed' => 5,
        'switch_min_reviews' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Review quality meter (deterministic, no AI)
    |--------------------------------------------------------------------------
    |
    | Each rule earns points; the score is earned / applicable points * 100. A rule
    | that cannot apply (no rating topics for the subject, no extended fields) is left
    | out of both. The same rules are served as JSON (GET review-quality-rules) so the
    | browser computes the identical score. Text is the plain text, whitespace
    | collapsed and trimmed, lower-cased; lengths are in Unicode code points; a word
    | matches when it is not preceded or followed by a letter or digit.
    |
    */
    'quality' => [
        'rules' => [
            ['key' => 'length', 'type' => 'length', 'steps' => [[40, 10], [120, 20], [300, 30]]],
            ['key' => 'pros', 'type' => 'pros', 'points' => 15],
            ['key' => 'cons', 'type' => 'cons', 'points' => 15],
            ['key' => 'usage', 'type' => 'words', 'points' => 15, 'words' => [
                'gebruik', 'gebruikt', 'gebruiken', 'gegeten', 'gedronken', 'geprobeerd', 'gekookt', 'gebakken',
                'opgewarmd', 'ontbijt', 'lunch', 'avondeten', 'recept', 'in koffie', 'in de thee', 'bij het eten',
                'used', 'use', 'using', 'tried', 'ate', 'drank', 'cooked', 'baked', 'heated', 'breakfast', 'dinner',
                'recipe', 'in coffee', 'with milk',
            ]],
            ['key' => 'topics', 'type' => 'topics', 'points' => 15],
            ['key' => 'concrete', 'type' => 'concrete', 'points' => 10, 'units' => [
                // Any digit counts as concrete; these words count without one ("een halve liter").
                'gram', 'kilo', 'liter', 'euro', 'minuten', 'minutes', 'uur', 'hours', 'dagen', 'days', 'weken',
                'weeks', 'kcal', 'calorieën', 'calories', 'procent', 'percent',
            ]],
        ],
        /** Score boundaries for the labels weak / fair / good / strong. */
        'levels' => ['fair' => 40, 'good' => 65, 'strong' => 85],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rephrase nudge (deterministic word list, no AI)
    |--------------------------------------------------------------------------
    |
    | Words and phrases that suggest a hostile tone, matched as whole words in any of
    | the lists (the text's language is not guessed). A match makes posting answer 422
    | with the suggestion; posting again with confirm_tone=true always goes through.
    |
    */
    'tone' => [
        'enabled' => true,
        'words' => [
            'nl' => [
                'idioot', 'idioten', 'debiel', 'debielen', 'achterlijk', 'sukkel', 'sukkels', 'domkop', 'stomkop',
                'oen', 'mafkees', 'randdebiel', 'hou je bek', 'hou je kop', 'kop dicht', 'bek dicht', 'rot op',
                'oprotten', 'zielig figuur', 'waardeloze', 'klootzak', 'eikel', 'lul', 'kut',
            ],
            'en' => [
                'idiot', 'idiots', 'moron', 'morons', 'stupid', 'dumb', 'imbecile', 'loser', 'losers', 'pathetic',
                'shut up', 'get lost', 'clown', 'jerk', 'asshole', 'bullshit', 'you people', 'brainless',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Review invitations
    |--------------------------------------------------------------------------
    */
    'invitations' => [
        'max_delay_days' => 365,
        'batch_size' => 500,
        // A due invitation whose subject does not accept reviews now moves this far ahead,
        // so it cannot block other due invitations run after run.
        'waiting_retry_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Undo window publisher
    |--------------------------------------------------------------------------
    */
    'publisher' => [
        'batch_size' => 500,
    ],

    'rating_topics' => [
        'max_active' => 4,
        'lock_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Review questions (rr_review_questions, admin/review-questions)
    |--------------------------------------------------------------------------
    |
    | Extra questions per scope and category, inherited by key (see ReviewQuestions):
    | `scale` (1 … scale_max with words at both ends), `choice` (one option) and `multi`
    | (several options). Answers are counted per question in the summary (`questions`).
    |
    */
    'review_questions' => [
        'enabled' => true,
        'scale_max' => 5,
        'max_per_subject' => 20,
        'max_options' => 10,
        'max_label_length' => 120,
    ],

    'reports' => [
        'reasons' => ['spam', 'offensive', 'off_topic', 'personal_data', 'misinformation', 'health_claim', 'other'],
        'details_required_for' => ['other'],
        'max_details_length' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML sanitizing
    |--------------------------------------------------------------------------
    |
    | Input over `max_input_bytes` is refused. An image is kept only when its
    | `src` is `uploads.url_prefix` + the path of an rr_uploads row of the author,
    | or `stickers.url_prefix` + the path of an active rr_stickers row; at most
    | `max_images` survive.
    |
    */
    'sanitizer' => [
        'max_input_bytes' => 50000,
        'max_images' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Image uploads (POST uploads) and review photos
    |--------------------------------------------------------------------------
    |
    | visibility `public`: files go to `disk` (default the `public` disk) and are
    |   served by the host under `url_prefix` (`php artisan storage:link`).
    | visibility `private`: files go to a non-public disk and the package serves
    |   them under /{routes.prefix}/media/{path}: to their uploader always, to
    |   others only while the review or comment they belong to is public.
    | Limits: `max_kilobytes`, `mime_types`, `min_*`/`max_*` pixel dimensions; a
    | member uploads at most `daily_quota` images per 24 hours (and `rr-upload`
    | per minute). `strip_metadata` re-encodes JPEG, PNG and WebP with GD (when
    | installed) so location data in EXIF is not published. Uploads that were never
    | attached to a review or comment are removed by rr:prune-uploads after
    | `prune_after_hours`.
    |
    */
    'uploads' => [
        'url_prefix' => '/storage/',
        'visibility' => 'public',
        'disk' => 'public',
        'directory' => 'rr-uploads',
        'max_kilobytes' => 5120,
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'min_width' => 32,
        'min_height' => 32,
        'max_width' => 6000,
        'max_height' => 6000,
        'daily_quota' => 50,
        'max_alt_length' => 255,
        'strip_metadata' => true,
        // false: GIF is refused (also when listed in mime_types). true: accepted; with
        // strip_metadata the file is rewritten block by block (animation kept, comments,
        // application data and trailing bytes dropped).
        'allow_gif' => true,
        // UploadScanner (a host binding): a scanner that throws refuses the upload, unless
        // fail_open is true (then the error is reported and the upload goes on).
        'scanner' => ['fail_open' => false],
        'prune_after_hours' => 24,
        'prune_batch_size' => 200,
    ],

    /*
    | Stickers (rr_stickers, managed through admin/stickers with rr.manage-settings).
    | An uploaded sticker image is stored on `disk` under `directory`; its URL is
    | `url_prefix` + path. The editor reads the active ones from GET stickers.
    */
    'stickers' => [
        'url_prefix' => '/storage/',
        'disk' => 'public',
        'directory' => 'rr-stickers',
        'max_kilobytes' => 512,
        'mime_types' => ['image/png', 'image/webp', 'image/gif', 'image/jpeg'],
        'max_width' => 1024,
        'max_height' => 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | SpamGuard (deterministic)
    |--------------------------------------------------------------------------
    |
    | Signals listed in `hard_signals` refuse the contribution; any other signal
    | holds it for a moderator (status pending).
    |
    */
    'spam' => [
        'honeypot_field' => 'website',
        'started_at_field' => 'form_started_at',
        'minimum_seconds' => 2,
        'max_links' => 3,
        'caps_min_length' => 40,
        'repeated_characters' => 15,
        'duplicate_minutes' => 10,
        'duplicate_min_length' => 20,
        'blocked_phrases' => [],
        'hold_phrases' => [],
        'hard_signals' => ['honeypot', 'submitted_too_fast', 'blocked_phrase', 'duplicate_recently'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default AuthorResolver
    |--------------------------------------------------------------------------
    |
    | model null: the model of the `users` auth provider.
    |
    */
    'authors' => [
        'model' => null,
        'name_attribute' => 'name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default VoterIdentity
    |--------------------------------------------------------------------------
    |
    | hash_key null: app.key. Guests are keyed on their network and user agent.
    |
    */
    'identity' => [
        'hash_key' => null,
        'ipv6_prefix_bits' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Card extensions (host features on review and comment cards)
    |--------------------------------------------------------------------------
    |
    | Host views rendered on every review or comment card of the Blade
    | components, per position: review.meta, review.footer, comment.meta,
    | comment.footer. Each view gets $item (the API array of the review or
    | comment, also as $review or $comment), $viewer (null for a guest),
    | $preloaded (the result of CardExtensions::preload() without key at that
    | position) and $position. Closures go through
    | RatingsAndReviews\Support\CardExtensions::register() instead.
    |
    */
    'card_extensions' => [
        'views' => [
            // 'review.footer' => ['reactions.review-bar'],
        ],
    ],

];
