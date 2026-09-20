<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Events;

use Grazulex\ChronoView\Models\TaskRun;
use Illuminate\Foundation\Events\Dispatchable;

final class TaskRunFailed
{
    use Dispatchable;

    public function __construct(public readonly TaskRun $run) {}
}
