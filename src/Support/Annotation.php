<?php

namespace RatingsAndReviews\Support;

/**
 * A small host-provided mark on a review or comment (a ContentAnnotator result): a health
 * claim warning, a verified-purchase note, a reliability badge. A stable `key` for styling
 * and tests, a `label` shown in the card header, a `tone` and an optional link.
 */
final readonly class Annotation
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const SUCCESS = 'success';

    /** @var list<string> */
    public const TONES = [self::INFO, self::WARNING, self::SUCCESS];

    public const MAX_KEY_LENGTH = 64;

    public const MAX_LABEL_LENGTH = 120;

    public function __construct(
        public string $key,
        public string $label,
        public string $tone = self::INFO,
        public ?string $url = null,
    ) {}

    /**
     * The API shape; null when the key or label is empty. An unknown tone becomes `info`, the
     * label is plain text, the URL goes through SafeUrl.
     *
     * @return array{key: string, label: string, tone: string, url: string|null}|null
     */
    public function toArray(): ?array
    {
        return self::present(['key' => $this->key, 'label' => $this->label, 'tone' => $this->tone, 'url' => $this->url]);
    }

    /**
     * Normalises one annotation-like array (also a badge from `author.extra.badges`, which
     * may be a plain string); null for anything without a label.
     *
     * @return array{key: string, label: string, tone: string, url: string|null}|null
     */
    public static function present(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = ['label' => $raw];
        }

        if (! is_array($raw)) {
            return null;
        }

        $label = mb_substr(trim(strip_tags(is_scalar($raw['label'] ?? null) ? (string) $raw['label'] : '')), 0, self::MAX_LABEL_LENGTH);
        $key = mb_substr(trim(is_scalar($raw['key'] ?? null) ? (string) $raw['key'] : ''), 0, self::MAX_KEY_LENGTH);
        $tone = is_string($raw['tone'] ?? null) && in_array($raw['tone'], self::TONES, true) ? $raw['tone'] : self::INFO;

        if ($label === '') {
            return null;
        }

        return [
            'key' => $key === '' ? 'badge' : $key,
            'label' => $label,
            'tone' => $tone,
            'url' => SafeUrl::sanitize(is_string($raw['url'] ?? null) ? $raw['url'] : null),
        ];
    }
}
