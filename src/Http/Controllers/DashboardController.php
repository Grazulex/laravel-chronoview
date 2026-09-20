<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Grazulex\ChronoView\ChronoView;
use Grazulex\ChronoView\Enums\Health;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Heartbeat;
use Illuminate\Contracts\View\View;

final class DashboardController
{
    public function __invoke(ChronoView $chronoView, Heartbeat $heartbeat): View
    {
        $tasks = MonitoredTask::query()->orderBy('name')->get();

        $attention = $tasks
            ->filter(fn (MonitoredTask $task): bool => $task->health() === Health::Unhealthy)
            ->sortByDesc('consecutive_failures')
            ->take(10);

        $upNext = $tasks
            ->filter(fn (MonitoredTask $task): bool => ! $task->isPaused())
            ->map(fn (MonitoredTask $task): array => ['task' => $task, 'at' => $task->nextRunAt()])
            ->sortBy(fn (array $row): int => $row['at']->getTimestamp())
            ->take(8);

        return view('chronoview::dashboard', [
            'alive' => $heartbeat->isAlive(),
            'hosts' => $heartbeat->hosts(),
            'stats' => $chronoView->stats(24),
            'taskCount' => $tasks->count(),
            'attention' => $attention,
            'upNext' => $upNext,
            'problems' => TaskRun::query()->with('task')->problems()->latest('id')->limit(10)->get(),
            'recent' => TaskRun::query()->with('task')->latest('id')->limit(15)->get(),
        ]);
    }
}
