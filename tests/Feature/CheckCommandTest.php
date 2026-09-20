<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Events\SchedulerDown;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Heartbeat;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Store;
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

it('dispatches SchedulerDown once per outage and again after the host beats and dies again', function (): void {
    Event::fake([SchedulerDown::class]);
    $heartbeat = app(Heartbeat::class);
    $heartbeat->beat('web-2');
    Carbon::setTestNow('2026-09-20 10:20:00');

    $this->artisan('chronoview:check')->assertSuccessful();
    $this->artisan('chronoview:check')->assertSuccessful();

    Event::assertDispatchedTimes(SchedulerDown::class, 1);

    // web-2 beats again, then dies again: a fresh outage should notify again.
    Carbon::setTestNow('2026-09-20 10:25:00');
    $heartbeat->beat('web-2');
    Carbon::setTestNow('2026-09-20 10:31:00');

    $this->artisan('chronoview:check')->assertSuccessful();

    Event::assertDispatchedTimes(SchedulerDown::class, 2);
});

it('runs detection without a lock when the cache store has no lock support', function (): void {
    config()->set('chronoview.check.stale_after', 3600);
    MonitoredTask::create([
        'key' => sha1('nolock'), 'name' => 'nolock', 'type' => TaskType::Closure,
        'expression' => '* * * * *', 'seen_at' => now(), 'created_at' => now()->subDay(),
    ]);

    $inner = new ArrayStore;
    $store = new class($inner) implements Store
    {
        public function __construct(private ArrayStore $inner) {}

        public function get($key)
        {
            return $this->inner->get($key);
        }

        public function many(array $keys)
        {
            return $this->inner->many($keys);
        }

        public function put($key, $value, $seconds)
        {
            return $this->inner->put($key, $value, $seconds);
        }

        public function touch($key, $seconds)
        {
            return $this->inner->touch($key, $seconds);
        }

        public function putMany(array $values, $seconds)
        {
            return $this->inner->putMany($values, $seconds);
        }

        public function increment($key, $value = 1)
        {
            return $this->inner->increment($key, $value);
        }

        public function decrement($key, $value = 1)
        {
            return $this->inner->decrement($key, $value);
        }

        public function forever($key, $value)
        {
            return $this->inner->forever($key, $value);
        }

        public function forget($key)
        {
            return $this->inner->forget($key);
        }

        public function flush()
        {
            return $this->inner->flush();
        }

        public function getPrefix()
        {
            return $this->inner->getPrefix();
        }
    };

    Cache::extend('nolock', fn () => Cache::repository($store));
    config()->set('cache.stores.nolock', ['driver' => 'nolock']);
    config()->set('cache.default', 'nolock');

    $this->artisan('chronoview:check')
        ->expectsOutputToContain('no lock support')
        ->assertSuccessful();

    expect(TaskRun::where('status', RunStatus::Missed->value)->count())->toBeGreaterThanOrEqual(1);
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
