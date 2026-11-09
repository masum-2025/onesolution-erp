<?php

use Illuminate\Support\Facades\Route;
use Modules\SocialCommerce\Http\Controllers\FacebookCallbackController;
use Modules\SocialCommerce\Http\Controllers\InboxController;
use Modules\SocialCommerce\Http\Controllers\PageController;
use Modules\SocialCommerce\Http\Controllers\PostController;
use Modules\SocialCommerce\Http\Controllers\ReplyController;
use Modules\SocialCommerce\Http\Controllers\ReportController;

/*
| Facebook selling API (loaded by SocialCommerceServiceProvider under /api,
| group "api"): a signed-in organization context, Inventory and Facebook
| selling on there (403 otherwise), and the permission checked in the
| controller at the company.
*/

Route::middleware(['auth:sanctum', 'org', 'module:inventory', 'module:social_commerce'])
    ->prefix('organizations/{organization}/social')
    ->group(function () {
        Route::get('pages', [PageController::class, 'index']);
        Route::get('connect/candidates', [PageController::class, 'candidates']);
        Route::get('options', [PostController::class, 'options']);
        Route::get('items', [PostController::class, 'items'])->middleware('throttle:social-writes');
        Route::get('posts', [PostController::class, 'index']);
        Route::get('posts/{post}', [PostController::class, 'show']);

        Route::middleware('throttle:social-connect')->group(function () {
            Route::post('connect', [PageController::class, 'start']);
            Route::post('connect/pages', [PageController::class, 'save'])->name('social.connect.pages');
            Route::post('pages/{page}/disconnect', [PageController::class, 'disconnect'])->name('social.pages.disconnect');
            Route::patch('pages/{page}', [PageController::class, 'switches'])->name('social.pages.switches');
        });

        // Sales reports (SC-5c).
        Route::get('reports/sales', [ReportController::class, 'sales'])->middleware('throttle:social-reports');

        // Inbox and automatic replies (SC-2).
        Route::get('inbox', [InboxController::class, 'index']);
        Route::get('inbox/{thread}', [InboxController::class, 'show']);
        Route::get('replies', [ReplyController::class, 'index']);
        Route::middleware('throttle:social-writes')->group(function () {
            Route::post('inbox/{thread}/reply', [InboxController::class, 'reply'])->name('social.inbox.reply');
            Route::post('inbox/{thread}/{step}', [InboxController::class, 'mark'])->whereIn('step', ['claim', 'done', 'reopen'])->name('social.inbox.mark');
            Route::post('replies/try', [ReplyController::class, 'try'])->name('social.replies.try');
            Route::put('replies/{intent}', [ReplyController::class, 'update'])->name('social.replies.update');
        });

        Route::middleware('throttle:social-writes')->group(function () {
            Route::post('posts', [PostController::class, 'store'])->name('social.posts.store');
            Route::patch('posts/{post}', [PostController::class, 'update'])->name('social.posts.update');
            Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('social.posts.destroy');
            Route::post('posts/{post}/submit', [PostController::class, 'step'])->defaults('step', 'submit')->name('social.posts.submit');
            Route::post('posts/{post}/approve', [PostController::class, 'step'])->defaults('step', 'approve')->name('social.posts.approve');
            Route::post('posts/{post}/reject', [PostController::class, 'step'])->defaults('step', 'reject')->name('social.posts.reject');
            Route::post('posts/{post}/withdraw', [PostController::class, 'step'])->defaults('step', 'withdraw')->name('social.posts.withdraw');
            Route::put('posts/{post}/media-order', [PostController::class, 'orderMedia'])->name('social.media.order');
            Route::delete('posts/{post}/media/{media}', [PostController::class, 'destroyMedia'])->name('social.media.destroy');
        });
        Route::post('posts/{post}/media', [PostController::class, 'storeMedia'])->middleware('throttle:social-uploads')->name('social.media.store');
    });

// Meta sends the person back here after signing in (no sign-in of ours: the one-time state is the proof).
Route::get('social/facebook/callback', FacebookCallbackController::class)->middleware('throttle:social-connect');
