{{--
    <x-rr::review-form :subject="$product" :action="route('reviews.store', $product)" />
    Score on the section's scale, topic scores, title, text (editor), pros and cons, facets,
    an optional comparison picker slot and the live quality meter (JavaScript, same rules as
    the server). With JavaScript it posts to the package API; without it, it posts to `action`
    (a host route) or, with `web_routes.enabled`, to the package form routes, and shows the
    errors and `session('rr_status')` after the redirect. Title, pros and cons follow the
    section's `review_extended_fields`; photos (up to `reviews.max_photos`) are uploaded with
    POST uploads (JavaScript) or sent as files to the form route.

    <x-slot:comparison-picker> must render inputs `compared_type` and `compared_id` (the host's
    own product search); the verdict select is part of this component.

    The subject's review questions (meta.review_questions) are asked as `answers[key]`
    (`answers[key][]` for several choices); with attributions (AttributionResolver) a select
    `attribution` offers posting "as" a brand. Renders nothing when the section's
    `reviews_enabled` is off, and a notice when the subject's participation rules keep the
    member out.
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
        $extended = (bool) ($meta['review_extended_fields'] ?? true);
        $minLength = (int) ($meta['review_min_length'] ?? 0);
        $limits = (array) ($meta['review_form'] ?? []);
        $maxPhotos = (int) ($limits['max_photos'] ?? 0);
        $ownsFlash = $rr['isForm']('review', main: true);
        $formErrors = $ownsFlash ? $rr['formErrors']('rr_review') : new \Illuminate\Support\MessageBag;
        $toolList = $tools ?? ($meta['editor']['editor_tools'] ?? ['bold', 'italic']);
        $locked = collect($meta['editor']['locked'] ?? [])->pluck('required', 'key')->all();
        $old = fn (string $field, mixed $default = null) => $ownsFlash ? old($field, $default) : $default;
        $rating = (int) $old('rating', $own['rating'] ?? 0);
        $ownTopics = collect($own['topic_ratings'] ?? [])->pluck('value', 'topic_id')->all();
        $ownFacets = collect($own['facets'] ?? [])->pluck('value', 'group')->all();
        $pros = array_values((array) $old('pros', $own['pros'] ?? []));
        $cons = array_values((array) $old('cons', $own['cons'] ?? []));
        $maxPoints = (int) ($limits['max_pros_cons'] ?? config('ratings-and-reviews.reviews.max_pros_cons', 5));
        $maxTitle = (int) ($limits['max_title_length'] ?? config('ratings-and-reviews.reviews.max_title_length', 150));
        $maxPointLength = (int) ($limits['max_pro_con_length'] ?? config('ratings-and-reviews.reviews.max_pro_con_length', 200));
        $updateAction ??= $own !== null ? $rr['webRoute']('reviews.update', ['review' => $own['id']]) : null;
        $action ??= $rr['webRoute']('reviews.store', ['type' => $type, 'id' => $key]);
        $formAction = $own !== null ? ($updateAction ?? $action) : $action;
        $ownPhotos = (array) ($own['photos'] ?? []);
        $keptPhotos = $ownsFlash && old('photos_present') ? array_map('intval', (array) old('photos', [])) : array_column($ownPhotos, 'id');
        $questions = (array) ($meta['review_questions'] ?? []);
        $ownAnswers = collect($own['answers'] ?? [])->pluck('value', 'key')->all();
        $attributions = (array) ($meta['attributions'] ?? []);
        $errors = $formErrors;
    @endphp
    @if ($meta['reviews_enabled'] ?? true)
    @include('rr::partials.client')
    <section {{ $attributes->merge(['class' => 'rr rr-nojs rr-review-form']) }} id="{{ $rootId }}" data-rr="review-form" data-rr-subject-type="{{ $type }}" data-rr-subject-id="{{ $key }}" @if ($own) data-rr-review-id="{{ $own['id'] }}" @endif aria-labelledby="{{ $rootId }}-title">
        <h2 id="{{ $rootId }}-title" class="rr-section-header__title">{{ $own ? __('ratings-and-reviews::ui.form.edit_title') : __('ratings-and-reviews::ui.form.title') }}</h2>

        @if (session('rr_status') && in_array(session('rr_form'), [null, 'review'], true))
            <p class="rr-alert rr-alert--success" role="status">{{ session('rr_status') }}</p>
        @endif
        @if (session('rr_error') && session('rr_form') === 'review')
            <p class="rr-alert rr-alert--error" role="alert">{{ session('rr_error') }}</p>
        @endif

        @if (! $authenticated)
            <p class="rr-notice">@if ($loginUrl)<a href="{{ $loginUrl }}">{{ __('ratings-and-reviews::ui.form.login_required') }}</a>@else{{ __('ratings-and-reviews::ui.form.login_required') }}@endif</p>
        @elseif (($meta['can_participate'] ?? null) === false)
            <p class="rr-notice" data-rr-participation-denied>{{ __('ratings-and-reviews::ui.reviews.participation_denied') }}</p>
        @elseif (! ($meta['accepts_reviews'] ?? true))
            <p class="rr-notice">{{ __('ratings-and-reviews::ui.form.closed') }}</p>
        @else
            @if ($rules !== null)
                <script type="application/json" data-rr-quality-rules>@json($rules)</script>
            @endif
            <form method="post" action="{{ $formAction ?? '#' }}" class="rr-form" novalidate data-rr-review-form data-topic-count="{{ count($topics) }}" data-extended="{{ $extended ? '1' : '0' }}" data-max-points="{{ $maxPoints }}" data-max-photos="{{ $maxPhotos }}" data-min-length="{{ $minLength }}" @if ($maxPhotos > 0) enctype="multipart/form-data" @endif>
                @csrf
                @if ($own !== null && $updateAction)<input type="hidden" name="_method" value="PATCH">@endif
                @if ($ownsFlash && session('rr_tone'))
                    {{-- The rephrase nudge after a post without JavaScript: ticking this posts it as it is. --}}
                    <p class="rr-notice rr-nojs-only">{{ session('rr_tone') }}</p>
                    <label class="rr-choice rr-nojs-only"><input type="checkbox" name="confirm_tone" value="1"><span>{{ __('ratings-and-reviews::ui.tone.post_anyway') }}</span></label>
                @endif
                <input type="hidden" name="{{ config('ratings-and-reviews.spam.started_at_field', 'form_started_at') }}" value="{{ time() }}">
                <div class="rr-visually-hidden" aria-hidden="true">
                    <label>{{ __('ratings-and-reviews::ui.common.honeypot') }} <input type="text" name="{{ config('ratings-and-reviews.spam.honeypot_field', 'website') }}" tabindex="-1" autocomplete="off"></label>
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

                @if ($questions !== [])
                    {{-- Review questions: a scale with words at both ends, one choice or several. --}}
                    <fieldset class="rr-fieldset rr-questions" data-rr-questions>
                        <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.questions') }}</legend>
                        @foreach ($questions as $question)
                            @php
                                $field = 'answers.'.$question['key'];
                                $answer = $old($field, $ownAnswers[$question['key']] ?? null);
                                $questionId = $rootId.'-q-'.$question['key'];
                            @endphp
                            <fieldset class="rr-fieldset rr-fieldset--nested rr-question rr-question--{{ $question['type'] }}" data-rr-question="{{ $question['key'] }}" data-type="{{ $question['type'] }}" @if ($errors->has($field)) aria-invalid="true" aria-describedby="{{ $questionId }}-error" @endif>
                                <legend class="rr-label rr-label--minor">{{ $question['label'] }}@if ($question['required']) <span class="rr-tag">{{ __('ratings-and-reviews::ui.form.question_required') }}</span>@endif</legend>
                                @if ($question['type'] === 'scale')
                                    <div class="rr-question__scale">
                                        @if ($question['low_label'])<span class="rr-question__anchor" aria-hidden="true">{{ $question['low_label'] }}</span>@endif
                                        <div class="rr-chips">
                                            @unless ($question['required'])
                                                <label class="rr-chip rr-chip--input"><input type="radio" name="answers[{{ $question['key'] }}]" value="" @checked($answer === null || $answer === '')><span>{{ __('ratings-and-reviews::ui.form.question_none') }}</span></label>
                                            @endunless
                                            @for ($value = 1; $value <= (int) $question['scale_max']; $value++)
                                                <label class="rr-chip rr-chip--input">
                                                    <input type="radio" name="answers[{{ $question['key'] }}]" value="{{ $value }}" @checked((string) $answer === (string) $value)>
                                                    <span aria-hidden="true">{{ $value }}</span>
                                                    <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.form.question_scale_option', ['value' => $value, 'max' => $question['scale_max']]) }}@if ($value === 1 && $question['low_label']), {{ $question['low_label'] }}@elseif ($value === (int) $question['scale_max'] && $question['high_label']), {{ $question['high_label'] }}@endif</span>
                                                </label>
                                            @endfor
                                        </div>
                                        @if ($question['high_label'])<span class="rr-question__anchor" aria-hidden="true">{{ $question['high_label'] }}</span>@endif
                                    </div>
                                @else
                                    @php $chosen = array_map('strval', (array) ($answer ?? [])); @endphp
                                    <div class="rr-chips">
                                        @if ($question['type'] === 'choice' && ! $question['required'])
                                            <label class="rr-chip rr-chip--input"><input type="radio" name="answers[{{ $question['key'] }}]" value="" @checked($chosen === [] || $chosen === [''])><span>{{ __('ratings-and-reviews::ui.form.question_none') }}</span></label>
                                        @endif
                                        @foreach ($question['options'] as $option)
                                            <label class="rr-chip rr-chip--input">
                                                @if ($question['type'] === 'multi')
                                                    <input type="checkbox" name="answers[{{ $question['key'] }}][]" value="{{ $option['value'] }}" @checked(in_array($option['value'], $chosen, true))>
                                                @else
                                                    <input type="radio" name="answers[{{ $question['key'] }}]" value="{{ $option['value'] }}" @checked(in_array($option['value'], $chosen, true))>
                                                @endif
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                                <p id="{{ $questionId }}-error" class="rr-field-error" data-rr-field-error="{{ $field }}">{{ $errors->first($field) }}</p>
                            </fieldset>
                        @endforeach
                    </fieldset>
                @endif

                @if ($extended)
                <div class="rr-field" data-rr-field="title">
                    <label for="{{ $rootId }}-title-input" class="rr-label">{{ __('ratings-and-reviews::ui.form.title_label') }}</label>
                    <input id="{{ $rootId }}-title-input" type="text" name="title" class="rr-input" maxlength="{{ $maxTitle }}" value="{{ $old('title', $own['title'] ?? '') }}" aria-describedby="{{ $rootId }}-title-help">
                    <p id="{{ $rootId }}-title-help" class="rr-help">{{ __('ratings-and-reviews::ui.form.title_help', ['max' => $maxTitle]) }}</p>
                    <p class="rr-field-error" data-rr-field-error>{{ $errors->first('title') }}</p>
                </div>
                @endif

                <div class="rr-field" data-rr-field="body">
                    <span class="rr-label">{{ __('ratings-and-reviews::ui.form.body') }}</span>
                    <x-rr::editor name="body" :id="$rootId.'-body'" :value="$old('body', $own['body_html'] ?? '')" :tools="$toolList" :locked="$locked" :label="__('ratings-and-reviews::ui.form.body')" :described-by="$rootId.'-body-help'" :invalid="$errors->has('body')" :rows="6" />
                    <p id="{{ $rootId }}-body-help" class="rr-help">{{ __('ratings-and-reviews::ui.form.body_help') }}@if ($minLength > 0) {{ trans_choice('ratings-and-reviews::ui.form.min_length', $minLength, ['count' => $minLength]) }}@endif</p>
                    <p class="rr-field-error" data-rr-field-error>{{ $errors->first('body') }}</p>
                </div>

                @if ($extended)
                <div class="rr-points-inputs">
                    @foreach (['pros' => $pros, 'cons' => $cons] as $kind => $points)
                        @php $rowsToShow = max(1, min($maxPoints, count(array_filter($points)) + 1)); @endphp
                        <fieldset class="rr-fieldset" data-rr-points="{{ $kind }}">
                            <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.'.$kind) }}</legend>
                            @for ($index = 0; $index < $maxPoints; $index++)
                                <div class="rr-input-row" @if ($index >= $rowsToShow) data-rr-spare @endif>
                                    <input type="text" name="{{ $kind }}[]" class="rr-input" maxlength="{{ $maxPointLength }}" value="{{ $points[$index] ?? '' }}" aria-label="{{ __('ratings-and-reviews::ui.form.'.$kind) }}: {{ __('ratings-and-reviews::ui.form.point', ['number' => $index + 1]) }}">
                                </div>
                            @endfor
                            <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-add-point>{{ __('ratings-and-reviews::ui.form.'.($kind === 'pros' ? 'add_pro' : 'add_con')) }}</button>
                            <p class="rr-field-error" data-rr-field-error>{{ $errors->first($kind) }}</p>
                        </fieldset>
                    @endforeach
                </div>
                @endif

                @if ($maxPhotos > 0)
                    {{-- Photos: kept ones are ticked checkboxes (photos[]); new ones are uploaded at once with JavaScript, or sent as photo_files[] by the form route. --}}
                    <fieldset class="rr-fieldset rr-photo-input" data-rr-field="photos" data-rr-photos data-max="{{ $maxPhotos }}">
                        <legend class="rr-label">{{ __('ratings-and-reviews::ui.form.photos') }}</legend>
                        <p id="{{ $rootId }}-photos-help" class="rr-help">{{ trans_choice('ratings-and-reviews::ui.form.photos_help', $maxPhotos, ['count' => $maxPhotos]) }}@if ($limits['photos_require_approval'] ?? false) {{ __('ratings-and-reviews::ui.form.photos_moderated') }}@endif</p>
                        <input type="hidden" name="photos_present" value="1">
                        <ul class="rr-photos rr-photos--input" data-rr-photo-list>
                            @foreach ($ownPhotos as $photo)
                                <li>
                                    <label class="rr-photo-choice">
                                        <input type="checkbox" name="photos[]" value="{{ $photo['id'] }}" @checked(in_array($photo['id'], $keptPhotos, true))>
                                        <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" loading="lazy">
                                        <span class="rr-photo-choice__label">{{ __('ratings-and-reviews::ui.form.photo_keep') }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <div class="rr-field @if (! $formAction) rr-js-only @endif">
                            <label for="{{ $rootId }}-photo-files" class="rr-label rr-label--minor">{{ __('ratings-and-reviews::ui.form.photos_add') }}</label>
                            <input id="{{ $rootId }}-photo-files" type="file" name="photo_files[]" class="rr-input rr-input--file" multiple accept="{{ implode(',', $rr['clientConfig']()['uploadTypes']) }}" aria-describedby="{{ $rootId }}-photos-help" data-rr-photo-files>
                        </div>
                        <p class="rr-help" role="status" data-rr-photo-status></p>
                        <p class="rr-field-error" data-rr-field-error>{{ $errors->first('photos') ?: $errors->first('photos.*') }}</p>
                    </fieldset>
                @endif

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

                @if ($attributions !== [])
                    @php $ownAttribution = $old('attribution', $own['attribution']['key'] ?? ''); @endphp
                    <div class="rr-field" data-rr-field="attribution">
                        <label for="{{ $rootId }}-attribution" class="rr-label">{{ __('ratings-and-reviews::ui.form.post_as') }}</label>
                        <select id="{{ $rootId }}-attribution" name="attribution" class="rr-select">
                            <option value="">{{ __('ratings-and-reviews::ui.form.post_as_self') }}</option>
                            @foreach ($attributions as $option)
                                <option value="{{ $option['key'] }}" @selected($ownAttribution === $option['key'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="rr-field-error" data-rr-field-error>{{ $errors->first('attribution') }}</p>
                    </div>
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
@endif
