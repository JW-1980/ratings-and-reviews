<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Support\AuthorProfile;
use RatingsAndReviews\Support\OfficialReply;
use RatingsAndReviews\Support\ProductCard;
use RatingsAndReviews\Support\SafeUrl;
use RatingsAndReviews\Tests\TestCase;

class SafeUrlTest extends TestCase
{
    public function test_only_http_https_and_relative_urls_pass(): void
    {
        foreach (['https://example.test/a', 'http://example.test/a', '/products/1', 'products/1', '#top'] as $url) {
            $this->assertSame($url, SafeUrl::sanitize($url));
        }

        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,1', '//evil.test/a', '  ', null] as $url) {
            $this->assertNull(SafeUrl::sanitize($url));
        }
    }

    public function test_host_supplied_dtos_never_expose_a_script_url(): void
    {
        $author = (new AuthorProfile(1, 'A', 'javascript:alert(1)', 'javascript:alert(2)'))->toArray();
        $this->assertNull($author['avatar_url']);
        $this->assertNull($author['profile_url']);

        $reply = (new OfficialReply('Brand', 'Body', authorUrl: 'javascript:alert(1)', avatarUrl: 'data:text/html,1'))->toArray();
        $this->assertNull($reply['author']['url']);
        $this->assertNull($reply['author']['avatar_url']);

        $card = (new ProductCard('product', 1, 'Title', 'javascript:alert(1)', '//evil.test/a.png'))->toArray();
        $this->assertNull($card['url']);
        $this->assertNull($card['image_url']);
    }
}
