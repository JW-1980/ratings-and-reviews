<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\StoreReviewQuestionRequest;
use RatingsAndReviews\Http\Requests\Admin\UpdateReviewQuestionRequest;
use RatingsAndReviews\Models\ReviewAnswer;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Services\ReviewQuestions;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\QuestionType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The review questionnaire per scope and category, managed with `rr.manage-topics` (asked
 * with the ReviewQuestion, or an unsaved one with the submitted scope when creating).
 */
class ReviewQuestionController extends Controller
{
    public function __construct(private readonly ReviewQuestions $questions) {}

    /**
     * The questions of the scopes the user may manage (filters `scope`, `category_key`; an
     * empty `category_key` lists the scope-wide ones).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireAbility($request, Ability::MANAGE_TOPICS);
        $resolver = app(AbilityResolver::class);
        $scopes = ReviewQuestion::query()->distinct()->pluck('scope')->map(fn (mixed $scope): string => (string) $scope)
            ->filter(fn (string $scope): bool => $resolver->allows($user, Ability::MANAGE_TOPICS, new ReviewQuestion(['scope' => $scope])))
            ->values()->all();

        $questions = ReviewQuestion::query()
            ->when($scopes === [], fn ($query) => $query->whereRaw('0 = 1'), fn ($query) => $query->whereIn('scope', $scopes))
            ->when($request->filled('scope'), fn ($query) => $query->where('scope', (string) $request->query('scope')))
            ->when($request->has('category_key'), fn ($query) => $request->filled('category_key')
                ? $query->where('category_key', (string) $request->query('category_key'))
                : $query->whereNull('category_key'))
            ->orderBy('scope')
            ->orderBy('category_key')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $answered = $questions->isEmpty() ? collect() : ReviewAnswer::query()->whereIn('question_id', $questions->modelKeys())->toBase()
            ->selectRaw('question_id, COUNT(DISTINCT review_id) as answered')->groupBy('question_id')->pluck('answered', 'question_id');

        return new JsonResponse([
            'data' => $questions->map(fn (ReviewQuestion $question): array => $this->present($question) + ['answered_reviews' => (int) ($answered[$question->id] ?? 0)])->values()->all(),
            'meta' => [
                'types' => array_map(fn (string $type): array => ['value' => $type, 'label' => __('ratings-and-reviews::enums.question_type.'.$type)], QuestionType::values()),
                'scale_max' => ReviewQuestion::scaleMax(),
                'max_per_subject' => max(1, (int) config('ratings-and-reviews.review_questions.max_per_subject', 20)),
            ],
        ]);
    }

    public function store(StoreReviewQuestionRequest $request): JsonResponse
    {
        /** @var array{scope: string, category_key?: string|null, key: string, type: string, label: array<string, string>} $data */
        $data = $request->validated();
        $question = $this->questions->create($data);

        return new JsonResponse(['data' => $this->present($question)], 201);
    }

    public function update(UpdateReviewQuestionRequest $request, string $question): JsonResponse
    {
        $updated = $this->questions->update($request->question(), $request->validated());

        return new JsonResponse(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, string $question): JsonResponse
    {
        $model = (ctype_digit($question) ? ReviewQuestion::query()->find((int) $question) : null) ?? throw new NotFoundHttpException;
        $this->requireAbility($request, Ability::MANAGE_TOPICS, $model);

        return new JsonResponse(['data' => ['answered_reviews' => $this->questions->delete($model)]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ReviewQuestion $question): array
    {
        return [
            'id' => $question->id,
            'scope' => $question->scope,
            'category_key' => $question->category_key,
            'key' => $question->key,
            'type' => $question->type->value,
            'label' => $question->label,
            'low_label' => $question->low_label,
            'high_label' => $question->high_label,
            'options' => $question->options ?? [],
            'is_required' => $question->is_required,
            'is_active' => $question->is_active,
            'sort_order' => $question->sort_order,
        ];
    }
}
