<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Heartbeat;
use Illuminate\Support\Carbon;

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
