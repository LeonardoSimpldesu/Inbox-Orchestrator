<?php

namespace App\Http\Controllers;

use App\Models\RetentionRule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'accounts' => $user->emailAccounts()->get()->map->summary(),
            'stats' => [
                'totalSenders' => $user->senders()->pendingSubscriptions()->count(),
                'archivedCount' => 0,
            ],
            'recentRules' => $user->retentionRules()
                ->where('retention_rules.is_active', true)
                ->get([
                    'retention_rules.id',
                    'retention_rules.name',
                    'retention_rules.retention_days',
                    'retention_rules.action',
                    'retention_rules.is_active',
                ])
                ->map(fn (RetentionRule $rule) => $rule->only(['id', 'name', 'retention_days', 'action', 'is_active'])),
        ]);
    }
}
