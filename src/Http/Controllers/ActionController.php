<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Grazulex\ChronoView\Jobs\RunScheduledTask;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

final class ActionController
{
    public function __construct(private readonly ScheduleInspector $inspector) {}

    public function run(MonitoredTask $task): RedirectResponse
    {
        abort_unless((bool) config('chronoview.actions.run_now', true), 403);

        RunScheduledTask::dispatch($task->key)
            ->onConnection(config('chronoview.actions.queue_connection'))
            ->onQueue(config('chronoview.actions.queue'));

        return $this->back($task, "“{$task->name}” has been queued for an immediate run.");
    }

    public function pause(MonitoredTask $task): RedirectResponse
    {
        abort_unless((bool) config('chronoview.actions.pause', true), 403);

        $task->forceFill(['paused_at' => Carbon::now()])->save();
        $this->inspector->forgetPausedKeys();

        return $this->back($task, "“{$task->name}” is paused. The scheduler will skip it from the next minute.");
    }

    public function resume(MonitoredTask $task): RedirectResponse
    {
        abort_unless((bool) config('chronoview.actions.pause', true), 403);

        $task->forceFill(['paused_at' => null])->save();
        $this->inspector->forgetPausedKeys();

        return $this->back($task, "“{$task->name}” resumed.");
    }

    private function back(MonitoredTask $task, string $message, string $type = 'success'): RedirectResponse
    {
        return redirect()
            ->route('chronoview.tasks.show', $task)
            ->with('chronoview.flash', ['type' => $type, 'message' => $message]);
    }
}
