<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Support\TaskDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Inspiring;

final class ChronoViewFakeJob implements ShouldQueue
{
    public function handle(): void {}
}

beforeEach(function (): void {
    // Testbench's skeleton config defaults app.timezone to 'UTC', which the
    // Schedule constructor forwards to every Event. Reset it to null so a
    // task with no explicit ->timezone() call round-trips to null, matching
    // a real application that leaves app.timezone unset.
    config(['app.timezone' => null]);
    $this->schedule = app(Schedule::class);
});

it('describes an artisan command by its normalized command string', function (): void {
    $event = $this->schedule->command('inspire')->everyFiveMinutes();
    $definition = TaskDefinition::fromEvent($event);

    expect($definition->type)->toBe(TaskType::Command)
        ->and($definition->name)->toBe('artisan inspire')
        ->and($definition->command)->toBe('artisan inspire')
        ->and($definition->expression)->toBe('*/5 * * * *')
        ->and($definition->timezone)->toBeNull()
        ->and($definition->key())->toBe(sha1('command|artisan inspire|*/5 * * * *|'));
});

it('keeps command arguments in the normalized command', function (): void {
    $event = $this->schedule->command('queue:prune-batches', ['--hours' => 48])->daily();

    // Schedule::compileParameters() skips escapeArgument() for numeric values
    // (Illuminate\Console\Scheduling\Schedule::compileParameters), so an int
    // flag value is compiled unquoted.
    expect(TaskDefinition::fromEvent($event)->name)->toBe('artisan queue:prune-batches --hours=48');
});

it('prefers the explicit name of a command', function (): void {
    $event = $this->schedule->command('inspire')->hourly()->name('Daily inspiration');

    expect(TaskDefinition::fromEvent($event)->name)->toBe('Daily inspiration')
        ->and(TaskDefinition::fromEvent($event)->command)->toBe('artisan inspire');
});

it('describes an exec task', function (): void {
    $event = $this->schedule->exec('ls -la')->hourly();
    $definition = TaskDefinition::fromEvent($event);

    expect($definition->type)->toBe(TaskType::Exec)
        ->and($definition->name)->toBe('ls -la')
        ->and($definition->command)->toBe('ls -la');
});

it('describes a closure by its file and line', function (): void {
    // Line captured via reflection on the closure itself, not on adjacent
    // source lines: Pint is free to reformat this statement across lines
    // without decoupling the assertion from the closure's real start line.
    $closure = fn () => Inspiring::quote();
    $line = (new ReflectionFunction($closure))->getStartLine();
    $event = $this->schedule->call($closure)->everyMinute();
    $definition = TaskDefinition::fromEvent($event);

    // Testbench's base_path() points at the vendor skeleton, so this test
    // file is not under it and the name keeps its absolute path; assert on
    // the stable suffix instead (brief Step 4 note).
    expect($definition->type)->toBe(TaskType::Closure)
        ->and($definition->name)->toEndWith('tests/Unit/TaskDefinitionTest.php:' . $line)
        ->and($definition->command)->toBeNull();
});

it('uses the name of a named closure', function (): void {
    $event = $this->schedule->call(fn () => null)->everyMinute()->name('cleanup');

    expect(TaskDefinition::fromEvent($event)->name)->toBe('cleanup')
        ->and(TaskDefinition::fromEvent($event)->type)->toBe(TaskType::Closure);
});

it('recognises a queued job', function (): void {
    $event = $this->schedule->job(ChronoViewFakeJob::class)->everyTenMinutes();
    $definition = TaskDefinition::fromEvent($event);

    expect($definition->type)->toBe(TaskType::Job)
        ->and($definition->name)->toBe(ChronoViewFakeJob::class);
});

it('captures flags and timezone', function (): void {
    $event = $this->schedule->command('inspire')->dailyAt('03:00')
        ->timezone('Europe/Brussels')->runInBackground()->withoutOverlapping()->onOneServer();
    $definition = TaskDefinition::fromEvent($event);

    expect($definition->timezone)->toBe('Europe/Brussels')
        ->and($definition->runInBackground)->toBeTrue()
        ->and($definition->withoutOverlapping)->toBeTrue()
        ->and($definition->onOneServer)->toBeTrue()
        ->and($definition->key())->toBe(sha1('command|artisan inspire|0 3 * * *|Europe/Brussels'));
});

it('accepts a DateTimeZone instance', function (): void {
    expect(TaskDefinition::timezoneName(new DateTimeZone('UTC')))->toBe('UTC')
        ->and(TaskDefinition::timezoneName('Europe/Paris'))->toBe('Europe/Paris')
        ->and(TaskDefinition::timezoneName(null))->toBeNull();
});

it('exports the columns of the tasks table', function (): void {
    $event = $this->schedule->command('inspire')->hourly();

    expect(TaskDefinition::fromEvent($event)->toArray())->toHaveKeys([
        'name', 'type', 'command', 'expression', 'timezone', 'description',
        'run_in_background', 'without_overlapping', 'on_one_server',
    ]);
});
