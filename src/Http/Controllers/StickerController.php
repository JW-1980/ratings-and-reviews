<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Models\Sticker;

/**
 * GET stickers: the active sticker library for the editor's sticker picker.
 */
class StickerController extends Controller
{
    public function index(): JsonResponse
    {
        $stickers = Sticker::query()->active()->orderBy('sort_order')->orderBy('id')->get();

        return new JsonResponse([
            'data' => $stickers->map(fn (Sticker $sticker): array => ['id' => $sticker->id, 'name' => $sticker->localizedName(), 'url' => $sticker->url()])->values()->all(),
        ]);
    }
}
