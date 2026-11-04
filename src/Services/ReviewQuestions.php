<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewAnswer;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Support\QuestionType;

/**
 * The review questionnaire: extra questions a reviewer answers with the review (a taste
 * dimension on a 1 … 5 scale, "good for breakfast?", one or several options out of a list).
 *
 * Inheritance by key, like rating topics by category: a subject in category path [a, b]
 * gets the questions of its scope without a category, then those of `a`, then those of `b`.
 * A question with a key that was already inherited replaces it in its place; an inactive one
 * removes it for that category and below. New keys are added in the order of the levels, then
 * `sort_order`. The list is capped at `review_questions.max_per_subject`.
 *
 * Answers are optional unless the question `is_required`. They are stored per review in
 * rr_review_answers (one row per chosen option, or one with the number for a scale), counted
 * per question in the aggregate job and shown in the summary (`questions`).
 */
class ReviewQuestions
{
    /** @var array<string, Collection<int, ReviewQuestion>> per scope and path, for the request */
    private array $effective = [];

    public function isEnabled(): bool
    {
        return (bool) config('ratings-and-reviews.review_questions.enabled', true);
    }

    /**
     * @return Collection<int, ReviewQuestion>
     */
    public function effectiveFor(Model&Reviewable $subject): Collection
    {
        return $this->effective($subject->ratingScope(), $subject->ratingCategoryPath());
    }

