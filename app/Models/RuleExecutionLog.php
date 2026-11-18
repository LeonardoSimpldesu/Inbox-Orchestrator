<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleExecutionLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'retention_rule_id',
        'affected_messages_count',
        'status',
        'error_message',
        'executed_at',
    ];

    protected $casts = [
        'affected_messages_count' => 'integer',
        'executed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RetentionRule::class, 'retention_rule_id');
    }
}
