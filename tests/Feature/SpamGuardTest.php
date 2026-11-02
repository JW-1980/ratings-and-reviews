<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\Request;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Services\SpamGuard;
use RatingsAndReviews\Tests\TestCase;

class SpamGuardTest extends TestCase
{
    private SpamGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = $this->app->make(SpamGuard::class);
    }

    public function test_honeypot_and_time_trap_refuse(): void
    {
        $this->assertSame(['honeypot'], $this->guard->inspect(Request::create('/', 'POST', ['website' => 'http://spam.test']), 'Normale tekst', 1)->reasons);
        $this->assertTrue($this->guard->inspect(Request::create('/', 'POST', ['website' => 'x']), 'Normale tekst', 1)->refuse);

        $tooFast = $this->guard->inspect(Request::create('/', 'POST', ['form_started_at' => time()]), 'Normale tekst', 1);
        $this->assertTrue($tooFast->refuse);
        $this->assertSame(['submitted_too_fast'], $tooFast->reasons);

        $this->assertFalse($this->guard->inspect(Request::create('/', 'POST', ['form_started_at' => time() - 30]), 'Normale tekst', 1)->refuse);
    }

    public function test_soft_signals_hold_instead_of_refusing(): void
    {
        $links = $this->guard->inspect(null, 'zie https://a.test https://b.test https://c.test https://d.test', 1);
        $this->assertFalse($links->refuse);
        $this->assertTrue($links->hold);
        $this->assertContains('too_many_links', $links->reasons);

        $this->assertContains('all_caps', $this->guard->inspect(null, 'DIT PRODUCT IS ECHT HELEMAAL VERSCHRIKKELIJK SLECHT EN DUUR', 1)->reasons);
        $this->assertContains('repeated_characters', $this->guard->inspect(null, 'Lekkerrrrrrrrrrrrrrrrrr', 1)->reasons);

        $clean = $this->guard->inspect(null, 'Prima product, smaakt goed en is niet te duur.', 1);
        $this->assertFalse($clean->refuse);
        $this->assertFalse($clean->hold);
    }

    public function test_blocked_phrases_come_from_the_config(): void
    {
        config(['ratings-and-reviews.spam.blocked_phrases' => ['Gratis iPhone']]);

        $this->assertTrue($this->guard->inspect(null, 'Win een GRATIS iphone vandaag', 1)->refuse);
        $this->assertFalse($this->guard->inspect(null, 'Een gewone reactie', 1)->refuse);
    }

    public function test_duplicates_are_compared_over_the_full_text(): void
    {
        $product = $this->product();
        $long = str_repeat('Dit is een lange reactie over de smaak van dit product. ', 4);

        Comment::factory()->forSubject($product)->create(['user_id' => 5, 'body_text' => $long.'Einde A.']);

        $this->assertFalse($this->guard->isDuplicate($long.'Einde B.', 5), 'Texts sharing only their start are not duplicates.');
        $this->assertTrue($this->guard->isDuplicate('  '.mb_strtoupper($long.'Einde A.'), 5), 'Case and surrounding whitespace are ignored.');
        $this->assertFalse($this->guard->isDuplicate($long.'Einde A.', 6), 'Another user may write the same text.');

        $this->travel(11)->minutes();
        $this->assertFalse($this->guard->isDuplicate($long.'Einde A.', 5), 'Older than the window.');
    }

    public function test_an_edit_is_not_its_own_duplicate(): void
    {
        $product = $this->product();
        $text = 'Een reactie die lang genoeg is om te vergelijken.';
        $comment = Comment::factory()->forSubject($product)->create(['user_id' => 5, 'body_text' => $text]);

        $this->assertTrue($this->guard->isDuplicate($text, 5));
        $this->assertFalse($this->guard->isDuplicate($text, 5, $comment));
    }
}
