<?php

namespace RatingsAndReviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RatingsAndReviews\Http\Concerns\ResolvesCurrentUser;

/**
 * Base request: validation messages and attribute names come from the package's own
 * translations (nl, en), so a host without published validation lines still shows them.
 * Authentication is checked before validation; `requiresUser()` and `ability()` decide.
 */
abstract class PackageRequest extends FormRequest
{
    use ResolvesCurrentUser;

    /** Whether a signed-in user is required. */
    protected function requiresUser(): bool
    {
        return true;
    }

    /** The package ability required, if any. */
    protected function ability(): ?string
    {
        return null;
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->ability() !== null) {
            $this->requireAbility($this, $this->ability());
        } elseif ($this->requiresUser()) {
            $this->requireUser($this);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return (array) trans('ratings-and-reviews::validation.messages');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) trans('ratings-and-reviews::validation.attributes');
    }
}
