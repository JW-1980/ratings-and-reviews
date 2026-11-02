<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveReportRequest extends PackageRequest
{
    private ?Report $report = null;

    protected function ability(): ?string
    {
        return Ability::MODERATE;
    }

    /** The report named by `{report}` (404 otherwise); rr.moderate is checked on it. */
    protected function abilitySubject(): mixed
    {
        return $this->report();
    }

    public function report(): Report
    {
        $id = (string) $this->route('report');

        return $this->report ??= (ctype_digit($id) ? Report::query()->find((int) $id) : null) ?? throw new NotFoundHttpException;
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
