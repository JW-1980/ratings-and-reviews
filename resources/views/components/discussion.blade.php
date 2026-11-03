{{--
    <x-rr::discussion :subject="$product" :action="route('comments.store', $product)" />
    Threaded discussion: sort (incl. controversial), TL;DR digest, search, composer with the
    section's editor tools, polls, votes, quotes, "new since your last visit", slow mode,
    locks, deleted placeholders, collapsed low scores, revisions and reports.
    Reading, sorting, paging, searching, replying (?rr_creply=ID) and loading the rest of a long
    thread (?rr_cthread=ID) work without JavaScript. Posting, editing (?rr_cedit=ID), deleting,
    undo, votes, poll votes and reports work without JavaScript through the package form routes
    (`web_routes.enabled`); `action` overrides the posting URL with a host route.
    The pinned comment leads the list (moderators and the discussion owner pin and unpin);
    attribution badges, a "post as" select (AttributionResolver), blocked members' notices and
    a notice for members the subject's participation rules keep out. Host features on every
    comment and reply come from Support\CardExtensions (comment.meta, comment.footer), with their
    preloads run once for the page.
--}}
@props(['subject', 'action' => null, 'prefix' => 'rr_c', 'loginUrl' => null, 'searchable' => true])
@php
    $rr = require view()->getFinder()->find('rr::support.helpers');
    $type = $subject->getMorphClass();
    $key = $subject->getKey();
    $response = $rr['api']('comments.index', ['type' => $type, 'id' => $key], [
        'sort' => request()->query($prefix.'sort'),
        'page' => request()->query($prefix.'page'),
    ]);
    $rootId = 'rr-discussion-'.\Illuminate\Support\Str::slug($type.'-'.$key);
    $authenticated = $rr['clientConfig']()['authenticated'];
    $action ??= $rr['webRoute']('comments.store', ['type' => $type, 'id' => $key]);
    $editId = filter_var(request()->query($prefix.'edit'), FILTER_VALIDATE_INT) ?: null;
    $threadId = filter_var(request()->query($prefix.'thread'), FILTER_VALIDATE_INT) ?: null;
    $threadPages = max(1, min(20, (int) (filter_var(request()->query($prefix.'thread_pages'), FILTER_VALIDATE_INT) ?: 1)));
