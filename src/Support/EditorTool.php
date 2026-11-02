<?php

namespace RatingsAndReviews\Support;

/**
 * One button of the rich-text editor, switchable per section. Each tool owns the HTML
 * elements it produces; the HtmlSanitizer keeps an element only while its tool is on, so
 * switching a tool off also stops that markup arriving through a hand-made request.
 */
enum EditorTool: string
{
    case Bold = 'bold';
    case Italic = 'italic';
    case Underline = 'underline';
    case Strike = 'strike';
    case BulletList = 'bullet_list';
    case OrderedList = 'ordered_list';
    case Quote = 'quote';
    case Heading = 'heading';
    case Code = 'code';
    case Link = 'link';
    case Emoji = 'emoji';
    case Image = 'image';
    case Sticker = 'sticker';
    case ProductCard = 'product_card';

    public function label(): string
    {
        return (string) __('ratings-and-reviews::enums.editor_tool.'.$this->value);
    }

    /**
     * The elements this tool may leave in stored HTML. `a` and `img` are configured
     * separately by the sanitizer because their attributes depend on several tools.
     *
     * @return list<string>
     */
    public function elements(): array
    {
        return match ($this) {
            self::Bold => ['strong', 'b'],
            self::Italic => ['em', 'i'],
            self::Underline => ['u'],
            self::Strike => ['s', 'del'],
            self::BulletList => ['ul'],
            self::OrderedList => ['ol'],
            self::Quote => ['blockquote'],
            self::Heading => ['h3', 'h4'],
            self::Code => ['code', 'pre'],
            self::Link, self::ProductCard => ['a'],
            self::Emoji => [],
            self::Image, self::Sticker => ['img'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $tool): string => $tool->value, self::cases());
    }
}
