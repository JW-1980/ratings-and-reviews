<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Http\UploadedFile;
use RatingsAndReviews\Contracts\UploadScanner;
use RatingsAndReviews\Support\ScanResult;

/** Default: no scanner, every upload passes. */
class NullUploadScanner implements UploadScanner
{
    public function scan(UploadedFile|string $path): ScanResult
    {
        return ScanResult::clean();
    }
}
