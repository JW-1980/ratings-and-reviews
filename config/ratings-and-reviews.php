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
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled commands
    |--------------------------------------------------------------------------
    |
    | When enabled the package adds to the host's scheduler: rr:publish-due every
    | minute (comments whose undo window ended) and rr:send-review-invitations hourly.
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
    ],

    'sections' => [
        // 'product' => ['max_depth' => 3, 'vote_dimensions' => ['agreement', 'helpfulness', 'needs_source']],
    ],

    /** Seconds the merged settings of a section stay cached. */
    'settings_cache_seconds' => 3600,

    /** The hard upper bound of `max_depth` an admin can set. */
    'max_depth_limit' => 10,

    'discussions' => [
        'roots_per_page' => 20,
        'max_replies_per_page' => 400,
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
        | Context facets a reviewer can tick, per group: group => allowed values.
        | Stored as {group: value}, filterable with ?facet[group]=value and counted in
        | the summary. Labels: ratings-and-reviews::enums.facet.<group>.<value>.
        */
        'facets' => [
            'purchase' => ['first_time', 'regular'],
            'deal' => ['on_offer', 'regular_price'],
        ],

        /** Below this many counted reviews the summary flags "few reviews". */
        'few_reviews_below' => 5,

        /** Months shown in the score trend (ending with the current month). */
        'trend_months' => 12,

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

    'uploads' => [
        'url_prefix' => '/storage/',
    ],

    'stickers' => [
        'url_prefix' => '/storage/',
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

];
