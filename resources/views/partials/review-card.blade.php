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
    <h3 id="{{ $headingId }}" class="rr-review__title">{{ $review['title'] ?: __('ratings-and-reviews::ui.reviews.rating', ['rating' => $review['rating'], 'max' => $review['scale_max']]) }}</h3>

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
    </footer>
</article>
