{{--
    <x-rr::topic-ratings :subject="$additive" :login-url="route('login')" />
    Scores per rating topic without writing a review (section `standalone_topic_ratings`):
    every topic with its average and, for a member, one form with a scale per topic. The form
    posts to the package form routes (`web_routes.enabled`), with and without JavaScript; it is
    not shown while they are off. Renders nothing while the section has the feature off.
--}}
@props(['subject', 'loginUrl' => null, 'action' => null])
@php
    $rr = require view()->getFinder()->find('rr::support.helpers');
    $type = $subject->getMorphClass();
    $key = $subject->getKey();
    $response = $rr['api']('topic-ratings.show', ['type' => $type, 'id' => $key]);
    $state = $response['data'] ?? null;
    $rootId = 'rr-topic-ratings-'.\Illuminate\Support\Str::slug($type.'-'.$key);
    $action ??= $rr['webRoute']('topic-ratings.update', ['type' => $type, 'id' => $key]);
    $authenticated = $rr['clientConfig']()['authenticated'];
    $errors = $rr['formErrors']('rr_topic_ratings');
    $mine = $rr['isForm']('topic-ratings');
@endphp
@if (is_array($state) && $state['enabled'] && $state['topics'] !== [])
    @include('rr::partials.client')
    <section {{ $attributes->merge(['class' => 'rr rr-topic-ratings']) }} id="{{ $rootId }}" aria-labelledby="{{ $rootId }}-title">
        <h2 id="{{ $rootId }}-title" class="rr-section-header__title">{{ __('ratings-and-reviews::ui.topic_ratings.title') }}</h2>
        <p class="rr-help">{{ __('ratings-and-reviews::ui.topic_ratings.intro') }}</p>
        @if ($mine && session('rr_status'))
            <p class="rr-alert rr-alert--success" role="status">{{ session('rr_status') }}</p>
        @endif
        @if ($mine && session('rr_error'))
            <p class="rr-alert rr-alert--error" role="alert">{{ session('rr_error') }}</p>
        @endif
        @if ($mine && $errors->has('ratings'))
            <p class="rr-alert rr-alert--error" role="alert">{{ $errors->first('ratings') }}</p>
        @endif

        @php $editable = $authenticated && $state['accepts_ratings'] && $action !== null; @endphp
        <form method="post" action="{{ $action ?? '' }}" class="rr-topic-ratings__form">
            @if ($editable)
                @csrf
                @method('PUT')
            @endif
            <ul class="rr-topic-ratings__list">
                @foreach ($state['topics'] as $topic)
                    @php
                        $own = (array) ($state['own'] ?? []);
                        $current = (int) ($mine ? old('ratings.'.$topic['id'], $own[$topic['id']] ?? 0) : ($own[$topic['id']] ?? 0));
                        $averageId = $rootId.'-'.$topic['id'].'-average';
                        $average = trans_choice('ratings-and-reviews::ui.topic_ratings.average', $topic['count'], ['average' => $topic['average'] === null ? '' : $rr['number']($topic['average']), 'max' => $topic['scale_max']]);
                    @endphp
                    <li class="rr-topic-ratings__item">
                        @if ($editable)
                            <fieldset class="rr-scale rr-scale--{{ $topic['scale_max'] }} {{ $errors->has('ratings.'.$topic['id']) ? 'is-invalid' : '' }}" aria-describedby="{{ $averageId }}">
                                <legend class="rr-label">{{ $topic['name'] }}</legend>
                                <div class="rr-scale__options">
                                    <label class="rr-scale__option rr-scale__option--clear">
                                        <input type="radio" name="ratings[{{ $topic['id'] }}]" value="" @checked($current === 0)>
                                        <span class="rr-scale__mark" aria-hidden="true">–</span>
                                        <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.topic_ratings.no_score') }}</span>
                                    </label>
                                    @for ($value = 1; $value <= $topic['scale_max']; $value++)
                                        <label class="rr-scale__option {{ $current >= $value ? 'is-filled' : '' }}">
                                            <input type="radio" name="ratings[{{ $topic['id'] }}]" value="{{ $value }}" @checked($current === $value)>
                                            <span class="rr-scale__mark" aria-hidden="true">{{ $topic['scale_max'] <= 5 ? '★' : $value }}</span>
                                            <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.form.rating_option', ['value' => $value, 'max' => $topic['scale_max']]) }}</span>
                                        </label>
                                    @endfor
                                </div>
                                @if ($mine && $errors->has('ratings.'.$topic['id']))
                                    <p class="rr-field-error">{{ $errors->first('ratings.'.$topic['id']) }}</p>
                                @endif
                            </fieldset>
                        @else
                            <span class="rr-label">{{ $topic['name'] }}</span>
                        @endif
                        <span id="{{ $averageId }}" class="rr-topic-ratings__average">{{ $average }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="rr-topic-ratings__actions">
                @if ($editable)
                    <button type="submit" class="rr-button rr-button--primary">{{ __('ratings-and-reviews::ui.topic_ratings.save') }}</button>
                @elseif (! $authenticated && $state['accepts_ratings'])
                    <p class="rr-help">
                        @if ($loginUrl)<a href="{{ $loginUrl }}">{{ __('ratings-and-reviews::ui.topic_ratings.login') }}</a>@else{{ __('ratings-and-reviews::ui.topic_ratings.login') }}@endif
                    </p>
                @elseif (! $state['accepts_ratings'])
                    <p class="rr-help">{{ __('ratings-and-reviews::ui.topic_ratings.closed') }}</p>
                @endif
            </div>
        </form>
    </section>
@endif
