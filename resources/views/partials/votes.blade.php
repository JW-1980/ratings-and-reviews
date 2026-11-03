@php
    /** @var array<string, array{up: int, down: int}> $counts */
    $positiveOnly ??= false;
    $guestVotes ??= false;
    $isOwn ??= false;
    // The author is in a block relation with the viewer (BlockList): shown, but not votable.
    $blocked ??= false;
    $labels = [
        'agreement' => [[1, 'agree'], [-1, 'disagree']],
        'helpfulness' => $positiveOnly ? [[1, 'helpful']] : [[1, 'helpful'], [-1, 'unhelpful']],
        'needs_source' => [[1, 'needs_source']],
    ];
    // Without JavaScript each vote is its own small form (package form routes, when enabled).
    $voteAction = $rr['webRoute']($target === 'review' ? 'reviews.votes.store' : 'comments.votes.store', [$target => $id]);
@endphp
<div class="rr-votes" role="group" aria-label="{{ __('ratings-and-reviews::ui.votes.group') }}" data-rr-votes data-target="{{ $target }}" data-id="{{ $id }}" data-own="{{ $isOwn ? '1' : '0' }}" data-blocked="{{ $blocked ? '1' : '0' }}" data-guest-votes="{{ $guestVotes ? '1' : '0' }}">
    @foreach ($dimensions as $dimension)
        @foreach ($labels[$dimension] ?? [] as [$value, $label])
            @php $count = (int) ($counts[$dimension][$value === 1 ? 'up' : 'down'] ?? 0); @endphp
            @if ($voteAction && ! $blocked)<form method="post" action="{{ $voteAction }}" class="rr-inline-form">@csrf<input type="hidden" name="dimension" value="{{ $dimension }}"><input type="hidden" name="value" value="{{ $value }}">@endif
            <button type="{{ $voteAction && ! $blocked ? 'submit' : 'button' }}"
                class="rr-vote rr-vote--{{ $dimension }} {{ $value === 1 ? 'rr-vote--up' : 'rr-vote--down' }}"
                aria-pressed="{{ (int) ($viewerVotes[$dimension] ?? 0) === $value ? 'true' : 'false' }}"
                aria-label="{{ __('ratings-and-reviews::ui.votes.count', ['label' => __('ratings-and-reviews::ui.votes.'.$label), 'count' => $count]) }}"
                data-dimension="{{ $dimension }}" data-value="{{ $value }}" data-label="{{ __('ratings-and-reviews::ui.votes.'.$label) }}"
                @if ($isOwn || $blocked || (! $rr['clientConfig']()['authenticated'] && ! $guestVotes)) aria-disabled="true" @endif
            ><span class="rr-vote__label" aria-hidden="true">{{ __('ratings-and-reviews::ui.votes.'.$label) }}</span><span class="rr-vote__count" aria-hidden="true">{{ $count }}</span></button>
            @if ($voteAction && ! $blocked)</form>@endif
        @endforeach
    @endforeach
    <p class="rr-votes__note" role="status" data-rr-vote-note></p>
</div>
