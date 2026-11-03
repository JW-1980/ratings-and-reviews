<?php

namespace RatingsAndReviews\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Validates the attributes the package adds on top of plain markup: an `img` keeps its
 * `src` only when it is in the allowed set, `data-rr-card` must be `type:id` and
 * `data-rr-quote` must be a comment id.
 *
 * @internal
 */
final class PackageAttributeSanitizer implements AttributeSanitizerInterface
{
    public const CARD_PATTERN = '/^[a-z][a-z0-9_]{0,63}:[A-Za-z0-9\-]{1,64}$/';

    public const QUOTE_PATTERN = '/^[1-9][0-9]{0,19}$/';

    /**
     * @param  array<string, string|null>  $allowedSources
     */
    public function __construct(private readonly array $allowedSources) {}

    public function getSupportedElements(): ?array
    {
        return ['img', 'a', 'blockquote'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src', 'data-rr-card', 'data-rr-quote'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return match (true) {
            $element === 'img' && $attribute === 'src' => array_key_exists($value, $this->allowedSources) ? $value : null,
            $element === 'a' && $attribute === 'data-rr-card' => preg_match(self::CARD_PATTERN, $value) === 1 ? $value : null,
            $element === 'blockquote' && $attribute === 'data-rr-quote' => preg_match(self::QUOTE_PATTERN, $value) === 1 ? $value : null,
            default => $value,
        };
    }
}
