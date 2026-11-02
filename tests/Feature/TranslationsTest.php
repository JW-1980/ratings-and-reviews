<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Services\ReviewFacets;
use RatingsAndReviews\Services\ReviewQualityMeter;
use RatingsAndReviews\Support\Comparison;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\DiscussionSort;
use RatingsAndReviews\Support\EditorTool;
use RatingsAndReviews\Support\VoteDimension;
use RatingsAndReviews\Tests\TestCase;

class TranslationsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function keys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            $keys = is_array($value) ? [...$keys, ...$this->keys($value, $prefix.$key.'.')] : [...$keys, $prefix.$key];
        }

        return $keys;
    }

    public function test_dutch_and_english_have_the_same_non_empty_lines(): void
    {
        foreach (['messages', 'enums', 'validation'] as $file) {
            $en = require __DIR__."/../../lang/en/{$file}.php";
            $nl = require __DIR__."/../../lang/nl/{$file}.php";

            $this->assertSame($this->keys($en), $this->keys($nl), "lang/{$file}.php differs between en and nl.");

            foreach ([$en, $nl] as $lines) {
                array_walk_recursive($lines, fn ($line) => $this->assertNotSame('', trim((string) $line)));
            }
        }
    }

    public function test_every_enum_case_and_configured_report_reason_has_a_label_in_both_languages(): void
    {
        foreach (['en', 'nl'] as $locale) {
            $this->app->setLocale($locale);

            foreach ([...ContentStatus::cases(), ...VoteDimension::cases(), ...EditorTool::cases(), ...DiscussionSort::cases()] as $case) {
                $this->assertStringNotContainsString('ratings-and-reviews::', $case->label(), $case::class.'::'.$case->name." has no {$locale} label.");
            }

            foreach ((array) config('ratings-and-reviews.reports.reasons') as $reason) {
                $this->assertStringNotContainsString('ratings-and-reviews::', (string) __('ratings-and-reviews::enums.report_reason.'.$reason));
            }
        }

        $this->app->setLocale('nl');
        $this->assertSame('Bron nodig', VoteDimension::NeedsSource->label());
    }

    public function test_every_configured_facet_quality_rule_and_comparison_has_a_label_in_both_languages(): void
    {
        foreach (['en', 'nl'] as $locale) {
            $this->app->setLocale($locale);

            foreach ($this->app->make(ReviewFacets::class)->groups() as $group => $values) {
                foreach (['label', ...$values] as $key) {
                    $this->assertTrue(trans()->has("ratings-and-reviews::enums.facet.{$group}.{$key}"), "Facet {$group}.{$key} has no {$locale} label.");
                }
            }

            foreach ($this->app->make(ReviewQualityMeter::class)->configuredRules() as $rule) {
                $this->assertTrue(trans()->has('ratings-and-reviews::messages.quality_hints.'.$rule['key']), "Quality rule {$rule['key']} has no {$locale} hint.");
            }

            foreach (Comparison::cases() as $case) {
                $this->assertStringNotContainsString('ratings-and-reviews::', $case->label());
            }
        }
    }
}
