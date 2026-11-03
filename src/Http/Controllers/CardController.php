<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Services\PackageAttributeSanitizer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The product card for `<a data-rr-card="type:id">` in the editor and in stored HTML. The
 * host's CardResolver decides what a card shows and who may see it; without one (the
 * default) every card is a 404 and the editor hides the tool.
 */
class CardController extends Controller
{
    public function __construct(private readonly CardResolver $cards) {}

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        if (! $this->cards->isEnabled() || preg_match(PackageAttributeSanitizer::CARD_PATTERN, $type.':'.$id) !== 1) {
            throw new NotFoundHttpException;
        }

        $card = $this->cards->resolve($type, $id, $this->currentUser($request));

        if ($card === null) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse(['data' => $card->toArray()]);
    }
}
