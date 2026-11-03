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
        foreach (['messages', 'enums', 'validation', 'ui'] as $file) {
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

    public function test_every_literal_key_used_in_php_and_blade_exists_in_both_languages(): void
    {
        $files = [
            ...new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src', \FilesystemIterator::SKIP_DOTS)),
            ...new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../resources/views', \FilesystemIterator::SKIP_DOTS)),
        ];
        $used = [];

        foreach ($files as $file) {
            if (str_ends_with((string) $file, '.php')) {
                preg_match_all("/ratings-and-reviews::((?:messages|ui|enums|validation)(?:\\.[a-z0-9_]+)+)(?=['\"])/", (string) file_get_contents((string) $file), $matches);
                $used = [...$used, ...$matches[1]];
            }
        }

        $used = array_values(array_unique($used));
        $this->assertGreaterThan(150, count($used));

        foreach (['en', 'nl'] as $locale) {
            $missing = array_values(array_filter($used, fn (string $key): bool => ! trans()->has('ratings-and-reviews::'.$key, $locale, false)));
            $this->assertSame([], $missing, "Missing {$locale} lines.");
        }
    }
}
