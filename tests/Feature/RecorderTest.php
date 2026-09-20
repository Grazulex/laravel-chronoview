<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\RunTrigger;
use Grazulex\ChronoView\Events\TaskRunFailed;
use Grazulex\ChronoView\Events\TaskRunMissed;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Recorder;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:05:20');
    $this->schedule = app(Schedule::class);
    $this->recorder = app(Recorder::class);
    $this->inspector = app(ScheduleInspector::class);
});

it('syncs the schedule into the tasks table', function (): void {
    $this->schedule->command('inspire')->everyFiveMinutes();
    $this->schedule->call(fn () => null)->daily()->name('nightly');
    $this->schedule->command('chronoview:check')->everyMinute();

    expect($this->recorder->syncSchedule())->toBe(2)
        ->and(MonitoredTask::count())->toBe(2)
        ->and(MonitoredTask::where('name', 'nightly')->first()?->seen_at?->toDateTimeString())->toBe('2026-09-20 10:05:20');

    Carbon::setTestNow('2026-09-20 10:06:20');
    $this->recorder->syncSchedule();

    expect(MonitoredTask::count())->toBe(2)
        ->and(MonitoredTask::where('name', 'nightly')->first()?->seen_at?->toDateTimeString())->toBe('2026-09-20 10:06:20');
});

it('records a successful command run with its output', function (): void {
    Event::fake([TaskRunFailed::class]);
    $event = $this->schedule->command('inspire')->everyFiveMinutes();
    $this->inspector->decorate($this->schedule);
    File::put($event->output, "Hello from inspire\n");

    $run = $this->recorder->starting($event);

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->trigger)->toBe(RunTrigger::Schedule)
        ->and($run->expected_at?->toDateTimeString())->toBe('2026-09-20 10:05:00')
        ->and($run->started_at?->toDateTimeString())->toBe('2026-09-20 10:05:20')
        ->and($run->hostname)->toBe(gethostname())
        ->and($run->task->last_status)->toBe(RunStatus::Running);

    Carbon::setTestNow('2026-09-20 10:05:22');
    $event->exitCode = 0;
    $closed = $this->recorder->finished($event, 1.5);

    expect($closed?->id)->toBe($run->id)
        ->and($closed?->status)->toBe(RunStatus::Success)
        ->and($closed?->exit_code)->toBe(0)
        ->and($closed?->duration_ms)->toBe(1500)
        ->and($closed?->output)->toBe("Hello from inspire\n")
        ->and($closed?->finished_at?->toDateTimeString())->toBe('2026-09-20 10:05:22')
        ->and(File::exists($event->output))->toBeFalse()
        ->and($closed?->task->fresh()?->last_status)->toBe(RunStatus::Success)
        ->and($closed?->task->fresh()?->consecutive_failures)->toBe(0);

    Event::assertNotDispatched(TaskRunFailed::class);
});

it('marks a non-zero exit code as failed and completes it with the later exception', function (): void {
    Event::fake([TaskRunFailed::class]);
    $event = $this->schedule->command('inspire')->everyFiveMinutes();

    $this->recorder->starting($event);
    $event->exitCode = 1;
    $run = $this->recorder->finished($event, 0.2);

    expect($run?->status)->toBe(RunStatus::Failed)
        ->and($run?->exit_code)->toBe(1)
        ->and($run?->exception)->toBeNull();

    $completed = $this->recorder->failed($event, new RuntimeException('Scheduled command failed with exit code [1].'));

    expect($completed->id)->toBe($run?->id)
        ->and($completed->exception)->toStartWith('RuntimeException: Scheduled command failed')
        ->and(TaskRun::count())->toBe(1)
        ->and($completed->task->fresh()?->consecutive_failures)->toBe(1);

    Event::assertDispatchedTimes(TaskRunFailed::class, 1);
});

it('records a closure that throws (no Finished event)', function (): void {
    Event::fake([TaskRunFailed::class]);
    $event = $this->schedule->call(fn () => throw new LogicException('boom'))->everyMinute()->name('explode');

    $this->recorder->starting($event);
    $run = $this->recorder->failed($event, new LogicException('boom'));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->exception)->toContain('LogicException: boom')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->task->fresh()?->last_status)->toBe(RunStatus::Failed);

    Event::assertDispatched(TaskRunFailed::class, fn (TaskRunFailed $e): bool => $e->run->is($run));
});

