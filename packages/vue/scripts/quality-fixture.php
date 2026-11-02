<?php

/**
 * Writes tests/fixtures/review-quality.json: the rules of ReviewQualityMeter (as served by
 * GET review-quality-rules) plus a set of drafts with the score, level and hints the PHP
 * meter gives them. The Vitest parity spec evaluates the same drafts with the JavaScript
 * meter and must get identical results.
 *
 * Run from the package root after `composer install`:
 *   php packages/vue/scripts/quality-fixture.php
 */

use Orchestra\Testbench\Foundation\Application;
use RatingsAndReviews\RatingsAndReviewsServiceProvider;
use RatingsAndReviews\Services\HtmlSanitizer;
use RatingsAndReviews\Services\ReviewQualityMeter;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

$app = Application::create(options: ['extra' => ['providers' => [RatingsAndReviewsServiceProvider::class]]]);
$app['config']->set('app.locale', 'en');

/** @var ReviewQualityMeter $meter */
$meter = $app->make(ReviewQualityMeter::class);
/** @var HtmlSanitizer $sanitizer */
$sanitizer = $app->make(HtmlSanitizer::class);

$long = str_repeat('Lekker romig en niet te zoet. ', 12);

/** Each case: the draft as the browser holds it (body is editor HTML). */
$cases = [
    ['name' => 'empty', 'body' => '', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'short body only', 'body' => '<p>Prima.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 4, 'extended_fields' => true],
    ['name' => 'length step 40', 'body' => '<p>'.str_repeat('a', 39).'</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'length step 40 reached', 'body' => '<p>'.str_repeat('a', 40).'</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'multibyte length', 'body' => '<p>'.str_repeat('é', 120).'</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'whitespace collapses', 'body' => "<p>a      b</p><p>\n\n   c</p>", 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'pros and cons', 'body' => '<p>Goed product voor elke dag, echt een aanrader.</p>', 'pros' => ['Romig'], 'cons' => ['Duur'], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'blank points do not count', 'body' => '<p>Goed.</p>', 'pros' => ['   '], 'cons' => [''], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'no extended fields', 'body' => '<p>Goed.</p>', 'pros' => ['Romig'], 'cons' => ['Duur'], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => false],
    ['name' => 'usage word', 'body' => '<p>Ik heb het gebruikt bij het ontbijt.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'usage word inside another word', 'body' => '<p>Ongebruikte verpakking.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'usage phrase uppercase', 'body' => '<p>Lekker IN KOFFIE.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'concrete digit', 'body' => '<p>Kost 2 euro.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'concrete unit word', 'body' => '<p>Een halve liter is genoeg.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
    ['name' => 'topics partly rated', 'body' => '<p>Goed.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 1, 'topics_available' => 3, 'extended_fields' => true],
    ['name' => 'topics all rated', 'body' => '<p>Goed.</p>', 'pros' => [], 'cons' => [], 'topics_rated' => 4, 'topics_available' => 4, 'extended_fields' => true],
    ['name' => 'strong review', 'body' => '<p>'.$long.'Ik heb het 3 dagen gebruikt in de thee.</p>', 'pros' => ['Romig', 'Lang houdbaar'], 'cons' => ['Prijs'], 'topics_rated' => 4, 'topics_available' => 4, 'extended_fields' => true],
    ['name' => 'formatted html', 'body' => '<p><strong>Heerlijk</strong> en <em>romig</em>.</p><ul><li>200 gram</li><li>recept</li></ul>', 'pros' => ['Smaak'], 'cons' => [], 'topics_rated' => 2, 'topics_available' => 4, 'extended_fields' => true],
    ['name' => 'english', 'body' => '<p>I tried it for breakfast with milk, 250 calories per bowl, nice and crunchy for weeks.</p>', 'pros' => ['Crunchy'], 'cons' => ['Sweet'], 'topics_rated' => 0, 'topics_available' => 0, 'extended_fields' => true],
];

$output = ['generated_by' => 'packages/vue/scripts/quality-fixture.php', 'rules' => $meter->rules(), 'cases' => []];

foreach ($cases as $case) {
    $text = $sanitizer->plainText($sanitizer->fromEditorInput($case['body']));
    $assessment = $meter->assess([
        'text' => $text,
        'pros' => count(array_filter($case['pros'], fn (string $point): bool => trim($point) !== '')),
        'cons' => count(array_filter($case['cons'], fn (string $point): bool => trim($point) !== '')),
        'topics_rated' => $case['topics_rated'],
        'topics_available' => $case['topics_available'],
        'extended_fields' => $case['extended_fields'],
    ]);

    $output['cases'][] = $case + ['expected' => ['score' => $assessment->score, 'level' => $assessment->level, 'hints' => $assessment->hints]];
}

$target = dirname(__DIR__).'/tests/fixtures/review-quality.json';
file_put_contents($target, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, "Wrote {$target} (".count($output['cases'])." cases)\n");
