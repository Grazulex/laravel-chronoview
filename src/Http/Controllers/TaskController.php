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

        $allRows = MonitoredTask::query()->orderBy('name')->get()
            ->map(fn (MonitoredTask $task): array => [
                'task' => $task,
                'health' => $task->health(),
                'next' => $task->isPaused() ? null : $task->nextRunAt(),
            ]);

        $counts = $allRows->countBy(fn (array $row): string => $row['health']->value);

        $rows = $allRows;

        if ($filter !== null) {
            $rows = $rows->filter(fn (array $row): bool => $row['health'] === $filter);
        }

        return view('chronoview::tasks.index', [
            'rows' => $rows->values(),
            'filter' => $filter,
            'counts' => $counts,
        ]);
    }

    public function show(MonitoredTask $task): View
    {
        $since = Carbon::now()->subDays(7);
        $week = $task->runs()->since($since)->get();
        $finished = $week->whereIn('status', [RunStatus::Success, RunStatus::Failed]);
        $success = $finished->where('status', RunStatus::Success)->count();
        $durations = $finished->pluck('duration_ms')->filter(fn (?int $ms): bool => $ms !== null)->sort()->values();

        $medianMs = null;

        if ($durations->isNotEmpty()) {
            $count = $durations->count();
            $mid = intdiv($count, 2);
            $medianMs = $count % 2 === 0
                ? (int) round(($durations->get($mid - 1) + $durations->get($mid)) / 2)
                : (int) $durations->get($mid);
        }

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
                'median_ms' => $medianMs,
            ],
            'sparkline' => $sparkline,
            'history' => $task->runs()->latest('id')->paginate(25)->withQueryString(),
            'next' => $task->isPaused() ? null : $task->nextRunAt(),
        ]);
    }
}
