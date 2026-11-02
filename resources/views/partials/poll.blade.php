@php
    /** @var array<string, mixed> $poll  PollService::present() */
    $showResults = $poll['is_closed'] || $poll['viewer_option_id'] !== null;
    $total = (int) $poll['total_votes'];
    // Largest remainder, so the percentages add up to 100 (as in resources/js/poll.js).
    $exact = array_map(fn (array $option): float => $total === 0 ? 0.0 : $option['votes'] * 100 / $total, $poll['options']);
    $percent = array_map('floor', $exact);
    $left = $total === 0 ? 0 : 100 - (int) array_sum($percent);
    $order = array_keys($exact);
    usort($order, fn (int $a, int $b): int => ($exact[$b] - $percent[$b]) <=> ($exact[$a] - $percent[$a]));
    foreach (array_slice($order, 0, max(0, $left)) as $index) { $percent[$index]++; }
    $pollAction = $rr['webRoute']('polls.votes.store', ['poll' => $poll['id']]);
@endphp
@if ($pollAction)<form method="post" action="{{ $pollAction }}" class="rr-inline-form" id="rr-poll-{{ $poll['id'] }}">@csrf @endif
<fieldset class="rr-poll" data-rr-poll="{{ $poll['id'] }}" aria-describedby="rr-poll-{{ $poll['id'] }}-total">
    <legend class="rr-poll__question">{{ $poll['question'] }}</legend>
    <ul class="rr-poll__options">
        @foreach ($poll['options'] as $index => $option)
            @php $chosen = $poll['viewer_option_id'] === $option['id']; @endphp
            <li class="rr-poll__option {{ $chosen ? 'is-chosen' : '' }}">
                <button type="{{ $pollAction ? 'submit' : 'button' }}" @if ($pollAction) name="option_id" value="{{ $option['id'] }}" @endif class="rr-poll__button" data-rr-poll-option="{{ $option['id'] }}" aria-pressed="{{ $chosen ? 'true' : 'false' }}" @disabled($poll['is_closed'])
                    aria-label="{{ $showResults ? __('ratings-and-reviews::ui.poll.result', ['label' => $option['label'], 'percent' => (int) $percent[$index], 'count' => $option['votes']]) : $option['label'] }}">
                    <span class="rr-poll__label" aria-hidden="true">{{ $option['label'] }}</span>
                    @if ($showResults)
                        <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" style="inline-size: {{ (int) $percent[$index] }}%"></span></span>
                        <span class="rr-poll__percent" aria-hidden="true">{{ (int) $percent[$index] }}%</span>
                    @endif
                    @if ($chosen)<span class="rr-tag">{{ __('ratings-and-reviews::ui.poll.your_vote') }}</span>@endif
                </button>
            </li>
        @endforeach
    </ul>
    <p id="rr-poll-{{ $poll['id'] }}-total" class="rr-poll__total" aria-live="polite">{{ trans_choice('ratings-and-reviews::ui.poll.votes', $total, ['count' => $total]) }}@if ($poll['is_closed']) · {{ __('ratings-and-reviews::ui.poll.closed') }}@endif</p>
</fieldset>
@if ($pollAction)</form>@endif
