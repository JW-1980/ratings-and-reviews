@php
    /** No-JavaScript delete with a confirmation step (native <details>): $deleteAction, $deleteQuestion, optional $deleteLabel. */
    $deleteLabel ??= __('ratings-and-reviews::ui.common.delete');
@endphp
@if ($deleteAction)
    <details class="rr-nojs-only rr-disclosure">
        <summary class="rr-button rr-button--small rr-button--ghost">{{ $deleteLabel }}</summary>
        <form method="post" action="{{ $deleteAction }}" class="rr-disclosure__panel">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
            <p class="rr-help">{{ $deleteQuestion }}</p>
            <button type="submit" class="rr-button rr-button--small rr-button--danger">{{ $deleteLabel }}</button>
        </form>
    </details>
@endif
