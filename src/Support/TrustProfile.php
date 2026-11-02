<?php

namespace RatingsAndReviews\Support;

/**
 * What one member may use in one section, from the section's trust levels and their number
 * of approved contributions. `settings` is the section settings with the locked editor tools
 * removed and, without `deep_reply`, the depth capped at 1; the sanitizer and the placement
 * use it, so a locked tool is also refused in a hand-made request.
 */
final readonly class TrustProfile
{
    public const DEEP_REPLY = 'deep_reply';

    public const POLL = 'poll';

    /**
     * @param  array<string, int>  $locked  key => approved contributions required
     */
    public function __construct(
        public SectionSettings $settings,
        public ?int $contributions,
        public array $locked,
        public bool $exempt,
    ) {}

    public function allows(string $key): bool
    {
        return ! array_key_exists($key, $this->locked);
    }

    /**
     * @return array{approved_contributions: int|null, exempt: bool, editor_tools: list<string>, max_depth: int, locked: list<array{key: string, required: int}>, can_create_poll: bool}
     */
    public function toArray(): array
    {
        $locked = [];

        foreach ($this->locked as $key => $required) {
            $locked[] = ['key' => $key, 'required' => $required];
        }

        return [
            'approved_contributions' => $this->contributions,
            'exempt' => $this->exempt,
            'editor_tools' => $this->settings->toolKeys(),
            'max_depth' => $this->settings->maxDepth,
            'locked' => $locked,
            'can_create_poll' => $this->allows(self::POLL) && (bool) config('ratings-and-reviews.polls.enabled', true),
        ];
    }
}
