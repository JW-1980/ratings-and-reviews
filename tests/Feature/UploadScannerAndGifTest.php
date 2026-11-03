<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\UploadScanner;
use RatingsAndReviews\Defaults\NullUploadScanner;
use RatingsAndReviews\Events\UploadRejected;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\Uploads;
use RatingsAndReviews\Support\GifSanitizer;
use RatingsAndReviews\Tests\Fixtures\RecordingUploadScanner;
use RatingsAndReviews\Tests\TestCase;
use RuntimeException;

/** UploadScanner hook (store and registerExisting) and the GIF policy (uploads.allow_gif, metadata stripping). */
class UploadScannerAndGifTest extends TestCase
{
    private RecordingUploadScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['app.debug' => false]);
        $this->scanner = new RecordingUploadScanner;
        $this->app->instance(UploadScanner::class, $this->scanner);
        $this->app->forgetInstance(Uploads::class);
    }

    /** A real 60x40 JPEG that also contains the scanner's test signature. */
    private function infectedImage(): UploadedFile
    {
        $bytes = (string) UploadedFile::fake()->image('virus.jpg', 60, 40)->get().' EICAR';

        return UploadedFile::fake()->createWithContent('virus.jpg', $bytes);
    }

    private function send(UploadedFile $file, ?object $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->user())->post('/rr/uploads', ['image' => $file], ['Accept' => 'application/json']);
    }

    /** A real one-frame GIF from GD (width x height). */
    private function gifBytes(int $width = 60, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /** The GD GIF with a comment and a hidden XMP-like application block before the frame, a second frame, a loop block and bytes after the trailer. */
    private function dirtyAnimatedGif(): string
    {
        $gif = $this->gifBytes();
        $frameStart = strpos($gif, "\x2C", 13 + 3 * (1 << ((ord($gif[10]) & 7) + 1)));
        $this->assertNotFalse($frameStart);
        $head = substr($gif, 0, $frameStart);
        $frame = substr($gif, $frameStart, -1);
        $comment = "\x21\xFE".chr(11).'SECRETPLACE'."\x00";
        $xmp = "\x21\xFF\x0BXMP DataXMP".chr(6).'<xmp/>'."\x00";
        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        return $head.$loop.$comment.$xmp.$frame.$frame."\x3B".'TRAILING-PAYLOAD';
    }

    public function test_the_default_scanner_lets_everything_through(): void
    {
        $this->app->forgetInstance(UploadScanner::class);
        $this->app->singleton(UploadScanner::class, NullUploadScanner::class);
        $this->app->forgetInstance(Uploads::class);

        $this->assertInstanceOf(NullUploadScanner::class, $this->app->make(UploadScanner::class));
        $this->send($this->infectedImage())->assertCreated();
    }

    public function test_a_clean_upload_is_scanned_before_it_is_stored(): void
    {
        $this->send(UploadedFile::fake()->image('ok.jpg', 80, 60))->assertCreated();

        $this->assertCount(1, $this->scanner->scanned);
        $this->assertSame('uploaded', $this->scanner->scanned[0]['type']);
        $this->assertSame(1, Upload::query()->count());
    }

    public function test_an_infected_upload_is_refused_with_a_translated_message_and_nothing_is_stored(): void
    {
        Event::fake([UploadRejected::class]);
        $user = $this->user();

        $this->send($this->infectedImage(), $user)
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'This file was refused because it did not pass the safety check.');

        $this->assertSame(0, Upload::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Event::assertDispatched(UploadRejected::class, fn (UploadRejected $event): bool => $event->userId === $user->id
            && $event->cause === UploadRejected::INFECTED && $event->reason === 'Test.EICAR' && $event->filename === 'virus.jpg'
            && $event->mimeType === 'image/jpeg' && $event->source === 'upload' && $event->size > 0);

        app()->setLocale('nl');
        $this->send($this->infectedImage(), $user)->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'Dit bestand is geweigerd omdat het de veiligheidscontrole niet doorstond.');
    }

    public function test_a_scanner_that_fails_refuses_the_upload_unless_it_may_fail_open(): void
    {
        Exceptions::fake();
        Event::fake([UploadRejected::class]);
        $this->scanner->throws = new RuntimeException('clamd unreachable');

        $this->send(UploadedFile::fake()->image('ok.jpg', 80, 60))
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'The file could not be checked right now. Please try again later.');

        $this->assertSame(0, Upload::query()->count());
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'clamd unreachable');
        Event::assertDispatched(UploadRejected::class, fn (UploadRejected $event): bool => $event->cause === UploadRejected::SCANNER_ERROR && $event->reason === 'clamd unreachable');

        config(['ratings-and-reviews.uploads.scanner.fail_open' => true]);
        $this->send(UploadedFile::fake()->image('ok.jpg', 80, 60))->assertCreated();
        $this->assertSame(1, Upload::query()->count());
    }

    public function test_registering_an_existing_file_scans_a_temporary_copy_and_refuses_an_infected_one(): void
    {
        Event::fake([UploadRejected::class]);
        Storage::fake('legacy');
        Storage::disk('legacy')->put('old/clean.png', (string) UploadedFile::fake()->image('c.png', 64, 48)->get());
        Storage::disk('legacy')->put('old/bad.jpg', (string) UploadedFile::fake()->image('b.jpg', 64, 48)->get().'EICAR');
        $uploads = $this->app->make(Uploads::class);

        $upload = $uploads->registerExisting(7, 'legacy', 'old/clean.png');
        $this->assertSame('old/clean.png', $upload->path);
        $this->assertSame('path', $this->scanner->scanned[0]['type']);
        $this->assertTrue($this->scanner->scanned[0]['readable']);
        $this->assertFileDoesNotExist($this->scanner->scanned[0]['path'], 'The temporary copy is removed after the scan.');

        try {
            $uploads->registerExisting(7, 'legacy', 'old/bad.jpg', copy: true);
            $this->fail('An infected legacy file must not be registered.');
        } catch (ValidationException $exception) {
            $this->assertSame('This file was refused because it did not pass the safety check.', $exception->errors()['path'][0]);
        }

        $this->assertSame(1, Upload::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Not even a copy was made.');
        $this->assertFileDoesNotExist($this->scanner->scanned[1]['path']);
        Event::assertDispatched(UploadRejected::class, fn (UploadRejected $event): bool => $event->source === 'import' && $event->userId === 7 && $event->filename === 'bad.jpg');
    }

    public function test_gif_uploads_can_be_switched_off(): void
    {
        config(['ratings-and-reviews.uploads.allow_gif' => false]);

        $this->assertNotContains('image/gif', Uploads::allowedMimeTypes());
        $this->send(UploadedFile::fake()->createWithContent('anim.gif', $this->gifBytes()))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(0, Upload::query()->count());

        // A host calling the service directly gets the same refusal.
        try {
            $this->app->make(Uploads::class)->store(UploadedFile::fake()->createWithContent('anim.gif', $this->gifBytes()), $this->user());
            $this->fail('A GIF must be refused when uploads.allow_gif is off.');
        } catch (ValidationException $exception) {
            $this->assertSame('GIF images are not accepted here. Please use a JPEG, PNG or WebP image.', $exception->errors()['image'][0]);
        }

        Storage::fake('legacy');
        Storage::disk('legacy')->put('old/a.gif', $this->gifBytes());
        $this->expectException(ValidationException::class);
        $this->app->make(Uploads::class)->registerExisting(1, 'legacy', 'old/a.gif');
    }

    public function test_an_accepted_gif_keeps_its_animation_but_loses_comments_application_data_and_trailing_bytes(): void
    {
        $dirty = $this->dirtyAnimatedGif();
        $this->assertStringContainsString('SECRETPLACE', $dirty);

        $data = $this->send(UploadedFile::fake()->createWithContent('anim.gif', $dirty))->assertCreated()->json('data');

        $stored = (string) Storage::disk('public')->get(Upload::query()->findOrFail($data['id'])->path);
        $this->assertStringNotContainsString('SECRETPLACE', $stored);
        $this->assertStringNotContainsString('XMP Data', $stored);
        $this->assertStringNotContainsString('TRAILING-PAYLOAD', $stored);
        $this->assertStringContainsString('NETSCAPE2.0', $stored);
        $this->assertSame(2, GifSanitizer::sanitize($stored)['frames'] ?? 0, 'Both frames survive.');
        $this->assertSame(strlen($stored), strlen(GifSanitizer::sanitize($stored)['bytes']), 'Sanitising is idempotent.');
        $this->assertNotFalse(imagecreatefromstring($stored), 'Still a decodable GIF.');
        $this->assertSame([60, 40], [$data['width'], $data['height']]);
        $this->assertSame('image/gif', Upload::query()->findOrFail($data['id'])->mime_type);
    }

    public function test_a_gif_is_stored_untouched_when_metadata_stripping_is_off(): void
    {
        config(['ratings-and-reviews.uploads.strip_metadata' => false]);
        $dirty = $this->dirtyAnimatedGif();

        $data = $this->send(UploadedFile::fake()->createWithContent('anim.gif', $dirty))->assertCreated()->json('data');

        $this->assertSame($dirty, Storage::disk('public')->get(Upload::query()->findOrFail($data['id'])->path));
    }

    public function test_a_truncated_or_malformed_gif_is_refused(): void
    {
        $gif = $this->gifBytes();

        $this->assertNull(GifSanitizer::sanitize(substr($gif, 0, -12)), 'Truncated in the frame data.');
        $this->assertNull(GifSanitizer::sanitize(substr($gif, 0, -1)), 'No trailer.');
        $this->assertNull(GifSanitizer::sanitize('GIF89a'.str_repeat("\x00", 20)), 'No frame.');
        $this->assertNull(GifSanitizer::sanitize('NOTAGIF'.$gif));
        $this->assertNull(GifSanitizer::sanitize(substr($gif, 0, $block = 13 + 3 * (1 << ((ord($gif[10]) & 7) + 1)))."\x99".substr($gif, $block + 1)), 'Unknown block type.');

        $this->send(UploadedFile::fake()->createWithContent('cut.gif', substr($gif, 0, -12)))->assertUnprocessable();
        $this->assertSame(0, Upload::query()->count());
    }
}
