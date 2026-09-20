<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Carbon\CarbonInterface;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\RunTrigger;
use Grazulex\ChronoView\Events\TaskRunFailed;
use Grazulex\ChronoView\Events\TaskRunMissed;
use Grazulex\ChronoView\Models\MonitoredTask;
use Grazulex\ChronoView\Models\TaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Throwable;

final class Recorder
{
    private const string TRUNCATED_PREFIX = "[… truncated …]\n";

    public function __construct(
        private readonly ScheduleInspector $inspector,
        private readonly Heartbeat $heartbeat,
    ) {}

    /**
     * Upsert every task of the schedule. Returns the number of tasks seen.
     */
    public function syncSchedule(): int
    {
        $now = Carbon::now();
        $count = 0;

        foreach ($this->inspector->definitions() as $definition) {
            MonitoredTask::query()->updateOrCreate(
                ['key' => $definition->key()],
                $definition->toArray() + ['seen_at' => $now],
            );
            $count++;
        }

        return $count;
    }

    public function resolveTask(Event $event): MonitoredTask
    {
        $definition = TaskDefinition::fromEvent($event);

        return MonitoredTask::query()->updateOrCreate(
            ['key' => $definition->key()],
            $definition->toArray() + ['seen_at' => Carbon::now()],
        );
    }

    public function starting(Event $event, RunTrigger $trigger = RunTrigger::Schedule): TaskRun
    {
        $task = $this->resolveTask($event);
        $now = Carbon::now();

        $run = $task->runs()->create([
            'status' => RunStatus::Running,
            'trigger' => $trigger,
            'expected_at' => $trigger === RunTrigger::Schedule ? $task->previousDueAt($now) : null,
            'started_at' => $now,
            'hostname' => $this->heartbeat->hostname(),
        ]);

        $task->forceFill(['last_started_at' => $now, 'last_status' => RunStatus::Running])->save();

        return $run->setRelation('task', $task);
    }

    public function finished(Event $event, float $runtime): ?TaskRun
    {
        $run = $this->openRun($event);

        if ($run === null) {
            return null;
        }

        if ($event->runInBackground) {
            // The process was only launched; schedule:finish will report the exit code.
            return $run;
        }

        $exitCode = (int) ($event->exitCode ?? 0);

        return $this->close($run, $exitCode, (int) round($runtime * 1000), $this->readOutput($event));
    }

    public function backgroundFinished(Event $event): ?TaskRun
    {
        $run = $this->openRun($event);

        if ($run === null) {
            return null;
        }

        $duration = $run->started_at !== null ? (int) $run->started_at->diffInMilliseconds(Carbon::now()) : null;

        return $this->close($run, (int) ($event->exitCode ?? 0), $duration, $this->readOutput($event));
    }

    public function failed(Event $event, Throwable $exception): TaskRun
    {
        $task = $this->resolveTask($event);
        $trace = $exception::class . ': ' . $exception->getMessage() . "\n\n" . $exception->getTraceAsString();

        $run = $this->openRun($event);

        if ($run !== null) {
            $run->forceFill(['exception' => $trace, 'output' => $run->output ?? $this->readOutput($event)]);

            return $this->close($run, (int) ($event->exitCode ?? 1), $this->elapsed($run), $run->output, forceFailed: true);
        }

        $failedWithoutException = $task->runs()
            ->where('status', RunStatus::Failed->value)
            ->whereNull('exception')
            ->latest('id')
            ->first();

        if ($failedWithoutException !== null) {
            $failedWithoutException->forceFill(['exception' => $trace])->save();

            return $failedWithoutException->setRelation('task', $task);
        }

        $now = Carbon::now();
        $run = $task->runs()->create([
            'status' => RunStatus::Failed,
            'expected_at' => $task->previousDueAt($now),
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'exit_code' => (int) ($event->exitCode ?? 1),
            'hostname' => $this->heartbeat->hostname(),
            'exception' => $trace,
        ]);

        $this->touchTaskAfterClose($task, RunStatus::Failed);
        EventDispatcher::dispatch(new TaskRunFailed($run->setRelation('task', $task)));

        return $run;
    }

    public function skipped(Event $event): ?TaskRun
    {
        if (! config('chronoview.record.skipped', true)) {
            return null;
        }

        $task = $this->resolveTask($event);

        return $task->runs()->create([
            'status' => RunStatus::Skipped,
            'expected_at' => $task->previousDueAt(Carbon::now()),
            'hostname' => $this->heartbeat->hostname(),
        ])->setRelation('task', $task);
    }

    public function missed(MonitoredTask $task, CarbonInterface $expectedAt): TaskRun
    {
        $run = $task->runs()->create([
            'status' => RunStatus::Missed,
            'expected_at' => Carbon::instance($expectedAt)->startOfMinute(),
        ]);

        $this->touchTaskAfterClose($task, RunStatus::Missed);
        EventDispatcher::dispatch(new TaskRunMissed($run->setRelation('task', $task)));

        return $run;
    }

    public function closeStale(TaskRun $run): TaskRun
    {
        $staleAfter = (int) config('chronoview.check.stale_after', 6 * 3600);

        $run->forceFill([
            'exception' => "Run was still running after {$staleAfter} seconds and was closed by chronoview:check.",
        ]);

        return $this->close($run, $run->exit_code ?? 1, $this->elapsed($run), $run->output, forceFailed: true);
    }

    private function openRun(Event $event): ?TaskRun
    {
        $task = $this->resolveTask($event);

        $run = $task->runs()->where('status', RunStatus::Running->value)->latest('id')->first();

        return $run?->setRelation('task', $task);
    }

    private function close(TaskRun $run, int $exitCode, ?int $durationMs, ?string $output, bool $forceFailed = false): TaskRun
    {
        $status = $forceFailed || $exitCode !== 0 ? RunStatus::Failed : RunStatus::Success;

        $run->forceFill([
            'status' => $status,
            'finished_at' => Carbon::now(),
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode,
            'memory_peak' => memory_get_peak_usage(true),
            'output' => $output,
        ])->save();

        /** @var MonitoredTask $task */
        $task = $run->task;
        $this->touchTaskAfterClose($task, $status);

        if ($status === RunStatus::Failed) {
            EventDispatcher::dispatch(new TaskRunFailed($run));
        }

        return $run;
    }

    private function touchTaskAfterClose(MonitoredTask $task, RunStatus $status): void
    {
        $task->forceFill([
            'last_finished_at' => Carbon::now(),
            'last_status' => $status,
            'consecutive_failures' => $status === RunStatus::Success ? 0 : $task->consecutive_failures + 1,
        ])->save();
    }

    private function elapsed(TaskRun $run): ?int
    {
        return $run->started_at !== null ? (int) $run->started_at->diffInMilliseconds(Carbon::now()) : null;
    }

    private function readOutput(Event $event): ?string
    {
        if (! $this->inspector->isOurOutput($event) || ! is_file((string) $event->output)) {
            return null;
        }

        $content = (string) file_get_contents((string) $event->output);
        @unlink((string) $event->output);

        $max = (int) config('chronoview.record.max_output', 64 * 1024);

        if (strlen($content) > $max) {
            $content = self::TRUNCATED_PREFIX . substr($content, -$max);
        }

        return $content === '' ? null : $content;
    }
}
