<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'accounts' => $user->emailAccounts()
                ->where('is_active', true)
                ->get(['id', 'provider', 'account_email', 'last_synced_at', 'is_active']),
            'stats' => [
                'totalSenders' => $user->senders()->count(),
                'archivedCount' => 0,
            ],
            'recentRules' => $user->retentionRules()
                ->where('retention_rules.is_active', true)
                ->get([
                    'retention_rules.id',
                    'retention_rules.name',
                    'retention_rules.filter_type',
                    'retention_rules.filter_value',
                    'retention_rules.retention_days',
                    'retention_rules.action',
                    'retention_rules.is_active',
                ]),
        ]);
    }
}
