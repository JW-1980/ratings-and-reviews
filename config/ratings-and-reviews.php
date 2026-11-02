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
    | Keys stored for later features and not read by the core:
    |   trust_levels, slow_mode_seconds, digest_threshold, undo_window_seconds.
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
    ],

    'reviews' => [
        'per_page' => 10,
        'allowed_scales' => [5, 10],
        'max_pros_cons' => 5,
        'max_pro_con_length' => 200,
        'max_title_length' => 150,
        'max_body_length' => 10000,
        'priors_cache_seconds' => 21600,
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
