<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Listeners;

use Grazulex\ChronoView\Support\Recorder;
use Grazulex\ChronoView\Support\ScheduleInspector;
use Grazulex\ChronoView\Support\TaskDefinition;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class ScheduleEventSubscriber
{
    private const array SCHEDULE_COMMANDS = ['schedule:run', 'schedule:work', 'schedule:test', 'schedule:finish'];

    public function __construct(
        private readonly Application $app,
        private readonly ScheduleInspector $inspector,
        private readonly Recorder $recorder,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ArtisanStarting::class => 'onArtisanStarting',
            CommandStarting::class => 'onCommandStarting',
            ScheduledTaskStarting::class => 'onTaskStarting',
            ScheduledTaskFinished::class => 'onTaskFinished',
            ScheduledTaskFailed::class => 'onTaskFailed',
            ScheduledTaskSkipped::class => 'onTaskSkipped',
            ScheduledBackgroundTaskFinished::class => 'onBackgroundTaskFinished',
        ];
    }

    /**
     * `CommandStarting` (below) is Laravel's documented hook for this, but the
     * framework only bridges Symfony's ConsoleEvents::COMMAND to it outside of
     * `Application::runningUnitTests()` (see `Kernel::__construct()`'s `booted()`
     * callback) — so under Testbench it never fires at all. `ArtisanStarting` is
     * dispatched unconditionally, once, right when the Artisan application is
     * built and before any command runs, so it is used as the reliable trigger;
     * `decorate()` is idempotent (WeakMap-guarded), so running both listeners is
     * harmless in the environments where `CommandStarting` does fire.
     */
    public function onArtisanStarting(ArtisanStarting $event): void
    {
        $this->guard(fn () => $this->inspector->decorate($this->app->make(Schedule::class)));
    }

    public function onCommandStarting(CommandStarting $event): void
    {
        if (! in_array($event->command, self::SCHEDULE_COMMANDS, true)) {
            return;
        }

        $this->guard(fn () => $this->inspector->decorate($this->app->make(Schedule::class)));
    }

    public function onTaskStarting(ScheduledTaskStarting $event): void
    {
        $this->forTask($event->task, fn (Event $task) => $this->recorder->starting($task));
    }

    public function onTaskFinished(ScheduledTaskFinished $event): void
    {
        $this->forTask($event->task, fn (Event $task) => $this->recorder->finished($task, $event->runtime));
    }

    public function onTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->forTask($event->task, fn (Event $task) => $this->recorder->failed($task, $event->exception));
    }

    public function onTaskSkipped(ScheduledTaskSkipped $event): void
    {
        $this->forTask($event->task, function (Event $task): void {
            if (in_array(TaskDefinition::fromEvent($task)->key(), $this->inspector->pausedKeys(), true)) {
                return; // skipped by our own pause filter: not worth a row
            }

            $this->recorder->skipped($task);
        });
    }

    public function onBackgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->forTask($event->task, fn (Event $task) => $this->recorder->backgroundFinished($task));
    }

    private function forTask(Event $task, callable $callback): void
    {
        if ($this->inspector->isOwnCommand($task)) {
            return;
        }

        $this->guard(fn () => $callback($task));
    }

    /**
     * Monitoring must never break the scheduler.
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
