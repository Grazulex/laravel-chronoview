<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class MissedRunDetector
{
    private const int SEEN_WITHIN_SECONDS = 600;

    public function __construct(private readonly Recorder $recorder) {}

    /**
     * @return Collection<int, TaskRun>
     */
    public function detect(?CarbonInterface $now = null): Collection
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $grace = (int) config('chronoview.check.grace', 90);
        $missed = new Collection;

        $tasks = MonitoredTask::query()
            ->active()
            ->where('seen_at', '>=', $now->subSeconds(self::SEEN_WITHIN_SECONDS))
            ->get();

        foreach ($tasks as $task) {
            $due = $task->previousDueAt($now);

            if ($due->diffInSeconds($now) < $grace) {
                continue;
            }

            if ($task->created_at !== null && $task->created_at->greaterThan($due)) {
                continue;
            }

            if ($this->hasRunFor($task, $due, $grace) || $this->alreadyMissed($task, $due)) {
                continue;
            }

            $missed->push($this->recorder->missed($task, $due));
        }

        return $missed;
    }

    /**
     * @return Collection<int, TaskRun>
     */
    public function closeStaleRuns(?CarbonInterface $now = null): Collection
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $staleAfter = (int) config('chronoview.check.stale_after', 6 * 3600);

        return TaskRun::query()
            ->with('task')
            ->where('status', RunStatus::Running->value)
            ->where('started_at', '<', $now->subSeconds($staleAfter))
            ->get()
            ->map(fn (TaskRun $run): TaskRun => $this->recorder->closeStale($run));
    }

    private function hasRunFor(MonitoredTask $task, CarbonImmutable $due, int $grace): bool
    {
        $windowEnd = $due->addSeconds($grace + 60);

        return $task->runs()
            ->where('status', '!=', RunStatus::Missed->value)
            ->where(function (Builder $query) use ($due, $windowEnd): void {
                $query->where('expected_at', $due)
                    ->orWhereBetween('started_at', [$due, $windowEnd]);
            })
            ->exists();
    }

    private function alreadyMissed(MonitoredTask $task, CarbonImmutable $due): bool
    {
        return $task->runs()
            ->where('status', RunStatus::Missed->value)
            ->where('expected_at', $due)
            ->exists();
    }
}
