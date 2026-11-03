@php
    /** No-JavaScript report form (package form routes): $reportType (rr_review|rr_comment), $reportId. */
    $reportAction = $rr['webRoute']('reports.store');
    $reportForm = 'report:'.$reportType.':'.$reportId;
    $reportErrors = $rr['isForm']($reportForm) ? $rr['formErrors']('rr_report') : new \Illuminate\Support\MessageBag;
    $reportFieldId = 'rr-report-'.$reportType.'-'.$reportId;
@endphp
@if ($reportAction)
    <details class="rr-nojs-only rr-disclosure" @if ($reportErrors->any()) open @endif>
        <summary class="rr-button rr-button--small rr-button--ghost">{{ __('ratings-and-reviews::ui.reviews.report') }}</summary>
        <form method="post" action="{{ $reportAction }}" class="rr-form rr-report rr-disclosure__panel" novalidate>
            @csrf
            <input type="hidden" name="reportable_type" value="{{ $reportType }}">
            <input type="hidden" name="reportable_id" value="{{ $reportId }}">
            <div class="rr-field">
                <label for="{{ $reportFieldId }}-reason" class="rr-label">{{ __('ratings-and-reviews::ui.report.reason') }}</label>
                <select id="{{ $reportFieldId }}-reason" name="reason" class="rr-select" required @if ($reportErrors->has('reason')) aria-invalid="true" @endif>
                    @foreach ($rr['reportReasons']() as $reason)
                        <option value="{{ $reason['value'] }}" @selected($rr['isForm']($reportForm) && old('reason') === $reason['value'])>{{ $reason['label'] }}</option>
                    @endforeach
                </select>
                <p class="rr-field-error">{{ $reportErrors->first('reason') }}</p>
            </div>
            <div class="rr-field">
                <label for="{{ $reportFieldId }}-details" class="rr-label">{{ __('ratings-and-reviews::ui.report.details') }}</label>
                <textarea id="{{ $reportFieldId }}-details" name="details" class="rr-textarea" rows="3" maxlength="{{ (int) config('ratings-and-reviews.reports.max_details_length', 1000) }}" @if ($reportErrors->has('details')) aria-invalid="true" @endif>{{ $rr['isForm']($reportForm) ? old('details') : '' }}</textarea>
                <p class="rr-field-error">{{ $reportErrors->first('details') }}</p>
            </div>
            <button type="submit" class="rr-button rr-button--small rr-button--primary">{{ __('ratings-and-reviews::ui.report.submit') }}</button>
        </form>
    </details>
@endif
