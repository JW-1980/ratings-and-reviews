<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Http\Controllers\Web\FormController;

/*
| Form routes for posting without JavaScript (`ratings-and-reviews.web_routes`, opt-in).
| Prefix, name prefix and middleware (default `web`: session and CSRF) come from the config.
| Every route answers with a redirect back to the page; the same named limiters as the API
| throttle them.
*/

$write = ThrottleRequests::using('rr-write');
$vote = ThrottleRequests::using('rr-vote');
$report = ThrottleRequests::using('rr-report');

$subject = ['type' => '[A-Za-z0-9_\-.]+', 'id' => '[A-Za-z0-9\-]+'];

Route::post('subjects/{type}/{id}/reviews', [FormController::class, 'storeReview'])->middleware($write)->where($subject)->name('reviews.store');
Route::patch('reviews/{review}', [FormController::class, 'updateReview'])->middleware($write)->name('reviews.update');
Route::delete('reviews/{review}', [FormController::class, 'destroyReview'])->middleware($write)->name('reviews.destroy');
Route::post('reviews/{review}/votes', [FormController::class, 'voteReview'])->middleware($vote)->name('reviews.votes.store');

Route::put('subjects/{type}/{id}/topic-ratings', [FormController::class, 'saveTopicRatings'])->middleware($write)->where($subject)->name('topic-ratings.update');

Route::post('subjects/{type}/{id}/comments', [FormController::class, 'storeComment'])->middleware($write)->where($subject)->name('comments.store');
Route::patch('comments/{comment}', [FormController::class, 'updateComment'])->middleware($write)->name('comments.update');
Route::delete('comments/{comment}', [FormController::class, 'destroyComment'])->middleware($write)->name('comments.destroy');
Route::post('comments/{comment}/undo', [FormController::class, 'undoComment'])->middleware($write)->name('comments.undo');
Route::post('comments/{comment}/votes', [FormController::class, 'voteComment'])->middleware($vote)->name('comments.votes.store');
Route::post('polls/{poll}/votes', [FormController::class, 'votePoll'])->middleware($vote)->name('polls.votes.store');

Route::post('reports', [FormController::class, 'storeReport'])->middleware($report)->name('reports.store');
