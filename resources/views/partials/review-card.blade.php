@php
    /** @var array<string, mixed> $review  ReviewResource */
    $features ??= [];
    $headingId = 'rr-review-'.$review['id'].'-title';
    $approved = ($review['status'] ?? 'approved') === 'approved';
@endphp
<article id="rr-review-{{ $review['id'] }}" class="rr-review {{ $review['is_own'] ? 'is-own' : '' }} {{ $approved ? '' : 'is-pending' }}" aria-labelledby="{{ $headingId }}" data-rr-review="{{ $review['id'] }}">
    <header class="rr-review__header">
        @include('rr::partials.author', ['author' => $review['author'] ?? null])
        <span class="rr-review__meta">
            @if ($review['created_at'])
                <time datetime="{{ $review['created_at'] }}" title="{{ $rr['date']($review['created_at'], 'long') }}">{{ $rr['date']($review['created_at'], 'relative') }}</time>
            @endif
            @if ($review['edited_at'])
                <span class="rr-tag" title="{{ $rr['date']($review['edited_at'], 'datetime') }}">{{ __('ratings-and-reviews::ui.common.edited') }}</span>
            @endif
        </span>
    </header>

    @if ($review['is_own'] && $review['status'] === 'pending')
        <p class="rr-notice rr-notice--pending" role="status">{{ __('ratings-and-reviews::ui.reviews.own_pending') }}</p>
    @elseif ($review['is_own'] && in_array($review['status'], ['rejected', 'spam'], true))
        <p class="rr-notice rr-notice--warning">{{ __('ratings-and-reviews::ui.reviews.own_rejected') }}</p>
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
                'viewerVotes' => $review['viewer_votes'] ?? [], 'isOwn' => $review['is_own'], 'positiveOnly' => true,
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
