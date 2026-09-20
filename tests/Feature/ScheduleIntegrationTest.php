<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Events\TaskRunFailed;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\TaskDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->schedule = app(Schedule::class);
});

it('records a successful closure run through schedule:run', function (): void {
    $this->schedule->call(fn (): string => 'ok')->everyMinute()->name('closure ok');

    $this->artisan('schedule:run')->assertSuccessful();

    $task = MonitoredTask::where('name', 'closure ok')->firstOrFail();
    $run = $task->runs()->firstOrFail();

    expect($run->status)->toBe(RunStatus::Success)
        ->and($run->exit_code)->toBe(0)
        ->and($run->duration_ms)->not->toBeNull()
        ->and($task->last_status)->toBe(RunStatus::Success);
});

it('records a throwing closure as failed with its exception', function (): void {
    Event::fake([TaskRunFailed::class]);
    $this->schedule->call(function (): void {
        throw new RuntimeException('kaboom');
    })->everyMinute()->name('closure ko');

    $this->artisan('schedule:run');

    $run = TaskRun::firstOrFail();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->exception)->toContain('RuntimeException: kaboom')
        ->and(TaskRun::count())->toBe(1);

    Event::assertDispatchedTimes(TaskRunFailed::class, 1);
});

it('records an artisan command with its captured output', function (): void {
    $this->schedule->command('inspire')->everyMinute();

    $this->artisan('schedule:run')->assertSuccessful();

    $run = TaskRun::firstOrFail();

    expect($run->status)->toBe(RunStatus::Success)
        ->and($run->task->name)->toBe('artisan inspire')
        ->and((string) $run->output)->not->toBe('');
});

it('records a failing exec command once, as failed', function (): void {
    Event::fake([TaskRunFailed::class]);
    $this->schedule->exec('exit 3')->everyMinute();

    $this->artisan('schedule:run');

    $run = TaskRun::firstOrFail();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->exit_code)->toBe(3)
        ->and($run->exception)->toContain('exit code [3]')
        ->and(TaskRun::count())->toBe(1);

    Event::assertDispatchedTimes(TaskRunFailed::class, 1);
});

it('records a skipped task', function (): void {
    $this->schedule->call(fn () => null)->everyMinute()->name('filtered')->when(fn (): bool => false);

    $this->artisan('schedule:run');

    expect(TaskRun::firstOrFail()->status)->toBe(RunStatus::Skipped);
});

it('does not run a paused task and does not record the skip', function (): void {
    $event = $this->schedule->call(fn () => throw new LogicException('should not run'))->everyMinute()->name('paused one');
    $definition = TaskDefinition::fromEvent($event);
    MonitoredTask::create($definition->toArray() + ['key' => $definition->key(), 'paused_at' => now()]);

    $this->artisan('schedule:run')->assertSuccessful();

    expect(TaskRun::count())->toBe(0);
});

it('never records its own commands', function (): void {
    $this->schedule->command('chronoview:sync')->everyMinute();

    $this->artisan('schedule:run');

    expect(MonitoredTask::count())->toBe(0);
});

it('keeps the scheduler working when the monitoring storage is broken', function (): void {
    config()->set('chronoview.table_prefix', 'missing_');
    $ran = false;
    $this->schedule->call(function () use (&$ran): void {
        $ran = true;
    })->everyMinute()->name('resilient');

    $this->artisan('schedule:run')->assertSuccessful();

    expect($ran)->toBeTrue();
});
