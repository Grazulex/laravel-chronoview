<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Closure;
use DateTimeZone;
use Grazulex\ChronoView\Enums\TaskType;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

use function Illuminate\Support\artisan_binary;
use function Illuminate\Support\php_binary;

use ReflectionFunction;

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
            'run_in_background' => $this->runInBackground,
            'without_overlapping' => $this->withoutOverlapping,
            'on_one_server' => $this->onOneServer,
        ];
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
            $file = (string) $reflection->getFileName();
            $relative = str_starts_with($file, base_path()) ? ltrim(substr($file, strlen(base_path())), '/\\') : $file;

            return 'Closure at: ' . $relative . ':' . $reflection->getStartLine();
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_object($callback)) {
            return $callback::class;
        }

        return 'Callback';
    }
}
