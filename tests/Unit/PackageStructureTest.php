<?php

namespace Prahsys\Lunar\Tests\Unit;

use Prahsys\Lunar\LunarPrahsysServiceProvider;
use Prahsys\Lunar\Tests\TestCase;

class PackageStructureTest extends TestCase
{
    /** @test */
    public function service_provider_is_registered(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(LunarPrahsysServiceProvider::class)
        );
    }

    /** @test */
    public function config_is_published(): void
    {
        $this->artisan('vendor:publish', [
            '--provider' => LunarPrahsysServiceProvider::class,
            '--tag' => 'lunar-prahsys-config',
        ])->assertExitCode(0);

        $this->assertFileExists(config_path('lunar-prahsys.php'));
    }

    /** @test */
    public function package_has_correct_namespace(): void
    {
        $reflection = new \ReflectionClass(LunarPrahsysServiceProvider::class);
        $this->assertEquals('Prahsys\Lunar', $reflection->getNamespaceName());
    }

    /** @test */
    public function configuration_is_merged(): void
    {
        $this->assertIsArray(config('lunar-prahsys'));
        $this->assertTrue(config('lunar-prahsys.driver.enabled'));
    }

    /** @test */
    public function package_has_required_directories(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        
        $this->assertDirectoryExists($packageRoot . '/src');
        $this->assertDirectoryExists($packageRoot . '/tests');
        $this->assertDirectoryExists($packageRoot . '/config');
    }
}