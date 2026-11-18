<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'account_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'history_id',
        'last_synced_at',
        'is_active',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function senders(): HasMany
    {
        return $this->hasMany(EmailSender::class);
    }

    public function messagesMeta(): HasMany
    {
        return $this->hasMany(EmailMessageMeta::class);
    }

    public function retentionRules(): HasMany
    {
        return $this->hasMany(RetentionRule::class);
    }
}
