<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSender extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'email',
        'name',
        'domain',
        'total_messages_received',
        'total_messages_unread',
        'unsubscribe_mailto',
        'unsubscribe_url',
        'unsubscribe_status',
        'last_email_received_at',
    ];

    protected $casts = [
        'last_email_received_at' => 'datetime',
        'total_messages_received' => 'integer',
        'total_messages_unread' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function messagesMeta(): HasMany
    {
        return $this->hasMany(EmailMessageMeta::class, 'email_sender_id');
    }
}
