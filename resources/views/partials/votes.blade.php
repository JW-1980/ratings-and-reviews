@php
    /** @var array<string, array{up: int, down: int}> $counts */
    $positiveOnly ??= false;
    $guestVotes ??= false;
    $isOwn ??= false;
    $labels = [
        'agreement' => [[1, 'agree'], [-1, 'disagree']],
        'helpfulness' => $positiveOnly ? [[1, 'helpful']] : [[1, 'helpful'], [-1, 'unhelpful']],
        'needs_source' => [[1, 'needs_source']],
    ];
@endphp
<div class="rr-votes" role="group" aria-label="{{ __('ratings-and-reviews::ui.votes.group') }}" data-rr-votes data-target="{{ $target }}" data-id="{{ $id }}" data-own="{{ $isOwn ? '1' : '0' }}" data-guest-votes="{{ $guestVotes ? '1' : '0' }}">
    @foreach ($dimensions as $dimension)
        @foreach ($labels[$dimension] ?? [] as [$value, $label])
            @php $count = (int) ($counts[$dimension][$value === 1 ? 'up' : 'down'] ?? 0); @endphp
            <button type="button"
                class="rr-vote rr-vote--{{ $dimension }} {{ $value === 1 ? 'rr-vote--up' : 'rr-vote--down' }}"
                aria-pressed="{{ (int) ($viewerVotes[$dimension] ?? 0) === $value ? 'true' : 'false' }}"
                aria-label="{{ __('ratings-and-reviews::ui.votes.count', ['label' => __('ratings-and-reviews::ui.votes.'.$label), 'count' => $count]) }}"
                data-dimension="{{ $dimension }}" data-value="{{ $value }}" data-label="{{ __('ratings-and-reviews::ui.votes.'.$label) }}"
                @if ($isOwn || (! $rr['clientConfig']()['authenticated'] && ! $guestVotes)) aria-disabled="true" @endif
            ><span class="rr-vote__label" aria-hidden="true">{{ __('ratings-and-reviews::ui.votes.'.$label) }}</span><span class="rr-vote__count" aria-hidden="true">{{ $count }}</span></button>
        @endforeach
    @endforeach
    <p class="rr-votes__note" role="status" data-rr-vote-note></p>
</div>
