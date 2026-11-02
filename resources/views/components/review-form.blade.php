{{--
    <x-rr::review-form :subject="$product" :action="route('reviews.store', $product)" />
    Score on the section's scale, topic scores, title, text (editor), pros and cons, facets,
    an optional comparison picker slot and the live quality meter (JavaScript, same rules as
    the server). With JavaScript it posts to the package API; without it, it posts to `action`
    (a host route, see the README) and shows `$errors`/`session('rr_status')` after the redirect.

    <x-slot:comparison-picker> must render inputs `compared_type` and `compared_id` (the host's
    own product search); the verdict select is part of this component.
--}}
@props(['subject', 'action' => null, 'updateAction' => null, 'loginUrl' => null, 'tools' => null, 'comparisonPicker' => null])
@php
    $rr = require view()->getFinder()->find('rr::support.helpers');
    $type = $subject->getMorphClass();
    $key = $subject->getKey();
    $page = $rr['api']('reviews.index', ['type' => $type, 'id' => $key]);
    $summary = $rr['api']('rating-summary.show', ['type' => $type, 'id' => $key])['data'] ?? [];
    $rules = $rr['api']('review-quality-rules')['data'] ?? null;
    $rootId = 'rr-review-form-'.\Illuminate\Support\Str::slug($type.'-'.$key);
    $authenticated = $rr['clientConfig']()['authenticated'];
