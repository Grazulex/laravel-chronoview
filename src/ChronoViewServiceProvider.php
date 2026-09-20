<?php

declare(strict_types=1);

namespace Grazulex\ChronoView;

use Grazulex\ChronoView\Commands\CheckCommand;
use Grazulex\ChronoView\Commands\InstallCommand;
use Grazulex\ChronoView\Commands\PruneCommand;
use Grazulex\ChronoView\Commands\SyncCommand;
use Grazulex\ChronoView\Http\Middleware\Authorize;
use Grazulex\ChronoView\Listeners\ScheduleEventSubscriber;
use Grazulex\ChronoView\Support\Heartbeat;
use Grazulex\ChronoView\Support\MissedRunDetector;
use Grazulex\ChronoView\Support\Recorder;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ChronoViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/chronoview.php', 'chronoview');

        $this->app->singleton(ChronoView::class, fn (): ChronoView => new ChronoView);
        $this->app->alias(ChronoView::class, 'chronoview');

        $this->app->singleton(ScheduleInspector::class, fn ($app): ScheduleInspector => new ScheduleInspector($app));

        $this->app->singleton(Heartbeat::class, fn ($app): Heartbeat => new Heartbeat($app['db']));
        $this->app->singleton(Recorder::class, fn ($app): Recorder => new Recorder(
            $app->make(ScheduleInspector::class),
            $app->make(Heartbeat::class),
        ));

        $this->app->singleton(ScheduleEventSubscriber::class, fn ($app): ScheduleEventSubscriber => new ScheduleEventSubscriber(
            $app,
            $app->make(ScheduleInspector::class),
            $app->make(Recorder::class),
        ));

        $this->app->singleton(MissedRunDetector::class, fn ($app): MissedRunDetector => new MissedRunDetector($app->make(Recorder::class)));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/chronoview.php' => config_path('chronoview.php'),
        ], 'chronoview-config');

        if (! config('chronoview.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app['router']->aliasMiddleware('chronoview.auth', Authorize::class);

        Route::group([
            'domain' => config('chronoview.domain'),
            'prefix' => config('chronoview.path', 'chronoview'),
            'as' => 'chronoview.',
        ], fn () => $this->loadRoutesFrom(__DIR__ . '/../routes/web.php'));

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'chronoview');

        /** @var Dispatcher $events */
        $events = $this->app['events'];
        $events->subscribe(ScheduleEventSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckCommand::class,
                SyncCommand::class,
                PruneCommand::class,
                InstallCommand::class,
            ]);
        }

        if (config('chronoview.check.enabled', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('chronoview:check')->everyMinute()->name('chronoview:check');
                $schedule->command('chronoview:prune')->daily()->onOneServer()->name('chronoview:prune');
            });
        }
    }
}
