<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use RatingsAndReviews\Contracts\UploadScanner;
use RatingsAndReviews\Support\ScanResult;
use Throwable;

/** A scanner for tests: refuses files containing "EICAR", records what it was given. */
class RecordingUploadScanner implements UploadScanner
{
    /** @var list<array{type: string, path: string, readable: bool, size: int}> */
    public array $scanned = [];

    public ?Throwable $throws = null;

    public function scan(UploadedFile|string $path): ScanResult
    {
        $file = $path instanceof UploadedFile ? (string) $path->getRealPath() : $path;
        $this->scanned[] = ['type' => $path instanceof UploadedFile ? 'uploaded' : 'path', 'path' => $file, 'readable' => is_readable($file), 'size' => (int) filesize($file)];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return str_contains((string) file_get_contents($file), 'EICAR') ? ScanResult::infected('Test.EICAR') : ScanResult::clean();
    }
}
