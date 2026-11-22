<?php

use App\Http\Controllers\AccountLabelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\RetentionRuleController;
use App\Http\Controllers\SenderController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/accounts', [EmailAccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts/google/redirect', [EmailAccountController::class, 'redirect'])->name('accounts.google.redirect');
    Route::get('/accounts/google/callback', [EmailAccountController::class, 'callback'])->name('accounts.google.callback');
    Route::post('/accounts/sync', [EmailAccountController::class, 'syncAll'])->name('accounts.sync');
    Route::post('/accounts/{account}/import-history', [EmailAccountController::class, 'importHistory'])->whereNumber('account')->name('accounts.import-history');
    Route::delete('/accounts/{account}', [EmailAccountController::class, 'destroy'])->whereNumber('account')->name('accounts.destroy');

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/subscriptions/{sender}/unsubscribe', [SubscriptionController::class, 'unsubscribe'])
        ->whereNumber('sender')->middleware('throttle:30,1')->name('subscriptions.unsubscribe');
    Route::post('/subscriptions/bulk-unsubscribe', [SubscriptionController::class, 'bulkUnsubscribe'])
        ->middleware('throttle:30,1')->name('subscriptions.bulk-unsubscribe');
    Route::post('/subscriptions/{sender}/reset', [SubscriptionController::class, 'reset'])->whereNumber('sender')->name('subscriptions.reset');
    Route::get('/subscriptions/{sender}/page', [SubscriptionController::class, 'page'])->whereNumber('sender')->name('subscriptions.page');

    Route::get('/accounts/{account}/labels', [AccountLabelController::class, 'index'])->whereNumber('account')->name('accounts.labels.index');
    Route::post('/accounts/{account}/labels', [AccountLabelController::class, 'store'])->whereNumber('account')->middleware('throttle:30,1')->name('accounts.labels.store');

    Route::get('/senders', [SenderController::class, 'index'])->name('senders.index');
    Route::post('/senders/categorize', [SenderController::class, 'categorize'])->middleware('throttle:30,1')->name('senders.categorize');
    Route::post('/senders/clean', [SenderController::class, 'clean'])->middleware('throttle:30,1')->name('senders.clean');

    Route::get('/retention-rules', [RetentionRuleController::class, 'index'])->name('retention-rules.index');
    Route::post('/retention-rules', [RetentionRuleController::class, 'store'])->name('retention-rules.store');
    Route::post('/retention-rules/run', [RetentionRuleController::class, 'run'])->middleware('throttle:30,1')->name('retention-rules.run');
    Route::put('/retention-rules/{rule}', [RetentionRuleController::class, 'update'])->whereNumber('rule')->name('retention-rules.update');
    Route::delete('/retention-rules/{rule}', [RetentionRuleController::class, 'destroy'])->whereNumber('rule')->name('retention-rules.destroy');
    Route::post('/retention-rules/{rule}/simulate', [RetentionRuleController::class, 'simulate'])->whereNumber('rule')->middleware('throttle:30,1')->name('retention-rules.simulate');
    Route::post('/retention-rules/{rule}/activate', [RetentionRuleController::class, 'activate'])->whereNumber('rule')->name('retention-rules.activate');
    Route::post('/retention-rules/{rule}/pause', [RetentionRuleController::class, 'pause'])->whereNumber('rule')->name('retention-rules.pause');

    Route::get('/settings/security', SecurityController::class)
        ->middleware('password.confirm')
        ->name('settings.security');
});
