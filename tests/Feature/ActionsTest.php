<?php

declare(strict_types=1);

use Grazulex\ChronoView\Facades\ChronoView;
use Grazulex\ChronoView\Jobs\RunScheduledTask;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Grazulex\ChronoView\Support\TaskDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    ChronoView::auth(fn (): bool => true);
    $event = app(Schedule::class)->command('inspire')->hourly();
    $definition = TaskDefinition::fromEvent($event);
    $this->task = MonitoredTask::create($definition->toArray() + ['key' => $definition->key(), 'seen_at' => now()]);
});

it('queues a manual run', function (): void {
    Queue::fake();
    config()->set('chronoview.actions.queue', 'chronoview');

    $this->post(route('chronoview.tasks.run', $this->task))
        ->assertRedirect(route('chronoview.tasks.show', $this->task))
        ->assertSessionHas('chronoview.flash.type', 'success');

    Queue::assertPushedOn('chronoview', RunScheduledTask::class, fn (RunScheduledTask $job): bool => $job->taskKey === $this->task->key);
});

it('refuses a manual run when disabled', function (): void {
    Queue::fake();
    config()->set('chronoview.actions.run_now', false);

    $this->post(route('chronoview.tasks.run', $this->task))->assertForbidden();

    Queue::assertNothingPushed();
});

it('pauses and resumes a task', function (): void {
    $this->post(route('chronoview.tasks.pause', $this->task))
        ->assertRedirect(route('chronoview.tasks.show', $this->task));

    expect($this->task->fresh()?->isPaused())->toBeTrue()
        ->and(app(ScheduleInspector::class)->pausedKeys())->toContain($this->task->key);

    $this->post(route('chronoview.tasks.resume', $this->task))
        ->assertRedirect(route('chronoview.tasks.show', $this->task));

    expect($this->task->fresh()?->isPaused())->toBeFalse()
        ->and($this->task->fresh()?->resumed_at)->not->toBeNull();
});

it('refuses pause when disabled', function (): void {
    config()->set('chronoview.actions.pause', false);

    $this->post(route('chronoview.tasks.pause', $this->task))->assertForbidden();
});

it('requires authorization for actions', function (): void {
    ChronoView::auth(fn (): bool => false);

    $this->post(route('chronoview.tasks.pause', $this->task))->assertForbidden();
});
