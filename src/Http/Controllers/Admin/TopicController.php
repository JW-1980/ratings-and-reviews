<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\StoreTopicRequest;
use RatingsAndReviews\Http\Requests\Admin\UpdateTopicRequest;
use RatingsAndReviews\Http\Resources\RatingTopicResource;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Services\RatingTopicCatalog;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TopicController extends Controller
{
    public function __construct(private readonly RatingTopicCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAbility($request, Ability::MANAGE_TOPICS);

        $topics = RatingTopic::query()
            ->when($request->filled('scope'), fn ($query) => $query->where('scope', (string) $request->query('scope')))
            ->when($request->has('category_key'), fn ($query) => $request->filled('category_key')
                ? $query->where('category_key', (string) $request->query('category_key'))
                : $query->whereNull('category_key'))
            ->withCount('ratings')
            ->orderBy('scope')
            ->orderBy('category_key')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return new JsonResponse([
            'data' => RatingTopicResource::collection($topics)->resolve($request),
            'meta' => ['max_active' => $this->catalog->maxActive()],
        ]);
    }

    public function store(StoreTopicRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['category_key'] = $data['category_key'] ?? null;
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        $topic = $this->catalog->create($data);

        return new JsonResponse(['data' => (new RatingTopicResource($topic))->resolve($request)], 201);
    }

    public function update(UpdateTopicRequest $request, string $topic): JsonResponse
    {
        $updated = $this->catalog->update($this->find($topic), $request->validated());

        return new JsonResponse(['data' => (new RatingTopicResource($updated))->resolve($request)]);
    }

    public function destroy(Request $request, string $topic): JsonResponse
    {
        $this->requireAbility($request, Ability::MANAGE_TOPICS);
        $deleted = $this->catalog->delete($this->find($topic));

        return new JsonResponse(['data' => ['deleted_ratings' => $deleted]]);
    }

    private function find(string $id): RatingTopic
    {
        return (ctype_digit($id) ? RatingTopic::query()->find((int) $id) : null) ?? throw new NotFoundHttpException;
    }
}
