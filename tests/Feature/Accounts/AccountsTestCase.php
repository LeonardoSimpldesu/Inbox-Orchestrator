<?php

namespace Tests\Feature\Accounts;

use App\Contracts\EmailProviderInterface;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

abstract class AccountsTestCase extends TestCase
{
    protected FakeEmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
    }
}
