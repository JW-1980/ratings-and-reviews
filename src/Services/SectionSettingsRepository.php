<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use RatingsAndReviews\Models\SectionSetting;
use RatingsAndReviews\Support\SectionSettings;

/**
 * The effective settings per section: `section_defaults`, overlaid with `sections.<name>`
 * from the config, overlaid with the admin's saved row. The merged values are cached and
 * the cache is cleared when an admin saves.
 */
class SectionSettingsRepository
{
    /** @var array<string, SectionSettings> */
    private array $resolved = [];

    public function __construct(private readonly Cache $cache) {}

    public function for(string $section): SectionSettings
    {
        return $this->resolved[$section] ??= SectionSettings::fromArray($section, $this->cache->remember(
            $this->cacheKey($section),
            max(1, (int) config('ratings-and-reviews.settings_cache_seconds', 3600)),
            fn (): array => $this->merged($section),
        ));
    }

    /**
     * The sections an admin can manage: those listed under `sections` in the config, plus
     * those that already have a saved row.
     *
     * @return list<string>
     */
    public function knownSections(): array
    {
        $configured = array_keys((array) config('ratings-and-reviews.sections', []));
        $saved = SectionSetting::query()->orderBy('section')->pluck('section')->all();
        $sections = array_values(array_unique(array_map('strval', [...$configured, ...$saved])));
        sort($sections);

        return $sections;
    }

    public function isKnown(string $section): bool
    {
        return in_array($section, $this->knownSections(), true);
    }

    /**
     * Saves the given keys (already validated) over the current row. Unknown keys are ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(string $section, array $values, int|string|null $updatedBy): SectionSettings
    {
        $known = array_keys((array) config('ratings-and-reviews.section_defaults', []));
        $values = array_intersect_key($values, array_flip($known));

        $row = SectionSetting::query()->firstOrNew(['section' => $section]);
        $row->settings = array_replace((array) ($row->settings ?? []), $values);
        $row->updated_by = $updatedBy === null ? null : (int) $updatedBy;
        $row->save();

        $this->forget($section);

        return $this->for($section);
    }

    public function forget(string $section): void
    {
        unset($this->resolved[$section]);
        $this->cache->forget($this->cacheKey($section));
    }

    /**
     * @return array<string, mixed>
     */
    private function merged(string $section): array
    {
        $saved = SectionSetting::query()->where('section', $section)->value('settings');
        $saved = is_string($saved) ? (array) json_decode($saved, true) : (array) ($saved ?? []);

        return array_replace(
            (array) config('ratings-and-reviews.section_defaults', []),
            (array) config('ratings-and-reviews.sections.'.$section, []),
            $saved,
        );
    }

    private function cacheKey(string $section): string
    {
        return 'rr:section-settings:'.$section;
    }
}
