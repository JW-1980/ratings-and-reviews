<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\AuthorProfile;
use RatingsAndReviews\Support\SqlDialect;

/**
 * Full-text search within the reviews and the discussion of one subject (MySQL FULLTEXT in
 * boolean mode over rr_reviews(title, body_text) and rr_comments(body_text)).
 *
 * Safe query parsing: the user's input is never passed to MATCH … AGAINST as typed. It is
 * cut to `search.max_query_length`, "quoted phrases" are taken out, and the rest is split
 * into words of letters and digits. Every word of at least `search.min_term_length`
 * characters becomes a required prefix term (`+word*`); a phrase whose words are all long
 * enough becomes a required phrase (`+"two words"`), otherwise its long words become terms.
 * At most `search.max_terms` terms and phrases are used. Only public content is searched:
 * approved reviews, published comments that were not removed.
 *
 * Note: InnoDB full-text indexes only see committed rows. On engines without FULLTEXT
 * (SQLite, PostgreSQL in a host's test suite) the same terms are matched with LIKE instead.
 */
class SearchService
{
    public function __construct(
        private readonly SearchHighlighter $highlighter,
        private readonly AuthorResolver $authors,
    ) {}

    /**
     * @return array{terms: list<string>, phrases: list<string>, boolean: string}
     *
     * @throws ValidationException when nothing searchable is left
     */
    public function parse(string $query): array
    {
        $min = max(1, (int) config('ratings-and-reviews.search.min_term_length', 3));
        $maxTerms = max(1, (int) config('ratings-and-reviews.search.max_terms', 8));
        $query = mb_substr($query, 0, max(1, (int) config('ratings-and-reviews.search.max_query_length', 200)));

        $terms = [];
        $phrases = [];

        $rest = (string) preg_replace_callback('/"([^"]*)"/u', function (array $match) use (&$terms, &$phrases, $min): string {
            $words = $this->words($match[1]);
            $long = array_values(array_filter($words, fn (string $word): bool => mb_strlen($word) >= $min));

            if (count($words) >= 2 && count($long) === count($words)) {
                $phrases[] = implode(' ', $words);
            } else {
                array_push($terms, ...$long);
            }

            return ' ';
        }, $query);

        foreach ($this->words($rest) as $word) {
            if (mb_strlen($word) >= $min) {
                $terms[] = $word;
            }
        }

        $phrases = array_values(array_unique($phrases));
        $terms = array_values(array_unique($terms));
        $phrases = array_slice($phrases, 0, $maxTerms);
        $terms = array_slice($terms, 0, max(0, $maxTerms - count($phrases)));

        if ($terms === [] && $phrases === []) {
            throw ValidationException::withMessages(['q' => __('ratings-and-reviews::messages.search_too_short', ['min' => $min])]);
        }

        $boolean = implode(' ', [
            ...array_map(fn (string $phrase): string => '+"'.$phrase.'"', $phrases),
            ...array_map(fn (string $term): string => '+'.$term.'*', $terms),
        ]);

        return ['terms' => $terms, 'phrases' => $phrases, 'boolean' => $boolean];
    }

