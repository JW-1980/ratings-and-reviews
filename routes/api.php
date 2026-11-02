<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Http\Controllers\Admin\ModerationController;
use RatingsAndReviews\Http\Controllers\Admin\ReportController as AdminReportController;
use RatingsAndReviews\Http\Controllers\Admin\SectionController;
use RatingsAndReviews\Http\Controllers\Admin\TopicController;
use RatingsAndReviews\Http\Controllers\CommentController;
use RatingsAndReviews\Http\Controllers\CommentVoteController;
use RatingsAndReviews\Http\Controllers\RatingSummaryController;
use RatingsAndReviews\Http\Controllers\ReportController;
use RatingsAndReviews\Http\Controllers\ReviewController;
use RatingsAndReviews\Http\Controllers\ReviewVoteController;

/*
| The package JSON API. Prefix, name prefix and middleware come from
| `ratings-and-reviews.routes`; every write route is throttled by a named limiter.
*/

$write = ThrottleRequests::using('rr-write');
$vote = ThrottleRequests::using('rr-vote');
$report = ThrottleRequests::using('rr-report');
$admin = ThrottleRequests::using('rr-admin');

$subject = ['type' => '[A-Za-z0-9_\-.]+', 'id' => '[A-Za-z0-9\-]+'];

Route::get('subjects/{type}/{id}/reviews', [ReviewController::class, 'index'])->where($subject)->name('reviews.index');
Route::post('subjects/{type}/{id}/reviews', [ReviewController::class, 'store'])->middleware($write)->where($subject)->name('reviews.store');
Route::patch('reviews/{review}', [ReviewController::class, 'update'])->middleware($write)->name('reviews.update');
Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->middleware($write)->name('reviews.destroy');
Route::post('reviews/{review}/votes', [ReviewVoteController::class, 'store'])->middleware($vote)->name('reviews.votes.store');
Route::get('subjects/{type}/{id}/rating-summary', [RatingSummaryController::class, 'show'])->where($subject)->name('rating-summary.show');

Route::get('subjects/{type}/{id}/comments', [CommentController::class, 'index'])->where($subject)->name('comments.index');
Route::post('subjects/{type}/{id}/comments', [CommentController::class, 'store'])->middleware($write)->where($subject)->name('comments.store');
Route::patch('comments/{comment}', [CommentController::class, 'update'])->middleware($write)->name('comments.update');
Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->middleware($write)->name('comments.destroy');
Route::get('comments/{comment}/revisions', [CommentController::class, 'revisions'])->name('comments.revisions');
Route::post('comments/{comment}/votes', [CommentVoteController::class, 'store'])->middleware($vote)->name('comments.votes.store');

Route::post('reports', [ReportController::class, 'store'])->middleware($report)->name('reports.store');

Route::prefix('admin')
    ->name('admin.')
    ->middleware((array) config('ratings-and-reviews.routes.admin_middleware', []))
    ->group(function () use ($admin): void {
        Route::get('sections', [SectionController::class, 'index'])->name('sections.index');
        Route::get('sections/{section}', [SectionController::class, 'show'])->name('sections.show');
        Route::patch('sections/{section}', [SectionController::class, 'update'])->middleware($admin)->name('sections.update');

        Route::get('topics', [TopicController::class, 'index'])->name('topics.index');
        Route::post('topics', [TopicController::class, 'store'])->middleware($admin)->name('topics.store');
        Route::patch('topics/{topic}', [TopicController::class, 'update'])->middleware($admin)->name('topics.update');
        Route::delete('topics/{topic}', [TopicController::class, 'destroy'])->middleware($admin)->name('topics.destroy');

        Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::patch('reports/{report}', [AdminReportController::class, 'update'])->middleware($admin)->name('reports.update');

        Route::get('moderation', [ModerationController::class, 'index'])->name('moderation.index');
        Route::patch('moderation/{kind}/{id}', [ModerationController::class, 'update'])->middleware($admin)->name('moderation.update');
    });
