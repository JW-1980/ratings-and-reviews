<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;

class ResolveReportRequest extends PackageRequest
{
    protected function ability(): ?string
    {
        return Ability::MODERATE;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['resolved', 'dismissed'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'take_down' => ['sometimes', 'boolean'],
        ];
    }
}
