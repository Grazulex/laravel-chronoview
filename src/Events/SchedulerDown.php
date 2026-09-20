<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

final class SchedulerDown
{
    use Dispatchable;

    public function __construct(
        public readonly string $hostname,
        public readonly CarbonImmutable $lastBeatAt,
    ) {}
}
