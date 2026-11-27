<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class RetentionRule extends Model
{
    use HasFactory;

    /** Order in which conditions appear in the Gmail search. */
    public const CONDITION_TYPES = ['category', 'label', 'domain', 'sender', 'subject'];

    public const KINDS = ['manual', 'sender_category', 'sender_cleanup', 'hub_block'];

    /** Rules owned by the Senders page or the Hub: their conditions can't be edited on the Rules page. */
    public const MANAGED_KINDS = ['sender_category', 'sender_cleanup', 'hub_block'];

    public const CATEGORIES = [
        'promotions' => 'CATEGORY_PROMOTIONS',
        'updates' => 'CATEGORY_UPDATES',
        'social' => 'CATEGORY_SOCIAL',
        'forums' => 'CATEGORY_FORUMS',
    ];

    public const ACTIONS = ['archive', 'trash', 'add_label'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => false,
        'archive_after_label' => false,
        'kind' => 'manual',
    ];

    protected $fillable = [
        'email_account_id',
        'name',
        'kind',
        'retention_days',
        'action',
        'target_label',
        'archive_after_label',
        'is_active',
        'last_run_at',
        'simulated_at',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'simulated_at' => 'datetime',
        'archive_after_label' => 'boolean',
    ];

    /**
     * A rule only runs after the user has seen what it would do.
     */
    public function canActivate(): bool
    {
        return $this->simulated_at !== null;
    }

    public function isManaged(): bool
    {
        return in_array($this->kind, self::MANAGED_KINDS, true);
    }

    /**
     * @param  list<array{type: string, value: string}>  $conditions
     */
    public function replaceConditions(array $conditions): void
    {
        DB::transaction(function () use ($conditions) {
            $this->conditions()->delete();
            $this->conditions()->createMany($conditions);
        });

        $this->unsetRelation('conditions');
    }

    /**
     * Bulk insert with a constant number of queries. Every rule has at least one
     * condition and SQLite serializes writers, so inside the transaction the
     * rules without conditions are exactly the ones just inserted, in id order.
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  list<list<array{type: string, value: string}>>  $conditions  one list per rule, same order
     */
    public static function insertWithConditions(array $rules, array $conditions): void
    {
        DB::transaction(function () use ($rules, $conditions) {
            self::insert($rules);

            $ids = self::whereIn('kind', array_unique(array_column($rules, 'kind')))
                ->whereIn('email_account_id', array_unique(array_column($rules, 'email_account_id')))
                ->doesntHave('conditions')
                ->orderBy('id')
                ->pluck('id');

            $now = now();
            RetentionRuleCondition::insert(collect($conditions)->flatMap(fn (array $list, int $i) => array_map(
                fn (array $condition) => [...$condition, 'retention_rule_id' => $ids[$i], 'created_at' => $now, 'updated_at' => $now],
                $list,
            ))->all());
        });
    }

    public function logExecution(string $status, int $affected = 0, ?string $error = null): void
    {
        $this->executionLogs()->create([
            'status' => $status,
            'affected_messages_count' => $affected,
            'error_message' => $error,
            'executed_at' => now(),
        ]);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('retention_rules.is_active', true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(RetentionRuleCondition::class)->orderBy('id');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(RuleExecutionLog::class);
    }
}
