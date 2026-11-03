@php
    $formId = $formId ?? $rootId.'-composer';
    $label = $parentId ? __('ratings-and-reviews::ui.composer.reply_label', ['name' => $replyName ?? '']) : __('ratings-and-reviews::ui.composer.label');
    $polls = $settings['polls'] ?? ['enabled' => false, 'min_options' => 2, 'max_options' => 4];
    $canPoll = ! $parentId && ($polls['enabled'] ?? false) && ($trust['can_create_poll'] ?? true);
    // Flashed errors and old input belong to this composer only (top level or the reply to $parentId).
    $template = $parentId === '__PARENT__';
    $composerForm = 'comment:'.($parentId ?: 'root');
    $ownsFlash = ! $template && $rr['isForm']($composerForm, main: ! $parentId);
    $composerErrors = $ownsFlash ? $rr['formErrors']('rr_comment') : new \Illuminate\Support\MessageBag;
@endphp
<form method="post" action="{{ $action ?? '#' }}" id="{{ $formId }}" class="rr-composer {{ $parentId ? 'rr-composer--reply' : '' }}" novalidate aria-labelledby="{{ $formId }}-label"
    data-rr-composer data-min-length="{{ $settings['min_length'] }}" data-max-length="{{ $settings['max_length'] }}" data-tone-check="{{ ($settings['tone_check'] ?? false) ? '1' : '0' }}"
    data-min-options="{{ $polls['min_options'] ?? 2 }}" data-max-options="{{ $polls['max_options'] ?? 4 }}" data-wait="{{ $wait ?? 0 }}">
    @csrf
    <input type="hidden" name="parent_id" value="{{ $parentId }}" data-rr-parent>
    <input type="hidden" name="quoted_comment_id" value="" data-rr-quote-id disabled>
    <input type="hidden" name="quote_text" value="" data-rr-quote-text disabled>
    <input type="hidden" name="{{ config('ratings-and-reviews.spam.started_at_field', 'form_started_at') }}" value="{{ time() }}">
    <div class="rr-visually-hidden" aria-hidden="true"><label>{{ __('ratings-and-reviews::ui.common.honeypot') }} <input type="text" name="{{ config('ratings-and-reviews.spam.honeypot_field', 'website') }}" tabindex="-1" autocomplete="off"></label></div>

    <p id="{{ $formId }}-label" class="rr-label">{{ $label }}</p>
    @if ($parentId)
        <p class="rr-composer__context">{{ __('ratings-and-reviews::ui.discussion.replying_to', ['name' => $replyName ?? '']) }}</p>
    @endif
    <p class="rr-composer__quote" data-rr-quote-chip hidden>
        <span class="rr-quote rr-quote--chip" data-rr-quote-preview></span>
        <button type="button" class="rr-icon-button" data-rr-quote-remove aria-label="{{ __('ratings-and-reviews::ui.discussion.remove_quote') }}">×</button>
    </p>

    <x-rr::editor name="body" :id="$formId.'-body'" :value="$ownsFlash ? old('body', '') : ''" :tools="$settings['editor_tools']" :locked="$locked"
        :label="$label" :placeholder="__('ratings-and-reviews::ui.composer.placeholder')" :described-by="$formId.'-meta'" :invalid="$composerErrors->has('body')"
        :minlength="$settings['min_length']" :maxlength="$settings['max_length']" required :rows="4" />

    @if ($canPoll)
        <fieldset class="rr-poll-builder" data-rr-poll-builder hidden disabled>
            <legend class="rr-label">{{ __('ratings-and-reviews::ui.poll.title') }}</legend>
            <div class="rr-field">
                <label for="{{ $formId }}-poll-q" class="rr-label">{{ __('ratings-and-reviews::ui.poll.question') }}</label>
                <input id="{{ $formId }}-poll-q" type="text" name="poll[question]" class="rr-input" maxlength="{{ (int) config('ratings-and-reviews.polls.max_question_length', 255) }}" data-rr-poll-question>
            </div>
            @for ($index = 0; $index < ($polls['max_options'] ?? 4); $index++)
                <div class="rr-input-row" @if ($index >= ($polls['min_options'] ?? 2)) data-rr-spare @endif>
                    <label for="{{ $formId }}-poll-{{ $index }}" class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.poll.option', ['number' => $index + 1]) }}</label>
                    <input id="{{ $formId }}-poll-{{ $index }}" type="text" name="poll[options][]" class="rr-input" maxlength="{{ (int) config('ratings-and-reviews.polls.max_option_length', 120) }}" placeholder="{{ __('ratings-and-reviews::ui.poll.option', ['number' => $index + 1]) }}" data-rr-poll-option-input>
                </div>
            @endfor
            <p class="rr-field-error" role="alert" data-rr-poll-error></p>
            <div class="rr-actions rr-actions--start">
                <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-poll-add>{{ __('ratings-and-reviews::ui.poll.add_option') }}</button>
                <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-poll-remove>{{ __('ratings-and-reviews::ui.composer.remove_poll') }}</button>
            </div>
        </fieldset>
    @endif

    <div class="rr-tone" role="alertdialog" aria-labelledby="{{ $formId }}-tone-title" aria-describedby="{{ $formId }}-tone-body" data-rr-tone hidden>
        <p id="{{ $formId }}-tone-title" class="rr-tone__title">{{ __('ratings-and-reviews::ui.tone.title') }}</p>
        <p id="{{ $formId }}-tone-body" class="rr-tone__body" data-rr-tone-text></p>
        <div class="rr-actions rr-actions--start">
            <button type="button" class="rr-button rr-button--small rr-button--primary" data-rr-tone-rephrase>{{ __('ratings-and-reviews::ui.tone.rephrase') }}</button>
            <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-tone-confirm>{{ __('ratings-and-reviews::ui.tone.post_anyway') }}</button>
        </div>
    </div>

    <p class="rr-field-error" role="alert" data-rr-error>{{ $composerErrors->first('body') ?: ($composerErrors->first('parent_id') ?: $composerErrors->first('confirm_tone')) }}</p>
    @if ($ownsFlash && session('rr_tone'))
        {{-- The rephrase nudge after a form post without JavaScript: posting again with this ticked goes through. --}}
        <label class="rr-choice rr-nojs-only"><input type="checkbox" name="confirm_tone" value="1"><span>{{ __('ratings-and-reviews::ui.tone.post_anyway') }}</span></label>
    @endif
    <p class="rr-notice rr-notice--slow" role="status" data-rr-wait @if (($wait ?? 0) <= 0) hidden @endif>{{ __('ratings-and-reviews::ui.discussion.slow_mode_wait', ['seconds' => $wait ?? 0]) }}</p>
    @if (! ($action ?? null))
        <noscript><p class="rr-notice">{{ __('ratings-and-reviews::ui.common.js_required') }}</p></noscript>
    @endif

    <div class="rr-composer__footer">
        <p id="{{ $formId }}-meta" class="rr-composer__meta">
            <span data-rr-length class="rr-js-only"></span>
            <span class="rr-composer__draft" data-rr-draft></span>
            <span class="rr-composer__shortcut rr-js-only">{{ __('ratings-and-reviews::ui.composer.shortcut') }}</span>
        </p>
        <div class="rr-actions">
            <button type="button" class="rr-button rr-button--small rr-button--ghost" data-rr-discard-draft hidden>{{ __('ratings-and-reviews::ui.composer.discard_draft') }}</button>
            @if ($canPoll)
                <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-poll-toggle aria-expanded="false">{{ __('ratings-and-reviews::ui.composer.add_poll') }}</button>
            @endif
            @if ($parentId)
                <a href="{{ $cancelUrl ?? '#' }}" class="rr-button rr-button--ghost" data-rr-cancel-reply>{{ __('ratings-and-reviews::ui.discussion.cancel_reply') }}</a>
            @endif
            <button type="submit" class="rr-button rr-button--primary" data-busy-label="{{ __('ratings-and-reviews::ui.composer.submitting') }}" @disabled(($wait ?? 0) > 0)>{{ $parentId ? __('ratings-and-reviews::ui.composer.reply_submit') : __('ratings-and-reviews::ui.composer.submit') }}</button>
        </div>
    </div>
</form>
