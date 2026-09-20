<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Heartbeat;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

it('prunes old runs, unseen tasks and old heartbeats', function (): void {
    config()->set('chronoview.prune.keep_days', 14);
    Carbon::setTestNow('2026-09-20 10:00:00');

    $task = MonitoredTask::create([
        'key' => sha1('a'), 'name' => 'a', 'type' => TaskType::Closure, 'expression' => '* * * * *', 'seen_at' => now(),
    ]);
    $task->runs()->create(['status' => RunStatus::Success, 'started_at' => now()->subDays(20)]);
    $task->runs()->create(['status' => RunStatus::Missed, 'expected_at' => now()->subDays(15)]);
    $task->runs()->create(['status' => RunStatus::Success, 'started_at' => now()->subDays(2)]);

    $gone = MonitoredTask::create([
        'key' => sha1('b'), 'name' => 'b', 'type' => TaskType::Closure, 'expression' => '* * * * *', 'seen_at' => now()->subDays(30),
    ]);
    $gone->runs()->create(['status' => RunStatus::Success, 'started_at' => now()->subDay()]);

    $heartbeat = app(Heartbeat::class);
    Carbon::setTestNow('2026-08-01 10:00:00');
    $heartbeat->beat('old-host');
    Carbon::setTestNow('2026-09-20 10:00:00');
    $heartbeat->beat('web-1');

    $this->artisan('chronoview:prune')
        ->expectsOutputToContain('2 runs')
        ->expectsOutputToContain('1 task')
        ->assertSuccessful();

    expect(TaskRun::count())->toBe(1)
        ->and(MonitoredTask::count())->toBe(1)
        ->and($heartbeat->hosts()->pluck('hostname')->all())->toBe(['web-1']);
});

it('clamps --days to a minimum of one instead of pruning everything', function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $task = MonitoredTask::create([
        'key' => sha1('c'), 'name' => 'c', 'type' => TaskType::Closure, 'expression' => '* * * * *', 'seen_at' => now(),
    ]);
    $task->runs()->create(['status' => RunStatus::Success, 'started_at' => now()->subHours(2)]);

    $this->artisan('chronoview:prune', ['--days' => 0])
        ->expectsOutputToContain('older than 1 days')
        ->assertSuccessful();

    expect(TaskRun::count())->toBe(1);
});

it('removes stale output files left by interrupted runs', function (): void {
    $dir = app(ScheduleInspector::class)->outputDirectory();
    File::ensureDirectoryExists($dir);
    File::put($dir . '/old.log', 'x');
    File::put($dir . '/fresh.log', 'y');
    touch($dir . '/old.log', time() - 2 * 86400);

    $this->artisan('chronoview:prune')->expectsOutputToContain('1 stale output file')->assertSuccessful();

    expect(File::exists($dir . '/old.log'))->toBeFalse()
        ->and(File::exists($dir . '/fresh.log'))->toBeTrue();

    File::delete($dir . '/fresh.log');
});
