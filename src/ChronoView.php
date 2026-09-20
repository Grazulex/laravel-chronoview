<?php

declare(strict_types=1);

namespace Grazulex\ChronoView;

final class ChronoView
{
    public function enabled(): bool
    {
        return (bool) config('chronoview.enabled', true);
    }
}
