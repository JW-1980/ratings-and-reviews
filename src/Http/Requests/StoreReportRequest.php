<?php

namespace RatingsAndReviews\Http\Requests;

use Illuminate\Validation\Rule;

class StoreReportRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reasons = (array) config('ratings-and-reviews.reports.reasons', []);

        return [
            'reportable_type' => ['required', 'string', 'max:64'],
            'reportable_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in($reasons)],
            'details' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('reason'), (array) config('ratings-and-reviews.reports.details_required_for', []), true)),
                'nullable', 'string', 'max:'.(int) config('ratings-and-reviews.reports.max_details_length', 1000),
            ],
        ];
    }
}
