@php
    /** The badge of a contribution posted "as" a brand or shop: $attribution {key, label, url} or null. */
@endphp
@if (! empty($attribution['label']))
    <span class="rr-tag rr-tag--attribution" data-rr-attribution="{{ $attribution['key'] }}">
        <span class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.discussion.posted_as', ['label' => $attribution['label']]) }}</span>
        <span aria-hidden="true">@if (! empty($attribution['url']))<a href="{{ $attribution['url'] }}">{{ $attribution['label'] }}</a>@else{{ $attribution['label'] }}@endif</span>
    </span>
@endif
