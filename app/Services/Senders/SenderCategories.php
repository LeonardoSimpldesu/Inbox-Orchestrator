<?php

namespace App\Services\Senders;

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A sender's category is its sender_category rule (one sender condition, apply
 * label); there is no other copy of it, so the senders page and the rules page
 * always agree.
 */
class SenderCategories
{
    /**
     * @param  Collection<int, EmailSender>  $senders
     * @return array<int, string|null> label id by sender id
     */
    public function forSenders(Collection $senders): array
    {
        if ($senders->isEmpty()) {
            return [];
        }

        $labels = $this->rulesFor($senders)->get(['retention_rules.email_account_id', 'retention_rule_conditions.value as sender_email', 'retention_rules.target_label'])
            ->mapWithKeys(fn (RetentionRule $rule) => [$rule->email_account_id.'|'.$rule->sender_email => $rule->target_label]);

        return $senders->mapWithKeys(fn (EmailSender $sender) => [
            $sender->id => $labels[$sender->email_account_id.'|'.$sender->email] ?? null,
        ])->all();
    }

    /**
     * Points every sender (all from $account) at $labelId, or removes their
     * category when null. Category rules start active: the user picked them.
     *
     * @param  Collection<int, EmailSender>  $senders
     * @return array<int, array{old: string|null, new: string|null}> only senders whose label changed
     */
    public function assign(EmailAccount $account, Collection $senders, ?string $labelId, ?string $labelName): array
    {
        $rules = $this->rulesFor($senders)->get(['retention_rules.*', 'retention_rule_conditions.value as sender_email'])->keyBy('sender_email');
        $changed = $senders->filter(fn (EmailSender $sender) => ($rules[$sender->email]->target_label ?? null) !== $labelId);

        if ($changed->isEmpty()) {
            return [];
        }

        if ($labelId === null) {
            RetentionRule::whereIn('id', $changed->map(fn (EmailSender $sender) => $rules[$sender->email]->id))->delete();
        } else {
            $now = now();
            $row = fn (EmailSender $sender) => [
                'email_account_id' => $account->id,
                'kind' => 'sender_category',
                'name' => Str::limit('Categoria '.$labelName.': '.($sender->name ?? $sender->email), 100, ''),
                'retention_days' => 0,
                'action' => 'add_label',
                'target_label' => $labelId,
                'archive_after_label' => false,
                'is_active' => true,
                'simulated_at' => $now,
                'updated_at' => $now,
            ];

            [$existing, $new] = $changed->partition(fn (EmailSender $sender) => isset($rules[$sender->email]));

            if ($existing->isNotEmpty()) {
                RetentionRule::upsert(
                    $existing->map(fn (EmailSender $sender) => ['id' => $rules[$sender->email]->id, ...$row($sender), 'created_at' => $rules[$sender->email]->created_at])->values()->all(),
                    ['id'],
                    ['name', 'target_label', 'is_active', 'simulated_at', 'updated_at'],
                );
            }

            if ($new->isNotEmpty()) {
                RetentionRule::insertWithConditions(
                    $new->map(fn (EmailSender $sender) => [...$row($sender), 'created_at' => $now])->values()->all(),
                    $new->map(fn (EmailSender $sender) => [['type' => 'sender', 'value' => $sender->email]])->values()->all(),
                );
            }
        }

        return $changed->mapWithKeys(fn (EmailSender $sender) => [
            $sender->id => ['old' => $rules[$sender->email]->target_label ?? null, 'new' => $labelId],
        ])->all();
    }

    /**
     * @param  Collection<int, EmailSender>  $senders
     * @return Builder<RetentionRule>
     */
    private function rulesFor(Collection $senders): Builder
    {
        return RetentionRule::join('retention_rule_conditions', 'retention_rule_conditions.retention_rule_id', '=', 'retention_rules.id')
            ->where('retention_rules.kind', 'sender_category')
            ->where('retention_rule_conditions.type', 'sender')
            ->whereIn('retention_rules.email_account_id', $senders->pluck('email_account_id')->unique()->values())
            ->whereIn('retention_rule_conditions.value', $senders->pluck('email')->unique()->values());
    }
}
