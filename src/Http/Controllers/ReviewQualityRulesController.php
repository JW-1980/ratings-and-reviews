<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Services\ReviewQualityMeter;

/**
 * The rules of the review quality meter as JSON, so the browser computes the same score
 * as ReviewQualityMeter::assess() while the review is written.
 */
class ReviewQualityRulesController extends Controller
{
    public function __construct(private readonly ReviewQualityMeter $meter) {}

    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->meter->rules()]);
    }
}
