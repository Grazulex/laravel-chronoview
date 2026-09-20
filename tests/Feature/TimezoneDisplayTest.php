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
    $this->task = MonitoredTask::create([
        'key' => sha1('tz'), 'name' => 'artisan inspire', 'type' => TaskType::Command, 'command' => 'artisan inspire',
        'expression' => '*/5 * * * *', 'timezone' => 'Europe/Brussels', 'seen_at' => now(),
    ]);
    $this->run = $this->task->runs()->create(['status' => RunStatus::Success, 'started_at' => '2026-09-20 09:58:00', 'finished_at' => '2026-09-20 09:58:02', 'duration_ms' => 2000]);
});

it('marks every timestamp with an ISO datetime and the server timezone', function (): void {
    $this->get(route('chronoview.runs.show', $this->run))
        ->assertOk()
        ->assertSee('data-server-tz="UTC"', false)
        ->assertSee('<time class="cv-time" datetime="2026-09-20T09:58:00+00:00"', false)
        ->assertSee('data-exact="2026-09-20 09:58:00"', false)
        ->assertSee('Toggle timezone')
        ->assertSee('application timezone (UTC)');
});

it('labels the task timezone explicitly', function (): void {
    $this->get(route('chronoview.tasks.show', $this->task))
        ->assertOk()
        ->assertSee('Task timezone: Europe/Brussels');

    $this->task->update(['timezone' => 'UTC']);

    $this->get(route('chronoview.tasks.show', $this->task))
        ->assertOk()
        ->assertSee('Task timezone: UTC (application default)');
});

it('uses the time partial on every page', function (): void {
    foreach ([route('chronoview.dashboard'), route('chronoview.tasks.index'), route('chronoview.runs.index')] as $url) {
        $this->get($url)->assertOk()->assertSee('<time class="cv-time"', false);
    }
});

it('renders a dash for missing dates without a time element', function (): void {
    $view = view('chronoview::partials.time', ['date' => null])->render();

    expect(trim($view))->toBe('—');
});
