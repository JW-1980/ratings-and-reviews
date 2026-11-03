@php
    $digits ??= 0;
    $size ??= 'sm';
    $fill = $value === null ? 0 : max(0, min(1, ($value - 1) / max(1, $max - 1)));
@endphp
<span class="rr-score rr-score--{{ $size }}" role="img" aria-label="{{ __('ratings-and-reviews::ui.reviews.rating', ['rating' => $rr['number']($value, $digits), 'max' => $max]) }}" style="--rr-fill: {{ $fill }}">
    @if ($max <= 5)
        <span class="rr-score__stars" aria-hidden="true">@for ($n = 1; $n <= $max; $n++)<span class="rr-score__star {{ $value !== null && $n <= round($value) ? 'is-on' : '' }}">★</span>@endfor</span>
    @endif
    <span class="rr-score__value" aria-hidden="true">{{ $rr['number']($value, $digits) }}<span class="rr-score__max">/{{ $max }}</span></span>
</span>
