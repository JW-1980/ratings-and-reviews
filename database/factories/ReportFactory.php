<?php

namespace RatingsAndReviews\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Services\ReportService;
use RatingsAndReviews\Support\ReportStatus;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'reporter_id' => $this->faker->unique()->numberBetween(100000, 999999),
            'reason' => 'spam',
            'details' => null,
            'snapshot' => [],
            'status' => ReportStatus::Open,
        ];
    }

    public function forSubject(Model $subject): static
    {
        return $this->state(['reportable_type' => $subject->getMorphClass(), 'reportable_id' => $subject->getKey(), 'section' => ReportService::sectionOf($subject)]);
    }
}
