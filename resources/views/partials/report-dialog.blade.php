{{-- One report dialog per component; opened by resources/js/blade/enhance.js for [data-rr-report]. --}}
<dialog class="rr rr-dialog" id="{{ $id }}" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-intro" data-rr-report-dialog>
    <form method="dialog" class="rr-dialog__panel rr-form rr-report" novalidate data-rr-report-form>
        <header class="rr-dialog__header">
            <h2 id="{{ $id }}-title" class="rr-dialog__title">{{ __('ratings-and-reviews::ui.report.title') }}</h2>
            <button type="button" class="rr-icon-button rr-dialog__close" value="cancel" data-rr-dialog-close aria-label="{{ __('ratings-and-reviews::ui.common.close') }}">×</button>
        </header>
        <p id="{{ $id }}-intro" class="rr-help">{{ __('ratings-and-reviews::ui.report.intro') }}</p>
        <fieldset class="rr-fieldset">
            <legend class="rr-label">{{ __('ratings-and-reviews::ui.report.reason') }}</legend>
            @foreach ($rr['reportReasons']() as $reason)
                <label class="rr-choice"><input type="radio" name="reason" value="{{ $reason['value'] }}" required><span>{{ $reason['label'] }}</span></label>
            @endforeach
        </fieldset>
        <div class="rr-field">
            <label for="{{ $id }}-details" class="rr-label">{{ __('ratings-and-reviews::ui.report.details') }}</label>
            <textarea id="{{ $id }}-details" name="details" class="rr-textarea" rows="3" maxlength="{{ (int) config('ratings-and-reviews.reports.max_details_length', 1000) }}"></textarea>
        </div>
        <p class="rr-alert rr-alert--error" role="alert" data-rr-error hidden></p>
        <div class="rr-actions">
            <button type="button" class="rr-button rr-button--ghost" data-rr-dialog-close>{{ __('ratings-and-reviews::ui.common.cancel') }}</button>
            <button type="submit" class="rr-button rr-button--primary">{{ __('ratings-and-reviews::ui.report.submit') }}</button>
        </div>
    </form>
</dialog>
