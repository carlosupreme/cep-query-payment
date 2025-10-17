<?php

namespace Carlosupreme\CEPQueryPayment;

use Illuminate\Support\ServiceProvider;

class CEPQueryPaymentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cep-query-payment.php',
            'cep-query-payment'
        );

        $this->app->singleton(CEPQueryService::class, function ($app) {
            return new CEPQueryService(
                config('cep-query-payment')
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cep-query-payment.php' => config_path('cep-query-payment.php'),
            ], 'cep-query-payment-config');
        }
    }
}
