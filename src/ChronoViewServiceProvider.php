<?php

declare(strict_types=1);

namespace Grazulex\ChronoView;

use Grazulex\ChronoView\Listeners\ScheduleEventSubscriber;
use Grazulex\ChronoView\Support\Heartbeat;
use Grazulex\ChronoView\Support\Recorder;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Contracts\Events\Dispatcher;
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

        /** @var Dispatcher $events */
        $events = $this->app['events'];
        $events->subscribe(ScheduleEventSubscriber::class);
    }
}
