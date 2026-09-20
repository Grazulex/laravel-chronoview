<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Enums;

enum Health: string
{
    case Paused = 'paused';
    case Unhealthy = 'unhealthy';
    case Running = 'running';
    case Healthy = 'healthy';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Paused => 'Paused',
            self::Unhealthy => 'Unhealthy',
            self::Running => 'Running',
            self::Healthy => 'Healthy',
            self::Unknown => 'Unknown',
        };
    }
}
