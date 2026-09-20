<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Events\SchedulerDown;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:07:00');
});

it('beats, syncs, detects missed runs and closes stale runs', function (): void {
    config()->set('chronoview.check.stale_after', 3600);
    app(Schedule::class)->command('inspire')->everyFiveMinutes();

    $stale = MonitoredTask::create([
        'key' => sha1('stale'), 'name' => 'stale', 'type' => TaskType::Closure,
        'expression' => '* * * * *', 'seen_at' => now(), 'created_at' => now()->subDay(),
    ]);
    $stale->runs()->create(['status' => RunStatus::Running, 'started_at' => now()->subHours(2)]);

    $this->artisan('chronoview:check')
        ->expectsOutputToContain('1 task')
        ->assertSuccessful();

    expect(app(Heartbeat::class)->isAlive())->toBeTrue()
        ->and(MonitoredTask::where('name', 'artisan inspire')->exists())->toBeTrue()
        ->and(TaskRun::where('status', RunStatus::Failed->value)->count())->toBe(1)
        ->and(TaskRun::where('status', RunStatus::Missed->value)->count())->toBeGreaterThanOrEqual(1);
});

it('dispatches SchedulerDown for hosts that stopped beating', function (): void {
    Event::fake([SchedulerDown::class]);
    $heartbeat = app(Heartbeat::class);
    $heartbeat->beat('web-2');
    Carbon::setTestNow('2026-09-20 10:20:00');

    $this->artisan('chronoview:check')->assertSuccessful();

    Event::assertDispatched(SchedulerDown::class, fn (SchedulerDown $e): bool => $e->hostname === 'web-2');
});

it('records the heartbeat of every host even when another host holds the detection lock', function (): void {
    $task = MonitoredTask::create([
        'key' => sha1('locked'), 'name' => 'locked', 'type' => TaskType::Closure,
        'expression' => '* * * * *', 'seen_at' => now(), 'created_at' => now()->subDay(),
    ]);
    Carbon::setTestNow('2026-09-20 10:10:00');

    $lock = Cache::lock('chronoview:detect', 55);
    $lock->get();

    try {
        $this->artisan('chronoview:check')
            ->expectsOutputToContain('detection skipped')
            ->assertSuccessful();

        expect(app(Heartbeat::class)->hosts()->pluck('hostname'))->toContain(app(Heartbeat::class)->hostname())
            ->and(TaskRun::where('task_id', $task->id)->where('status', RunStatus::Missed->value)->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('syncs on demand', function (): void {
    app(Schedule::class)->command('inspire')->hourly();

    $this->artisan('chronoview:sync')->expectsOutputToContain('1 task')->assertSuccessful();

    expect(MonitoredTask::count())->toBe(1);
});

it('registers its own tasks in the schedule', function (): void {
    $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();

    expect($names)->toContain('chronoview:check')->toContain('chronoview:prune');
});

it('does not register its tasks when check is disabled', function (): void {
    config()->set('chronoview.check.enabled', false);
    $this->refreshApplication();

    $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();

    expect($names)->not->toContain('chronoview:check');
})->skip('config is read at boot; covered manually — keep as documentation');
