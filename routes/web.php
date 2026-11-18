<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/settings/security', SecurityController::class)
        ->middleware('password.confirm')
        ->name('settings.security');
});
