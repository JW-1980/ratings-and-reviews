<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Concerns\HasComments;
use RatingsAndReviews\Concerns\HasReviews;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\Reviewable;

/**
 * A host model as an application would write it.
 *
 * @property int $id
 * @property string $name
 * @property string $section
 * @property string $scope
 * @property array<int, string>|null $category_path
 * @property bool $is_open
 * @property bool $is_hidden
 */
class Product extends Model implements Commentable, Reviewable
{
    use HasComments;
    use HasReviews;

    protected $table = 'products';

    protected $guarded = [];

    protected $attributes = [
        'section' => 'product',
        'scope' => 'product',
        'is_open' => true,
        'is_hidden' => false,
    ];

    protected function casts(): array
    {
        return ['category_path' => 'array', 'is_open' => 'boolean', 'is_hidden' => 'boolean'];
    }

    public function reviewSectionKey(): string
    {
        return $this->section;
    }

    public function acceptsReviews(): bool
    {
        return $this->is_open;
    }

    public function reviewsVisibleTo(?Authenticatable $viewer): bool
    {
        return ! $this->is_hidden;
    }

    public function reviewSubjectTitle(): string
    {
        return $this->name;
    }

    public function reviewSubjectUrl(): ?string
    {
        return '/products/'.$this->id;
    }

    public function ratingScope(): string
    {
        return $this->scope;
    }

    public function ratingCategoryPath(): array
    {
        return array_values($this->category_path ?? []);
    }

    public function commentSectionKey(): string
    {
        return $this->section;
    }

    public function acceptsComments(): bool
    {
        return $this->is_open;
    }

    public function commentsVisibleTo(?Authenticatable $viewer): bool
    {
        return ! $this->is_hidden;
    }

    public function discussionTitle(): string
    {
        return $this->name;
    }

    public function discussionUrl(): ?string
    {
        return '/products/'.$this->id;
    }
}
