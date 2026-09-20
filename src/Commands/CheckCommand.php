<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Commands;

use Grazulex\ChronoView\Events\SchedulerDown;
use Grazulex\ChronoView\Support\Heartbeat;
use Grazulex\ChronoView\Support\MissedRunDetector;
use Grazulex\ChronoView\Support\Recorder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class CheckCommand extends Command
{
    protected $signature = 'chronoview:check';

    protected $description = 'Heartbeat, sync the schedule, detect missed runs and close stale runs';

    public function handle(Heartbeat $heartbeat, Recorder $recorder, MissedRunDetector $detector, Dispatcher $events): int
    {
        $heartbeat->beat();
        $synced = $recorder->syncSchedule();

        $lock = Cache::lock('chronoview:detect', 55);
        $result = $lock->get(fn (): array => [$detector->detect(), $detector->closeStaleRuns()]);
        [$missed, $stale] = $result === false ? [new Collection, new Collection] : $result;

        foreach ($heartbeat->deadHosts() as $host) {
            if ($host->hostname === $heartbeat->hostname()) {
                continue;
            }

            if ($host->notified_at === null || $host->notified_at->lessThan($host->beat_at)) {
                $events->dispatch(new SchedulerDown($host->hostname, $host->beat_at));
                $heartbeat->markNotified($host->hostname);
            }
        }

        if ($result === false) {
            $this->components->info(sprintf(
                'Heartbeat ok · %d %s synced · detection skipped (another host holds the lock)',
                $synced,
                $synced === 1 ? 'task' : 'tasks',
            ));
        } else {
            $this->components->info(sprintf(
                'Heartbeat ok · %d %s synced · %d missed · %d stale',
                $synced,
                $synced === 1 ? 'task' : 'tasks',
                $missed->count(),
                $stale->count(),
            ));
        }

        return self::SUCCESS;
    }
}
