{{--
    <x-rr::rating-summary :subject="$product" />
    Score with uncertainty band, weighted score, trend, histogram (links that filter <x-rr::reviews>),
    per-topic bars, facets, the answers to the review questions, the change after follow-ups and
    "reviewers switch to". Data: GET rating-summary, rendered on the server.
--}}
@props(['subject', 'prefix' => 'rr_', 'reviewsAnchor' => null])
@php
    $rr = require view()->getFinder()->find('rr::support.helpers');
    $type = $subject->getMorphClass();
    $key = $subject->getKey();
    $data = $rr['api']('rating-summary.show', ['type' => $type, 'id' => $key])['data'] ?? null;
    $rootId = 'rr-summary-'.\Illuminate\Support\Str::slug($type.'-'.$key);
    $anchor = $reviewsAnchor ?? 'rr-reviews-'.\Illuminate\Support\Str::slug($type.'-'.$key);
    $active = filter_var(request()->query($prefix.'rating'), FILTER_VALIDATE_INT) ?: null;
    $activeFacets = (array) request()->query($prefix.'facet', []);
@endphp
@if ($data !== null)
    @php
        $scale = (int) $data['scale_max'];
        $count = (int) $data['count'];
        $histogram = (array) $data['histogram'];
        krsort($histogram);
        $total = array_sum($histogram);
        $band = $data['uncertainty'] ?? null;
        $trend = array_values(array_filter((array) ($data['trend']['points'] ?? []), fn (array $point): bool => $point['average'] !== null));
        $switch = $data['comparisons']['often_switch_to'] ?? null;
        $alternatives = (array) ($data['comparisons']['alternatives'] ?? []);
        $facetGroups = array_values(array_filter((array) ($data['facets'] ?? []), fn (array $group): bool => collect($group['values'])->sum('count') > 0));
        $questions = array_values(array_filter((array) ($data['questions'] ?? []), fn (array $question): bool => (int) $question['count'] > 0));
        $followUps = $data['follow_ups'] ?? null;
    @endphp
    @include('rr::partials.client')
    <section {{ $attributes->merge(['class' => 'rr rr-nojs rr-summary']) }} id="{{ $rootId }}" data-rr="summary" data-rr-subject-type="{{ $type }}" data-rr-subject-id="{{ $key }}" aria-labelledby="{{ $rootId }}-title">
        <h2 id="{{ $rootId }}-title" class="rr-summary__title">{{ __('ratings-and-reviews::ui.summary.title') }}</h2>

        <div class="rr-summary__headline">
            <p class="rr-summary__score">
                <span class="rr-summary__value" aria-hidden="true">{{ $rr['number']($data['average']) }}</span>
                <span class="rr-summary__max" aria-hidden="true">/{{ $scale }}</span>
                @if ($count > 0 && ($band['margin'] ?? null) !== null)
                    <span class="rr-summary__band" aria-hidden="true">{{ __('ratings-and-reviews::ui.summary.uncertainty', ['margin' => $rr['number']($band['margin'])]) }}</span>
                @endif
                <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.summary.score_out_of', ['score' => $rr['number']($data['average']), 'max' => $scale]) }}</span>
            </p>
            <p class="rr-summary__count">{{ trans_choice('ratings-and-reviews::ui.summary.based_on', $count, ['count' => $count]) }}</p>
            @if ($count > 0 && $band !== null)
                <p class="rr-summary__band-help">
                    @if ($band['few_reviews'] ?? false)
                        {{ __('ratings-and-reviews::ui.summary.few_reviews') }}
                    @elseif (($band['lower'] ?? null) !== null)
                        {{ __('ratings-and-reviews::ui.summary.uncertainty_help', ['low' => $rr['number']($band['lower']), 'high' => $rr['number']($band['upper'])]) }}
                    @endif
                </p>
            @endif
            @if ($count > 0 && $data['bayesian_average'] !== null)
                <p class="rr-summary__weighted" title="{{ __('ratings-and-reviews::ui.summary.weighted_help') }}">{{ __('ratings-and-reviews::ui.summary.weighted', ['score' => $rr['number']($data['bayesian_average'])]) }}</p>
            @endif
            @if (count($trend) > 1)
                @php
                    $points = (array) $data['trend']['points'];
                    $span = max(1, count($points) - 1);
                    $line = [];
                    foreach ($points as $index => $point) {
                        if ($point['average'] !== null) {
                            $line[] = [round(3 + $index / $span * 114, 2), round(3 + (1 - ($point['average'] - 1) / max(1, $scale - 1)) * 26, 2)];
                        }
                    }
                    $first = $trend[0];
                    $last = $trend[count($trend) - 1];
                    $label = __('ratings-and-reviews::ui.summary.trend_label', ['from' => $rr['date']($first['month'].'-01', 'month'), 'first' => $rr['number']($first['average']), 'to' => $rr['date']($last['month'].'-01', 'month'), 'last' => $rr['number']($last['average'])]);
                @endphp
                <div class="rr-summary__trend">
                    <span class="rr-summary__label">{{ __('ratings-and-reviews::ui.summary.trend') }}</span>
                    <svg class="rr-sparkline" viewBox="0 0 120 32" width="120" height="32" role="img" aria-label="{{ $label }}">
                        <path class="rr-sparkline__line" fill="none" d="{{ collect($line)->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L').$p[0].' '.$p[1])->implode(' ') }}" />
                        <circle class="rr-sparkline__dot" cx="{{ end($line)[0] }}" cy="{{ end($line)[1] }}" r="2.5" />
                    </svg>
                </div>
            @endif
        </div>

        @if ($count === 0)
            <p class="rr-empty">{{ __('ratings-and-reviews::ui.summary.no_reviews') }}</p>
        @else
            <div class="rr-summary__histogram" role="group" aria-label="{{ __('ratings-and-reviews::ui.summary.histogram') }}">
                @foreach ($histogram as $bucket => $bucketCount)
                    @php
                        $percent = $total === 0 ? 0 : (int) round($bucketCount / $total * 100);
                        $isActive = $active === (int) $bucket;
                        $label = __('ratings-and-reviews::ui.summary.histogram_row', ['bucket' => $bucket, 'max' => $scale, 'count' => $bucketCount, 'percent' => $percent]).'. '.__('ratings-and-reviews::ui.summary.histogram_filter', ['bucket' => $bucket, 'max' => $scale]);
                    @endphp
                    @if ($bucketCount === 0 && ! $isActive)
                        <span class="rr-histogram__row" aria-label="{{ $label }}" role="img">
                    @else
                        <a href="{{ $rr['query']($prefix, ['rating' => $isActive ? null : $bucket, 'page' => null]) }}#{{ $anchor }}" class="rr-histogram__row {{ $isActive ? 'is-active' : '' }}" @if ($isActive) aria-current="true" @endif aria-label="{{ $label }}" data-rr-nav>
                    @endif
                        <span class="rr-histogram__bucket" aria-hidden="true">{{ $bucket }}</span>
                        <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" style="inline-size: {{ $percent }}%"></span></span>
                        <span class="rr-histogram__count" aria-hidden="true">{{ $bucketCount }}</span>
                    @if ($bucketCount === 0 && ! $isActive)</span>@else</a>@endif
                @endforeach
            </div>
            @if ($active)
                <p class="rr-summary__filter" role="status">
                    {{ __('ratings-and-reviews::ui.summary.filter_active', ['bucket' => $active, 'max' => $scale]) }}
                    <a href="{{ $rr['query']($prefix, ['rating' => null, 'page' => null]) }}#{{ $anchor }}" class="rr-button rr-button--link" data-rr-nav>{{ __('ratings-and-reviews::ui.summary.clear_filter') }}</a>
                </p>
            @endif
        @endif

        @if (! empty($data['topics']))
            <div class="rr-summary__topics">
                <h3 class="rr-summary__subtitle">{{ __('ratings-and-reviews::ui.summary.topics') }}</h3>
                <ul class="rr-topics">
                    @foreach ($data['topics'] as $topic)
                        <li class="rr-topic">
                            <span class="rr-topic__name">{{ $topic['name'] }}</span>
                            <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" style="inline-size: {{ $topic['average'] === null ? 0 : round(($topic['average'] - 1) / max(1, $topic['scale_max'] - 1) * 100, 1) }}%"></span></span>
                            <span class="rr-topic__score">
                                @if ($topic['average'] !== null)
                                    <span aria-hidden="true">{{ $rr['number']($topic['average']) }}</span>
                                    <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.summary.topic_score', ['name' => $topic['name'], 'score' => $rr['number']($topic['average']), 'max' => $topic['scale_max']]) }}</span>
                                @else
                                    {{ __('ratings-and-reviews::ui.summary.topic_none') }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($questions !== [])
            <div class="rr-summary__questions">
                <h3 class="rr-summary__subtitle">{{ __('ratings-and-reviews::ui.summary.questions') }}</h3>
                <ul class="rr-question-summaries">
                    @foreach ($questions as $question)
                        <li class="rr-question-summary rr-question-summary--{{ $question['type'] }}">
                            <span class="rr-topic__name">{{ $question['label'] }}</span>
                            <span class="rr-help">{{ trans_choice('ratings-and-reviews::ui.summary.question_answers', $question['count'], ['count' => $question['count']]) }}</span>
                            @if ($question['type'] === 'scale')
                                @php $max = (int) ($question['scale_max'] ?? 5); @endphp
                                <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" style="inline-size: {{ $question['average'] === null ? 0 : round(($question['average'] - 1) / max(1, $max - 1) * 100, 1) }}%"></span></span>
                                <span class="rr-topic__score">
                                    @if ($question['average'] !== null){{ __('ratings-and-reviews::ui.summary.question_average', ['average' => $rr['number']($question['average']), 'max' => $max]) }}@else{{ __('ratings-and-reviews::ui.summary.topic_none') }}@endif
                                </span>
                                @if ($question['low_label'] || $question['high_label'])
                                    <span class="rr-question-summary__anchors rr-help"><span>{{ $question['low_label'] }}</span><span>{{ $question['high_label'] }}</span></span>
                                @endif
                            @else
                                <ul class="rr-question-summary__options">
                                    @foreach ($question['options'] as $option)
                                        @php $percent = $option['share'] === null ? 0 : (int) round($option['share'] * 100); @endphp
                                        <li class="rr-question-summary__option">
                                            <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" style="inline-size: {{ $percent }}%"></span></span>
                                            <span>{{ __('ratings-and-reviews::ui.summary.question_option', ['label' => $option['label'], 'percent' => $percent, 'count' => $option['count']]) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (($followUps['enabled'] ?? false) && ($followUps['count'] ?? 0) > 0)
            <div class="rr-summary__follow-ups">
                <h3 class="rr-summary__subtitle">{{ __('ratings-and-reviews::ui.summary.follow_ups') }}</h3>
                @if ($followUps['average_change'] !== null)
                    @php $change = $followUps['average_change']; @endphp
                    <p class="rr-summary__follow-up-change" data-direction="{{ $change > 0 ? 'up' : ($change < 0 ? 'down' : 'same') }}">{{ __('ratings-and-reviews::ui.summary.follow_up_change', ['change' => ($change > 0 ? '+' : '').$rr['number']($change), 'before' => $rr['number']($followUps['average_before']), 'after' => $rr['number']($followUps['average_after'])]) }}</p>
                    <p class="rr-help">{{ trans_choice('ratings-and-reviews::ui.summary.follow_up_count', $followUps['count'], ['count' => $followUps['count']]) }}</p>
                @else
                    <p class="rr-help">{{ __('ratings-and-reviews::ui.summary.follow_up_few') }}</p>
                @endif
            </div>
        @endif

        @if ($facetGroups !== [])
            <div class="rr-summary__facets">
                <h3 class="rr-summary__subtitle">{{ __('ratings-and-reviews::ui.summary.facets') }}</h3>
                @foreach ($facetGroups as $group)
                    <div class="rr-chips" role="group" aria-label="{{ $group['label'] }}">
                        <span class="rr-chips__label" aria-hidden="true">{{ $group['label'] }}</span>
                        @foreach ($group['values'] as $value)
                            @php
                                $on = ($activeFacets[$group['group']] ?? null) === $value['value'];
                                $next = $activeFacets;
                                if ($on) { unset($next[$group['group']]); } else { $next[$group['group']] = $value['value']; }
                            @endphp
                            @if ($value['count'] > 0 || $on)
                                <a href="{{ $rr['query']($prefix, ['facet' => $next, 'page' => null]) }}#{{ $anchor }}" class="rr-chip" aria-pressed="{{ $on ? 'true' : 'false' }}" role="button" data-rr-nav>{{ __('ratings-and-reviews::ui.summary.facet_count', ['label' => $value['label'], 'count' => $value['count']]) }}</a>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @if ($switch !== null || $alternatives !== [])
            <div class="rr-summary__switches">
                @if ($switch !== null)
                    <p class="rr-switch rr-switch--highlight">
                        <span class="rr-switch__lead">{{ __('ratings-and-reviews::ui.summary.switches') }}</span>
                        @if ($switch['url'])<a href="{{ $switch['url'] }}" class="rr-switch__title">{{ $switch['title'] }}</a>@else<span class="rr-switch__title">{{ $switch['title'] }}</span>@endif
                        <span class="rr-switch__count">({{ trans_choice('ratings-and-reviews::ui.summary.switch_count', $switch['count'], ['count' => $switch['count']]) }})</span>
                    </p>
                @endif
                @if ($alternatives !== [])
                    <h3 class="rr-summary__subtitle">{{ __('ratings-and-reviews::ui.summary.alternatives') }}</h3>
                    <ul class="rr-switches">
                        @foreach ($alternatives as $alternative)
                            <li class="rr-switch">
                                @if ($alternative['url'])<a href="{{ $alternative['url'] }}" class="rr-switch__title">{{ $alternative['title'] }}</a>@else<span class="rr-switch__title">{{ $alternative['title'] }}</span>@endif
                                <span class="rr-switch__count">{{ __('ratings-and-reviews::ui.summary.alternative_counts', ['better' => $alternative['better'], 'same' => $alternative['same'], 'worse' => $alternative['worse']]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </section>
@endif
