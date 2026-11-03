<?php

namespace RatingsAndReviews\Support;

/**
 * What the package shows of an author. Provided by the host's AuthorResolver.
 */
final readonly class AuthorProfile
{
    /**
     * @param  array<string, mixed>  $extra  Host-specific data for the hovercard (badges, member since, …).
     *                                       `badges` (strings, or `{label, tone?, url?, icon?, key?}`) are also shown
     *                                       next to the author on the card (reliability, flair).
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
            'avatar_url' => SafeUrl::sanitize($this->avatarUrl),
            'profile_url' => SafeUrl::sanitize($this->profileUrl),
            'extra' => $this->extra(),
        ];
    }

    /**
     * `extra` with its `badges` normalised (plain-text label, tone, safe URL); empty ones are
     * dropped. The rest is passed on as the host gave it.
     *
     * @return array<string, mixed>
     */
    private function extra(): array
    {
        if (! array_key_exists('badges', $this->extra)) {
            return $this->extra;
        }

        $badges = [];

        foreach (is_iterable($this->extra['badges']) ? $this->extra['badges'] : [] as $badge) {
            $row = Annotation::present($badge);

            if ($row !== null) {
                $badges[] = $row + ['icon' => is_array($badge) && is_string($badge['icon'] ?? null) ? $badge['icon'] : null];
            }
        }

        return ['badges' => $badges] + $this->extra;
    }
}
