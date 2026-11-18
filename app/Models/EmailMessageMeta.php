<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessageMeta extends Model
{
    use HasFactory;

    protected $table = 'email_messages_meta';

    protected $fillable = [
        'email_account_id',
        'provider_message_id',
        'provider_thread_id',
        'email_sender_id',
        'sender_email',
        'sender_domain',
        'subject',
        'labels',
        'received_at',
        'is_archived',
        'is_deleted',
    ];

    protected $casts = [
        'labels' => 'array',
        'received_at' => 'datetime',
        'is_archived' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(EmailSender::class, 'email_sender_id');
    }

    public function getGmailThreadUrlAttribute(): string
    {
        $accountEmail = urlencode($this->account->account_email ?? '');

        return "https://mail.google.com/mail/u/?authuser={$accountEmail}#all/{$this->provider_thread_id}";
    }
}
