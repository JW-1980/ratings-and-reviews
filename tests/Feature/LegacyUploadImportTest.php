<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\Uploads;
use RatingsAndReviews\Tests\TestCase;

class LegacyUploadImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('legacy');
    }

    private function putImage(string $disk, string $path, int $width = 64, int $height = 48): void
    {
        Storage::disk($disk)->put($path, (string) UploadedFile::fake()->image('x.png', $width, $height)->get());
    }

    private function uploads(): Uploads
    {
        return $this->app->make(Uploads::class);
    }

    public function test_an_existing_file_becomes_an_attached_upload_of_the_user_once(): void
    {
        $this->putImage('public', 'legacy/comments/a.png');
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => 41]);

        $upload = $this->uploads()->registerExisting(41, 'public', '/legacy/comments/a.png', $comment, alt: ' Kopje <b>koffie</b> ');

        $this->assertSame(41, $upload->user_id);
        $this->assertSame('legacy/comments/a.png', $upload->path);
        $this->assertSame('image/png', $upload->mime_type);
        $this->assertSame([64, 48], [$upload->width, $upload->height]);
        $this->assertSame('Kopje koffie', $upload->alt_text);
        $this->assertSame(['rr_comment', $comment->id, Upload::ROLE_INLINE], [$upload->attached_type, $upload->attached_id, $upload->role]);
        $this->assertSame('/storage/legacy/comments/a.png', $upload->url());

        $again = $this->uploads()->registerExisting(41, 'public', 'legacy/comments/a.png', $comment);
        $this->assertSame($upload->id, $again->id);
        $this->assertSame(1, Upload::query()->count());

        try {
            $this->uploads()->registerExisting(42, 'public', 'legacy/comments/a.png');
            $this->fail('A file registered for one member is not handed to another.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('path', $exception->errors());
        }

        $this->assertSame(0, $this->uploads()->prune(0), 'An attached import is never pruned.');
        Storage::disk('public')->assertExists('legacy/comments/a.png');
    }

    public function test_review_photos_are_imported_in_order_and_shown_with_the_review(): void
    {
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['user_id' => 7]);
        $this->putImage('public', 'legacy/r/2.png');
        $this->putImage('public', 'legacy/r/1.png');

        $this->uploads()->registerExisting(7, 'public', 'legacy/r/2.png', $review, Upload::ROLE_PHOTO, position: 1);
        $this->uploads()->registerExisting(7, 'public', 'legacy/r/1.png', $review, Upload::ROLE_PHOTO, position: 0);

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")
            ->assertJsonPath('data.0.photos.0.url', '/storage/legacy/r/1.png')
            ->assertJsonPath('data.0.photos.1.url', '/storage/legacy/r/2.png');
    }

    public function test_copying_moves_the_bytes_into_the_package_disk_and_is_idempotent(): void
    {
        $this->putImage('legacy', 'old/pic.png');

        $first = $this->uploads()->registerExisting(9, 'legacy', 'old/pic.png', copy: true);
        $second = $this->uploads()->registerExisting(9, 'legacy', 'old/pic.png', copy: true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('public', $first->disk);
        $this->assertStringStartsWith('rr-uploads/imported/', $first->path);
        Storage::disk('public')->assertExists($first->path);
        Storage::disk('legacy')->assertExists('old/pic.png');
    }

    public function test_missing_files_and_files_that_are_not_allowed_images_are_refused(): void
    {
        Storage::disk('public')->put('legacy/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        Storage::disk('public')->put('legacy/notes.txt', 'plain text');

        foreach (['legacy/missing.png', 'legacy/evil.svg', 'legacy/notes.txt', '../outside.png', ''] as $path) {
            try {
                $this->uploads()->registerExisting(1, 'public', $path);
                $this->fail("Refused: {$path}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('path', $exception->errors());
            }
        }

        $this->assertSame(0, Upload::query()->count());
    }
}
