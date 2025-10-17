<?php

namespace Carlosupreme\CEPQueryPayment;

use Carlosupreme\CEPQueryPayment\CEPQueryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class CEPQueryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(CEPQueryService::class, function ($app) {
            $scriptPath = __DIR__.'/../resources/js/cep-form-filler.js';

            // Create logger callback that uses Laravel's Log facade
            $logger = function (string $level, string $message, array $context = []) {
                Log::$level($message, $context);
            };

            return new CEPQueryService($scriptPath, $logger);
        });

        // Alias for backward compatibility
        $this->app->alias(CEPQueryService::class, 'cep-query');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
