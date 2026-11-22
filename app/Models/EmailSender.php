<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSender extends Model
{
    use HasFactory;

    public const STATUS_NONE = 'none';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_NONE, self::STATUS_PROCESSING, self::STATUS_REQUESTED, self::STATUS_BLOCKED, self::STATUS_FAILED];

    public const PENDING_STATUSES = [self::STATUS_NONE, self::STATUS_PROCESSING, self::STATUS_FAILED];

    public const HANDLED_STATUSES = [self::STATUS_REQUESTED, self::STATUS_BLOCKED];

    protected $fillable = [
        'email_account_id',
        'email',
        'name',
        'domain',
        'total_messages_received',
        'total_messages_unread',
        'total_messages_promotional',
        'unsubscribe_mailto',
        'unsubscribe_url',
        'unsubscribe_one_click',
        'unsubscribe_status',
        'unsubscribe_method',
        'unsubscribe_requested_at',
        'unsubscribe_error',
        'label_applying_at',
        'last_email_received_at',
    ];

    protected $casts = [
        'last_email_received_at' => 'datetime',
        'total_messages_received' => 'integer',
        'total_messages_unread' => 'integer',
        'total_messages_promotional' => 'integer',
        'unsubscribe_requested_at' => 'datetime',
        'label_applying_at' => 'datetime',
        'unsubscribe_one_click' => 'boolean',
    ];

    /**
     * Senders the Subscription Hub shows: still sending (non-deleted mail) and
     * either offering an unsubscribe mechanism or landing in Gmail's Promotions.
     */
    public function scopeSubscriptions(Builder $query): void
    {
        $query->where('email_senders.total_messages_received', '>', 0)
            ->where(fn (Builder $q) => $q->whereNotNull('email_senders.unsubscribe_url')
                ->orWhereNotNull('email_senders.unsubscribe_mailto')
                ->orWhere('email_senders.total_messages_promotional', '>', 0));
    }

    /**
     * Everyone the Hub leaves out: still sending, with no unsubscribe mechanism
     * and nothing in Gmail's Promotions.
     */
    public function scopeNonSubscriptions(Builder $query): void
    {
        $query->where('email_senders.total_messages_received', '>', 0)
            ->whereNull('email_senders.unsubscribe_url')
            ->whereNull('email_senders.unsubscribe_mailto')
            ->where('email_senders.total_messages_promotional', 0);
    }

    /**
     * Case-insensitive match on name, e-mail or domain; LIKE wildcards in the term are literal.
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw("lower(email_senders.name) like ? escape '\\'", [$like])
            ->orWhereRaw("lower(email_senders.email) like ? escape '\\'", [$like])
            ->orWhereRaw("lower(email_senders.domain) like ? escape '\\'", [$like]));
    }

    public function scopePendingSubscriptions(Builder $query): void
    {
        $query->subscriptions()->whereIn('email_senders.unsubscribe_status', self::PENDING_STATUSES);
    }

    public function scopeHandledSubscriptions(Builder $query): void
    {
        $query->subscriptions()->whereIn('email_senders.unsubscribe_status', self::HANDLED_STATUSES);
    }

    /**
     * @return array{one_click: bool, mailto: bool, page: bool}
     */
    public function unsubscribeOptions(): array
    {
        return [
            'one_click' => $this->unsubscribe_one_click && $this->unsubscribe_url !== null,
            'mailto' => $this->unsubscribe_mailto !== null,
            'page' => $this->unsubscribe_url !== null,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function messagesMeta(): HasMany
    {
        return $this->hasMany(EmailMessageMeta::class, 'email_sender_id');
    }
}
