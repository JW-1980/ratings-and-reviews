<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Http\Requests\ToneCheckRequest;
use RatingsAndReviews\Services\HtmlSanitizer;
use RatingsAndReviews\Services\ToneNudge;

/**
 * Checks a draft for the rephrase nudge before posting. The draft may be editor HTML; only
 * its text is checked. Nothing is stored.
 */
class ToneCheckController extends Controller
{
    public function __construct(
        private readonly ToneNudge $tone,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function store(ToneCheckRequest $request): JsonResponse
    {
        $html = $this->sanitizer->fromEditorInput((string) $request->validated('text'));
        $suggestion = $this->tone->check($this->sanitizer->plainText(strip_tags($html, '<p><br><li><blockquote>')));

        return new JsonResponse(['data' => ['enabled' => $this->tone->isEnabled(), 'nudge' => $suggestion?->toArray()]]);
    }
}
