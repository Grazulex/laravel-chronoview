<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Enums;

enum TaskType: string
{
    case Command = 'command';
    case Closure = 'closure';
    case Job = 'job';
    case Exec = 'exec';
}
