<?php

namespace App\Http\Controllers;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountLabelController extends Controller
{
    private const UNAVAILABLE = 'Não foi possível carregar os marcadores do Gmail. Tente de novo.';

    public function __construct(private readonly EmailProviderInterface $provider) {}

    public function index(Request $request, string $account): JsonResponse
    {
        $emailAccount = $request->user()->emailAccounts()->findOrFail($account);

        try {
            return response()->json(['labels' => $this->provider->listLabels($emailAccount)]);
        } catch (ProviderUnavailable|ReauthorizationRequired) {
            return response()->json(['message' => self::UNAVAILABLE], 503);
        }
    }

    public function store(Request $request, string $account): JsonResponse
    {
        $emailAccount = $request->user()->emailAccounts()->findOrFail($account);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100', 'not_regex:/^\/|\/$/']]);

        try {
            return response()->json(['label' => $this->provider->createLabel($emailAccount, $validated['name'])], 201);
        } catch (ProviderUnavailable|ReauthorizationRequired) {
            return response()->json(['message' => 'Não foi possível criar o marcador no Gmail. Tente de novo.'], 503);
        }
    }
}
