<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RatingsAndReviews\Models\CommentRevision;

/**
 * @property CommentRevision $resource
 */
class RevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revision = $this->resource;

        return [
            'revision' => $revision->revision,
            'body_html' => $revision->body_html,
            'edited_by' => $revision->edited_by,
            'edited_at' => $revision->edited_at->toIso8601String(),
        ];
    }
}
