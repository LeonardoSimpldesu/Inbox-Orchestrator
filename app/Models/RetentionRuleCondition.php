<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionRuleCondition extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'value'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RetentionRule::class, 'retention_rule_id');
    }
}
