<?php

namespace RatingsAndReviews\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Support\ToneSuggestion;

/**
 * Thrown when a post matches the rephrase nudge and was not confirmed. A validation error
 * on `confirm_tone` (422) that also carries the suggestion as `tone_nudge`; resending with
 * `confirm_tone=true` posts it.
 */
class ToneNudgeRequired extends ValidationException
{
    public function __construct(public readonly ToneSuggestion $suggestion)
    {
        parent::__construct(Validator::make([], []));
        $this->validator->errors()->add('confirm_tone', $suggestion->message());
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->suggestion->message(),
            'errors' => $this->errors(),
            'tone_nudge' => $this->suggestion->toArray(),
        ], 422);
    }
}
