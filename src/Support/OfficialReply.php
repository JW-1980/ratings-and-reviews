<?php

namespace RatingsAndReviews\Support;

use DateTimeInterface;

/**
 * An official reply to a review, from the host's OfficialReplyResolver. The body is plain
 * text: the package escapes it and turns blank lines into paragraphs and line breaks into
 * <br> (`body_html`), so a host never has to sanitise reply HTML for it.
 */
final readonly class OfficialReply
{
    public function __construct(
        public string $authorName,
        public string $body,
        public ?DateTimeInterface $repliedAt = null,
        public ?string $label = null,
        public ?string $authorUrl = null,
        public ?string $avatarUrl = null,
    ) {}

    /**
     * @return array{author: array{name: string, url: string|null, avatar_url: string|null}, label: string|null, body: string, body_html: string, replied_at: string|null}
     */
    public function toArray(): array
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $this->body));
        $paragraphs = array_filter(preg_split('/\n{2,}/', $body) ?: [], fn (string $paragraph): bool => trim($paragraph) !== '');
        $html = implode('', array_map(
            fn (string $paragraph): string => '<p>'.nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false).'</p>',
            $paragraphs,
        ));

        return [
            'author' => ['name' => $this->authorName, 'url' => $this->authorUrl, 'avatar_url' => $this->avatarUrl],
            'label' => $this->label,
            'body' => $body,
            'body_html' => $html,
            'replied_at' => $this->repliedAt?->format(DATE_ATOM),
        ];
    }
}
