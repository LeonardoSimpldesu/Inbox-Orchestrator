<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/accounts', [EmailAccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts/google/redirect', [EmailAccountController::class, 'redirect'])->name('accounts.google.redirect');
    Route::get('/accounts/google/callback', [EmailAccountController::class, 'callback'])->name('accounts.google.callback');
    Route::delete('/accounts/{account}', [EmailAccountController::class, 'destroy'])->whereNumber('account')->name('accounts.destroy');

    Route::get('/settings/security', SecurityController::class)
        ->middleware('password.confirm')
        ->name('settings.security');
});
