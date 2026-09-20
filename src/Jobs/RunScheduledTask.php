<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Jobs;

use Grazulex\ChronoView\Enums\RunTrigger;
use Grazulex\ChronoView\Support\Recorder;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class RunScheduledTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $taskKey) {}

    public function handle(ScheduleInspector $inspector, Recorder $recorder, Application $app): void
    {
        $schedule = $inspector->schedule();
        $inspector->decorate($schedule);

        $event = $inspector->find($this->taskKey);

        if ($event === null) {
            report(new RuntimeException("ChronoView: task [{$this->taskKey}] is no longer in the schedule."));

            return;
        }

        $recorder->starting($event, RunTrigger::Manual);
        $start = microtime(true);

        try {
            $event->run($app);
            $recorder->finished($event, round(microtime(true) - $start, 2));

            if ($event->exitCode != 0 && ! $event->runInBackground) {
                throw new RuntimeException("Scheduled command [{$event->getSummaryForDisplay()}] failed with exit code [{$event->exitCode}].");
            }
        } catch (Throwable $e) {
            $recorder->failed($event, $e);
        }
    }
}
