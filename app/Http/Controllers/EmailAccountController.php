<?php

namespace App\Http\Controllers;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\MissingRequiredScopes;
use App\Exceptions\OAuthExchangeFailed;
use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Services\Providers\OAuthGrant;
use App\Services\Sync\SyncMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EmailAccountController extends Controller
{
    private const STATE_KEY = 'google_oauth_state';

    private const GENERIC_ERROR = 'Não foi possível conectar a conta Gmail. Tente novamente.';

    public function __construct(private readonly EmailProviderInterface $provider) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Accounts/Index', [
            'accounts' => $request->user()->emailAccounts()->orderBy('account_email')->get()->map->summary(),
            'testingMode' => (bool) config('services.google.testing'),
        ]);
    }

    public function redirect(Request $request): SymfonyResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        $loginHint = $request->user()->emailAccounts()->find($request->integer('account'))?->account_email;

        return Inertia::location($this->provider->authorizationUrl($state, $loginHint));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $state = $request->query('state');

        if (! is_string($expected)) {
            return $this->back(error: 'Sua sessão expirou durante a conexão. Clique em Conectar conta Gmail de novo.');
        }

        if (! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->back(error: 'Não foi possível validar a resposta do Google. Tente novamente.');
        }

        if ($request->query('error') === 'access_denied') {
            return $this->back(error: 'Conexão cancelada.');
        }

        $code = $request->query('code');

        if ($request->has('error') || ! is_string($code) || $code === '') {
            return $this->back(error: self::GENERIC_ERROR);
        }

        // Rejected grants are discarded without revoking: the grant is shared by
        // every token of this Google account, so revoking would also cut off the
        // user who legitimately has it connected.
        try {
            $grant = $this->provider->exchangeCode($code);
        } catch (MissingRequiredScopes) {
            return $this->back(error: 'Para funcionar, o Inbox Orchestrator precisa de todas as permissões pedidas. Conecte de novo e marque todas as opções.');
        } catch (OAuthExchangeFailed $e) {
            Log::warning('Gmail OAuth code exchange failed.', ['error' => $e->getMessage()]);

            return $this->back(error: self::GENERIC_ERROR);
        }

        if ($grant->refreshToken === null) {
            return $this->back(error: self::GENERIC_ERROR);
        }

        $account = DB::transaction(fn () => $this->storeGrant($request, $grant));

        if ($account === null) {
            return $this->back(error: 'Esta conta Gmail já está conectada por outro usuário.');
        }

        SyncEmailAccountJob::dispatch($account->id);

        $verb = $account->wasRecentlyCreated ? 'conectada' : 'reconectada';

        return $this->back(success: "Conta {$account->account_email} {$verb}.");
    }

    public function destroy(Request $request, string $account): RedirectResponse
    {
        $emailAccount = $request->user()->emailAccounts()->findOrFail($account);

        if (! $this->provider->revoke($emailAccount)) {
            Log::warning('Gmail token revocation failed; disconnecting anyway.', ['account_id' => $emailAccount->id]);
        }

        $emailAccount->delete();

        return $this->back(success: "Conta {$emailAccount->account_email} desconectada. Os dados ficam guardados se você reconectar.");
    }

    public function importHistory(Request $request, string $account): RedirectResponse
    {
        $emailAccount = $request->user()->emailAccounts()->findOrFail($account);

        if ($emailAccount->needsReauth()) {
            return $this->back(error: 'Reconecte a conta antes de importar o histórico.');
        }

        $emailAccount->forceFill(['history_import_requested_at' => now()])->save();
        SyncEmailAccountJob::dispatch($emailAccount->id, SyncMode::HistoryNow);

        return $this->back(success: 'Importação do histórico iniciada. Pode levar até 20 minutos.');
    }

    public function syncAll(Request $request): RedirectResponse
    {
        $accounts = $request->user()->emailAccounts()->syncable()->get()->reject->needsReauth();

        if ($accounts->isEmpty()) {
            return back()->with('error', 'Nenhuma conta conectada para sincronizar.');
        }

        $accounts->each(fn (EmailAccount $account) => SyncEmailAccountJob::dispatch($account->id, SyncMode::Changes));

        return back()->with('success', 'Sincronização iniciada.');
    }

    /**
     * A Gmail can be connected by only one user at a time; disconnected
     * (soft-deleted) accounts don't count, and each user keeps their own row.
     */
    private function storeGrant(Request $request, OAuthGrant $grant): ?EmailAccount
    {
        $takenByOtherUser = EmailAccount::where('account_email', $grant->email)
            ->where('user_id', '!=', $request->user()->id)
            ->lockForUpdate()
            ->exists();

        if ($takenByOtherUser) {
            return null;
        }

        $account = $request->user()->emailAccounts()->withTrashed()->firstOrNew(['account_email' => $grant->email]);

        $account->forceFill([
            'provider' => 'gmail',
            'access_token' => $grant->accessToken,
            'refresh_token' => $grant->refreshToken,
            'token_expires_at' => $grant->expiresAt,
            'authorized_at' => now(),
            'reauth_required_at' => null,
            'deleted_at' => null,
        ])->save();

        return $account;
    }

    private function back(?string $success = null, ?string $error = null): RedirectResponse
    {
        return redirect()->route('accounts.index')->with(array_filter(['success' => $success, 'error' => $error]));
    }
}
