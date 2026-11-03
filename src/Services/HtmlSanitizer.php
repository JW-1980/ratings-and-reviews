<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Support\EditorTool;
use RatingsAndReviews\Support\SanitizedHtml;
use RatingsAndReviews\Support\SectionSettings;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The only place user HTML becomes trusted HTML.
 *
 * The allowlist is built per section from the enabled editor tools: the markup of a
 * switched-off tool is unwrapped (its text stays), scripts and embeds are dropped with
 * their content. Links get `rel="nofollow ugc noopener noreferrer"`; an image survives only
 * when it is one of the author's own uploads or an active sticker, at most
 * `sanitizer.max_images` of them. `<a data-rr-card="type:id">` (product card) and
 * `<blockquote data-rr-quote="id">` (quote reply) are kept when their tools are on.
 */
class HtmlSanitizer
{
    public const LINK_REL = 'nofollow ugc noopener noreferrer';

    /** Containers that are unwrapped so pasted text survives without their markup. */
    private const UNWRAPPED = [
        'div', 'span', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'font', 'center', 'small', 'big',
        'sup', 'sub', 'mark', 'abbr', 'cite', 'q', 'h1', 'h2', 'h5', 'h6', 'figure', 'figcaption', 'label', 'table',
        'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'dl', 'dt', 'dd', 'address', 'time', 'kbd', 'samp', 'var',
    ];

