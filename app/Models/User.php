<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Disabled users get no reset link. The endpoint still answers with the
     * same generic message, so nothing reveals the account state.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $this->notify(new ResetPassword($token));
    }

    /**
     * @return HasMany<EmailAccount, $this>
     */
    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    /**
     * Senders of every e-mail account owned by this user.
     *
     * @return HasManyThrough<EmailSender, EmailAccount, $this>
     */
    public function senders(): HasManyThrough
    {
        return $this->hasManyThrough(EmailSender::class, EmailAccount::class);
    }

    /**
     * Retention rules of every e-mail account owned by this user.
     *
     * @return HasManyThrough<RetentionRule, EmailAccount, $this>
     */
    public function retentionRules(): HasManyThrough
    {
        return $this->hasManyThrough(RetentionRule::class, EmailAccount::class);
    }
}
