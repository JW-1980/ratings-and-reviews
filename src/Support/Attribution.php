<?php

namespace RatingsAndReviews\Support;

/**
 * One identity a member may post as (AttributionResolver::allowedFor()): a stable `key` the
 * client sends, the `label` shown as a badge ("Brand X", "Official answer") and an optional
 * profile URL. Stored with the contribution as a snapshot.
 */
final readonly class Attribution
{
    public const MAX_KEY_LENGTH = 64;

    public const MAX_LABEL_LENGTH = 120;

    public function __construct(
        public string $key,
        public string $label,
        public ?string $url = null,
    ) {}

    /**
     * The stored snapshot, or null when the key or label is empty.
     *
     * @return array{key: string, label: string, url: string|null}|null
     */
    public function toArray(): ?array
    {
        $key = mb_substr(trim($this->key), 0, self::MAX_KEY_LENGTH);
        $label = mb_substr(trim(strip_tags($this->label)), 0, self::MAX_LABEL_LENGTH);

        if ($key === '' || $label === '') {
            return null;
        }

        return ['key' => $key, 'label' => $label, 'url' => SafeUrl::sanitize($this->url)];
    }

    /**
     * A stored snapshot as the API shows it (null for anything malformed).
     *
     * @return array{key: string, label: string, url: string|null}|null
     */
    public static function present(mixed $stored): ?array
    {
        if (! is_array($stored) || ! is_string($stored['key'] ?? null) || ! is_string($stored['label'] ?? null)) {
            return null;
        }

        return (new self($stored['key'], $stored['label'], is_string($stored['url'] ?? null) ? $stored['url'] : null))->toArray();
    }
}
