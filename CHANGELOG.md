# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Bug Fixes

- Run now refuses a task restricted with `environments()` outside its environments, and the button is hidden there ([#3](https://github.com/Grazulex/laravel-chronoview/issues/3))
- tasks restricted with `environments()` are no longer flagged missed in the other environments; the task page says they are not scheduled here ([#4](https://github.com/Grazulex/laravel-chronoview/issues/4))

## [0.1.0](https://github.com/Grazulex/laravel-chronoview/releases/tag/v0.1.0) (2026-09-20)

### Features

- record every scheduler run (status, trigger, due time, duration, exit code, host, output, exception) by listening to the native scheduler events
- automatic sync of the schedule (type, readable name, cron in plain English, timezone, `runInBackground` / `withoutOverlapping` / `onOneServer` flags)
- human-readable cron descriptions for common expressions, with the raw expression as fallback
- missed-run detection and stale-run closing (`chronoview:check`, scheduled every minute by the package)
- per-host scheduler heartbeat with a red banner when `schedule:run` stopped
- dashboard: overview, tasks list with health filter, task detail with sparkline and paginated history, run detail with output and exception
- Run now (queued job) and Pause / Resume without redeploying
- public events `TaskRunFailed`, `TaskRunMissed`, `SchedulerDown` — `SchedulerDown` is dispatched once per outage, and again only after the host has beaten again
- commands `chronoview:install`, `chronoview:sync`, `chronoview:check`, `chronoview:prune`
- Resume records `resumed_at` so a task freshly unpaused is never flagged with a false missed run for the minutes it was paused
- embedded Alpine.js 3.17.3, no CDN
- email allow-list authorization (`CHRONOVIEW_ALLOWED_EMAILS`, `CHRONOVIEW_GUARD`)
- task description and source file on the task page
