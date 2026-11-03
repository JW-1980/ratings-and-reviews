<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\UpdateSectionRequest;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\DiscussionSort;
use RatingsAndReviews\Support\EditorTool;
use RatingsAndReviews\Support\SectionScope;
use RatingsAndReviews\Support\VoteDimension;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SectionController extends Controller
{
    public function __construct(private readonly SectionSettingsRepository $settings) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->requireAbility($request, Ability::MANAGE_SETTINGS);
        $resolver = app(AbilityResolver::class);
        $sections = array_values(array_filter($this->settings->knownSections(), fn (string $section): bool => $resolver->allows($user, Ability::MANAGE_SETTINGS, new SectionScope($section))));

        return new JsonResponse([
            'data' => array_map(fn (string $section): array => [
                'section' => $section,
                'settings' => $this->settings->for($section)->toArray(),
            ], $sections),
            'meta' => $this->options(),
        ]);
    }

    public function show(Request $request, string $section): JsonResponse
    {
        $this->assertKnown($section);
        $this->requireAbility($request, Ability::MANAGE_SETTINGS, new SectionScope($section));

        return new JsonResponse(['data' => ['section' => $section, 'settings' => $this->settings->for($section)->toArray()], 'meta' => $this->options()]);
    }

    public function update(UpdateSectionRequest $request, string $section): JsonResponse
    {
        $this->assertKnown($section);
        $user = $this->requireUser($request);

        $settings = $this->settings->update($section, $request->validated(), $user->getAuthIdentifier());

        return new JsonResponse(['data' => ['section' => $section, 'settings' => $settings->toArray()], 'message' => __('ratings-and-reviews::messages.settings_saved')]);
    }

    private function assertKnown(string $section): void
    {
        if (! $this->settings->isKnown($section)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * The choices an admin screen offers, with translated labels.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        $list = fn (array $cases): array => array_map(fn ($case): array => ['value' => $case->value, 'label' => $case->label()], $cases);

        return [
            'editor_tools' => $list(EditorTool::cases()),
            'vote_dimensions' => $list(VoteDimension::cases()),
            'sorts' => $list(DiscussionSort::cases()),
        ];
    }
}
