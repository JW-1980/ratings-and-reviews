<?php

namespace RatingsAndReviews\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        $text = $this->faker->sentence(10);

        return [
            'section' => 'product',
            'user_id' => $this->faker->unique()->numberBetween(100000, 999999),
            'status' => ContentStatus::Approved,
            'depth' => 0,
            'body_html' => '<p>'.$text.'</p>',
            'body_text' => $text,
            'published_at' => now(),
        ];
    }

    public function forSubject(Model $subject): static
    {
        return $this->state(['commentable_type' => $subject->getMorphClass(), 'commentable_id' => $subject->getKey()]);
    }

    /** A reply directly beneath the given comment. */
    public function replyTo(Comment $parent): static
    {
        return $this->state([
            'commentable_type' => $parent->commentable_type,
            'commentable_id' => $parent->commentable_id,
            'section' => $parent->section,
            'parent_id' => $parent->id,
            'root_id' => $parent->root_id ?? $parent->id,
            'depth' => $parent->depth + 1,
        ]);
    }

    public function status(ContentStatus $status): static
    {
        return $this->state(['status' => $status, 'published_at' => $status === ContentStatus::Approved ? now() : null]);
    }
}
