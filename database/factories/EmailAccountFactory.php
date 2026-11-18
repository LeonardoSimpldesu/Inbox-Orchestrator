<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'gmail',
            'account_email' => Str::lower(fake()->unique()->userName()).'@gmail.com',
            'access_token' => 'ya29.'.Str::random(40),
            'refresh_token' => '1//'.Str::random(40),
            'token_expires_at' => now()->addHour(),
            'authorized_at' => now(),
        ];
    }

    public function needsReauth(): static
    {
        return $this->state(fn () => ['reauth_required_at' => now()]);
    }

    public function disconnected(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }
}
