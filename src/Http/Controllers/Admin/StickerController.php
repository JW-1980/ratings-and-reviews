<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\StoreStickerRequest;
use RatingsAndReviews\Http\Requests\Admin\UpdateStickerRequest;
use RatingsAndReviews\Http\Resources\StickerResource;
use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * CRUD of the sticker library (ability rr.manage-settings).
 *
 * Deleting removes the row, so the sticker can no longer be inserted; posts that already
 * show it keep their image, because its file stays unless `delete_file=1` is passed (only
 * for a file the package stored). Deactivating (`is_active=false`) hides it from the picker.
 */
class StickerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAbility($request, Ability::MANAGE_SETTINGS);

        $stickers = Sticker::query()->orderBy('sort_order')->orderBy('id')->get();

        return new JsonResponse(['data' => StickerResource::collection($stickers)->resolve($request)]);
    }

    public function store(StoreStickerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $disk = null;
        $path = isset($data['path']) && is_string($data['path']) ? $data['path'] : null;

        if ($request->hasFile('image')) {
            $disk = (string) config('ratings-and-reviews.stickers.disk', 'public');
            $directory = trim((string) config('ratings-and-reviews.stickers.directory', 'rr-stickers'), '/');
            $file = $request->file('image');
            $path = ($directory === '' ? '' : $directory.'/').Str::random(40).'.'.($file->guessExtension() ?? 'png');

            if (! Storage::disk($disk)->put($path, (string) $file->get(), ['visibility' => 'public'])) {
                throw ValidationException::withMessages(['image' => __('ratings-and-reviews::messages.upload_failed')]);
            }
        }

        try {
            $sticker = Sticker::query()->create([
                'name' => ['nl' => $data['name']['nl'], 'en' => $data['name']['en']],
                'path' => $path,
                'disk' => $disk,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        } catch (Throwable $exception) {
            if ($disk !== null && $path !== null) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        return new JsonResponse(['data' => (new StickerResource($sticker))->resolve($request)], 201);
    }

    public function update(UpdateStickerRequest $request, string $sticker): JsonResponse
    {
        $model = $this->find($sticker);
        $data = $request->validated();

        if (isset($data['name'])) {
            $model->name = ['nl' => $data['name']['nl'], 'en' => $data['name']['en']];
        }

        $model->fill(array_intersect_key($data, array_flip(['is_active', 'sort_order'])))->save();

        return new JsonResponse(['data' => (new StickerResource($model))->resolve($request)]);
    }

    public function destroy(Request $request, string $sticker): JsonResponse
    {
        $this->requireAbility($request, Ability::MANAGE_SETTINGS);
        $model = $this->find($sticker);
        $deleteFile = $request->boolean('delete_file') && $model->disk !== null;

        $model->delete();
        $fileDeleted = $deleteFile && Storage::disk((string) $model->disk)->delete($model->path);

        return new JsonResponse(['data' => ['id' => $model->id, 'deleted' => true, 'file_deleted' => $fileDeleted]]);
    }

    private function find(string $id): Sticker
    {
        return (ctype_digit($id) ? Sticker::query()->find((int) $id) : null) ?? throw new NotFoundHttpException;
    }
}