    /**
     * @param  list<int>  $hiddenAuthors  members whose reviews and comments are left out (blocked, HidesBlockedMembers)
     * @return array{reviews: list<array<string, mixed>>, comments: list<array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function search(Model $subject, string $query, bool $reviews, bool $comments, array $hiddenAuthors = []): array
    {
        $parsed = $this->parse($query);
        $limit = max(1, (int) config('ratings-and-reviews.search.results_per_type', 20));

        $reviewRows = collect();
        $reviewTotal = 0;

        if ($reviews) {
            $base = $this->matching(Review::query()->forSubject($subject)->approved()->when($hiddenAuthors !== [], fn (Builder $query) => $query->whereNotIn('user_id', $hiddenAuthors)), ['title', 'body_text'], $parsed);
            $reviewTotal = (clone $base)->count();
            $reviewRows = $reviewTotal === 0 ? collect() : $this->withRelevance($base, ['title', 'body_text'], $parsed)
                ->orderByDesc('relevance')->orderByDesc('helpful_count')->orderByDesc('id')
                ->limit($limit)->get();
        }

        $commentRows = collect();
        $commentTotal = 0;

        if ($comments) {
            $base = $this->matching(Comment::query()->forSubject($subject)->published()->withContent()->withoutAuthors($hiddenAuthors), ['body_text'], $parsed);
            $commentTotal = (clone $base)->count();
            $commentRows = $commentTotal === 0 ? collect() : $this->withRelevance($base, ['body_text'], $parsed)
                ->orderByDesc('relevance')->orderByDesc('helpful_score')->orderByDesc('id')
                ->limit($limit)->get();
        }

        $profiles = $this->authors->resolve($reviewRows->pluck('user_id')->merge($commentRows->pluck('user_id'))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());
        $length = max(20, (int) config('ratings-and-reviews.search.snippet_length', 180));

        return [
            'reviews' => $this->presentReviews($reviewRows, $parsed, $profiles, $length),
            'comments' => $commentRows->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'root_id' => $comment->root_id ?? $comment->id,
                'parent_id' => $comment->parent_id,
                'snippet_html' => $this->highlighter->snippet($comment->body_text, $parsed['terms'], $parsed['phrases'], $length),
                'author' => ($profiles[$comment->user_id] ?? null)?->toArray(),
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'query' => $query,
                'terms' => $parsed['terms'],
                'phrases' => $parsed['phrases'],
                'total_reviews' => $reviewTotal,
                'total_comments' => $commentTotal,
                'limit_per_type' => $limit,
            ],
        ];
    }

    /**
     * Narrows the query to rows matching every term and phrase: MATCH … AGAINST in boolean
     * mode on MySQL (the FULLTEXT indexes), a portable LIKE per term on other engines (no
     * FULLTEXT index there; slower and without prefix-only matching, but the same results for
     * whole words). The columns are package constants, never user input.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  array{terms: list<string>, phrases: list<string>, boolean: string}  $parsed
     * @return Builder<TModel>
     */
    private function matching(Builder $query, array $columns, array $parsed): Builder
    {
        if (SqlDialect::isMysql($query->getConnection())) {
            return $query->whereRaw('MATCH('.$this->columnList($columns).') AGAINST (? IN BOOLEAN MODE)', [$parsed['boolean']]);
        }

        foreach ([...$parsed['terms'], ...$parsed['phrases']] as $needle) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle)).'%';
            $query->where(function (Builder $any) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $any->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '\\'", [$pattern]);
                }
            });
        }

        return $query;
    }

    /**
     * Selects the rows with a `relevance` column: the FULLTEXT score on MySQL, 0 elsewhere
     * (the secondary orderings decide).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  array{terms: list<string>, phrases: list<string>, boolean: string}  $parsed
     * @return Builder<TModel>
     */
    private function withRelevance(Builder $query, array $columns, array $parsed): Builder
    {
        $query->select('*');

        return SqlDialect::isMysql($query->getConnection())
            ? $query->selectRaw('MATCH('.$this->columnList($columns).') AGAINST (? IN BOOLEAN MODE) as relevance', [$parsed['boolean']])
            : $query->selectRaw('0 as relevance');
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns));
    }

    /**
     * @param  Collection<int, Review>  $rows
     * @param  array{terms: list<string>, phrases: list<string>, boolean: string}  $parsed
     * @param  array<int, AuthorProfile>  $profiles
     * @return list<array<string, mixed>>
     */
    private function presentReviews(Collection $rows, array $parsed, array $profiles, int $length): array
    {
        return $rows->map(fn (Review $review): array => [
            'id' => $review->id,
            'rating' => $review->rating,
            'scale_max' => $review->scale_max,
            'title_html' => $review->title === null ? null : $this->highlighter->highlight($review->title, $parsed['terms'], $parsed['phrases']),
            'snippet_html' => $this->highlighter->snippet($review->body_text, $parsed['terms'], $parsed['phrases'], $length),
            'author' => ($profiles[$review->user_id] ?? null)?->toArray(),
            'created_at' => $review->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Lower-cased words of letters and digits.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }
}
