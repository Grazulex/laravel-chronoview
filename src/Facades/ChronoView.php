<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 *
 * @see \Grazulex\ChronoView\ChronoView
 */
final class ChronoView extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Grazulex\ChronoView\ChronoView::class;
    }
}
