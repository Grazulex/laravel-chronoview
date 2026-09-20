<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Grazulex\ChronoView\Models\MonitoredTask;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Collection;
use Throwable;
use WeakMap;

final class ScheduleInspector
{
    /** @var WeakMap<Event, bool> */
    private WeakMap $decorated;

    /** @var array<int, string>|null */
    private ?array $pausedKeys = null;

    public function __construct(private readonly Application $app)
    {
        $this->decorated = new WeakMap;
    }

    /**
     * The fully loaded schedule, even from HTTP: `Kernel::all()` bootstraps the
     * console kernel (requiring routes/console.php) and constructs the Artisan
     * application, which replays the `Artisan::starting` callbacks that
     * `ApplicationBuilder::withSchedule()` registers itself through — those
     * closures never run otherwise, since `bootstrap()` alone never
     * instantiates Artisan.
     */
    public function schedule(): Schedule
    {
        if (! $this->app->runningInConsole()) {
            /** @var Kernel $kernel */
            $kernel = $this->app->make(ConsoleKernel::class);
            $kernel->all();
        }

        return $this->app->make(Schedule::class);
    }

    /**
     * @return Collection<int, Event>
     */
    public function events(): Collection
    {
        return (new Collection($this->schedule()->events()))
            ->reject(fn (Event $event): bool => $this->isOwnCommand($event))
            ->values();
    }

    /**
     * @return Collection<int, TaskDefinition>
     */
    public function definitions(): Collection
    {
        return $this->events()->map(fn (Event $event): TaskDefinition => TaskDefinition::fromEvent($event));
    }

    public function find(string $key): ?Event
    {
        return $this->events()->first(fn (Event $event): bool => TaskDefinition::fromEvent($event)->key() === $key);
    }

    public function decorate(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            if (isset($this->decorated[$event]) || $this->isOwnCommand($event)) {
                continue;
            }

            $this->decorated[$event] = true;
            $key = TaskDefinition::fromEvent($event)->key();

            $event->when(fn (): bool => ! in_array($key, $this->pausedKeys(), true));

            if ($this->shouldCaptureOutput($event)) {
                $this->ensureOutputDirectory();
                $event->sendOutputTo($this->outputPathFor($key));
            }
        }
    }

    public function isOwnCommand(Event $event): bool
    {
        return ! $event instanceof CallbackEvent
            && preg_match('/chronoview:(check|prune|sync)\b/', (string) $event->command) === 1;
    }

    public function outputPathFor(string $key): string
    {
        return $this->app->storagePath('framework/chronoview/' . $key . '.log');
    }

    public function isOurOutput(Event $event): bool
    {
        return str_starts_with($event->output, $this->app->storagePath('framework/chronoview/'));
    }

    /**
     * @return array<int, string>
     */
    public function pausedKeys(): array
    {
        if ($this->pausedKeys !== null) {
            return $this->pausedKeys;
        }

        try {
            $this->pausedKeys = MonitoredTask::query()->paused()->pluck('key')->map(fn ($k): string => (string) $k)->all();
        } catch (Throwable) {
            $this->pausedKeys = [];
        }

        return $this->pausedKeys;
    }

    public function forgetPausedKeys(): void
    {
        $this->pausedKeys = null;
    }

    private function shouldCaptureOutput(Event $event): bool
    {
        return (bool) config('chronoview.record.output', true)
            && ! $event instanceof CallbackEvent
            && $event->output === $event->getDefaultOutput();
    }

    private function ensureOutputDirectory(): void
    {
        $dir = $this->app->storagePath('framework/chronoview');

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
