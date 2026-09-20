<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Commands;

use Grazulex\ChronoView\Events\SchedulerDown;
use Grazulex\ChronoView\Support\Heartbeat;
use Grazulex\ChronoView\Support\MissedRunDetector;
use Grazulex\ChronoView\Support\Recorder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

final class CheckCommand extends Command
{
    protected $signature = 'chronoview:check';

    protected $description = 'Heartbeat, sync the schedule, detect missed runs and close stale runs';

    public function handle(Heartbeat $heartbeat, Recorder $recorder, MissedRunDetector $detector, Dispatcher $events): int
    {
        $heartbeat->beat();
        $synced = $recorder->syncSchedule();
        $missed = $detector->detect();
        $stale = $detector->closeStaleRuns();

        foreach ($heartbeat->deadHosts() as $host) {
            if ($host->hostname !== $heartbeat->hostname()) {
                $events->dispatch(new SchedulerDown($host->hostname, $host->beat_at));
            }
        }

        $this->components->info(sprintf(
            'Heartbeat ok · %d %s synced · %d missed · %d stale',
            $synced,
            $synced === 1 ? 'task' : 'tasks',
            $missed->count(),
            $stale->count(),
        ));

        return self::SUCCESS;
    }
}
