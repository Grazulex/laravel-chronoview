<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Facades\ChronoView;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Support\Heartbeat;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');
    ChronoView::auth(fn (): bool => true);
});

it('renders an empty dashboard with the scheduler marked down', function (): void {
    $this->get(route('chronoview.dashboard'))
        ->assertOk()
        ->assertSee('Scheduler down')
        ->assertSee('No task recorded yet');
});

it('renders health, KPIs, attention list, upcoming runs and recent problems', function (): void {
    app(Heartbeat::class)->beat('web-1');

    $ok = MonitoredTask::create([
        'key' => sha1('ok'), 'name' => 'artisan inspire', 'type' => TaskType::Command, 'command' => 'artisan inspire',
        'expression' => '*/5 * * * *', 'last_status' => RunStatus::Success, 'seen_at' => now(),
    ]);
    $ok->runs()->create(['status' => RunStatus::Success, 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(5), 'duration_ms' => 1200]);

    $ko = MonitoredTask::create([
        'key' => sha1('ko'), 'name' => 'nightly report', 'type' => TaskType::Closure,
        'expression' => '0 2 * * *', 'last_status' => RunStatus::Failed, 'consecutive_failures' => 3, 'seen_at' => now(),
    ]);
    $ko->runs()->create(['status' => RunStatus::Failed, 'started_at' => now()->subHours(8), 'finished_at' => now()->subHours(8), 'exit_code' => 1, 'exception' => 'RuntimeException: boom']);
    $ko->runs()->create(['status' => RunStatus::Missed, 'expected_at' => now()->subHours(32)]);

    $response = $this->get(route('chronoview.dashboard'))->assertOk();

    $response->assertSee('Scheduler alive')
        ->assertSee('web-1')
        ->assertSee('Needs attention')
        ->assertSee('nightly report')
        ->assertSee('3 consecutive failures')
        ->assertSee('Up next')
        ->assertSee('2026-09-20 10:05:00')
        ->assertSee('Recent problems')
        ->assertSee('Recent runs')
        ->assertSee('50.0%')   // success rate: 1 success, 1 failed in 24h
        ->assertSee('1.2 s');
});

it('emits the auto-refresh interval and the theme switch', function (): void {
    config()->set('chronoview.refresh', 30);

    $this->get(route('chronoview.dashboard'))
        ->assertOk()
        ->assertSee('data-refresh="30"', false)
        ->assertSee('chronoview-theme');
});
