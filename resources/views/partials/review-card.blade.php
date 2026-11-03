@php
    /** @var array<string, mixed> $review  ReviewResource */
    $features ??= [];
    $headingId = 'rr-review-'.$review['id'].'-title';
    $approved = ($review['status'] ?? 'approved') === 'approved';
    $blocked = (bool) ($review['is_blocked'] ?? false);
    $followUp = $review['follow_up'] ?? null;
    $followUpForm = 'follow-up:'.$review['id'];
    $followUpErrors = $rr['isForm']($followUpForm) ? $rr['formErrors']('rr_review') : new \Illuminate\Support\MessageBag;
    $followUpAction = $rr['webRoute']('reviews.follow-up.store', ['review' => $review['id']]);
    $uncountedLabel = $review['uncounted_label'] ?? null;
    $uncountedPublic = (bool) ($review['uncounted_public'] ?? false);
@endphp
<article id="rr-review-{{ $review['id'] }}" class="rr-review {{ $review['is_own'] ? 'is-own' : '' }} {{ $approved ? '' : 'is-pending' }}" aria-labelledby="{{ $headingId }}" data-rr-review="{{ $review['id'] }}">
    <header class="rr-review__header">
        @include('rr::partials.author', ['author' => $review['author'] ?? null])
        @include('rr::partials.attribution', ['attribution' => $review['attribution'] ?? null])
        <span class="rr-review__meta">
            @if ($review['created_at'])
                <time datetime="{{ $review['created_at'] }}" title="{{ $rr['date']($review['created_at'], 'long') }}">{{ $rr['date']($review['created_at'], 'relative') }}</time>
            @endif
            @if ($review['edited_at'])
                <span class="rr-tag" title="{{ $rr['date']($review['edited_at'], 'datetime') }}">{{ __('ratings-and-reviews::ui.common.edited') }}</span>
            @endif
            @if ($uncountedLabel && $uncountedPublic)
                <span class="rr-tag rr-tag--uncounted" data-reason="{{ $review['uncounted_reason'] }}" title="{{ __('ratings-and-reviews::ui.reviews.uncounted_public') }}">{{ $uncountedLabel }}<span class="rr-visually-hidden"> · {{ __('ratings-and-reviews::ui.reviews.uncounted_public') }}</span></span>
            @endif
        </span>
    </header>

    @if ($review['is_own'] && $review['status'] === 'pending')
        <p class="rr-notice rr-notice--pending" role="status">{{ __('ratings-and-reviews::ui.reviews.own_pending') }}</p>
    @elseif ($review['is_own'] && in_array($review['status'], ['rejected', 'spam'], true))
        <p class="rr-notice rr-notice--warning">{{ __('ratings-and-reviews::ui.reviews.own_rejected') }}</p>
    @endif
    @if ($uncountedLabel && ! $uncountedPublic)
        <p class="rr-notice rr-notice--muted" data-rr-uncounted>{{ __($review['is_own'] ? 'ratings-and-reviews::ui.reviews.own_uncounted' : 'ratings-and-reviews::ui.reviews.uncounted_moderator', ['reason' => $uncountedLabel]) }}</p>
    @endif

    <div class="rr-review__score">@include('rr::partials.score', ['value' => $review['rating'], 'max' => $review['scale_max'], 'size' => 'md'])</div>
    <h3 id="{{ $headingId }}" @class(['rr-review__title', 'rr-visually-hidden' => blank($review['title'])])>{{ $review['title'] ?: __('ratings-and-reviews::ui.reviews.rating', ['rating' => $review['rating'], 'max' => $review['scale_max']]) }}</h3>

    @if (! empty($review['topic_ratings']))
        <ul class="rr-review__topics" aria-label="{{ __('ratings-and-reviews::ui.reviews.topic_scores') }}">
            @foreach ($review['topic_ratings'] as $rating)
                <li class="rr-review__topic">
                    <span class="rr-review__topic-name">{{ $topicNames[$rating['topic_id']] ?? '' }}</span>
                    @include('rr::partials.score', ['value' => $rating['value'], 'max' => $rating['scale_max']])
                </li>
            @endforeach
        </ul>
    @endif

    @if (! empty($review['facets']))
        <ul class="rr-chips rr-chips--static">
            @foreach ($review['facets'] as $facet)
                <li class="rr-chip">{{ $facet['label'] }}</li>
            @endforeach
        </ul>
    @endif

    @if ($review['body_html'] !== '')
        {{-- body_html is the output of the package HtmlSanitizer (allowlist per section). --}}
        <div class="rr-prose rr-review__body">{!! $review['body_html'] !!}</div>
    @endif

    @if (! empty($review['answers']))
        <dl class="rr-review__answers" aria-label="{{ __('ratings-and-reviews::ui.reviews.answers') }}">
            @foreach ($review['answers'] as $answer)
                <div class="rr-review__answer rr-review__answer--{{ $answer['type'] }}">
                    <dt>{{ $answer['label'] }}</dt>
                    <dd>{{ $answer['type'] === 'scale' ? __('ratings-and-reviews::ui.form.question_scale_option', ['value' => $answer['value'], 'max' => $answer['scale_max']]) : $answer['display'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if (! empty($review['pros']) || ! empty($review['cons']))
        <div class="rr-review__points">
            @foreach (['pros', 'cons'] as $kind)
                @if (! empty($review[$kind]))
                    <div class="rr-points rr-points--{{ $kind }}">
                        <h4 class="rr-points__title">{{ __('ratings-and-reviews::ui.reviews.'.$kind) }}</h4>
                        <ul>@foreach ($review[$kind] as $point)<li>{{ $point }}</li>@endforeach</ul>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if (! empty($review['photos']))
        {{-- Thumbnails link to the full image; alt comes from the API (the uploader's text or the review title). --}}
        <ul class="rr-photos" aria-label="{{ __('ratings-and-reviews::ui.reviews.photos') }}">
            @foreach ($review['photos'] as $photo)
                <li><a href="{{ $photo['url'] }}" class="rr-photo"><img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" loading="lazy" decoding="async" @if (! empty($photo['width'])) width="{{ $photo['width'] }}" height="{{ $photo['height'] }}" @endif></a></li>
            @endforeach
        </ul>
    @endif

    @if (! empty($review['price']) || ! empty($review['compared']))
        <p class="rr-review__context">
            @if (! empty($review['price']))
                <span class="rr-tag">{{ __('ratings-and-reviews::ui.reviews.price_at_review', ['price' => $rr['money']($review['price'])]) }}</span>
            @endif
            @if (! empty($review['compared']))
                @php $text = __('ratings-and-reviews::ui.comparison.compared_with', ['title' => $review['compared']['title'], 'verdict' => $review['compared']['comparison_label']]); @endphp
                <span class="rr-tag rr-tag--comparison" data-verdict="{{ $review['compared']['comparison'] }}">
                    @if ($review['compared']['url'])<a href="{{ $review['compared']['url'] }}">{{ $text }}</a>@else{{ $text }}@endif
                </span>
            @endif
        </p>
    @endif

    @if ($followUp !== null)
        @php $followUpTitle = trans_choice('ratings-and-reviews::ui.reviews.follow_up_title', (int) ($followUp['days_after'] ?? 0), ['count' => (int) ($followUp['days_after'] ?? 0)]); @endphp
        <aside class="rr-follow-up" aria-label="{{ $followUpTitle }}" data-rr-follow-up="{{ $review['id'] }}">
            <p class="rr-follow-up__title">
                <span>{{ $followUpTitle }}</span>
                @include('rr::partials.score', ['value' => $followUp['rating'], 'max' => $followUp['scale_max']])
                @if ($followUp['created_at'])<time class="rr-review__meta" datetime="{{ $followUp['created_at'] }}">{{ $rr['date']($followUp['created_at'], 'relative') }}</time>@endif
            </p>
            @if ($followUp['rating'] !== $followUp['initial_rating'] || $followUp['scale_max'] !== $followUp['initial_scale_max'])
                <p class="rr-help">{{ __('ratings-and-reviews::ui.reviews.follow_up_change', ['from' => __('ratings-and-reviews::ui.reviews.rating', ['rating' => $followUp['initial_rating'], 'max' => $followUp['initial_scale_max']]), 'to' => __('ratings-and-reviews::ui.reviews.rating', ['rating' => $followUp['rating'], 'max' => $followUp['scale_max']])]) }}</p>
            @endif
            @if (! empty($followUp['body']))
                {{-- Plain text from the server, escaped here. --}}
                <p class="rr-follow-up__body">{!! nl2br(e($followUp['body']), false) !!}</p>
            @endif
            @if ($review['is_own'])
                <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-delete-follow-up="{{ $review['id'] }}">{{ __('ratings-and-reviews::ui.reviews.follow_up_delete') }}</button>
                @include('rr::partials.delete-form', ['deleteAction' => $rr['webRoute']('reviews.follow-up.destroy', ['review' => $review['id']]), 'deleteQuestion' => __('ratings-and-reviews::ui.reviews.follow_up_delete_confirm'), 'deleteLabel' => __('ratings-and-reviews::ui.reviews.follow_up_delete')])
            @endif
        </aside>
    @elseif ($review['is_own'] && ($review['can']['follow_up'] ?? false))
        {{-- The author's follow-up: a native <details> (no JavaScript needed); with JavaScript it posts to the API. --}}
        <details class="rr-follow-up rr-follow-up--form" @if ($rr['isForm']($followUpForm)) open @endif>
            <summary class="rr-button rr-button--small">{{ __('ratings-and-reviews::ui.reviews.follow_up_add') }}</summary>
            <form method="post" action="{{ $followUpAction ?? '#' }}" class="rr-form @if (! $followUpAction) rr-js-only @endif" novalidate data-rr-follow-up-form="{{ $review['id'] }}">
                @csrf
                <p class="rr-help">{{ __('ratings-and-reviews::ui.reviews.follow_up_intro') }}</p>
                <p class="rr-alert rr-alert--error" role="alert" data-rr-error @if (! $followUpErrors->has('follow_up')) hidden @endif>{{ $followUpErrors->first('follow_up') }}</p>
                <fieldset class="rr-scale rr-scale--{{ $review['scale_max'] }}" data-rr-field="rating">
                    <legend class="rr-label">{{ __('ratings-and-reviews::ui.reviews.follow_up_rating') }}</legend>
                    <div class="rr-scale__options">
                        @for ($value = 1; $value <= $review['scale_max']; $value++)
                            <label class="rr-scale__option">
                                <input type="radio" name="rating" value="{{ $value }}" @checked((int) old('rating') === $value && $rr['isForm']($followUpForm)) @if ($value === 1) required @endif>
                                <span class="rr-scale__mark" aria-hidden="true">{{ $review['scale_max'] <= 5 ? '★' : $value }}</span>
                                <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.form.rating_option', ['value' => $value, 'max' => $review['scale_max']]) }}</span>
                            </label>
                        @endfor
                    </div>
                    <p class="rr-field-error" data-rr-field-error>{{ $followUpErrors->first('rating') }}</p>
                </fieldset>
                <div class="rr-field" data-rr-field="body">
                    <label for="rr-review-{{ $review['id'] }}-follow-up-body" class="rr-label">{{ __('ratings-and-reviews::ui.reviews.follow_up_body') }}</label>
                    <textarea id="rr-review-{{ $review['id'] }}-follow-up-body" name="body" class="rr-textarea" rows="3">{{ $rr['isForm']($followUpForm) ? old('body') : '' }}</textarea>
                    <p class="rr-field-error" data-rr-field-error>{{ $followUpErrors->first('body') }}</p>
                </div>
                <div class="rr-actions">
                    <button type="submit" class="rr-button rr-button--primary rr-button--small" data-busy-label="{{ __('ratings-and-reviews::ui.form.submitting') }}">{{ __('ratings-and-reviews::ui.reviews.follow_up_submit') }}</button>
                </div>
            </form>
        </details>
    @elseif ($review['is_own'] && ! empty($review['follow_up_window']['opens_at']) && now()->lt(\Illuminate\Support\Carbon::parse($review['follow_up_window']['opens_at'])))
        <p class="rr-help rr-follow-up__opens">{{ __('ratings-and-reviews::ui.reviews.follow_up_opens', ['date' => $rr['date']($review['follow_up_window']['opens_at'], 'long')]) }}</p>
    @endif

    @if ($blocked)
        <p class="rr-notice rr-notice--muted" data-rr-blocked>{{ __('ratings-and-reviews::ui.reviews.blocked') }}</p>
    @endif

    @if (isset($brandReply) && is_callable($brandReply))
        {{-- Host slot: a closure fn (array $review) returning a View/Htmlable (escaped when it is a plain string). --}}
        {{ $brandReply($review) }}
    @elseif (! empty($review['official_reply']))
        @php $official = $review['official_reply']; $officialTitle = __('ratings-and-reviews::ui.reviews.official_reply', ['name' => $official['author']['name']]); @endphp
        <aside class="rr-brand-reply rr-official-reply" aria-label="{{ $officialTitle }}">
            <p class="rr-brand-reply__title">
                @if ($official['author']['url'])<a href="{{ $official['author']['url'] }}">{{ $officialTitle }}</a>@else{{ $officialTitle }}@endif
                @if ($official['label'])<span class="rr-tag">{{ $official['label'] }}</span>@endif
                @if ($official['replied_at'])<time class="rr-review__meta" datetime="{{ $official['replied_at'] }}">{{ $rr['date']($official['replied_at'], 'relative') }}</time>@endif
            </p>
            {{-- body_html is the server's escaped plain text (OfficialReply::toArray()). --}}
            <div class="rr-richtext">{!! $official['body_html'] !!}</div>
        </aside>
    @endif

    <footer class="rr-review__footer">
        @if ($approved)
            @include('rr::partials.votes', [
                'target' => 'review', 'id' => $review['id'], 'dimensions' => ['helpfulness'],
                'counts' => ['helpfulness' => ['up' => $review['helpful_count'], 'down' => 0]],
                'viewerVotes' => $review['viewer_votes'] ?? [], 'isOwn' => $review['is_own'], 'positiveOnly' => true, 'blocked' => $blocked,
            ])
        @endif
        <div class="rr-review__actions rr-js-only">
            @if (! empty($features['want_to_try']) && ! $review['is_own'])
                <button type="button" class="rr-button rr-button--small" aria-pressed="false" data-rr-want-to-try="{{ $review['id'] }}" data-added-label="{{ __('ratings-and-reviews::ui.reviews.want_to_try_added') }}">{{ __('ratings-and-reviews::ui.reviews.want_to_try') }}</button>
            @endif
            @if ($review['can']['delete'] ?? false)
                <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-delete-review="{{ $review['id'] }}">{{ __('ratings-and-reviews::ui.common.delete') }}</button>
            @endif
            @if (! $review['is_own'] && $approved && $rr['clientConfig']()['authenticated'])
                <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-report="rr_review" data-id="{{ $review['id'] }}">{{ __('ratings-and-reviews::ui.reviews.report') }}</button>
            @endif
        </div>
        @if ($approved && ($review['replies_count'] ?? null) !== null && ($review['replies_count'] > 0 || ($review['can']['reply'] ?? false)))
            {{-- A plain link (no data-rr-nav): the replies open below the list, also without JavaScript. --}}
            <a class="rr-button rr-button--small rr-button--ghost" href="{{ $rr['query']($prefix ?? 'rr_', ['rthread' => $review['id']]) }}#rr-review-replies-{{ $review['id'] }}">
                {{ $review['replies_count'] > 0 ? __('ratings-and-reviews::ui.reviews.show_replies', ['count' => $review['replies_count']]) : trans_choice('ratings-and-reviews::ui.reviews.replies', 0) }}
            </a>
        @endif
        {{-- Without JavaScript, through the package form routes (web_routes.enabled). --}}
        <div class="rr-review__actions rr-nojs-only">
            @if ($review['can']['delete'] ?? false)
                @include('rr::partials.delete-form', ['deleteAction' => $rr['webRoute']('reviews.destroy', ['review' => $review['id']]), 'deleteQuestion' => __('ratings-and-reviews::ui.reviews.delete_confirm')])
            @endif
            @if (! $review['is_own'] && $approved && $rr['clientConfig']()['authenticated'])
                @include('rr::partials.report-form', ['reportType' => 'rr_review', 'reportId' => $review['id']])
            @endif
        </div>
    </footer>
</article>
