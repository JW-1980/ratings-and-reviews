src/Services/DiscussionService.php:96:        $trust = $this->trust->for($author, $settings);
src/Services/DiscussionService.php:238:        $clean = $this->cleanBody($html, $this->trust->for($editor, $settings)->settings, $comment->user_id, $comment);
src/Services/ReviewService.php:324:        $clean = $this->sanitizer->clean($body, $this->trust->for($author, $settings)->settings, $authorId, $target);
src/Http/Controllers/ReviewController.php:118:                'editor' => $this->trust->for($viewer, $settings)->toArray(),
src/Http/Controllers/CommentController.php:69:        $trust = $this->trust->for($viewer, $discussion->settings);
