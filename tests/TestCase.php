<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Backend tests never need compiled assets, so they don't depend on a Vite
     * dev server or a build (CI has neither).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
