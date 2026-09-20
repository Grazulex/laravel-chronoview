<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Facades\ChronoView;
use Grazulex\ChronoView\Models\MonitoredTask;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');
    ChronoView::auth(fn (): bool => true);

    $this->healthy = MonitoredTask::create([
        'key' => sha1('h'), 'name' => 'artisan inspire', 'type' => TaskType::Command, 'command' => 'artisan inspire',
        'expression' => '*/5 * * * *', 'last_status' => RunStatus::Success, 'seen_at' => now(),
        'run_in_background' => true, 'without_overlapping' => true, 'timezone' => 'Europe/Brussels',
    ]);
    $this->broken = MonitoredTask::create([
        'key' => sha1('b'), 'name' => 'nightly report', 'type' => TaskType::Closure,
        'expression' => '0 2 * * *', 'last_status' => RunStatus::Failed, 'seen_at' => now(),
    ]);
    $this->paused = MonitoredTask::create([
        'key' => sha1('p'), 'name' => 'paused thing', 'type' => TaskType::Job,
        'expression' => '0 * * * *', 'paused_at' => now(), 'seen_at' => now(),
    ]);
});

it('lists every task with health, schedule and next run', function (): void {
    $this->get(route('chronoview.tasks.index'))
        ->assertOk()
        ->assertSeeInOrder(['artisan inspire', 'nightly report', 'paused thing'])
        ->assertSee('Every 5 minutes')
        ->assertSee('Daily at 02:00')
        ->assertSee('Paused')
        ->assertSee('Unhealthy')
        ->assertSee('Healthy');
});

it('filters by health', function (): void {
    $this->get(route('chronoview.tasks.index', ['health' => 'unhealthy']))
        ->assertOk()
        ->assertSee('nightly report')
        ->assertDontSee('artisan inspire')
        ->assertDontSee('paused thing');

    $this->get(route('chronoview.tasks.index', ['health' => 'paused']))
        ->assertOk()
        ->assertSee('paused thing')
        ->assertDontSee('nightly report');
});

it('shows a task with its summary, flags, sparkline and history', function (): void {
    foreach (range(1, 30) as $i) {
        $this->healthy->runs()->create([
            'status' => $i % 10 === 0 ? RunStatus::Failed : RunStatus::Success,
            'started_at' => now()->subMinutes($i * 5),
            'finished_at' => now()->subMinutes($i * 5)->addSeconds(2),
            'duration_ms' => 1000 + $i * 10,
            'exit_code' => $i % 10 === 0 ? 1 : 0,
        ]);
    }
    $this->healthy->runs()->create(['status' => RunStatus::Missed, 'expected_at' => now()->subDays(2)]);

    $this->get(route('chronoview.tasks.show', $this->healthy))
        ->assertOk()
        ->assertSee('artisan inspire')
        ->assertSee('*/5 * * * *')
        ->assertSee('Europe/Brussels')
        ->assertSee('background')
        ->assertSee('without overlapping')
        ->assertSee('Last 7 days')
        ->assertSee('90.0%')          // 27 success / 30 finished
        ->assertSee('cv-sparkline')
        ->assertSee('Run now')
        ->assertSee('Pause')
        ->assertSee('Missed');
});

it('paginates the history', function (): void {
    foreach (range(1, 30) as $i) {
        $this->healthy->runs()->create(['status' => RunStatus::Success, 'started_at' => now()->subMinutes($i), 'duration_ms' => 10]);
    }

    $this->get(route('chronoview.tasks.show', [$this->healthy, 'page' => 2]))
        ->assertOk()
        ->assertSee('page=1');
});

it('shows Resume instead of Pause for a paused task', function (): void {
    $this->get(route('chronoview.tasks.show', $this->paused))
        ->assertOk()
        ->assertSee('Resume')
        ->assertDontSee('>Pause<', false);
});

it('returns 404 for an unknown task', function (): void {
    $this->get('/chronoview/tasks/999')->assertNotFound();
});
