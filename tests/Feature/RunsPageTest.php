<?php

declare(strict_types=1);

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\RunTrigger;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Facades\ChronoView;
use Grazulex\ChronoView\Models\MonitoredTask;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');
    ChronoView::auth(fn (): bool => true);
    $this->task = MonitoredTask::create([
        'key' => sha1('t'), 'name' => 'artisan inspire', 'type' => TaskType::Command, 'command' => 'artisan inspire',
        'expression' => '*/5 * * * *', 'seen_at' => now(),
    ]);
    $this->ok = $this->task->runs()->create([
        'status' => RunStatus::Success, 'trigger' => RunTrigger::Manual, 'started_at' => now()->subMinute(),
        'finished_at' => now(), 'duration_ms' => 1234, 'exit_code' => 0, 'hostname' => 'web-1',
        'output' => "Hello <b>world</b>\n", 'memory_peak' => 2 * 1024 * 1024,
    ]);
    $this->ko = $this->task->runs()->create([
        'status' => RunStatus::Failed, 'started_at' => now()->subMinutes(6), 'finished_at' => now()->subMinutes(6),
        'duration_ms' => 20, 'exit_code' => 1, 'exception' => "RuntimeException: boom\n\n#0 /app/foo.php(12): bar()",
    ]);
});

it('lists runs and filters by status', function (): void {
    $this->get(route('chronoview.runs.index'))
        ->assertOk()
        ->assertSee('artisan inspire')
        ->assertSee('Success')
        ->assertSee('Failed');

    $this->get(route('chronoview.runs.index', ['status' => 'failed']))
        ->assertOk()
        ->assertSee('Failed')
        ->assertDontSee('cv-badge-success');
});

it('excludes output and exception from the run list query', function (): void {
    $response = $this->get(route('chronoview.runs.index'))->assertOk();

    $runs = $response->viewData('runs');

    expect($runs->first()?->getAttributes())->not->toHaveKey('output')
        ->and($runs->first()?->getAttributes())->not->toHaveKey('exception');
});

it('shows a run with escaped output and metadata', function (): void {
    $this->get(route('chronoview.runs.show', $this->ok))
        ->assertOk()
        ->assertSee('artisan inspire')
        ->assertSee('web-1')
        ->assertSee('1.2 s')
        ->assertSee('2.0 MB')
        ->assertSee('manual')
        ->assertSee('Hello &lt;b&gt;world&lt;/b&gt;', false)
        ->assertDontSee('Exception');
});

it('shows the exception of a failed run', function (): void {
    $this->get(route('chronoview.runs.show', $this->ko))
        ->assertOk()
        ->assertSee('RuntimeException: boom')
        ->assertSee('/app/foo.php(12)')
        ->assertSee('No output captured');
});
