# Laravel ChronoView

> [!TIP]
> **What Laravel ChronoView does for you** — Stop discovering that a scheduled task silently stopped running weeks ago. ChronoView gives your Laravel scheduler a Horizon-style dashboard: every run, its output, its failures — and the runs that **never happened**. Zero changes to your tasks.
>
> **This package is free and maintained on my own time.** If it saves you hours, a small contribution helps me keep it going:
> [💖 GitHub Sponsors](https://github.com/sponsors/Grazulex) · [☕ Buy Me a Coffee](https://buymeacoffee.com/grazulex) · [PayPal](https://paypal.me/strauven)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/grazulex/laravel-chronoview.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-chronoview)
[![Tests](https://github.com/grazulex/laravel-chronoview/actions/workflows/tests.yml/badge.svg)](https://github.com/grazulex/laravel-chronoview/actions/workflows/tests.yml)
[![Static Analysis](https://github.com/grazulex/laravel-chronoview/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/grazulex/laravel-chronoview/actions/workflows/static-analysis.yml)
[![Code Style](https://github.com/grazulex/laravel-chronoview/actions/workflows/code-style.yml/badge.svg)](https://github.com/grazulex/laravel-chronoview/actions/workflows/code-style.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/grazulex/laravel-chronoview.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-chronoview)
[![License](https://img.shields.io/packagist/l/grazulex/laravel-chronoview.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-chronoview)

> Horizon, but for the scheduler.

## Features

- **Zero instrumentation** — listens to the native scheduler events; no `->monitor()` to chain on every task
- **Every run recorded** — status, trigger, due time, start, end, duration, exit code, host, captured output, exception
- **Missed-run detection** — a due minute with no run and no skip is flagged as `missed`
- **Scheduler heartbeat** — the dashboard turns red when `schedule:run` itself stopped
- **Human-readable cron** — common expressions (`* * * * *`, `0 */2 * * *`, …) are described in plain English, with the raw expression as fallback
- **Run now & Pause / Resume** — without redeploying
- **Public events** — `TaskRunFailed`, `TaskRunMissed`, `SchedulerDown` for your own alerts
- **Self-contained UI** — Blade + embedded Alpine.js 3.17.3, light/dark theme, no build step, no CDN
- **Database storage** — any connection, automatic pruning; no Redis required

## Requirements

- PHP 8.3+
- Laravel 12.x or 13.x

## Installation

```bash
composer require grazulex/laravel-chronoview
php artisan chronoview:install
```

That's it. Keep your usual cron entry (`* * * * * php artisan schedule:run`) — ChronoView schedules its own `chronoview:check` (every minute) and `chronoview:prune` (daily).

Open `/chronoview`. In `local` it is open; elsewhere, authorise access like Horizon:

```php
// AppServiceProvider::boot()
ChronoView::auth(fn ($request) => $request->user()?->isAdmin());

// or with a gate
Gate::define('viewChronoView', fn (User $user) => $user->isAdmin());
```

## Screens

| Overview | Task |
|---|---|
| Scheduler alive/down, 24 h KPIs, tasks needing attention, up next, recent problems | Cron in plain English, 7-day summary, duration sparkline, paginated history, Run now / Pause |

*(screenshots to add before the LinkedIn / Laravel News post)*

## How it works

ChronoView never touches `routes/console.php`. It only listens to the scheduler's own events and records what it sees.

**1. Decoration, at the start of `schedule:run`/`work`/`test`/`finish`.** `ScheduleEventSubscriber` listens to `CommandStarting` for these four commands. At that exact moment the schedule is fully built (`routes/console.php` has already run), so `ScheduleInspector::decorate()` can safely walk every event and:
- add a `->when(...)` filter that skips the task if its key is in the current list of paused tasks (loaded once per process, so a pause becomes effective on the very next `schedule:run`);
- redirect its output to `storage/framework/chronoview/<key>.log`, but only if nothing has redirected it yet — your own `sendOutputTo()` is never overridden, and closures are never captured.

Any earlier hook (e.g. `afterResolving(Schedule::class)`) would fire before `routes/console.php` runs and would miss tasks, which is why the decoration happens on `CommandStarting` instead.

**2. Recording a run.** For every due task:
- `ScheduledTaskStarting` → `Recorder::starting()` inserts a `running` row (expected due time, host, trigger `schedule` or `manual`);
- `ScheduledTaskFinished` → `Recorder::finished()` finds that task's latest `running` row **in the database** (never in memory), closes it `success` or `failed` from the exit code, and reads/truncates the captured output;
- `ScheduledTaskFailed` (thrown exceptions — closures, or an artisan command whose non-zero exit code Laravel turns into an exception) → `Recorder::failed()` attaches the exception trace to that same row. A failing artisan command therefore produces `Finished` (closes the run as `failed`) then `Failed` (adds the exception): **one row**, not two;
- `ScheduledTaskSkipped` → `Recorder::skipped()` records a `skipped` row, unless the skip was caused by ChronoView's own pause filter (that one is not worth logging);
- `ScheduledBackgroundTaskFinished`, fired by `schedule:finish` in a **different process** for `runInBackground` tasks → `Recorder::finished()`'s database lookup is exactly what lets that other process find and close the same `running` row.

**3. The control loop.** ChronoView registers its own `chronoview:check` command, every minute with `withoutOverlapping()`, and `chronoview:prune`, daily with `onOneServer()`. Each run of `chronoview:check`:
1. records a heartbeat for the current host;
2. re-syncs every task of the schedule (upsert, `seen_at = now`);
3. runs `MissedRunDetector::detect()` — for every active task seen recently, it looks at the last due minute older than `check.grace` seconds and flags it `missed` unless a run or a skip already exists for it, or the task was created after that minute (a brand-new task can't have missed a run that predates it);
4. closes any `running` row older than `check.stale_after` as `failed` (a run that never got its `Finished`/`Failed` event — crashed worker, killed process);
5. dispatches `SchedulerDown` for any other host whose heartbeat is older than `check.heartbeat_timeout`.

If the cron itself stops, `chronoview:check` stops too — that failure is caught separately by the heartbeat banner on the dashboard, not by `SchedulerDown` (which only covers *other* hosts in a multi-server setup).

Migrations are loaded automatically (`loadMigrationsFrom`); `chronoview:install` publishes the config, runs `migrate` and calls `chronoview:sync`.

Access is checked by `ChronoView::check()`: your `ChronoView::auth()` callback if you registered one, otherwise the `viewChronoView` gate if it is defined, otherwise the `local` environment only.

## Configuration

Publish the config with `php artisan vendor:publish --tag=chronoview-config` (done automatically by `chronoview:install`). All values can be overridden per key.

| Key | Default | Role |
|---|---|---|
| `enabled` | `true` (`CHRONOVIEW_ENABLED`) | Master switch. `false` disables routes, listeners and the internal schedule entirely. |
| `path` | `chronoview` (`CHRONOVIEW_PATH`) | URI prefix of the dashboard. |
| `domain` | `null` (`CHRONOVIEW_DOMAIN`) | Optional domain for the dashboard routes. |
| `middleware` | `['web', 'chronoview.auth']` | Middleware stack of the dashboard routes. `chronoview.auth` enforces `ChronoView::auth()` / the `viewChronoView` gate / the `local` environment. |
| `refresh` | `15` (`CHRONOVIEW_REFRESH`) | Auto-refresh interval of the dashboard pages, in seconds. `0` disables it. |
| `connection` | `null` (`CHRONOVIEW_DB_CONNECTION`) | Database connection used for ChronoView's tables. `null` means the app's default connection. |
| `table_prefix` | `chronoview_` | Prefix of every table ChronoView creates. |
| `record.output` | `true` | Capture the output of artisan/`exec` tasks. Closures are never captured. |
| `record.max_output` | `65536` (64 KB) | Bytes of output kept per run. When truncating, the *end* of the output is kept. |
| `record.skipped` | `true` | Record `ScheduledTaskSkipped` as `skipped` runs (except skips caused by ChronoView's own pause). |
| `check.enabled` | `true` | Register the internal `chronoview:check` (every minute) and `chronoview:prune` (daily) schedule entries. |
| `check.grace` | `90` | Seconds after a due minute before a task with no run and no skip is flagged `missed`. |
| `check.heartbeat_timeout` | `180` | Seconds without a heartbeat before a host's scheduler is considered down (`SchedulerDown`). |
| `check.stale_after` | `21600` (6 h) | Seconds after which a `running` run with no closing event is force-closed as `failed`. |
| `actions.run_now` | `true` | Enable the "Run now" button/action. |
| `actions.queue_connection` | `null` (`CHRONOVIEW_QUEUE_CONNECTION`) | Queue connection used by the `RunScheduledTask` job dispatched by "Run now". |
| `actions.queue` | `null` (`CHRONOVIEW_QUEUE`) | Queue name used by the `RunScheduledTask` job. |
| `actions.pause` | `true` | Enable the Pause / Resume actions. |
| `prune.keep_days` | `14` | Days of history kept by `chronoview:prune` (runs, tasks no longer seen, stale heartbeats). |

## Events

```php
Event::listen(TaskRunFailed::class, fn ($e) => Notification::route('slack', '…')->notify(new TaskFailed($e->run)));
Event::listen(TaskRunMissed::class, fn ($e) => /* $e->run->task->name, $e->run->expected_at */);
Event::listen(SchedulerDown::class, fn ($e) => /* $e->hostname, $e->lastBeatAt */);
```

## Actions

- **Run now** dispatches the queued job `RunScheduledTask`, which replays `starting`/`finished`/`failed` through the same `Recorder` as a real scheduled run (trigger `manual`). It honours `actions.queue_connection` and `actions.queue`.
- **Pause / Resume** flip a flag read once per `schedule:run` process, so the effect is visible from the next run of the scheduler, not instantly.

## FAQ

**Why is a run marked missed?** No run and no skip was recorded for that due minute within `check.grace` seconds (90 by default) and the task was already known before that minute. Typical causes: the cron stopped (see the heartbeat banner), `withoutOverlapping` held the task, or the task is filtered by `environments()` on a server where `record.skipped` is disabled.

**A task is still `running` after hours.** `chronoview:check` closes runs older than `check.stale_after` (6 h) as failed. Lower it if your tasks are always short.

**Does it capture the output of closures?** No — only artisan commands and `exec()` tasks. Laravel redirects their stdout to a file; ChronoView reads it and deletes it. Your own `sendOutputTo()` is never overridden.

**Does it slow down the scheduler?** One `UPDATE` at task start, one at the end, and a single `SELECT` of paused keys per `schedule:run` process.

**Which timezone are the times in?** The application timezone by default (shown in the header); click the clock button to switch to your browser's local time. Each task also shows its own scheduling timezone.

## Support This Package

[💖 GitHub Sponsors](https://github.com/sponsors/Grazulex) · [☕ Buy Me a Coffee](https://buymeacoffee.com/grazulex) · [PayPal](https://paypal.me/strauven)

## License

MIT — see [LICENSE.md](LICENSE.md).
