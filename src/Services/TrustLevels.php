<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\EditorTool;
use RatingsAndReviews\Support\SectionSettings;
use RatingsAndReviews\Support\TrustProfile;

/**
 * Trust levels: a section's `trust_levels` map a tool to the number of approved
 * contributions (ContributionCounter) a member needs before it unlocks.
 *
 * - An editor tool key (`link`, `image`, `sticker`, `product_card`, …) removes that tool
 *   from the member's settings, so the sanitizer unwraps its markup.
 * - `deep_reply` caps the member's depth at 1: deeper replies are flattened onto the first
 *   reply level by the normal placement, with `in_reply_to_id` kept.
 * - `poll` refuses a poll attached to a comment.
 *
 * Moderators are exempt. The product card tool is also removed while the host has no
 * CardResolver. The count is only asked when a threshold above 0 applies; it is not
 * memoised, because this service can live longer than one request (long-running workers).
 */
class TrustLevels
{
    public function __construct(
        private readonly ContributionCounter $counter,
        private readonly AbilityResolver $abilities,
        private readonly CardResolver $cards,
    ) {}

    public function for(?Authenticatable $user, SectionSettings $settings): TrustProfile
    {
        $tools = $settings->editorTools;

        if (! $this->cards->isEnabled()) {
            $tools = array_values(array_filter($tools, fn (EditorTool $tool): bool => $tool !== EditorTool::ProductCard));
        }

        $exempt = $user !== null && $this->abilities->allows($user, Ability::MODERATE);
        $thresholds = array_filter($settings->trustLevels, fn (int $required): bool => $required > 0);
        $contributions = null;
        $locked = [];

        if (! $exempt && $thresholds !== []) {
            $contributions = $user === null ? 0 : $this->counter->approvedContributions((int) $user->getAuthIdentifier());

            foreach ($thresholds as $key => $required) {
                if ($contributions < $required) {
                    $locked[$key] = $required;
                }
            }
        }

        $tools = array_values(array_filter($tools, fn (EditorTool $tool): bool => ! array_key_exists($tool->value, $locked)));
        $effective = $settings->with(['editor_tools' => array_map(fn (EditorTool $tool): string => $tool->value, $tools)]);

        if (array_key_exists(TrustProfile::DEEP_REPLY, $locked)) {
            $effective = $effective->with(['max_depth' => min(1, $settings->maxDepth)]);
        }

        return new TrustProfile($effective, $contributions, $locked, $exempt);
    }
}
