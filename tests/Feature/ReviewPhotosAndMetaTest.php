<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Tests\TestCase;

class ReviewPhotosAndMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload(object $user): int
    {
        return (int) $this->actingAs($user)->post('/rr/uploads', ['image' => UploadedFile::fake()->image('p.jpg', 80, 80)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function review(object $user, int $productId, array $data = [], int $status = 201): array
    {
        return $this->actingAs($user)->postJson("/rr/subjects/product/{$productId}/reviews", $data + ['rating' => 4, 'body' => 'Lekker romig in koffie.'])
            ->assertStatus($status)
            ->json();
    }

    public function test_a_review_carries_its_photos_in_order_with_an_alt_text_from_its_title(): void
    {
        $product = $this->product();
        $author = $this->user();
        [$first, $second] = [$this->upload($author), $this->upload($author)];

        $data = $this->review($author, $product->id, ['title' => 'Romig', 'photos' => [$second, $first]])['data'];

        $this->assertSame([$second, $first], array_column($data['photos'], 'id'));
        $this->assertSame('Photo 1 with the review "Romig"', $data['photos'][0]['alt']);
        $this->assertSame('/storage/'.Upload::query()->findOrFail($second)->path, $data['photos'][0]['url']);
        $this->assertSame([[Upload::ROLE_PHOTO, 0], [Upload::ROLE_PHOTO, 1]], Upload::query()->whereKey([$second, $first])->orderBy('position')->get()->map(fn (Upload $u): array => [$u->role, $u->position])->all());

        $listed = $this->actingAs($this->user())->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->json('data.0.photos');
        $this->assertSame([$second, $first], array_column($listed, 'id'));

        $this->app->setLocale('nl');
        $untitled = $this->review($this->user(), $product->id, ['photos' => []])['data'];
        $this->assertSame([], $untitled['photos']);
    }

    public function test_photos_must_be_own_unattached_uploads_within_the_limit(): void
    {
        config(['ratings-and-reviews.reviews.max_photos' => 2]);
        $product = $this->product();
        $author = $this->user();
        $other = $this->user();
        $foreign = $this->upload($other);
        $own = [$this->upload($author), $this->upload($author), $this->upload($author)];

        $this->review($author, $product->id, ['photos' => [$foreign]], 422);
        $this->review($author, $product->id, ['photos' => $own], 422);
        $this->review($author, $product->id, ['photos' => ['x']], 422);
        $this->review($author, $product->id, ['photos' => [999999]], 422);
        $this->assertSame(0, Review::query()->count());

        $id = $this->review($author, $product->id, ['photos' => [$own[0]]])['data']['id'];

        // An upload attached to this review cannot become a photo of another review.
        $this->review($this->user(), $this->product()->id, ['photos' => [$own[0]]], 422);
        $this->assertSame($id, Upload::query()->findOrFail($own[0])->attached_id);
    }

    public function test_photos_can_be_switched_off(): void
    {
        config(['ratings-and-reviews.reviews.max_photos' => 0]);
        $author = $this->user();
        $photo = $this->upload($author);

        $json = $this->review($author, $this->product()->id, ['photos' => [$photo]], 422);
        $this->assertSame(['Photos cannot be added to reviews here.'], $json['errors']['photos']);
    }

    public function test_editing_replaces_or_keeps_photos_and_releases_the_removed_ones(): void
    {
        $product = $this->product();
        $author = $this->user();
        [$a, $b, $c] = [$this->upload($author), $this->upload($author), $this->upload($author)];
        $id = $this->review($author, $product->id, ['photos' => [$a, $b]])['data']['id'];

        // Without `photos` the current ones stay.
        $kept = $this->actingAs($author)->patchJson("/rr/reviews/{$id}", ['rating' => 5, 'body' => 'Nog steeds lekker romig.'])->assertOk()->json('data.photos');
        $this->assertSame([$a, $b], array_column($kept, 'id'));

        $replaced = $this->actingAs($author)->patchJson("/rr/reviews/{$id}", ['rating' => 5, 'body' => 'Nog steeds lekker romig.', 'photos' => [$c, $a]])->assertOk()->json('data.photos');
        $this->assertSame([$c, $a], array_column($replaced, 'id'));
        $this->assertNull(Upload::query()->findOrFail($b)->attached_id, 'A removed photo is released, so prune removes it.');

        $this->actingAs($author)->patchJson("/rr/reviews/{$id}", ['rating' => 5, 'body' => 'Nog steeds lekker romig.', 'photos' => []])->assertOk()->assertJsonPath('data.photos', []);
        $this->assertSame(0, Upload::query()->whereNotNull('attached_id')->count());
    }

    public function test_photos_of_a_review_that_is_not_public_are_shown_only_to_its_author_and_moderators(): void
    {
        config(['ratings-and-reviews.reviews.photos_require_approval' => true]);
        $product = $this->product();
        $author = $this->user();
        $photo = $this->upload($author);

        $data = $this->review($author, $product->id, ['photos' => [$photo]]);
        $this->assertSame('pending', $data['data']['status'], 'A review that gains a photo waits for a moderator.');
        $this->assertCount(1, $data['data']['photos']);

        $this->actingAs($author)->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonCount(1, 'meta.own_review.photos');
        $this->actingAs($this->user())->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonCount(0, 'data');

        $moderator = $this->moderator();
        $this->actingAs($moderator)->getJson('/rr/admin/moderation?kind=reviews')->assertOk()->assertJsonPath('data.0.photos.0.id', $photo);
        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$data['data']['id']}", ['action' => 'approve'])->assertOk();
        $this->actingAs($this->user())->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('data.0.photos.0.id', $photo);

        // Without new photos an edit is not held again.
        $this->actingAs($author)->patchJson("/rr/reviews/{$data['data']['id']}", ['rating' => 3, 'body' => 'Toch iets minder romig.', 'photos' => [$photo]])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_inline_images_are_attached_to_the_review_and_released_when_the_text_drops_them(): void
    {
        $this->configureSection('product', ['editor_tools' => ['bold', 'image']]);
        $product = $this->product();
        $author = $this->user();
        $photo = $this->upload($author);
        $url = '/storage/'.Upload::query()->findOrFail($photo)->path;

        $id = $this->review($author, $product->id, ['body' => '<p>Zie<img src="'.$url.'" alt="x"></p>'])['data']['id'];
        $this->assertSame([$id, Upload::ROLE_INLINE], [Upload::query()->findOrFail($photo)->attached_id, Upload::query()->findOrFail($photo)->role]);

        $this->actingAs($author)->patchJson("/rr/reviews/{$id}", ['rating' => 4, 'body' => '<p>Zonder foto nu.</p>'])->assertOk();
        $this->assertNull(Upload::query()->findOrFail($photo)->attached_id);
    }

    public function test_deleting_an_own_review_deletes_its_photo_files(): void
    {
        $product = $this->product();
        $author = $this->user();
        $photo = $this->upload($author);
        $path = Upload::query()->findOrFail($photo)->path;
        $id = $this->review($author, $product->id, ['photos' => [$photo]])['data']['id'];

        $this->actingAs($author)->deleteJson("/rr/reviews/{$id}")->assertNoContent();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_the_reviews_meta_describes_the_form_of_the_section(): void
    {
        $this->configureSection('product', ['review_extended_fields' => false, 'review_min_length' => 20, 'review_scale' => 10]);
        config(['ratings-and-reviews.reviews.max_photos' => 3]);
        $product = $this->product();

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()
            ->assertJsonPath('meta.scale_max', 10)
            ->assertJsonPath('meta.review_scale', 10)
            ->assertJsonPath('meta.review_extended_fields', false)
            ->assertJsonPath('meta.review_min_length', 20)
            ->assertJsonPath('meta.review_form.max_photos', 3)
            ->assertJsonPath('meta.review_form.max_title_length', 150)
            ->assertJsonPath('meta.review_form.max_pros_cons', 5);
    }

    public function test_without_extended_fields_a_title_pros_or_cons_is_refused_and_the_minimum_length_applies(): void
    {
        $this->configureSection('product', ['review_extended_fields' => false, 'review_min_length' => 20]);
        $product = $this->product();
        $author = $this->user();
        $body = 'Twintig tekens of meer hier.';

        $json = $this->review($author, $product->id, ['body' => $body, 'title' => 'Titel', 'pros' => ['Romig'], 'cons' => ['', ' ']], 422);
        $this->assertSame(['title', 'pros'], array_keys($json['errors']));
        $this->assertSame('This field is not used for reviews here.', $json['errors']['title'][0]);

        $this->review($author, $product->id, ['body' => 'Te kort.'], 422);
        $this->assertSame('rating', array_key_first($this->review($author, $product->id, ['rating' => 11, 'body' => $body], 422)['errors']));

        $data = $this->review($author, $product->id, ['body' => $body, 'title' => '', 'pros' => [''], 'cons' => []])['data'];
        $this->assertNull($data['title']);
        $this->assertSame([], $data['pros']);
    }

    public function test_with_extended_fields_title_pros_and_cons_are_stored(): void
    {
        $data = $this->review($this->user(), $this->product()->id, ['title' => 'Romig', 'pros' => ['Smaak'], 'cons' => ['Prijs']])['data'];

        $this->assertSame(['Romig', ['Smaak'], ['Prijs']], [$data['title'], $data['pros'], $data['cons']]);
    }
}
