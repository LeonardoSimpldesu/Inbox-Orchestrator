<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PrivacyPolicyController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Privacy', [
            'controllerName' => config('privacy.controller_name'),
            'contactEmail' => config('privacy.contact_email'),
            'updatedAt' => config('privacy.updated_at'),
            'disconnectedRetentionDays' => (int) config('app.disconnected_account_retention_days'),
        ]);
    }
}
