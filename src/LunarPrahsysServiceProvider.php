<?php

namespace Prahsys\Lunar;

use Illuminate\Support\ServiceProvider;
use Lunar\Facades\Payments;
use Prahsys\Lunar\PaymentTypes\PrahsysPaymentType;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;

class LunarPrahsysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/lunar-prahsys.php', 
            'lunar-prahsys'
        );

        $this->app->singleton(PrahsysPaymentDriver::class);
        $this->app->singleton(PrahsysPaymentType::class);
    }

    public function boot(): void
    {
        // Register payment driver with Lunar
        if (class_exists(Payments::class)) {
            Payments::extend('prahsys', function ($app) {
                return $app->make(PrahsysPaymentType::class);
            });
        }

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/lunar-prahsys.php' => config_path('lunar-prahsys.php'),
        ], 'lunar-prahsys-config');
        
        // Publish views
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/lunar-prahsys'),
        ], 'lunar-prahsys-views');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lunar-prahsys');

        // Register view components
        $this->loadViewComponentsAs('lunar-prahsys', [
            'checkout-button' => \Prahsys\Lunar\View\Components\CheckoutButton::class,
        ]);
    }

    public function provides(): array
    {
        return [
            PrahsysPaymentType::class,
        ];
    }
}