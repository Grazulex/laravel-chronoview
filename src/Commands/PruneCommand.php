<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Commands;

use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Grazulex\ChronoView\Support\Heartbeat;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PruneCommand extends Command
{
    protected $signature = 'chronoview:prune {--days= : Override chronoview.prune.keep_days}';

    protected $description = 'Delete old runs, tasks that disappeared from the schedule and stale heartbeats';

    public function handle(Heartbeat $heartbeat, ScheduleInspector $inspector): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('chronoview.prune.keep_days', 14)));
        $cutoff = Carbon::now()->subDays($days);

        $runs = TaskRun::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('started_at', '<', $cutoff)
                    ->orWhere(fn (Builder $q) => $q->whereNull('started_at')->where('expected_at', '<', $cutoff))
                    ->orWhere(fn (Builder $q) => $q->whereNull('started_at')->whereNull('expected_at')->where('created_at', '<', $cutoff));
            })
            ->delete();

        $unseenTaskIds = MonitoredTask::query()->where('seen_at', '<', $cutoff)->pluck('id');
        TaskRun::query()->whereIn('task_id', $unseenTaskIds)->delete();
        $tasks = MonitoredTask::query()->whereIn('id', $unseenTaskIds)->delete();

        $hosts = 0;
        foreach ($heartbeat->hosts() as $host) {
            if ($host->beat_at->lessThan($cutoff)) {
                $heartbeat->forget($host->hostname);
                $hosts++;
            }
        }

        $files = 0;
        $dir = $inspector->outputDirectory();

        if (is_dir($dir)) {
            foreach (glob($dir . '/*.log') ?: [] as $file) {
                if (filemtime($file) !== false && filemtime($file) < Carbon::now()->subDay()->getTimestamp()) {
                    @unlink($file);
                    $files++;
                }
            }
        }

        $this->components->info(sprintf('Pruned %d %s older than %d days.', $runs, $runs === 1 ? 'run' : 'runs', $days));
        $this->components->info(sprintf('Pruned %d %s older than %d days.', $tasks, $tasks === 1 ? 'task' : 'tasks', $days));
        $this->components->info(sprintf('Forgot %d %s older than %d days.', $hosts, $hosts === 1 ? 'heartbeat' : 'heartbeats', $days));
        $this->components->info(sprintf('Removed %d stale output %s.', $files, $files === 1 ? 'file' : 'files'));

        return self::SUCCESS;
    }
}
