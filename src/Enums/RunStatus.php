<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Enums;

enum RunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Missed = 'missed';

    public function isProblem(): bool
    {
        return $this === self::Failed || $this === self::Missed;
    }
}
