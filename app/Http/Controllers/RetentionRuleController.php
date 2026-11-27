<?php

namespace App\Http\Controllers;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\LabelMissing;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Http\Requests\RetentionRuleRequest;
use App\Jobs\ExecuteRetentionRuleJob;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\RetentionRuleCondition;
use App\Models\RuleExecutionLog;
use App\Services\Retention\RuleSimulator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RetentionRuleController extends Controller
{
    private const HISTORY_SIZE = 10;

    private const RECONNECT = 'Reconecte a conta antes de usar as regras.';

    public function __construct(private readonly EmailProviderInterface $provider) {}

    public function index(Request $request): Response
    {
        $accounts = $request->user()->emailAccounts()->orderBy('account_email')->get();

        $rules = RetentionRule::whereIn('email_account_id', $accounts->modelKeys())
            ->with(['conditions', 'executionLogs' => fn ($query) => $query->latest('executed_at')->latest('id')->limit(self::HISTORY_SIZE)])
            ->orderBy('email_account_id')
            ->orderBy('name')
            ->get();

        $labelNames = $this->labelNames($rules, $accounts);

        return Inertia::render('RetentionRules/Index', [
            'rules' => $rules->map(fn (RetentionRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'email_account_id' => $rule->email_account_id,
                'account_email' => $accounts->find($rule->email_account_id)->account_email,
                'kind' => $rule->kind,
                'conditions' => $rule->conditions->map(fn (RetentionRuleCondition $condition) => [
                    'type' => $condition->type,
                    'value' => $condition->value,
                    'label' => $condition->type === 'label' ? ($labelNames[$rule->email_account_id][$condition->value] ?? null) : null,
                ])->values(),
                'retention_days' => $rule->retention_days,
                'action' => $rule->action,
                'target_label' => $rule->target_label,
                'target_label_name' => $rule->action === 'add_label' ? ($labelNames[$rule->email_account_id][$rule->target_label] ?? $rule->target_label) : null,
                'archive_after_label' => $rule->archive_after_label,
                'is_active' => $rule->is_active,
                'can_activate' => $rule->canActivate(),
                'last_run_at' => $rule->last_run_at?->toIso8601String(),
                'logs' => $rule->executionLogs->map(fn (RuleExecutionLog $log) => [
                    'status' => $log->status,
                    'affected' => $log->affected_messages_count,
                    'error' => $log->error_message,
                    'executed_at' => $log->executed_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'accounts' => $accounts->map(fn (EmailAccount $account) => [
                'id' => $account->id,
                'account_email' => $account->account_email,
                'needs_reauth' => $account->needsReauth(),
            ])->values(),
            'simulation' => fn () => $request->session()->get('simulation'),
        ]);
    }

    public function store(RetentionRuleRequest $request, RuleSimulator $simulator): RedirectResponse
    {
        if ($request->forAllAccounts()) {
            $accountIds = $request->user()->emailAccounts()->pluck('email_accounts.id');
            $now = now();

            RetentionRule::insertWithConditions(
                $accountIds->map(fn (int $accountId) => [
                    ...$request->ruleAttributes(),
                    'email_account_id' => $accountId,
                    'kind' => 'manual',
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                $accountIds->map(fn () => $request->ruleConditions())->all(),
            );

            // Each copy is simulated on its own row: the count differs per account.
            return back()->with('success', "Regra criada em {$accountIds->count()} contas. Simule cada uma antes de ativar.");
        }

        $rule = DB::transaction(function () use ($request) {
            $rule = RetentionRule::create($request->ruleAttributes());
            $rule->replaceConditions($request->ruleConditions());

            return $rule;
        });

        if ($request->boolean('simulate')) {
            return $this->simulateAndRedirect($rule, $simulator, 'Regra criada.');
        }

        return back()->with('success', 'Regra criada.');
    }

    public function update(RetentionRuleRequest $request, string $rule): RedirectResponse
    {
        $retentionRule = $this->findRule($request, $rule);
        $previous = $retentionRule->only(['email_account_id', 'filter_type', 'filter_value']);

        DB::transaction(function () use ($request, $retentionRule) {
            $retentionRule->forceFill([
                ...$request->ruleAttributes(),
                'is_active' => false,
                'simulated_at' => null,
            ])->save();
            $retentionRule->replaceConditions($request->ruleConditions());
        });

        if ($previous['filter_type'] === 'sender' && $retentionRule->only(array_keys($previous)) !== $previous) {
            $this->releaseHubSender($previous['email_account_id'], $previous['filter_value']);
        }

        return back()->with('success', 'Regra atualizada. Simule de novo antes de ativar.');
    }

    public function destroy(Request $request, string $rule): RedirectResponse
    {
        $retentionRule = $this->findRule($request, $rule);
        $retentionRule->delete();

        if ($retentionRule->filter_type === 'sender') {
            $this->releaseHubSender($retentionRule->email_account_id, $retentionRule->filter_value);
        }

        return back()->with('success', 'Regra apagada.');
    }

    public function simulate(Request $request, string $rule, RuleSimulator $simulator): RedirectResponse
    {
        return $this->simulateAndRedirect($this->findRule($request, $rule), $simulator);
    }

    public function activate(Request $request, string $rule): RedirectResponse
    {
        $retentionRule = $this->findRule($request, $rule);

        if (! $retentionRule->canActivate()) {
            return back()->with('error', 'Simule a regra antes de ativá-la.');
        }

        $retentionRule->forceFill(['is_active' => true])->save();

        return back()->with('success', 'Regra ativada.');
    }

    public function pause(Request $request, string $rule): RedirectResponse
    {
        $this->findRule($request, $rule)->forceFill(['is_active' => false])->save();

        return back()->with('success', 'Regra pausada.');
    }

    public function run(Request $request): RedirectResponse
    {
        $rules = RetentionRule::active()
            ->whereIn('email_account_id', $request->user()->emailAccounts()->select('email_accounts.id'))
            ->with('account')
            ->get()
            ->reject(fn (RetentionRule $rule) => $rule->account->needsReauth());

        if ($rules->isEmpty()) {
            return back()->with('error', 'Nenhuma regra ativa para executar.');
        }

        $rules->each(fn (RetentionRule $rule) => ExecuteRetentionRuleJob::dispatch($rule->id, $rule->email_account_id));

        return back()->with('success', 'Execução das regras iniciada.');
    }

    /**
     * A sender blocked from the Hub goes back to its pending list once no rule
     * on that account targets it anymore.
     */
    private function releaseHubSender(int $accountId, string $email): void
    {
        $stillTargeted = RetentionRule::where('email_account_id', $accountId)
            ->where('filter_type', 'sender')
            ->where('filter_value', $email)
            ->exists();

        if (! $stillTargeted) {
            EmailSender::where('email_account_id', $accountId)
                ->where('email', $email)
                ->where('unsubscribe_status', EmailSender::STATUS_BLOCKED)
                ->update(['unsubscribe_status' => EmailSender::STATUS_NONE, 'unsubscribe_method' => null]);
        }
    }

    private function findRule(Request $request, string $id): RetentionRule
    {
        return RetentionRule::whereIn('email_account_id', $request->user()->emailAccounts()->select('email_accounts.id'))
            ->with('account')
            ->findOrFail($id);
    }

    private function simulateAndRedirect(RetentionRule $rule, RuleSimulator $simulator, ?string $success = null): RedirectResponse
    {
        $rule->loadMissing('account');

        if ($rule->account->needsReauth()) {
            return back()->with('error', self::RECONNECT);
        }

        try {
            $result = $simulator->simulate($rule);
        } catch (LabelMissing) {
            return back()->with('error', 'O marcador desta regra não existe mais no Gmail.');
        } catch (ReauthorizationRequired) {
            return back()->with('error', self::RECONNECT);
        } catch (ProviderUnavailable) {
            return back()->with('error', 'Não foi possível simular agora. Tente de novo em alguns minutos.');
        }

        return back()->with(array_filter([
            'success' => $success,
            'simulation' => ['rule_id' => $rule->id, 'total' => $result->total, 'capped' => $result->capped, 'sample' => $result->sample],
        ]));
    }

    /**
     * One cached Gmail call per account that has label rules; never per rule.
     *
     * @param  Collection<int, RetentionRule>  $rules
     * @param  Collection<int, EmailAccount>  $accounts
     * @return array<int, array<string, string>> label name by id, per account id
     */
    private function labelNames($rules, $accounts): array
    {
        $names = [];

        foreach ($rules->filter(fn (RetentionRule $rule) => $rule->action === 'add_label' || $rule->conditions->contains('type', 'label'))->pluck('email_account_id')->unique() as $accountId) {
            try {
                $names[$accountId] = array_column($this->provider->listLabels($accounts->find($accountId)), 'name', 'id');
            } catch (ProviderUnavailable|ReauthorizationRequired) {
                $names[$accountId] = [];
            }
        }

        return $names;
    }
}
