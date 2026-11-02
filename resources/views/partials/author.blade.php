@php $name = $author['name'] ?? __('ratings-and-reviews::ui.reviews.anonymous'); @endphp
<span class="rr-author">
    @if (! empty($author['avatar_url']))
        <img src="{{ $author['avatar_url'] }}" alt="" class="rr-avatar" loading="lazy" width="32" height="32">
    @else
        <span class="rr-avatar rr-avatar--initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
    @endif
    @if ($author !== null && ! empty($author['profile_url']))
        <a href="{{ $author['profile_url'] }}" class="rr-author__name" data-rr-author="{{ $author['id'] }}" aria-expanded="false">{{ $name }}</a>
    @elseif ($author !== null)
        <button type="button" class="rr-author__name" data-rr-author="{{ $author['id'] }}" aria-expanded="false">{{ $name }}</button>
    @else
        <span class="rr-author__name">{{ $name }}</span>
    @endif
</span>
