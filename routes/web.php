<?php

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    $accounts = EmailAccount::where('is_active', true)
        ->get(['id', 'provider', 'account_email', 'last_synced_at', 'is_active']);
    $totalSenders = EmailSender::count();
    $rules = RetentionRule::where('is_active', true)->get();

    return Inertia::render('Dashboard', [
        'accounts' => $accounts,
        'stats' => [
            'totalSenders' => $totalSenders,
            'archivedCount' => 0,
        ],
        'recentRules' => $rules,
    ]);
});