    /**
     * @param  list<string>  $categoryPath
     * @return Collection<int, ReviewQuestion>
     */
    public function effective(string $scope, array $categoryPath = []): Collection
    {
        if (! $this->isEnabled()) {
            return new Collection;
        }

        $keys = array_values(array_filter($categoryPath, fn (mixed $key): bool => is_string($key) && $key !== ''));
        $cacheKey = $scope."\0".implode("\0", $keys);

        if (isset($this->effective[$cacheKey])) {
            return $this->effective[$cacheKey];
        }

        $rows = ReviewQuestion::query()
            ->where('scope', $scope)
            ->where(function ($query) use ($keys): void {
                $query->whereNull('category_key');

                if ($keys !== []) {
                    $query->orWhereIn('category_key', $keys);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var array<string, ReviewQuestion|null> $slots key => question (null: switched off below) */
        $slots = [];

        foreach ([null, ...$keys] as $level) {
            foreach ($rows->filter(fn (ReviewQuestion $question): bool => $question->category_key === $level) as $question) {
                $slots[$question->key] = $question->is_active ? $question : null;
            }
        }

        $max = max(1, (int) config('ratings-and-reviews.review_questions.max_per_subject', 20));

        return $this->effective[$cacheKey] = new Collection(array_slice(array_values(array_filter($slots)), 0, $max));
    }

    /**
     * The questions as the review form shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function presentFor(Model&Reviewable $subject): array
    {
        return $this->effectiveFor($subject)->map(fn (ReviewQuestion $question): array => $question->present())->values()->all();
    }

    /**
     * Validates `answers` ({question key: value}): a whole number 1 … scale for a scale, one
     * option value for a choice, a list of distinct option values for a multi question; null,
     * '' or [] leaves the question unanswered. Null input: not sent (an edit keeps the stored
     * answers); then required questions are not checked.
     *
     * @return array<int, list<array{option_key: string, value: int|null}>>|null question id => rows
     *
     * @throws ValidationException
     */
    public function validatedAnswers(Model&Reviewable $subject, mixed $input, bool $sent): ?array
    {
        if (! $sent) {
            return null;
        }

        $questions = $this->effectiveFor($subject)->keyBy('key');
        $input = is_array($input) ? $input : [];
        $errors = [];
        $answers = [];

        foreach ($input as $key => $value) {
            $question = is_string($key) ? $questions->get($key) : null;

            if (! $question instanceof ReviewQuestion) {
                $errors['answers.'.$key] = [__('ratings-and-reviews::messages.question_unknown')];

                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $rows = $this->rows($question, $value);

            if ($rows === null) {
                $errors['answers.'.$key] = [$question->type === QuestionType::Scale
                    ? __('ratings-and-reviews::messages.question_scale', ['max' => ReviewQuestion::scaleMax()])
                    : __('ratings-and-reviews::messages.question_option')];

                continue;
            }

            $answers[$question->id] = $rows;
        }

        foreach ($questions as $question) {
            if ($question->is_required && ! array_key_exists($question->id, $answers) && ! isset($errors['answers.'.$question->key])) {
                $errors['answers.'.$question->key] = [__('ratings-and-reviews::messages.question_required')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    /**
     * Replaces the review's answers (inside the caller's transaction).
     *
     * @param  array<int, list<array{option_key: string, value: int|null}>>  $answers
     */
    public function sync(Review $review, array $answers): void
    {
        ReviewAnswer::query()->where('review_id', $review->id)->delete();

        $now = now();
        $rows = [];

        foreach ($answers as $questionId => $options) {
            foreach ($options as $option) {
                $rows[] = ['review_id' => $review->id, 'question_id' => $questionId, 'option_key' => $option['option_key'], 'value' => $option['value'], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            ReviewAnswer::query()->insert($rows);
        }
    }

    /**
     * The review's answers for display (relation `answers.question` loaded), in the order of
     * the questions. Answers to questions that no longer exist are left out.
     *
     * @return list<array{key: string, label: string, type: string, value: int|string|list<string>, display: string, scale_max: int|null}>
     */
    public function presentAnswers(Review $review): array
    {
        if (! $review->relationLoaded('answers')) {
            return [];
        }

        $grouped = [];

        foreach ($review->answers as $answer) {
            $question = $answer->relationLoaded('question') ? $answer->question : null;

            if (! $question instanceof ReviewQuestion) {
                continue;
            }

            $grouped[$question->id] ??= ['question' => $question, 'rows' => []];
            $grouped[$question->id]['rows'][] = $answer;
        }

        uasort($grouped, fn (array $a, array $b): int => [$a['question']->sort_order, $a['question']->id] <=> [$b['question']->sort_order, $b['question']->id]);

        $list = [];

        foreach ($grouped as ['question' => $question, 'rows' => $rows]) {
            if ($question->type === QuestionType::Scale) {
                $value = (int) ($rows[0]->value ?? 0);
                $display = (string) $value;
            } else {
                $values = array_values(array_map(fn (ReviewAnswer $row): string => $row->option_key, $rows));
                $order = array_flip($question->optionValues());
                usort($values, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
                $value = $question->type === QuestionType::Choice ? ($values[0] ?? '') : $values;
                $display = implode(', ', array_map(fn (string $option): string => $question->optionLabel($option), $values));
            }

            $list[] = [
                'key' => $question->key,
                'label' => ReviewQuestion::localized($question->label),
                'type' => $question->type->value,
                'value' => $value,
                'display' => $display,
                'scale_max' => $question->type === QuestionType::Scale ? ReviewQuestion::scaleMax() : null,
            ];
        }

        return $list;
    }

    /**
     * @param  array{scope: string, category_key?: string|null, key: string, type: string, label: array<string, string>, low_label?: array<string, string>|null, high_label?: array<string, string>|null, options?: list<array{value: string, label: array<string, string>}>|null, is_required?: bool, is_active?: bool, sort_order?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): ReviewQuestion
    {
        $type = QuestionType::from($data['type']);
        $this->assertOptions($type, $data['options'] ?? null);

        $this->effective = [];

        try {
            return DB::transaction(function () use ($data, $type): ReviewQuestion {
                // The unique index does not cover a null category (MySQL), so the scope's rows are
                // locked and the key checked first.
                $taken = ReviewQuestion::query()->where('scope', $data['scope'])->lockForUpdate()->get(['id', 'category_key', 'key'])
                    ->contains(fn (ReviewQuestion $row): bool => $row->key === $data['key'] && $row->category_key === ($data['category_key'] ?? null));

                if ($taken) {
                    throw ValidationException::withMessages(['key' => __('ratings-and-reviews::messages.question_key_taken')]);
                }

                return ReviewQuestion::query()->create([
                    'scope' => $data['scope'],
                    'category_key' => $data['category_key'] ?? null,
                    'key' => $data['key'],
                    'type' => $type,
                    'label' => $data['label'],
                    'low_label' => $type === QuestionType::Scale ? ($data['low_label'] ?? null) : null,
                    'high_label' => $type === QuestionType::Scale ? ($data['high_label'] ?? null) : null,
                    'options' => $type->hasOptions() ? array_values($data['options'] ?? []) : null,
                    'is_required' => (bool) ($data['is_required'] ?? false),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'sort_order' => $data['sort_order'] ?? ((int) ReviewQuestion::query()->where('scope', $data['scope'])->where('category_key', $data['category_key'] ?? null)->max('sort_order') + 1),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => __('ratings-and-reviews::messages.question_key_taken')]);
        }
    }

    /**
     * Relabels, reorders, (de)activates or changes the options of a question. The type, scope,
     * category and key stay (the stored answers depend on them). Removing an option deletes
     * its answers; a change that alters which answers count refreshes the aggregates.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(ReviewQuestion $question, array $data): ReviewQuestion
    {
        if (array_key_exists('options', $data)) {
            $this->assertOptions($question->type, $data['options']);
        }

        return DB::transaction(function () use ($question, $data): ReviewQuestion {
            $before = ['is_active' => $question->is_active, 'options' => $question->optionValues()];
            $fields = ['label', 'low_label', 'high_label', 'is_required', 'is_active', 'sort_order'];

            if ($question->type->hasOptions()) {
                $fields[] = 'options';
            }

            $question->fill(array_intersect_key($data, array_flip($fields)))->save();

            $removed = array_values(array_diff($before['options'], $question->optionValues()));

            if ($removed !== []) {
                ReviewAnswer::query()->where('question_id', $question->id)->whereIn('option_key', $removed)->delete();
            }

            if ($removed !== [] || $before['is_active'] !== $question->is_active) {
                $this->refreshSubjectsAnswering($question->id);
            }

            $this->effective = [];

            return $question;
        });
    }

    /**
     * Deletes a question with its answers; the aggregates of the subjects that had answers are
     * recomputed.
     *
     * @return int How many reviews had answered it.
     */
    public function delete(ReviewQuestion $question): int
    {
        return DB::transaction(function () use ($question): int {
            $count = ReviewAnswer::query()->where('question_id', $question->id)->distinct()->count('review_id');
            $this->refreshSubjectsAnswering($question->id);
            $question->delete();
            $this->effective = [];

            return $count;
        });
    }

    /**
     * The answer rows of one value, or null when it is invalid for the question.
     *
     * @return list<array{option_key: string, value: int|null}>|null
     */
    private function rows(ReviewQuestion $question, mixed $value): ?array
    {
        if ($question->type === QuestionType::Scale) {
            $number = filter_var($value, FILTER_VALIDATE_INT);

            return $number === false || $number < 1 || $number > ReviewQuestion::scaleMax() ? null : [['option_key' => '', 'value' => (int) $number]];
        }

        $allowed = $question->optionValues();
        $values = $question->type === QuestionType::Choice ? [$value] : (is_array($value) ? array_values($value) : null);

        if ($values === null || $values === [] || count($values) !== count(array_unique(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $values)))) {
            return null;
        }

        $rows = [];

        foreach ($values as $item) {
            $item = is_scalar($item) ? (string) $item : null;

            if ($item === null || ! in_array($item, $allowed, true)) {
                return null;
            }

            $rows[] = ['option_key' => $item, 'value' => null];
        }

        return $rows;
    }

    /**
     * @throws ValidationException
     */
    private function assertOptions(QuestionType $type, mixed $options): void
    {
        if (! $type->hasOptions()) {
            return;
        }

        $values = array_map(fn (mixed $option): mixed => is_array($option) ? ($option['value'] ?? null) : null, is_array($options) ? $options : []);
        $max = max(2, (int) config('ratings-and-reviews.review_questions.max_options', 10));

        if (count($values) < 2 || count($values) > $max || count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages(['options' => __('ratings-and-reviews::messages.question_options_invalid', ['max' => $max])]);
        }
    }

    private function refreshSubjectsAnswering(int $questionId): void
    {
        $subjects = Review::query()->withErasureWithheld()
            ->whereIn('id', ReviewAnswer::query()->where('question_id', $questionId)->select('review_id'))
            ->toBase()
            ->select(['reviewable_type', 'reviewable_id'])
            ->distinct()
            ->orderBy('reviewable_type')
            ->orderBy('reviewable_id')
            ->cursor();

        foreach ($subjects as $row) {
            RefreshRatingAggregates::dispatch((string) $row->reviewable_type, (string) $row->reviewable_id);
        }
    }
}
