<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Closure;
use DateTimeZone;
use Grazulex\ChronoView\Enums\TaskType;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

use function Illuminate\Support\artisan_binary;
use function Illuminate\Support\php_binary;

use ReflectionClass;
use ReflectionFunction;
use Throwable;

final readonly class TaskDefinition
{
    public function __construct(
        public TaskType $type,
        public string $name,
        public ?string $command,
        public string $expression,
        public ?string $timezone,
        public ?string $description,
        public bool $runInBackground,
        public bool $withoutOverlapping,
        public bool $onOneServer,
        public ?string $source = null,
    ) {}

    public static function fromEvent(Event $event): self
    {
        $description = is_string($event->description) && $event->description !== '' ? $event->description : null;

        if ($event instanceof CallbackEvent) {
            $callback = self::callbackOf($event);
            $type = $description !== null && class_exists($description) ? TaskType::Job : TaskType::Closure;
            $name = $description ?? self::describeCallback($callback);
            $command = null;
        } else {
            $raw = (string) $event->command;
            $type = self::isArtisan($raw) ? TaskType::Command : TaskType::Exec;
            $command = self::normalizeCommand($raw);
            $name = $description ?? $command;
        }

        return new self(
            type: $type,
            name: $name,
            command: $command,
            expression: $event->getExpression(),
            timezone: self::timezoneName($event->timezone),
            description: $description,
            runInBackground: (bool) $event->runInBackground,
            withoutOverlapping: (bool) $event->withoutOverlapping,
            onOneServer: (bool) $event->onOneServer,
            source: self::sourceOf($event),
        );
    }

    public function key(): string
    {
        return sha1(implode('|', [$this->type->value, $this->name, $this->expression, $this->timezone ?? '']));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'command' => $this->command,
            'expression' => $this->expression,
            'timezone' => $this->timezone,
            'description' => $this->description,
            'source' => $this->source,
            'run_in_background' => $this->runInBackground,
            'without_overlapping' => $this->withoutOverlapping,
            'on_one_server' => $this->onOneServer,
        ];
    }

    public static function sourceOf(Event $event): ?string
    {
        try {
            if ($event instanceof CallbackEvent) {
                $description = $event->description;

                if (is_string($description) && class_exists($description)) {
                    $file = (new ReflectionClass($description))->getFileName();

                    return $file !== false ? self::relative($file) : null;
                }

                $callback = self::callbackOf($event);

                if ($callback instanceof Closure) {
                    $reflection = new ReflectionFunction($callback);

                    return self::relative((string) $reflection->getFileName()) . ':' . $reflection->getStartLine();
                }

                if (is_object($callback)) {
                    $file = (new ReflectionClass($callback::class))->getFileName();

                    return $file !== false ? self::relative($file) : null;
                }

                return null;
            }

            $raw = (string) $event->command;

            if (! self::isArtisan($raw)) {
                return null;
            }

            $name = explode(' ', self::normalizeCommand($raw))[1] ?? null;
            $commands = app(ConsoleKernel::class)->all();

            if ($name === null || ! isset($commands[$name])) {
                return null;
            }

            $file = (new ReflectionClass($commands[$name]))->getFileName();

            return $file !== false ? self::relative($file) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function timezoneName(string|DateTimeZone|null $timezone): ?string
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return $timezone === '' ? null : $timezone;
    }

    /**
     * "'/usr/bin/php' 'artisan' inspire" → "artisan inspire".
     */
    public static function normalizeCommand(string $command): string
    {
        $normalized = str_replace(
            [Application::phpBinary(), Application::artisanBinary(), php_binary(), artisan_binary()],
            ['', 'artisan', '', 'artisan'],
            $command,
        );

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private static function isArtisan(string $command): bool
    {
        return str_contains($command, Application::artisanBinary()) || str_contains($command, artisan_binary());
    }

    private static function callbackOf(CallbackEvent $event): mixed
    {
        // $callback is protected on CallbackEvent: read it through a bound closure.
        return (fn (): mixed => $this->callback)->call($event);
    }

    private static function describeCallback(mixed $callback): string
    {
        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);

            return 'Closure at: ' . self::relative((string) $reflection->getFileName()) . ':' . $reflection->getStartLine();
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_object($callback)) {
            return $callback::class;
        }

        return 'Callback';
    }

    private static function relative(string $file): string
    {
        $base = base_path();

        return str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/\\') : $file;
    }
}