@endphp
@if ($page !== null && isset($page['meta']))
    @php
        $meta = $page['meta'];
        $own = $meta['own_review'] ?? null;
        $scale = (int) $meta['scale_max'];
        $topics = (array) ($summary['topics'] ?? []);
        $extended = true;
        $toolList = $tools ?? ($meta['editor']['editor_tools'] ?? ['bold', 'italic']);
        $locked = collect($meta['editor']['locked'] ?? [])->pluck('required', 'key')->all();
        $old = fn (string $field, mixed $default = null) => old($field, $default);
        $rating = (int) $old('rating', $own['rating'] ?? 0);
        $ownTopics = collect($own['topic_ratings'] ?? [])->pluck('value', 'topic_id')->all();
        $ownFacets = collect($own['facets'] ?? [])->pluck('value', 'group')->all();
        $pros = array_values((array) $old('pros', $own['pros'] ?? []));
        $cons = array_values((array) $old('cons', $own['cons'] ?? []));
        $maxPoints = (int) config('ratings-and-reviews.reviews.max_pros_cons', 5);
        $maxTitle = (int) config('ratings-and-reviews.reviews.max_title_length', 150);
        $formAction = $own !== null ? ($updateAction ?? $action) : $action;
    @endphp
    @include('rr::partials.client')
    <section {{ $attributes->merge(['class' => 'rr rr-nojs rr-review-form']) }} id="{{ $rootId }}" data-rr="review-form" data-rr-subject-type="{{ $type }}" data-rr-subject-id="{{ $key }}" @if ($own) data-rr-review-id="{{ $own['id'] }}" @endif aria-labelledby="{{ $rootId }}-title">
        <h2 id="{{ $rootId }}-title" class="rr-section-header__title">{{ $own ? __('ratings-and-reviews::ui.form.edit_title') : __('ratings-and-reviews::ui.form.title') }}</h2>

        @if (session('rr_status'))
            <p class="rr-alert rr-alert--success" role="status">{{ session('rr_status') }}</p>
        @endif

        @if (! $authenticated)
            <p class="rr-notice">@if ($loginUrl)<a href="{{ $loginUrl }}">{{ __('ratings-and-reviews::ui.form.login_required') }}</a>@else{{ __('ratings-and-reviews::ui.form.login_required') }}@endif</p>
        @elseif (! ($meta['accepts_reviews'] ?? true))
            <p class="rr-notice">{{ __('ratings-and-reviews::ui.form.closed') }}</p>
        @else
            @if ($rules !== null)
                <script type="application/json" data-rr-quality-rules>@json($rules)</script>
            @endif
            <form method="post" action="{{ $formAction ?? '#' }}" class="rr-form" novalidate data-rr-review-form data-topic-count="{{ count($topics) }}" data-extended="{{ $extended ? '1' : '0' }}" data-max-points="{{ $maxPoints }}">
                @csrf
                @if ($own !== null && $updateAction)<input type="hidden" name="_method" value="PATCH">@endif
                <input type="hidden" name="{{ config('ratings-and-reviews.spam.started_at_field', 'form_started_at') }}" value="{{ time() }}">
                <div class="rr-visually-hidden" aria-hidden="true">
                    <label>Website <input type="text" name="{{ config('ratings-and-reviews.spam.honeypot_field', 'website') }}" tabindex="-1" autocomplete="off"></label>
                </div>

                <p class="rr-visually-hidden" aria-live="polite" role="status" data-rr-live></p>
                <p class="rr-alert rr-alert--error" role="alert" data-rr-error @if (! $errors->any()) hidden @endif>{{ $errors->any() ? __('ratings-and-reviews::ui.form.errors') : '' }}</p>
                @if (! $formAction)
                    <noscript><p class="rr-notice">{{ __('ratings-and-reviews::ui.common.js_required') }}</p></noscript>
                @endif

                <div data-rr-field="rating">
                    <fieldset class="rr-scale rr-scale--{{ $scale }} {{ $errors->has('rating') ? 'is-invalid' : '' }}" @if ($errors->has('rating')) aria-invalid="true" aria-describedby="{{ $rootId }}-rating-error" @endif>
                        <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.rating') }}</legend>
                        <div class="rr-scale__options">
                            @for ($value = 1; $value <= $scale; $value++)
                                <label class="rr-scale__option {{ $rating >= $value ? 'is-filled' : '' }}">
                                    <input type="radio" name="rating" value="{{ $value }}" @checked($rating === $value) @if ($value === 1) required @endif>
                                    <span class="rr-scale__mark" aria-hidden="true">{{ $scale <= 5 ? '★' : $value }}</span>
                                    <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.form.rating_option', ['value' => $value, 'max' => $scale]) }}</span>
                                </label>
                            @endfor
                        </div>
                    </fieldset>
                    <p id="{{ $rootId }}-rating-error" class="rr-field-error" data-rr-field-error>{{ $errors->first('rating') }}</p>
                </div>

                @if ($topics !== [])
                    <fieldset class="rr-fieldset">
                        <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.topics') }}</legend>
                        <div class="rr-topic-inputs">
                            @foreach ($topics as $topic)
                                @php $current = (int) $old('topic_ratings.'.$topic['id'], $ownTopics[$topic['id']] ?? 0); @endphp
                                <fieldset class="rr-scale rr-scale--{{ $topic['scale_max'] }}" data-rr-topic="{{ $topic['id'] }}">
                                    <legend class="rr-label">{{ $topic['name'] }}</legend>
                                    <div class="rr-scale__options">
                                        <label class="rr-scale__option rr-scale__option--clear">
                                            <input type="radio" name="topic_ratings[{{ $topic['id'] }}]" value="" @checked($current === 0)>
                                            <span class="rr-scale__mark" aria-hidden="true">–</span>
                                            <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.form.topic_clear', ['name' => $topic['name']]) }}</span>
                                        </label>
                                        @for ($value = 1; $value <= $topic['scale_max']; $value++)
                                            <label class="rr-scale__option {{ $current >= $value ? 'is-filled' : '' }}">
                                                <input type="radio" name="topic_ratings[{{ $topic['id'] }}]" value="{{ $value }}" @checked($current === $value)>
                                                <span class="rr-scale__mark" aria-hidden="true">{{ $topic['scale_max'] <= 5 ? '★' : $value }}</span>
                                                <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.form.rating_option', ['value' => $value, 'max' => $topic['scale_max']]) }}</span>
                                            </label>
                                        @endfor
                                    </div>
                                    <p class="rr-field-error" data-rr-field-error="topic_ratings.{{ $topic['id'] }}">{{ $errors->first('topic_ratings.'.$topic['id']) }}</p>
                                </fieldset>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="rr-field" data-rr-field="title">
                    <label for="{{ $rootId }}-title-input" class="rr-label">{{ __('ratings-and-reviews::ui.form.title_label') }}</label>
                    <input id="{{ $rootId }}-title-input" type="text" name="title" class="rr-input" maxlength="{{ $maxTitle }}" value="{{ $old('title', $own['title'] ?? '') }}" aria-describedby="{{ $rootId }}-title-help">
                    <p id="{{ $rootId }}-title-help" class="rr-help">{{ __('ratings-and-reviews::ui.form.title_help', ['max' => $maxTitle]) }}</p>
                    <p class="rr-field-error" data-rr-field-error>{{ $errors->first('title') }}</p>
                </div>

                <div class="rr-field" data-rr-field="body">
                    <span class="rr-label">{{ __('ratings-and-reviews::ui.form.body') }}</span>
                    <x-rr::editor name="body" :id="$rootId.'-body'" :value="$old('body', $own['body_html'] ?? '')" :tools="$toolList" :locked="$locked" :label="__('ratings-and-reviews::ui.form.body')" :described-by="$rootId.'-body-help'" :invalid="$errors->has('body')" :rows="6" />
                    <p id="{{ $rootId }}-body-help" class="rr-help">{{ __('ratings-and-reviews::ui.form.body_help') }}</p>
                    <p class="rr-field-error" data-rr-field-error>{{ $errors->first('body') }}</p>
                </div>

                <div class="rr-points-inputs">
                    @foreach (['pros' => $pros, 'cons' => $cons] as $kind => $points)
                        @php $rowsToShow = max(1, min($maxPoints, count(array_filter($points)) + 1)); @endphp
                        <fieldset class="rr-fieldset" data-rr-points="{{ $kind }}">
                            <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.'.$kind) }}</legend>
                            @for ($index = 0; $index < $maxPoints; $index++)
                                <div class="rr-input-row" @if ($index >= $rowsToShow) data-rr-spare @endif>
                                    <input type="text" name="{{ $kind }}[]" class="rr-input" maxlength="{{ (int) config('ratings-and-reviews.reviews.max_pro_con_length', 200) }}" value="{{ $points[$index] ?? '' }}" aria-label="{{ __('ratings-and-reviews::ui.form.'.$kind) }}: {{ __('ratings-and-reviews::ui.form.point', ['number' => $index + 1]) }}">
                                </div>
                            @endfor
                            <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-add-point>{{ __('ratings-and-reviews::ui.form.'.($kind === 'pros' ? 'add_pro' : 'add_con')) }}</button>
                            <p class="rr-field-error" data-rr-field-error>{{ $errors->first($kind) }}</p>
                        </fieldset>
                    @endforeach
                </div>

                @if (! empty($meta['facets']))
                    <fieldset class="rr-fieldset">
                        <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.facets') }}</legend>
                        @foreach ($meta['facets'] as $group)
                            @php $chosen = $old('facets.'.$group['group'], $ownFacets[$group['group']] ?? ''); @endphp
                            <fieldset class="rr-fieldset rr-fieldset--nested">
                                <legend class="rr-label rr-label--minor">{{ $group['label'] }}</legend>
                                <div class="rr-chips">
                                    @foreach ($group['values'] as $value)
                                        <label class="rr-chip rr-chip--input"><input type="radio" name="facets[{{ $group['group'] }}]" value="{{ $value['value'] }}" @checked($chosen === $value['value'])><span>{{ $value['label'] }}</span></label>
                                    @endforeach
                                    <label class="rr-chip rr-chip--input"><input type="radio" name="facets[{{ $group['group'] }}]" value="" @checked($chosen === '' || $chosen === null)><span>{{ __('ratings-and-reviews::ui.form.facet_none') }}</span></label>
                                </div>
                            </fieldset>
                        @endforeach
                    </fieldset>
                @endif

                @if ($comparisonPicker !== null || ! empty($own['compared']))
                    <fieldset class="rr-fieldset rr-comparison-input" data-rr-field="comparison">
                        <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.comparison') }}</legend>
                        <p class="rr-help">{{ __('ratings-and-reviews::ui.form.comparison_help') }}</p>
                        {{ $comparisonPicker }}
                        @if ($comparisonPicker === null && ! empty($own['compared']))
                            <input type="hidden" name="compared_type" value="{{ $own['compared']['type'] }}">
                            <input type="hidden" name="compared_id" value="{{ $own['compared']['id'] }}">
                            <span class="rr-tag">{{ $own['compared']['title'] }}</span>
                        @endif
                        <label class="rr-inline-field">
                            <span class="rr-label">{{ __('ratings-and-reviews::ui.form.comparison_verdict') }}</span>
                            <select name="comparison" class="rr-select">
                                <option value="">{{ __('ratings-and-reviews::ui.form.comparison_none') }}</option>
                                @foreach ($meta['comparisons'] ?? [] as $option)
                                    <option value="{{ $option['value'] }}" @selected($old('comparison', $own['compared']['comparison'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="rr-field-error" data-rr-field-error>{{ $errors->first('compared_id') ?: $errors->first('comparison') }}</p>
                    </fieldset>
                @endif

                <div class="rr-quality rr-js-only" data-rr-quality hidden>
                    <div class="rr-quality__header">
                        <span id="{{ $rootId }}-quality-label" class="rr-label">{{ __('ratings-and-reviews::ui.quality.title') }}</span>
                        <span class="rr-quality__level" data-rr-quality-level></span>
                    </div>
                    <div class="rr-meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="{{ $rootId }}-quality-label" data-rr-quality-meter><span class="rr-meter__fill"></span></div>
                    <ul class="rr-quality__hints" aria-label="{{ __('ratings-and-reviews::ui.quality.hints') }}" data-rr-quality-hints></ul>
                </div>

                <div class="rr-tone" role="alertdialog" aria-labelledby="{{ $rootId }}-tone-title" aria-describedby="{{ $rootId }}-tone-body" data-rr-tone hidden>
                    <p id="{{ $rootId }}-tone-title" class="rr-tone__title">{{ __('ratings-and-reviews::ui.tone.title') }}</p>
                    <p id="{{ $rootId }}-tone-body" class="rr-tone__body" data-rr-tone-text></p>
                    <div class="rr-actions rr-actions--start">
                        <button type="button" class="rr-button rr-button--small rr-button--primary" data-rr-tone-rephrase>{{ __('ratings-and-reviews::ui.tone.rephrase') }}</button>
                        <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-tone-confirm>{{ __('ratings-and-reviews::ui.tone.post_anyway') }}</button>
                    </div>
                </div>

                <div class="rr-actions">
                    <button type="submit" class="rr-button rr-button--primary" data-busy-label="{{ __('ratings-and-reviews::ui.form.submitting') }}">{{ $own ? __('ratings-and-reviews::ui.form.update') : __('ratings-and-reviews::ui.form.submit') }}</button>
                </div>
            </form>
        @endif
    </section>
@endif
