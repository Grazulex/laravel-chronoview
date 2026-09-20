<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Grazulex\ChronoView\Support\TaskDefinition;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->schedule = app(Schedule::class);
    $this->inspector = app(ScheduleInspector::class);
});

it('lists definitions and ignores its own commands', function (): void {
    $this->schedule->command('inspire')->hourly();
    $this->schedule->command('chronoview:check')->everyMinute();
    $this->schedule->command('chronoview:prune')->daily();
    $this->schedule->call(fn () => null)->daily()->name('closure');

    $names = $this->inspector->definitions()->map(fn (TaskDefinition $d): string => $d->name)->all();

    expect($names)->toBe(['artisan inspire', 'closure']);
});

it('finds an event by key', function (): void {
    $event = $this->schedule->command('inspire')->hourly();
    $key = TaskDefinition::fromEvent($event)->key();

    expect($this->inspector->find($key))->toBe($event)
        ->and($this->inspector->find(sha1('nope')))->toBeNull();
});

it('redirects the output of commands to a per-task file, once', function (): void {
    $event = $this->schedule->command('inspire')->hourly();
    $closure = $this->schedule->call(fn () => null)->hourly()->name('c');
    $key = TaskDefinition::fromEvent($event)->key();

    $this->inspector->decorate($this->schedule);
    $this->inspector->decorate($this->schedule);

    expect($event->output)->toBe($this->inspector->outputPathFor($key))
        ->and($this->inspector->isOurOutput($event))->toBeTrue()
        ->and($closure->output)->toBe($closure->getDefaultOutput())
        ->and(File::isDirectory(storage_path('framework/chronoview')))->toBeTrue();
});

it('never overrides an output location chosen by the developer', function (): void {
    $event = $this->schedule->command('inspire')->hourly()->sendOutputTo('/tmp/chronoview-custom.log');

    $this->inspector->decorate($this->schedule);

    expect($event->output)->toBe('/tmp/chronoview-custom.log')
        ->and($this->inspector->isOurOutput($event))->toBeFalse();
});

it('does not capture output when disabled', function (): void {
    config()->set('chronoview.record.output', false);
    $event = $this->schedule->command('inspire')->hourly();

    $this->inspector->decorate($this->schedule);

    expect($event->output)->toBe($event->getDefaultOutput());
});

it('filters out paused tasks', function (): void {
    $event = $this->schedule->command('inspire')->hourly();
    $definition = TaskDefinition::fromEvent($event);
    MonitoredTask::create($definition->toArray() + ['key' => $definition->key(), 'paused_at' => now()]);

    $this->inspector->decorate($this->schedule);

    expect($event->filtersPass(app()))->toBeFalse();
});

it('loads paused keys once per process', function (): void {
    $event = $this->schedule->command('inspire')->hourly();
    $this->inspector->decorate($this->schedule);

    expect($event->filtersPass(app()))->toBeTrue();

    $definition = TaskDefinition::fromEvent($event);
    MonitoredTask::create($definition->toArray() + ['key' => $definition->key(), 'paused_at' => now()]);

    expect($event->filtersPass(app()))->toBeTrue();

    $this->inspector->forgetPausedKeys();

    expect($event->filtersPass(app()))->toBeFalse();
});

it('exposes the schedule from a non-console context', function (): void {
    $this->schedule->command('inspire')->hourly();

    expect($this->inspector->schedule())->toBe($this->schedule)
        ->and($this->inspector->events())->toHaveCount(1)
        ->and($this->inspector->definitions()->first()?->type)->toBe(TaskType::Command);
});

it('drops a .gitignore in the output directory so the host repository stays clean', function (): void {
    $this->schedule->command('inspire')->hourly();

    $this->inspector->decorate($this->schedule);

    $gitignore = $this->inspector->outputDirectory() . '/.gitignore';

    expect(File::exists($gitignore))->toBeTrue()
        ->and(File::get($gitignore))->toBe("*\n!.gitignore\n");
});

it('constructs the Artisan application when accessed outside the console', function (): void {
    $property = new ReflectionProperty($this->app::class, 'isRunningInConsole');
    $property->setAccessible(true);
    $property->setValue($this->app, false);

    // `ApplicationBuilder::withSchedule()` registers its closure through
    // `Artisan::starting(...)`, which only fires when the console Application is
    // actually constructed. Asserting our own starting callback fires proves
    // `schedule()` triggers that construction from a non-console context.
    $started = false;
    ConsoleApplication::starting(function () use (&$started): void {
        $started = true;
    });

    try {
        expect($this->inspector->schedule())->toBe($this->schedule)
            ->and($started)->toBeTrue();
    } finally {
        ConsoleApplication::forgetBootstrappers();
    }
});
