<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class EmailAccount extends Model
{
    use HasFactory, SoftDeletes;

    /** Google expires refresh tokens of apps in "Testing" publishing status after 7 days. */
    private const TESTING_TOKEN_LIFETIME_DAYS = 7;

    private const EXPIRING_SOON_DAYS = 2;

    protected $fillable = [
        'user_id',
        'provider',
        'account_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'authorized_at',
        'reauth_required_at',
        'history_id',
        'last_synced_at',
        'oldest_synced_at',
        'history_completed_at',
    ];

    /**
     * OAuth credentials are decrypted by the casts below, so they must never
     * be serialized (Inertia props, JSON responses, logs, queue payloads).
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'authorized_at' => 'datetime',
        'reauth_required_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'oldest_synced_at' => 'datetime',
        'history_completed_at' => 'datetime',
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

    public function reconnectBy(): ?Carbon
    {
        if (! config('services.google.testing') || $this->authorized_at === null) {
            return null;
        }

        return $this->authorized_at->copy()->addDays(self::TESTING_TOKEN_LIFETIME_DAYS);
    }

    public function needsReauth(): bool
    {
        return $this->reauth_required_at !== null || ($this->reconnectBy()?->isPast() ?? false);
    }

    public function expiringSoon(): bool
    {
        $deadline = $this->reconnectBy();

        return $deadline !== null
            && ! $this->needsReauth()
            && now()->diffInDays($deadline) <= self::EXPIRING_SOON_DAYS;
    }

    /**
     * @return 'importing_recent'|'importing_history'|'complete'
     */
    public function syncState(): string
    {
        return match (true) {
            $this->history_id === null => 'importing_recent',
            $this->history_completed_at === null => 'importing_history',
            default => 'complete',
        };
    }

    /**
     * @return array{id: int, provider: string, account_email: string, last_synced_at: ?string, status: 'connected'|'needs_reauth', reconnect_by: ?string, expiring_soon: bool, sync: array{state: string, oldest_synced_at: ?string, last_synced_at: ?string}}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'account_email' => $this->account_email,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'status' => $this->needsReauth() ? 'needs_reauth' : 'connected',
            'reconnect_by' => $this->reconnectBy()?->toDateString(),
            'expiring_soon' => $this->expiringSoon(),
            'sync' => [
                'state' => $this->syncState(),
                'oldest_synced_at' => $this->oldest_synced_at?->toDateString(),
                'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            ],
        ];
    }
}
