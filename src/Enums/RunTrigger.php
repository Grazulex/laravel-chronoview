<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Enums;

enum RunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
}
