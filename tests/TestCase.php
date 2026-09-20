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
    protected function setUp(): void
    {
        // Some tests (e.g. ScheduleIntegrationTest, RunScheduledTaskTest) let the
        // scheduler spawn a *real* `php artisan ...` subprocess against the
        // testbench skeleton (vendor/orchestra/testbench-core/laravel). That
        // skeleton's own bootstrap/autoload.php falls back to a `vendor/autoload.php`
        // inside the skeleton itself when TESTBENCH_WORKING_PATH isn't set — a path
        // that only exists there via a `vendor` symlink that testbench's CLI
        // (`vendor/bin/testbench`) leaves behind after a manual invocation. On a
        // clean checkout (CI, or any fresh `composer install`) that symlink is
        // absent, so the subprocess dies instantly with a fatal autoload error and
        // every scheduled artisan-command test is (correctly) recorded as failed.
        // Exporting TESTBENCH_WORKING_PATH here makes the subprocess boot the real
        // package/vendor tree, exactly like `vendor/bin/testbench` does, regardless
        // of whether that symlink happens to exist.
        //
        // It must be set on $_ENV (not just via putenv()): Symfony Process builds
        // the child's environment from `$_ENV + array_intersect_key(getenv(), $_SERVER)`
        // (see Process::getDefaultEnv()), so a putenv()-only variable that isn't
        // already a $_SERVER key is silently dropped before it reaches the
        // subprocess.
        if (! is_string(getenv('TESTBENCH_WORKING_PATH'))) {
            $workingPath = dirname(__DIR__);

            putenv('TESTBENCH_WORKING_PATH=' . $workingPath);
            $_ENV['TESTBENCH_WORKING_PATH'] = $workingPath;
        }

        parent::setUp();
    }

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
