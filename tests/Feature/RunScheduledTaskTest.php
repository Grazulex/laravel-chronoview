<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\RunTrigger;
use Grazulex\ChronoView\Jobs\RunScheduledTask;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Recorder;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Grazulex\ChronoView\Support\TaskDefinition;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function (): void {
    $this->schedule = app(Schedule::class);
});

it('runs a closure now and records a manual run', function (): void {
    $ran = 0;
    $event = $this->schedule->call(function () use (&$ran): void {
        $ran++;
    })->monthly()->name('manual closure');

    (new RunScheduledTask(TaskDefinition::fromEvent($event)->key()))->handle(
        app(ScheduleInspector::class),
        app(Recorder::class),
        app(),
    );

    $run = TaskRun::firstOrFail();

    expect($ran)->toBe(1)
        ->and($run->trigger)->toBe(RunTrigger::Manual)
        ->and($run->status)->toBe(RunStatus::Success)
        ->and($run->expected_at)->toBeNull();
});

it('records a failing manual run', function (): void {
    $event = $this->schedule->call(fn () => throw new RuntimeException('manual boom'))->monthly()->name('manual ko');

    dispatch_sync(new RunScheduledTask(TaskDefinition::fromEvent($event)->key()));

    $run = TaskRun::firstOrFail();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->exception)->toContain('manual boom');
});

it('runs an artisan command now with its output', function (): void {
    $event = $this->schedule->command('inspire')->monthly();

    dispatch_sync(new RunScheduledTask(TaskDefinition::fromEvent($event)->key()));

    $run = TaskRun::firstOrFail();

    expect($run->status)->toBe(RunStatus::Success)
        ->and((string) $run->output)->not->toBe('');
});

it('runs a paused task anyway (manual trigger bypasses the pause)', function (): void {
    $ran = false;
    $event = $this->schedule->call(function () use (&$ran): void {
        $ran = true;
    })->monthly()->name('paused manual');
    $definition = TaskDefinition::fromEvent($event);
    MonitoredTask::create($definition->toArray() + ['key' => $definition->key(), 'paused_at' => now()]);

    dispatch_sync(new RunScheduledTask($definition->key()));

    expect($ran)->toBeTrue()->and(TaskRun::count())->toBe(1);
});

it('reports and stops when the task disappeared from the schedule', function (): void {
    dispatch_sync(new RunScheduledTask(sha1('gone')));

    expect(TaskRun::count())->toBe(0);
});

it('refuses to run a task restricted to another environment', function (): void {
    $ran = false;
    $event = $this->schedule->call(function () use (&$ran): void {
        $ran = true;
    })->monthly()->name('production only')->environments(['production']);

    dispatch_sync(new RunScheduledTask(TaskDefinition::fromEvent($event)->key()));

    expect($ran)->toBeFalse()->and(TaskRun::count())->toBe(0);
});
