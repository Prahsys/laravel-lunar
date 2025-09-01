<?php

namespace Prahsys\Lunar\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Prahsys\Clerk\ClerkServiceProvider;
use Prahsys\Lunar\LunarPrahsysServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ClerkServiceProvider::class,
            LunarPrahsysServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set test configuration
        config()->set('clerk.api.api_key', 'test_key_123');
        config()->set('lunar-prahsys.driver.enabled', true);
    }
}