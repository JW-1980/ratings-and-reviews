<?php

namespace RatingsAndReviews\Contracts;

use Illuminate\Http\UploadedFile;
use RatingsAndReviews\Support\ScanResult;

/**
 * Optional: scans an upload for malware before the package stores it (a ClamAV adapter, a
 * cloud scanner). Called by `Uploads::store()` and `Uploads::registerExisting()` with the
 * file as the member sent it, or a readable path of the legacy file (a temporary copy
 * that the package removes afterwards). Return `ScanResult::infected($reason)` to refuse it.
 *
 * An exception means "could not scan": the upload is refused as well (fail closed) unless
 * `uploads.scanner.fail_open` is on; the exception is reported either way.
 */
interface UploadScanner
{
    public function scan(UploadedFile|string $path): ScanResult;
}
