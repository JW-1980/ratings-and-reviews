<?php

namespace RatingsAndReviews\Summary;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Services\ReviewQuestions;
use RatingsAndReviews\Support\QuestionType;
use RatingsAndReviews\Support\SectionSettings;

/**
 * Summary key `questions`: per review question of the subject (ReviewQuestions), how many
 * counted reviews answered it and, for a scale, the distribution and the average (from the
 * section's `min_reviews_for_average` answers); for a choice or multi question, per option
 * the count and the share of the reviews that answered.
 */
class ReviewQuestionSummary implements SummaryExtension
{
    public function __construct(private readonly ReviewQuestions $questions) {}

    public function key(): string
    {
        return 'questions';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function compute(Model&Reviewable $subject, ?RatingAggregate $aggregate, SectionSettings $settings): array
    {
        $stored = (array) ($aggregate?->question_counts ?? []);
        $minimum = max(1, $settings->minReviewsForAverage);

        return $this->questions->effectiveFor($subject)->map(function (ReviewQuestion $question) use ($stored, $minimum): array {
            $totals = (array) ($stored[(string) $question->id] ?? []);
            $answered = (int) ($totals['answered'] ?? 0);
            $base = $question->present();
            unset($base['required']);

            if ($question->type === QuestionType::Scale) {
                $values = (array) ($totals['values'] ?? []);
                $sum = 0;
                $answered = 0;
                $distribution = [];

                foreach (range(1, ReviewQuestion::scaleMax()) as $value) {
                    $count = (int) ($values[(string) $value] ?? 0);
                    $sum += $value * $count;
                    $answered += $count;
                    $distribution[] = ['value' => $value, 'count' => $count];
                }

                return $base + [
                    'count' => $answered,
                    'average' => $answered >= $minimum ? round($sum / $answered, 2) : null,
                    'distribution' => $distribution,
                ];
            }

            $options = (array) ($totals['options'] ?? []);

            return array_replace($base, [
                'count' => $answered,
                'average' => null,
                'options' => array_map(fn (array $option): array => $option + [
                    'count' => (int) ($options[$option['value']] ?? 0),
                    'share' => $answered > 0 ? round((int) ($options[$option['value']] ?? 0) / $answered, 4) : null,
                ], $base['options']),
            ]);
        })->values()->all();
    }
}
