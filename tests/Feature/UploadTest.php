<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Tests\TestCase;

class UploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->configureSection('product', ['editor_tools' => ['bold', 'image']]);
    }

    /** A real image file with EXIF-free pixels made by GD. */
    private function image(string $name = 'photo.jpg', int $width = 120, int $height = 80): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function upload(?object $user = null, ?UploadedFile $file = null): array
    {
        return $this->actingAs($user ?? $this->user())->post('/rr/uploads', ['image' => $file ?? $this->image()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');
    }

    public function test_a_member_uploads_an_image_and_gets_its_id_and_public_url(): void
    {
        $user = $this->user();
        $data = $this->actingAs($user)->post('/rr/uploads', ['image' => $this->image(), 'alt' => ' Havermelk <b>in</b> koffie '], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url', 'alt', 'width', 'height']])
            ->assertJsonPath('data.alt', 'Havermelk in koffie')
            ->assertJsonPath('data.width', 120)
            ->assertJsonPath('data.height', 80)
            ->json('data');

        $upload = Upload::query()->findOrFail($data['id']);
        $this->assertSame($user->id, $upload->user_id);
        $this->assertSame('public', $upload->disk);
        $this->assertStringStartsWith('rr-uploads/'.now()->format('Y/m').'/', $upload->path);
        $this->assertSame('/storage/'.$upload->path, $data['url']);
        $this->assertSame('image/jpeg', $upload->mime_type);
        $this->assertNull($upload->attached_id);
        Storage::disk('public')->assertExists($upload->path);
    }

    public function test_guests_cannot_upload(): void
    {
        $this->post('/rr/uploads', ['image' => $this->image()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_size_type_and_dimension_limits_come_from_the_config(): void
    {
        config(['ratings-and-reviews.uploads.max_kilobytes' => 10, 'ratings-and-reviews.uploads.max_width' => 200, 'ratings-and-reviews.uploads.min_width' => 50]);
        $user = $this->user();
        $post = fn (UploadedFile $file) => $this->actingAs($user)->post('/rr/uploads', ['image' => $file], ['Accept' => 'application/json']);

        $post(UploadedFile::fake()->create('big.jpg', 50, 'image/jpeg'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $post(UploadedFile::fake()->create('doc.pdf', 2, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $post(UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $post($this->image('wide.png', 300, 60))->assertUnprocessable()->assertJsonValidationErrors('image');
        $post($this->image('narrow.png', 30, 60))->assertUnprocessable()->assertJsonValidationErrors('image');
        $post($this->image('ok.png', 100, 60))->assertCreated()->assertJsonPath('data.width', 100);

        $this->assertSame(1, Upload::query()->count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_the_daily_quota_answers_429_with_retry_after_until_the_oldest_upload_is_a_day_old(): void
    {
        config(['ratings-and-reviews.uploads.daily_quota' => 2]);
        $user = $this->user();
        $this->upload($user);
        $this->travel(2)->hours();
        $this->upload($user);

        $response = $this->actingAs($user)->post('/rr/uploads', ['image' => $this->image()], ['Accept' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'You have reached the limit of 2 images per day. Please try again later.');
        $retry = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(21 * 3600, $retry);
        $this->assertLessThanOrEqual(22 * 3600, $retry);

        // Another member is not affected; after a day the first upload no longer counts.
        $this->upload($this->user());
        $this->travel(22)->hours();
        $this->upload($user);
        $this->assertSame(4, Upload::query()->count());
    }

    public function test_uploads_are_throttled_per_member_per_minute(): void
    {
        config(['ratings-and-reviews.rate_limits.rr-upload' => 2]);
        $user = $this->user();
        $this->upload($user);
        $this->upload($user);
        $this->actingAs($user)->post('/rr/uploads', ['image' => $this->image()], ['Accept' => 'application/json'])->assertStatus(429);
        $this->upload($this->user());
    }

    public function test_metadata_is_stripped_from_jpegs(): void
    {
        $user = $this->user();
        $jpeg = $this->jpegWithExifComment();
        $this->assertStringContainsString('SecretLocation', $jpeg);
        $file = UploadedFile::fake()->createWithContent('exif.jpg', $jpeg);

        $data = $this->upload($user, $file);
        $stored = Storage::disk('public')->get(Upload::query()->findOrFail($data['id'])->path);

        $this->assertStringNotContainsString('SecretLocation', $stored);
        $this->assertNotFalse(imagecreatefromstring($stored));
    }

    public function test_an_upload_is_attached_to_the_comment_that_shows_it_and_cannot_be_used_by_others_or_twice(): void
    {
        $product = $this->product();
        $author = $this->user();
        $data = $this->upload($author);
        $body = '<p>Kijk<img src="'.$data['url'].'" alt="foto"></p>';

        // Someone else cannot show the author's upload.
        $other = $this->user();
        $foreign = $this->actingAs($other)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => $body])->assertCreated()->json('data.body_html');
        $this->assertStringNotContainsString('<img', $foreign);

        $html = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => $body])->assertCreated()->json('data');
        $this->assertStringContainsString('src="'.$data['url'].'"', $html['body_html']);
        $upload = Upload::query()->findOrFail($data['id']);
        $this->assertSame('rr_comment', $upload->attached_type);
        $this->assertSame($html['id'], $upload->attached_id);
        $this->assertSame(Upload::ROLE_INLINE, $upload->role);

        // Once attached it belongs to that comment: a second comment cannot reuse it...
        $second = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => $body.'<p>nogmaals</p>'])->assertCreated()->json('data.body_html');
        $this->assertStringNotContainsString('<img', $second);

        // ...but editing the comment that owns it keeps it.
        $edited = $this->actingAs($author)->patchJson("/rr/comments/{$html['id']}", ['body' => '<p>Bewerkt<img src="'.$data['url'].'" alt="foto"></p>'])->assertOk()->json('data.body_html');
        $this->assertStringContainsString('<img', $edited);
    }

    public function test_deleting_an_own_comment_deletes_its_files_unless_a_report_keeps_it_as_evidence(): void
    {
        $product = $this->product();
        $author = $this->user();
        $first = $this->upload($author);
        $second = $this->upload($author);
        $post = fn (array $upload) => $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Foto<img src="'.$upload['url'].'"></p>'])->json('data.id');
        $plain = $post($first);
        $reported = $post($second);
        $this->actingAs($this->user())->postJson('/rr/reports', ['reportable_type' => 'rr_comment', 'reportable_id' => $reported, 'reason' => 'spam'])->assertCreated();
        $paths = Upload::query()->orderBy('id')->pluck('path')->all();

        $this->actingAs($author)->deleteJson("/rr/comments/{$plain}")->assertNoContent();
        $this->actingAs($author)->deleteJson("/rr/comments/{$reported}")->assertNoContent();

        Storage::disk('public')->assertMissing($paths[0]);
        Storage::disk('public')->assertExists($paths[1]);
        $this->assertSame([$second['id']], Upload::query()->pluck('id')->all());
        $this->assertSame(1, Report::query()->open()->count());
    }

    public function test_a_moderator_removal_keeps_the_files(): void
    {
        $product = $this->product();
        $author = $this->user();
        $upload = $this->upload($author);
        $id = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Foto<img src="'.$upload['url'].'"></p>'])->json('data.id');

        $this->actingAs($this->moderator())->deleteJson("/rr/comments/{$id}")->assertNoContent();

        $this->assertTrue(Comment::query()->withTrashed()->findOrFail($id)->trashed());
        Storage::disk('public')->assertExists(Upload::query()->sole()->path);
    }

    public function test_prune_deletes_only_unattached_uploads_past_the_grace_period(): void
    {
        $product = $this->product();
        $author = $this->user();
        $used = $this->upload($author);
        $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Foto<img src="'.$used['url'].'"></p>'])->assertCreated();
        $old = $this->upload($author);
        $this->travel(23)->hours();
        $fresh = $this->upload($author);
        $paths = Upload::query()->pluck('path', 'id');

        $this->artisan('rr:prune-uploads')->assertSuccessful()->expectsOutputToContain('Deleted 0 unattached upload(s).');
        $this->travel(2)->hours();
        $this->artisan('rr:prune-uploads')->assertSuccessful()->expectsOutputToContain('Deleted 1 unattached upload(s).');

        $this->assertEqualsCanonicalizing([$used['id'], $fresh['id']], Upload::query()->pluck('id')->all());
        Storage::disk('public')->assertMissing($paths[$old['id']]);
        Storage::disk('public')->assertExists($paths[$used['id']]);
        Storage::disk('public')->assertExists($paths[$fresh['id']]);

        $this->artisan('rr:prune-uploads', ['--hours' => 0])->assertSuccessful()->expectsOutputToContain('Deleted 1 unattached upload(s).');
        $this->artisan('rr:prune-uploads', ['--hours' => 'soon'])->assertFailed();
        $this->assertSame([$used['id']], Upload::query()->pluck('id')->all());
    }

    public function test_prune_keeps_the_row_when_the_file_cannot_be_deleted(): void
    {
        $upload = Upload::query()->create(['user_id' => 1, 'disk' => 'missing-disk', 'path' => 'x/y.png']);
        $this->travel(2)->days();

        $this->artisan('rr:prune-uploads')->assertSuccessful()->expectsOutputToContain('Deleted 0 unattached upload(s).');
        $this->assertNotNull($upload->fresh());
    }

    public function test_prune_is_scheduled_hourly(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'rr:prune-uploads'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }

    public function test_private_uploads_are_served_to_the_uploader_and_to_others_only_while_the_content_is_public(): void
    {
        Storage::fake('local');
        config(['ratings-and-reviews.uploads.visibility' => 'private', 'ratings-and-reviews.uploads.disk' => 'local']);
        $product = $this->product();
        $author = $this->user();
        $data = $this->upload($author);
        $this->assertStringStartsWith('/rr/media/rr-uploads/', $data['url']);

        $this->actingAs($author)->get($data['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $stranger = $this->user();
        $this->actingAs($stranger)->get($data['url'])->assertNotFound();

        $this->configureSection('product', ['editor_tools' => ['image'], 'undo_window_seconds' => 60]);
        $id = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Privé<img src="'.$data['url'].'"></p>'])->assertCreated()->json('data.id');
        $this->assertSame($id, Upload::query()->sole()->attached_id);
        $this->actingAs($stranger)->get($data['url'])->assertNotFound();

        $this->travel(61)->seconds();
        $this->artisan('rr:publish-due');
        $this->actingAs($stranger)->get($data['url'])->assertOk();
        auth()->forgetGuards();
        $this->get($data['url'])->assertOk();

        $product->update(['is_hidden' => true]);
        $this->get($data['url'])->assertNotFound();
        $this->get('/rr/media/rr-uploads/nothing.jpg')->assertNotFound();
    }

    public function test_the_media_route_serves_nothing_when_uploads_are_public(): void
    {
        $data = $this->upload();
        $path = Upload::query()->sole()->path;

        $this->get('/rr/media/'.$path)->assertNotFound();
        $this->assertSame('/storage/'.$path, $data['url']);
    }

    private function jpegWithExifComment(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        // Insert an APP1 "Exif" segment right after the SOI marker, as a camera would.
        $payload = "Exif\0\0".'SecretLocation 52.37N 4.89E';
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
