<?php

namespace RatingsAndReviews\Support;

/**
 * What the package shows of an author. Provided by the host's AuthorResolver.
 */
final readonly class AuthorProfile
{
    /**
     * @param  array<string, mixed>  $extra  Host-specific data for the hovercard (badges, member since, …).
     */
    public function __construct(
        public int|string $id,
        public string $displayName,
        public ?string $avatarUrl = null,
        public ?string $profileUrl = null,
        public array $extra = [],
    ) {}

    /**
     * @return array{id: int|string, name: string, avatar_url: ?string, profile_url: ?string, extra: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName,
            'avatar_url' => $this->avatarUrl,
            'profile_url' => $this->profileUrl,
            'extra' => $this->extra,
        ];
    }
}
