<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailAccountController;
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
    Route::get('/subscriptions/{sender}/page', [SubscriptionController::class, 'page'])->whereNumber('sender')->name('subscriptions.page');

    Route::get('/settings/security', SecurityController::class)
        ->middleware('password.confirm')
        ->name('settings.security');
});