it('increments consecutive failures and resets them on success', function (): void {
    $event = $this->schedule->call(fn () => null)->everyMinute()->name('flaky');

    $this->recorder->starting($event);
    $this->recorder->failed($event, new RuntimeException('1'));
    $this->recorder->starting($event);
    $this->recorder->failed($event, new RuntimeException('2'));

    expect(MonitoredTask::first()?->consecutive_failures)->toBe(2);

    $this->recorder->starting($event);
    $event->exitCode = 0;
    $this->recorder->finished($event, 0.1);

    expect(MonitoredTask::first()?->consecutive_failures)->toBe(0);
});

it('keeps a background task running until the background finish', function (): void {
    $event = $this->schedule->command('inspire')->everyMinute()->runInBackground();

    $run = $this->recorder->starting($event);
    $still = $this->recorder->finished($event, 0.05);

    expect($still?->status)->toBe(RunStatus::Running);

    Carbon::setTestNow('2026-09-20 10:05:50');
    $event->exitCode = 0;
    $closed = $this->recorder->backgroundFinished($event);

    expect($closed?->id)->toBe($run->id)
        ->and($closed?->status)->toBe(RunStatus::Success)
        ->and($closed?->duration_ms)->toBe(30000);
});

it('records skipped runs unless disabled', function (): void {
    $event = $this->schedule->command('inspire')->everyFiveMinutes();

    $skip = $this->recorder->skipped($event);

    expect($skip?->status)->toBe(RunStatus::Skipped)
        ->and($skip?->expected_at?->toDateTimeString())->toBe('2026-09-20 10:05:00')
        ->and($skip?->started_at)->toBeNull()
        ->and($skip?->task->last_status)->toBeNull();

    config()->set('chronoview.record.skipped', false);

    expect($this->recorder->skipped($event))->toBeNull()
        ->and(TaskRun::count())->toBe(1);
});

it('records a missed run and dispatches an event', function (): void {
    Event::fake([TaskRunMissed::class]);
    $task = $this->recorder->resolveTask($this->schedule->command('inspire')->everyFiveMinutes());

    $run = $this->recorder->missed($task, Carbon::parse('2026-09-20 10:00:00'));

    expect($run->status)->toBe(RunStatus::Missed)
        ->and($run->expected_at?->toDateTimeString())->toBe('2026-09-20 10:00:00')
        ->and($task->fresh()?->last_status)->toBe(RunStatus::Missed)
        ->and($task->fresh()?->consecutive_failures)->toBe(1);

    Event::assertDispatched(TaskRunMissed::class);
});

it('closes a stale run as failed', function (): void {
    Event::fake([TaskRunFailed::class]);
    $event = $this->schedule->command('inspire')->everyMinute();
    $run = $this->recorder->starting($event);

    Carbon::setTestNow('2026-09-20 17:00:00');
    $closed = $this->recorder->closeStale($run);

    expect($closed->status)->toBe(RunStatus::Failed)
        ->and($closed->exception)->toContain('still running')
        ->and($closed->finished_at?->toDateTimeString())->toBe('2026-09-20 17:00:00');

    Event::assertDispatched(TaskRunFailed::class);
});

it('truncates long output keeping the end', function (): void {
    config()->set('chronoview.record.max_output', 100);
    $event = $this->schedule->command('inspire')->everyMinute();
    $this->inspector->decorate($this->schedule);
    File::put($event->output, str_repeat('a', 150) . 'END');

    $this->recorder->starting($event);
    $event->exitCode = 0;
    $run = $this->recorder->finished($event, 0.1);

    expect($run?->output)->toStartWith('[… truncated …]')
        ->and($run?->output)->toEndWith('END')
        ->and(strlen((string) $run?->output))->toBeLessThanOrEqual(100 + strlen("[… truncated …]\n"));
});

it('does not close a running run started on a different host', function (): void {
    $event = $this->schedule->command('inspire')->everyFiveMinutes();
    $task = $this->recorder->resolveTask($event);
    $run = $task->runs()->create([
        'status' => RunStatus::Running, 'started_at' => now(), 'hostname' => 'other-host',
    ]);

    $event->exitCode = 0;
    $closed = $this->recorder->finished($event, 0.1);

    expect($closed)->toBeNull()
        ->and($run->fresh()?->status)->toBe(RunStatus::Running);
});

it('records a manual run without an expected time', function (): void {
    $event = $this->schedule->command('inspire')->everyFiveMinutes();

    $run = $this->recorder->starting($event, RunTrigger::Manual);

    expect($run->trigger)->toBe(RunTrigger::Manual)
        ->and($run->expected_at)->toBeNull();
});
