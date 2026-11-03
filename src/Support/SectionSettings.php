<?php

namespace RatingsAndReviews\Support;

/**
 * The effective settings of one section: the config defaults, the per-section config and
 * what an admin saved, merged and normalised. Immutable.
 */
final readonly class SectionSettings
{
    /**
     * @param  list<EditorTool>  $editorTools
     * @param  list<VoteDimension>  $voteDimensions
     * @param  array<string, int>  $trustLevels
     */
    public function __construct(
        public string $section,
        public bool $enabled,
        public int $maxDepth,
        public array $editorTools,
        public array $voteDimensions,
        public bool $guestVotes,
        public DiscussionSort $defaultSort,
        public bool $collapseLowQuality,
        public int $collapseThreshold,
        public bool $highlightTopComment,
        public int $topCommentMinVotes,
        public int $minLength,
        public int $maxLength,
        public ?int $editWindowMinutes,
        public array $trustLevels,
        public int $slowModeSeconds,
        public int $digestThreshold,
        public int $undoWindowSeconds,
        public int $reviewScale,
        public bool $reviewExtendedFields,
        public int $reviewMinLength,
        public bool $reviewRequiresApproval,
        public bool $standaloneTopicRatings = false,
        public bool $reviewsEnabled = true,
        public int $minReviewsForAverage = 0,
        public int $reviewFollowUpAfterDays = 0,
        public int $reviewFollowUpWindowDays = 150,
    ) {}

    /**
     * Normalises raw values: unknown tools and dimensions are dropped, numbers are clamped
     * to their valid range and an unknown sort or scale falls back to the default.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(string $section, array $values): self
    {
        $depthLimit = max(0, (int) config('ratings-and-reviews.max_depth_limit', 10));
        $scales = array_map('intval', (array) config('ratings-and-reviews.reviews.allowed_scales', [5, 10]));
        $scale = (int) ($values['review_scale'] ?? 5);
        $editWindow = $values['edit_window_minutes'] ?? null;

        return new self(
            section: $section,
            enabled: (bool) ($values['enabled'] ?? true),
            maxDepth: max(0, min($depthLimit, (int) ($values['max_depth'] ?? 5))),
            editorTools: self::enumList(EditorTool::class, $values['editor_tools'] ?? []),
            voteDimensions: self::enumList(VoteDimension::class, $values['vote_dimensions'] ?? []),
            guestVotes: (bool) ($values['guest_votes'] ?? false),
            defaultSort: DiscussionSort::tryFrom((string) ($values['default_sort'] ?? '')) ?? DiscussionSort::Helpful,
            collapseLowQuality: (bool) ($values['collapse_low_quality'] ?? true),
            collapseThreshold: max(1, (int) ($values['collapse_threshold'] ?? 3)),
            highlightTopComment: (bool) ($values['highlight_top_comment'] ?? true),
            topCommentMinVotes: max(1, (int) ($values['top_comment_min_votes'] ?? 3)),
            minLength: max(1, (int) ($values['min_length'] ?? 2)),
            maxLength: max(1, (int) ($values['max_length'] ?? 10000)),
            editWindowMinutes: $editWindow === null ? null : max(0, (int) $editWindow),
            trustLevels: array_map(fn (mixed $value): int => max(0, (int) $value), array_filter(
                (array) ($values['trust_levels'] ?? []),
                fn (mixed $value, mixed $key): bool => is_string($key) && is_numeric($value),
                ARRAY_FILTER_USE_BOTH,
            )),
            slowModeSeconds: max(0, (int) ($values['slow_mode_seconds'] ?? 0)),
            digestThreshold: max(0, (int) ($values['digest_threshold'] ?? 30)),
            undoWindowSeconds: max(0, (int) ($values['undo_window_seconds'] ?? 0)),
            reviewScale: in_array($scale, $scales, true) ? $scale : ($scales[0] ?? 5),
            reviewExtendedFields: (bool) ($values['review_extended_fields'] ?? true),
            reviewMinLength: max(0, (int) ($values['review_min_length'] ?? 0)),
            reviewRequiresApproval: (bool) ($values['review_requires_approval'] ?? false),
            standaloneTopicRatings: (bool) ($values['standalone_topic_ratings'] ?? false),
            reviewsEnabled: (bool) ($values['reviews_enabled'] ?? true),
            minReviewsForAverage: max(0, min(1000, (int) ($values['min_reviews_for_average'] ?? 0))),
            reviewFollowUpAfterDays: max(0, min(3650, (int) ($values['review_follow_up_after_days'] ?? 0))),
            reviewFollowUpWindowDays: max(1, min(3650, (int) ($values['review_follow_up_window_days'] ?? 150))),
        );
    }

    /**
     * A copy with other values for some keys (as in toArray()), normalised again.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): self
    {
        return self::fromArray($this->section, array_replace($this->toArray(), $values));
    }

    public function allowsTool(EditorTool $tool): bool
    {
        return in_array($tool, $this->editorTools, true);
    }

    public function allowsDimension(VoteDimension $dimension): bool
    {
        return in_array($dimension, $this->voteDimensions, true);
    }

    /** Whether a reply directly beneath a comment at this depth stays at its own level (top level is 0). */
    public function allowsReplyAt(int $depth): bool
    {
        return $depth < $this->maxDepth;
    }

    /** Whether a comment with this helpfulness balance is shown folded. */
    public function shouldCollapse(int $helpfulCount, int $unhelpfulCount): bool
    {
        return $this->collapseLowQuality && ($helpfulCount - $unhelpfulCount) <= -$this->collapseThreshold;
    }

    /**
     * A stable signature of the sanitizer-relevant settings, for caching built sanitizers.
     */
    public function toolSignature(): string
    {
        $tools = $this->toolKeys();
        sort($tools);

        return implode(',', $tools);
    }

    /**
     * @return list<string>
     */
    public function toolKeys(): array
    {
        return array_map(fn (EditorTool $tool): string => $tool->value, $this->editorTools);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_depth' => $this->maxDepth,
            'editor_tools' => $this->toolKeys(),
            'vote_dimensions' => array_map(fn (VoteDimension $dimension): string => $dimension->value, $this->voteDimensions),
            'guest_votes' => $this->guestVotes,
            'default_sort' => $this->defaultSort->value,
            'collapse_low_quality' => $this->collapseLowQuality,
            'collapse_threshold' => $this->collapseThreshold,
            'highlight_top_comment' => $this->highlightTopComment,
            'top_comment_min_votes' => $this->topCommentMinVotes,
            'min_length' => $this->minLength,
            'max_length' => $this->maxLength,
            'edit_window_minutes' => $this->editWindowMinutes,
            'trust_levels' => $this->trustLevels,
            'slow_mode_seconds' => $this->slowModeSeconds,
            'digest_threshold' => $this->digestThreshold,
            'undo_window_seconds' => $this->undoWindowSeconds,
            'review_scale' => $this->reviewScale,
            'review_extended_fields' => $this->reviewExtendedFields,
            'review_min_length' => $this->reviewMinLength,
            'review_requires_approval' => $this->reviewRequiresApproval,
            'standalone_topic_ratings' => $this->standaloneTopicRatings,
            'reviews_enabled' => $this->reviewsEnabled,
            'min_reviews_for_average' => $this->minReviewsForAverage,
            'review_follow_up_after_days' => $this->reviewFollowUpAfterDays,
            'review_follow_up_window_days' => $this->reviewFollowUpWindowDays,
        ];
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>
     */
    private static function enumList(string $enum, mixed $values): array
    {
        $cases = [];

        foreach ((array) $values as $value) {
            $case = is_string($value) ? $enum::tryFrom($value) : null;

            if ($case !== null && ! in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }
}
