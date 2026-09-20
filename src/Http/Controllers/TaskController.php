<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Grazulex\ChronoView\Enums\Health;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Models\MonitoredTask;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class TaskController
{
    public function index(Request $request): View
    {
        $filter = Health::tryFrom((string) $request->query('health', ''));

        $tasks = MonitoredTask::query()->orderBy('name')->get()
            ->map(fn (MonitoredTask $task): array => [
                'task' => $task,
                'health' => $task->health(),
                'next' => $task->isPaused() ? null : $task->nextRunAt(),
            ]);

        if ($filter !== null) {
            $tasks = $tasks->filter(fn (array $row): bool => $row['health'] === $filter);
        }

        $tasks = $tasks->values();

        return view('chronoview::tasks.index', [
            'rows' => $tasks,
            'filter' => $filter,
            'counts' => MonitoredTask::query()->get()->countBy(fn (MonitoredTask $t): string => $t->health()->value),
        ]);
    }

    public function show(MonitoredTask $task): View
    {
        $since = Carbon::now()->subDays(7);
        $week = $task->runs()->since($since)->get();
        $finished = $week->whereIn('status', [RunStatus::Success, RunStatus::Failed]);
        $success = $finished->where('status', RunStatus::Success)->count();
        $durations = $finished->pluck('duration_ms')->filter()->sort()->values();

        $sparkline = $task->runs()
            ->whereIn('status', [RunStatus::Success->value, RunStatus::Failed->value])
            ->whereNotNull('duration_ms')
            ->latest('id')->limit(40)->get()->reverse()->values();

        return view('chronoview::tasks.show', [
            'task' => $task,
            'summary' => [
                'runs' => $week->count(),
                'success_rate' => $finished->count() > 0 ? round($success / $finished->count() * 100, 1) : null,
                'failed' => $week->where('status', RunStatus::Failed)->count(),
                'missed' => $week->where('status', RunStatus::Missed)->count(),
                'median_ms' => $durations->isEmpty() ? null : (int) $durations->get(intdiv($durations->count(), 2)),
            ],
            'sparkline' => $sparkline,
            'history' => $task->runs()->latest('id')->paginate(25)->withQueryString(),
            'next' => $task->isPaused() ? null : $task->nextRunAt(),
        ]);
    }
}
