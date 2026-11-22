<?php

namespace App\Http\Controllers;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\ApplySenderLabelJob;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Services\Senders\SenderCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class SenderController extends Controller
{
    private const PER_PAGE = 100;

    /** Longest a label change runs (20 min budget) plus slack; older flags are stale. */
    private const APPLYING_WINDOW_MINUTES = 30;

    private const LABELS_UNAVAILABLE = 'Não foi possível carregar os marcadores do Gmail. Tente de novo.';

    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly SenderCategories $categories,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $accounts = $user->emailAccounts()->orderBy('account_email')->get(['email_accounts.id', 'account_email', 'reauth_required_at', 'authorized_at']);
        $accountId = $accounts->firstWhere('id', $request->integer('account'))?->id;
        $category = $request->filled('category') ? (string) $request->query('category') : null;
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $all = $request->boolean('all');

        $senders = $user->senders()
            ->where('email_senders.total_messages_received', '>', 0)
            ->when(! $all, fn (Builder $query) => $query->nonSubscriptions())
            ->when($accountId, fn (Builder $query) => $query->where('email_accounts.id', $accountId))
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when($category === 'none', fn (Builder $query) => $query->whereNotExists($this->categoryRule()))
            ->when($category !== null && $category !== 'none', fn (Builder $query) => $query->whereExists($this->categoryRule($category)))
            ->addSelect('email_accounts.account_email')
            ->orderByDesc('email_senders.total_messages_received')
            ->orderBy('email_senders.email')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $labelIds = $this->categories->forSenders($senders->getCollection());
        $labelNames = $this->labelNames($accounts->whereIn('id', collect($labelIds)->filter()->keys()
            ->map(fn (int $senderId) => $senders->getCollection()->firstWhere('id', $senderId)->email_account_id)->unique()));

        return Inertia::render('Senders/Index', [
            'senders' => $senders->through(fn (EmailSender $sender) => [
                'id' => $sender->id,
                'name' => $sender->name,
                'email' => $sender->email,
                'domain' => $sender->domain,
                'account_id' => $sender->email_account_id,
                'account_email' => $sender->account_email,
                'total' => $sender->total_messages_received,
                'unread' => $sender->total_messages_unread,
                'last_received_at' => $sender->last_email_received_at?->toIso8601String(),
                'is_subscription' => $sender->unsubscribe_url !== null || $sender->unsubscribe_mailto !== null || $sender->total_messages_promotional > 0,
                'category' => ($labelId = $labelIds[$sender->id] ?? null) === null ? null : [
                    'label_id' => $labelId,
                    'name' => $labelNames[$sender->email_account_id][$labelId] ?? $labelId,
                ],
                'applying' => $sender->label_applying_at?->isAfter(now()->subMinutes(self::APPLYING_WINDOW_MINUTES)) ?? false,
                'gmail_url' => 'https://mail.google.com/mail/u/?authuser='.rawurlencode($sender->account_email)
                    .'#search/from:'.rawurlencode($sender->email),
            ]),
            'accounts' => $accounts->map(fn (EmailAccount $account) => ['id' => $account->id, 'account_email' => $account->account_email])->values(),
            'filters' => ['account' => $accountId, 'category' => $category, 'q' => $search, 'all' => $all],
            'labels' => $accountId === null ? [] : $this->labelsOrEmpty($accounts->find($accountId)),
        ]);
    }

    public function categorize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'label_id' => ['nullable', 'string', 'max:255'],
        ]);

        $senders = $request->user()->senders()->with('account')->whereIn('email_senders.id', $validated['ids'])->get();

        if ($senders->isEmpty()) {
            return back()->with('error', 'Nenhum remetente encontrado.');
        }

        if ($senders->pluck('email_account_id')->unique()->count() > 1) {
            return back()->with('error', 'Selecione remetentes de uma mesma conta para escolher a categoria.');
        }

        $account = $senders->first()->account;

        if ($account->needsReauth()) {
            return back()->with('error', 'Reconecte a conta antes de organizar os remetentes.');
        }

        $labelId = $validated['label_id'] ?? null;
        $labelName = null;

        if ($labelId !== null) {
            try {
                $labelName = collect($this->provider->listLabels($account))->firstWhere('id', $labelId)['name'] ?? null;
            } catch (ProviderUnavailable|ReauthorizationRequired) {
                return back()->with('error', self::LABELS_UNAVAILABLE);
            }

            if ($labelName === null) {
                return back()->with('error', 'Escolha um marcador desta conta.');
            }
        }

        $changes = $this->categories->assign($account, $senders, $labelId, $labelName);

        if ($changes !== []) {
            EmailSender::whereIn('id', array_keys($changes))->update(['label_applying_at' => now()]);
        }

        foreach ($changes as $senderId => $change) {
            ApplySenderLabelJob::dispatch($senderId, $account->id, $change['new'], $change['old']);
        }

        return back()->with('success', $labelId === null
            ? "Categoria removida de {$senders->count()} remetente(s)."
            : "Categoria aplicada a {$senders->count()} remetente(s).");
    }

    /**
     * Correlated "the sender has a category rule (with this label)" condition.
     */
    private function categoryRule(?string $labelId = null): \Closure
    {
        return fn (QueryBuilder $query) => $query->selectRaw('1')->from('retention_rules')
            ->whereColumn('retention_rules.email_account_id', 'email_senders.email_account_id')
            ->whereColumn('retention_rules.filter_value', 'email_senders.email')
            ->where('retention_rules.filter_type', 'sender')
            ->where('retention_rules.action', 'add_label')
            ->when($labelId !== null, fn (QueryBuilder $q) => $q->where('retention_rules.target_label', $labelId));
    }

    /**
     * One cached Gmail call per account that has categorized senders on the page.
     *
     * @param  Collection<int, EmailAccount>  $accounts
     * @return array<int, array<string, string>>
     */
    private function labelNames(Collection $accounts): array
    {
        return $accounts->mapWithKeys(fn (EmailAccount $account) => [
            $account->id => array_column($this->labelsOrEmpty($account), 'name', 'id'),
        ])->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function labelsOrEmpty(EmailAccount $account): array
    {
        try {
            return $this->provider->listLabels($account);
        } catch (ProviderUnavailable|ReauthorizationRequired) {
            return [];
        }
    }
}
