{{--
    <x-rr::reviews :subject="$product" />
    Review list with sort, score and facet filters (GET, works without JavaScript), pagination,
    the viewer's own (pending) review on top, helpful votes, report, "want to try" and an
    optional brand reply: :brand-reply="fn (array $review) => view('…', ['review' => $review])"
    (without one, the official reply of the host's OfficialReplyResolver is shown). Filter
    "with photos" (?rr_photos=1). With reviews.replies on, every review links to its replies
    (?rr_rthread=ID), shown below the list as a discussion of the review (:login-url passes on).
    Every card shows the attribution badge, the answers to the review questions and the
    author's follow-up; the author adds or removes a follow-up from their card. Renders nothing
    when the section's `reviews_enabled` is off (topic ratings only).
--}}
@props(['subject', 'prefix' => 'rr_', 'brandReply' => null, 'loginUrl' => null])
@php
    $rr = require view()->getFinder()->find('rr::support.helpers');
    $type = $subject->getMorphClass();
    $key = $subject->getKey();
    $facetQuery = array_filter((array) request()->query($prefix.'facet', []), 'is_string');
    $response = $rr['api']('reviews.index', ['type' => $type, 'id' => $key], [
        'sort' => request()->query($prefix.'sort'),
        'rating' => request()->query($prefix.'rating'),
        'facet' => $facetQuery,
        'with_photos' => request()->query($prefix.'photos') === '1' ? 1 : null,
        'page' => request()->query($prefix.'page'),
    ]);
    $threadReviewId = filter_var(request()->query($prefix.'rthread'), FILTER_VALIDATE_INT) ?: null;
    $summary = $rr['api']('rating-summary.show', ['type' => $type, 'id' => $key])['data'] ?? [];
    $rootId = 'rr-reviews-'.\Illuminate\Support\Str::slug($type.'-'.$key);
@endphp
@if ($response !== null && isset($response['data']) && ($response['meta']['reviews_enabled'] ?? true))
    @php
        $items = $response['data'];
        $meta = $response['meta'];
        $own = $meta['own_review'] ?? null;
        $list = array_values(array_filter($items, fn (array $review): bool => $own === null || $review['id'] !== $own['id']));
        $topicNames = collect($summary['topics'] ?? [])->pluck('name', 'id')->all();
        $features = $meta['features'] ?? [];
        $filtered = ($meta['filters']['rating'] ?? null) !== null || ! empty((array) ($meta['filters']['facets'] ?? [])) || ! empty($meta['filters']['with_photos']);
        // The review whose replies are open: one of this subject's public reviews.
        $threadReview = $threadReviewId !== null && ! empty($features['review_replies'])
            ? \RatingsAndReviews\Models\Review::query()->forSubject($subject)->approved()->find($threadReviewId)
            : null;
        $scale = (int) $meta['scale_max'];
    @endphp
    @include('rr::partials.client')
    <section {{ $attributes->merge(['class' => 'rr rr-nojs rr-reviews']) }} id="{{ $rootId }}" data-rr="reviews" data-rr-subject-type="{{ $type }}" data-rr-subject-id="{{ $key }}" aria-labelledby="{{ $rootId }}-title">
        <header class="rr-section-header">
            <h2 id="{{ $rootId }}-title" class="rr-section-header__title">
                {{ __('ratings-and-reviews::ui.reviews.title') }}
                <span class="rr-section-header__count">{{ trans_choice('ratings-and-reviews::ui.reviews.count', $meta['total'], ['count' => $meta['total']]) }}</span>
            </h2>
            <form method="get" action="{{ request()->url() }}#{{ $rootId }}" class="rr-toolbar" data-rr-nav data-rr-autosubmit>
                @foreach (request()->except([$prefix.'sort', $prefix.'rating', $prefix.'facet', $prefix.'photos', $prefix.'page', $prefix.'rthread']) as $name => $value)
                    @if (is_string($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                @endforeach
                <label class="rr-inline-field">
                    <span class="rr-label">{{ __('ratings-and-reviews::ui.reviews.sort') }}</span>
                    <select name="{{ $prefix }}sort" class="rr-select">
                        @foreach ($meta['sorts'] as $sort)
                            <option value="{{ $sort['value'] }}" @selected($sort['value'] === $meta['sort'])>{{ $sort['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="rr-inline-field">
                    <span class="rr-label">{{ __('ratings-and-reviews::ui.reviews.filter_rating') }}</span>
                    <select name="{{ $prefix }}rating" class="rr-select">
                        <option value="">{{ __('ratings-and-reviews::ui.reviews.filter_any_rating') }}</option>
                        @for ($bucket = $scale; $bucket >= 1; $bucket--)
                            <option value="{{ $bucket }}" @selected(($meta['filters']['rating'] ?? null) === $bucket)>{{ __('ratings-and-reviews::ui.reviews.filter_rating_option', ['bucket' => $bucket, 'max' => $scale]) }}</option>
                        @endfor
                    </select>
                </label>
                @foreach ($meta['facets'] ?? [] as $group)
                    <label class="rr-inline-field">
                        <span class="rr-label">{{ $group['label'] }}</span>
                        <select name="{{ $prefix }}facet[{{ $group['group'] }}]" class="rr-select">
                            <option value="">{{ __('ratings-and-reviews::ui.reviews.filter_any_facet') }}</option>
                            @foreach ($group['values'] as $value)
                                <option value="{{ $value['value'] }}" @selected(($facetQuery[$group['group']] ?? null) === $value['value'])>{{ $value['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                @if (($meta['review_form']['max_photos'] ?? 0) > 0 || ! empty($meta['filters']['with_photos']))
                    <label class="rr-inline-field">
                        <span class="rr-label">{{ __('ratings-and-reviews::ui.reviews.filter_photos') }}</span>
                        <select name="{{ $prefix }}photos" class="rr-select">
                            <option value="">{{ __('ratings-and-reviews::ui.reviews.filter_photos_any') }}</option>
                            <option value="1" @selected(! empty($meta['filters']['with_photos']))>{{ __('ratings-and-reviews::ui.reviews.filter_photos_only') }}</option>
                        </select>
                    </label>
                @endif
                <button type="submit" class="rr-button rr-button--small rr-nojs-only">{{ __('ratings-and-reviews::ui.reviews.apply') }}</button>
            </form>
        </header>

        <p class="rr-visually-hidden" aria-live="polite" role="status" data-rr-live></p>
        {{-- Outcome of a form post without JavaScript (package form routes) that belongs to this list. --}}
        @php $listFlash = in_array(session('rr_form'), ['review:delete', 'vote:review'], true) || str_starts_with((string) session('rr_form'), 'report:rr_review') || str_starts_with((string) session('rr_form'), 'follow-up:'); @endphp
        @if ($listFlash && session('rr_status'))
            <p class="rr-alert rr-alert--success" role="status">{{ session('rr_status') }}</p>
        @endif
        @if ($listFlash && session('rr_error'))
            <p class="rr-alert rr-alert--error" role="alert">{{ session('rr_error') }}</p>
        @endif

        @if ($own !== null)
            <div class="rr-reviews__own">
                <p class="rr-reviews__own-label">{{ __('ratings-and-reviews::ui.reviews.your_review') }}</p>
                @include('rr::partials.review-card', ['review' => $own])
            </div>
        @endif

        @if ($list === [] && $own === null)
            <p class="rr-empty">{{ $filtered ? __('ratings-and-reviews::ui.reviews.empty_filtered') : __('ratings-and-reviews::ui.reviews.empty') }}</p>
        @endif

        <ol class="rr-review-list">
            @foreach ($list as $review)
                <li>@include('rr::partials.review-card', ['review' => $review])</li>
            @endforeach
        </ol>

        @if ($meta['last_page'] > 1)
            <nav class="rr-pagination" aria-label="{{ __('ratings-and-reviews::ui.common.page', ['page' => $meta['page'], 'last' => $meta['last_page']]) }}">
                @if ($meta['page'] > 1)
                    <a class="rr-button rr-button--small" rel="prev" href="{{ $rr['query']($prefix, ['page' => $meta['page'] - 1 > 1 ? $meta['page'] - 1 : null]) }}#{{ $rootId }}" data-rr-nav>{{ __('ratings-and-reviews::ui.common.previous') }}</a>
                @endif
                <span class="rr-pagination__status" aria-current="page">{{ __('ratings-and-reviews::ui.common.page', ['page' => $meta['page'], 'last' => $meta['last_page']]) }}</span>
                @if ($meta['page'] < $meta['last_page'])
                    <a class="rr-button rr-button--small" rel="next" href="{{ $rr['query']($prefix, ['page' => $meta['page'] + 1]) }}#{{ $rootId }}" data-rr-nav>{{ __('ratings-and-reviews::ui.common.next') }}</a>
                @endif
            </nav>
        @endif

        @include('rr::partials.report-dialog', ['id' => $rootId.'-report'])
    </section>
    @if ($threadReview !== null)
        {{-- Outside the list's section, so the discussion is enhanced on its own. --}}
        <div class="rr rr-review-thread" id="rr-review-replies-{{ $threadReview->id }}">
            <p class="rr-review-thread__back">
                <a href="{{ $rr['query']($prefix, ['rthread' => null]) }}#rr-review-{{ $threadReview->id }}">{{ __('ratings-and-reviews::ui.reviews.hide_replies') }}</a>
            </p>
            <x-rr::discussion :subject="$threadReview" prefix="rr_r" :login-url="$loginUrl" :searchable="false" />
        </div>
    @endif
@endif
