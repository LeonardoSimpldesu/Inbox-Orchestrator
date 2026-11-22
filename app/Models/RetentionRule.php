<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetentionRule extends Model
{
    use HasFactory;

    public const FILTER_TYPES = ['category', 'domain', 'sender', 'label'];

    /** Rule category keys mapped to Gmail's system label ids. */
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
    ];

    protected $fillable = [
        'email_account_id',
        'name',
        'filter_type',
        'filter_value',
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

    public function scopeActive(Builder $query): void
    {
        $query->where('retention_rules.is_active', true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(RuleExecutionLog::class);
    }
}
