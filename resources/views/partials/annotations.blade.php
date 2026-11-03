@php
    /**
     * The badge row of a card header: the author's badges (AuthorResolver `extra.badges`, e.g.
     * reliability, flair) followed by the ContentAnnotator's annotations of the item. Each is
     * {key, label, tone: info|warning|success, url}. $annotations: the item's `annotations`,
     * $author: its `author`.
     */
    $badges = collect((array) ($author['extra']['badges'] ?? []))
        ->filter(fn ($badge): bool => is_array($badge) && ! empty($badge['label']))
        ->map(fn (array $badge): array => array_merge($badge, ['source' => 'author']));
    $marks = $badges->concat(collect((array) ($annotations ?? []))->filter(fn ($mark): bool => is_array($mark) && ! empty($mark['label']))->map(fn (array $mark): array => array_merge($mark, ['source' => 'content'])));
    $icons = ['info' => 'ℹ', 'warning' => '⚠', 'success' => '✓'];
    $toneLabels = ['info' => __('ratings-and-reviews::ui.annotations.tone_info'), 'warning' => __('ratings-and-reviews::ui.annotations.tone_warning'), 'success' => __('ratings-and-reviews::ui.annotations.tone_success')];
@endphp
@if ($marks->isNotEmpty())
    <ul class="rr-annotations" aria-label="{{ __('ratings-and-reviews::ui.annotations.label') }}">
        @foreach ($marks as $mark)
            @php $tone = in_array($mark['tone'] ?? 'info', ['info', 'warning', 'success'], true) ? $mark['tone'] : 'info'; @endphp
            <li>
                <span class="rr-tag rr-tag--annotation" data-rr-annotation="{{ $mark['key'] ?? 'badge' }}" data-rr-annotation-source="{{ $mark['source'] }}" data-tone="{{ $tone }}">
                    <span class="rr-tag__icon" aria-hidden="true">{{ $icons[$tone] }}</span>
                    <span class="rr-visually-hidden">{{ $toneLabels[$tone] }}: </span>
                    @if (! empty($mark['url']))<a href="{{ $mark['url'] }}">{{ $mark['label'] }}</a>@else{{ $mark['label'] }}@endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
