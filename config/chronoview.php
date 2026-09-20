<?php

declare(strict_types=1);

return [
    /*
     * Master switch. When false, no routes, no listeners, no internal schedule.
     */
    'enabled' => env('CHRONOVIEW_ENABLED', true),

    /*
     * URI prefix and optional domain of the dashboard.
     */
    'path' => env('CHRONOVIEW_PATH', 'chronoview'),
    'domain' => env('CHRONOVIEW_DOMAIN'),

    /*
     * Middleware stack of the dashboard routes. `chronoview.auth` enforces
     * ChronoView::auth() / the `viewChronoView` gate / local environment.
     */
    'middleware' => ['web', 'chronoview.auth'],

    /*
     * Auto-refresh interval of the pages, in seconds. 0 disables it.
     */
    'refresh' => (int) env('CHRONOVIEW_REFRESH', 15),

    /*
     * Database connection (null = default) and table prefix.
     */
    'connection' => env('CHRONOVIEW_DB_CONNECTION'),
    'table_prefix' => 'chronoview_',

    'record' => [
        // Capture the output of artisan/exec tasks (never of closures).
        'output' => true,
        // Bytes kept per run; the *end* of the output is kept when truncating.
        'max_output' => 64 * 1024,
        // Record ScheduledTaskSkipped as `skipped` runs.
        'skipped' => true,
    ],

    'check' => [
        // Register chronoview:check (every minute) and chronoview:prune (daily).
        'enabled' => true,
        // Seconds after the due minute before a run is considered missed.
        'grace' => 90,
        // Seconds without a heartbeat before the scheduler is considered down.
        'heartbeat_timeout' => 180,
        // Seconds after which a `running` run is closed as failed.
        'stale_after' => 6 * 3600,
    ],

    'actions' => [
        'run_now' => true,
        'queue_connection' => env('CHRONOVIEW_QUEUE_CONNECTION'),
        'queue' => env('CHRONOVIEW_QUEUE'),
        'pause' => true,
    ],

    'prune' => [
        'keep_days' => 14,
    ],
];