    /** Dropped with everything inside them. */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template', 'svg',
        'math', 'form', 'input', 'button', 'select', 'option', 'textarea', 'head', 'title', 'meta', 'link', 'base',
        'audio', 'video', 'source', 'track', 'canvas', 'picture', 'map', 'area', 'dialog', 'portal',
    ];

    public function __construct(private readonly MediaSources $media) {}

    public function maxInputBytes(): int
    {
        return max(1, (int) config('ratings-and-reviews.sanitizer.max_input_bytes', 50000));
    }

    public function exceedsInputLimit(string $input): bool
    {
        return strlen($input) > $this->maxInputBytes();
    }

    /**
     * Cleans editor input for one section. `$authorId` is whose uploads may be shown;
     * `$target` is the review or comment being edited, whose attached uploads stay allowed
     * (for new content: null, only unattached uploads).
     */
    public function clean(string $input, SectionSettings $settings, ?int $authorId, ?Model $target = null): SanitizedHtml
    {
        $input = $this->fromEditorInput($input);

        if (trim($input) === '') {
            return new SanitizedHtml('', '');
        }

        $uploads = $settings->allowsTool(EditorTool::Image);
        $stickers = $settings->allowsTool(EditorTool::Sticker);
        $allowedSources = ($uploads || $stickers)
            ? $this->media->allowed($this->imageSources($input), $authorId, $uploads, $stickers, $target)
            : [];

        $sanitizer = new SymfonyHtmlSanitizer($this->config($settings, $allowedSources));
        $html = trim($sanitizer->sanitize($input));

        // An image whose src was refused is left without one: remove it.
        $html = (string) preg_replace('/<img\b(?![^>]*\ssrc=)[^>]*>/i', '', $html);

        $limit = max(0, (int) config('ratings-and-reviews.sanitizer.max_images', 4));
        $kept = 0;
        $dropped = 0;
        $uploadPaths = [];
        $html = (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $match) use (&$kept, &$dropped, &$uploadPaths, $limit, $allowedSources): string {
            if ($kept >= $limit) {
                $dropped++;

                return '';
            }

            $kept++;

            if (preg_match('/\ssrc="([^"]*)"/i', $match[0], $src) === 1) {
                $path = $allowedSources[html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')] ?? null;

                if ($path !== null) {
                    $uploadPaths[] = $path;
                }
            }

            return $match[0];
        }, $html);

        // An anchor that kept neither a link nor a card reference is just text.
        $html = (string) preg_replace('/<a\b(?![^>]*\s(?:href|data-rr-card)=)[^>]*>(.*?)<\/a>/is', '$1', $html);
        $html = trim((string) preg_replace('/^(?:\s|<p>\s*<\/p>|<br\s*\/?>)+|(?:\s|<p>\s*<\/p>|<br\s*\/?>)+$/i', '', $html));

        return new SanitizedHtml(
            html: $html,
            text: $this->plainText($html),
            imageCount: $kept,
            droppedImages: $dropped,
            uploadPaths: array_values(array_unique($uploadPaths)),
        );
    }

    /**
     * What a user typed, as editor HTML. The rich editor sends markup; a plain textarea
     * (no JavaScript) sends text, whose blank lines become paragraphs and single line
     * breaks `<br>`. Text without any tag is escaped.
     */
    public function fromEditorInput(string $input): string
    {
        if (preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?\/?>/i', $input) === 1) {
            return $input;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $input));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [$text];

        return implode('', array_map(
            fn (string $paragraph): string => '<p>'.implode('<br>', array_map(
                fn (string $line): string => htmlspecialchars(trim($line), ENT_QUOTES | ENT_HTML5, 'UTF-8', false),
                explode("\n", trim($paragraph)),
            )).'</p>',
            $paragraphs,
        ));
    }

    /** Plain text of trusted HTML, whitespace collapsed (used for length checks, spam checks and search). */
    public function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $spaced = preg_replace('/<(br|\/p|\/li|\/blockquote|\/h3|\/h4|\/pre)\b[^>]*>/i', ' $0', $html) ?? $html;
        $spaced = preg_replace('/<img\b[^>]*\balt="([^"]*)"[^>]*>/i', ' $1 ', $spaced) ?? $spaced;
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @return list<string>
     */
    private function imageSources(string $input): array
    {
        preg_match_all('/<img\b[^>]*?\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $input, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match): string => html_entity_decode(trim($match[1] !== '' ? $match[1] : ($match[2] ?? '').($match[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches,
        );
    }

    /**
     * @param  array<string, string|null>  $allowedSources
     */
    private function config(SectionSettings $settings, array $allowedSources): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->withMaxInputLength($this->maxInputBytes());

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        foreach (EditorTool::cases() as $tool) {
            foreach ($tool->elements() as $element) {
                if ($element === 'img' || $element === 'a' || $element === 'blockquote') {
                    continue;
                }

                $config = $settings->allowsTool($tool) ? $config->allowElement($element) : $config->blockElement($element);
            }
        }

        $lists = $settings->allowsTool(EditorTool::BulletList) || $settings->allowsTool(EditorTool::OrderedList);
        $config = $lists ? $config->allowElement('li') : $config->blockElement('li');

        $config = $settings->allowsTool(EditorTool::Quote)
            ? $config->allowElement('blockquote', ['data-rr-quote'])
            : $config->blockElement('blockquote');

        $links = $settings->allowsTool(EditorTool::Link);
        $cards = $settings->allowsTool(EditorTool::ProductCard);

        if ($links || $cards) {
            $attributes = [...($links ? ['href', 'title'] : []), ...($cards ? ['data-rr-card'] : [])];
            $config = $config->allowElement('a', $attributes)
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks()
                ->forceAttribute('a', 'rel', self::LINK_REL);
        } else {
            $config = $config->blockElement('a');
        }

        $uploads = $settings->allowsTool(EditorTool::Image);
        $stickers = $settings->allowsTool(EditorTool::Sticker);

        if ($uploads || $stickers) {
            $config = $config->allowElement('img', ['src', 'alt'])
                ->allowMediaSchemes(['https', 'http'])
                ->allowRelativeMedias()
                ->forceAttribute('img', 'loading', 'lazy')
                ->forceAttribute('img', 'decoding', 'async');
        } else {
            $config = $config->dropElement('img');
        }

        return $config->withAttributeSanitizer(new PackageAttributeSanitizer($allowedSources));
    }
}