@endphp
@if ($response !== null && isset($response['data']))
    @php
        $items = $response['data'];
        $meta = $response['meta'];
        $settings = $meta['settings'];
        $trust = $meta['trust'] ?? [];
        $locked = collect($trust['locked'] ?? [])->pluck('required', 'key')->all();
        $discussionState = $meta['discussion'] ?? [];
        $acceptsComments = (bool) $meta['accepts_comments'];
        $truncated = (array) ($meta['truncated_threads'] ?? []);
        // "Show more replies" without JavaScript: the rest of one cut thread, $threadPages pages of it.
        if ($threadId !== null && isset($truncated[$threadId])) {
            foreach ($items as $index => $root) {
                if ($root['id'] !== $threadId) { continue; }
                $fetched = [];
                $cursor = null;
                $more = false;
                // Continue after the last reply the tree already shows (deepest, then newest).
                $shown = $rr['flattenReplies']($root['replies'] ?? []);
                usort($shown, fn (array $a, array $b): int => [$a['depth'], $a['created_at'] ?? '', $a['id']] <=> [$b['depth'], $b['created_at'] ?? '', $b['id']]);
                $lastShown = $shown === [] ? null : end($shown)['id'];
                for ($pageNumber = 0; $pageNumber < $threadPages; $pageNumber++) {
                    $chunk = $rr['api']('comments.replies', ['comment' => $threadId], $cursor === null ? ['after' => $lastShown] : ['cursor' => $cursor]);
                    if ($chunk === null || ! isset($chunk['data'])) { break; }
                    $fetched = [...$fetched, ...$chunk['data']];
                    $cursor = $chunk['meta']['next_cursor'] ?? null;
                    $more = (bool) ($chunk['meta']['has_more'] ?? false);
                    if (! $more) { break; }
                }
                $items[$index]['replies'] = $rr['replyTree']($threadId, [...$rr['flattenReplies']($root['replies'] ?? []), ...$fetched]);
                if ($more) { $truncated[$threadId] = (int) ($chunk['meta']['total'] ?? $truncated[$threadId]); } else { unset($truncated[$threadId]); }
            }
        }
        $newSince = $meta['new_since'] ?? [];
        $wait = (int) ($discussionState['seconds_until_next_post'] ?? 0);
        $slow = (int) ($discussionState['slow_mode_seconds'] ?? 0);
        $digest = $rr['api']('digest.show', ['type' => $type, 'id' => $key])['data'] ?? null;
        $q = trim((string) request()->query($prefix.'q', ''));
        $search = $searchable && $q !== '' ? $rr['api']('search.index', ['type' => $type, 'id' => $key], ['q' => $q]) : null;
        $replyTo = filter_var(request()->query($prefix.'reply'), FILTER_VALIDATE_INT) ?: null;
        $find = function (array $list, int $id) use (&$find): ?array {
            foreach ($list as $item) {
                if ($item['id'] === $id) { return $item; }
                if ($found = $find($item['replies'] ?? [], $id)) { return $found; }
            }
            return null;
        };
        $replyTarget = $replyTo === null ? null : $find($items, $replyTo);
        $interval = match (true) {
            $slow >= 3600 && $slow % 3600 === 0 => trans_choice('ratings-and-reviews::ui.interval.hours', intdiv($slow, 3600), ['count' => intdiv($slow, 3600)]),
            $slow >= 60 && $slow % 60 === 0 => trans_choice('ratings-and-reviews::ui.interval.minutes', intdiv($slow, 60), ['count' => intdiv($slow, 60)]),
            default => trans_choice('ratings-and-reviews::ui.interval.seconds', $slow, ['count' => $slow]),
        };
        $agree = $digest['agreement'] ?? null;
        $agreePercent = $agree && $agree['agree_share'] !== null ? (int) round($agree['agree_share'] * 100) : null;
        // One renderer for every comment of the page, replies included (removed placeholders show no extensions).
        $cardExtensions = \RatingsAndReviews\Support\CardExtensions::forList('comment', array_values(array_filter($rr['flattenReplies']($items), fn (array $comment): bool => ! ($comment['is_removed'] ?? false))), $rr['viewer']());
    @endphp
    @include('rr::partials.client')
    <section {{ $attributes->merge(['class' => 'rr rr-nojs rr-discussion']) }} id="{{ $rootId }}" data-rr="discussion" data-rr-subject-type="{{ $type }}" data-rr-subject-id="{{ $key }}"
        data-max-quote="{{ $settings['max_quote_length'] ?? 500 }}" data-slow-mode="{{ $slow }}" data-accepts="{{ $acceptsComments && $authenticated ? '1' : '0' }}" aria-labelledby="{{ $rootId }}-title">
        <header class="rr-section-header">
            <h2 id="{{ $rootId }}-title" class="rr-section-header__title">
                {{ __('ratings-and-reviews::ui.discussion.title') }}
                <span class="rr-section-header__count">{{ trans_choice('ratings-and-reviews::ui.discussion.count', $meta['total'], ['count' => $meta['total']]) }}</span>
            </h2>
            <form method="get" action="{{ request()->url() }}#{{ $rootId }}" class="rr-toolbar" data-rr-nav data-rr-autosubmit>
                @foreach (request()->except([$prefix.'sort', $prefix.'page', $prefix.'reply']) as $name => $value)
                    @if (is_string($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                @endforeach
                <label class="rr-inline-field">
                    <span class="rr-label">{{ __('ratings-and-reviews::ui.discussion.sort') }}</span>
                    <select name="{{ $prefix }}sort" class="rr-select">
                        @foreach ($meta['sorts'] as $sort)
                            <option value="{{ $sort['value'] }}" @selected($sort['value'] === $meta['sort'])>{{ $sort['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="rr-button rr-button--small rr-nojs-only">{{ __('ratings-and-reviews::ui.reviews.apply') }}</button>
            </form>
        </header>

        <p class="rr-visually-hidden" aria-live="polite" role="status" data-rr-live></p>

        @if ($searchable)
            <div class="rr-search">
                <form method="get" action="{{ request()->url() }}#{{ $rootId }}" role="search" class="rr-search__form" aria-label="{{ __('ratings-and-reviews::ui.search.label') }}" data-rr-nav>
                    @foreach (request()->except([$prefix.'q']) as $name => $value)
                        @if (is_string($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                    @endforeach
                    <label for="{{ $rootId }}-q" class="rr-visually-hidden">{{ __('ratings-and-reviews::ui.search.label') }}</label>
                    <input id="{{ $rootId }}-q" type="search" name="{{ $prefix }}q" value="{{ $q }}" class="rr-input" placeholder="{{ __('ratings-and-reviews::ui.search.placeholder') }}" minlength="{{ (int) config('ratings-and-reviews.search.min_term_length', 3) }}">
                    <button type="submit" class="rr-button">{{ __('ratings-and-reviews::ui.search.submit') }}</button>
                    @if ($q !== '')
                        <a href="{{ $rr['query']($prefix, ['q' => null]) }}#{{ $rootId }}" class="rr-button rr-button--ghost" data-rr-nav>{{ __('ratings-and-reviews::ui.search.clear') }}</a>
                    @endif
                </form>
                @if ($q !== '')
                    <div class="rr-search__results">
                        @if (isset($search['errors']))
                            <p class="rr-field-error" role="alert">{{ collect($search['errors'])->flatten()->first() }}</p>
                        @elseif ($search !== null)
                            @php $hits = [...array_map(fn ($hit) => $hit + ['type' => 'review'], $search['data']['reviews'] ?? []), ...array_map(fn ($hit) => $hit + ['type' => 'comment'], $search['data']['comments'] ?? [])]; @endphp
                            @php $totalHits = (int) ($search['meta']['total_reviews'] ?? 0) + (int) ($search['meta']['total_comments'] ?? 0); @endphp
                            <p class="rr-search__summary" role="status">{{ trans_choice('ratings-and-reviews::ui.search.results', $totalHits, ['count' => $totalHits, 'query' => $q]) }}</p>
                            <ol class="rr-search__list">
                                @foreach ($hits as $hit)
                                    <li class="rr-search__hit">
                                        <span class="rr-tag">{{ __('ratings-and-reviews::ui.search.'.$hit['type']) }}</span>
                                        {{-- Snippets are the server's escaped plain text with <mark> added by SearchHighlighter. --}}
                                        @if (! empty($hit['title_html']))<p class="rr-search__title">{!! $hit['title_html'] !!}</p>@endif
                                        <p class="rr-search__snippet">{!! $hit['snippet_html'] !!}</p>
                                        <a href="#{{ $hit['type'] === 'review' ? 'rr-review-'.$hit['id'] : 'rr-comment-'.$hit['id'] }}" class="rr-button rr-button--link">{{ __('ratings-and-reviews::ui.search.open') }}</a>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if ($digest !== null && ($digest['available'] ?? false))
            <aside class="rr-digest" aria-labelledby="{{ $rootId }}-digest">
                <h3 id="{{ $rootId }}-digest" class="rr-digest__title">{{ __('ratings-and-reviews::ui.digest.title') }}</h3>
                @if ($agreePercent !== null)
                    @php $ratio = __('ratings-and-reviews::ui.digest.ratio', ['agree' => $agreePercent, 'disagree' => 100 - $agreePercent]); @endphp
                    <div class="rr-digest__ratio">
                        <span class="rr-split" role="img" aria-label="{{ __('ratings-and-reviews::ui.digest.ratio_label', ['total' => $agree['agree'] + $agree['disagree']]) }}: {{ $ratio }}"><span class="rr-split__agree" style="inline-size: {{ $agreePercent }}%"></span></span>
                        <span class="rr-digest__ratio-text" aria-hidden="true">{{ $ratio }}</span>
                    </div>
                @endif
                <div class="rr-digest__groups">
                    @foreach (['agreeing' => $digest['most_agreed'] ?? [], 'disagreeing' => $digest['most_disagreed'] ?? []] as $stance => $entries)
                        @if ($entries !== [])
                            <section class="rr-digest__group" data-stance="{{ $stance }}">
                                <h4 class="rr-digest__group-title">{{ __('ratings-and-reviews::ui.digest.'.$stance) }}</h4>
                                <ul>
                                    @foreach ($entries as $entry)
                                        <li><q>{{ $entry['excerpt'] }}</q> <a href="#rr-comment-{{ $entry['id'] }}" class="rr-digest__jump">{{ __('ratings-and-reviews::ui.digest.jump') }}</a></li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    @endforeach
                    @if (! empty($digest['most_helpful']))
                        <section class="rr-digest__group" data-stance="helpful">
                            <h4 class="rr-digest__group-title">{{ __('ratings-and-reviews::ui.digest.most_helpful') }}</h4>
                            <p><q>{{ $digest['most_helpful']['excerpt'] }}</q> <a href="#rr-comment-{{ $digest['most_helpful']['id'] }}" class="rr-digest__jump">{{ __('ratings-and-reviews::ui.digest.jump') }}</a></p>
                        </section>
                    @endif
                </div>
                <p class="rr-help">{{ __('ratings-and-reviews::ui.digest.based_on', ['count' => $digest['total_comments']]) }}</p>
            </aside>
        @endif

        @if (($newSince['count'] ?? 0) > 0 && ! empty($newSince['first_new_id']))
            <div class="rr-new-since" role="region" aria-label="{{ __('ratings-and-reviews::ui.discussion.new_since') }}">
                <span class="rr-new-since__label">{{ __('ratings-and-reviews::ui.discussion.new_since') }}</span>
                <a href="#rr-comment-{{ $newSince['first_new_id'] }}" class="rr-button rr-button--small" data-rr-jump="{{ $newSince['first_new_id'] }}">{{ trans_choice('ratings-and-reviews::ui.discussion.jump_new', $newSince['count'], ['count' => $newSince['count']]) }}</a>
            </div>
        @endif

        @if ($discussionState['locked'] ?? false)
            <p class="rr-notice">{{ __('ratings-and-reviews::ui.discussion.locked') }}</p>
        @elseif (($meta['can_participate'] ?? null) === false)
            <p class="rr-notice" data-rr-participation-denied>{{ __('ratings-and-reviews::ui.discussion.participation_denied') }}</p>
        @elseif (! $acceptsComments)
            <p class="rr-notice">{{ __('ratings-and-reviews::ui.discussion.closed') }}</p>
        @endif
        @if ($slow > 0)
            <p class="rr-notice rr-notice--slow">{{ __('ratings-and-reviews::ui.discussion.slow_mode', ['interval' => $interval]) }}</p>
        @endif
        @php $flashOwned = preg_match('/^(comment|edit|delete|undo|poll|pin|vote:comment|report:rr_comment)/', (string) session('rr_form')) === 1; @endphp
        @if (session('rr_status') && (session('rr_form') === null || $flashOwned))
            <p class="rr-alert rr-alert--success" role="status">{{ session('rr_status') }}</p>
        @endif
        @if (session('rr_error') && $flashOwned)
            <p class="rr-alert rr-alert--error" role="alert">{{ session('rr_error') }}</p>
        @endif

        @if ($acceptsComments)
            @if ($authenticated)
                @include('rr::partials.composer', [
                    'parentId' => $replyTarget['id'] ?? null,
                    'replyName' => $replyTarget['author']['name'] ?? null,
                    'cancelUrl' => $rr['query']($prefix, ['reply' => null]).'#'.$rootId,
                ])
                <template data-rr-reply-template>
                    @include('rr::partials.composer', ['parentId' => '__PARENT__', 'replyName' => '__NAME__', 'formId' => $rootId.'-reply', 'cancelUrl' => '#', 'wait' => $wait])
                </template>
            @else
                <p class="rr-notice">@if ($loginUrl)<a href="{{ $loginUrl }}">{{ __('ratings-and-reviews::ui.discussion.login_to_reply') }}</a>@else{{ __('ratings-and-reviews::ui.discussion.login_to_reply') }}@endif</p>
            @endif
        @endif

        @if ($items === [])
            <p class="rr-empty">{{ __('ratings-and-reviews::ui.discussion.empty') }}</p>
        @endif

        <div class="rr-discussion__thread" data-rr-thread>
            <ol class="rr-thread" aria-label="{{ __('ratings-and-reviews::ui.discussion.thread') }}" data-rr-thread-list>
                @foreach ($items as $comment)
                    @include('rr::partials.comment', ['comment' => $comment])
                @endforeach
            </ol>
            <button type="button" class="rr-button rr-button--small rr-button--primary rr-quote-offer" data-rr-quote-offer hidden>{{ __('ratings-and-reviews::ui.discussion.quote_reply') }}</button>
        </div>

        @if ($meta['has_more'])
            <div class="rr-more">
                <a href="{{ $rr['query']($prefix, ['page' => $meta['page'] + 1]) }}#{{ $rootId }}" class="rr-button" data-rr-more>{{ __('ratings-and-reviews::ui.discussion.load_more') }}</a>
            </div>
        @endif

        @include('rr::partials.report-dialog', ['id' => $rootId.'-report'])
        <dialog class="rr rr-dialog" id="{{ $rootId }}-revisions" aria-labelledby="{{ $rootId }}-revisions-title" data-rr-revisions-dialog>
            <div class="rr-dialog__panel">
                <header class="rr-dialog__header">
                    <h2 id="{{ $rootId }}-revisions-title" class="rr-dialog__title">{{ __('ratings-and-reviews::ui.discussion.revisions_title') }}</h2>
                    <button type="button" class="rr-icon-button rr-dialog__close" data-rr-dialog-close aria-label="{{ __('ratings-and-reviews::ui.common.close') }}">×</button>
                </header>
                <div data-rr-revisions-body></div>
            </div>
        </dialog>
    </section>
@endif
