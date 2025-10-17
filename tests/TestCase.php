<?php

namespace Carlosupreme\CEPQueryPayment\Tests;

use Carlosupreme\CEPQueryPayment\CEPQueryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CEPQueryServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default environment
    }
}
