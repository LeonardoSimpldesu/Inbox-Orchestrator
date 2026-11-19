<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnsubscribeRequest;
use App\Jobs\UnsubscribeSenderJob;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $accounts = $user->emailAccounts()->orderBy('account_email')->get(['id', 'account_email']);
        $accountId = $accounts->firstWhere('id', $request->integer('account'))?->id;
        $status = $request->query('status') === 'handled' ? 'handled' : 'pending';

        $scoped = fn () => $user->senders()
            ->when($accountId, fn (Builder $query) => $query->where('email_accounts.id', $accountId));

        $subscriptions = $scoped()
            ->{$status === 'handled' ? 'handledSubscriptions' : 'pendingSubscriptions'}()
            ->addSelect('email_accounts.account_email')
            ->withCount(['messagesMeta as emails_after_request' => fn (Builder $query) => $query
                ->whereColumn('email_messages_meta.received_at', '>', 'email_senders.unsubscribe_requested_at'),
            ])
            ->orderByDesc('email_senders.total_messages_received')
            ->orderBy('email_senders.email')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (EmailSender $sender) => [
                'id' => $sender->id,
                'name' => $sender->name,
                'email' => $sender->email,
                'domain' => $sender->domain,
                'account_email' => $sender->account_email,
                'total' => $sender->total_messages_received,
                'unread' => $sender->total_messages_unread,
                'last_received_at' => $sender->last_email_received_at?->toIso8601String(),
                'status' => $sender->unsubscribe_status,
                'method' => $sender->unsubscribe_method,
                'error' => $sender->unsubscribe_error,
                'requested_at' => $sender->unsubscribe_requested_at?->toIso8601String(),
                'emails_after_request' => (int) $sender->emails_after_request,
                'options' => $sender->unsubscribeOptions(),
                'gmail_url' => 'https://mail.google.com/mail/u/?authuser='.rawurlencode($sender->account_email)
                    .'#search/from:'.rawurlencode($sender->email),
            ]);

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'accounts' => $accounts,
            'filters' => ['account' => $accountId, 'status' => $status],
            'counts' => [
                'pending' => $scoped()->pendingSubscriptions()->count(),
                'handled' => $scoped()->handledSubscriptions()->count(),
            ],
        ]);
    }

    public function unsubscribe(UnsubscribeRequest $request, string $sender): RedirectResponse
    {
        $subscription = $request->user()->senders()->with('account')->findOrFail($sender);
        $method = $request->string('method')->toString();
        $cleanup = $request->string('cleanup')->toString();

        if ($subscription->account->needsReauth()) {
            return back()->with('error', 'Reconecte a conta antes de se desinscrever.');
        }

        if ($method !== 'rule' && ! $subscription->unsubscribeOptions()[$method]) {
            throw ValidationException::withMessages(['method' => 'Este remetente não oferece essa forma de desinscrição.']);
        }

        if ($subscription->unsubscribe_status === EmailSender::STATUS_PROCESSING) {
            return back()->with('success', 'Desinscrição iniciada.');
        }

        $message = match ($method) {
            'page' => $this->markPageUnsubscribed($subscription),
            'rule' => $this->createRule($subscription, $request->string('rule_action')->toString()),
            default => $this->startUnsubscribe($subscription),
        };

        $needsJob = in_array($method, ['one_click', 'mailto'], true) || $cleanup !== 'keep';

        if ($needsJob) {
            UnsubscribeSenderJob::dispatch($subscription->id, $method, $cleanup);
        }

        return back()->with('success', $message);
    }

    /**
     * The sender's unsubscribe page is opened by the user's browser, never fetched
     * by the server, and its URL is kept out of Inertia props.
     */
    public function page(Request $request, string $sender): RedirectResponse
    {
        $url = $request->user()->senders()->findOrFail($sender)->unsubscribe_url;

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    private function startUnsubscribe(EmailSender $sender): string
    {
        $sender->forceFill(['unsubscribe_status' => EmailSender::STATUS_PROCESSING, 'unsubscribe_error' => null])->save();

        return 'Desinscrição iniciada.';
    }

    private function markPageUnsubscribed(EmailSender $sender): string
    {
        $sender->forceFill([
            'unsubscribe_status' => EmailSender::STATUS_REQUESTED,
            'unsubscribe_method' => 'page',
            'unsubscribe_requested_at' => now(),
            'unsubscribe_error' => null,
        ])->save();

        return 'Marcado como desinscrito.';
    }

    private function createRule(EmailSender $sender, string $action): string
    {
        $label = $sender->name ?? $sender->email;

        RetentionRule::create([
            'email_account_id' => $sender->email_account_id,
            'name' => ($action === 'trash' ? 'Mandar para a lixeira e-mails de ' : 'Arquivar e-mails de ').$label,
            'filter_type' => 'sender',
            'filter_value' => $sender->email,
            'retention_days' => 0,
            'action' => $action,
            'is_active' => true,
        ]);

        $sender->forceFill([
            'unsubscribe_status' => EmailSender::STATUS_BLOCKED,
            'unsubscribe_method' => 'rule',
            'unsubscribe_requested_at' => now(),
            'unsubscribe_error' => null,
        ])->save();

        return 'Regra criada.';
    }
}
