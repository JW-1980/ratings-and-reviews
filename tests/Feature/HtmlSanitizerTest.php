<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\HtmlSanitizer;
use RatingsAndReviews\Support\SectionSettings;
use RatingsAndReviews\Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = $this->app->make(HtmlSanitizer::class);
    }

    /**
     * @param  list<string>  $tools
     */
    private function settings(array $tools): SectionSettings
    {
        return SectionSettings::fromArray('test', ['editor_tools' => $tools]);
    }

    private function clean(string $html, array $tools = ['bold', 'link', 'quote'], ?int $authorId = 1): string
    {
        return $this->sanitizer->clean($html, $this->settings($tools), $authorId)->html;
    }

    public function test_script_and_style_are_dropped_with_their_content(): void
    {
        $html = $this->clean('<p>Hallo<script>alert(1)</script><style>p{color:red}</style> wereld</p>');

        $this->assertSame('<p>Hallo wereld</p>', $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    public function test_event_handler_attributes_are_removed(): void
    {
        $html = $this->clean('<p onclick="steal()"><strong onmouseover="x()">vet</strong></p><img src="x" onerror="alert(1)">', ['bold', 'image']);

        $this->assertSame('<p><strong>vet</strong></p>', $html);
        $this->assertStringNotContainsString('on', strtolower(str_replace(['strong'], '', $html)));
    }

    public function test_javascript_links_lose_their_href_and_are_unwrapped(): void
    {
        $html = $this->clean('<p><a href="javascript:alert(1)">klik</a> <a href=" JaVaScRiPt:alert(1)">twee</a> <a href="data:text/html,x">drie</a></p>');

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('javascript', strtolower($html));
        $this->assertSame('<p>klik twee drie</p>', $html);
    }

    public function test_links_are_forced_to_nofollow_ugc_noopener_noreferrer(): void
    {
        $html = $this->clean('<p><a href="https://example.com/x" rel="dofollow" target="_top">bron</a></p>');

        $this->assertSame('<p><a href="https://example.com/x" rel="nofollow ugc noopener noreferrer">bron</a></p>', $html);
    }

    public function test_switched_off_tools_are_unwrapped_but_their_text_stays(): void
    {
        $html = $this->clean('<p><strong>vet</strong> <em>schuin</em> <a href="https://example.com">link</a></p>', ['italic']);

        $this->assertSame('<p>vet <em>schuin</em> link</p>', $html);
    }

    public function test_iframes_svg_and_forms_are_dropped(): void
    {
        $html = $this->clean('<p>a</p><iframe src="https://evil.test"></iframe><svg onload="alert(1)"><circle/></svg><form action="/x"><input name="a"></form><p>b</p>');

        $this->assertSame('<p>a</p><p>b</p>', $html);
    }

    public function test_foreign_images_are_removed_and_own_uploads_kept(): void
    {
        Upload::query()->create(['user_id' => 7, 'disk' => 'public', 'path' => 'rr/own.jpg']);
        Upload::query()->create(['user_id' => 8, 'disk' => 'public', 'path' => 'rr/other.jpg']);

        $html = $this->clean(
            '<p><img src="https://tracker.test/pixel.gif" alt="a"><img src="/storage/rr/own.jpg" alt="mine"><img src="/storage/rr/other.jpg" alt="theirs"><img src="data:image/png;base64,AAAA"><img src="/storage/rr/../rr/own.jpg"></p>',
            ['image'],
            authorId: 7,
        );

        $this->assertSame('<p><img src="/storage/rr/own.jpg" alt="mine" loading="lazy" decoding="async" /></p>', $html);
    }

    public function test_images_need_the_image_tool_even_when_they_are_own_uploads(): void
    {
        Upload::query()->create(['user_id' => 7, 'disk' => 'public', 'path' => 'rr/own.jpg']);

        $this->assertSame('<p>tekst</p>', $this->clean('<p>tekst<img src="/storage/rr/own.jpg"></p>', ['bold'], authorId: 7));
    }

    public function test_only_active_stickers_are_kept(): void
    {
        Sticker::query()->create(['name' => ['nl' => 'Blij', 'en' => 'Happy'], 'path' => 'stickers/happy.svg', 'is_active' => true]);
        Sticker::query()->create(['name' => ['nl' => 'Oud', 'en' => 'Old'], 'path' => 'stickers/old.svg', 'is_active' => false]);

        $html = $this->clean('<p><img src="/storage/stickers/happy.svg" alt="blij"><img src="/storage/stickers/old.svg" alt="oud"></p>', ['sticker']);

        $this->assertStringContainsString('stickers/happy.svg', $html);
        $this->assertStringNotContainsString('stickers/old.svg', $html);
    }

    public function test_the_number_of_images_is_capped(): void
    {
        config(['ratings-and-reviews.sanitizer.max_images' => 2]);
        Sticker::query()->create(['name' => ['nl' => 'Blij', 'en' => 'Happy'], 'path' => 'stickers/happy.svg', 'is_active' => true]);

        $result = $this->sanitizer->clean(str_repeat('<img src="/storage/stickers/happy.svg">', 5), $this->settings(['sticker']), 1);

        $this->assertSame(2, substr_count($result->html, '<img'));
        $this->assertSame(2, $result->imageCount);
        $this->assertSame(3, $result->droppedImages);
    }

    public function test_product_card_anchors_are_kept_only_with_a_valid_reference_and_the_tool_on(): void
    {
        $input = '<p><a data-rr-card="product:42">Oat drink</a> <a data-rr-card="javascript:alert(1)">bad</a></p>';

        $this->assertSame('<p><a data-rr-card="product:42" rel="nofollow ugc noopener noreferrer">Oat drink</a> bad</p>', $this->clean($input, ['product_card']));
        $this->assertSame('<p>Oat drink bad</p>', $this->clean($input, ['bold']));
    }

    public function test_quote_blockquotes_keep_a_numeric_reference_only(): void
    {
        $this->assertSame('<blockquote data-rr-quote="12">citaat</blockquote>', $this->clean('<blockquote data-rr-quote="12" onclick="x()">citaat</blockquote>'));
        $this->assertSame('<blockquote>citaat</blockquote>', $this->clean('<blockquote data-rr-quote="12 onmouseover=x">citaat</blockquote>'));
    }

    public function test_plain_text_input_becomes_escaped_paragraphs(): void
    {
        $result = $this->sanitizer->clean("Eerste & regel\ntweede\n\nNieuwe alinea", $this->settings([]), 1);

        $this->assertSame('<p>Eerste &amp; regel<br />tweede</p><p>Nieuwe alinea</p>', $result->html);
        $this->assertSame('Eerste & regel tweede Nieuwe alinea', $result->text);
    }

    public function test_input_over_the_byte_limit_is_detected(): void
    {
        config(['ratings-and-reviews.sanitizer.max_input_bytes' => 100]);

        $this->assertTrue($this->sanitizer->exceedsInputLimit(str_repeat('a', 101)));
        $this->assertFalse($this->sanitizer->exceedsInputLimit(str_repeat('a', 100)));
    }
}
