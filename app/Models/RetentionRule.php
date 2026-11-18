<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetentionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'name',
        'filter_type',
        'filter_value',
        'retention_days',
        'action',
        'target_label',
        'is_active',
        'last_run_at',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(RuleExecutionLog::class);
    }
}
