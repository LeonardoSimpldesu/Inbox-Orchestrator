<?php

namespace App\Services\Providers;

use Carbon\CarbonImmutable;

final readonly class OAuthGrant
{
    /**
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public CarbonImmutable $expiresAt,
        public array $grantedScopes,
        public string $email,
    ) {}

    /**
     * @param  list<string>  $required
     */
    public function hasScopes(array $required): bool
    {
        return array_diff($required, $this->grantedScopes) === [];
    }
}
