@php
    /** No-JavaScript delete with a confirmation step (native <details>): $deleteAction, $deleteQuestion. */
@endphp
@if ($deleteAction)
    <details class="rr-nojs-only rr-disclosure">
        <summary class="rr-button rr-button--small rr-button--ghost">{{ __('ratings-and-reviews::ui.common.delete') }}</summary>
        <form method="post" action="{{ $deleteAction }}" class="rr-disclosure__panel">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
            <p class="rr-help">{{ $deleteQuestion }}</p>
            <button type="submit" class="rr-button rr-button--small rr-button--danger">{{ __('ratings-and-reviews::ui.common.delete') }}</button>
        </form>
    </details>
@endif
