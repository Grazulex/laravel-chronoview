<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\MissedRunDetector;
use Illuminate\Support\Carbon;

function seenTask(array $attributes = []): MonitoredTask
{
    return MonitoredTask::create(array_merge([
        'key' => sha1(uniqid('', true)),
        'name' => 'artisan inspire',
        'type' => TaskType::Command,
        'command' => 'artisan inspire',
        'expression' => '*/5 * * * *',
        'seen_at' => now(),
        'created_at' => now()->subDay(),
    ], $attributes));
}

beforeEach(function (): void {
    config()->set('chronoview.check.grace', 90);
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->detector = app(MissedRunDetector::class);
});

it('flags a due minute without any run once the grace period is over', function (): void {
    $task = seenTask();
    Carbon::setTestNow('2026-09-20 10:06:30'); // 10:05 due, grace 90s → 10:06:30
    $task->forceFill(['seen_at' => now()])->save(); // chronoview:check re-syncs before detecting

    $missed = $this->detector->detect();

    expect($missed)->toHaveCount(1)
        ->and($missed->first()?->status)->toBe(RunStatus::Missed)
        ->and($missed->first()?->expected_at?->toDateTimeString())->toBe('2026-09-20 10:05:00')
        ->and($task->fresh()?->last_status)->toBe(RunStatus::Missed);
});

it('waits for the grace period', function (): void {
    $task = seenTask();
    $task->runs()->create(['status' => RunStatus::Success, 'expected_at' => '2026-09-20 10:00:00', 'started_at' => '2026-09-20 10:00:01']);
    Carbon::setTestNow('2026-09-20 10:06:29');
    $task->forceFill(['seen_at' => now()])->save(); // chronoview:check re-syncs before detecting

    expect($this->detector->detect())->toBeEmpty();

    Carbon::setTestNow('2026-09-20 10:06:30');
    $task->forceFill(['seen_at' => now()])->save();

    $missed = $this->detector->detect();

    expect($missed)->toHaveCount(1)
        ->and($missed->first()?->expected_at?->toDateTimeString())->toBe('2026-09-20 10:05:00');
});

it('detects missed runs of every-minute tasks whose interval is shorter than the grace period', function (): void {
    $task = seenTask(['expression' => '* * * * *']);
    Carbon::setTestNow('2026-09-20 10:06:30');
    $task->forceFill(['seen_at' => now()])->save(); // chronoview:check re-syncs before detecting

    $missed = $this->detector->detect();

    expect($missed)->toHaveCount(1)
        ->and($missed->first()?->expected_at?->toDateTimeString())->toBe('2026-09-20 10:05:00');

    Carbon::setTestNow('2026-09-20 10:07:30');
    $task->forceFill(['seen_at' => now()])->save();

    $missed = $this->detector->detect();

    expect($missed)->toHaveCount(1)
        ->and($missed->first()?->expected_at?->toDateTimeString())->toBe('2026-09-20 10:06:00')
        ->and(TaskRun::where('status', RunStatus::Missed->value)->count())->toBe(2);
});

it('does not flag when a run matches the due minute', function (): void {
    $task = seenTask();
    $task->runs()->create(['status' => RunStatus::Success, 'expected_at' => '2026-09-20 10:05:00', 'started_at' => '2026-09-20 10:05:01']);
    Carbon::setTestNow('2026-09-20 10:07:00');

    expect($this->detector->detect())->toBeEmpty();
});

it('does not flag when a run started late but inside the window', function (): void {
    $task = seenTask();
    $task->runs()->create(['status' => RunStatus::Running, 'expected_at' => null, 'started_at' => '2026-09-20 10:06:50']);
    Carbon::setTestNow('2026-09-20 10:07:00');

    expect($this->detector->detect())->toBeEmpty();
});

it('does not flag when the task was skipped', function (): void {
    $task = seenTask();
    $task->runs()->create(['status' => RunStatus::Skipped, 'expected_at' => '2026-09-20 10:05:00']);
    Carbon::setTestNow('2026-09-20 10:07:00');

    expect($this->detector->detect())->toBeEmpty();
});

it('never flags the same due minute twice', function (): void {
    $task = seenTask();
    Carbon::setTestNow('2026-09-20 10:07:00');
    $task->forceFill(['seen_at' => now()])->save(); // chronoview:check re-syncs before detecting

    $this->detector->detect();
    $this->detector->detect();

    expect(TaskRun::where('status', RunStatus::Missed->value)->count())->toBe(1);
});

it('ignores paused tasks, tasks not seen recently and tasks created after the due minute', function (): void {
    seenTask(['paused_at' => now()]);
    seenTask(['seen_at' => now()->subHour()]);
    seenTask(['created_at' => '2026-09-20 10:05:30']);
    Carbon::setTestNow('2026-09-20 10:07:00');

    expect($this->detector->detect())->toBeEmpty();
});

it('does not flag a false missed run right after resuming a paused task', function (): void {
    Carbon::setTestNow('2026-09-20 15:00:00');
    seenTask(['expression' => '0 3 * * *', 'resumed_at' => '2026-09-20 15:00:00']);
    Carbon::setTestNow('2026-09-20 15:01:30');

    expect($this->detector->detect())->toBeEmpty();
});

it('still flags a missed run when the task was never resumed', function (): void {
    Carbon::setTestNow('2026-09-20 15:00:00');
    seenTask(['expression' => '0 3 * * *', 'resumed_at' => null]);
    Carbon::setTestNow('2026-09-20 15:01:30');

    $missed = $this->detector->detect();

    expect($missed)->toHaveCount(1)
        ->and($missed->first()?->expected_at?->toDateTimeString())->toBe('2026-09-20 03:00:00');
});

it('closes stale running runs as failed', function (): void {
    config()->set('chronoview.check.stale_after', 3600);
    $task = seenTask();
    $task->runs()->create(['status' => RunStatus::Running, 'started_at' => '2026-09-20 08:00:00']);
    $task->runs()->create(['status' => RunStatus::Running, 'started_at' => '2026-09-20 09:30:00']);

    $closed = $this->detector->closeStaleRuns();

    expect($closed)->toHaveCount(1)
        ->and($closed->first()?->status)->toBe(RunStatus::Failed)
        ->and(TaskRun::where('status', RunStatus::Running->value)->count())->toBe(1);
});
