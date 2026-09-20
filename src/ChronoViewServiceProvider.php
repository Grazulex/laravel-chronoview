<?php

declare(strict_types=1);

namespace Grazulex\ChronoView;

use Illuminate\Support\ServiceProvider;

final class ChronoViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/chronoview.php', 'chronoview');

        $this->app->singleton(ChronoView::class, fn (): ChronoView => new ChronoView);
        $this->app->alias(ChronoView::class, 'chronoview');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/chronoview.php' => config_path('chronoview.php'),
        ], 'chronoview-config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
