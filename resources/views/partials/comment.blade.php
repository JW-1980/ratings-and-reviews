@php
    /** @var array<string, mixed> $comment  CommentResource */
    $anchor = 'rr-comment-'.$comment['id'];
    $depth = min((int) $comment['depth'], 3);
    $name = $comment['author']['name'] ?? __('ratings-and-reviews::ui.reviews.anonymous');
    $approved = $comment['status'] === 'approved' && ($comment['visibility'] ?? 'public') === 'public';
    $bodyHasQuote = str_contains((string) $comment['body_html'], 'data-rr-quote');
@endphp
<li class="rr-thread__item" data-depth="{{ $depth }}">
    <article id="{{ $anchor }}" tabindex="-1" data-rr-comment="{{ $comment['id'] }}"
        class="rr-comment {{ $comment['is_new'] ? 'is-new' : '' }} {{ $comment['is_top'] ? 'is-top' : '' }} {{ $comment['is_removed'] ? 'is-removed' : '' }} {{ $approved ? '' : 'is-pending' }} {{ $comment['is_own'] ? 'is-own' : '' }}"
        aria-label="{{ $comment['is_removed'] ? $comment['removed_label'] : __('ratings-and-reviews::ui.discussion.by', ['name' => $name]) }}">
        @if ($comment['is_removed'])
            <p class="rr-comment__removed">{{ $comment['removed_label'] }}</p>
        @else
            <header class="rr-comment__header">
                @include('rr::partials.author', ['author' => $comment['author']])
                @if (! empty($comment['in_reply_to_author']))
                    <span class="rr-comment__reply-to">↳ {{ __('ratings-and-reviews::ui.discussion.in_reply_to', ['name' => $comment['in_reply_to_author']['name']]) }}</span>
                @endif
                <span class="rr-comment__meta">
                    <a href="#{{ $anchor }}" class="rr-comment__time">@if ($comment['created_at'])<time datetime="{{ $comment['created_at'] }}" title="{{ $rr['date']($comment['created_at'], 'datetime') }}">{{ $rr['date']($comment['created_at'], 'relative') }}</time>@endif</a>
                    @if ($comment['is_top'])<span class="rr-tag rr-tag--top">{{ __('ratings-and-reviews::ui.discussion.top_comment') }}</span>@endif
                    @if ($comment['is_new'])<span class="rr-tag rr-tag--new">{{ __('ratings-and-reviews::ui.discussion.new') }}</span>@endif
                    @if (! $approved)<span class="rr-tag rr-tag--pending">{{ __('ratings-and-reviews::ui.common.pending') }}</span>@endif
                    @if ($comment['is_edited'])
                        <button type="button" class="rr-tag rr-tag--button rr-js-only" data-rr-revisions="{{ $comment['id'] }}" aria-label="{{ __('ratings-and-reviews::ui.discussion.revisions') }}">{{ __('ratings-and-reviews::ui.common.edited') }}</button>
                        <span class="rr-tag rr-nojs-only">{{ __('ratings-and-reviews::ui.common.edited') }}</span>
                    @endif
                </span>
            </header>

            {{-- Collapsed for a low helpfulness balance: native <details>, works without JavaScript. --}}
            @if ($comment['is_collapsed'])<details class="rr-comment__collapsed-wrap"><summary class="rr-comment__collapsed">{{ __('ratings-and-reviews::ui.discussion.collapsed') }} <span class="rr-button rr-button--link">{{ __('ratings-and-reviews::ui.discussion.show_collapsed') }}</span></summary>@endif
            <div id="{{ $anchor }}-body" class="rr-comment__content">
                @if (! empty($comment['quote']) && ! $bodyHasQuote)
                    <blockquote class="rr-quote">
                        <p>{{ $comment['quote']['text'] }}</p>
                        @if ($comment['quote']['available'])
                            <footer class="rr-quote__source"><a href="#rr-comment-{{ $comment['quote']['comment_id'] }}">{{ ! empty($comment['quote']['author']) ? __('ratings-and-reviews::ui.discussion.quoting_from', ['name' => $comment['quote']['author']['name']]) : __('ratings-and-reviews::ui.discussion.quote_source') }}</a></footer>
                        @endif
                    </blockquote>
                @endif

                {{-- body_html is the output of the package HtmlSanitizer (allowlist per section). --}}
                <div class="rr-prose rr-comment__body" data-rr-quote-source="{{ $comment['id'] }}" data-rr-body>{!! $comment['body_html'] !!}</div>

                @if (! empty($comment['poll']))
                    @include('rr::partials.poll', ['poll' => $comment['poll']])
                @endif

                <footer class="rr-comment__footer">
                    @if ($approved && $settings['vote_dimensions'] !== [])
                        @include('rr::partials.votes', [
                            'target' => 'comment', 'id' => $comment['id'], 'dimensions' => $settings['vote_dimensions'],
                            'counts' => (array) $comment['counts'], 'viewerVotes' => (array) $comment['viewer_votes'],
                            'isOwn' => $comment['is_own'], 'guestVotes' => $settings['guest_votes'],
                        ])
                    @endif
                    <div class="rr-comment__actions">
                        @if ($comment['can']['reply'] && $acceptsComments && $authenticated)
                            <a href="{{ $rr['query']($prefix, ['reply' => $comment['id']]) }}#{{ $rootId }}-composer" class="rr-button rr-button--small rr-button--ghost" data-rr-reply="{{ $comment['id'] }}" data-rr-reply-name="{{ $name }}" aria-expanded="false">{{ __('ratings-and-reviews::ui.discussion.reply') }}</a>
                        @endif
                        @if ($comment['can']['edit'])
                            <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-edit="{{ $comment['id'] }}">{{ __('ratings-and-reviews::ui.common.edit') }}</button>
                        @endif
                        @if ($comment['can']['delete'])
                            <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-delete-comment="{{ $comment['id'] }}">{{ __('ratings-and-reviews::ui.common.delete') }}</button>
                        @endif
                        @if (($comment['can']['undo'] ?? false) && ! empty($comment['publish_at']))
                            <button type="button" class="rr-button rr-button--small rr-js-only" data-rr-undo="{{ $comment['id'] }}" data-publish-at="{{ $comment['publish_at'] }}">{{ __('ratings-and-reviews::ui.undo.action') }}</button>
                        @endif
                        @if (! $comment['is_own'] && $approved && $authenticated)
                            <button type="button" class="rr-button rr-button--small rr-button--ghost rr-js-only" data-rr-report="rr_comment" data-id="{{ $comment['id'] }}">{{ __('ratings-and-reviews::ui.reviews.report') }}</button>
                        @endif
                    </div>
                </footer>
            </div>
            @if ($comment['is_collapsed'])</details>@endif
        @endif
    </article>
    <div data-rr-reply-slot="{{ $comment['id'] }}"></div>

    @if (! empty($comment['replies']))
        <ol class="rr-thread rr-thread--replies">
            @foreach ($comment['replies'] as $reply)
                @include('rr::partials.comment', ['comment' => $reply])
            @endforeach
        </ol>
    @endif
    @if (! empty($truncated[$comment['id']] ?? null))
        <p class="rr-help rr-thread__truncated">{{ __('ratings-and-reviews::ui.discussion.more_replies') }} ({{ $truncated[$comment['id']] }})</p>
    @endif
</li>
