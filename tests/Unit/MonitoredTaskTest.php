<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\Health;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Illuminate\Support\Carbon;

function makeTask(array $attributes = []): MonitoredTask
{
    return MonitoredTask::create(array_merge([
        'key' => sha1(uniqid('', true)),
        'name' => 'artisan inspire',
        'type' => TaskType::Command,
        'command' => 'artisan inspire',
        'expression' => '*/5 * * * *',
        'timezone' => null,
    ], $attributes));
}

it('uses the configured table prefix', function (): void {
    expect((new MonitoredTask)->getTable())->toBe('chronoview_tasks')
        ->and((new TaskRun)->getTable())->toBe('chronoview_runs');
});

it('reports health from its state', function (): void {
    expect(makeTask()->health())->toBe(Health::Unknown)
        ->and(makeTask(['paused_at' => now()])->health())->toBe(Health::Paused)
        ->and(makeTask(['last_status' => RunStatus::Failed])->health())->toBe(Health::Unhealthy)
        ->and(makeTask(['last_status' => RunStatus::Missed])->health())->toBe(Health::Unhealthy)
        ->and(makeTask(['last_status' => RunStatus::Running])->health())->toBe(Health::Running)
        ->and(makeTask(['last_status' => RunStatus::Success])->health())->toBe(Health::Healthy);
});

it('computes the previous due minute and the next run', function (): void {
    Carbon::setTestNow('2026-09-20 10:07:30');
    $task = makeTask(['expression' => '*/5 * * * *']);

    expect($task->previousDueAt(now())->toDateTimeString())->toBe('2026-09-20 10:05:00')
        ->and($task->nextRunAt()->toDateTimeString())->toBe('2026-09-20 10:10:00');
});

it('treats the current minute as due when it matches', function (): void {
    Carbon::setTestNow('2026-09-20 10:05:00');
    $task = makeTask(['expression' => '*/5 * * * *']);

    expect($task->previousDueAt(now())->toDateTimeString())->toBe('2026-09-20 10:05:00');
});

it('honours the task timezone when computing due dates', function (): void {
    Carbon::setTestNow('2026-09-20 22:30:00'); // UTC
    $task = makeTask(['expression' => '0 1 * * *', 'timezone' => 'Europe/Brussels']);

    // 01:00 Brussels (CEST, UTC+2) = 23:00 UTC the day before
    expect($task->previousDueAt(now())->toDateTimeString())->toBe('2026-09-19 23:00:00')
        ->and($task->nextRunAt()->toDateTimeString())->toBe('2026-09-20 23:00:00');
});

it('exposes a human readable cron description', function (): void {
    expect(makeTask(['expression' => '*/5 * * * *'])->cronDescription())->toBe('Every 5 minutes');
});

it('scopes active and paused tasks', function (): void {
    makeTask();
    makeTask(['paused_at' => now()]);

    expect(MonitoredTask::active()->count())->toBe(1)
        ->and(MonitoredTask::paused()->count())->toBe(1);
});

it('flags failed and missed runs as problems', function (): void {
    $task = makeTask();
    $task->runs()->create(['status' => RunStatus::Success, 'started_at' => now()]);
    $task->runs()->create(['status' => RunStatus::Failed, 'started_at' => now()]);
    $task->runs()->create(['status' => RunStatus::Missed, 'expected_at' => now()]);

    expect(TaskRun::problems()->count())->toBe(2)
        ->and(TaskRun::problems()->first()?->isProblem())->toBeTrue();
});
