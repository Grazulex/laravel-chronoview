<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Tests;

use Grazulex\ChronoView\ChronoViewServiceProvider;
use Grazulex\ChronoView\Facades\ChronoView;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ChronoViewServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['ChronoView' => ChronoView::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Laravel only bridges Symfony's ConsoleEvents::COMMAND to CommandStarting
        // outside of `runningUnitTests()` (Foundation\Console\Kernel::__construct()'s
        // `booted()` callback), which is always true under Testbench. ChronoView's
        // scheduler subscriber relies on CommandStarting, so the bridge is enabled
        // here — before the Artisan application is built (Kernel::getArtisan()
        // only attaches the Symfony dispatcher the first time it constructs it),
        // to make the test environment faithful to real (non-test) console runs.
        $app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
