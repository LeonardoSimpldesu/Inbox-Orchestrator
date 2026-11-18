<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Two-factor state only. The QR code, setup key and recovery codes are
     * fetched on demand from Fortify's JSON endpoints, never sent as props.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Security', [
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'status' => $request->session()->get('status'),
        ]);
    }
}
